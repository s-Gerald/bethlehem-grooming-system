const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const classes = new Set();
const element = () => ({ isConnected: true, focus(options) { this.focusOptions = options; document.activeElement = this; }, querySelectorAll: () => [] });
const document = { activeElement: element(), documentElement: { classList: { toggle(name, on) { on ? classes.add(name) : classes.delete(name); } } }, body: { classList: { toggle() {} } } };
let role = 'admin', calls = 0, resolveRequest;
const context = vm.createContext({ document, window: {}, console, API: {
  getUserRole: () => role,
  unarchiveCustomer: () => { calls++; return new Promise(resolve => { resolveRequest = resolve; }); },
  restoreUnregisteredCustomer: async () => { calls++; throw new Error('Restore failed'); },
} });
vm.runInContext(fs.readFileSync('scripts/components/admin-customers.js', 'utf8'), context);
const ui = context.adminCustomers();
ui.$nextTick = callback => callback();
ui.$refs = Object.fromEntries(['confirmDialog', 'confirmCancel', 'ownerDialog', 'petDialog', 'ownerActionsButton', 'ownerActionsMenu', 'petKeepEditing', 'petClose'].map(name => [name, element()]));
ui.loadCustomers = async () => {};
const portal = { id: 1, fullName: 'Owner', isActive: true, recordType: 'registered' };
const manual = { ...portal, recordType: 'unregistered' };

(async () => {
  assert.equal(ui.customerStatusLabel(manual), 'Manual record');
  assert.equal(ui.customerStatusLabel(portal), 'Portal account · Enabled');
  assert.equal(ui.customerStatusLabel({ ...portal, isActive: false }), 'Portal account · Deactivated');
  assert.equal(ui.customerStatusLabel({ ...manual, isArchived: true }), 'Archived · Manual record');
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
  console.log('Customer actions, restore submission, nested focus, and scroll locks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
