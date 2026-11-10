// Optional browser coverage of production History markup with isolated API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const admin = read('pages/admin/archive.html');
const adminModal = admin.slice(admin.indexOf('<!-- View Details Modal -->'), admin.indexOf('<!-- Read-only historical encounter -->'));
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
const clinic = {appointment_reference: 'CL-91', appointment_date: '2026-11-10', status: 'completed',
  pet: {pet_id: 12, pet_name: 'Clicked pet'}, treatment_given: 'Recorded care',
  payment: {...payment, total_paid: 3100, payment_method_label: 'Online Payment ' + 'LongLabel'.repeat(12)}};

(async () => {
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://history.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/admin/history') {
        return route.fulfill({contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head>
          <body><main x-data="adminArchive()">
          <template x-for="booking in filteredList" :key="booking.id"><button :data-history-booking="booking.id" @click="viewDetails(booking, $event.currentTarget)">View Details</button></template>
          <p role="status" x-text="historyNotice"></p>${adminModal}</main>
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
      window.API = {
        getStaffIdentity: async () => ({user: {role: 'admin'}}),
        getArchivedBookings: async () => { window.historyCalls++; return {archived: [session]}; },
        loadAdminData: async (endpoint, load, render) => { render(await load()); },
        getGroomingSignedConsent: () => { window.consentCalls++; return new Promise(() => {}); },
        getBookingHistory: async () => ({history: [grooming]}),
        getCustomerClinicHistory: async () => ({medical_records: [clinic]}),
        loadCustomerData: async (endpoint, load, render) => { window.customerCalls++; render(await load()); },
        getMe: async () => ({user: {first_name: 'Owner', last_name: 'Test'}}),
      };
    }, {session, grooming, clinic});
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
    await page.getByRole('button', {name: 'View Details', exact: true}).click();
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
    assert.deepEqual(errors, []);
    console.log('History browser checks passed: deep links, pet scrolling, focus, close, stale targets and responsive customer payments.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
