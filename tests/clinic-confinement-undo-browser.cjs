// Isolated production-markup fixture; no application server or real patient API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');

(async () => {
  const html = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const start = html.indexOf('            <div id="confinement-actions"');
  const end = html.indexOf('\n          </div>', start);
  const confirmStart = html.indexOf('    <!-- Shared clinic confirm dialog');
  const confirmEnd = html.indexOf('      <div class="app-toast"', confirmStart);
  assert.ok(start >= 0 && end > start && confirmEnd > confirmStart);
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const context = await browser.newContext(); const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://127.0.0.1:8999/**', route => route.fulfill({contentType: 'text/html', body: '<html></html>'}));
    await page.goto('http://127.0.0.1:8999/');
    await page.setContent(`<main class="mx-auto max-w-4xl p-4" x-data="clinicConfinementWorkspace()" x-init="confinementCaseId = 1; confinementCase = {id: 1, status: 'in_consultation'}; episode = {id: 9}; undoAllowed = true; medicalForm = {working_diagnosis: 'Unsaved diagnosis'}">
      <p role="alert" x-text="error" x-show="error"></p><button x-ref="admitConfinement" x-show="canAdmitConfinement" @click="openAdmission()">Admit for Confinement</button>
      ${html.slice(start, end)}</main>${html.slice(confirmStart, confirmEnd)}</div>`);
    await page.addStyleTag({path: path.join(root, 'css/output.css')});
    await page.addStyleTag({path: path.join(root, 'css/short-notes.css')});
    for (const file of ['clinic-record-presentation.js', 'admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js', 'clinic-feedback.js']) {
      await page.addScriptTag({path: path.join(root, 'scripts/components', file)});
    }
    await page.addScriptTag({content: `
      window.undoCalls = [];
      window.API = {
        getStaffIdentity: async () => ({user: {user_id: 1, clinic_permissions: {clinical: true}}}),
        clinicUndoConfinementAdmission: async (id, payload) => {
          undoCalls.push({id, payload});
          if (window.rejectUndo) throw Object.assign(Error('Cannot undo'), {status: 422, errors: {confinement: ['Monitoring or confinement care has already been recorded.']}});
          await new Promise(resolve => { window.finishUndo = resolve; });
          return {data: null, case: {id, status: 'in_consultation', total_amount: 1200, confinement: null}};
        }
      };
    `});
    await page.addScriptTag({url: 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js'});
    const undo = page.getByRole('button', {name: 'Undo Confinement Admission', exact: true});
    const discharge = page.getByRole('button', {name: 'Discharge & Send to Billing', exact: true});
    await undo.waitFor(); await discharge.waitFor();
    const snapshot = () => page.evaluate(() => Alpine.$data(document.querySelector('main')));
    for (const width of [320, 375, 414, 768]) {
      await page.setViewportSize({width, height: 900});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Actions fit ${width}px`);
    }
    await undo.click();
    const dialog = page.getByRole('alertdialog');
    const reason = page.getByRole('textbox', {name: 'Reason for correction', exact: true});
    const keep = page.getByRole('button', {name: 'Keep Confinement', exact: true});
    const confirm = page.getByRole('button', {name: 'Undo Admission', exact: true});
    await reason.waitFor();
    assert.equal(await dialog.getByRole('heading').innerText(), 'Undo confinement admission?');
    assert.equal(await reason.evaluate(el => el === document.activeElement), true, 'Reason receives initial focus');
    assert.equal(await reason.getAttribute('aria-required'), 'true');
    assert.equal(await reason.getAttribute('maxlength'), '200');
    await confirm.click();
    assert.equal(await dialog.isVisible(), true);
    assert.equal(await reason.getAttribute('aria-invalid'), 'true');
    assert.equal(await page.evaluate(() => undoCalls.length), 0);
    await reason.fill('Wrong patient selected');
    assert.notEqual(await reason.getAttribute('aria-invalid'), 'true', 'Correction clears the reason error immediately');
    await reason.focus(); await page.keyboard.press('Shift+Tab');
    assert.equal(await confirm.evaluate(el => el === document.activeElement), true, 'Shift+Tab stays in the correction dialog');
    await page.keyboard.press('Tab');
    assert.equal(await reason.evaluate(el => el === document.activeElement), true);
    for (const width of [320, 375, 414, 768]) {
      await page.setViewportSize({width, height: 900});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Dialog fits ${width}px`);
    }
    if (process.env.LOCAL_REVIEW_SCREENSHOTS) {
      fs.mkdirSync(process.env.LOCAL_REVIEW_SCREENSHOTS, {recursive: true});
      await page.screenshot({path: path.join(process.env.LOCAL_REVIEW_SCREENSHOTS, 'undo-confinement-confirmation.png'), fullPage: true});
    }
    await keep.click(); await dialog.waitFor({state: 'hidden'});
    assert.equal(await dialog.isVisible(), false); assert.equal(await page.evaluate(() => undoCalls.length), 0);
    assert.equal(await undo.evaluate(el => el === document.activeElement), true, 'Cancel restores focus to Undo');
    await undo.click(); await reason.fill('Admitted by mistake'); await confirm.click();
    await page.waitForFunction(() => undoCalls.length === 1);
    const busy = page.getByRole('button', {name: 'Undoing…', exact: true});
    assert.equal(await busy.isDisabled(), true);
    await page.evaluate(() => Alpine.$data(document.querySelector('main')).undoConfinementAdmission());
    assert.equal(await page.evaluate(() => undoCalls.length), 1, 'In-flight correction cannot be submitted twice');
    await page.evaluate(() => finishUndo());
    await page.getByRole('button', {name: 'Admit for Confinement', exact: true}).waitFor();
    const state = await snapshot();
    assert.equal(state.episode, null); assert.equal(state.confinementCase.status, 'in_consultation');
    assert.equal(state.medicalForm.working_diagnosis, 'Unsaved diagnosis');
    assert.equal(await page.evaluate(() => Alpine.store('clinicFeedback').toast.message), 'Confinement admission undone. Patient returned to Active Cases.');
    await page.evaluate(() => { const state = Alpine.$data(document.querySelector('main')); state.episode = {id: 10}; state.undoAllowed = false; });
    assert.equal(await undo.isVisible(), false, 'Ineligible care does not offer Undo');
    await discharge.waitFor();
    assert.equal(await discharge.isVisible(), true, 'Discharge remains available for real confinement');
    assert.deepEqual(errors, []);
    await context.close();
    console.log('Rendered Undo actions/confirmation passed at 320/375/414/768px; required reason, keyboard focus, busy state, duplicate protection and state preservation passed');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
