const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const classes = new Set();
const element = () => ({ isConnected: true, focus(options) { this.focusOptions = options; document.activeElement = this; }, querySelectorAll: () => [] });
const document = { activeElement: element(), addEventListener() {}, documentElement: { classList: { toggle(name, on) { on ? classes.add(name) : classes.delete(name); } } }, body: { classList: { toggle() {} } } };
let role = 'admin', calls = 0, resolveRequest;
const context = vm.createContext({ document, window: {}, console, API: {
  getUserRole: () => role,
  unarchiveCustomer: () => { calls++; return new Promise(resolve => { resolveRequest = resolve; }); },
  restoreUnregisteredCustomer: async () => { calls++; throw new Error('Restore failed'); },
} });
vm.runInContext(fs.readFileSync('scripts/utils/human-name.js', 'utf8'), context);
vm.runInContext(fs.readFileSync('scripts/components/admin-customers.js', 'utf8'), context);
const ui = context.adminCustomers();
ui.$nextTick = callback => callback();
ui.$refs = Object.fromEntries(['confirmDialog', 'confirmCancel', 'ownerDialog', 'petDialog', 'ownerActionsButton', 'ownerActionsMenu', 'petKeepEditing', 'petClose', 'nameFirst', 'nameLast'].map(name => [name, element()]));
ui.loadCustomers = async () => {};
const portal = { id: 1, fullName: 'Owner', isActive: true, recordType: 'registered' };
const manual = { ...portal, recordType: 'unregistered' };

(async () => {
  assert.equal(ui.customerStatusLabel(manual), 'Manual record');
  assert.equal(ui.customerStatusLabel(portal), 'Online account · Enabled');
  assert.equal(ui.customerStatusLabel({ ...portal, isActive: false }), 'Online account · Deactivated');
  assert.equal(ui.customerStatusLabel({ ...portal, isArchived: true }), 'Archived · Online account');
  assert.equal(ui.customerStatusLabel({ ...manual, isArchived: true }), 'Archived · Manual record');
  assert.equal(ui.categoryLabel('active'), 'Online Accounts');
  for (const [status, description] of Object.entries({
    active: 'Customers with an enabled online account.',
    unregistered: 'Customer records created by staff without an online account.',
    inactive: 'Online accounts with login access disabled.',
  })) {
    ui.statusFilter = status;
    assert.equal(ui.categoryDescription(), description);
  }
  assert.deepEqual(Array.from(ui.customerActions(portal), a => a.action), ['deactivate', 'archive']);
  assert.deepEqual(Array.from(ui.customerActions({ ...portal, isActive: false }), a => a.action), ['reactivate', 'archive']);
  role = 'staff';
  assert.equal(ui.customerActions(portal).length, 0);
  assert.deepEqual(Array.from(ui.customerActions(manual), a => a.action), ['archive_unregistered']);
  assert.deepEqual(Array.from(ui.customerActions({ ...manual, isArchived: true }), a => a.action), ['restore_unregistered']);
  role = 'customer';
  assert.equal(ui.customerActions(manual).length, 0);
  role = 'admin';

  const menuItems = [element(), element()];
  ui.$refs.ownerActionsMenu.querySelector = () => menuItems[0];
  ui.$refs.ownerActionsMenu.querySelectorAll = () => menuItems;
  ui.toggleOwnerActions();
  assert.equal(document.activeElement, menuItems[0]);
  let prevented = 0;
  const key = value => ({ key: value, preventDefault() { prevented++; }, stopPropagation() {} });
  ui.navigateOwnerActions(key('ArrowUp'));
  assert.equal(document.activeElement, menuItems[1], 'Menu wraps backwards');
  ui.navigateOwnerActions(key('Home'));
  assert.equal(document.activeElement, menuItems[0]);
  ui.handleCustomerEscape(key('Escape'));
  assert.equal(ui.ownerActionsOpen, false);
  assert.equal(document.activeElement, ui.$refs.ownerActionsButton);

  ui.confirmAction('deactivate', portal);
  assert.equal(ui.confirmModal.message, 'Disable online access and end existing sessions. You can reactivate the account later.');
  ui.closeConfirm();
  ui.confirmAction('reactivate', { ...portal, isActive: false });
  assert.equal(ui.confirmModal.message, 'Enable online access for this customer.');
  ui.closeConfirm();
  ui.confirmAction('archive_unregistered', manual);
  ui.closeConfirm();
  await ui.executeAction();
  assert.equal(calls, 0, 'Cancelled confirmation never sends a mutation');

  const archived = { ...portal, isArchived: true };
  ui.detailModal.open = true;
  ui.confirmAction('unarchive', archived);
  assert.equal(ui.confirmModal.title, 'Restore customer?');
  const pending = ui.executeAction();
  await ui.executeAction();
  ui.closeConfirm();
  assert.equal(ui.confirmModal.open, true, 'Cannot dismiss a running mutation');
  assert.equal(calls, 1, 'Repeated submissions send one request');
  resolveRequest({ message: 'Customer restored.' });
  await pending;
  assert.equal(ui.confirmModal.open, false);
  assert.equal(ui.detailModal.open, false);
  assert.equal(ui.successMessage, 'Customer restored.');

  ui.detailModal.open = true;
  ui.confirmAction('restore_unregistered', { ...manual, isArchived: true });
  assert.doesNotMatch(ui.confirmModal.message, /access/);
  await ui.executeAction();
  assert.equal(ui.confirmModal.open, true);
  assert.equal(ui.confirmModal.error, 'Restore failed');
  ui.closeConfirm();
  assert.equal(classes.has('customer-detail-modal-open'), true, 'Parent keeps scroll lock');
  assert.equal(document.activeElement, ui.$refs.ownerActionsButton);

  ui.petModal.open = true;
  ui.$refs.ownerBody = { scrollTop: 320 };
  ui.closeCustomerDetails();
  assert.equal(ui.detailModal.open, true, 'Nested pet blocks owner close');
  ui.petModal.editing = true;
  ui.closePetDetails();
  assert.equal(ui.petModal.open, true, 'Unsaved editing requires explicit discard');
  assert.equal(ui.petDiscardOpen, true);
  ui.keepPetEditing();
  assert.equal(ui.petDiscardOpen, false);
  const petTrigger = element();
  ui._petReturnFocus = petTrigger;
  ui.closePetDetails(true);
  assert.equal(ui.petModal.open, false);
  assert.equal(document.activeElement, petTrigger);
  assert.equal(petTrigger.focusOptions?.preventScroll, true, 'Returning focus must not scroll the owner to its pet trigger');
  assert.equal(ui.$refs.ownerBody.scrollTop, 320, 'Closing a nested pet preserves owner scroll');
  assert.equal(classes.has('customer-detail-modal-open'), true);
  const firstControl = element(), lastControl = element();
  for (const control of [firstControl, lastControl]) {
    control.tabIndex = 0;
    control.getClientRects = () => [1];
    control.closest = () => null;
  }
  ui.$refs.ownerDialog.querySelectorAll = () => [firstControl, lastControl];
  ui.$refs.ownerDialog.contains = target => [firstControl, lastControl].includes(target);
  lastControl.focus();
  ui.trapDialogFocus(key('Tab'));
  assert.equal(document.activeElement, firstControl, 'Tab stays inside the top dialog');
  ui.trapDialogFocus({ ...key('Tab'), shiftKey: true });
  assert.equal(document.activeElement, lastControl, 'Shift+Tab wraps within the dialog');
  ui.closeCustomerDetails();
  assert.equal(classes.has('customer-detail-modal-open'), false);

  const edit = element();
  const online = { ...portal, firstName: 'Jhon', lastName: 'Peña', nameVersion: 'old' };
  ui.customers = [online, manual];
  ui.detailModal = { open: true, customer: online };
  assert.equal(ui.canEditCustomerName, true);
  ui.openNameEditor(edit);
  assert.equal(document.activeElement, ui.$refs.nameFirst);
  assert.equal(ui.canSaveCustomerName, false);
  ui.updateNameField('first_name', 'Jhon2');
  await ui.saveCustomerName();
  assert.match(ui.nameModal.errors.first_name, /Numbers/);
  ui.updateNameField('first_name', ' john ');
  assert.equal(ui.nameModal.errors.first_name, '');
  let finish;
  context.API.correctCustomerName = (type, id, payload) => {
    assert.equal(type, 'registered'); assert.equal(id, online.id);
    assert.equal(payload.name_version, 'old'); assert.equal(payload.first_name, 'John');
    assert.equal(payload.last_name, undefined);
    return new Promise(resolve => { finish = resolve; });
  };
  const save = ui.saveCustomerName();
  ui.closeNameEditor();
  assert.equal(ui.nameModal.open, true);
  finish({ customer: { ...online, firstName: 'John', fullName: 'John Peña', nameVersion: 'new' } });
  await save;
  assert.equal(ui.detailModal.customer.fullName, 'John Peña');
  assert.equal(ui.customers[0].firstName, 'John');
  assert.equal(ui.customers[1].fullName, 'Owner', 'Same numeric ID on the manual record stays separate');
  assert.equal(document.activeElement, edit);

  ui.openNameEditor(edit);
  ui.updateNameField('last_name', 'Santos');
  context.API.correctCustomerName = async () => { throw { status: 409, message: 'Name changed.', data: {
    customer: { ...online, firstName: 'Juan', fullName: 'Juan Peña', nameVersion: 'latest' },
  } }; };
  await ui.saveCustomerName();
  assert.equal(ui.nameModal.open, true);
  assert.equal(ui.nameModal.form.last_name, 'Santos');
  assert.equal(ui.canSaveCustomerName, false);
  assert.equal(ui.detailModal.customer.firstName, 'Juan');
  ui.closeNameEditor();
  ui.detailModal.customer = manual;
  assert.equal(ui.canEditCustomerName, false);
  console.log('Customer actions, restore submission, nested focus, and scroll locks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
