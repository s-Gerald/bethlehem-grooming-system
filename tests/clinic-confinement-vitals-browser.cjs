// Isolated browser fixture: renders the production Monitoring markup/scripts.
// No application API or real patient data is contacted.
// Run with PLAYWRIGHT_PATH pointing to an installed Playwright package.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');

(async () => {
  const html = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const start = html.indexOf('            <template x-if="monitoringOpen && monitoringForm">');
  const end = html.indexOf('            <admin-loader', start);
  const form = html.slice(start, end);
  const history = html.split('\n').find(line => line.includes('x-show="observations.length"') && line.includes('Chronological confinement observations'));
  assert.ok(start >= 0 && end > start && history);
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    // Give sessionStorage a local origin without starting an application server.
    await page.route('http://127.0.0.1:8999/**', route => route.fulfill({contentType: 'text/html', body: '<html></html>'}));
    await page.goto('http://127.0.0.1:8999/');
    await page.setContent(`<html><head></head><body><main class="mx-auto max-w-4xl p-4" x-data="clinicConfinementWorkspace()" x-init="confinementCaseId = 1; confinementCase = {id: 1, status: 'in_consultation', pet: {species: 'Dog'}}; episode = {id: 1}; openMonitoring()">${form}${history}<button id="reopen" type="button" @click="openMonitoring()">Record Monitoring</button></main></body></html>`);
    await page.addStyleTag({path: path.join(root, 'css/output.css')});
    await page.addStyleTag({path: path.join(root, 'css/short-notes.css')});
    for (const file of ['clinic-record-presentation.js', 'admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js']) {
      await page.addScriptTag({path: path.join(root, 'scripts/components', file)});
    }
    await page.addScriptTag({type: 'module', path: path.join(root, 'scripts/utils/short-notes.js')});
    await page.addScriptTag({content: `
      window.requests = [];
      window.API = {
        clinicRecordConfinementObservation: async (id, payload) => { requests.push(payload); return {data: {id: requests.length}}; },
        getClinicConfinementObservations: async () => ({data: [{id: 1, temperature_c: '46.0', heart_rate_bpm: 90, respiratory_rate_bpm: 20,
          recorded_at: '2026-11-10T08:00:00Z', recorded_by_user_id: 1, notes: 'Observed', verification_history: [{field: 'temperature_c', value: '46',
          reason: 'Confirmed temperature with second thermometer', verified_by_name: 'Attending Vet', verified_at: '2026-11-10T08:00:00Z'}]}], current_page: 1, last_page: 1})
      };
      document.addEventListener('alpine:init', () => {
        Alpine.store('clinicAccess', {permissions: {clinical: true}, user: {user_id: 1, role: 'staff', staff_type: 'clinic', staff_subrole: 'veterinarian'}});
        Alpine.store('clinicFeedback', {notify() {}});
      });
    `});
    await page.addScriptTag({url: 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js'});
    const temperature = page.getByRole('textbox', {name: 'Temperature (°C)', exact: true});
    const heart = page.getByRole('textbox', {name: 'Heart Rate (bpm)', exact: true});
    const respiratory = page.getByRole('textbox', {name: 'Respiratory Rate (breaths/min)', exact: true});
    const save = page.getByRole('button', {name: 'Save Monitoring', exact: true});
    try { await temperature.waitFor({timeout: 10000}); }
    catch (error) { throw new Error(`${error.message}\nAlpine errors: ${errors.join('; ')}`); }
    assert.equal(await page.locator('#confinement-monitoring-form [aria-invalid="true"]').count(), 0);
    await temperature.focus(); await heart.focus();
    assert.equal(await page.locator('#confinement-monitoring-form [aria-invalid="true"]').count(), 0, 'Opening and leaving empty fields shows no required errors');
    for (const width of [320, 375, 414, 768]) {
      await page.setViewportSize({width, height: 950});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Form fits ${width}px`);
    }
    await save.click();
    assert.equal(await page.locator('#confinement-monitoring-form [aria-invalid="true"]').count(), 3);
    assert.equal(await temperature.evaluate(el => el === document.activeElement), true);
    assert.equal(await page.locator('#monitoring-validation-summary').innerText(), 'Complete the required fields marked *.');
    await temperature.blur(); // Existing Bethlehem focus styling uses the clinic blue border.
    assert.equal(await temperature.evaluate(el => getComputedStyle(el).borderColor), 'rgb(248, 113, 113)');
    await temperature.fill('38.5'); await heart.fill('90'); await respiratory.fill('20');
    assert.equal(await page.locator('#confinement-monitoring-form [aria-invalid="true"]').count(), 0, 'Corrections clear inline required states immediately');
    await temperature.fill('40.5'); await temperature.blur();
    assert.equal(await page.locator('#monitoring-temperatureWarning').innerText(), 'Unusual reading. Please confirm.');
    assert.equal(await temperature.evaluate(el => getComputedStyle(el).borderColor), 'rgb(251, 191, 36)');
    if (process.env.LOCAL_REVIEW_SCREENSHOTS) {
      fs.mkdirSync(process.env.LOCAL_REVIEW_SCREENSHOTS, {recursive: true});
      await page.screenshot({path: path.join(process.env.LOCAL_REVIEW_SCREENSHOTS, 'monitoring-unusual.png'), fullPage: true});
    }
    await temperature.fill('46'); await temperature.blur();
    await page.getByRole('button', {name: 'Verify exceptional reading'}).click();
    const reason = page.getByRole('textbox', {name: 'Clinical reason for verified reading'});
    await reason.fill('abcd'); await save.click();
    assert.equal(await page.evaluate(() => requests.length), 0);
    await reason.fill('Confirmed temperature with second thermometer');
    assert.notEqual(await temperature.getAttribute('aria-invalid'), 'true');
    await temperature.fill('47');
    assert.equal(await reason.count(), 0, 'Editing exceptional reading removes stale reason controls');
    await temperature.blur();
    await page.getByRole('button', {name: 'Verify exceptional reading'}).click();
    await reason.fill('Confirmed temperature with second thermometer');
    await save.click();
    await page.getByText('Verified reading', {exact: true}).waitFor();
    assert.equal(await page.evaluate(() => requests.length), 1);
    assert.equal(await page.locator('tbody tr').count(), 1);
    assert.equal(await page.locator('tbody tr td').count(), 6, 'Audit disclosure preserves the six-column timeline');
    await page.getByText('Verified reading', {exact: true}).click();
    assert.equal(await page.getByText('Confirmed temperature with second thermometer', {exact: true}).isVisible(), true);
    assert.equal(await page.getByText(/Attending Vet ·/).isVisible(), true);
    assert.deepEqual(errors, [], 'Alpine bindings must render without runtime errors');
    await context.close();
    console.log('Rendered Monitoring form passed at 320/375/414/768px; required/error/amber/focus/verification/history bindings passed');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
