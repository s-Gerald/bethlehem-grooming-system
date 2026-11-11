// Optional browser coverage of production History markup with isolated API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const admin = read('pages/admin/archive.html');
const adminStart = admin.indexOf('<main', admin.indexOf('<!-- Archive Body -->'));
const adminContent = admin.slice(adminStart, admin.indexOf('</main>', adminStart) + '</main>'.length);
const styles = [...admin.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*>|<style>[\s\S]*?<\/style>/g)].map(match => match[0]).join('\n');
const session = {
  id: 91, ownerName: 'Recorded Owner', bookingReference: 'WI-91', status: 'released',
  appointmentDate: '2024-02-09', historyDate: '2024-02-10', historyAtIso: '2024-02-10T10:00:00+08:00',
  pets: [{bookingPetId: 11, petName: 'First pet', specialInstructions: 'Recorded care instructions.\n'.repeat(30)},
    {bookingPetId: 12, petName: 'Clicked pet'}], services: [], productAddons: [],
};
const payment = {status: 'paid', payment_method_label: 'Cash', paid_at: '2026-11-10T16:38:00Z'};
const grooming = {booking_id: 91, booking_reference: 'GR-91', status: 'released', paid: true, number_of_pets: 2,
  booking_date: '2026-11-11', history_date: '2026-11-11',
  payment_summary: {...payment, final_booking_total: 1000},
  pets: [{booking_pet_id: 11, pet_name: 'First pet', services: [{service_name: 'Recorded grooming'}]},
    {booking_pet_id: 12, pet_name: 'Clicked pet', services: [{service_name: 'Recorded bath'}]}]};
const clinic = {id: 91, appointment_reference: 'CL-91', appointment_date: '2026-11-10', status: 'completed',
  pet: {pet_id: 12, pet_name: 'Clicked pet'}, treatment_given: 'Recorded care',
  payment: {...payment, total_paid: 3100, payment_method_label: 'Online Payment ' + 'LongLabel'.repeat(12)}};

(async () => {
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let releaseAlpine, alpineHold;
    let alpineSource;
    await page.route('**/alpinejs@*/**', async route => {
      alpineSource ||= route.fetch().then(response => response.body());
      const source = await alpineSource;
      if (alpineHold) await alpineHold;
      await route.fulfill({contentType: 'text/javascript', body: source});
    });
    await page.route('https://history.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/admin/history') {
        return route.fulfill({contentType: 'text/html', body: `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head>
          <body>${adminContent}
          <script src="/scripts/components/admin-loading.js"></script>
          <script src="/scripts/components/recorded-product-pricing.js"></script><script src="/scripts/components/admin-archive.js"></script>
          <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script></body></html>`});
      }
      if (url.pathname === '/client/history') {
        return route.fulfill({contentType: 'text/html', body: read('pages/client/grooming-history.html')
          .replace(/<script\b[^>]*\bsrc="[^"]+"[^>]*><\/script>/g, '')
          .replace('data-session-gate="checking"', '')});
      }
      const file = path.resolve(root, '.' + url.pathname);
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({status: 404, body: ''});
      return route.fulfill({path: file, contentType: file.endsWith('.js') ? 'application/javascript' : undefined});
    });
    await page.addInitScript(({session, grooming, clinic}) => {
      window.historyCalls = 0; window.consentCalls = 0; window.customerCalls = 0;
      const scenario = new URL(location.href).searchParams.get('scenario');
      window.holdHistory = Boolean(scenario);
      const loadHistory = async type => {
        window.historyCalls++;
        if (window.holdHistory) await new Promise(resolve => { window.releaseHistory = resolve; });
        if (window.failHistory) throw new Error('History service is temporarily unavailable.');
        const patients = [81, 82].map(id => ({
          id, appointment_reference: `CL-${id}`, appointment_date: '2024-02-10', status: 'completed',
          pet: {id, name: id === 81 ? 'Clinic pet' : 'Clinic sibling'}, ownerName: 'Recorded Owner',
          record: {working_diagnosis: id === 81 ? 'First patient findings' : 'Sibling findings'},
          treatment_count: 0, service_count: 0,
        }));
        return {archived: window.emptyHistory ? [] : type === 'grooming' ? [session] : [{
          ...patients[0], session: {id: 1, patients, total_amount: 3000, paid_at: '2024-02-10T10:00:00+08:00'},
        }]};
      };
      window.API = {
        getStaffIdentity: async () => ({user: scenario === 'clinic-only'
          ? {role: 'staff', staff_type: 'clinic', staff_permissions: {clinic_workspace: true}} : {role: 'admin'}}),
        getArchivedBookings: () => loadHistory('grooming'),
        getArchivedClinicAppointments: () => loadHistory('clinic'),
        loadAdminData: async (endpoint, load, render) => {
          if (scenario === 'cached') render({archived: [session]});
          render(await load());
        },
        getGroomingSignedConsent: () => { window.consentCalls++; return new Promise(() => {}); },
        getBookingHistory: async () => ({history: [grooming]}),
        getClinicCase: async id => {
          window.clinicPatientCalls = (window.clinicPatientCalls || 0) + 1;
          return {case: {id, appointment_reference: `CL-${id}`, appointment_date: '2024-02-10', status: 'completed',
            pet: {id, name: 'Clinic sibling'}, ownerName: 'Recorded Owner', record: {working_diagnosis: 'Sibling findings'},
            treatment_count: 0, service_count: 0}};
        },
        getCustomerClinicHistory: async () => {
          window.customerCalls++;
          return {visits: [{...clinic, patients: [clinic]}], current_page: 1, last_page: 1};
        },
        getCustomerClinicHistoryVisit: async () => {
          window.clinicDetailCalls = (window.clinicDetailCalls || 0) + 1;
          return {patients: [clinic], grouped: false};
        },
        loadCustomerData: async (endpoint, load, render) => { window.customerCalls++; render(await load()); },
        getMe: async () => ({user: {first_name: 'Owner', last_name: 'Test'}}),
      };
    }, {session, grooming, clinic});

    const startup = {
      error: page.locator('main > section[x-show="!loading && errorMessage"]'),
      empty: page.locator('main > section[x-show="historyLoaded && !errorMessage && filteredCount === 0"]'),
      records: page.locator('.history-groups'),
      clear: page.locator('button[x-show="hasActiveFilters"]'),
      month: page.locator('div[x-show="selectedYear && availableMonths.length > 1"]'),
      year: page.locator('div[x-show="availableYears.length > 0"]'),
      yearIndicator: page.locator('div[x-show="selectedYear"]'),
    };
    const loader = page.locator('main > admin-loader[page]');
    for (const target of ['?scenario=startup', 'reload', '?scenario=startup&type=grooming&booking_id=91&booking_pet_id=12']) {
      alpineHold = new Promise(resolve => { releaseAlpine = resolve; });
      if (target === 'reload') await page.reload({waitUntil: 'commit'});
      else await page.goto('https://history.test/admin/history' + target, {waitUntil: 'commit'});
      await page.getByRole('heading', {name: 'History', exact: true}).waitFor();
      await page.waitForFunction(() => Boolean(document.querySelector('link[href^="/css/custom.css"]').sheet));
      assert.equal(await page.evaluate(() => typeof window.Alpine), 'undefined');
      assert.equal(await page.getByRole('textbox', {name: 'Search history'}).isVisible(), true, 'Stable search shell stays visible');
      assert.equal(await page.locator('main').getAttribute('x-cloak'), null, 'The whole page must not be cloaked');
      for (const [name, surface] of Object.entries(startup)) {
        assert.equal(await surface.evaluate(el => getComputedStyle(el).display), 'none', `${name} must not paint before Alpine`);
        assert.equal(await surface.getAttribute('x-cloak'), '', `${name} uses the existing cloak mechanism`);
      }
      assert.equal(await page.getByRole('button', {name: 'Try again'}).count(), 0, 'False retry is absent from the accessibility tree');
      releaseAlpine(); alpineHold = null;
      await page.waitForFunction(() => window.historyCalls === 1);
      assert.equal(await loader.isVisible(), true, 'The intended loader appears while the request is pending');
      for (const name of ['error', 'empty', 'records', 'clear']) assert.equal(await startup[name].isVisible(), false);
      await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });
      await startup.records.waitFor({state: 'visible'});
      assert.equal(await loader.isVisible(), false);
      assert.equal(await startup.error.isVisible(), false); assert.equal(await startup.empty.isVisible(), false);
      assert.equal(await page.evaluate(() => window.historyCalls), 1);
      if (target.includes('booking_id')) {
        await page.getByRole('dialog').filter({has: page.locator('#grooming-record-title')}).waitFor({state: 'visible'});
        assert.equal(await page.evaluate(() => window.consentCalls), 1, 'Pending consent never blocks deep-link opening');
      }
    }

    await page.goto('https://history.test/admin/history?scenario=retry');
    await page.waitForFunction(() => typeof window.releaseHistory === 'function');
    await page.evaluate(() => { window.failHistory = true; window.holdHistory = false; window.releaseHistory(); });
    await startup.error.waitFor({state: 'visible'});
    assert.match(await startup.error.innerText(), /History service is temporarily unavailable/);
    for (const surface of [loader, startup.empty, startup.records]) assert.equal(await surface.isVisible(), false);
    await page.evaluate(() => { window.failHistory = false; window.holdHistory = true; });
    await startup.error.getByRole('button', {name: 'Try again'}).click();
    await loader.waitFor({state: 'visible'});
    assert.equal(await startup.error.isVisible(), false);
    await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });
    await startup.records.waitFor({state: 'visible'});
    assert.equal(await page.evaluate(() => window.historyCalls), 2, 'Retry uses exactly one additional request');

    await page.getByRole('button', {name: 'Clinic', exact: true}).click();
    await page.locator('.clinic-row').waitFor();
    assert.equal(await startup.error.isVisible(), false);
    await page.locator('.clinic-row').getByRole('button', {name: /View Visit/}).click();
    const clinicDialog = page.getByRole('dialog', {name: 'Clinic Record'});
    await clinicDialog.waitFor({state: 'visible'});
    await clinicDialog.getByRole('button', {name: 'Clinic sibling · CL-82'}).click();
    await clinicDialog.getByRole('heading', {name: 'Clinic sibling', exact: true}).waitFor();
    assert.match(await clinicDialog.innerText(), /Sibling findings/);
    assert.doesNotMatch(await clinicDialog.innerText(), /First patient findings/);
    assert.match(await clinicDialog.innerText(), /Total paid.*3,000/);
    assert.equal(await page.evaluate(() => window.clinicPatientCalls), 1);
    if (process.env.HISTORY_SCREENSHOT_DIR) {
      await page.evaluate(async () => Promise.all(document.getAnimations().filter(animation => animation.effect?.getTiming().iterations !== Infinity)
        .map(animation => animation.finished.catch(() => {}))));
      await page.screenshot({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, 'clinic-owner-session.png')});
    }
    await page.keyboard.press('Escape');
    await page.goto('https://history.test/admin/history?scenario=clinic-only');
    await page.waitForFunction(() => typeof window.releaseHistory === 'function');
    await page.evaluate(() => { window.emptyHistory = true; window.holdHistory = false; window.releaseHistory(); });
    await startup.empty.waitFor({state: 'visible'});
    assert.match(await startup.empty.innerText(), /clinic history/i);
    for (const surface of [loader, startup.error, startup.records, page.getByRole('group', {name: 'History type'})]) assert.equal(await surface.isVisible(), false);

    await page.goto('https://history.test/admin/history?scenario=cached');
    await startup.records.waitFor({state: 'visible'});
    await page.waitForFunction(() => window.historyCalls === 1);
    assert.equal(await loader.isVisible(), false, 'Valid cached records remain visible during revalidation');
    assert.equal(await startup.error.isVisible(), false); assert.equal(await startup.empty.isVisible(), false);
    await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });

    await page.goto('https://history.test/admin/history?type=grooming&booking_id=91&booking_pet_id=12');
    const adminDialog = page.getByRole('dialog');
    await adminDialog.waitFor({state: 'visible'});
    const body = page.getByRole('region', {name: 'Grooming record details'});
    await page.waitForFunction(() => document.querySelector('[aria-label="Grooming record details"]').scrollTop > 0);
    assert.equal(await adminDialog.locator('[data-history-booking-pet]').count(), 2);
    const targetRect = await adminDialog.locator('[data-history-booking-pet="12"]').boundingBox();
    const bodyRect = await body.boundingBox();
    assert.ok(targetRect.y >= bodyRect.y - 2 && targetRect.y < bodyRect.y + bodyRect.height);
    assert.equal(await page.evaluate(() => window.scrollY), 0, 'Targeting does not move the page');
    assert.equal(await page.evaluate(() => window.historyCalls), 1);
    assert.equal(await page.evaluate(() => window.consentCalls), 1);
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Close grooming record');
    await page.keyboard.press('Escape');
    await adminDialog.waitFor({state: 'hidden'});
    assert.equal(new URL(page.url()).search, '?type=grooming');
    assert.equal(await page.evaluate(() => document.activeElement.dataset.historyBooking), '91');
    await page.locator('[data-history-booking="91"]').click();
    await adminDialog.waitFor({state: 'visible'});
    assert.equal(await body.evaluate(el => el.scrollTop), 0, 'Ordinary row opening starts normally');
    await page.keyboard.press('Escape');
    await page.goto('https://history.test/admin/history?type=grooming&booking_id=999');
    await page.getByRole('status').filter({hasText: /no longer available/}).waitFor();
    assert.equal(await adminDialog.isVisible(), false);
    assert.equal(new URL(page.url()).search, '?type=grooming');

    await page.goto('https://history.test/client/history');
    await page.locator('[data-history-open]').nth(2).waitFor();
    assert.equal(await page.evaluate(() => window.customerCalls), 2);
    await page.locator('[data-history-type="grooming"]').first().click();
    const customerDialog = page.getByRole('dialog');
    await customerDialog.waitFor({state: 'visible'});
    assert.match(await customerDialog.innerText(), /Visit total paid[\s\S]*₱1,000.00[\s\S]*Cash[\s\S]*Nov 11, 2026 · 12:38 AM/);
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Close', 'Focus stays inside the dialog');
    await page.keyboard.press('Escape');
    await page.locator('[data-history-type="clinic"]').click();
    await customerDialog.getByRole('heading', {name: 'Clicked pet', exact: true}).waitFor();
    assert.match(await customerDialog.innerText(), /Total paid[\s\S]*₱3,100.00[\s\S]*Online Payment/);
    for (const width of [320, 375, 414, 768, 1280]) {
      await page.setViewportSize({width, height: 850});
      await page.evaluate(async () => {
        await Promise.all(document.getAnimations().filter(animation => animation.effect?.getTiming().iterations !== Infinity)
          .map(animation => animation.finished.catch(() => {})));
      });
      if (process.env.HISTORY_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, `clinic-payment-${width}.png`)});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true,
        `Page fits ${width}px: ${JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('body *')]
          .filter(el => el.getBoundingClientRect().right > innerWidth + 1 && el.getClientRects().length)
          .slice(0, 8).map(el => ({tag: el.tagName, id: el.id, classes: el.className, width: el.getBoundingClientRect().width}))))}`);
      assert.equal(await customerDialog.evaluate(el => el.scrollWidth <= el.clientWidth), true, `Payment fits ${width}px`);
    }
    assert.equal(await page.evaluate(() => window.customerCalls), 2, 'Opening details makes no payment request');
    assert.equal(await page.evaluate(() => window.clinicDetailCalls), 1, 'Only the selected clinic visit loads clinical details');
    assert.deepEqual(errors, []);
    console.log('History browser checks passed: pre-Alpine paint/reload, loading, error/retry, empty/Clinic/cache states, deep links, focus and responsive customer payments.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
