// Isolated browser regression using the production attending picker and component.
// Run with PLAYWRIGHT_PATH pointing to an installed Playwright package if needed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');

(async () => {
  const html = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const start = html.indexOf('<section class="space-y-3" aria-labelledby="medical-veterinarian-heading">');
  const end = html.indexOf('</section>', start) + '</section>'.length;
  assert.ok(start >= 0 && end > start);
  const legend = html.split('\n').find(line => line.includes('id="medical-required-legend"'));
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    for (const user of [
      {role: 'admin'}, {role: 'admin', admin_type: 'owner'}, {role: 'admin', admin_type: 'manager'},
      {role: 'staff', staff_type: 'clinic', staff_subrole: 'veterinarian'},
    ]) {
      const page = await browser.newPage({viewport: {width: 768, height: 800}});
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.setContent(`<html><head></head><body><main class="p-4" x-data="adminClinicModal()">
        <form id="medical-record-form">${legend}${html.slice(start, end)}</form>
        <p id="medical-validation-summary" role="alert" tabindex="-1" x-text="modalError"></p>
      </main></body></html>`);
      await page.addStyleTag({path: path.join(root, 'css/output.css')});
      await page.addScriptTag({path: path.join(root, 'scripts/components/clinic-record-presentation.js')});
      await page.addScriptTag({path: path.join(root, 'scripts/components/admin-clinic.js')});
      await page.addScriptTag({content: `
        window.optionsCalls = 0;
        window.API = {
          getUserRole: () => ${JSON.stringify(user.role)},
          getAdminVaccinationOptions: async () => {
            optionsCalls++;
            return {current_user_id: 1, current_user_is_attending_clinician: true,
              current_user_is_veterinarian: ${user.role === 'staff'},
              veterinarians: Array.from({length: 10}, (_, index) => ({user_id: index + 2, name: 'Veterinarian ' + (index + 2)}))};
          },
        };
        clinicPetWeightToolsReady = Promise.resolve({formatSavedWeight: () => '', getWeightValueValidationMessage: () => ''});
        document.addEventListener('alpine:init', () => {
          Alpine.store('clinicAccess', {permissions: {clinical: true}, user: {
            ...${JSON.stringify(user)}, user_id: 1, first_name: 'Angela', last_name: 'Santiago'
          }});
        });
      `});
      await page.addScriptTag({url: 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js'});
      await page.evaluate(async () => {
        const state = Alpine.$data(document.querySelector('main'));
        await state.openModal({appt: {id: 10, status: 'in_consultation'}});
      });
      const picker = page.getByRole('combobox', {name: 'Attending Veterinarian(s)', exact: true});
      await picker.waitFor({timeout: 5000});
      assert.equal(await page.locator('#medical-veterinarians-label').innerText(), 'Attending Veterinarian(s)');
      assert.equal(await picker.getAttribute('aria-required'), null);
      assert.equal(await picker.innerText(), 'Add another veterinarian\n▾');
      const ownChip = page.getByText('Angela Santiago · You', {exact: true});
      assert.equal(await ownChip.count(), 1);
      assert.equal(await page.getByRole('button', {name: 'Remove Angela Santiago', exact: true}).count(), 0);
      assert.equal(await page.locator('#medical-veterinarians-label').getByText('*', {exact: true}).count(), 0);
      assert.equal(await page.locator('main').getByText(/\(optional\)/).count(), 0);
      assert.equal(await page.locator('#medical-required-legend').isVisible(), true);
      for (const width of [320, 375, 414, 768]) {
        await page.setViewportSize({width, height: 800});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Picker fits ${width}px`);
      }
      await picker.focus();
      await picker.press('ArrowDown');
      const search = page.getByRole('searchbox', {name: 'Search veterinarian', exact: true});
      await search.waitFor();
      assert.equal(await search.evaluate(el => el === document.activeElement), true);
      await search.fill('Veterinarian 2');
      const choice = page.getByRole('checkbox', {name: 'Veterinarian 2', exact: true});
      await choice.check();
      assert.equal(await page.getByRole('button', {name: 'Remove Veterinarian 2', exact: true}).isVisible(), true);
      await search.press('Escape');
      assert.equal(await picker.getAttribute('aria-expanded'), 'false');
      assert.equal(await picker.evaluate(el => el === document.activeElement), true);
      await page.getByRole('button', {name: 'Remove Veterinarian 2', exact: true}).click();
      assert.equal(await picker.evaluate(el => el === document.activeElement), true);
      assert.equal(await ownChip.isVisible(), true);
      await page.evaluate(() => {
        const state = Alpine.$data(document.querySelector('main'));
        state.showValidationErrors({'additional_veterinarian_ids.0': ['The selected veterinarian is invalid.']});
      });
      assert.equal(await picker.getAttribute('aria-invalid'), 'true');
      assert.equal(await page.locator('#medical-veterinariansError').innerText(), 'Select an available veterinarian.');
      assert.ok((await picker.getAttribute('aria-describedby')).includes('medical-veterinariansError'));
      assert.equal(await picker.getAttribute('aria-errormessage'), 'medical-validation-summary');
      assert.equal(await page.evaluate(() => optionsCalls), 1);
      assert.deepEqual(errors, []);
      await page.close();
    }
    console.log('Attending clinician browser regression checks passed');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
