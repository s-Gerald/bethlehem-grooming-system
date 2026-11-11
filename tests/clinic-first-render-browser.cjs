// Local-only browser regression; API fixtures never contact real accounts.
// Run with PLAYWRIGHT_PATH pointing to an installed Playwright package if needed.
// CLINIC_BASELINE=1 compares requests using the unchanged HEAD Clinic sources.
// CLINIC_MOBILE_ONLY=1 and CLINIC_MOBILE_ROLES=admin can narrow navigation checks.
// CLINIC_RENDER_ONLY=1 runs the original first-render/loading cases separately.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const origin = 'http://127.0.0.1:8000';
const url = origin + '/admin/clinic#cases';
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const selector = '[x-data="adminClinicCasesSection()"]';
const alert = `${selector} [role="alert"][x-text="accessError"]`;
let alpineSource;

async function fixture(browser, staffSubrole) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 800 } });
  const state = { requests: [], sessionHold: null, identityHold: null, alpineHold: null,
    identityStatus: 200, casesStatus: 200, access: true };
  await context.addInitScript(staffSubrole => {
    localStorage.setItem('admin_token', '17|local-clinic-fixture');
    localStorage.setItem('user_role', staffSubrole ? 'staff' : 'admin');
    // Destination workspaces have separate data fixtures; keep their real sidebar/header.
    if (!location.pathname.endsWith('/clinic')) document.addEventListener('alpine:init', () => {
      document.querySelectorAll('[x-data]:not([data-admin-shell])').forEach(el => el.setAttribute('x-ignore', ''));
    });
    // Record every insertion, including alerts inserted and removed between frames.
    window.clinicPaint = { alerts: [], close: false };
    new MutationObserver(() => {
      const errors = document.querySelectorAll('[role="alert"][x-text="accessError"]');
      for (const el of errors) window.clinicPaint.alerts.push(el.textContent);
      if (document.querySelector('[\\@click="detail.open = false"]')) window.clinicPaint.close = true;
    }).observe(document, { childList: true, subtree: true });
  }, staffSubrole);
  await context.route('**/alpinejs@*/**', async route => {
    alpineSource ||= route.fetch().then(response => response.body());
    const body = await alpineSource;
    await state.alpineHold?.promise;
    await route.fulfill({ contentType: 'text/javascript', body });
  });
  if (process.env.CLINIC_BASELINE) {
    for (const file of ['pages/admin/clinic.html', 'scripts/components/admin-clinic.js']) {
      const body = execFileSync('git', ['show', 'HEAD:' + file], { encoding: 'utf8' });
      await context.route(origin + (file.endsWith('.html') ? '/admin/clinic' : '/' + file) + '*', route => route.fulfill({
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
      locked: false, user_id: 7, role: staffSubrole ? 'staff' : 'admin', sidebar_revision: 'clinic-test-1',
      server_now: new Date().toISOString(), idle_at: new Date(Date.now() + 1800000).toISOString(),
      expires_at: new Date(Date.now() + 43200000).toISOString(),
    };
    if (endpoint.pathname === '/api/me') {
      status = state.identityStatus;
      data = status === 200 ? { sidebar_revision: 'clinic-test-1', user: { user_id: 7, role: staffSubrole ? 'staff' : 'admin',
        staff_type: staffSubrole ? 'clinic' : undefined, staff_subrole: staffSubrole,
        first_name: 'Local', last_name: 'Admin', clinic_permissions: { access: state.access, clinical: true, payment: true, billing_view: true } } }
        : { message: 'Local identity verification failed.' };
    }
    if (endpoint.pathname === '/api/admin/clinic-cases') {
      status = state.casesStatus;
      data = { today: '2026-10-19', activeClinicCount: 16, confined_count: 0, arrivals: [], cases: Array.from({ length: 16 }, (_, i) => ({
        id: i + 1, status: 'in_consultation', case_type: 'consultation', appointment_reference: `CL-TEST-${i + 1}`,
        appointment_date: '2026-10-19', pet: { id: i + 1, name: `Local patient ${i + 1}`, species: 'Dog' },
        owner: { first_name: 'Local', last_name: 'Owner' },
      })) };
      if (state.ownerSession) data = { ...data, cases: state.ownerSession.patients, sessions: [state.ownerSession] };
    }
    if (state.ownerSession && endpoint.pathname === '/api/admin/clinic-cases/1/charges') {
      data = { session: {
        id: state.ownerSession.id, eligible_count: 13, ready_count: 12, can_pay: false, total_amount: '6500.00',
        patients: state.ownerSession.patients.map(patient => ({
          id: patient.id, pet_name: patient.pet.name, reference: patient.appointment_reference, status: patient.status,
          subtotal: '500.00', charges: [{ id: patient.id, description: 'Consultation', amount: '500.00' }],
        })),
      } };
    }
    if (state.ownerSession && endpoint.pathname === '/api/admin/clinic-cases/1/bulk-services/9') {
      data = { patients: state.ownerSession.patients.slice(0, 2).map((patient, index) => ({
        id: patient.id, name: patient.pet.name, reference: patient.appointment_reference,
        pricing: { resolved_price: index === 0 ? '350.00' : '600.00', pet_weight_kg: index === 0 ? 4.5 : 35 },
      })) };
    }
    if (state.ownerSession && endpoint.pathname === '/api/admin/clinic-cases/1/bulk-services') {
      state.bulkPayload = route.request().postDataJSON();
      data = { data: [] };
    }
    if (state.ownerSession && endpoint.pathname === '/api/admin/clinic-cases/1/bulk-treatments/301') {
      data = { administered_date: '2026-11-11', available_quantity: 2, unit: 'bottle',
        patients: state.ownerSession.patients.slice(0, 2).map((patient, index) => ({
          id: patient.id, name: patient.pet.name, reference: patient.appointment_reference,
          weight: index === 0 ? 4.5 : 35, priced_weight: index === 0 ? 4.5 : 35, price: index === 0 ? '350.00' : '600.00',
        })),
      };
    }
    if (state.ownerSession && endpoint.pathname === '/api/admin/clinic-cases/1/bulk-treatments') {
      state.treatmentPayload = route.request().postDataJSON();
      data = { data: [] };
    }
    if (endpoint.pathname === '/api/admin/clinic/activity') data = { activeClinicCount: 16 };
    if (endpoint.pathname === '/api/admin/clinic-records') data = { records: [], current_page: 1, last_page: 1 };
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  // Match the sidebar suite's document-load allowance; keep UI assertions at 15s.
  page.setDefaultNavigationTimeout(30000);
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
    await f.page.setViewportSize({ width: 375, height: 844 });
    f.state.alpineHold = deferred();
    await f.page.goto(url, { waitUntil: 'commit' });
    await f.page.locator('main').getByRole('heading', { name: 'Clinic Cases', exact: true }).waitFor();
    assert.equal(await f.page.locator('header').first().evaluate(el => el.getBoundingClientRect().height), 81, 'Reserve role-label space before Alpine/identity hydration');
    await noErrorUI(f.page);
    assert.equal(await f.page.locator(`${selector} [role="dialog"][aria-labelledby="patient-profile-title"]`).count(), 1);
    f.state.alpineHold.resolve();
    await ready(f.page);
    assert.equal(await f.page.locator('header').first().evaluate(el => el.getBoundingClientRect().height), 81, 'Hydration must not move the header');
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
    if (!process.env.CLINIC_BASELINE) {
      await f.page.locator('aside [data-clinic-activity]').waitFor({ state: 'visible' });
      assert.equal(await f.page.locator('aside a[href="/admin/clinic"]').getAttribute('aria-description'), 'Clinic has active or incoming patients');
    }
    console.log('cold startup Network:', JSON.stringify(counts(f.state.requests)));
    console.log('startup request chain (ms):', JSON.stringify(f.network.map(request => ({
      path: request.path, start: Math.round((request.time - f.network[0].time) * 1000),
    }))));
    assert.deepEqual(counts(f.state.requests), { '/api/admin/clinic-cases': 1, '/api/admin/grooming/activity': 1, '/api/admin/clinic/activity': 1, '/api/admin/notifications?mode=count': 1, '/api/me': 1, '/api/session': 1 });
    if (!process.env.CLINIC_BASELINE) await noErrorUI(f.page);
    // Real link navigation reuses verified identity and the Cases presentation cache.
    await Promise.all([
      f.page.waitForEvent('domcontentloaded', { timeout: 30000 }),
      f.page.getByRole('link', { name: 'Clinic', exact: true }).click(),
    ]);
    await ready(f.page);
    assert.equal(f.state.requests.filter(p => p === '/api/me').length, 1);
    assert.ok(f.state.requests.filter(p => p === '/api/admin/clinic-cases').length <= 2, 'Navigation may revalidate an expired Cases cache once');
    const before = f.state.requests.length;
    f.state.sessionHold = deferred();
    const loaded = f.page.waitForEvent('domcontentloaded', { timeout: 30000 });
    await f.cdp.send('Page.reload', { ignoreCache: true });
    await loaded;
    if (!process.env.CLINIC_BASELINE) await noErrorUI(f.page);
    assert.equal(await f.page.locator('main').getByRole('heading', { name: 'Clinic Cases', exact: true }).isVisible(), true);
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
    if (!process.env.CLINIC_BASELINE) {
      await f.page.setViewportSize({ width: 375, height: 844 });
      await f.page.locator('header').first().getByRole('button', { name: 'Open navigation', exact: true }).click();
      await f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
      const dot = f.page.locator('aside [data-clinic-activity]');
      await dot.waitFor({ state: 'visible' });
      const { width, height } = await dot.boundingBox();
      assert.deepEqual([width, height], [10, 10]);
      assert.equal(await f.page.locator('aside a[href="/admin/clinic"]').getAttribute('aria-description'), 'Clinic has active or incoming patients');
    }
    console.log('navigation, cache-bypassed reload, shared identity, lazy records and on-demand secondary data passed');
  } finally { f.state.sessionHold?.resolve(); await f.context.close(); }
}

async function failures(browser) {
  for (const kind of ['denied', 'identity', 'cases']) {
    const f = await fixture(browser, kind === 'denied' ? 'veterinarian' : undefined);
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
        assert.equal(f.state.requests.some(p => p.includes('/admin/clinic-') || p === '/api/admin/clinic/activity'), false, 'Denied access cannot load Clinic data');
      }
      assert.deepEqual(f.errors, []);
      console.log(kind + ': no pending alert; real failure uses its accessible error state');
    } finally { f.state.identityHold?.resolve(); await f.context.close(); }
  }
}

async function mobileNavigation(browser, staffSubrole) {
  const f = await fixture(browser, staffSubrole);
  const header = f.page.locator('header').first();
  const menu = header.getByRole('button', { name: 'Open navigation', exact: true });
  const backdrop = f.page.locator('[data-admin-shell] > div[x-show="sidebarOpen"]');
  const opened = () => f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
  const closed = () => f.page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().right <= 1);
  const presentation = () => header.evaluate(el => {
    const s = getComputedStyle(el), b = getComputedStyle(el.querySelector('button'));
    return { height: el.getBoundingClientRect().height, padding: s.padding, background: s.backgroundColor,
      border: s.borderBottom, titleSize: getComputedStyle(el.querySelector('h2')).fontSize,
      button: [b.width, b.height, b.borderRadius, b.border, b.color, b.backgroundColor] };
  });
  try {
    await f.page.setViewportSize({ width: 375, height: 844 });
    await f.page.goto(url, { waitUntil: 'domcontentloaded' }); await ready(f.page);
    // Catches the former unhandled custom event, through the real nested Clinic scope.
    await menu.click(); await opened();
    assert.equal(await backdrop.isVisible(), true);
    assert.equal(await f.page.locator('aside nav [aria-current="page"]').innerText(), 'Clinic');
    assert.equal(await f.page.locator('aside a[href$="reports"]').isVisible(), !staffSubrole);
    assert.equal(await f.page.locator('aside a[href$="grooming"]').isVisible(), !staffSubrole);
    const pointer = await menu.evaluate(el => {
      const s = getComputedStyle(el);
      return { visible: el.matches(':focus-visible'), outlineVisible: s.outlineStyle !== 'none' && s.outlineWidth !== '0px' && s.outlineColor !== 'rgba(0, 0, 0, 0)', shadow: s.boxShadow, border: s.border };
    });
    assert.equal(pointer.visible, false);
    assert.equal(pointer.outlineVisible, false, 'Pointer activation must not add a visible outline');
    await backdrop.click({ position: { x: 350, y: 120 } }); await closed();
    // Reach the menu through actual keyboard navigation, rather than programmatic focus.
    await menu.press('Tab');
    await f.page.keyboard.press('Shift+Tab');
    const keyboard = await menu.evaluate(el => ({ focused: document.activeElement === el,
      visible: el.matches(':focus-visible'), style: getComputedStyle(el).outlineStyle,
      width: getComputedStyle(el).outlineWidth, color: getComputedStyle(el).outlineColor,
      offset: getComputedStyle(el).outlineOffset }));
    assert.deepEqual(keyboard, { focused: true, visible: true, style: 'solid', width: '2px', color: 'rgb(49, 91, 126)', offset: '2px' });
    await f.page.keyboard.press('Enter'); await opened();
    await backdrop.click({ position: { x: 350, y: 120 } }); await closed();
    assert.equal((await header.innerText()).replace(/\s+/g, ' ').trim(), `${staffSubrole ? 'Staff' : 'Admin'} Panel Clinic Cases`);
    const clinicPresentation = await presentation();
    assert.equal(clinicPresentation.height, 81);
    assert.equal(pointer.shadow, await menu.evaluate(el => getComputedStyle(el).boxShadow));
    assert.equal(pointer.border, await menu.evaluate(el => getComputedStyle(el).border));
    // Same rendered dimensions, palette, typography and alignment as the existing reference pages.
    for (const [file, active] of [['dashboard', 'Dashboard'], ['customers', 'Customers'], ...(!staffSubrole ? [['grooming', 'Grooming']] : [])]) {
      await menu.click(); await opened();
      await f.page.locator('aside').getByRole('link', { name: active, exact: true }).click();
      await f.page.waitForURL(`**/${file}`);
      await f.page.waitForFunction(() => document.querySelector('aside[data-admin-navigation-ready]') && !document.documentElement.hasAttribute('data-session-gate'));
      await closed();
      assert.equal(await f.page.locator('aside nav [aria-current="page"]').innerText(), active);
      assert.deepEqual(await presentation(), clinicPresentation);
      await header.locator('button').click(); await opened();
      await f.page.locator('aside').getByRole('link', { name: 'Clinic', exact: true }).click();
      await ready(f.page); await closed();
    }
    for (const width of [320, 375, 414, 768, 960, 1024, 1440]) {
      await f.page.setViewportSize({ width, height: 844 });
      await f.page.evaluate(() => scrollTo(0, 400));
      assert.equal(await header.isVisible(), width < 1024);
      const geometry = await f.page.evaluate(() => {
        const header = document.querySelector('header'), banner = document.querySelector('main header');
        return { gap: banner.getBoundingClientRect().top - (getComputedStyle(header).display === 'none' ? 0 : header.getBoundingClientRect().bottom),
          overflow: document.documentElement.scrollWidth > innerWidth };
      });
      assert.ok(Math.abs(geometry.gap) <= 1, `${width}px: sticky header/banner must meet without overlap or gap`);
      assert.equal(geometry.overflow, false, `${width}px: no horizontal overflow`);
      if (width >= 1024) continue;
      await menu.click(); await opened();
      // The backdrop must win hit testing over the header and scrolled Clinic content.
      assert.equal(await backdrop.evaluate(el => document.elementFromPoint(innerWidth - 10, 30) === el), true);
      await backdrop.click({ position: { x: width - 10, y: 120 } }); await closed();
    }
    // Emulate browser zoom's CSS viewport and device scale at a 1440x1000 window.
    for (const zoom of [0.8, 1.25, 1.5, 2]) {
      const width = Math.round(1440 / zoom);
      await f.cdp.send('Emulation.setDeviceMetricsOverride', { width, height: Math.round(1000 / zoom), deviceScaleFactor: zoom, mobile: false });
      await f.page.evaluate(() => scrollTo(0, 400));
      assert.equal(await header.isVisible(), width < 1024);
      assert.ok(await f.page.evaluate(() => {
        const h = document.querySelector('header'), b = document.querySelector('main header');
        return Math.abs(b.getBoundingClientRect().top - (getComputedStyle(h).display === 'none' ? 0 : h.getBoundingClientRect().bottom)) <= 1 && document.documentElement.scrollWidth <= innerWidth;
      }), `${zoom * 100}% emulated zoom: aligned sticky header and no horizontal overflow`);
    }
    await f.cdp.send('Emulation.clearDeviceMetricsOverride');
    await f.page.setViewportSize({ width: 375, height: 844 });
    await f.page.evaluate(() => { scrollTo(0, 0); document.documentElement.style.fontSize = '20px'; });
    await f.page.evaluate(() => scrollTo(0, 400));
    assert.ok(await f.page.evaluate(() => Math.abs(document.querySelector('main header').getBoundingClientRect().top - document.querySelector('header').getBoundingClientRect().bottom) <= 1), 'Sticky offset follows text scaling');
    await f.page.evaluate(() => { document.documentElement.style.fontSize = ''; scrollTo(0, 0); });
    f.state.sessionHold = deferred();
    const reloaded = f.page.waitForEvent('domcontentloaded', { timeout: 30000 });
    await f.cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await f.cdp.send('Page.reload', { ignoreCache: true }); await reloaded;
    assert.equal(await header.getByRole('heading', { name: 'Clinic Cases', exact: true }).isVisible(), true);
    assert.equal((await presentation()).height, 81, 'Pending-session hard refresh preserves header geometry');
    assert.equal(await f.page.locator('aside nav').evaluate(el => el.inert), true);
    f.state.sessionHold.resolve(); await ready(f.page);
    assert.equal(f.state.requests.filter(p => p === '/api/me').length, 1, 'Navigation and hard refresh reuse identity');
    await menu.click(); await opened();
    assert.equal(await f.page.locator('aside nav [aria-current="page"]').innerText(), 'Clinic');
    await backdrop.click({ position: { x: 350, y: 120 } }); await closed();
    if (process.env.LOCAL_REVIEW_SCREENSHOTS && !staffSubrole) await f.page.screenshot({ path: `${process.env.LOCAL_REVIEW_SCREENSHOTS}/clinic-mobile-navigation.png` });
    assert.deepEqual(f.errors, [], 'Mobile navigation produces no JavaScript errors');
    console.log(`${staffSubrole || 'admin'}: Clinic menu, backdrop, navigation, active state, focus, responsive geometry and cached hard refresh passed`);
  } finally { f.state.sessionHold?.resolve(); await f.context.close(); }
}

async function ownerSessionReview(browser) {
  const f = await fixture(browser);
  const patients = Array.from({ length: 13 }, (_, index) => ({
    id: index + 1, clinic_visit_group_id: 1, appointment_reference: `CL-GROUP-${index + 1}`,
    queue_number: 21 + index, status: index === 0 ? 'in_consultation' : 'for_payment',
    ownerName: 'Jamie Santos', pet: { id: index + 1, name: `Group patient ${index + 1}` },
  }));
  f.state.ownerSession = { id: 1, owner_name: 'Jamie Santos', state: 'active-cases', ready_count: 12, eligible_count: 13, patients };
  try {
    await f.page.setViewportSize({ width: 414, height: 844 });
    await f.page.goto(url, { waitUntil: 'domcontentloaded' });
    await ready(f.page);
    const groupHeading = f.page.getByRole('heading', { name: 'Jamie Santos · 13 pets' });
    await groupHeading.waitFor();
    assert.equal(await groupHeading.count(), 1);
    assert.equal(await f.page.getByRole('button', { name: /#\d+ · Group patient/ }).count(), 13);
    assert.equal(await f.page.getByRole('button', { name: 'Review Payment' }).isDisabled(), true);
    await f.page.getByRole('button', { name: 'View Billing' }).click();
    await f.page.getByRole('heading', { name: 'Owner Session Billing' }).waitFor();
    await f.page.getByRole('heading', { name: 'Group patient 13 · CL-GROUP-13' }).waitFor();
    assert.equal(await f.page.getByRole('button', { name: 'Confirm Payment' }).isVisible(), false);
    await f.page.getByRole('button', { name: 'Close', exact: true }).filter({ visible: true }).click();

    await f.page.evaluate(() => window.dispatchEvent(new CustomEvent('clinic-bulk-open', {
      detail: { kind: 'services', caseId: 1, item: { id: 9, name: 'Consultation', billing_unit: 'visit' } },
    })));
    const dialog = f.page.getByRole('dialog', { name: 'Apply to multiple pets' });
    await dialog.getByRole('button', { name: 'Select all eligible' }).click();
    await dialog.getByRole('button', { name: 'Apply to 2 pets' }).click();
    await dialog.waitFor({ state: 'hidden' });
    assert.deepEqual(f.state.bulkPayload.patients.map(({ id, expected_price }) => [id, expected_price]), [[1, '350.00'], [2, '600.00']]);

    await f.page.evaluate(() => window.dispatchEvent(new CustomEvent('clinic-bulk-open', {
      detail: { kind: 'treatments', caseId: 1, item: { item_id: 301, item_name: 'Clinic medicine', category: 'medicine' } },
    })));
    await dialog.getByRole('button', { name: 'Select all eligible' }).click();
    const apply = dialog.getByRole('button', { name: 'Apply to 2 pets' });
    assert.equal(await apply.isDisabled(), true, 'Each patient needs an independent dose review');
    for (let index = 0; index < 2; index++) {
      await dialog.getByLabel('Quantity used', { exact: true }).nth(index).fill('1');
      await dialog.getByLabel('Dose', { exact: true }).nth(index).fill(index === 0 ? '0.5' : '1.5');
      await dialog.getByLabel(/^Dose unit/).nth(index).selectOption('mL');
      await dialog.getByLabel(/^Route/).nth(index).selectOption('oral');
    }
    await dialog.getByLabel('Quantity used', { exact: true }).nth(1).fill('2');
    assert.equal(await apply.isDisabled(), true, 'Combined quantity cannot exceed stock');
    await dialog.getByLabel('Quantity used', { exact: true }).nth(1).fill('1');
    await apply.click();
    await dialog.waitFor({ state: 'hidden' });
    assert.deepEqual(f.state.treatmentPayload.patients.map(({ id, dose_amount, expected_price, expected_weight_kg }) =>
      [id, dose_amount, expected_price, expected_weight_kg]), [[1, '0.5', '350.00', 4.5], [2, '1.5', '600.00', 35]]);
    await f.page.evaluate(async () => {
      const {mountClinicPatientReasons} = await import('/scripts/components/clinic-visit-reason-fields.js');
      const root = document.createElement('div');
      root.id = 'clinicReasonReview';
      document.body.append(root);
      const patients = Array.from({length: 13}, (_, index) => ({pet: {id: index + 1, petName: `Patient ${index + 1}`}}));
      window.reasonReview = mountClinicPatientReasons(root, patients, rows => { window.savedReasons = structuredClone(rows); });
      window.restoreReasons = () => { window.reasonReview = mountClinicPatientReasons(root, window.savedReasons, rows => { window.savedReasons = structuredClone(rows); }); };
    });
    const reasons = f.page.locator('#clinicReasonReview');
    assert.equal(await f.page.evaluate(() => window.reasonReview.validate()), false);
    for (let index = 0; index < 13; index++) {
      const patient = reasons.locator(`[data-reason-patient="${index}"]`);
      if (await patient.getAttribute('aria-expanded') !== 'true') await patient.click();
      assert.equal(await reasons.locator('[data-clinic-concern][aria-pressed="true"]').count(), 0, 'Reasons are never copied between pets');
      await reasons.getByRole('button', {name: index === 0 ? 'Routine check-up' : 'Vaccination', exact: true}).click();
      await reasons.getByLabel('Tell us more').fill(`Independent reason ${index + 1}`);
    }
    assert.equal(await f.page.evaluate(() => window.reasonReview.validate()), true);
    assert.deepEqual(await f.page.evaluate(() => window.savedReasons.map(row => row.chiefComplaint)),
      Array.from({length: 13}, (_, index) => `Independent reason ${index + 1}`));
    await f.page.evaluate(() => window.restoreReasons());
    await reasons.locator('[data-reason-patient="0"]').click();
    assert.equal(await reasons.getByLabel('Tell us more').inputValue(), 'Independent reason 1');
    await reasons.evaluate(element => element.remove());
    assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(f.errors, []);
    console.log('Clinic owner-session mobile case list, billing readiness, and bulk review passed');
  } catch (error) {
    console.error('Owner session fixture state', await f.page.evaluate(selector => {
      const cases = Alpine.$data(document.querySelector(selector));
      return { tab: cases.tab, activeSessions: cases.activeSessions?.length, headings: [...document.querySelectorAll('main h4')].map(h => h.textContent).slice(0, 8) };
    }, selector), f.errors);
    throw error;
  } finally { await f.context.close(); }
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    if (process.env.CLINIC_OWNER_SESSION_ONLY) return await ownerSessionReview(browser);
    if (!process.env.CLINIC_BASELINE && !process.env.CLINIC_RENDER_ONLY) for (const role of process.env.CLINIC_MOBILE_ROLES?.split(',') || ['admin', 'veterinarian', 'clinic_receptionist']) await mobileNavigation(browser, role === 'admin' ? undefined : role);
    if (process.env.CLINIC_MOBILE_ONLY) return;
    if (!process.env.CLINIC_BASELINE) await firstPaint(browser);
    await startupAndRefresh(browser);
    if (!process.env.CLINIC_BASELINE) await failures(browser);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
