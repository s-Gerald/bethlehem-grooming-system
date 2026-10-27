const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = name => fs.readFileSync(path.join(__dirname, '..', 'scripts/components', name), 'utf8');

async function clinicalDefaults() {
  const requests = [];
  const identity = {user: {role: 'staff', staff_type: 'clinic', staff_subrole: 'veterinarian',
    staff_permissions: {clinic_workspace: true, availability_view: true, availability_manage: false}}};
  const context = {
    URL, URLSearchParams, Date, Intl, setTimeout, clearTimeout,
    window: {location: new URL('https://example.test/pages/admin/archive.html?pos=2'), history: {state: null}, addEventListener() {}, removeEventListener() {}},
    document: {currentScript: null, addEventListener() {}, removeEventListener() {}},
    API: {isClinicWorkspace: () => true, getCachedStaffIdentity: () => identity,
      getStaffIdentity: async () => identity,
      getArchivedClinicAppointments: async () => {requests.push('clinic-history'); return {archived: []};},
      getArchivedBookings: async () => {throw new Error('Grooming history must never load');},
      loadAdminData: async (_, load, render) => {const data = await load(); render(data); return data;},
      getTransactions: async params => {requests.push(params.transaction_type); return {transactions: [], total: 0};}},
  };
  context.window.API = context.API;
  vm.createContext(context);
  vm.runInContext(read('admin-archive.js'), context);
  vm.runInContext(read('admin-transactions.js'), context);
  vm.runInContext(read('admin-sidebar.js'), context);
  const sidebar = context.getAdminSidebarIdentityState(identity.user);
  assert.equal(sidebar.clinicOnly, true);
  assert.equal(sidebar.canViewServices, false);
  assert.equal(sidebar.canAccessGrooming, false);
  const history = context.adminArchive();
  assert.equal(history.archiveType, 'clinic', 'Cached veterinary identity selects clinic before the first paint');
  await history.init();
  await history.selectArchiveType('grooming');
  assert.equal(history.archiveType, 'clinic');
  assert.deepEqual(requests, ['clinic-history']);
  const transactions = context.adminTransactions();
  transactions.$nextTick = fn => fn();
  await transactions.init();
  assert.equal(transactions.transactionType, 'clinic_payment', 'POS deep links cannot select POS for a veterinarian');
  transactions.transactionType = 'all';
  await transactions.loadTransactions();
  transactions.clearFilters();
  await Promise.resolve();
  assert.equal(transactions.transactionType, 'clinic_payment');
  assert.ok(requests.filter(x => x !== 'clinic-history').every(x => x === 'clinic_payment'));
  vm.runInContext(read('admin-settings.js'), context);
  const settings = context.adminSettings();
  await settings.init();
  assert.equal(settings.isAdmin, false, 'Settings relies on validated identity');
  vm.runInContext(read('admin-availability.js'), context);
  context.API.getAvailabilitySettings = async () => ({availability: {}, capacity: {used: 0, max: 15}, stopped_today: {clinic: false, grooming: false}});
  const availability = context.adminAvailability(); await availability.init();
  assert.equal(availability.canManage, false);
  availability.editPetGroomer({id: 1, first_name: 'Staff'});
  assert.equal(availability.petGroomerEditor, null);
  await availability.savePetGroomer({first_name: 'Changed'}, 1);
  assert.equal(availability.groomersOnDutySaving, false);
}

async function readOnlyInventory() {
  const calls = [];
  const context = {URLSearchParams, Date, clearTimeout, setTimeout,
    window: {location: {hash: '#stock-out?item=1'}},
    API: {isClinicWorkspace: () => true, enforceAdminPageAccess: () => true},
    ProductForm: {empty: () => ({})}, InventoryAPI: {},
    history: {replaceState: (_, __, hash) => {context.window.location.hash = hash;}},
  };
  context.window.API = context.API;
  vm.createContext(context);
  for (const name of ['admin-inventory.js', 'admin-inventory-items.js']) vm.runInContext(read(name), context);
  const inventory = context.adminInventory();
  inventory.mountSection = async section => {calls.push(section); throw new Error('Stop after observing lazy load target');};
  inventory.$refs = {panels: {querySelector: () => null}};
  context.window.scrollTo = () => {};
  context.document = {};
  await inventory.showSection();
  assert.deepEqual(calls, ['overview'], 'Restricted sections never load markup or scripts');
  const items = context.adminInventoryItems();
  items.openAdd();
  assert.equal(items.modal.open, false);
  items.openEdit({item_id: 1});
  assert.equal(items.modal.open, false);
  items.details.item = {item_id: 1, is_active: true};
  items.stockDetails('stock-out');
  assert.equal(context.window.location.hash, '#overview');
  const markup = fs.readFileSync(path.join(__dirname, '../pages/admin/inventory/sections/products.html'), 'utf8');
  const detailCondition = markup.match(/<template x-if="([^"]+)">\s*<div class="space-y-6">\s*<section aria-labelledby="product-information-heading"/)[1];
  const shown = vm.runInNewContext(detailCondition, {clinicOnly: true, details: {item: {item_id: 1}, loading: false}});
  assert.ok(shown, 'Veterinarians can render clinical product information, stock and batch details');
  const actionCondition = markup.match(/<template x-if="([^"]+)">\s*<div class="flex flex-wrap gap-3">/)[1];
  for (const clinicOnly of [true, false]) {
    assert.equal(Boolean(vm.runInNewContext(actionCondition, {clinicOnly, details: {item: {item_id: 1}, loading: false}})), !clinicOnly,
      'Only inventory managers render product-detail mutation actions');
  }
}

(async () => {
  await clinicalDefaults();
  await readOnlyInventory();
  console.log('Veterinary clinical defaults and read-only inventory passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
