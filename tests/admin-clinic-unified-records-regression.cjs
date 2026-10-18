const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return {promise, resolve};
};

function setup(access = true) {
  const records = deferred(), cases = deferred(), events = [], timers = [];
  const calls = {records: [], cases: 0, confined: 0};
  const permissions = {access, clinical: true, customers: true};
  const context = {
    URL, URLSearchParams, console,
    setTimeout(callback) { timers.push(callback); return timers.length; },
    clearTimeout(id) { timers[id - 1] = null; },
    CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
    document: {addEventListener() {}},
    location: {search: '', href: 'http://localhost/pages/admin/clinic.html#cases', hash: '#cases'},
    history: {replaceState() {}}, scrollTo() {}, addEventListener() {},
    dispatchEvent(event) { events.push(event); },
    API: {
      getAdminToken: () => 'test-token', getUserRole: () => 'staff',
      getStaffIdentity: async () => ({user: {clinic_permissions: permissions}}),
      getActiveClinicCases() { calls.cases++; return cases.promise; },
      getClinicRecords(page) { calls.records.push(page); return records.promise; },
      getClinicConfinedCases: async () => {calls.confined++; return {data: [], total: 0, current_page: 1, last_page: 1};},
      searchWalkInCustomers: async () => ({customers: [{id: 2, fullName: 'Search customer', pets: []}]}),
    },
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic.js'), context);
  vm.runInContext(read('scripts/components/admin-clinic-shell.js'), context);
  const state = context.adminClinicCasesSection();
  state.$store = {clinicAccess: {permissions}};
  state.$nextTick = callback => callback();
  return {context, state, records, cases, calls, events, timers};
}

(async () => {
  const app = setup();
  await app.state.activate();
  assert.equal(app.calls.cases, 1);
  assert.deepEqual(app.calls.records, [1]);
  assert.equal(app.state.searching, true, 'Records have their own loading state');
  app.cases.resolve({cases: [{id: 1, status: 'in_consultation', pet: {name: 'Milo'}}, {id: 2, status: 'for_payment'}],
    arrivals: [{id: 3, status: 'waiting_to_arrive', pet: {name: 'Mochi'}}]});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.state.activeCasesLoading, false, 'Cases finish while Records remain pending');
  assert.equal(app.state.visibleActiveCases[0].id, 1);
  assert.equal(app.state.expectedCount, 1);
  const loadedCases = app.events.find(event => event.type === 'clinic-cases-loaded').detail;
  assert.deepEqual(Array.from(loadedCases, item => item.id), [1, 2], 'Clinical consumers receive no pending arrivals');
  app.state.openCase(app.state.visibleActiveCases[0]);
  assert.equal(app.events.at(-1).type, 'clinic-open-modal', 'A case opens before Records resolve');
  app.state.setTab('expected');
  assert.deepEqual(Array.from(app.state.caseRows, item => item.id), [3]);
  assert.equal(app.state.activeCount, 1);
  app.state.setTab('for-payment');
  assert.equal(app.state.visibleActiveCases[0].id, 2);
  app.state.setTab('confined');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.calls.confined, 1);
  app.state.setTab('active-cases');
  assert.equal(app.state.searching, true, 'Switching filters does not wait for Records');
  await app.state.activate();
  assert.deepEqual(app.calls.records, [1], 'Returning from Services reuses loaded/in-flight data');

  app.state.searchQuery = 'Search';
  await app.state.onSearchInput();
  await app.timers.at(-1)();
  assert.equal(app.state.searchRows[0].owner.fullName, 'Search customer');
  app.records.resolve({rows: [{owner: {fullName: 'Stale customer'}, pets: []}], has_more: true});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.state.searchRows[0].owner.fullName, 'Search customer', 'A slow initial load cannot replace a newer search');

  app.context.API.getClinicRecords = async page => ({rows: [{owner: {id: page}, pets: []}], has_more: page === 1});
  await app.state.clearSearch();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.state.searchRows.length, 1);
  assert.equal(app.state.hasMoreRecords, true);
  await app.state.loadAllRecords(true);
  assert.equal(app.state.searchRows.length, 2, 'Load more appends customers');
  assert.equal(app.state.recordsPage, 2);
  assert.equal(app.state.hasMoreRecords, false);
  app.context.API.getClinicRecords = async () => ({rows: [], has_more: false});
  await app.state.loadAllRecords();
  assert.equal(app.state.noResults, true, 'The existing empty state remains available');

  const denied = setup(false);
  await denied.state.activate();
  assert.equal(denied.calls.cases, 0);
  assert.equal(denied.calls.records.length, 0, 'Clinic access gates both data requests');
  assert.match(denied.state.accessError, /does not have access/);

  const pending = setup();
  await pending.state.activate();
  pending.state.searchQuery = 'x';
  await pending.state.onSearchInput();
  pending.records.resolve({rows: [{owner: {id: 1}, pets: []}]});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(pending.state.searching, false, 'A short query cancels the initial loading state');
  assert.equal(pending.state.searchRows.length, 0);

  console.log('Unified Clinic Cases independent loading, search, pagination and access regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
