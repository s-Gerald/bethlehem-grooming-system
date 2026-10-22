// Local-only browser tests: real HTML/CSS/Alpine with isolated API fixtures.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const origin = 'http://127.0.0.1:8000';
const evidence = process.env.LOCAL_REVIEW_SCREENSHOTS;
const activities = [
  ['pre_registrations', 'Pre-registrations'], ['schedule_changes', 'Schedule changes'],
  ['payments', 'Payments'], ['cancellations', 'Cancellations'], ['expired', 'No show'],
].map(([value, label]) => ({value, label}));
const types = ['booked', 'payment_due', 'cancelled', 'clinic_booked', 'clinic_payment_due', 'clinic_cancelled'];
const records = () => Array.from({length: 24}, (_, i) => {
  const type = types[i % types.length], service = type.startsWith('clinic') ? 'clinic' : 'grooming';
  const activity = type.endsWith('booked') ? 'pre_registrations' : type.endsWith('cancelled') ? 'cancellations' : 'payments';
  return {notification_id: i + 1, type, service, activity, icon: 'calendar-days', title: `${service} ${activity}`,
    message_parts: [{text: `${service} ${activity} notification ${i + 1}`, emphasized: true}], context_parts: [],
    is_read: i % 4 === 0, created_at: new Date(Date.now() - i * 60000).toISOString()};
});
const deferred = () => {let resolve; const promise = new Promise(r => resolve = r); return {promise, resolve};};
let alpine;
async function fixture(browser, role = 'admin') {
  const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
  const state = {rows: records(), requests: [], errors: [], locked: false, expired: false, hold: null};
  const user = {user_id: 7, role: role === 'admin' ? 'admin' : 'staff', first_name: 'Local', last_name: 'Review',
    staff_type: role === 'grooming' ? 'grooming' : 'clinic', staff_subrole: role === 'grooming' ? 'grooming_receptionist' : 'veterinarian',
    clinic_permissions: role === 'grooming' ? {} : {access: true, services_view: true, clinical: true}};
  await context.addInitScript(({origin, role}) => {
    if (location.origin !== origin || sessionStorage.getItem('notification-fixture')) return;
    localStorage.setItem('admin_token', '17|local-notification-fixture');
    localStorage.setItem('user_role', role);
    sessionStorage.setItem('notification-fixture', '1');
  }, {origin, role: user.role});
  await context.route('**/alpinejs@*/**', async route => {
    alpine ||= route.fetch().then(response => response.body());
    await route.fulfill({contentType: 'text/javascript', body: await alpine});
  });
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url()); assert.equal(url.origin, origin);
    const path = url.pathname, params = url.searchParams;
    state.requests.push({path, query: url.search, method: route.request().method()});
    let data = {};
    if (path === '/api/me') data = {user, sidebar_revision: 'notification-fixture-1'};
    if (path.startsWith('/api/session')) {
      if (path.endsWith('/unlock')) state.locked = false;
      data = {locked: state.locked, user_id: 7, role: user.role, sidebar_revision: 'notification-fixture-1',
        server_now: new Date().toISOString(), idle_at: new Date(Date.now() + 1800000).toISOString(), expires_at: new Date(Date.now() + 43200000).toISOString()};
    }
    if (path === '/api/admin/notifications') {
      if (params.get('service') === 'grooming') await state.hold?.promise;
      const unread_count = state.rows.filter(n => !n.is_read).length;
      const rows = state.rows.filter(n => (params.get('status') !== 'unread' || !n.is_read)
        && (!params.get('service') || params.get('service') === 'all' || n.service === params.get('service'))
        && (!params.get('activity') || params.get('activity') === 'all' || n.activity === params.get('activity')));
      const page = Number(params.get('page') || 1), perPage = Number(params.get('per_page') || 30);
      data = params.get('mode') === 'count' ? {unread_count} : {unread_count, notifications: rows.slice((page - 1) * perPage, page * perPage),
        activity_options: activities, pagination: {has_more: page * perPage < rows.length}};
    }
    if (path.startsWith('/api/admin/notifications/')) {
      const id = path.split('/')[4];
      state.rows = state.rows.map(n => id === 'read-all' || n.notification_id === Number(id) ? {...n, is_read: true} : n);
      data = {success: true, unread_count: state.rows.filter(n => !n.is_read).length};
    }
    if (path === '/api/admin/dashboard/summary') data = {summary: {}, stats: {}};
    if (path === '/api/admin/bookings') data = {bookings: []};
    if (path === '/api/admin/clinic-cases') data = {cases: []};
    if (path === '/api/admin/clinic-records') data = {records: [], pagination: {has_more: false}};
    await route.fulfill({status: state.expired ? 401 : 200, contentType: 'application/json', body: JSON.stringify(data)});
  });
  const page = await context.newPage(); page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(45000);
  page.on('pageerror', error => state.errors.push(error.message));
  return {context, page, state};
}
const visit = (page, file) => page.goto(`${origin}/pages/admin/${file}`, {waitUntil: 'domcontentloaded'});
const ready = page => page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate') && document.querySelector('[data-admin-shell]')?.hasAttribute('data-admin-ready'));
const preview = page => page.locator('#admin-notification-preview');
const notificationRequests = state => state.requests.filter(r => r.path === '/api/admin/notifications');
async function contained(locator, page) {
  const box = await locator.boundingBox(), size = page.viewportSize();
  assert.ok(box && box.x >= 0 && box.y >= 0 && box.x + box.width <= size.width + 1 && box.y + box.height <= size.height + 1, JSON.stringify({box, size}));
  assert.equal(await locator.evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(255, 255, 255)');
}
async function bellsAndNavigation(browser) {
  const f = await fixture(browser);
  const budgets = [];
  try {
    for (const [file, from, label] of [['dashboard.html', 'dashboard', 'Dashboard'], ['appointments.html', 'grooming', 'Grooming'], ['clinic.html', 'clinic', 'Clinic']]) {
      const before = notificationRequests(f.state).length;
      await visit(f.page, file); await ready(f.page);
      const bell = f.page.getByRole('button', {name: /^Notifications/}); await bell.waitFor();
      await f.page.waitForFunction(() => document.querySelector('.admin-notification-badge')?.textContent === '18');
      assert.ok(notificationRequests(f.state).slice(before).every(r => r.query === '?mode=count'), 'Closed bell loads only a count');
      budgets.push({page: file, startup: notificationRequests(f.state).slice(before).map(r => r.query), previewLimit: 10});
      await bell.click(); await preview(f.page).waitFor({state: 'visible'});
      await preview(f.page).locator('.admin-notification-row').first().waitFor();
      assert.equal(await preview(f.page).locator('.admin-notification-row').count(), 10);
      await contained(preview(f.page), f.page);
      assert.equal(await preview(f.page).evaluate(el => getComputedStyle(el).outlineStyle), 'none', 'Pointer opening never outlines the popup');
      const previewCount = notificationRequests(f.state).filter(r => r.query.includes('mode=dropdown')).length;
      await f.page.keyboard.press('Tab');
      assert.equal(await preview(f.page).evaluate(el => el.contains(document.activeElement)), true, 'Tab enters the dropdown controls');
      assert.equal(await f.page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle), 'solid', 'Keyboard focus remains visible on popup controls');
      await f.page.keyboard.press('Escape'); await preview(f.page).waitFor({state: 'hidden'});
      assert.equal(await bell.evaluate(el => document.activeElement === el), true, 'Escape returns focus to the bell');
      await bell.click(); await preview(f.page).locator('.admin-notification-row').first().waitFor();
      assert.equal(notificationRequests(f.state).filter(r => r.query.includes('mode=dropdown')).length, previewCount, 'Reopening reuses a fresh preview');
      for (const width of [320, 375, 414, 768, 1440]) {
        await f.page.setViewportSize({width, height: 844}); await contained(preview(f.page), f.page);
        assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      }
      if (evidence && from === 'clinic') {
        await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= -1);
        await f.page.screenshot({path: `${evidence}/notifications-clinic-preview.png`});
      }
      await preview(f.page).getByRole('link', {name: 'See all notifications'}).click();
      assert.ok(f.page.url().endsWith(`?from=${from}`)); await ready(f.page);
      await f.page.locator('main .admin-notification-row').first().waitFor();
      const active = f.page.locator('aside nav a.bg-\\[\\#315b7e\\]');
      assert.equal((await active.innerText()).trim(), label);
      assert.equal(await f.page.locator('aside [aria-current="page"]').count(), 0);
      const unrelated = f.state.requests.length;
      const cdp = await f.context.newCDPSession(f.page); await cdp.send('Network.setCacheDisabled', {cacheDisabled: true});
      await f.page.reload({waitUntil: 'domcontentloaded'}); await ready(f.page);
      assert.equal((await active.innerText()).trim(), label);
      assert.equal(await f.page.locator('aside [aria-current="page"]').count(), 0);
      assert.equal(f.state.requests.slice(unrelated).some(r => /dashboard|bookings|grooming\/activity|clinic-cases/.test(r.path)), false, 'Origin must not fetch its workspace');
    }
    for (const from of ['', '?from=invalid', '?from=__proto__']) {
      await visit(f.page, 'notifications.html' + from); await ready(f.page);
      assert.equal(await f.page.locator('aside nav a.bg-\\[\\#315b7e\\]').count(), 0);
    }
    assert.deepEqual(f.state.errors, []);
    if (evidence) require('node:fs').writeFileSync(`${evidence}/notifications-network.json`, JSON.stringify({budgets, requests: f.state.requests}, null, 2));
    console.log('Notification request budgets: ' + JSON.stringify(budgets));
    console.log('Three bells, desktop/mobile geometry, cached previews, origins and hard refresh passed');
  } finally {await f.context.close();}
}
async function filtersAndMutations(browser) {
  const f = await fixture(browser);
  try {
    await visit(f.page, 'notifications.html?from=clinic'); await ready(f.page);
    await f.page.locator('main .admin-notification-row').first().waitFor();
    await f.page.getByRole('button', {name: 'Load More', exact: true}).click();
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 20);
    const service = f.page.getByRole('combobox', {name: 'Service', exact: true});
    const activity = f.page.getByRole('combobox', {name: 'Notification activity', exact: true});
    assert.deepEqual(await activity.locator('option').allTextContents(), ['All Activities', 'Pre-registrations', 'Schedule changes', 'Payments', 'Cancellations', 'No show']);
    for (const control of [service, activity]) {
      await control.click(); await f.page.keyboard.press('Escape');
      assert.equal(await control.evaluate(el => getComputedStyle(el).outlineStyle), 'none', 'Pointer focus has no filter outline');
    }
    await service.press('Tab');
    assert.equal(await activity.evaluate(el => document.activeElement === el && getComputedStyle(el).outlineStyle === 'solid'), true, 'Tab focus remains visible on native filters');
    for (const selected of ['clinic', 'grooming']) {
      await service.selectOption(selected); await activity.selectOption('payments');
      await f.page.waitForFunction(service => [...document.querySelectorAll('main .admin-notification-row')].length === 4 && [...document.querySelectorAll('main .admin-notification-row')].every(el => el.textContent.includes(service + ' payments')), selected);
      const query = new URLSearchParams(notificationRequests(f.state).at(-1).query); assert.equal(query.get('page'), '1');
      await activity.selectOption('cancellations');
      await f.page.waitForFunction(service => [...document.querySelectorAll('main .admin-notification-row')].length === 4 && [...document.querySelectorAll('main .admin-notification-row')].every(el => el.textContent.includes(service + ' cancellations')), selected);
    }
    await service.selectOption('clinic'); await activity.selectOption('payments');
    await f.page.getByRole('button', {name: /^Unread/}).click();
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 2);
    await f.page.locator('main .admin-notification-row').first().click();
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 1);
    await f.page.getByRole('button', {name: 'Mark all as read'}).click();
    await f.page.getByText('No notifications match these filters.').waitFor();
    assert.equal(f.state.rows.some(n => !n.is_read), false, 'Mark all preserves global semantics');
    for (const width of [320, 375, 414, 768, 1440]) {
      await f.page.setViewportSize({width, height: 844});
      await contained(service, f.page); await contained(activity, f.page);
      await activity.focus(); await activity.press('ArrowDown'); await activity.press('Escape');
    }
    if (evidence) {
      await f.page.getByRole('button', {name: 'All', exact: true}).click();
      await activity.selectOption('payments');
      await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= -1 && document.querySelectorAll('main .admin-notification-row').length === 4);
      await f.page.screenshot({path: `${evidence}/notifications-filters.png`});
    }
    assert.deepEqual(f.state.errors, []);
    console.log('Rendered native filters, combinations, unread mutations, pagination reset and mobile controls passed');
  } finally {await f.context.close();}
}
async function permissionsAndLifecycle(browser) {
  for (const role of ['grooming', 'clinic']) {
    const f = await fixture(browser, role);
    try {
      await visit(f.page, role === 'grooming' ? 'appointments.html' : 'clinic.html'); await ready(f.page);
      const bell = f.page.getByRole('button', {name: /^Notifications/}); await bell.click();
      await preview(f.page).locator('.admin-notification-row').first().waitFor();
      await f.page.locator('h2:visible').first().click(); assert.equal(await preview(f.page).isVisible(), false, 'Outside click closes preview');
      f.state.locked = true; await f.page.reload({waitUntil: 'domcontentloaded'});
      await f.page.getByRole('heading', {name: 'Session locked'}).waitFor();
      assert.equal(await preview(f.page).isVisible(), false);
      const count = notificationRequests(f.state).length;
      await f.page.evaluate(() => window.dispatchEvent(new Event('focus')));
      assert.equal(notificationRequests(f.state).length, count, 'Locked session makes no notification reads');
      await f.page.getByLabel('Password', {exact: true}).fill('LocalFixturePassword');
      await f.page.getByRole('button', {name: 'Unlock session'}).click(); await ready(f.page);
      await bell.click(); await preview(f.page).locator('.admin-notification-row').first().waitFor();
      await bell.press('Escape');
      await f.page.getByRole('button', {name: 'Logout', exact: true}).click();
      await f.page.waitForURL('**/sign-in.html');
      assert.equal(await f.page.evaluate(() => localStorage.getItem('admin_token')), null);
      assert.deepEqual(f.state.errors, []);
    } finally {await f.context.close();}
  }
  console.log('Staff permissions, outside click, session lock/unlock and logout passed');
}
async function preAlpineOrigins(browser) {
  const f = await fixture(browser);
  try {
    await visit(f.page, 'dashboard.html'); await ready(f.page);
    for (const [from, label] of [['dashboard', 'Dashboard'], ['grooming', 'Grooming'], ['clinic', 'Clinic'], ['invalid', '']]) {
      const hold = deferred();
      const handler = async route => {await hold.promise; await route.fallback();};
      await f.context.route('**/alpinejs@*/**', handler);
      try {
        await f.page.goto(`${origin}/pages/admin/notifications.html?from=${from}`, {waitUntil: 'commit'});
        await f.page.waitForFunction(() => typeof renderCachedAdminSidebar === 'function');
        const selected = f.page.locator('aside nav a.bg-\\[\\#315b7e\\]');
        assert.equal(await selected.count(), label ? 1 : 0);
        if (label) assert.equal((await selected.innerText()).trim(), label);
        assert.equal(await f.page.locator('aside [aria-current="page"]').count(), 0);
        assert.equal(await f.page.evaluate(() => !!document.querySelector('[data-admin-shell]')._x_dataStack), false);
      } finally {hold.resolve(); await f.context.unroute('**/alpinejs@*/**', handler);}
      await ready(f.page);
    }
    console.log('Correct origin styling before Alpine, with no incorrect aria-current or Grooming flash passed');
  } finally {await f.context.close();}
}
async function crossTabAndHiddenPolling(browser) {
  const f = await fixture(browser);
  try {
    await f.page.clock.install();
    await visit(f.page, 'dashboard.html'); await ready(f.page);
    await f.page.getByRole('button', {name: /^Notifications/}).click();
    await preview(f.page).locator('.admin-notification-row').first().waitFor();
    const other = await f.context.newPage();
    await visit(other, 'clinic.html'); await ready(other);
    await other.getByRole('button', {name: /^Notifications/}).click();
    await preview(other).locator('.admin-notification-row').first().waitFor();
    if (!await preview(f.page).isVisible()) await f.page.getByRole('button', {name: /^Notifications/}).click();
    await preview(f.page).locator('.admin-notification-row.is-unread').first().click();
    await other.waitForFunction(() => document.querySelector('.admin-notification-badge').textContent === '17');
    if (!await preview(other).isVisible()) await other.getByRole('button', {name: /^Notifications/}).click();
    await other.waitForFunction(() => document.querySelectorAll('#admin-notification-preview .is-unread').length === 6);
    await other.close();
    const service = f.state.requests.length;
    await f.page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'hidden'});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await f.page.clock.fastForward(31000);
    assert.equal(f.state.requests.slice(service).some(r => r.path === '/api/admin/notifications'), false, 'Hidden tab pauses notification requests');
    await f.page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', {configurable: true, get: () => 'visible'});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await f.page.waitForFunction(() => document.querySelector('.admin-notification-badge').textContent === '17');
    assert.deepEqual(f.state.errors, []);
    console.log('Cross-tab read/count synchronization and hidden-tab request pause passed');
  } finally {await f.context.close();}
}
async function noShowFiltersAndServerRefresh(browser) {
  const f = await fixture(browser);
  const noShow = (id, service, is_read = false) => ({notification_id: id, type: service === 'clinic' ? 'clinic_no_show' : 'no_show',
    service, activity: 'expired', icon: 'history', title: `No show — ${service}`,
    message_parts: [{text: `No show — ${service}: Local Review · Mochi`, emphasized: false}],
    context_parts: [{text: 'Pre-registration for Oct 20 · No check-in recorded', emphasized: false}], is_read, created_at: new Date().toISOString()});
  try {
    await f.page.clock.install();
    f.state.rows = [noShow(100, 'grooming'), noShow(101, 'clinic', true)];
    await visit(f.page, 'notifications.html'); await ready(f.page);
    const service = f.page.getByRole('combobox', {name: 'Service', exact: true});
    const activity = f.page.getByRole('combobox', {name: 'Notification activity', exact: true});
    await activity.selectOption('expired');
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 2);
    for (const selected of ['grooming', 'clinic']) {
      await service.selectOption(selected);
      await f.page.waitForFunction(service => document.querySelectorAll('main .admin-notification-row').length === 1
        && document.querySelector('main .admin-notification-row').textContent.includes(`No show — ${service}`), selected);
    }
    await service.selectOption('all');
    await f.page.getByRole('button', {name: /^Unread/}).click();
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 1);
    f.state.rows.unshift(noShow(102, 'clinic'));
    await f.page.clock.fastForward(31000);
    await f.page.waitForFunction(() => document.querySelectorAll('main .admin-notification-row').length === 2);
    const before = notificationRequests(f.state).length;
    await f.page.clock.fastForward(31000);
    assert.equal(notificationRequests(f.state).slice(before).some(r => r.query.includes('mode=full')), false, 'Unchanged count does not refetch full history');
    assert.deepEqual(f.state.errors, []);
    console.log('No show service/unread filters and server-generated event visibility passed');
  } finally {await f.context.close();}
}
(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {})});
  try {
    for (const test of [bellsAndNavigation, filtersAndMutations, permissionsAndLifecycle, preAlpineOrigins, crossTabAndHiddenPolling, noShowFiltersAndServerRefresh]) {
      if (!process.env.LOCAL_REVIEW_CASES || process.env.LOCAL_REVIEW_CASES.split(',').includes(test.name)) await test(browser);
    }
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
