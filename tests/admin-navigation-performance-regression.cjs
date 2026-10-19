const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
const read = p => fs.readFileSync(p, 'utf8');
async function customersStartupAndSearchRace() {
  const e = environment();
  let imports = 0;
  vm.runInContext(read('scripts/components/admin-customers.js'), e.c, { importModuleDynamically: async () => { imports++; throw Error('Editor should be on demand'); } });
  const ui = e.c.adminCustomers();
  ui.initializePetFormFields = async () => { throw Error('Directory must not initialize the pet form'); };
  e.c.window.location.search = '';
  await ui.init(); assert.equal(ui.customersLoaded, true); assert.equal(imports, 0);
  const retained = e.page();
  vm.runInContext(read('scripts/components/admin-customers.js'), retained.c);
  const next = retained.c.adminCustomers(); const wait = deferred(); e.advance(31000); e.respond(() => wait.promise);
  const p = next.loadCustomers(); assert.equal(next.customersLoaded, true); assert.equal(next.customers[0].id, 1);
  wait.resolve({ customers: [{ id: 2 }] }); await p; assert.equal(next.customers[0].id, 2);
  const old = deferred(); e.respond(() => old.promise); next.searchQuery = 'old'; const a = next.loadCustomers();
  e.respond(() => ({ customers: [{ id: 3 }] })); next.searchQuery = 'new'; await next.loadCustomers();
  old.resolve({ customers: [{ id: 4 }] }); await a; assert.equal(next.customers[0].id, 3);
}
async function dashboardDoesNotWaitForClock() {
  const e = environment(), clock = deferred();
  e.c.window.AppClock = { load: () => clock.promise, todayKey: () => '2026-10-19' };
  e.c.Intl = Intl;
  vm.runInContext(read('scripts/components/admin-dashboard.js'), e.c);
  const ui = e.c.adminDashboard(); ui.pageMode = 'dashboard';
  for (const method of ['registerBridge', 'loadConfig', 'startContentPolling', 'dispatchDashboardEvent']) ui[method] = () => {};
  const p = ui.init(); await new Promise(r => setImmediate(r));
  assert.ok(e.calls.some(c => c.endpoint === '/admin/dashboard/summary'), 'Summary starts without an unresolved clock');
  assert.equal(e.calls.some(c => c.endpoint.startsWith('/admin/bookings')), false);
  clock.resolve(null); await p;
}
async function customerDetailsFormatWeightWithoutPetEditor() {
  const e = environment(), imports = [];
  vm.runInContext(read('scripts/components/admin-customers.js'), e.c, { importModuleDynamically: async specifier => {
    imports.push(specifier);
    return import(require('node:url').pathToFileURL(require('node:path').resolve('scripts/components', specifier.split('?')[0])).href);
  }});
  const ui = e.c.adminCustomers(); ui.setCustomerDetailScrollLock = () => {};
  e.c.API.getCustomerDetails = async () => ({customer: {id: 1, pets: [{weight: '25.000000000000'}, {weight: '4.999000000000'}]}});
  await ui.openCustomerDetails({id: 1});
  assert.equal(ui.formatWeight(ui.detailModal.customer.pets[0].weight), '25 kg');
  assert.equal(ui.formatWeight(ui.detailModal.customer.pets[1].weight), '4.999 kg');
  assert.equal(ui.formatWeight(null), 'Not provided');
  assert.ok(!imports.some(s => /pet-weight-size|breed-|booking-draft/.test(s)), 'Viewing a customer is independent of pet editing');
}
async function groomingWaitsForVerifiedTestClock() {
  const e = environment(), clock = deferred();
  e.c.Intl = Intl;
  e.respond(endpoint => endpoint === '/system/clock' ? clock.promise : {});
  vm.runInContext(read('scripts/services/app-clock.js'), e.c);
  e.c.window.AppClock = e.c.AppClock;
  vm.runInContext(read('scripts/components/admin-dashboard.js'), e.c);
  const ui = e.c.adminDashboard(); ui.pageMode = 'grooming';
  ui.config.includeFutureAppointments = true;
  for (const method of ['registerBridge', 'loadConfig', 'startContentPolling', 'dispatchDashboardEvent']) ui[method] = () => {};
  ui.bootstrapDashboard = ui.restoreGroomingOnlinePayment = async () => {};
  const p = ui.init(); await new Promise(r => setImmediate(r));
  assert.equal(e.calls.some(c => c.endpoint.startsWith('/admin/bookings')), false, 'Date-dependent board waits for the server clock');
  clock.resolve({test_clock_active: true, now: '2099-05-10T10:00:00+08:00', timezone: 'Asia/Manila'});
  await p;
  assert.equal(ui.selectedDate, '2099-05-10');
  assert.ok(e.calls.some(c => c.endpoint === '/admin/bookings?date=2099-05-10&include_future=1&view=board'));
  assert.equal(e.c.AppClock.isTestClockActive(), true);
  e.respond(() => ({test_clock_active: false, now: '2026-10-19T10:00:00+08:00'}));
  await e.c.AppClock.load({force: true});
  assert.equal(e.c.AppClock.isTestClockActive(), false, 'A forced clock verification can leave test mode');
}
async function dashboardDoesNotRequestIntakeStatus() {
  const e = environment(), summary = deferred();
  e.respond(endpoint => endpoint === '/admin/dashboard/summary' ? summary.promise : {});
  e.c.Intl = Intl;
  vm.runInContext(read('scripts/components/admin-dashboard.js'), e.c);
  const ui = e.c.adminDashboard(); ui.pageMode = 'dashboard';
  for (const method of ['registerBridge', 'loadConfig', 'startContentPolling', 'dispatchDashboardEvent']) ui[method] = () => {};
  const p = ui.init(); await new Promise(r => setImmediate(r));
  assert.equal(e.calls.some(c => c.endpoint === '/clinic/status'), false, 'Secondary status cannot sit ahead of primary Dashboard data');
  summary.resolve({}); await p; await new Promise(r => setImmediate(r));
  assert.equal(e.calls.filter(c => c.endpoint === '/clinic/status').length, 0, 'Dashboard does not load obsolete intake controls');
}
async function clinicDefersRecordsAndKeepsConfinedOnDemand() {
  const e = environment(); let observe;
  e.c.window.location.search = '';
  e.c.setInterval = () => 1; e.c.clearInterval = () => {};
  e.c.window.removeEventListener = () => {}; e.c.document.removeEventListener = () => {};
  e.c.IntersectionObserver = class { constructor(fn) { observe = fn; } observe() {} disconnect() {} };
  e.c.window.addEventListener = () => {};
  e.c.CustomEvent = class {};
  vm.runInContext(read('scripts/components/admin-clinic.js'), e.c);
  const ui = e.c.adminClinicPage(); ui.$root = { querySelector: () => ({}) };
  ui.$nextTick = fn => fn(); ui.restoreOnlinePayment = async () => {};
  e.c.API.getStaffIdentity = async () => ({ user: { clinic_permissions: { access: true } } });
  await ui.init();
  assert.ok(ui.activeCasesLoaded);
  assert.equal(e.calls.some(c => c.endpoint.includes('clinic-records')), false, 'Offscreen records do not compete with active cases');
  assert.equal(e.calls.some(c => c.endpoint.includes('clinic-confinements')), false);
  observe([{ isIntersecting: true }]); await new Promise(r => setImmediate(r));
  assert.ok(e.calls.some(c => c.endpoint === '/admin/clinic-records?page=1')); assert.ok(ui.recordsLoaded);
  ui.destroy();
}
async function clinicListRefreshPreservesWorkspaceDetails() {
  const e = environment(), listeners = new Map();
  e.c.window.addEventListener = (name, fn) => listeners.set(name, fn);
  vm.runInContext(read('scripts/components/admin-clinic.js'), e.c);
  vm.runInContext(read('scripts/components/admin-vaccinations.js'), e.c);
  const ui = e.c.adminClinicVaccinations(); ui.init();
  ui.appointment = {id: 1, status: 'in_consultation', weight_kg: '24.00', confinement: {id: 9}, paid: false};
  listeners.get('clinic-cases-loaded')({detail: [{id: 1, status: 'for_payment', paid: false, list_only: true}]});
  assert.equal(ui.appointment.status, 'for_payment');
  assert.equal(ui.appointment.weight_kg, '24.00', 'A list refresh cannot erase medical workspace weight');
  assert.equal(ui.appointment.confinement.id, 9);
}
(async () => { for (const test of [customersStartupAndSearchRace, customerDetailsFormatWeightWithoutPetEditor, dashboardDoesNotWaitForClock, dashboardDoesNotRequestIntakeStatus, groomingWaitsForVerifiedTestClock, clinicDefersRecordsAndKeepsConfinedOnDemand, clinicListRefreshPreservesWorkspaceDetails]) {
  await test(); console.log(test.name+' passed');
} })().catch(error => { console.error(error); process.exitCode = 1; });
