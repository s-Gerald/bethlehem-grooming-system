const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
function component(file, factory, API = {}, extra = {}) {
  let source = fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8');
  if (factory === 'adminCustomers') source = source.slice(source.indexOf('function adminCustomers()'));
  API.loadAdminData ??= (key, load, render) => load().then(render);
  const context = vm.createContext({ API, console, Date, Intl, URL, URLSearchParams, setTimeout, clearTimeout,
    window: {}, ...extra });
  if (file === 'admin-clinic.js') vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
  vm.runInContext(source, context);
  const ui = context[factory](); ui.$nextTick = fn => fn(); ui.refreshIcons = () => {};
  return { ui, context };
}
async function customerRefresh() {
  let result = { customers: [{ id: 7, first_name: 'Local' }], total: 1 };
  const { ui, context } = component('admin-customers.js', 'adminCustomers', { getCustomers: async () => result });
  await ui.loadCustomers();
  const next = deferred(); context.API.getCustomers = () => next.promise;
  const refresh = ui.loadCustomers();
  assert.equal(ui.customersLoaded, true, 'Loaded customers remain available while refreshing');
  assert.equal(ui.customers[0].id, 7);
  next.resolve({ customers: [], total: 0 }); await refresh;
  assert.equal(ui.customersLoaded, true, 'A loaded empty list is data');
  assert.equal(ui.totalCount, 0);
  context.API.getCustomers = async () => { throw new Error('offline'); };
  await ui.loadCustomers(); assert.equal(ui.customersLoaded, true); assert.equal(ui.totalCount, 0);
}
async function reportRefresh() {
  const report = { success: true, overview: {}, period: {}, performance: [], services: [], customers: {}, outcomes: {} };
  const { ui, context } = component('admin-reports.js', 'adminReports', { getGroomingPerformanceReport: async () => report });
  await ui.loadReport();
  const next = deferred(); context.API.getGroomingPerformanceReport = () => next.promise;
  const refresh = ui.loadReport();
  assert.equal(ui.report, report, 'Refreshing a report retains its loaded content');
  next.resolve(report); await refresh;
  context.API.getGroomingPerformanceReport = async () => { throw new Error('offline'); };
  await ui.loadReport(); assert.equal(ui.report, report, 'A refresh error retains the previous report');
  ui.period = 'today'; const changed = ui.loadReport();
  assert.equal(ui.report, null, 'A different reporting period must not display old-period data'); await changed;
}
async function sidebarReadiness() {
  let identityCalls = 0;
  const API = { getUserRole: () => 'admin', enforceAdminPageAccess: () => true,
    getMe: async () => ({}), getStaffIdentity: async () => { identityCalls++; return { user: { role: 'admin', clinic_permissions: {} } }; },
    getAdminGroomingActivity: async () => ({}), getAdminToken: () => 'local', getAdminBookings() {} };
  const { ui } = component('admin-sidebar.js', 'adminSidebar', API, {
    window: { API, location: new URL('https://example.test/pages/admin/dashboard.html'), addEventListener() {}, removeEventListener() {}, dispatchEvent() {} },
    document: { currentScript: null, querySelector: () => null, querySelectorAll: () => [], addEventListener() {}, removeEventListener() {} },
    setInterval: () => 1, clearInterval() {}, CustomEvent: class {},
  });
  const start = ui.init();
  assert.equal(identityCalls, 1, 'Sidebar starts authorization immediately');
  await start;
  assert.equal(ui.sidebarReady, true, 'Navigation is ready only after authenticated identity'); ui.destroy();
}
async function historyTabs() {
  const e = require('./admin-data-cache-regression.cjs').environment();
  e.respond(() => ({archived: [], total: 0}));
  const { ui } = component('admin-archive.js', 'adminArchive', e.API);
  ui._archiveIdentityLoaded = true;
  ui.closeDetails = () => {}; ui.closeClinicRecord = () => {}; ui.selectAvailableYear = () => {};
  await ui.loadArchive(); await ui.selectArchiveType('clinic'); await ui.selectArchiveType('grooming');
  assert.equal(e.calls.length, 2, 'Previously loaded History tabs reuse the shared API cache');
}
async function petProfileReadiness() {
  const { ui, context } = component('admin-customers.js', 'adminCustomers', {
    getAdminPetProfile: async () => ({ grooming_records: [], medical_records: [], vaccinations: [] }),
  });
  ui.petModal.open = true; ui.petModal.pet = { id: 1 };
  assert.equal(ui.petModal.profileLoaded, false);
  await ui.loadPetProfile(); assert.equal(ui.petModal.profileLoaded, true);
  context.API.getAdminPetProfile = async () => { throw new Error('offline'); };
  await ui.loadPetProfile(); assert.equal(ui.petModal.profileLoaded, true);
}
async function notificationReadiness() {
  const { ui, context } = component('admin-notifications.js', 'adminNotifications', {
    getNotifications: async () => ({ notifications: [], unread_count: 0 }),
  }, {AdminNotificationService: {load: filters => context.API.getNotifications(filters)}});
  assert.equal(ui.loaded, false);
  await ui.loadNotifications(true); assert.equal(ui.loaded, true);
  const next = deferred(); context.API.getNotifications = () => next.promise;
  const refresh = ui.loadNotifications(true); assert.equal(ui.loaded, true);
  next.resolve({ notifications: [], unread_count: 0 }); await refresh;
  ui.statusFilter = 'unread'; const changed = ui.loadNotifications(true);
  assert.equal(ui.loaded, false, 'Changed filters cannot present old notifications as matching results');
  await changed;
}
async function availabilityReadiness() {
  const response = {availability: {}, capacity: {used: 0, max: 12, remaining: 12, percent: 0, is_full: false}, stopped_today: {clinic: false, grooming: false}, groomers_on_duty: 0, pet_groomers: [], blocked_dates: []};
  const {ui, context} = component('admin-availability.js', 'adminAvailability', {getAvailabilitySettings: async () => response});
  ui.identityReady = true;
  assert.equal(ui.availabilityLoaded, false);
  await ui.loadAvailability(); assert.equal(ui.availabilityLoaded, true);
  context.API.getAvailabilitySettings = async () => {throw new Error('offline');};
  await ui.loadAvailability(); assert.equal(ui.availabilityLoaded, true);
  assert.equal(ui.groomersOnDuty, 0, 'Confirmed zero remains data after failed refresh');
}
async function blockedDatesReadiness() {
  const response = {availability: {}, capacity: {used: 0, max: 12}, stopped_today: {clinic: false, grooming: false}, groomers_on_duty: 0, blocked_dates: [{id: 1}]};
  const {ui, context} = component('admin-availability.js', 'adminAvailability', {getAvailabilitySettings: async () => response});
  ui.identityReady = true;
  await ui.loadAvailability(); assert.equal(ui.blockedDates.length, 1);
  context.API.getAvailabilitySettings = async () => {throw new Error('offline');};
  await ui.loadAvailability(); assert.equal(ui.blockedDates.length, 1);
  assert.ok(ui.availabilityError);
}
async function clinicServiceQueries() {
  const response = { data: [], total: 0, current_page: 1, last_page: 1, categories: [] };
  const { ui, context } = component('admin-clinic-services.js', 'adminClinicServices', {
    adminRequest: async () => response,
  });
  await ui.load(); assert.equal(ui.loaded, true);
  const next = deferred(); context.API.adminRequest = () => next.promise;
  const refresh = ui.load(); assert.equal(ui.loaded, true);
  next.resolve(response); await refresh;
  const changedResponse = deferred(); context.API.adminRequest = () => changedResponse.promise;
  ui.status = 'inactive'; const changed = ui.load();
  assert.equal(ui.loaded, false, 'Changed Services filters show loading instead of old-filter rows');
  changedResponse.resolve(response); await changed;
}
async function historyIdentityRetry() {
  let fail = true, groomingCalls = 0, clinicCalls = 0;
  const { ui } = component('admin-archive.js', 'adminArchive', {
    getStaffIdentity: async () => { if (fail) throw new Error('offline'); return { user: { role: 'staff', staff_type: 'clinic' } }; },
    getArchivedBookings: async () => { groomingCalls++; return { archived: [] }; },
    getArchivedClinicAppointments: async () => { clinicCalls++; return { archived: [] }; },
  });
  ui.selectAvailableYear = () => {};
  await ui.loadArchive(); assert.equal(ui.loading, false); assert.ok(ui.errorMessage);
  fail = false; await ui.loadArchive();
  assert.equal(ui.historyLoaded, true); assert.equal(groomingCalls, 0); assert.equal(clinicCalls, 1);
}
async function clinicReadiness() {
  const { ui, context } = component('admin-clinic.js', 'adminClinicPage', {
    getActiveClinicCases: async () => ({ cases: [], arrivals: [], confined_count: 0 }),
  }, { window: { dispatchEvent() {} }, document: { addEventListener() {} }, CustomEvent: class {} });
  await ui.loadActiveCases(); assert.equal(ui.activeCasesLoaded, true);
  const next = deferred(); context.API.getActiveClinicCases = () => next.promise;
  const refresh = ui.loadActiveCases(); assert.equal(ui.activeCasesLoaded, true);
  next.resolve({ cases: [], arrivals: [], confined_count: 0 }); await refresh;
  assert.equal(ui.activeCasesLoaded, true, 'Empty Clinic results do not restart initial loading');
}
(async () => { for (const run of [customerRefresh, reportRefresh, sidebarReadiness, historyTabs, clinicReadiness, availabilityReadiness, blockedDatesReadiness, notificationReadiness, petProfileReadiness, historyIdentityRetry, clinicServiceQueries]) { await run(); console.log(run.name + ' passed'); } })()
  .catch(error => { console.error(error); process.exitCode = 1; });
