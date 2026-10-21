// Optional browser regression. Requires an installed Playwright package and
// temporary sessions for existing LOCAL accounts; never run against production.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const sessions = JSON.parse(fs.readFileSync(process.env.LOCAL_REVIEW_SESSIONS, 'utf8').replace(/^\uFEFF/, ''));
const origin = process.env.LOCAL_REVIEW_ORIGIN || 'http://127.0.0.1:8000';
if (!['127.0.0.1', 'localhost'].includes(new URL(origin).hostname)) throw new Error('Browser review requires a local server.');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const pages = [
  ['dashboard', 'dashboard.html', 'summaryLoaded'], ['customers', 'clients.html', 'customersLoaded'],
  ['grooming', 'appointments.html', 'bookingsLoaded'], ['clinic', 'clinic.html', 'activeCasesLoaded'],
  ['inventory', 'inventory/inventory.html', '_inventory'], ['services', 'services.html', '_services'],
  ['history', 'archive.html', 'historyLoaded'], ['transactions', 'transactions.html', 'transactionsLoaded'],
  ['reports', 'reports.html', 'report'], ['settings', 'settings.html', 'staffLoaded'],
  ['notifications', 'notifications.html', 'loaded'],
].filter(([name]) => !process.env.LOCAL_REVIEW_PAGES || process.env.LOCAL_REVIEW_PAGES.split(',').includes(name));
async function ready(page, name, property) {
  await page.waitForFunction(({ name, property }) => {
    const el = name === 'clinic' ? document.querySelector('[x-data="adminClinicCasesSection()"]') : document.querySelector('main');
    const ui = Alpine.$data(el);
    if (name === 'inventory') return !ui.sectionLoading && ui.sectionData(ui.activeSection)?.loaded;
    if (name === 'services') {
      const panel = ui.$refs[ui.activeTab].firstElementChild;
      return !ui.loading && panel && Alpine.$data(panel).loaded;
    }
    return !!ui[property];
  }, { name, property });
}
(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  try {
    for (const key of Object.keys(sessions)) {
      const session = sessions[key];
      const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
      await context.addInitScript(({ token, role, origin }) => {
        window.BETHLEHEM_API_BASE_URL = origin;
        if (sessionStorage.getItem('local-ui-test')) return;
        localStorage.setItem('admin_token', token); localStorage.setItem('user_role', role);
        sessionStorage.setItem('local-ui-test', '1');
      }, { ...session, origin });
      const page = await context.newPage(); page.setDefaultTimeout(20000);
      const errors = [], requests = [];
      page.on('pageerror', error => { errors.push(error.message); console.log('Browser error: ' + error.message); });
      page.on('request', request => { if (request.url().startsWith(origin + '/api/')) requests.push(new URL(request.url()).pathname + new URL(request.url()).search); });
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url());
        if (!['/api/me', '/api/session', '/api/session/activity', '/api/logout', '/api/session/unlock'].includes(url.pathname)) await pause(900);
        await route.continue();
      });
      for (const [name, file, property] of pages) {
        if (session.role === 'staff' && name === 'reports') continue;
        if (key === 'staff' && ['clinic', 'services'].includes(name)) continue;
        if (key === 'clinic' && ['dashboard', 'grooming'].includes(name)) continue;
        requests.length = 0;
        await page.goto(origin + '/pages/admin/' + file, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.querySelector('[data-admin-shell]')?.hasAttribute('data-admin-ready'));
        assert.equal(await page.locator('aside nav').isVisible(), true, name + ' navigation visible before data');
        if (session.role === 'staff') assert.equal(await page.locator('aside nav a[href$="reports.html"]').isVisible(), false);
        if (key === 'clinic') assert.equal(await page.locator('aside nav a[href$="appointments.html"]').isVisible(), false);
        if (key === 'staff') assert.equal(await page.locator('aside nav a[href$="clinic.html"]').isVisible(), false);
        assert.equal(await page.locator('.admin-shell-nav-placeholder, [class*="admin-skeleton"], .reports-skeleton').count(), 0);
        const headings = await page.locator('main [data-session-public]').allInnerTexts();
        if (name === 'dashboard') assert.ok(headings.join(' ').includes(session.role === 'admin' ? 'Admin Dashboard' : 'Staff Dashboard'));
        if (['dashboard', 'grooming'].includes(name)) assert.equal(await page.locator('main h3:visible').count(), 0, 'Initial KPI values stay concealed until data is ready');
        if (name === 'settings') {
          await page.getByRole('button', { name: 'Security', exact: true }).click();
        }
        const visibleLoader = page.locator('admin-loader:visible').first();
        await visibleLoader.waitFor();
        assert.equal((await visibleLoader.innerText()).trim(), 'Loading...');
        assert.ok((await visibleLoader.locator('use').getAttribute('href')).endsWith('/assets/icons/phosphor.svg#spinner-gap'));
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await visibleLoader.locator('svg').evaluate(el => getComputedStyle(el).animationName), 'none');
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        if (process.env.LOCAL_REVIEW_SCREENSHOTS && key === 'admin') await page.screenshot({ path: path.join(process.env.LOCAL_REVIEW_SCREENSHOTS, 'universal-' + name + '-loading.png'), fullPage: true });
        await ready(page, name, property);
        if (name === 'inventory') {
          assert.equal(requests.filter(url=>url.startsWith('/api/inventory/')).length,1,'Overview has one data request');
          assert.ok(requests.some(url=>url.includes('/inventory/summary?page=1&view=overview')));
          assert.equal(await page.locator('script[src*="html5-qrcode"]').count(),0,'Scanner stays on demand');
        }
        if (name === 'services') {
          assert.equal(requests.some(url=>url.includes(session.role === 'admin' ? '/clinic-services' : '/grooming/services')),false,'Inactive service catalogue is not fetched');
        }
        if (name === 'history') {
          assert.equal(requests.filter(url=>url.includes('/archived')).length,1,'Only the active history type loads');
          assert.equal(requests.some(url=>/signed-consent|case-details|attachments/.test(url)),false,'History details stay on demand');
        }
        await page.waitForFunction(() => [...document.querySelectorAll('admin-loader')].filter(el => el.getBoundingClientRect().height && getComputedStyle(el).visibility === 'visible').length === 0);
        if (name === 'dashboard') {
          assert.equal(requests.filter(url => url.startsWith('/api/admin/bookings')).length, 0);
          const count = requests.filter(url => url === '/api/admin/dashboard/summary').length;
          await page.reload({ waitUntil: 'domcontentloaded' }); await ready(page, name, property);
          assert.equal(await page.locator('admin-loader[page]').isVisible(), false, 'Cached metrics render without another loader');
          assert.equal(requests.filter(url => url === '/api/admin/dashboard/summary').length, count, 'Navigation reuses cached metrics');
          const old = await page.locator('main h3').allInnerTexts();
          await page.route('**/api/admin/dashboard/summary', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Local retry test"}' }));
          await page.evaluate(() => Alpine.$data(document.querySelector('main')).loadDashboardSummary({ force: true }));
          assert.deepEqual(await page.locator('main h3').allInnerTexts(), old);
          assert.equal(await page.locator('admin-loader[page]').isVisible(), false);
          await page.unroute('**/api/admin/dashboard/summary');
          await page.getByRole('button', { name: 'Retry', exact: true }).first().click();
          await page.waitForFunction(() => !Alpine.$data(document.querySelector('main')).summaryError);
        }
        if (name === 'grooming') {
          assert.equal(requests.filter(url => url.startsWith('/api/admin/bookings?')).length, 1);
          const count = requests.length;
          for (const tab of ['queued', 'in-progress', 'for-payment', 'to-be-picked-up', 'incoming']) await page.evaluate(tab => Alpine.$data(document.querySelector('main')).setTab(tab), tab);
          assert.equal(requests.length, count); assert.equal(await page.evaluate(() => typeof window.Html5Qrcode), 'undefined');
        }
        if (name === 'clinic') {
          const count = requests.length;
          for (const tab of ['expected', 'for-payment', 'active-cases']) await page.evaluate(tab => Alpine.$data(document.querySelector('[x-data="adminClinicCasesSection()"]')).setTab(tab), tab);
          assert.equal(requests.length, count, 'Loaded Clinic filters do not refetch');
        }
        if (name === 'inventory') {
          await page.evaluate(() => location.hash = 'products'); await ready(page, name, property);
          await page.getByRole('button', { name: 'Add Product', exact: true }).click();
          await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).sectionData('products').modal.open);
          await page.evaluate(() => Alpine.$data(document.querySelector('main')).sectionData('products').modal.open = false);
          const count = requests.length;
          await page.evaluate(() => location.hash = 'overview'); await ready(page, name, property);
          assert.equal(requests.length, count, 'Loaded Inventory panel is immediate');
          for (const section of ['stock-in', 'stock-out', 'history', 'sell-product']) {
            await page.evaluate(section => location.hash = section, section);
            await page.waitForFunction(section => {
              const ui = Alpine.$data(document.querySelector('main'));
              return ui.activeSection === section && !ui.sectionLoading && !!ui.sectionData(section);
            }, section);
            assert.equal(await page.locator('[data-admin-heading]:visible').count(), 1);
          }
          await page.evaluate(() => location.hash = 'overview'); await ready(page, name, property);
          // An ordinary refresh now honors freshness. Let its five-second
          // presentation window expire before exercising a network failure.
          await pause(5100);
          await page.route('**/api/inventory/summary*', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Local retry test"}' }));
          const refreshCount = requests.filter(url=>url.includes('/inventory/summary')).length;
          await page.evaluate(() => Alpine.$data(document.querySelector('main')).sectionData('overview').load());
          assert.equal(requests.filter(url=>url.includes('/inventory/summary')).length,refreshCount+1,'Expired presentation revalidates even when retained');
          assert.equal(await page.locator('admin-loader:visible').count(), 0, 'Background Inventory refresh keeps loaded content');
          await page.unroute('**/api/inventory/summary*');
        }
        if (name === 'services' && key === 'admin') {
          await page.getByRole('tab', { name: 'Clinic', exact: true }).click();
          await ready(page, name, property);
          const count = requests.length;
          await page.getByRole('tab', { name: 'Grooming', exact: true }).click(); await ready(page, name, property);
          assert.equal(requests.length, count, 'Services reuses its loaded panels');
        }
        if (name === 'reports') {
          await page.getByRole('tab', { name: 'Clinic', exact: true }).click(); await ready(page, name, property);
          const count = requests.length;
          await page.getByRole('tab', { name: 'Grooming', exact: true }).click(); await ready(page, name, property);
          assert.equal(requests.length, count);
        }
        if (name === 'settings') {
          const count = requests.filter(url => /security\/(accounts|account)$/.test(url)).length;
          await page.getByRole('button', { name: 'General', exact: true }).click();
          await page.getByRole('button', { name: 'Security', exact: true }).click(); await ready(page, name, property);
          assert.equal(requests.filter(url => /security\/(accounts|account)$/.test(url)).length, count);
          await page.getByRole('button', { name: 'Availability', exact: true }).click();
          await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).availabilityLoaded);
          await page.waitForFunction(() => Alpine.$data(document.querySelector('[data-admin-shell]')).blockedDatesLoaded);
          await page.evaluate(() => Alpine.$data(document.querySelector('main')).loadAvailability());
          assert.equal(await page.locator('admin-loader:visible').count(), 0, 'Loaded Availability is retained');
        }
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().right <= 1);
        await page.evaluate(() => Alpine.$data(document.querySelector('[data-admin-shell]')).sidebarOpen = true);
        await page.waitForFunction(() => document.querySelector('aside').getBoundingClientRect().left >= 0);
        assert.equal(await page.locator('aside nav').isVisible(), true);
        await page.evaluate(() => Alpine.$data(document.querySelector('[data-admin-shell]')).sidebarOpen = false);
        await page.setViewportSize({ width: 1440, height: 1000 });
        assert.deepEqual(errors, [], name + ' browser errors');
        console.log(key + ' ' + name + ': authorized shell, title, loader, content and mobile passed');
      }
      if (process.env.LOCAL_REVIEW_WALK_IN) {
        const entries = key === 'admin' ? ['dashboard.html', 'appointments.html', 'clinic.html']
          : [key === 'clinic' ? 'clinic.html' : 'appointments.html'];
        for (const entry of entries) {
          await page.goto(origin + '/pages/admin/' + entry, { waitUntil: 'domcontentloaded' });
          await page.waitForFunction(() => document.querySelector('[data-admin-shell]')?.hasAttribute('data-admin-ready') && !document.documentElement.hasAttribute('data-session-gate'));
          let release;
          const held = new Promise(resolve => { release = resolve; });
          await page.route('**/api/session', async route => { await held; await route.continue(); });
          const before = requests.length;
          const started = Date.now();
          try {
            await Promise.all([page.waitForEvent('domcontentloaded'), page.getByRole('button', { name: 'Walk-in', exact: true }).click()]);
            assert.equal(await page.locator('#bethlehem-session-lock').isVisible(), false);
            assert.equal(await page.locator('#walkInPageTitle').isVisible(), true);
            assert.equal(await page.locator('#walkInOwnerForm').isVisible(), false);
            assert.equal(await page.evaluate(async () => { try { await API.submitWalkIn({}); } catch (error) { return error.status; } }), 503);
          } finally { release(); }
          await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
          await page.unroute('**/api/session');
          assert.equal(requests.slice(before).filter(url => url === '/api/session').length, 1);
          assert.equal(requests.slice(before).filter(url => url === '/api/me').length, 0);
          const flow = entry === 'clinic.html' ? 'clinic' : 'grooming';
          assert.equal(await page.evaluate(() => JSON.parse(sessionStorage.getItem('walkInOwnerStep')).appointmentType), flow);
          console.log(`${key} ${entry} → ${flow} Walk-in: real API verified in ${Date.now() - started}ms, one /session, zero /me, no checking card`);
          if (process.env.LOCAL_REVIEW_LOCK_HELPER && process.env.LOCAL_REVIEW_PASSWORD) {
            await page.locator('#ownerFirstName').fill('Retained local draft');
            execFileSync('php', [process.env.LOCAL_REVIEW_LOCK_HELPER, 'lock', key]);
            await page.evaluate(() => window.dispatchEvent(new Event('focus')));
            await page.getByRole('heading', { name: 'Session locked', exact: true }).waitFor();
            await page.getByLabel('Password', { exact: true }).fill('IncorrectLocalPassword');
            await page.getByRole('button', { name: 'Unlock session', exact: true }).click();
            await page.getByText('Incorrect password.', { exact: true }).waitFor();
            await page.getByLabel('Password', { exact: true }).fill(process.env.LOCAL_REVIEW_PASSWORD);
            await page.getByRole('button', { name: 'Unlock session', exact: true }).click();
            await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
            assert.equal(await page.locator('#ownerFirstName').inputValue(), 'Retained local draft');
          }
          await page.evaluate(flow => {
            sessionStorage.setItem('walkInOwnerStep', JSON.stringify({ appointmentType: flow, firstName: 'Local', lastName: 'Review' }));
            sessionStorage.setItem('walkInPetStep', JSON.stringify({ pets: [{ id: 'local-review', petName: 'Private Local Pet', petType: 'Dog', breed: 'Poodle', furType: 'Curly', weight: 8, size: 'Small' }], newPetForm: {} }));
          }, flow);
          await page.goto(origin + '/pages/admin/walk-in-pet-details.html', { waitUntil: 'domcontentloaded' });
          await page.getByText('Private Local Pet', { exact: true }).waitFor();
          const stepChecks = requests.filter(url => url === '/api/session').length;
          await page.locator('#nextBtn').click();
          await page.locator(flow === 'clinic' ? '#clinicComplaintForm' : '#walkInServicesForm').waitFor();
          assert.equal(await page.locator('#bethlehem-session-lock').isVisible(), false);
          assert.equal(requests.filter(url => url === '/api/session').length, stepChecks);
          await page.setViewportSize({ width: 375, height: 844 });
          assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
          await page.setViewportSize({ width: 1440, height: 1000 });
        }
        assert.deepEqual(errors, [], 'Real Walk-in session/navigation errors');
        console.log(key + ': real Walk-in lock/unlock, retained form and pet draft, dynamic step and mobile passed');
        await context.close();
        continue;
      }
      if (process.env.LOCAL_REVIEW_VERIFY_GATE) {
        let release;
        const held = new Promise(resolve => { release = resolve; });
        await page.route('**/api/session', async route => { await held; await route.continue(); });
        try {
          await page.goto(origin + '/pages/admin/settings.html', { waitUntil: 'domcontentloaded' });
          assert.equal(await page.locator('aside nav').isVisible(), true, 'Pending session validation preserves navigation');
          assert.equal(await page.locator('.admin-sidebar-footer').isVisible(), true, 'Pending session validation preserves the footer');
          assert.equal(await page.locator('aside nav').evaluate(el => el.inert), true, 'Pending navigation stays non-interactive');
          assert.equal(await page.locator('#bethlehem-session-lock').isVisible(), false, 'Verification leaves the safe shell visible without a checking card');
          assert.equal(await page.locator('main').evaluate(el => getComputedStyle(el).visibility), 'hidden', 'Cached protected content remains concealed');
          assert.equal(await page.locator('main [data-session-public]').filter({ hasText: /^Settings$/ }).isVisible(), true, 'Static title is visible independently');
          if (process.env.LOCAL_REVIEW_SCREENSHOTS) await page.screenshot({ path: path.join(process.env.LOCAL_REVIEW_SCREENSHOTS, 'navigation-pending-' + key + '.png') });
        } finally { release(); }
        await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate') && document.querySelector('[data-admin-shell]')?.hasAttribute('data-admin-ready'));
        await page.unroute('**/api/session');
        console.log(key + ' pending session presentation passed');
      }
      if (process.env.LOCAL_REVIEW_LOCK_HELPER) {
        execFileSync('php', [process.env.LOCAL_REVIEW_LOCK_HELPER, 'lock', key]);
        await page.goto(origin + '/pages/admin/settings.html', { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: 'Session locked', exact: true }).waitFor();
        assert.equal(await page.locator('aside.admin-sidebar-menu').evaluate(el => getComputedStyle(el).visibility), 'hidden');
        await page.getByLabel('Password', { exact: true }).fill('DeliberatelyIncorrectLocalTest!');
        await page.getByRole('button', { name: 'Unlock session', exact: true }).click();
        await page.getByText('Incorrect password.', { exact: true }).waitFor();
        assert.equal(await page.evaluate(async () => {
          try { await API.adminStopToday(); return null; } catch (error) { return error.status; }
        }), 423, 'Locked writes stay blocked');
        if (process.env.LOCAL_REVIEW_PASSWORD) {
          await page.getByLabel('Password', { exact: true }).fill(process.env.LOCAL_REVIEW_PASSWORD);
          await page.getByRole('button', { name: 'Unlock session', exact: true }).click();
          await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
          const destinations = key === 'clinic' ? ['Customers', 'Clinic', 'Services', 'Inventory', 'History']
            : ['Dashboard', 'Grooming', ...(session.role === 'admin' ? ['Clinic'] : []), 'Dashboard', 'Customers',
              ...(session.role === 'admin' ? ['Services'] : []), 'Inventory', 'History'];
          for (const label of destinations) {
            const before = requests.length;
            await Promise.all([page.waitForEvent('domcontentloaded', { timeout: 30000 }), (async () => {
              if (label === 'Inventory') {
                await page.getByRole('button', { name: 'Inventory', exact: true }).click();
                await page.getByRole('link', { name: 'Overview', exact: true }).click();
              } else await page.locator('aside').getByRole('link', { name: label, exact: true }).click();
            })()]);
            await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate') && document.querySelector('[data-admin-shell]')?.hasAttribute('data-admin-ready'));
            assert.equal(await page.locator('#bethlehem-session-lock').isVisible(), false, 'Real navigation after unlocking has no authentication popup');
            assert.equal(requests.slice(before).filter(url => url === '/api/session').length, 1);
            assert.equal(requests.slice(before).filter(url => url === '/api/me').length, 0);
            assert.equal((await page.locator('aside nav [aria-current="page"]').innerText()).trim(), label === 'Inventory' ? 'Overview' : label);
          }
          await page.goBack({waitUntil: 'domcontentloaded'});
          await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
          await page.goForward({waitUntil: 'domcontentloaded'});
          await page.waitForFunction(() => !document.documentElement.hasAttribute('data-session-gate'));
          assert.equal(await page.locator('#bethlehem-session-lock').isVisible(), false);
          assert.deepEqual(errors, [], 'Real navigation after unlock produces no JavaScript errors');
          console.log(key + ' real sidebar navigation after unlock, back/forward, one /session and zero /me per document passed');
          if (process.env.LOCAL_REVIEW_VERIFY_REVOCATION && key !== 'clinic') {
            execFileSync('php', [process.env.LOCAL_REVIEW_LOCK_HELPER, key === 'admin' ? 'expire' : 'revoke', key]);
            await page.goto(origin + '/pages/admin/settings.html', {waitUntil: 'domcontentloaded'});
            await page.waitForURL('**/sign-in.html');
            assert.equal(await page.evaluate(() => localStorage.getItem('admin_token')), null);
            console.log(key + ' real ' + (key === 'admin' ? 'expiration' : 'revocation') + ' redirect passed');
            await context.close();
            continue;
          }
          execFileSync('php', [process.env.LOCAL_REVIEW_LOCK_HELPER, 'lock', key]);
          await page.goto(origin + '/pages/admin/settings.html', {waitUntil: 'domcontentloaded'});
          await page.getByRole('heading', {name: 'Session locked', exact: true}).waitFor();
        }
        await page.getByRole('button', { name: 'Sign in with another account', exact: true }).click();
        await page.waitForURL('**/sign-in.html');
        assert.equal(await page.evaluate(() => localStorage.getItem('admin_token')), null);
        console.log(key + ' real lock and logout passed');
      }
      await context.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
