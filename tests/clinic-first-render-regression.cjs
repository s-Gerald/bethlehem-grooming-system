const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
const settle = () => new Promise(setImmediate);
function clinic() {
  const e = environment(), stores = {}, listeners = new Map();
  let intersect;
  e.API.setUserRole('admin');
  Object.assign(e.c, { setInterval: () => 1, clearInterval() {},
    IntersectionObserver: class { constructor(callback) { intersect = callback; } observe() {} disconnect() {} },
    Alpine: { store: (name, state) => stores[name] = state },
  });
  e.c.window.location.search = '';
  e.c.window.removeEventListener = () => {};
  e.c.document.removeEventListener = () => {};
  e.c.document.addEventListener = (name, fn) => listeners.set(name, fn);
  for (const file of ['clinic-feedback.js', 'admin-clinic.js', 'admin-clinic-shell.js'])
    vm.runInContext(fs.readFileSync('scripts/components/' + file, 'utf8'), e.c);
  listeners.get('alpine:init')();
  const ui = e.c.adminClinicCasesSection();
  ui.$store = stores;
  ui.$root = { querySelector: () => ({}) };
  return { ...e, ui, stores, intersect: () => intersect([{ isIntersecting: true }]) };
}
const identity = access => ({ sidebar_revision: 'clinic-test-1', user: { role: 'admin', user_id: 7, clinic_permissions: { access } } });
async function sharedIdentityAndLazyData() {
  const e = clinic(), pending = deferred();
  e.respond(endpoint => endpoint === '/me' ? pending.promise : { cases: [], records: [], current_page: 1, last_page: 1 });
  // Sidebar, shared access store and Cases all consume the real API identity cache.
  const sidebar = e.API.getStaffIdentity(), access = e.stores.clinicAccess.init(), opening = e.ui.activate();
  await settle();
  assert.ok(!e.ui.accessError, 'Pending identity is not an access error');
  assert.deepEqual(e.calls.map(c => c.endpoint), ['/me'], 'Identity consumers share one in-flight request');
  pending.resolve(identity(true));
  await Promise.all([sidebar, access, opening]);
  assert.ok(e.ui.activeCasesLoaded);
  assert.ok(!e.ui.accessError);
  assert.deepEqual(e.calls.map(c => c.endpoint), ['/me', '/admin/clinic-cases'], 'Startup loads only visible Cases data');
  await e.ui.activate(); await e.API.getStaffIdentity();
  assert.equal(e.calls.length, 2, 'Reactivation and cached identity do not refetch');
  e.intersect(); await settle();
  assert.deepEqual(e.calls.map(c => c.endpoint), ['/me', '/admin/clinic-cases', '/admin/clinic-records?page=1']);
  assert.ok(e.ui.recordsLoaded, 'Patient Records load when reached');
  e.ui.destroy();
}
async function accessAndSectionFailures() {
  for (const kind of ['denied', 'identity', 'cases', 'records']) {
    const e = clinic(), pending = deferred();
    e.respond(endpoint => {
      if (endpoint === '/me') return pending.promise;
      if (endpoint === '/admin/clinic-cases' && kind === 'cases') throw Error('Cases unavailable');
      if (endpoint.includes('clinic-records') && kind === 'records') throw Error('Records unavailable');
      return { cases: [] };
    });
    const opening = e.ui.activate(); await settle();
    assert.ok(!e.ui.accessError, 'Verification has no error before its result');
    pending.resolve(kind === 'identity' ? { ok: false, status: 503, json: async () => ({ message: 'Identity unavailable' }) } : identity(kind !== 'denied'));
    await opening;
    if (['denied', 'identity'].includes(kind)) {
      assert.ok(e.ui.accessError, 'A genuine access/identity failure populates the alert');
      assert.deepEqual(e.calls.map(c => c.endpoint), ['/me'], 'Access failure cannot fetch Clinic data');
    } else {
      if (kind === 'records') { e.intersect(); await settle(); }
      assert.ok(!e.ui.accessError, 'Data errors must not become access errors');
      assert.ok(kind === 'cases' ? e.ui.activeCasesError : e.ui.recordsError);
    }
    e.ui.destroy();
  }
}
(async () => {
  await sharedIdentityAndLazyData();
  await accessAndSectionFailures();
  console.log('Clinic shared identity, startup request budget, lazy records and access/section failure isolation passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
