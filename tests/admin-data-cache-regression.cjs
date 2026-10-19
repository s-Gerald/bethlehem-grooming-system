const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('scripts/api.js', 'utf8');
class Storage {
  values = new Map();
  getItem(k) { return this.values.get(k) ?? null; }
  setItem(k, v) { this.values.set(k, String(v)); }
  removeItem(k) { this.values.delete(k); }
}
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data, headers: { get: () => null } });
function environment() {
  const localStorage = new Storage(), sessionStorage = new Storage(), calls = [];
  let now = 1000000, handler = () => ({ version: 1, customers: [{ id: 1 }], cases: [] });
  function page() {
    const events = new Map();
    const c = vm.createContext({ localStorage, sessionStorage, URL, URLSearchParams, AbortController, setTimeout, clearTimeout, console,
      Date: class extends Date { static now() { return now; } },
      CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
      document: { querySelector: () => null, getElementById: () => null, addEventListener() {} },
      window: { location: { protocol: 'http:', origin: 'http://127.0.0.1:8000', hostname: '127.0.0.1', port: '8000', pathname: '/pages/admin/sign-in.html', replace() {} },
        addEventListener: (name, fn) => events.set(name, fn), dispatchEvent() {} },
      fetch: async (url, options) => { const endpoint = url.split('/api')[1]; calls.push({ endpoint, method: options.method });
        const data = await handler(endpoint, options); return data?.json ? data : response(data); },
    });
    vm.runInContext(source, c);
    return { API: c.API, c, events };
  }
  const first = page(); first.API.setAdminToken('1|synthetic-secret');
  return { ...first, page, localStorage, sessionStorage, calls, advance: n => { now += n; }, respond: fn => { handler = fn; } };
}
async function navigationAndRetention() {
  const e = environment();
  await e.API.getCustomers();
  const next = e.page().API; let visible;
  await next.loadAdminData('/admin/customers?status=active', () => next.getCustomers(), d => { visible = d; });
  assert.equal(visible.customers[0].id, 1); assert.equal(e.calls.length, 1, 'Fresh list survives an HTML navigation');
  e.advance(31000); const waiting = deferred(); e.respond(() => waiting.promise);
  visible = null;
  const pending = next.loadAdminData('/admin/customers?status=active', () => next.getCustomers(), d => { visible = d; });
  assert.equal(visible.customers[0].id, 1, 'Retained data renders synchronously before the server replies');
  waiting.resolve({ customers: [{ id: 2 }] }); await pending;
  assert.equal(visible.customers[0].id, 2);
  assert.ok(!JSON.stringify([...e.sessionStorage.values]).includes('synthetic-secret'));
}
async function deduplicationAndMutationRace() {
  const e = environment(), old = deferred(); e.respond(() => old.promise);
  const a = e.API.getActiveClinicCases(), b = e.API.getActiveClinicCases();
  assert.equal(e.calls.length, 1);
  e.respond(() => ({ success: true })); await e.API.startClinicCase(1);
  e.respond(() => ({ version: 2 })); await e.API.getActiveClinicCases();
  old.resolve({ version: 1 });
  await Promise.all([assert.rejects(a, /changed|superseded/i), assert.rejects(b, /changed|superseded/i)]);
  assert.equal((await e.API.getActiveClinicCases()).version, 2);
  e.respond(() => ({version: 3}));
  assert.equal((await e.API.getActiveClinicCases({force: true})).version, 3, 'An explicit conflict refresh must bypass a fresh list');
}
async function preciseInvalidationAndCrossTab() {
  const e = environment();
  const seed = async () => { for (const endpoint of ['/admin/customers?status=active', '/admin/dashboard/summary', '/admin/bookings?date=2026-10-19&view=board', '/admin/clinic-cases', '/admin/clinic-records?page=1', '/admin/clinic-confinements?page=1', '/admin/notifications', '/clinic/status']) await e.API.adminRequest('GET', endpoint); };
  await seed();
  await e.API.markNotificationRead(1);
  assert.equal(e.API.readAdminCache('/admin/notifications'), null);
  assert.ok(e.API.readAdminCache('/admin/clinic-cases'));
  await seed();
  await e.API.adminRequest('PATCH', '/admin/bookings/1/internal-staff-note', {internal_staff_note: 'Local note'});
  assert.equal(e.API.readAdminCache('/admin/bookings?date=2026-10-19&view=board'), null);
  for (const key of ['/admin/dashboard/summary', '/admin/notifications', '/admin/customers?status=active']) {
    assert.ok(e.API.readAdminCache(key), 'An internal note affects the board alone');
  }
  await seed();
  await e.API.deactivateCustomer(1);
  assert.equal(e.API.readAdminCache('/admin/customers?status=active'), null);
  assert.equal(e.API.readAdminCache('/admin/clinic-records?page=1'), null);
  assert.ok(e.API.readAdminCache('/admin/bookings?date=2026-10-19&view=board'), 'Customer account status does not flush unrelated Grooming board');
  await seed(); await e.API.adminUpdatePet(1, {pet_name: 'Updated patient'});
  assert.equal(e.API.readAdminCache('/admin/bookings?date=2026-10-19&view=board'), null, 'Pet edits invalidate the patient presentation on the board');
  assert.equal(e.API.readAdminCache('/admin/dashboard/summary'), null, 'Queue preview patient information must update too');
  await seed();
  await e.API.startClinicCase(1);
  for (const key of ['/admin/clinic-cases', '/admin/dashboard/summary']) assert.equal(e.API.readAdminCache(key), null);
  assert.ok(e.API.readAdminCache('/admin/clinic-records?page=1'));
  await seed(); await e.API.clinicAdmitConfinement(1, {});
  assert.equal(e.API.readAdminCache('/admin/clinic-confinements?page=1'), null);
  await seed();
  const other = e.page(); await other.API.markNotificationRead(2);
  e.events.get('storage')({ storageArea: e.localStorage, key: 'bethlehem.admin.data_changed', newValue: e.localStorage.getItem('bethlehem.admin.data_changed') });
  assert.equal(e.API.readAdminCache('/admin/notifications'), null);
  assert.ok(e.API.readAdminCache('/admin/clinic-cases'), 'Cross-tab invalidation remains group-specific');
  await e.API.adminRequest('POST', '/admin/security/password', {});
  assert.ok(e.API.readAdminCache('/admin/clinic-cases'));
}
async function failuresAndSessionOwnership() {
  const e = environment(); await e.API.getActiveClinicCases(); e.advance(6000);
  for (const failure of [() => response({}, 503), () => { throw new Error('offline'); }]) {
    e.respond(failure); let visible;
    await e.API.loadAdminData('/admin/clinic-cases', () => e.API.getActiveClinicCases(), d => { visible = d; });
    assert.equal(visible.version, 1);
  }
  e.respond(() => response({ message: 'Forbidden' }, 403));
  await assert.rejects(e.API.loadAdminData('/admin/clinic-cases', () => e.API.getActiveClinicCases(), () => {}), error => error.status === 403);
  e.respond(() => ({ version: 1 })); await e.API.getCustomers();
  e.API.setAdminToken('2|replacement-secret');
  assert.equal(e.API.readAdminCache('/admin/customers?status=active'), null);
  await e.API.getCustomers(); e.API.clearAuthState();
  assert.equal(e.API.readAdminCache('/admin/customers?status=active'), null);
  e.API.setAdminToken('3|staff-secret'); e.API.setUserRole('staff');
  await e.API.getCustomers(); const count = e.calls.length;
  await e.page().API.getCustomers();
  assert.equal(e.calls.length, count, 'A Staff session also reuses its own presentation across documents');
  e.API.setUserRole('admin');
  assert.equal(e.API.readAdminCache('/admin/customers?status=active'), null, 'A changed role cannot reuse the previous Staff cache');
}
async function boundedSearchAndOperationalReuse() {
  const e = environment();
  for (let i = 0; i < 40; i++) await e.API.getCustomers({ search: 'query'+i });
  assert.ok(!JSON.stringify([...e.sessionStorage.values]).includes('query'), 'Free text results never enter session storage');
  await e.API.getAdminBookings('2026-10-19', { boardOnly: true });
  await e.API.getActiveClinicCases(); e.advance(6000);
  for (const [key, load] of [['/admin/bookings?date=2026-10-19&view=board', api => api.getAdminBookings('2026-10-19', { boardOnly: true })], ['/admin/clinic-cases', api => api.getActiveClinicCases()]]) {
    const api = e.page().API, wait = deferred(); e.respond(() => wait.promise); let visible;
    const p = api.loadAdminData(key, () => load(api), d => { visible = d; });
    assert.equal(visible.version, 1); wait.resolve({ version: 2 }); await p; assert.equal(visible.version, 2);
  }
  e.respond(() => ({})); for (let i = 0; i < 40; i++) await e.API.getAdminBookings('2026-10-'+i, { boardOnly: true });
  const stored = JSON.parse(e.sessionStorage.getItem('bethlehem.admin.presentation.v2'));
  assert.ok(Object.keys(stored.entries).length <= 24);
}
async function retainedDataWaitsForSessionVerification() {
  const e = environment(); await e.API.getCustomers(); e.advance(31000);
  const session = deferred(), data = deferred();
  e.respond(endpoint => endpoint === '/session' ? session.promise : data.promise);
  e.c.window.location.pathname = '/pages/admin/dashboard.html';
  e.c.document.createElement = () => ({});
  e.c.setTimeout = () => 1; e.c.clearTimeout = () => {};
  e.API.startSessionInactivityManager();
  let rendered;
  const p = e.API.loadAdminData('/admin/customers?status=active', () => e.API.getCustomers(), d => { rendered = d; });
  assert.equal(rendered, undefined, 'Retained content cannot bypass verification');
  session.resolve({locked: false, server_now: '2026-10-19T00:00:00Z', idle_at: '2026-10-19T00:30:00Z', expires_at: '2026-10-19T12:00:00Z'});
  await new Promise(r => setImmediate(r));
  assert.equal(rendered.customers[0].id, 1, 'Once verified, stale content appears before revalidation finishes');
  data.resolve({customers: [{id: 2}]}); await p;
  assert.equal(rendered.customers[0].id, 2);
}
module.exports = { environment, deferred };
if (require.main === module) (async () => { for (const test of [navigationAndRetention, deduplicationAndMutationRace, preciseInvalidationAndCrossTab, failuresAndSessionOwnership, boundedSearchAndOperationalReuse, retainedDataWaitsForSessionVerification]) {
  await test(); console.log(test.name+' passed');
} })().catch(error => { console.error(error); process.exitCode = 1; });
