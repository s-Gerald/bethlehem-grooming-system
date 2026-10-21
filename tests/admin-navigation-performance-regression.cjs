const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { registerHooks } = require('node:module');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
const read = p => fs.readFileSync(p, 'utf8');
async function withCustomersComponent(e, test) {
  // Observe real imports while using the native loader, which needs no VM flag.
  const imports = [];
  const hooks = registerHooks({ resolve(specifier, context, nextResolve) {
    imports.push(specifier);
    return nextResolve(specifier, context);
  } });
  try {
    vm.runInContext(read('scripts/components/admin-customers.js'), e.c, {
      filename: path.resolve('scripts/components/admin-customers.js'),
      importModuleDynamically: vm.constants.USE_MAIN_CONTEXT_DEFAULT_LOADER,
    });
    await test(e.c.adminCustomers(), imports);
  } finally { hooks.deregister(); }
}
async function customersStartupAndSearchRace() {
  const e = environment();
  await withCustomersComponent(e, async (ui, imports) => {
    ui.initializePetFormFields = async () => { throw Error('Directory must not initialize the pet form'); };
    e.c.window.location.search = '';
    await ui.init(); assert.equal(ui.customersLoaded, true); assert.deepEqual(imports, []);
    assert.deepEqual(e.calls.map(call => call.endpoint), ['/admin/customers?status=active'], 'Startup loads only the directory');
    const retained = e.page();
    vm.runInContext(read('scripts/components/admin-customers.js'), retained.c);
    const next = retained.c.adminCustomers(); const wait = deferred(); e.advance(31000); e.respond(() => wait.promise);
    const p = next.loadCustomers(); assert.equal(next.customersLoaded, true); assert.equal(next.customers[0].id, 1);
    wait.resolve({ customers: [{ id: 2 }] }); await p; assert.equal(next.customers[0].id, 2);
    const old = deferred(); e.respond(() => old.promise); next.searchQuery = 'old'; const a = next.loadCustomers();
    e.respond(() => ({ customers: [{ id: 3 }] })); next.searchQuery = 'new'; await next.loadCustomers();
    old.resolve({ customers: [{ id: 4 }] }); await a; assert.equal(next.customers[0].id, 3);
  });
}
async function dashboardLoadsSummaryDirectly() {
  const e = environment();
  e.c.Intl = Intl;
  vm.runInContext(read('scripts/components/admin-dashboard.js'), e.c);
  const ui = e.c.adminDashboard(); ui.pageMode = 'dashboard';
  for (const method of ['registerBridge', 'loadConfig', 'startContentPolling', 'dispatchDashboardEvent']) ui[method] = () => {};
  const p = ui.init(); await new Promise(r => setImmediate(r));
  assert.ok(e.calls.some(c => c.endpoint === '/admin/dashboard/summary'), 'Summary starts directly on entry');
  assert.equal(e.calls.some(c => c.endpoint.startsWith('/admin/bookings')), false);
  assert.equal(e.calls.some(c => c.endpoint.startsWith('/admin/notifications')), false, 'Dashboard content does not own notification loading');
  await p;
}
async function customerDetailsFormatWeightWithoutPetEditor() {
  const e = environment();
  await withCustomersComponent(e, async (ui, imports) => {
    ui.setCustomerDetailScrollLock = () => {};
    assert.deepEqual(imports, [], 'Customer details formatting stays lazy');
    e.respond(() => ({customer: {id: 1, pets: [{weight: '25.000000000000'}, {weight: '4.999000000000'}]}}));
    await ui.openCustomerDetails({id: 1});
    assert.equal(ui.detailModal.error, '', 'Customer details and their lazy formatter must load successfully');
    assert.equal(ui.detailModal.customer.pets.length, 2);
    assert.equal(ui.formatWeight(ui.detailModal.customer.pets[0].weight), '25 kg');
    assert.equal(ui.formatWeight(ui.detailModal.customer.pets[1].weight), '4.999 kg');
    assert.equal(ui.formatWeight(null), 'Not provided');
    assert.ok(!imports.some(s => /pet-weight-size|breed-|booking-draft/.test(s)), 'Viewing a customer is independent of pet editing');
    assert.deepEqual(e.calls.map(call => call.endpoint), ['/admin/customers/1'], 'Opening details makes one on-demand request');
  });
}
async function groomingUsesManilaDateDirectly() {
  const e = environment();
  e.c.Intl = Intl;
  e.c.Date = class extends Date {
    constructor(...args) { super(...(args.length ? args : ['2026-12-31T16:00:00Z'])); }
  };
  e.respond(() => ({}));
  vm.runInContext(read('scripts/components/admin-dashboard.js'), e.c);
  const ui = e.c.adminDashboard(); ui.pageMode = 'grooming';
  ui.config.includeFutureAppointments = true;
  for (const method of ['registerBridge', 'loadConfig', 'startContentPolling', 'dispatchDashboardEvent']) ui[method] = () => {};
  ui.bootstrapDashboard = ui.restoreGroomingOnlinePayment = async () => {};
  await ui.init();
  assert.equal(ui.selectedDate, '2027-01-01');
  assert.equal(ui.todayDate, '2027-01-01');
  assert.equal(ui.maxDate, '2027-01-04');
  assert.match(ui.formatDateGroupLabel('2027-01-01'), /^Today/);
  assert.match(ui.formatDateGroupLabel('2027-01-02'), /^Tomorrow/);
  assert.deepEqual(e.calls.map(c => c.endpoint), ['/admin/bookings?date=2027-01-01&include_future=1&view=board'],
    'Grooming needs only its board request on entry');
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
async function scopedListsReuseRetainedData() {
  const e = environment();
  const response = {data: [{item_id: 1}], page: 1, last_page: 1, total: 1, priceLimits: {}, archived: []};
  e.respond(() => response);
  const load = (page, file, factory) => {vm.runInContext(read('scripts/components/'+file), page.c); return page.c[factory]();};
  let services = load(e, 'admin-grooming-services.js', 'adminGroomingServices');
  await services.activate();
  services = load(e.page(), 'admin-grooming-services.js', 'adminGroomingServices'); await services.activate();
  assert.equal(e.calls.length,1,'Fresh Services survives HTML navigation');
  assert.equal(e.calls.some(c=>c.endpoint.includes('clinic-services')),false);
  e.advance(61000); const held=deferred(); e.respond(()=>held.promise);
  const retained=load(e.page(),'admin-grooming-services.js','adminGroomingServices');
  let presented=false; retained._onPresentation=()=>{presented=true;};
  const pending=retained.activate();
  assert.ok(retained.loaded && presented,'Services releases retained content before network completes');
  held.resolve(response); await pending;
  e.respond(()=>response);
  for (const [file,factory] of [['admin-inventory-dashboard.js','adminInventoryDashboard'],['admin-inventory-items.js','adminInventoryItems'],['admin-inventory-transactions.js','adminInventoryTransactions']]) {
    const page=e.page(); vm.runInContext(read('scripts/services/inventory-service.js'),page.c);
    page.c.ProductForm={empty:()=>({})};
    const ui=load(page,file,factory); const before=e.calls.length; await ui.load();
    assert.ok(ui.loaded); assert.equal(e.calls.length,before+1,'Section needs exactly its own list');
    const next=e.page(); vm.runInContext(read('scripts/services/inventory-service.js'),next.c); next.c.ProductForm={empty:()=>({})};
    const reused=load(next,file,factory); await reused.load(); assert.ok(reused.loaded);
    assert.equal(e.calls.length,before+1,'Inventory list survives HTML navigation');
    e.advance(6000); const refresh=deferred(); e.respond(()=>refresh.promise);
    const stale=e.page(); vm.runInContext(read('scripts/services/inventory-service.js'),stale.c); stale.c.ProductForm={empty:()=>({})};
    const visible=load(stale,file,factory); let shown=false; visible._onPresentation=()=>{shown=true;};
    const p=visible.load(); assert.ok(visible.loaded && shown,'Retained section content is ready before revalidation');
    refresh.resolve(response); await p; e.respond(()=>response);
  }
}
async function servicesPrimaryDoesNotWaitForClinicPermissions() {
  const e=environment(), permission=deferred(); e.c.API.getUserRole=()=> 'admin';
  e.c.window.location.hash='#grooming'; e.c.window.removeEventListener=()=>{};
  e.c.history={replaceState(){}};
  vm.runInContext(read('scripts/components/admin-services.js'),e.c);
  const shell=e.c.adminServices(); shell.$store={clinicAccess:{init:()=>permission.promise,permissions:{services_view:false}}};
  let mounted=''; const state={loaded:true,activate:async()=>{}};
  shell.$refs={grooming:{},clinic:{}}; shell.mount=async tab=>{mounted=tab;return {};}; e.c.Alpine={$data:()=>state};
  const pending=shell.init(); await new Promise(setImmediate);
  assert.equal(mounted,'grooming','Unresolved Clinic permissions cannot delay Grooming');
  permission.resolve(); await pending;
  e.c.window.location.hash='#clinic'; await shell.showTab();
  assert.equal(mounted,'grooming','Denied Clinic access never mounts its functionality'); assert.ok(shell.error);
}
async function historyActiveTypeAndSearchRace() {
  const e=environment(); e.c.API.getStaffIdentity=async()=>({user:{role:'admin'}});
  e.c.window.history={state:null}; e.c.window.removeEventListener=()=>{};
  vm.runInContext(read('scripts/components/admin-archive.js'),e.c);
  const ui=e.c.adminArchive(); ui.selectAvailableYear=()=>{}; ui.closeDetails=()=>{}; ui.closeClinicRecord=()=>{};
  e.respond(()=>({archived:[],total:0})); await ui.init();
  assert.deepEqual(e.calls.map(c=>c.endpoint),['/admin/bookings/archived'],'History fetches no inactive type or details');
  await ui.selectArchiveType('clinic'); await ui.selectArchiveType('grooming');
  assert.equal(e.calls.length,2,'History tabs share API memory retention');
  const old=deferred(); ui.searchQuery='old'; e.respond(()=>old.promise); const pending=ui.loadArchive();
  ui.searchQuery='new'; old.resolve({archived:[{id:99}]}); await pending;
  assert.equal(ui.archivedList.length,0,'A query changed during markup debounce cannot accept the old response');
}
async function staffServicesWaitForAuthorizedActiveTab() {
  const e=environment(), permission=deferred(); e.c.API.getUserRole=()=> 'staff';
  e.c.window.location.hash=''; e.c.history={replaceState(){}};
  vm.runInContext(read('scripts/components/admin-services.js'),e.c);
  const shell=e.c.adminServices(); shell.$store={clinicAccess:{init:()=>permission.promise,permissions:{services_view:true}}};
  let mounted=''; const state={loaded:true,activate:async()=>{}};
  shell.$refs={grooming:{},clinic:{}}; shell.mount=async tab=>{mounted=tab;return {};}; e.c.Alpine={$data:()=>state};
  const pending=shell.init(); await new Promise(setImmediate);
  assert.equal(mounted,'','Staff Clinic functionality waits for permission verification');
  permission.resolve(); await pending; assert.equal(mounted,'clinic');
}
async function retainedDataWaitsForNavigationAuthorization() {
  const e = environment();
  await e.API.getCustomers();
  const verification = deferred();
  e.respond(endpoint => endpoint === '/session' ? verification.promise : {customers: []});
  const next = e.page();
  next.c.window.location.pathname = '/pages/admin/clients.html';
  next.c.document.documentElement = {setAttribute() {}, removeAttribute() {}};
  next.c.document.querySelector = selector => selector === '[data-admin-shell]' ? {} : null;
  next.c.document.createElement = () => ({});
  next.c.setTimeout = () => 1;
  next.c.clearTimeout = () => {};
  next.API.startSessionInactivityManager();
  let presented = false;
  const pending = next.API.loadAdminData('/admin/customers?status=active', () => next.API.getCustomers(), () => { presented = true; });
  assert.equal(presented, false, 'A navigation cache cannot authorize protected content');
  await assert.rejects(next.API.adminStopToday(), error => error.status === 503);
  next.events.get('focus')();
  assert.equal(e.calls.filter(call => call.endpoint === '/session').length, 1, 'Startup and focus share verification');
  verification.resolve({locked: false, role: 'admin', user_id: 7, sidebar_revision: 'navigation-test',
    server_now: '1970-01-01T00:16:40Z', idle_at: '1970-01-01T00:46:40Z', expires_at: '1970-01-01T12:16:40Z'});
  await pending;
  assert.equal(presented, true, 'Verified navigation immediately reuses retained data');
  assert.equal(e.calls.filter(call => call.endpoint === '/admin/customers?status=active').length, 1, 'Verification does not discard or refetch a fresh directory');
}
(async () => { for (const test of [retainedDataWaitsForNavigationAuthorization, scopedListsReuseRetainedData, servicesPrimaryDoesNotWaitForClinicPermissions, staffServicesWaitForAuthorizedActiveTab, historyActiveTypeAndSearchRace, customersStartupAndSearchRace, customerDetailsFormatWeightWithoutPetEditor, dashboardLoadsSummaryDirectly, dashboardDoesNotRequestIntakeStatus, groomingUsesManilaDateDirectly, clinicDefersRecordsAndKeepsConfinedOnDemand, clinicListRefreshPreservesWorkspaceDetails]) {
  await test(); console.log(test.name+' passed');
} })().catch(error => { console.error(error); process.exitCode = 1; });
