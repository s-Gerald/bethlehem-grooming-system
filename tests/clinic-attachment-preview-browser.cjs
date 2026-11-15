// Optional browser regression using the production Imaging markup and an isolated API fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

(async () => {
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const page = await browser.newPage({viewport: {width: 1280, height: 800}});
    const errors = [];
    const popups = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('popup', popup => popups.push(popup));
    await page.route('https://clinic-preview.test/**', route => route.fulfill({body: '<html></html>', contentType: 'text/html'}));
    await page.goto('https://clinic-preview.test/');
    const clinic = read('pages/admin/clinic.html');
    const start = clinic.indexOf('<section x-data="clinicImagingWorkspace()"');
    const imaging = clinic.slice(start, clinic.indexOf('</section>', start) + '</section>'.length);
    await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">
      <main x-data="attachmentRecordFixture()" role="dialog" aria-label="Medical Record"
        x-show="open" @keydown.escape.window="closeModal()">${imaging}</main>`);
    for (const file of ['css/output.css', 'css/custom.css']) await page.addStyleTag({path: path.join(root, file)});
    for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'clinic-attachments.js']) {
      await page.addScriptTag({path: path.join(root, 'scripts/components', file)});
    }
    await page.addScriptTag({content: `
      const formats = ['sample.png', 'chest.JPG', 'abdomen.jpeg', 'study.dcm', 'report.pdf', 'unreadable.png', 'corrupt.png', 'misnamed.png'];
      const uploads = [];
      const requests = [];
      const urls = new Set();
      const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
      URL.createObjectURL = blob => { const url = create(blob); urls.add(url); return url; };
      URL.revokeObjectURL = url => { urls.delete(url); revoke(url); };
      const canvas = Object.assign(document.createElement('canvas'), {width: 1200, height: 600});
      canvas.getContext('2d').fillRect(5, 5, 1190, 590);
      window.API = {
        getClinicCaseServices: async () => ({data: [{id: 7, status: 'performed', service_snapshot: {name: 'X-Ray'}}], current_page: 1, last_page: 1}),
        clinicUploadAttachment: async (caseId, file, label, serviceId) => {
          uploads.push([caseId, file.name, serviceId]);
          return {attachment: {id: 1, file_name: file.name, file_type: 'image/png', clinic_case_service_id: serviceId}};
        },
        clinicDownloadAttachment: async (caseId, id) => {
          requests.push([caseId, id]);
          if (window.holdAttachment) await new Promise(resolve => { window.releaseAttachment = resolve; });
          if (id === 6) throw new Error('Network error');
          const type = id === 1 ? 'image/png' : 'image/jpeg';
          const image = id <= 3 && await new Promise(resolve => canvas.toBlob(resolve, type));
          if (id === 1) window.originalPng = image;
          return {fileName: formats[id - 1], blob: new Blob([image || 'original bytes'], {type:
            id === 2 ? type : id === 3 || id === 4 || id === 1 ? 'application/octet-stream' :
            id === 5 || id === 8 ? 'application/pdf' : 'image/png'})};
        },
      };
      function attachmentRecordFixture() {
        return Object.assign(adminClinicModal(), {
          open: true, apptId: 41, currentAppt: {id: 41, status: 'in_consultation'},
          serviceRemovingId: null, canRemoveService: () => false, serviceInfo: entry => entry.service_snapshot,
          serviceNotes: () => '',
          attachments: formats.slice(1).map((file_name, index) => ({id: index + 2, file_name, clinic_case_service_id: 7})),
        });
      }
      document.addEventListener('alpine:init', () => {
        Alpine.store('clinicAccess', {permissions: {clinical: true}});
        Alpine.store('clinicFeedback', {notify() {}});
      });
    `});
    if (process.env.ALPINE_PATH) await page.addScriptTag({path: process.env.ALPINE_PATH});
    else await page.addScriptTag({url: 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js'});

    const record = page.getByRole('dialog', {name: 'Medical Record'});
    await record.getByLabel('Add Image').setInputFiles({name: 'sample.png', mimeType: 'image/png', buffer: Buffer.from('upload fixture')});
    const filename = record.getByRole('button', {name: 'sample.png', exact: true});
    await filename.waitFor();
    assert.deepEqual(await page.evaluate(() => uploads), [[41, 'sample.png', 7]]);
    await page.evaluate(() => { window.holdAttachment = true; });
    await filename.click();
    const preview = page.getByRole('dialog', {name: 'sample.png', exact: true});
    await preview.getByRole('status').waitFor({timeout: 3000});
    assert.equal(await filename.isDisabled(), true);
    const close = preview.getByRole('button', {name: 'Close image preview'});
    const download = preview.getByRole('button', {name: 'Download Original'});
    assert.equal(await close.evaluate(el => el === document.activeElement), true);
    assert.equal(await download.isDisabled(), true);
    await record.getByLabel('Add Image').evaluate(el => el.focus());
    assert.equal(await close.evaluate(el => el === document.activeElement), true, 'The underlying record is inert');
    await page.evaluate(() => { window.holdAttachment = false; window.releaseAttachment(); });
    const image = preview.getByRole('img', {name: 'sample.png'});
    await image.waitFor();
    assert.deepEqual(await page.evaluate(() => requests), [[41, 1]], 'Generic PNG response uses one authenticated fetch');
    for (const width of [1280, 320]) {
      await page.setViewportSize({width, height: 640});
      const bounds = await image.boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width && bounds.y + bounds.height <= 640);
      assert.ok(Math.abs(bounds.width / bounds.height - 2) < 0.03, 'The image retains its aspect ratio');
    }
    await page.keyboard.press('Shift+Tab');
    assert.equal(await download.evaluate(el => el === document.activeElement), true);
    await page.keyboard.press('Tab');
    assert.equal(await close.evaluate(el => el === document.activeElement), true);
    const original = page.waitForEvent('download');
    await download.click();
    const file = await original;
    assert.equal(file.suggestedFilename(), 'sample.png');
    const originalBytes = await page.evaluate(async () => Array.from(new Uint8Array(await window.originalPng.arrayBuffer())));
    assert.deepEqual(fs.readFileSync(await file.path()), Buffer.from(originalBytes));
    assert.deepEqual(await page.evaluate(() => requests), [[41, 1]], 'Download uses the originally fetched file');
    const imageUrl = await image.getAttribute('src');
    await page.keyboard.press('Escape');
    await preview.waitFor({state: 'hidden'});
    await page.waitForFunction(() => document.activeElement.textContent === 'sample.png');
    assert.equal(await record.isVisible(), true);
    assert.equal(await page.evaluate(url => urls.has(url), imageUrl), false);

    await page.evaluate(() => { window.holdAttachment = true; window.releaseAttachment = null; });
    await filename.click();
    await preview.getByRole('status').waitFor();
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => document.activeElement.textContent === 'sample.png');
    await page.evaluate(() => { window.holdAttachment = false; window.releaseAttachment(); });
    assert.equal(await preview.isVisible(), false);

    for (const name of ['chest.JPG', 'abdomen.jpeg', 'sample.png']) {
      const button = record.getByRole('button', {name, exact: true});
      await button.click();
      const dialog = page.getByRole('dialog', {name, exact: true});
      const photo = dialog.getByRole('img', {name});
      await photo.waitFor();
      const url = await photo.getAttribute('src');
      await dialog.getByRole('button', {name: 'Close image preview'}).click();
      await page.waitForFunction(expected => document.activeElement.textContent === expected, name);
      assert.equal(await page.evaluate(url => urls.has(url), url), false);
    }
    for (const name of ['unreadable.png', 'corrupt.png']) {
      await record.getByRole('button', {name, exact: true}).click();
      const dialog = page.getByRole('dialog', {name, exact: true});
      await dialog.getByRole('alert').filter({hasText: 'Could not load this image.'}).waitFor();
      await page.keyboard.press('Escape');
      assert.equal(await record.isVisible(), true);
    }
    for (const name of ['study.dcm', 'report.pdf', 'misnamed.png']) {
      const pending = page.waitForEvent('download');
      await record.getByRole('button', {name, exact: true}).click();
      assert.equal((await pending).suggestedFilename(), name);
    }
    await page.waitForFunction(() => urls.size === 0);
    assert.equal(popups.length, 0);
    assert.deepEqual(errors, []);
    console.log('Clinic Imaging upload, preview, focus, image errors and original downloads passed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
