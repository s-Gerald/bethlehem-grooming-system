// Render the production pet-profile section with controlled history data.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');

(async () => {
  const html = fs.readFileSync(path.join(root, 'pages/admin/clients.html'), 'utf8');
  const start = html.indexOf('<section id="customer-pet-grooming-panel"');
  const end = html.indexOf('</section>', start) + '</section>'.length;
  assert.ok(start >= 0 && end > start);
  const longService = 'Full grooming with medicated bath and coat conditioning, ' + 'LongServiceName'.repeat(20);
  const fixtures = [
    { booking_reference: 'BAC-101', status: 'for_payment', services: [longService], paid: false,
      grooming_started_at: '8:30 AM', grooming_finished_at: '9:30 AM' },
    { booking_reference: 'WI-102', status: 'released', services: ['Bath'], paid: true,
      grooming_started_at: '8:30 AM', grooming_finished_at: '9:30 AM' },
    { booking_reference: 'WI-103', status: 'archived', services: [], paid: true },
    { booking_reference: 'BAC-104', status: 'in_progress', services: ['Full Groom'], paid: false,
      grooming_started_at: '8:30 AM' },
    { booking_reference: 'BAC-105', status: 'in_progress', services: ['Full Groom'], paid: false,
      grooming_started_at: '8:30 AM', grooming_finished_at: '9:30 AM' },
    { booking_reference: 'BAC-106', status: 'cancelled', services: ['Bath'], paid: false },
    { booking_reference: 'BAC-107', status: 'waiting_to_arrive', services: ['Bath'], paid: false },
    { booking_reference: 'WI-108', status: 'for_pickup', services: ['Bath'], paid: true },
  ].map((record, index) => ({ booking_id: 301 + index, booking_pet_id: 401 + index,
    history_eligible: ['released', 'archived', 'cancelled', 'for_pickup'].includes(record.status),
    booking_date: '2026-08-01', grooming_started_at: null, grooming_finished_at: null, ...record }));
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent(`<main class="mx-auto max-w-4xl p-4" x-data="groomingFixture()">${html.slice(start, end)}</main>`);
    await page.addStyleTag({ path: path.join(root, 'css/output.css') });
    await page.addStyleTag({ path: path.join(root, 'css/custom.css') });
    await page.addScriptTag({ path: path.join(root, 'scripts/components/admin-customers.js') });
    await page.addScriptTag({ content: `
      function groomingFixture() {
        const ui = adminCustomers();
        ui.init = () => {};
        ui.petModal.profileLoaded = true;
        ui.petModal.activeTab = 'grooming';
        ui.petModal.groomingRecords = ${JSON.stringify(fixtures)};
        return ui;
      }
    ` });
    await page.addScriptTag({ url: 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js' });
    const cards = page.locator('#customer-pet-grooming-panel a');
    await cards.nth(fixtures.length - 1).waitFor();
    const labels = ['For Payment', 'Ready for Pickup', 'Completed', 'In Progress', 'In Progress', 'Cancelled', 'Registered', 'Ready for Pickup'];
    for (const [index, record] of fixtures.entries()) {
      const card = cards.nth(index);
      assert.equal(await card.getAttribute('href'), record.history_eligible
        ? `/admin/history?type=grooming&booking_id=${record.booking_id}&booking_pet_id=${record.booking_pet_id}` : null);
      assert.equal(await card.getAttribute('onclick'), null, 'Native navigation must stay available');
      assert.equal(await card.getByText('Visit Reference', { exact: true }).count(), 1);
      assert.equal(await card.getByRole('heading').innerText(), record.booking_reference);
      assert.equal(await card.getByText(labels[index], { exact: true }).count(), 1);
      assert.equal(await card.getByText('Grooming Date', { exact: true }).count(), 1);
      assert.equal(await card.getByText('Services', { exact: true }).count(), 1);
      assert.equal(await card.getByText(record.paid ? 'Paid' : 'Unpaid', { exact: true }).count(), 1);
      assert.equal(await card.locator('dt').filter({ hasText: /^Started$/ }).count(), record.grooming_started_at ? 1 : 0);
      assert.equal(await card.locator('dt').filter({ hasText: /^Finished$/ }).count(), record.grooming_finished_at ? 1 : 0);
      if (record.grooming_finished_at) assert.ok((await card.innerText()).includes('9:30 AM'));
      assert.doesNotMatch(await card.innerText(), /Arrival Window|Booking Reference|Grooming Finished|Not provided|Scheduled/);
    }
    assert.ok((await cards.nth(2).innerText()).includes('Not recorded'));
    assert.equal(await cards.nth(2).locator('dt').count(), 3, 'Legacy completed record needs no timestamps');
    assert.equal(await cards.first().getByText(longService, { exact: true }).count(), 1);
    await cards.nth(1).focus();
    assert.equal(await cards.nth(1).evaluate(el => el.matches(':focus')), true);
    for (const width of [320, 375, 414, 768, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `Cards fit ${width}px`);
      for (const card of await cards.all()) {
        assert.equal(await card.evaluate(el => el.scrollWidth <= el.clientWidth), true, `Card contents fit ${width}px`);
      }
      if (process.env.GROOMING_SCREENSHOT_DIR && [375, 1280].includes(width)) {
        await page.screenshot({ path: path.join(process.env.GROOMING_SCREENSHOT_DIR, `grooming-records-${width}.png`), fullPage: true });
      }
    }
    assert.deepEqual(errors, [], 'No Alpine rendering errors');
    console.log('Pet-profile grooming cards: lifecycle, references, services, payment, optional timestamps and responsive wrapping passed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
