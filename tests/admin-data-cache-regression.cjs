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
async function dashboardRoleChangeRejectsPendingData() {
  const e = environment(), held = deferred();
  e.respond(() => held.promise);
  let rendered = false;
  const pending = e.API.loadAdminData('/admin/dashboard/summary', () => e.API.getAdminDashboardSummary(), () => { rendered = true; });
  e.API.setUserRole('staff');
  held.resolve({summary: {revenueToday: 1200}});
  await assert.rejects(pending, /changed|session/i);
  assert.equal(rendered, false, 'An old-role response cannot render after the session role changes');
  assert.equal(e.API.readAdminCache('/admin/dashboard/summary'), null);
}
module.exports = { environment, deferred };
async function scopedPresentationPolicies() {
  for (const [key, freshness] of [
    ['/admin/grooming/services', 61000], ['/admin/clinic-services?page=1&status=active', 61000],
    ['/admin/clinic-services/options', 61000], ['/inventory/summary?page=1&view=overview', 6000],
    ['/inventory/items?page=1', 6000], ['/inventory/transactions?page=1', 6000],
  ]) {
    const e = environment(); await e.API.adminRequest('GET', key);
    const next = e.page().API;
    await next.adminRequest('GET', key);
    assert.equal(e.calls.length, 1, key + ' fresh cross-document reuse');
    e.advance(freshness); const held = deferred(); e.respond(() => held.promise); let visible;
    const a = next.loadAdminData(key, () => next.adminRequest('GET', key), data => { visible = data; });
    const b = next.adminRequest('GET', key);
    assert.equal(visible.version, 1, key + ' retained content precedes revalidation');
    assert.equal(e.calls.length, 2, key + ' revalidations deduplicate');
    held.resolve({ version: 2 }); await Promise.all([a, b]); assert.equal(visible.version, 2);
    e.API.setUserRole('staff'); assert.equal(e.API.readAdminCache(key), null);
    e.API.setAdminToken('2|other'); assert.equal(e.API.readAdminCache(key), null);
  }
  const e = environment();
  for (const key of ['/admin/bookings/archived', '/admin/clinic-appointments/archived', '/admin/clinic-services?page=1&q=private-search', '/inventory/items?page=1&q=private-search', '/inventory/transactions?page=1&type=stock_in']) {
    await e.API.adminRequest('GET', key); await e.API.adminRequest('GET', key);
    assert.equal(e.calls.filter(c => c.endpoint === key).length, 1, key + ' memory reuse');
    assert.ok(!e.sessionStorage.getItem('bethlehem.admin.presentation.v2')?.includes(key), key + ' must not persist');
  }
  for (const key of ['/inventory/search?q=retail&sale_context=pos', '/inventory/items/1', '/admin/bookings/1/signed-consent', '/admin/clinic-cases/1']) {
    await e.API.adminRequest('GET', key); await e.API.adminRequest('GET', key);
    assert.equal(e.calls.filter(c => c.endpoint === key).length, 2, key + ' operational reads stay current');
  }
}
async function scopedMutationInvalidation() {
  const keys = ['/admin/grooming/services', '/admin/clinic-services/options', '/inventory/items?page=1', '/inventory/summary?page=1&view=overview', '/inventory/transactions?page=1', '/admin/bookings/archived', '/admin/clinic-appointments/archived', '/admin/notifications?mode=count', '/admin/notifications?mode=full&service=clinic&activity=payments'];
  for (const [mutation, affected] of [
    ['/admin/grooming/services/1/status', [0]], ['/admin/clinic-services/1/status', [1]],
    ['/inventory/items/1/deactivate', [2,3,4]], ['/inventory/stock-in', [2,3,4]],
    ['/inventory/stock-out', [2,3,4]], ['/pos/transactions', [2,3,4]],
    ['/admin/bookings/1/mark-done', [5,7,8]], ['/admin/clinic-appointments/1/finish', [2,3,4,6,7,8]],
    ['/admin/bookings/1/pay', [2,3,4,5,7,8]], ['/admin/clinic-appointments/1/pay', [2,3,4,6,7,8]],
    ['/pos/validate-cart', []],
    ['/admin/bookings/1/online-payment', [2,3,4,5]], ['/pos/online-payment', [2,3,4]],
    ['/admin/clinic-appointments/1/online-payment', [6]],
  ]) {
    const e = environment();
    for (const key of keys) await e.API.adminRequest('GET', key);
    e.respond(() => response({message: 'Rejected'}, 422));
    await assert.rejects(e.API.adminRequest('POST', mutation, {}));
    for (const key of keys) assert.ok(e.API.readAdminCache(key), 'Failed mutation retains ' + key);
    e.respond(() => ({})); const other = e.page();
    // Memory-only History entries are seeded in the current document above.
    await other.API.adminRequest('POST', mutation, {});
    const signal = e.localStorage.getItem('bethlehem.admin.data_changed');
    if (signal) e.events.get('storage')({storageArea: e.localStorage, key: 'bethlehem.admin.data_changed', newValue: signal});
    keys.forEach((key, index) => assert.equal(!!e.API.readAdminCache(key), !affected.includes(index), mutation + ' invalidates precisely ' + key));
  }
}
async function onlineSettlementInvalidation() {
  const keys = ['/inventory/items?page=1', '/admin/bookings/archived', '/admin/clinic-appointments/archived', '/admin/notifications?mode=count'];
  for (const [endpoint, affected, receipt] of [
    ['/admin/bookings/1/online-payment', [0, 1, 3], {payment: {product_addons: [{itemName: 'Treats'}]}}],
    ['/admin/clinic-appointments/1/online-payment', [0, 2, 3], {appointment: {paid: true}}],
    ['/pos/online-payment/1', [0], {receipt: {pos_id: 1}}],
  ]) {
    const e = environment();
    for (const key of keys) await e.API.adminRequest('GET', key);
    e.respond(() => ({online_payment: {status: 'pending'}}));
    await e.API.adminRequest('GET', endpoint); await e.API.adminRequest('GET', endpoint);
    assert.equal(e.calls.filter(call => call.endpoint === endpoint).length, 2, 'Payment status must never use presentation cache');
    for (const key of keys) assert.ok(e.API.readAdminCache(key), 'Pending status preserves unrelated cached presentation');
    e.respond(() => ({online_payment: {status: 'paid'}, ...receipt}));
    await e.API.adminRequest('GET', endpoint);
    keys.forEach((key, index) => assert.equal(!!e.API.readAdminCache(key), !affected.includes(index), 'Settlement invalidates precisely ' + key));
  }
  for (const endpoint of ['/admin/bookings/1/online-payment', '/pos/online-payment/1']) {
    const e = environment(), inventory = '/inventory/items?page=1';
    await e.API.adminRequest('GET', inventory);
    e.respond(() => ({online_payment: {id: 1, status: 'expired'}}));
    await e.API.adminRequest('GET', endpoint);
    assert.equal(e.API.readAdminCache(inventory), null, 'Confirmed expiry refreshes released stock availability');
  }
  const e = environment(), inventory = '/inventory/items?page=1';
  await e.API.adminRequest('GET', inventory);
  e.respond(() => response({online_payment: {id: 1, status: 'pending'}}, 502));
  await assert.rejects(e.API.adminRequest('POST', '/pos/online-payment', {}));
  assert.equal(e.API.readAdminCache(inventory), null, 'An uncertain creation response can still own a stock hold');
}
async function inventoryReadMutationRace() {
  const e=environment(), old=deferred(), key='/inventory/items?page=1';
  e.respond(()=>old.promise); const a=e.API.adminRequest('GET',key), b=e.API.adminRequest('GET',key);
  assert.equal(e.calls.length,1);
  e.respond(()=>({})); await e.API.adminRequest('POST','/inventory/stock-out',{});
  old.resolve({version:1}); await Promise.all([assert.rejects(a,/changed/),assert.rejects(b,/changed/)]);
  await e.API.adminRequest('GET',key);

}
if (require.main === module) (async () => { for (const test of [navigationAndRetention, deduplicationAndMutationRace, preciseInvalidationAndCrossTab, failuresAndSessionOwnership, boundedSearchAndOperationalReuse, retainedDataWaitsForSessionVerification, dashboardRoleChangeRejectsPendingData]) {
  await test(); console.log(test.name+' passed');
} for (const test of [scopedPresentationPolicies, scopedMutationInvalidation, onlineSettlementInvalidation, inventoryReadMutationRace]) { await test(); console.log(test.name+' passed'); } })().catch(error => { console.error(error); process.exitCode = 1; });
