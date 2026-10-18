// Optional local browser check. PLAYWRIGHT_PATH points to an installed Playwright
// package; LOCAL_REVIEW_SESSIONS contains temporary local admin/staff sessions.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const sessions = JSON.parse(fs.readFileSync(process.env.LOCAL_REVIEW_SESSIONS, 'utf8').replace(/^\uFEFF/, ''));
const origin = 'http://127.0.0.1:8000';
const delay = ms => new Promise(r => setTimeout(r, ms));
const screenshotDir = process.env.LOCAL_REVIEW_SCREENSHOTS;

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const role of ['admin', 'staff']) {
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      await context.addInitScript(({ token, role }) => {
        if (!sessionStorage.getItem('local-review-auth-ready')) {
          localStorage.setItem('admin_token', token); localStorage.setItem('user_role', role);
          sessionStorage.setItem('local-review-auth-ready', '1');
        }
      }, { token: sessions[role].token, role });
      const page = await context.newPage(), errors = [], calls = [];
      page.setDefaultTimeout(15000);
      page.on('pageerror', error => { errors.push(error.message); console.log('Browser error: ' + error.message); });
      page.on('request', req => { if (req.url().startsWith(origin + '/api/')) calls.push(new URL(req.url()).pathname + new URL(req.url()).search); });
      page.on('response', res => { if (res.url().startsWith(origin + '/api/') && res.status() >= 400) errors.push('HTTP ' + res.status() + ' ' + new URL(res.url()).pathname); });
      await page.route('**/api/session', async route => { await delay(900); await route.continue(); });
      await page.route('**/api/admin/dashboard/summary', async route => { await delay(1200); await route.continue(); });
      await page.goto(origin + '/pages/admin/dashboard.html', { waitUntil: 'domcontentloaded' });
      console.log(role + ' DOM ready');
      await page.waitForFunction(() => document.querySelector('[data-admin-shell]')?._x_dataStack);
      const checking = await page.evaluate(() => ({
        gate: document.documentElement.getAttribute('data-session-gate'),
        sidebar: getComputedStyle(document.querySelector('aside')).visibility,
        identity: [...document.querySelectorAll('[x-text="staffDisplayName"]')].map(el => getComputedStyle(el).visibility),
      }));
      assert.equal(checking.gate, 'checking'); assert.equal(checking.sidebar, 'visible');
      assert.ok(checking.identity.every(v => v === 'hidden'));
      await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
      console.log(role + ' session verified');
      const numbers = page.locator('main h3').filter({ has: page.locator('[aria-label="Loading statistic"]') });
      assert.equal(await numbers.count(), 4);
      for (const number of await numbers.all()) assert.equal((await number.innerText()).trim(), '', 'No initial zero statistic');
      const loadingGeometry = await numbers.evaluateAll(nodes => nodes.map(node => ({ top: node.getBoundingClientRect().top, height: node.getBoundingClientRect().height })));
      await page.emulateMedia({ reducedMotion: 'reduce' });
      assert.equal(await page.locator('[aria-label="Loading statistic"]').first().evaluate(el => getComputedStyle(el).animationName), 'none');
      await page.emulateMedia({ reducedMotion: 'no-preference' });
      if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, role + '-dashboard-loading.png'), fullPage: true });
      try {
        await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).summaryLoaded);
      } catch (error) {
        console.log(JSON.stringify({ calls, errors, state: await page.evaluate(() => {
          const ui = Alpine.$data(document.querySelector('main'));
          return { summaryError: ui.summaryError, pageMode: ui.pageMode, initialized: ui._initialized, gate: document.documentElement.getAttribute('data-session-gate') };
        }) }));
        throw error;
      }
      await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).activityLoaded);
      const loadedGeometry = await numbers.evaluateAll(nodes => nodes.map(node => ({ top: node.getBoundingClientRect().top, height: node.getBoundingClientRect().height })));
      assert.deepEqual(loadedGeometry, loadingGeometry, 'KPI number slots retain their geometry');
      assert.equal(calls.filter(url => url.startsWith('/api/admin/bookings')).length, 0, 'Dashboard never loads Grooming workflows');
      if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, role + '-dashboard-loaded.png'), fullPage: true });
      console.log(role + ' Dashboard requests: ' + JSON.stringify(calls));
      const loadedNumbers = await numbers.allTextContents();
      await page.route('**/api/admin/dashboard/summary', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Local retry test"}' }));
      await page.evaluate(() => Alpine.$data(document.querySelector('main')).loadDashboardSummary({ force: true }));
      assert.deepEqual(await numbers.allTextContents(), loadedNumbers, 'A failed refresh retains real statistics');
      assert.equal(await page.locator('[aria-label="Loading statistic"]').first().isVisible(), false);
      await page.unroute('**/api/admin/dashboard/summary');
      await page.getByRole('button', { name: 'Retry', exact: true }).click();
      await page.waitForFunction(() => !Alpine.$data(document.querySelector('main')).summaryError);
      const expectedError = errors.indexOf('HTTP 500 /api/admin/dashboard/summary');
      assert.ok(expectedError >= 0); errors.splice(expectedError, 1);
      calls.length = 0;
      await page.route(/\/api\/admin\/bookings\?/, async route => { await delay(900); await route.continue(); });
      await page.goto(origin + '/pages/admin/appointments.html', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => window.Alpine && !document.documentElement.hasAttribute('data-session-gate'));
      await page.getByRole('status', { name: 'Loading grooming visits', exact: true }).waitFor();
      assert.equal((await page.locator('main h3:has([aria-label="Loading statistic"])').allInnerTexts()).join('').trim(), '', 'Grooming metrics never start at zero');
      await page.waitForFunction(() => window.Alpine && Alpine.$data(document.querySelector('main')).bookingsLoaded, { timeout: 20000 });
      assert.equal(calls.filter(url => url.startsWith('/api/admin/bookings?')).length, 1);
      assert.equal(calls.filter(url => url.startsWith('/api/admin/grooming/activity')).length, 0);
      assert.equal(await page.evaluate(() => typeof window.Html5Qrcode), 'undefined', 'Scanner stays deferred');
      const before = calls.length;
      for (const tab of ['queued', 'in-progress', 'for-payment', 'to-be-picked-up', 'incoming']) {
        await page.evaluate(tab => Alpine.$data(document.querySelector('main')).setTab(tab), tab);
      }
      assert.equal(calls.length, before, 'Already-loaded tab switching performs no requests');
      await page.setViewportSize({ width: 390, height: 844 });
      await page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().right <= 1);
      await page.locator('header button').first().click();
      await page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
      if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, role + '-grooming-mobile.png') });
      console.log(role + ' Grooming requests: ' + JSON.stringify(calls));
      await page.setViewportSize({ width: 1440, height: 1000 });
      calls.length = 0;
      await page.route('**/api/admin/security/accounts', async route => { await delay(900); await route.continue(); });
      await page.goto(origin + '/pages/admin/settings.html', { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => window.Alpine && Alpine.$data(document.querySelector('main')).availabilityLoading === false);
      assert.equal(calls.filter(url => /security\/(accounts|account)$/.test(url)).length, 0);
      await page.getByRole('button', { name: 'Security', exact: true }).click();
      if (role === 'admin') {
        await page.getByRole('status', { name: 'Loading staff accounts', exact: true }).waitFor();
        assert.equal(await page.locator('[x-text="staffAccounts.length"]').isVisible(), false, 'Staff totals remain concealed while loading');
        if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, role + '-security-loading.png'), fullPage: true });
        await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).staffLoaded);
        await page.getByRole('button', { name: 'General', exact: true }).click();
        await page.getByRole('button', { name: 'Security', exact: true }).click();
        assert.equal(calls.filter(url => url.endsWith('/admin/security/accounts')).length, 1);
      } else await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).staffAccount.fullName);
      console.log(role + ' Settings requests: ' + JSON.stringify(calls));
      if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, role + '-security-loaded.png'), fullPage: true });
      assert.deepEqual(errors, [], 'Browser/API errors');
      if (process.env.LOCAL_REVIEW_LOCK_HELPER) {
        execFileSync('php', [process.env.LOCAL_REVIEW_LOCK_HELPER, 'lock', role]);
        await page.goto(origin + '/pages/admin/dashboard.html', { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: 'Session locked', exact: true }).waitFor();
        assert.equal(await page.locator('aside').evaluate(el => getComputedStyle(el).visibility), 'hidden', 'Actual session lock conceals even the static shell');
        await page.getByLabel('Password', { exact: true }).fill('DeliberatelyIncorrectLocalReviewPassword!');
        await page.getByRole('button', { name: 'Unlock session', exact: true }).click();
        await page.getByText('Incorrect password.', { exact: true }).waitFor();
        assert.equal(await page.locator('html').getAttribute('data-session-gate'), 'locked');
        await page.getByRole('button', { name: 'Sign in with another account', exact: true }).click();
        await page.waitForURL('**/sign-in.html');
        assert.equal(await page.evaluate(() => localStorage.getItem('admin_token')), null);
        console.log(role + ' real lock, wrong-password concealment and logout passed');
      }
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
