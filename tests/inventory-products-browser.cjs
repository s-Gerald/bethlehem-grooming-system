// Run with PLAYWRIGHT_PATH and ALPINE_PATH when using the local browser test tools.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const origin = 'https://inventory-products.example';

(async () => {
  const alpine = process.env.ALPINE_PATH ? fs.readFileSync(process.env.ALPINE_PATH, 'utf8')
    : await (await fetch('https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js', { signal: AbortSignal.timeout(15000) })).text();
  const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, timezoneId: 'Asia/Manila' });
    const page = await context.newPage();
    const requests = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const product = {
      item_id: 1, item_name: 'Anti medicine', category: 'medicine', medicine_type: 'general_medication',
      unit: 'piece', description: 'Product details', pricing_method: 'flat', unit_cost: 5, selling_price: 10,
      quantity_on_hand: 0, usable_quantity: 0, saleable_quantity: 0, reserved_quantity: 0, reorder_level: 2,
      stock_status: 'out_of_stock', low_stock: true, is_active: true, deactivated_at: null,
      tracks_expiry: true, current_batches: [], created_at: '2020-01-01T00:00:00Z',
    };
    let reactivated = false;
    await page.route(origin + '/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/api/')) {
        requests.push(url.pathname + url.search);
        let response;
        if (url.pathname.endsWith('/reactivate')) {
          reactivated = true; response = { data: { ...product, deactivated_at: null } };
        } else if (url.pathname === '/api/inventory/items/1') {
          response = { data: { ...product, is_active: reactivated, deactivated_at: reactivated ? null : '2026-11-10T06:45:00.000000Z' } };
        } else if (url.pathname === '/api/inventory/alerts/expiry') {
          response = { data: [], page: 1, last_page: 1, total: 0 };
        } else {
          const inactive = url.searchParams.get('inactive_only') === '1';
          response = { data: inactive ? [
            ...(!reactivated ? [{ ...product, is_active: false, deactivated_at: '2026-11-10T06:45:00.000000Z' }] : []),
            { ...product, item_id: 2, item_name: 'Legacy medicine', is_active: false },
          ] : [product], page: Number(url.searchParams.get('page')), last_page: 2, total: 12 };
        }
        return route.fulfill({ json: response });
      }
      if (url.pathname === '/') {
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><meta charset="utf-8">
          <link rel="stylesheet" href="/css/output.css"><link rel="stylesheet" href="/css/components/admin-inventory.css">
          <style>[x-cloak]{display:none!important}main{padding:16px}@media(min-width:640px){main{padding:24px}}@media(min-width:1024px){main{margin-left:288px}}</style>
          </head><body><main x-data="inventoryFixture()">${read('pages/admin/inventory/sections/products.html')}</main></body></html>` });
      }
      const file = path.join(root, url.pathname);
      if (fs.existsSync(file)) return route.fulfill({ path: file });
      return route.fulfill({ status: 404, body: '' });
    });
    await page.goto(origin);
    await page.evaluate(() => {
      window.API = {
        adminRequest: async (method, endpoint, body) => (await fetch('/api' + endpoint, { method, ...(body ? { body: JSON.stringify(body) } : {}) })).json(),
        loadAdminData: async (_key, load, render) => { const response = await load(); render(response); return response; },
      };
      window.showToast = () => {};
    });
    for (const file of ['scripts/services/inventory-service.js', 'scripts/components/product-form.js',
      'scripts/components/inventory-alerts.js', 'scripts/components/admin-inventory-items.js']) {
      await page.addScriptTag({ path: path.join(root, file) });
    }
    await page.evaluate(() => {
      window.inventoryFixture = () => ({ ...adminInventoryItems(), categoryOptions: [
        { value: 'medicine', label: 'Medicine' }, { value: 'food', label: 'Food' },
      ] });
    });
    await page.addScriptTag({ content: alpine });
    await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).loaded);
    const sort = page.getByLabel('Sort products'), category = page.locator('select[x-model="category"]');
    const inactive = page.locator('input[x-model="showInactive"]');
    const ready = () => page.waitForFunction(() => !Alpine.$data(document.querySelector('main')).loading);
    assert.equal(await sort.inputValue(), 'newest');
    assert.equal(new URL(origin + requests.at(-1)).searchParams.get('sort'), 'newest');
    await page.getByRole('button', { name: 'Next', exact: true }).click(); await ready();
    assert.equal(new URL(origin + requests.at(-1)).searchParams.get('page'), '2');
    await sort.selectOption('oldest'); await ready();
    assert.equal(new URL(origin + requests.at(-1)).searchParams.get('page'), '1');
    await category.selectOption('medicine'); await ready();
    await page.locator('input[x-model="q"]').fill('anti');
    await page.waitForFunction(() => Alpine.$data(document.querySelector('main'))._loadedQuery.includes('anti'));
    await inactive.check(); await ready();
    let params = new URL(origin + requests.at(-1)).searchParams;
    for (const [key, value] of Object.entries({ sort: 'oldest', category: 'medicine', q: 'anti', inactive_only: '1', page: '1' }))
      assert.equal(params.get(key), value);
    await sort.selectOption('newest'); await ready();
    assert.equal(new URL(origin + requests.at(-1)).searchParams.get('sort'), 'newest');
    const rows = page.locator('.inventory-product-row');
    await page.getByText('Out of Stock', { exact: true }).waitFor({ state: 'hidden' });
    assert.deepEqual(await page.locator('.inventory-product-status .inventory-stock-badge:visible').allTextContents(), ['Deactivated', 'Deactivated']);
    assert.equal(await rows.first().locator('.inventory-deactivation-date').textContent(), 'Nov 10, 2026');
    assert.equal(await rows.nth(1).locator('.inventory-deactivation-date').textContent(), 'Date unavailable');
    assert.deepEqual(await page.locator('.inventory-products-table thead th').allTextContents(),
      ['Item', 'Category', 'Selling Price', 'Usable Stock', 'Status', 'Actions']);
    for (const width of [320, 375, 414, 640, 768, 1024, 1199, 1200, 1280, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const layout = await page.evaluate(() => {
        const row = document.querySelector('.inventory-product-row');
        const controls = [...document.querySelectorAll('.inventory-product-filters > *')].map(el => {
          const r = el.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, right: r.right, height: r.height };
        });
        return { width: innerWidth, scroll: document.documentElement.scrollWidth, controls,
          search: document.querySelector('input[x-model="q"]').getBoundingClientRect().width,
          rowDisplay: getComputedStyle(row).display,
          statusColumn: getComputedStyle(row.querySelector('.inventory-product-status')).gridColumnStart,
          columns: [...row.children].map((cell, i) => {
            const a = cell.getBoundingClientRect(), b = document.querySelectorAll('.inventory-products-table thead th')[i].getBoundingClientRect();
            return { left: Math.abs(a.left - b.left), width: Math.abs(a.width - b.width) };
          }),
        };
      });
      assert.ok(layout.scroll <= width, `${width}: no horizontal page overflow`);
      for (const control of layout.controls) {
        assert.ok(control.x >= 0 && control.right <= width, `${width}: controls fit`);
        assert.ok(control.height >= 40, `${width}: existing usable control height`);
      }
      assert.ok(layout.search >= 230, `${width}: search retains usable width`);
      if (width < 1200) {
        assert.equal(layout.rowDisplay, 'grid'); assert.equal(layout.statusColumn, '2');
      } else {
        assert.equal(layout.rowDisplay, 'table-row');
        assert.ok(layout.columns.every(column => column.left < 1 && column.width < 1), `${width}: all six columns align`);
      }
      if (width === 1440) assert.ok(layout.controls.every(control =>
        Math.abs(control.y + control.height / 2 - layout.controls[0].y - layout.controls[0].height / 2) < 1), 'Wide toolbar fits on one line');
      if (process.env.BROWSER_SCREENSHOT_DIR && [375, 768, 1440].includes(width))
        await page.screenshot({ path: path.join(process.env.BROWSER_SCREENSHOT_DIR, `inventory-products-${width}.png`), fullPage: true });
    }
    await rows.first().click();
    await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).details.item);
    const detailsDate = page.locator('dt').filter({ hasText: /^Deactivated on$/ }).locator('..');
    assert.equal((await detailsDate.locator('dd').textContent()), 'Nov 10, 2026, 2:45 PM');
    await page.getByRole('dialog').getByRole('button', { name: 'Reactivate', exact: true }).click(); await ready();
    await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).details.item.is_active);
    await detailsDate.waitFor({ state: 'hidden' });
    assert.equal(await detailsDate.isVisible(), false);
    assert.equal(await rows.count(), 1);
    await page.getByRole('button', { name: 'Close product details', exact: true }).click();
    await inactive.uncheck(); await ready();
    await rows.first().locator('.inventory-deactivation-date').waitFor({ state: 'hidden' });
    assert.equal(await rows.first().locator('.inventory-deactivation-date').isVisible(), false);
    await page.evaluate(async () => { const ui = Alpine.$data(document.querySelector('main')); ui.alertFilter = 'low-stock'; await ui.load(1); });
    params = new URL(origin + requests.at(-1)).searchParams;
    assert.equal(params.get('low_stock'), '1'); assert.equal(params.get('sort'), 'newest');
    await page.evaluate(async () => { const ui = Alpine.$data(document.querySelector('main')); ui.alertFilter = 'expiry'; await ui.load(1); });
    assert.equal(requests.at(-1), '/api/inventory/alerts/expiry?page=1');
    await sort.waitFor({ state: 'hidden' });
    assert.equal(await sort.isVisible(), false);
    assert.deepEqual(errors, []);
    console.log('Products browser checks passed: sort/filter requests, lifecycle dates, expiry isolation, six-column alignment and 10 responsive widths.');
    await context.close();
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
