const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
class Storage {
  values = new Map();
  getItem(key) { return this.values.get(key) ?? null; }
  setItem(key, value) { this.values.set(key, String(value)); }
  removeItem(key) { this.values.delete(key); }
}
const identity = (overrides = {}, revision = 'revision-1') => ({
  sidebar_revision: revision,
  user: { user_id: 7, role: 'staff', staff_type: 'clinic', staff_subrole: 'veterinarian',
    first_name: 'Test', last_name: 'Staff', username: 'teststaff', clinic_permissions: { access: true, clinical: true, services_view: true },
    staff_permissions: {grooming: overrides.role === 'admin' || ['grooming_receptionist', 'clinic_receptionist'].includes(overrides.staff_subrole)}, ...overrides },
});
function environment() {
  const localStorage = new Storage(), sessionStorage = new Storage(), calls = [];
  let response = () => identity(), status = 200;
  function page() {
    const listeners = new Map();
    const c = vm.createContext({ localStorage, sessionStorage, URL, URLSearchParams, Date, console,
      AbortController, setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
      CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
      document: { querySelector: () => null, querySelectorAll: () => [], getElementById: () => null,
        addEventListener() {}, removeEventListener() {} },
      window: { location: { protocol: 'http:', origin: 'http://127.0.0.1:8000', hostname: '127.0.0.1',
        port: '8000', pathname: '/pages/admin/clinic.html', hash: '', replace() {} },
        addEventListener(name, fn) { listeners.set(name, [...(listeners.get(name) || []), fn]); },
        removeEventListener() {}, dispatchEvent(event) { for (const fn of listeners.get(event.type) || []) fn(event); } },
      fetch: async (url, options) => { calls.push({ url, method: options.method }); const data = await response(url, options);
        return { ok: status >= 200 && status < 300, status, json: async () => data, headers: { get: () => null } }; },
    });
    vm.runInContext(read('scripts/api.js'), c);
    c.window.API = c.API;
    vm.runInContext(read('scripts/components/admin-sidebar.js'), c);
    return c;
  }
  const c = page(); c.API.setAdminToken('17|test-secret'); c.API.setUserRole('staff');
  return { c, page, calls, localStorage, sessionStorage, respond: (fn, code = 200) => { response = fn; status = code; } };
}
async function reuseAndInvalidate() {
  const e = environment();
  await Promise.all([e.c.API.getStaffIdentity(), e.c.API.getStaffIdentity()]);
  assert.equal(e.calls.length, 1, 'Concurrent consumers share identity');
  const next = e.page();
  await next.API.getStaffIdentity();
  assert.equal(e.calls.length, 1, 'Navigation and hard reload reuse session identity without GET /me');
  assert.equal(next.API.getCachedStaffIdentity().user.user_id, 7);
  await next.API.adminCheckIn(1);
  await next.API.getStaffIdentity();
  assert.equal(e.calls.filter(c => c.url.endsWith('/me')).length, 1, 'Unrelated mutations do not expire navigation');
  next.API.clearAuthState();
  assert.equal(next.API.getCachedStaffIdentity(), null, 'Logout removes cached navigation synchronously');
  assert.equal(e.sessionStorage.getItem('bethlehem.admin.navigation.v1'), null);
}
async function isolationAndRaces() {
  const e = environment();
  let resolve;
  e.respond(() => new Promise(r => { resolve = r; }));
  const old = e.c.API.getStaffIdentity();
  e.c.API.setAdminToken('18|other-secret'); e.c.API.setUserRole('staff');
  resolve(identity());
  await assert.rejects(old, /changed|ended|superseded/i);
  assert.equal(e.c.API.getCachedStaffIdentity(), null);
  e.respond(() => identity({ user_id: 8, staff_type: 'grooming', staff_subrole: 'grooming_receptionist' }, 'revision-2'));
  await e.c.API.getStaffIdentity();
  const sidebar = e.page().adminSidebar();
  assert.equal(sidebar.sidebarReady, true, 'Cached navigation is ready synchronously');
  assert.equal(sidebar.canAccessGrooming, true);
  assert.equal(sidebar.staffRoleLabel, 'Grooming Receptionist');
  assert.equal(sidebar.isAdmin, false);
  assert.equal(sidebar.activePage, 'clinic');
  e.localStorage.setItem('admin_token', '19|third-secret');
  assert.equal(e.page().API.getCachedStaffIdentity(), null, 'Token switches cannot reuse another account presentation');
}
async function independentRendering() {
  const e = environment();
  await e.c.API.getStaffIdentity();
  const c = e.page(), sidebar = c.adminSidebar();
  let statusCalls = 0;
  c.API.getClinicStatus = async () => { statusCalls++; return { stopped_today: {clinic: false, grooming: false} }; };
  sidebar.$nextTick = fn => fn();
  const pending = sidebar.init();
  assert.equal(sidebar.sidebarReady, true, 'Cached navigation is available immediately');
  assert.equal(sidebar.staffDisplayName, 'Test Staff');
  await Promise.resolve();
  await pending;
  assert.equal(statusCalls, 0, 'Sidebar initialization does not read intake status');
  assert.equal(e.calls.filter(c => c.url.endsWith('/me')).length, 1);
  sidebar.destroy();
}
async function rolesAndInvalidCache() {
  const cases = [
    [{ role: 'admin', staff_type: null, staff_subrole: null }, true, true, 'Staff'],
    [{ staff_type: 'clinic', staff_subrole: 'veterinarian' }, false, false, 'Veterinarian'],
    [{ staff_type: 'clinic', staff_subrole: 'clinic_receptionist' }, false, true, 'Clinic Receptionist'],
    [{ staff_type: 'grooming', staff_subrole: 'grooming_receptionist', staff_permissions: {grooming: false} }, false, false, 'Grooming Receptionist'],
    [{ staff_type: 'grooming', staff_subrole: 'grooming_receptionist', clinic_permissions: {} }, false, true, 'Grooming Receptionist'],
  ];
  for (const [user, admin, grooming, label] of cases) {
    const e = environment();
    if (admin) e.c.API.setUserRole('admin');
    e.respond(() => identity(user));
    await e.c.API.getStaffIdentity();
    const sidebar = e.page().adminSidebar();
    assert.equal(sidebar.isAdmin, admin);
    assert.equal(sidebar.canAccessGrooming, grooming);
    assert.equal(sidebar.staffRoleLabel, label);
  }
  const e = environment();
  e.sessionStorage.setItem('bethlehem.admin.navigation.v1', '{invalid');
  let c = e.page();
  assert.equal(c.adminSidebar().sidebarReady, false);
  await c.API.getStaffIdentity();
  const stored = JSON.parse(e.sessionStorage.getItem('bethlehem.admin.navigation.v1'));
  assert.equal(JSON.stringify(stored).includes('test-secret'), false, 'Cache never persists bearer secrets');
  stored.at = Date.now() - 12 * 3600000;
  e.sessionStorage.setItem('bethlehem.admin.navigation.v1', JSON.stringify(stored));
  assert.equal(e.page().adminSidebar().sidebarReady, false, 'Expired cache cannot expose navigation');
  e.sessionStorage.getItem = () => { throw new Error('Storage blocked'); };
  c = e.page();
  assert.equal(c.adminSidebar().sidebarReady, false);
  await c.API.getStaffIdentity();
  assert.equal(c.API.getCachedStaffIdentity().user.user_id, 7, 'Memory presentation works if storage is blocked');
}
const settle = async () => { for (let i = 0; i < 40; i++) await Promise.resolve(); };
function enableSession(c) {
  const attrs = new Map();
  c.document.documentElement = { setAttribute: (key, value) => attrs.set(key, value), removeAttribute: key => attrs.delete(key) };
  c.document.createElement = () => ({});
  c.document.visibilityState = 'visible';
  c.API.startSessionInactivityManager();
  return attrs;
}
async function permissionRevalidation() {
  const e = environment();
  await e.c.API.getStaffIdentity();
  let revision = 'revision-1';
  e.respond(url => url.endsWith('/session') ? {
    locked: false, server_now: new Date().toISOString(), idle_at: new Date(Date.now() + 1800000).toISOString(),
    expires_at: new Date(Date.now() + 43200000).toISOString(), sidebar_revision: revision, user_id: 7, role: 'staff',
  } : identity(revision === 'revision-2' ? { staff_type: 'grooming', staff_subrole: 'grooming_receptionist', clinic_permissions: {} } : {}, revision));
  const c = e.page(), sidebar = c.adminSidebar();
  sidebar.$nextTick = fn => fn();
  await sidebar.init();
  const navigation = { inert: false };
  c.document.querySelectorAll = () => [navigation];
  const attrs = enableSession(c);
  assert.equal(navigation.inert, true, 'Cached navigation blocks keyboard and pointer interaction while checking');
  await settle();
  assert.equal(attrs.has('data-session-gate'), false, 'Server validation still releases the session gate');
  assert.equal(navigation.inert, false);
  assert.equal(e.calls.filter(c => c.url.endsWith('/me')).length, 1, 'Unchanged /session revision needs no identity refetch');
  await c.API.adminRequest('GET', '/inventory/items?page=1');
  assert.ok(c.API.readAdminCache('/inventory/items?page=1'));
  revision = 'revision-2';
  c.window.dispatchEvent({ type: 'focus' });
  await settle();
  assert.equal(e.calls.filter(c => c.url.endsWith('/me')).length, 2, 'Changed server permissions fetch identity once');
  assert.equal(sidebar.canAccessGrooming, true);
  assert.equal(sidebar.clinicPermissions.access, undefined);
  assert.equal(sidebar.sidebarReady, true);
  assert.equal(c.API.readAdminCache('/inventory/items?page=1'), null, 'A verified permission change discards page data from the former role');
  e.respond(() => ({success: true}));
  await c.API.logout('staff');
  assert.equal(sidebar.sidebarReady, false, 'Logout clears the existing navigation identity immediately');
  assert.equal(sidebar.canAccessGrooming, false, 'Logout revokes permission-dependent presentation');
  assert.deepEqual(Object.keys(sidebar.clinicPermissions), []);
  assert.equal(sidebar.staffDisplayName, '', 'Logout clears the previous account name');
  assert.equal(c.API.getCachedStaffIdentity(), null);
  sidebar.destroy();
}
async function firstLoginAndExpiration() {
  const e = environment();
  e.c.API.setUserRole('admin');
  let resolveIdentity;
  e.respond(url => url.endsWith('/me') ? new Promise(resolve => { resolveIdentity = resolve; }) : {});
  const sidebar = e.c.adminSidebar();
  sidebar.$nextTick = fn => fn();
  const pending = sidebar.init();
  assert.equal(sidebar.sidebarReady, false, 'First login keeps permission-dependent presentation unresolved');
  assert.equal(sidebar.isAdmin, false, 'Stored role alone cannot reveal admin controls');
  resolveIdentity(identity({ role: 'admin', staff_type: null, staff_subrole: null }));
  await pending;
  assert.equal(sidebar.sidebarReady, true);
  assert.equal(sidebar.isAdmin, true);
  e.respond(() => ({ message: 'Session expired.' }), 401);
  await assert.rejects(e.c.API.getAdminBookings('2026-10-18', { force: true }), error => error.status === 401);
  assert.equal(e.c.API.getCachedStaffIdentity(), null);
  assert.equal(e.c.API.getAdminToken(), null);
  assert.equal(sidebar.sidebarReady, false, 'Expiration clears the existing navigation identity');
  assert.equal(sidebar.isAdmin, false, 'Expiration clears admin presentation even when the shell stays visible');
  sidebar.destroy();
}
async function cachedIdentityLogoutRace() {
  const e = environment();
  await e.c.API.getStaffIdentity();
  const c = e.page(), sidebar = c.adminSidebar();
  sidebar.$nextTick = fn => fn();
  const pending = sidebar.init();
  c.API.clearAuthState();
  await pending;
  assert.equal(sidebar.sidebarReady, false, 'A queued cached identity cannot restore presentation after logout');
  assert.equal(sidebar.staffDisplayName, '');
  assert.equal(sidebar.clinicPermissions.access, undefined);
  sidebar.destroy();
}
async function sharedReceptionistSwitchReplacesTheFormerAccountDocument() {
  for (const subroles of [['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']]) {
    const e = environment();
    e.respond(() => identity({staff_subrole: subroles[0], staff_type: subroles[0] === 'clinic_receptionist' ? 'clinic' : 'grooming'}));
    await e.c.API.getStaffIdentity();
    await e.c.API.adminRequest('GET', '/inventory/items?page=1');
    let reloads = 0;
    e.c.window.location.reload = () => { reloads++; };
    // Another normal tab finishes a new staff login while this document still
    // contains the previous account's workspace and cached identity.
    e.localStorage.setItem('admin_token', '18|replacement-session');
    e.c.window.dispatchEvent({type: 'storage', storageArea: e.localStorage,
      key: 'admin_token', oldValue: '17|test-secret', newValue: '18|replacement-session'});
    assert.equal(e.c.API.getCachedStaffIdentity(), null);
    assert.equal(e.c.API.readAdminCache('/inventory/items?page=1'), null);
    assert.equal(reloads, 1, 'A confirmed shared-profile account switch replaces the former account workspace');
    e.c.window.dispatchEvent({type: 'storage', storageArea: e.localStorage,
      key: 'user_role', oldValue: null, newValue: 'staff'});
    assert.equal(reloads, 1, 'The matching role event does not request a duplicate reload');
  }
}
async function profileIdentitySynchronization() {
  const e = environment();
  await e.c.API.getStaffIdentity();
  const sidebar = e.c.adminSidebar();
  sidebar.$nextTick = fn => fn();
  await sidebar.init();
  let lateIdentity;
  e.respond(url => url.endsWith('/me') ? new Promise(resolve => { lateIdentity = resolve; }) : ({
    ...identity({ first_name: 'Updated', last_name: 'Person', username: 'updated.staff' }, 'revision-updated'),
    account: { first_name: 'Updated', last_name: 'Person', username: 'updated.staff' }, success: true,
  }));
  const oldMe = e.c.API.getMe('staff');
  const epoch = e.c.API.getAdminDataRevision('grooming');
  await e.c.API.updateStaffProfile({ first_name: 'Updated', last_name: 'Person', username: 'updated.staff', current_password: 'Test!234' });
  assert.equal(sidebar.staffDisplayName, 'Updated Person');
  assert.equal(sidebar.staffInitials, 'UP');
  assert.equal(sidebar.clinicPermissions.clinical, true);
  assert.equal(e.c.API.getAdminToken(), '17|test-secret');
  assert.equal(e.c.API.getAdminDataRevision('grooming'), epoch, 'Profile updates preserve operational cache epochs');
  lateIdentity(identity());
  await oldMe;
  assert.equal(e.c.API.getCachedStaffIdentity().user.first_name, 'Updated', 'Late GET /me cannot restore old cache');
  const next = e.page();
  const updated = await next.API.getStaffIdentity();
  assert.equal(updated.user.username, 'updated.staff');
  assert.equal(next.adminSidebar().staffDisplayName, 'Updated Person');
  assert.equal(e.calls.filter(c => c.url.endsWith('/me')).length, 2, 'Saving/navigation do not fetch identity again');
  sidebar.destroy();
}

async function profileSessionSwitchRace() {
  const e = environment();
  await e.c.API.getStaffIdentity();
  let resolveUpdate;
  e.respond(() => new Promise(resolve => { resolveUpdate = resolve; }));
  const update = e.c.API.updateStaffProfile({ first_name: 'Updated', last_name: 'Person', username: 'newstaff' });
  e.c.API.setAdminToken('18|another-session');
  resolveUpdate(identity({ first_name: 'Updated' }, 'revision-updated'));
  await assert.rejects(update, /session changed/i);
  assert.equal(e.c.API.getCachedStaffIdentity(), null);
}

async function activityLogNavigationUsesVerifiedAdminIdentity() {
  for (const subrole of [null, 'grooming_receptionist', 'clinic_receptionist', 'veterinarian']) {
    const e = environment(), admin = subrole === null;
    if (admin) e.c.API.setUserRole('admin');
    e.respond(() => identity({role: admin ? 'admin' : 'staff', staff_subrole: subrole}));
    await e.c.API.getStaffIdentity();
    const makeLink = () => ({attrs: new Map(), style: {},
      setAttribute(name, value) {this.attrs.set(name, value);}, getAttribute(name) {return this.attrs.get(name);}});
    let inserted;
    const settings = {href: 'http://127.0.0.1:8000/pages/admin/settings.html',
      cloneNode: () => makeLink(), before: link => {inserted = link;}};
    const aside = {querySelector: () => null, closest: () => null, setAttribute() {}, removeAttribute() {},
      querySelectorAll: selector => selector === '[x-show]' ? [inserted] : []};
    e.c.document.currentScript = {src: 'http://127.0.0.1:8000/scripts/components/admin-sidebar.js'};
    e.c.document.querySelector = selector => selector.includes('settings.html') ? settings : selector === '.admin-sidebar-menu' ? aside : null;
    e.c.installAdminActivityNavigation();
    assert.equal(inserted.href, 'http://127.0.0.1:8000/pages/admin/activity-log.html');
    e.c.renderCachedAdminSidebar();
    assert.equal(inserted.style.display, admin ? '' : 'none', 'Activity Log precedes Settings and is visible only to verified admins');
    assert.equal(e.c.getAdminSidebarActivePage('/api/admin/activity-log/page'), 'activity-log');
    assert.equal(e.c.getAdminSidebarActivePage('/pages/admin/activity-log.html'), 'activity-log');
  }
}

async function availabilityNavigationForAuthorizedRoles() {
  for (const subrole of [null, 'grooming_receptionist', 'clinic_receptionist', 'veterinarian']) {
    const e = environment(), admin = subrole === null;
    if (admin) e.c.API.setUserRole('admin');
    e.respond(() => identity({role: admin ? 'admin' : 'staff', staff_subrole: subrole, staff_permissions: {availability_view: true, availability_manage: subrole !== 'veterinarian'}}));
    await e.c.API.getStaffIdentity();
    const links = [];
    const settings = {href: 'http://127.0.0.1:8000/pages/admin/settings.html', cloneNode: () => ({attrs: new Map(), style: {}, setAttribute(name,value) {this.attrs.set(name,value);}, getAttribute(name) {return this.attrs.get(name);}}), before: link => links.push(link)};
    const aside = {querySelector: () => null, closest: () => null, setAttribute() {}, removeAttribute() {}, querySelectorAll: selector => selector === '[x-show]' ? links : []};
    e.c.document.currentScript = {src: 'http://127.0.0.1:8000/scripts/components/admin-sidebar.js'};
    e.c.document.querySelector = selector => selector.includes('settings.html') ? settings : selector === '.admin-sidebar-menu' ? aside : null;
    e.c.installAdminActivityNavigation(); e.c.installAdminAvailabilityNavigation(); e.c.renderCachedAdminSidebar();
    assert.equal(links[0].href.endsWith('activity-log.html'), true);
    assert.equal(links[1].href.endsWith('availability.html'), true);
    assert.equal(links[1].style.display, '', 'Every authorized role can see Availability');
    assert.equal(links[1].attrs.get('@click'), 'sidebarOpen = false', 'Mobile sidebar closes through the shared action');
    assert.equal(e.c.getAdminSidebarActivePage('/pages/admin/availability.html'), 'availability');
    assert.equal(e.c.getAdminSidebarActivePage('/api/admin/availability/page'), 'availability');
  }
}

(async () => {
  for (const test of [reuseAndInvalidate, isolationAndRaces, independentRendering, rolesAndInvalidCache, permissionRevalidation, firstLoginAndExpiration, cachedIdentityLogoutRace, sharedReceptionistSwitchReplacesTheFormerAccountDocument, profileIdentitySynchronization, profileSessionSwitchRace, activityLogNavigationUsesVerifiedAdminIdentity, availabilityNavigationForAuthorizedRoles]) {
    await test(); console.log(test.name + ' passed');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
