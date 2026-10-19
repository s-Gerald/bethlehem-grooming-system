// Local-only browser regression; API fixtures never contact real accounts.
// Run with PLAYWRIGHT_PATH pointing to an installed Playwright package if needed.
// CLINIC_BASELINE=1 compares requests using the unchanged HEAD Clinic sources.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const origin = 'http://127.0.0.1:8000';
const url = origin + '/pages/admin/clinic.html#cases';
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const selector = '[x-data="adminClinicCasesSection()"]';
const alert = `${selector} [role="alert"][x-text="accessError"]`;
let alpineSource;

async function fixture(browser) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 800 } });
  const state = { requests: [], sessionHold: null, identityHold: null, alpineHold: null,
    identityStatus: 200, casesStatus: 200, access: true };
  await context.addInitScript(() => {
    localStorage.setItem('admin_token', '17|local-clinic-fixture');
    localStorage.setItem('user_role', 'admin');
    // Record every insertion, including alerts inserted and removed between frames.
    window.clinicPaint = { alerts: [], close: false };
    new MutationObserver(() => {
      const errors = document.querySelectorAll('[role="alert"][x-text="accessError"]');
      for (const el of errors) window.clinicPaint.alerts.push(el.textContent);
      if (document.querySelector('[\\@click="detail.open = false"]')) window.clinicPaint.close = true;
    }).observe(document, { childList: true, subtree: true });
  });
  await context.route('**/alpinejs@*/**', async route => {
    alpineSource ||= route.fetch().then(response => response.body());
    const body = await alpineSource;
    await state.alpineHold?.promise;
    await route.fulfill({ contentType: 'text/javascript', body });
  });
  if (process.env.CLINIC_BASELINE) {
    for (const file of ['pages/admin/clinic.html', 'scripts/components/admin-clinic.js']) {
      const body = execFileSync('git', ['show', 'HEAD:' + file], { encoding: 'utf8' });
      await context.route(origin + '/' + file + '*', route => route.fulfill({
        contentType: file.endsWith('.html') ? 'text/html' : 'text/javascript', body,
      }));
    }
  }
  await context.route('**/api/**', async route => {
    const endpoint = new URL(route.request().url());
    assert.equal(endpoint.origin, origin, 'Fixtures must never contact a live API');
    state.requests.push(endpoint.pathname + endpoint.search);
    if (endpoint.pathname === '/api/session') await state.sessionHold?.promise;
    if (endpoint.pathname === '/api/me') await state.identityHold?.promise;
    let status = 200, data = {};
    if (endpoint.pathname.startsWith('/api/session')) data = {
      locked: false, user_id: 7, role: 'admin', sidebar_revision: 'clinic-test-1',
      server_now: new Date().toISOString(), idle_at: new Date(Date.now() + 1800000).toISOString(),
      expires_at: new Date(Date.now() + 43200000).toISOString(),
    };
    if (endpoint.pathname === '/api/me') {
      status = state.identityStatus;
      data = status === 200 ? { sidebar_revision: 'clinic-test-1', user: { user_id: 7, role: 'admin',
        first_name: 'Local', last_name: 'Admin', clinic_permissions: { access: state.access, clinical: true, payment: true } } }
        : { message: 'Local identity verification failed.' };
    }
    if (endpoint.pathname === '/api/admin/clinic-cases') {
      status = state.casesStatus;
      data = { today: '2026-10-19', confined_count: 0, arrivals: [], cases: Array.from({ length: 16 }, (_, i) => ({
        id: i + 1, status: 'in_consultation', case_type: 'consultation', appointment_reference: `CL-TEST-${i + 1}`,
        appointment_date: '2026-10-19', pet: { id: i + 1, name: `Local patient ${i + 1}`, species: 'Dog' },
        owner: { first_name: 'Local', last_name: 'Owner' },
      })) };
    }
    if (endpoint.pathname === '/api/admin/clinic-records') data = { records: [], current_page: 1, last_page: 1 };
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  const network = [];
  cdp.on('Network.requestWillBeSent', event => {
    if (event.request.url.startsWith(origin + '/api/')) network.push({
      path: new URL(event.request.url).pathname, time: event.timestamp,
    });
  });
  return { context, page, state, errors, cdp, network };
}
const ready = page => page.waitForFunction(selector => typeof Alpine !== 'undefined' && Alpine.$data(document.querySelector(selector)).activeCasesLoaded, selector);
const noErrorUI = async page => {
  assert.equal(await page.locator(alert).count(), 0, 'Access alert must not exist before a real failure');
  assert.equal(await page.locator('[\\@click="detail.open = false"]').count(), 0, 'Legacy detail footer must be removed from the DOM');
  assert.equal(await page.getByRole('button', { name: 'Close', exact: true }).count(), 0, 'Obsolete Close footer must not exist');
};
const counts = requests => Object.fromEntries([...new Set(requests)].sort().map(key => [key, requests.filter(p => p === key).length]));

async function firstPaint(browser) {
  const f = await fixture(browser);
  try {
    f.state.alpineHold = deferred();
    await f.page.goto(url, { waitUntil: 'commit' });
    await f.page.getByRole('heading', { name: 'Clinic Cases', exact: true }).waitFor();
    await noErrorUI(f.page);
    assert.equal(await f.page.locator(`${selector} [role="dialog"][aria-labelledby="patient-profile-title"]`).count(), 1);
    f.state.alpineHold.resolve();
    await ready(f.page);
    await noErrorUI(f.page);
    assert.deepEqual(await f.page.evaluate(() => window.clinicPaint), { alerts: [], close: false });
    assert.deepEqual(f.errors, []);
    console.log('pre-Alpine first paint: no instantiated access alert or obsolete Close footer');
  } finally { f.state.alpineHold?.resolve(); await f.context.close(); }
}

async function startupAndRefresh(browser) {
  const f = await fixture(browser);
  try {
    await f.page.goto(url, { waitUntil: 'domcontentloaded' });
    await ready(f.page);
    console.log('cold startup Network:', JSON.stringify(counts(f.state.requests)));
    console.log('startup request chain (ms):', JSON.stringify(f.network.map(request => ({
      path: request.path, start: Math.round((request.time - f.network[0].time) * 1000),
    }))));
    assert.deepEqual(counts(f.state.requests), { '/api/admin/clinic-cases': 1, '/api/admin/grooming/activity': 1, '/api/me': 1, '/api/session': 1 });
    if (!process.env.CLINIC_BASELINE) await noErrorUI(f.page);
    // Real link navigation reuses verified identity and the Cases presentation cache.
    await Promise.all([
      f.page.waitForEvent('domcontentloaded'),
      f.page.getByRole('link', { name: 'Clinic', exact: true }).click(),
    ]);
    await ready(f.page);
    assert.equal(f.state.requests.filter(p => p === '/api/me').length, 1);
    assert.ok(f.state.requests.filter(p => p === '/api/admin/clinic-cases').length <= 2, 'Navigation may revalidate an expired Cases cache once');
    const before = f.state.requests.length;
    f.state.sessionHold = deferred();
    const loaded = f.page.waitForEvent('domcontentloaded');
    await f.cdp.send('Page.reload', { ignoreCache: true });
    await loaded;
    if (!process.env.CLINIC_BASELINE) await noErrorUI(f.page);
    assert.equal(await f.page.getByRole('heading', { name: 'Clinic Cases', exact: true }).isVisible(), true);
    assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), true);
    f.state.sessionHold.resolve();
    await ready(f.page);
    const refresh = f.state.requests.slice(before);
    console.log('cached Ctrl+F5 Network:', JSON.stringify(counts(refresh)));
    assert.equal(refresh.filter(p => p === '/api/session').length, 1);
    assert.equal(refresh.filter(p => p === '/api/me').length, 0);
    assert.ok(refresh.filter(p => p === '/api/admin/clinic-cases').length <= 1);
    assert.equal(refresh.some(p => /clinic-records|case-details|confinements|charges|vaccinations|medical-records/.test(p)), false);
    if (!process.env.CLINIC_BASELINE) {
      await noErrorUI(f.page);
      assert.deepEqual(await f.page.evaluate(() => window.clinicPaint), { alerts: [], close: false });
      assert.deepEqual(f.errors, [], 'No Alpine, DOM or missing-reference errors');
    }
    // Offscreen Patient Records must become fetchable only when the user reaches them.
    await f.page.locator('#patient-records-heading').scrollIntoViewIfNeeded();
    await f.page.waitForFunction(selector => Alpine.$data(document.querySelector(selector)).recordsLoaded, selector);
    assert.equal(f.state.requests.filter(p => p.startsWith('/api/admin/clinic-records')).length, 1);
    assert.equal(f.state.requests.some(p => /case-details|confinements|charges|vaccinations|medical-records/.test(p)), false);
    if (process.env.LOCAL_REVIEW_SCREENSHOTS && !process.env.CLINIC_BASELINE) {
      await f.page.evaluate(() => window.scrollTo(0, 0));
      await f.page.screenshot({ path: process.env.LOCAL_REVIEW_SCREENSHOTS + '/clinic-first-render.png' });
    }
    console.log('navigation, cache-bypassed reload, shared identity, lazy records and on-demand secondary data passed');
  } finally { f.state.sessionHold?.resolve(); await f.context.close(); }
}

async function failures(browser) {
  for (const kind of ['denied', 'identity', 'cases']) {
    const f = await fixture(browser);
    try {
      f.state.identityHold = deferred();
      if (kind === 'denied') f.state.access = false;
      if (kind === 'identity') f.state.identityStatus = 503;
      if (kind === 'cases') f.state.casesStatus = 503;
      await f.page.goto(url, { waitUntil: 'domcontentloaded' });
      await f.page.waitForFunction(() => typeof Alpine !== 'undefined' && !!Alpine.store('clinicAccess'));
      await noErrorUI(f.page);
      f.state.identityHold.resolve();
      if (kind === 'cases') {
        await f.page.waitForFunction(selector => !!Alpine.$data(document.querySelector(selector)).activeCasesError, selector);
        await noErrorUI(f.page);
        assert.equal(await f.page.getByRole('alert').filter({ hasText: 'Could not load Clinic cases.' }).isVisible(), true);
      } else {
        await f.page.locator(alert).waitFor({ state: 'visible' });
        assert.ok((await f.page.locator(alert).innerText()).trim());
        assert.equal(f.state.requests.some(p => p.includes('/admin/clinic-')), false, 'Denied access cannot load Clinic data');
      }
      assert.deepEqual(f.errors, []);
      console.log(kind + ': no pending alert; real failure uses its accessible error state');
    } finally { f.state.identityHold?.resolve(); await f.context.close(); }
  }
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    if (!process.env.CLINIC_BASELINE) await firstPaint(browser);
    await startupAndRefresh(browser);
    if (!process.env.CLINIC_BASELINE) await failures(browser);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
