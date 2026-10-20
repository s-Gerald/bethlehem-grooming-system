// Local-only browser regression. API fixtures never contact a database or server account.
// PLAYWRIGHT_PATH can point to an existing Playwright installation.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const origin = 'http://127.0.0.1:8000';
let alpineSource;
const pages = [
  ['dashboard.html', 'Dashboard'], ['clients.html', 'Customers'],
  ['appointments.html', 'Grooming'], ['clinic.html', 'Clinic'],
  ['inventory/inventory.html', 'Inventory'], ['transactions.html', 'Transactions'],
  ['reports.html', 'Reports'], ['settings.html', 'Settings'],
  ['services.html', 'Services'], ['archive.html', 'History'], ['notifications.html', ''],
];
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const profiles = {
  admin: { role: 'admin', first_name: 'Local', last_name: 'Admin', clinic_permissions: {} },
  vet: { role: 'staff', first_name: 'Local', last_name: 'Vet', staff_type: 'clinic', staff_subrole: 'veterinarian', clinic_permissions: { access: true, services_view: true } },
  grooming: { role: 'staff', first_name: 'Local', last_name: 'Groomer', staff_type: 'grooming', staff_subrole: 'grooming_receptionist', clinic_permissions: {} },
};
async function fixture(browser, profile = 'admin', inventory = false) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const state = { user: { user_id: 7, ...profiles[profile] }, revision: 'revision-1', locked: false, expired: false, requests: [], sessionHold: null, identityHold: null, logoutHold: null };
  await context.addInitScript(({ role, inventory, origin }) => {
    if (location.origin !== origin) return;
    // Exercise the real shared sidebar against each page's HTML/CSS. Page
    // workspaces have separate loading tests and do not use these identity-only fixtures.
    document.addEventListener('alpine:init', () => {
      document.querySelectorAll('[x-data]:not([data-admin-shell])').forEach(el => {
        if (!inventory || el.getAttribute('x-data') !== 'adminInventory()') el.setAttribute('x-ignore', '');
      });
    });
    if (sessionStorage.getItem('sidebar-test')) return;
    localStorage.setItem('admin_token', '17|local-sidebar-fixture');
    localStorage.setItem('user_role', role);
    sessionStorage.setItem('sidebar-test', '1');
  }, { role: state.user.role, inventory, origin });
  // Reuse the app's real Alpine response across its two CDN URLs. CDN latency
  // should not decide whether a local sidebar regression passes.
  await context.route('**/alpinejs@*/**', async route => {
    alpineSource ||= route.fetch().then(response => response.body());
    await route.fulfill({ contentType: 'text/javascript', body: await alpineSource });
  });
  await context.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    assert.equal(url.origin, origin);
    state.requests.push(url.pathname);
    if (url.pathname === '/api/session') await state.sessionHold?.promise;
    if (url.pathname === '/api/me') await state.identityHold?.promise;
    if (url.pathname === '/api/logout') await state.logoutHold?.promise;
    let data = {};
    if (url.pathname === '/api/inventory/summary') data = { total_items: 0, low_stock_count: 0, expiry_alert_count: 0, recent_transactions: [], low_stock_items: [], expiry_items: [] };
    if (['/api/inventory/items', '/api/inventory/transactions', '/api/inventory/alerts/expiry'].includes(url.pathname)) data = { data: [], page: 1, last_page: 1, total: 0 };
    if (url.pathname === '/api/pos/online-payment') data = { payment: null };
    if (url.pathname === '/api/me') data = { user: state.user, sidebar_revision: state.revision };
    if (url.pathname.startsWith('/api/session')) {
      if (url.pathname === '/api/session/unlock') state.locked = false;
      data = { locked: state.locked, user_id: 7, role: state.user.role, sidebar_revision: state.revision,
        server_now: new Date().toISOString(), idle_at: new Date(Date.now() + 1800000).toISOString(), expires_at: new Date(Date.now() + 43200000).toISOString() };
    }
    await route.fulfill({ status: state.expired ? 401 : 200, contentType: 'application/json', body: JSON.stringify(data) });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  // Full document loads can approach 10s on localhost even with unchanged assets.
  // Keep UI assertion timeouts separate from server/resource navigation time.
  page.setDefaultNavigationTimeout(30000);
  return { context, page, state };
}
const visit = (page, file) => page.goto(`${origin}/pages/admin/${file}`, { waitUntil: 'domcontentloaded' });
const ready = async page => {
  try { await page.waitForFunction(() => document.querySelector('.admin-sidebar-menu')?.hasAttribute('data-admin-navigation-ready') && !document.documentElement.hasAttribute('data-session-gate')); }
  catch (error) {
    console.error(await page.evaluate(() => ({url: location.href, gate: document.documentElement.getAttribute('data-session-gate'),
      ready: document.querySelector('.admin-sidebar-menu')?.hasAttribute('data-admin-navigation-ready'), alpine: !!window.Alpine})));
    throw error;
  }
};
async function visibleShell(page, active) {
  const aside = page.locator('aside.admin-sidebar-menu');
  for (const label of ['Dashboard', 'Customers', 'History', 'Transactions', 'Settings']) {
    assert.equal(await aside.getByRole('link', { name: label, exact: true }).isVisible(), true, `${label} remains visible`);
  }
  assert.equal(await aside.getByRole('button', { name: 'Logout', exact: true }).isVisible(), true, 'Logout remains visible');
  if (!active) {
    assert.equal(await aside.locator('nav [aria-current="page"]').count(), 0);
    assert.equal(await aside.locator('nav a.bg-\\[\\#315b7e\\]').count(), 0);
    return;
  }
  const selected = aside.locator(active === 'Inventory' ? 'nav button[aria-controls="inventory-submenu"], nav a[href$="inventory.html"]' : 'nav [aria-current="page"]').first();
  assert.equal((await selected.innerText()).trim().replace(/[▾▸]/g, '').trim(), active);
  assert.equal(await selected.evaluate(el => el.classList.contains('bg-[#315b7e]')), true);
  assert.equal(await aside.locator('[data-admin-sidebar-loading]').count(), 0);
  await page.waitForFunction(() => {
    const icon = document.querySelector('aside.admin-sidebar-menu nav a[href$="dashboard.html"] use');
    return icon && icon.getBBox().width > 0;
  });
}
async function coldPaint(browser) {
  const f = await fixture(browser);
  try {
    // Disable JavaScript execution through resource interception: inspect actual HTML/CSS paint.
    await f.context.route('**/*', route => route.request().resourceType() === 'script' ? route.abort() : route.fallback());
    for (const [file, active] of pages.filter(([, active]) => active !== 'Services')) {
      await visit(f.page, file);
      await visibleShell(f.page, active);
      for (const href of ['clinic.html', 'appointments.html', 'reports.html']) {
        assert.equal(await f.page.locator(`aside a[href$="${href}"]`).isVisible(), false, 'Cold role alone reveals no restricted area');
      }
      assert.equal(await f.page.locator('[data-admin-account]').getAttribute('aria-busy'), 'true');
      assert.equal(await f.page.locator('aside.admin-sidebar-menu').innerText().then(t => t.includes('Clinic Manager')), false);
      assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), true);
      const icon = f.page.locator('aside nav a[href$="dashboard.html"] svg');
      assert.ok((await icon.locator('use').getAttribute('href')).includes('/assets/icons/phosphor.svg'));
      assert.equal(await icon.evaluate(el => el.getBoundingClientRect().width), 20);
      if (file === 'dashboard.html' && process.env.LOCAL_REVIEW_SCREENSHOTS) await f.page.screenshot({ path: `${process.env.LOCAL_REVIEW_SCREENSHOTS}/sidebar-cold-paint.png` });
    }
    console.log('cold HTML paint: persistent shell, static active state, restricted items concealed');
  } finally { await f.context.close(); }
}
async function cachedRefresh(browser, profile) {
  const f = await fixture(browser, profile);
  try {
    await visit(f.page, 'dashboard.html'); await ready(f.page);
    const count = () => f.state.requests.filter(p => p === '/api/me').length;
    assert.equal(count(), 1);
    for (const [file, active] of pages.filter(([file]) => !process.env.LOCAL_REVIEW_PAGES || process.env.LOCAL_REVIEW_PAGES.split(',').includes(file))) {
      if (profile !== 'admin' && (['Reports', 'Services'].includes(active) || (active === 'Clinic' && profile === 'grooming'))) continue;
      f.state.sessionHold = deferred();
      await visit(f.page, file);
      await visibleShell(f.page, active);
      if (profile === 'admin' && file === 'dashboard.html' && process.env.LOCAL_REVIEW_SCREENSHOTS) await f.page.screenshot({ path: `${process.env.LOCAL_REVIEW_SCREENSHOTS}/sidebar-cached-checking.png` });
      assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), true);
      assert.equal(await f.page.locator('.admin-sidebar-footer').evaluate(el => el.inert), true);
      assert.equal(await f.page.locator('[data-admin-account]').getAttribute('aria-busy'), 'false');
      f.state.sessionHold.resolve(); await ready(f.page);
      assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), false);
      assert.equal(count(), 1, 'Page navigation must reuse identity');
      console.log(`${profile} ${file}: cached pending-session shell passed`);
    }
    const cdp = await f.context.newCDPSession(f.page);
    for (const hard of [false, true]) {
      console.log(`${profile}: checking ${hard ? 'hard' : 'normal'} reload`);
      f.state.sessionHold = deferred();
      if (hard) {
        await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
        const loaded = f.page.waitForEvent('domcontentloaded');
        await cdp.send('Page.reload', { ignoreCache: true });
        await loaded;
      } else await f.page.reload({ waitUntil: 'domcontentloaded' });
      await visibleShell(f.page, '');
      assert.equal(await f.page.locator('html').getAttribute('data-session-gate'), 'checking');
      assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), true);
      assert.equal(count(), 1, 'Valid cached reload avoids /me');
      f.state.sessionHold.resolve(); await ready(f.page);
    }
    assert.equal(await f.page.locator('aside a[href$="clinic.html"]').isVisible(), profile !== 'grooming');
    assert.equal(await f.page.locator('aside a[href$="reports.html"]').isVisible(), profile === 'admin');
    for (const width of [320, 375, 414, 768]) {
      await f.page.setViewportSize({ width, height: 844 });
      await f.page.evaluate(() => Alpine.$data(document.querySelector('[data-admin-shell]')).sidebarOpen = true);
      await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
      await visibleShell(f.page, '');
      await f.page.getByRole('link', { name: 'Customers', exact: true }).click();
      await ready(f.page);
      await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().right <= 1);
      await visit(f.page, 'notifications.html'); await ready(f.page);
    }
    console.log(`${profile}: page sweep, normal/hard reload, no redundant /me, mobile drawer passed`);
  } finally { f.state.sessionHold?.resolve(); await f.context.close(); }
}
async function securityTransitions(browser) {
  const f = await fixture(browser);
  try {
    await visit(f.page, 'dashboard.html'); await ready(f.page);
    f.state.logoutHold = deferred();
    await f.page.getByRole('button', { name: 'Logout', exact: true }).click();
    await f.page.waitForFunction(() => !localStorage.getItem('admin_token'));
    assert.equal(await f.page.locator('aside nav').isVisible(), true);
    assert.equal(await f.page.getByRole('button', { name: 'Logout', exact: true }).isVisible(), true);
    assert.equal(await f.page.locator('aside a[href$="reports.html"]').isVisible(), false);
    assert.equal(await f.page.locator('[data-admin-account]').getAttribute('aria-busy'), 'true');
    f.state.logoutHold.resolve();
    await f.page.waitForURL('**/sign-in.html');
  } finally { f.state.logoutHold?.resolve(); await f.context.close(); }

  const g = await fixture(browser, 'vet');
  try {
    await visit(g.page, 'dashboard.html'); await ready(g.page);
    g.state.user = { user_id: 7, ...profiles.grooming };
    g.state.revision = 'revision-2';
    g.state.identityHold = deferred();
    await g.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await g.page.waitForFunction(() => !Alpine.$data(document.querySelector('[data-admin-shell]')).sidebarReady);
    await visibleShell(g.page, 'Dashboard');
    assert.equal(await g.page.locator('aside a[href$="clinic.html"]').isVisible(), false);
    assert.equal(await g.page.locator('aside a[href$="appointments.html"]').isVisible(), false);
    g.state.identityHold.resolve(); await ready(g.page);
    assert.equal(g.state.requests.filter(p => p === '/api/me').length, 2, 'Revision change refetches identity exactly once');
    assert.equal(await g.page.locator('aside a[href$="clinic.html"]').isVisible(), false);
    assert.equal(await g.page.locator('aside a[href$="appointments.html"]').isVisible(), true);
    g.state.locked = true;
    await g.page.reload({ waitUntil: 'domcontentloaded' });
    await g.page.getByRole('heading', { name: 'Session locked', exact: true }).waitFor();
    assert.equal(await g.page.locator('aside.admin-sidebar-menu').evaluate(el => getComputedStyle(el).visibility), 'hidden');
    assert.equal(await g.page.getByRole('button', { name: 'Logout', exact: true }).isVisible(), false);
    await g.page.getByLabel('Password', { exact: true }).fill('LocalFixturePassword');
    await g.page.getByRole('button', { name: 'Unlock session', exact: true }).click();
    await ready(g.page); await visibleShell(g.page, 'Dashboard');
    assert.equal(g.state.requests.filter(p => p === '/api/me').length, 2, 'Unlock retains valid navigation identity');
    g.state.expired = true;
    await g.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await g.page.waitForURL('**/sign-in.html');
    assert.equal(await g.page.evaluate(() => localStorage.getItem('admin_token')), null);
    assert.equal(await g.page.evaluate(() => sessionStorage.getItem('bethlehem.admin.navigation.v1')), null);
    console.log('revision invalidation, logout clearing, full lock/unlock, expired-session redirect passed');
  } finally { g.state.identityHold?.resolve(); await g.context.close(); }
}
async function invalidCaches(browser) {
  for (const kind of ['cold', 'malformed', 'expired', 'other-account']) {
    const f = await fixture(browser, 'grooming');
    try {
      await visit(f.page, 'dashboard.html'); await ready(f.page);
      await f.page.evaluate(kind => {
        const key = 'bethlehem.admin.navigation.v1';
        const cached = JSON.parse(sessionStorage.getItem(key));
        if (kind === 'cold') sessionStorage.removeItem(key);
        if (kind === 'malformed') sessionStorage.setItem(key, '{invalid');
        if (kind === 'expired') { cached.at = Date.now() - 12 * 3600000; sessionStorage.setItem(key, JSON.stringify(cached)); }
        if (kind === 'other-account') { cached.owner = 'other-account'; sessionStorage.setItem(key, JSON.stringify(cached)); }
      }, kind);
      f.state.identityHold = deferred();
      await f.page.reload({ waitUntil: 'domcontentloaded' });
      await f.page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
      await visibleShell(f.page, 'Dashboard');
      assert.equal(await f.page.locator('.admin-sidebar-footer').evaluate(el => el.inert), false, 'Verified session makes Logout usable without waiting for /me');
      for (const href of ['clinic.html', 'appointments.html', 'reports.html']) assert.equal(await f.page.locator(`aside a[href$="${href}"]`).isVisible(), false);
      assert.equal(await f.page.locator('[data-admin-account]').getAttribute('aria-busy'), 'true');
      const before = await f.page.getByRole('button', { name: 'Logout', exact: true }).boundingBox();
      f.state.identityHold.resolve(); await ready(f.page);
      const after = await f.page.getByRole('button', { name: 'Logout', exact: true }).boundingBox();
      assert.equal(before.y, after.y, 'Identity hydration does not move Logout');
      assert.equal(await f.page.locator('aside a[href$="appointments.html"]').isVisible(), true);
      assert.equal(await f.page.locator('aside a[href$="clinic.html"]').isVisible(), false);
    } finally { f.state.identityHold?.resolve(); await f.context.close(); }
  }
  console.log('cold/malformed/expired/other-account caches: safe shell and independent account hydration passed');
}
// Assert computed paint as well as semantics: the original bug had correct
// aria-current values while the first destination kept its white background.
async function inventoryHighlight(page, section, inside = true) {
  assert.equal(await page.locator('#inventory-submenu').isVisible(), true, 'Inventory submenu is expanded');
  await page.mouse.move(900, 10);
  const links = await page.locator('#inventory-submenu a').evaluateAll(elements => elements.map(el => ({
    section: new URL(el.href).hash.slice(1), current: el.getAttribute('aria-current'),
    background: getComputedStyle(el).backgroundColor, weight: getComputedStyle(el).fontWeight,
  })));
  const expected = ['overview', 'products', 'sell-product', 'history'].includes(section) ? [section] : [];
  assert.deepEqual(links.filter(el => el.current === 'page').map(el => el.section), expected);
  assert.deepEqual(links.filter(el => el.background === 'rgba(255, 255, 255, 0.65)').map(el => el.section), expected,
    `Only ${section || 'no subtab'} receives the white active highlight`);
  for (const link of links) {
    assert.equal(link.current, expected.includes(link.section) ? 'page' : null);
    assert.equal(link.weight, expected.includes(link.section) ? '800' : '600');
  }
  assert.equal(await page.locator('[aria-controls="inventory-submenu"]').evaluate(el => getComputedStyle(el).backgroundColor === 'rgb(49, 91, 126)'), inside);
}
async function inventoryNavigation(browser) {
  const f = await fixture(browser, 'admin', true);
  const cdp = await f.context.newCDPSession(f.page);
  const listenerCount = async () => (await cdp.send('Runtime.evaluate', {
    expression: '(getEventListeners(window).hashchange || []).length', includeCommandLineAPI: true, returnByValue: true,
  })).result.value;
  const errors = [];
  f.page.on('pageerror', error => errors.push(error.message));
  const loaded = section => f.page.waitForFunction(section => {
    const el = document.querySelector('main[x-data="adminInventory()"]');
    const ui = el && window.Alpine && Alpine.$data(el);
    return ui?.activeSection === section && !ui.sectionLoading && !ui.sectionError;
  }, section).catch(async error => {
    console.error('Inventory loading failure:', await f.page.evaluate(() => {
      const ui = window.Alpine && Alpine.$data(document.querySelector('main'));
      return { hash: location.hash, section: ui?.activeSection, loading: ui?.sectionLoading, error: ui?.sectionError };
    }), errors);
    throw error;
  });
  const choose = async section => {
    await f.page.locator(`#inventory-submenu a[href$="#${section}"]`).click();
    await loaded(section); await inventoryHighlight(f.page, section);
  };
  const entries = ['overview', 'products', 'sell-product', 'history'];
  try {
    for (const entry of entries) {
      await visit(f.page, 'dashboard.html'); await ready(f.page);
      await f.page.getByRole('button', { name: 'Inventory' }).click();
      await inventoryHighlight(f.page, '', false);
      await choose(entry); await ready(f.page);
      await f.page.waitForFunction(() => !!Alpine.$data(document.querySelector('[data-admin-shell]'))._incomingAppointmentInterval);
      assert.equal(await listenerCount(), 2, 'Only the sidebar and Inventory router listen for hash changes');
      assert.deepEqual(await f.page.locator('[data-inventory-section]').evaluateAll(els => els.map(el => el.dataset.inventorySection)), [entry], 'Entry mounts only its own section');
      const sidebarRequests = () => f.state.requests.filter(p => !p.startsWith('/api/inventory/') && !p.startsWith('/api/pos/'));
      const beforeSidebar = sidebarRequests();
      await f.page.evaluate(() => window.inventoryTestDocument = true);
      for (const section of [...entries, 'overview']) await choose(section);
      const before = f.state.requests.length;
      assert.deepEqual(await f.page.locator('[data-inventory-section]').evaluateAll(els => els.map(el => el.dataset.inventorySection).sort()), [...entries].sort(), 'Inactive action sections remain unmounted');
      for (const section of entries) await choose(section);
      assert.equal(f.state.requests.length, before, 'Revisiting mounted sections makes no API requests');
      assert.deepEqual(sidebarRequests(), beforeSidebar, 'Subtabs make no sidebar API requests');
      assert.equal(await f.page.evaluate(() => !!window.inventoryTestDocument), true, 'Subtabs preserve the current document');
      assert.equal(await f.page.evaluate(() => Alpine.$data(document.querySelector('main')).inventoryRevision), 0);
      assert.equal(await listenerCount(), 2, 'Navigation does not register duplicate hash listeners');
      await f.page.goBack(); await loaded('sell-product'); await inventoryHighlight(f.page, 'sell-product');
      await f.page.goForward(); await loaded('history'); await inventoryHighlight(f.page, 'history');
      console.log(`Inventory entry ${entry}: exclusive paint, cached navigation, history passed (${before} total API requests)`);
    }
    for (const [hash, section] of [['products?filter=low-stock', 'products'], ['stock-in', 'stock-in'], ['stock-out', 'stock-out'], ['invalid', 'overview']]) {
      await f.page.goto('about:blank');
      await visit(f.page, `inventory/inventory.html#${hash}`); await ready(f.page); await loaded(section);
      await inventoryHighlight(f.page, section);
      assert.deepEqual(await f.page.locator('[data-inventory-section]').evaluateAll(els => els.map(el => el.dataset.inventorySection)), [section]);
      for (const hard of [false, true]) {
        if (hard) {
          const reloaded = f.page.waitForEvent('domcontentloaded');
          await cdp.send('Page.reload', { ignoreCache: true }); await reloaded;
        } else await f.page.reload({ waitUntil: 'domcontentloaded' });
        await ready(f.page); await loaded(section); await inventoryHighlight(f.page, section);
      }
    }
    await f.page.getByRole('link', { name: 'Clinic', exact: true }).click(); await ready(f.page);
    await f.page.waitForLoadState('domcontentloaded');
    await f.page.getByRole('button', { name: 'Inventory' }).click(); await inventoryHighlight(f.page, '', false);
    await choose('overview');
    if (process.env.LOCAL_REVIEW_SCREENSHOTS) await f.page.screenshot({ path: `${process.env.LOCAL_REVIEW_SCREENSHOTS}/inventory-sidebar-desktop.png` });
    for (const width of [375, 768]) {
      await f.page.setViewportSize({ width, height: 844 });
      for (const section of entries) {
        await f.page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        await choose(section);
        await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().right <= 1);
      }
      if (process.env.LOCAL_REVIEW_SCREENSHOTS) {
        await f.page.getByRole('button', { name: 'Open navigation', exact: true }).click();
        await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
        await f.page.screenshot({ path: `${process.env.LOCAL_REVIEW_SCREENSHOTS}/inventory-sidebar-${width}.png` });
        await f.page.evaluate(() => Alpine.$data(document.querySelector('[data-admin-shell]')).sidebarOpen = false);
      }
    }
    assert.deepEqual(errors, [], 'Inventory navigation produces no JavaScript errors');
    console.log('Inventory direct/query/action/invalid routes, reloads, return from Clinic and mobile passed');
  } finally { await f.context.close(); }
}
async function cachedBeforeAlpine(browser) {
  const f = await fixture(browser, 'vet');
  try {
    await visit(f.page, 'dashboard.html'); await ready(f.page);
    for (const file of ['settings.html', ...['overview', 'products?filter=expiry', 'sell-product', 'history', 'stock-in', 'stock-out', 'invalid'].map(hash => `inventory/inventory.html#${hash}`)]) {
      const alpine = deferred();
      const hold = async route => { await alpine.promise; await route.fallback(); };
      await f.context.route('**/alpinejs@*/**', hold);
      try {
        // DOMContentLoaded waits for defer scripts; commit lets us inspect the sidebar while Alpine is held.
        // Leave the document so successive hashes exercise fresh first paint.
        await f.page.goto('about:blank');
        await f.page.goto(`${origin}/pages/admin/${file}`, { waitUntil: 'commit' });
        await f.page.locator('.admin-sidebar-menu[data-admin-navigation-ready]').waitFor({ state: 'attached' });
        assert.equal(await f.page.evaluate(() => typeof window.Alpine), 'undefined', `${file}: cached highlight is painted before Alpine loads`);
        assert.equal(await f.page.locator('[data-admin-account] p').first().innerText(), 'Local Vet');
        assert.equal(await f.page.locator('[data-admin-account] p').last().innerText(), 'Veterinarian');
        assert.equal(await f.page.locator('aside a[href$="clinic.html"]').isVisible(), true);
        assert.equal(await f.page.locator('aside a[href$="appointments.html"]').isVisible(), false);
        assert.equal(await f.page.locator('aside a[href$="reports.html"]').isVisible(), false);
        assert.equal(f.state.requests.filter(p => p === '/api/me').length, 1);
        const hash = file.split('#')[1]?.split('?')[0];
        const section = hash === 'invalid' ? 'overview' : hash;
        if (hash) await inventoryHighlight(f.page, section);
        if (hash === 'overview') {
          await f.page.evaluate(() => { location.hash = '#products?filter=low-stock'; renderCachedAdminSidebar(); });
          await inventoryHighlight(f.page, 'products');
          await f.page.evaluate(() => { location.hash = '#overview'; renderCachedAdminSidebar(); });
          await inventoryHighlight(f.page, 'overview');
        }
        alpine.resolve(); await ready(f.page);
        await f.page.waitForFunction(() => !!document.querySelector('[data-admin-shell]')._x_dataStack);
        if (hash) await inventoryHighlight(f.page, section);
      } finally { alpine.resolve(); await f.context.unroute('**/alpinejs@*/**', hold); }
    }
    console.log('cached identity and permissions render before Alpine, including nested Inventory');
  } finally { await f.context.close(); }
}
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    const cases = process.env.LOCAL_REVIEW_CASES?.split(',') || ['cold', 'cache', 'security', 'invalid', 'pre-alpine', 'inventory'];
    if (cases.includes('cold')) await coldPaint(browser);
    if (cases.includes('cache')) for (const profile of Object.keys(profiles).filter(profile => !process.env.LOCAL_REVIEW_PROFILES || process.env.LOCAL_REVIEW_PROFILES.split(',').includes(profile))) await cachedRefresh(browser, profile);
    if (cases.includes('security')) await securityTransitions(browser);
    if (cases.includes('invalid')) await invalidCaches(browser);
    if (cases.includes('pre-alpine')) await cachedBeforeAlpine(browser);
    if (cases.includes('inventory')) await inventoryNavigation(browser);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
