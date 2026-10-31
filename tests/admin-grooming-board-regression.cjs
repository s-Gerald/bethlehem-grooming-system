const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), context);
const ui = context.adminDashboard();
ui.openActionConfirmModal({action:'cancel', booking:{id:901}, title:'Cancel', message:'', confirmLabel:'Confirm cancellation'});
assert.equal(ui.actionConfirmModal.conflictBlocked, false, 'Cancellation must start with an explicit nonblocking state');
ui.actionConfirmModal.conflictBlocked = true;
ui.openActionConfirmModal({action:'cancelPet', booking:{id:902}, title:'Cancel pet', message:''});
assert.equal(ui.actionConfirmModal.conflictBlocked, false, 'Check-in conflict state cannot leak into pet cancellation');
ui.closeActionConfirmModal();
assert.equal(ui.actionConfirmModal.conflictBlocked, false);

ui.$nextTick = () => {};
ui.dispatchDashboardEvent = () => {};
assert.equal(ui.sedationConsentStatusLabel({ sedationConsent: true }), 'Sedation authorized');
assert.equal(ui.sedationConsentStatusLabel({ sedationConsent: false, sedationConsentRecorded: true }), 'Sedation not authorized');
assert.equal(ui.sedationConsentStatusLabel({ sedationConsent: false }), 'Sedation not recorded');
assert.match(ui.sedationConsentStatusClass({ sedationConsent: false }), /amber/);
assert.match(ui.sedationConsentStatusClass({ sedationConsent: true }), /green/);
assert.equal(ui.sedationConsentSourceLabel({ sedationConsent: true, sedationConsentSource: 'customer_online' }), 'Online');
assert.equal(ui.sedationConsentSourceLabel({ sedationConsent: true, sedationConsentSource: 'staff_in_person' }), 'In person');
assert.equal(ui.sedationConsentSourceLabel({ sedationConsent: false }), '');
const pet = { bookingPetId: 7, petQueueNumber: 12, petName: 'Hopper', species: 'Dog', breed: 'Dachshund', groomingState: 'not_started' };
const booking = { id: 42, booking_type: 'online', walkin_id: null, queueNumber: 3, ownerName: 'Erwin Francisco', pets: [pet], dropOffTime: '1:22 AM' };

for (const [tab, list, label, tone] of [
  ['queued', 'queuedList', 'Waiting', 'waiting'],
  ['in-progress', 'inProgressList', 'In Progress', 'progress'],
  ['for-payment', 'forPaymentList', 'Awaiting Payment', 'payment'],
  ['to-be-picked-up', 'releasedList', 'Paid & Ready', 'pickup'],
]) {
  ui.applyDashboardData({ [list]: [{ ...booking, status: tab }] });
  ui.setTab(tab);
  const record = ui.operationalBookings[0];
  assert.equal(record.id, booking.id);
  assert.equal(record.queueNumber, 3);
  assert.equal(ui.formatPetQueueNumber(record.pets[0]), '#P12');
  assert.equal(ui.groomingPetCount(record), '1 pet');
  assert.equal(ui.groomingStatusLabel(record), label);
  assert.equal(ui.groomingCardTone(record), tone);
}
assert.equal(ui.groomingStatusLabel({ status: 'for_payment', paid: true }), 'Paid & Ready');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'queued' }), 'Dropped off 1:22 AM');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'in_progress', startedAt: '1:25 AM' }), 'Started 1:25 AM');
assert.equal(ui.groomingBookingTiming({ ...booking, status: 'for-payment', completedAt: '3:10 AM' }), 'Finished 3:10 AM');
assert.equal(ui.groomingBookingTiming({ status: 'for-payment' }), '');
assert.equal(ui.shouldShowUndoCheckIn({ ...booking, status: 'queued' }), true);
for (const origin of [{ booking_type: 'walk_in' }, { walkin_id: 42 }, { booking_type: undefined, walkin_id: undefined }]) {
  const walkin = { ...booking, ...origin, bookingType: 'Pre-Register', status: 'queued' };
  assert.equal(ui.shouldShowUndoCheckIn(walkin), false, 'Only persisted online origin permits Undo Check-in');
  assert.equal(ui.shouldShowOwnerCancel(walkin), true);
  ui.confirmUndoCheckIn(walkin);
  assert.equal(ui.actionConfirmModal.open, false, 'A stale action cannot open an invalid correction');
}
assert.equal(ui.shouldShowUndoCheckIn({ ...booking, status: 'in-progress', pets: [{ ...pet, groomingState: 'in_progress' }] }), false);
assert.equal(ui.shouldShowUndoCheckIn({ ...booking, status: 'in-progress', pets: [{ ...pet, groomingState: 'finished' }] }), false);
assert.equal(ui.shouldShowUndoCheckIn({ ...booking, status: 'for-payment' }), false);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'queued' }), true);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'in-progress', paid: true }), false);
assert.equal(ui.shouldShowOwnerCancel({ ...booking, status: 'to-be-picked-up' }), false);

const sibling = { ...pet, bookingPetId: 8, petName: 'Mark' };
const cancelled = { ...sibling, groomingState: 'cancelled' };
const started = { ...pet, groomingState: 'in_progress', groomingStartedAtIso: '2026-10-16T09:00:00+08:00' };
const finished = { ...started, groomingState: 'finished', groomingFinishedAtIso: '2026-10-16T10:00:00+08:00' };
const pair = { ...booking, status: 'queued', pets: [pet, sibling] };
assert.equal(ui.canCancelPet(pair, sibling), true);
assert.equal(ui.canCancelPet({ ...booking, status: 'queued' }, pet), false, 'Single-pet rows use Cancel Booking');
assert.equal(ui.canCancelPet({ ...pair, pets: [pet, cancelled] }, pet), false, 'One remaining active pet uses Cancel Booking');
assert.equal(ui.canUndoPetStart({ ...pair, status: 'in-progress', pets: [started, finished] }, started), true);
assert.equal(ui.shouldShowOwnerCancel({ ...pair, pets: [started, sibling] }), false);
assert.equal(ui.shouldShowUndoCheckIn({ ...pair, pets: [finished, sibling] }), false);
for (const record of [{ ...pair, paid: true }, { ...pair, payment: { id: 1 } }]) {
  assert.equal(ui.shouldShowOwnerCancel(record), false);
  assert.equal(ui.shouldShowUndoCheckIn(record), false);
  assert.equal(ui.canCancelPet(record, sibling), false);
  assert.equal(ui.canUndoPetStart(record, started), false);
}
for (const groomingState of ['in_progress', 'finished', 'cancelled', 'medical_concern']) {
  assert.equal(ui.canCancelPet(pair, { ...pet, groomingState }), false);
}
for (const status of ['for-payment', 'to-be-picked-up', 'archived', 'cancelled']) {
  const record = { ...pair, status };
  assert.equal(ui.shouldShowOwnerCancel(record), false);
  assert.equal(ui.shouldShowUndoCheckIn(record), false);
  assert.equal(ui.canCancelPet(record, sibling), false);
  assert.equal(ui.canUndoPetStart(record, started), false);
}
assert.equal(ui.petGroomingStateLabel(cancelled), 'Cancelled');
assert.equal(ui.getWaitingGroomingPets({ ...pair, pets: [pet, cancelled] }).length, 1);
assert.equal(ui.hasUnfinishedQueuedPets({ ...pair, pets: [finished, cancelled] }), false);
ui.confirmCancelPet(pair, sibling);
assert.equal(ui.actionConfirmModal.title, 'Cancel Mark');
assert.equal(ui.actionConfirmModal.message, '', 'Cancellation adds no helper paragraph');
assert.equal(ui.actionConfirmModal.booking.actionPet.bookingPetId, sibling.bookingPetId);
ui.closeActionConfirmModal();
ui.confirmCancelBooking(pair);
assert.equal(ui.actionConfirmModal.title, 'Cancel online registration?');
assert.equal(ui.actionConfirmModal.confirmLabel, 'Confirm cancellation');
assert.equal(ui.actionConfirmModal.message, '');
ui.closeActionConfirmModal();
ui.confirmCancelBooking({ ...pair, bookingType: 'Walk-In' });
assert.equal(ui.actionConfirmModal.title, 'Cancel walk-in?');
assert.equal(ui.actionConfirmModal.confirmLabel, 'Confirm cancellation');
ui.closeActionConfirmModal();
ui.confirmCheckInBooking({ ...pair, pets: [pet, cancelled] });
assert.equal(ui.actionConfirmModal.petSizes.length, 1, 'Cancelled pets are excluded when checking in again');
ui.closeActionConfirmModal();
const billing = { ...pair, pets: [pet, cancelled], services: [
  { id: 1, bookingPetId: 7, priceAtBooking: 850, name: 'Grooming' },
  { id: 2, bookingPetId: 8, priceAtBooking: 650, name: 'Grooming' },
] };
assert.equal(ui.buildPaymentBreakdown(billing).length, 1);
assert.equal(ui.billableGroomingServices(billing).length, 1);
assert.equal(ui.getPetServicesAvailed(cancelled, billing).length, 1, 'Original services remain available for display');

const multiple = { ...booking, pets: [pet, { ...pet, bookingPetId: 8, petName: 'Mark' }, { ...pet, bookingPetId: 9, petName: 'Milo' }] };
assert.equal(ui.incomingPetSummary(booking), 'Hopper');
assert.equal(ui.incomingPetSummary(multiple), 'Hopper · + 2 more pets');
for (const status of ['queued', 'in-progress']) {
  const record = { ...multiple, status };
  assert.equal(ui.isGroomingBookingExpanded(record), false);
  ui.toggleGroomingBooking(record);
  assert.equal(ui.isGroomingBookingExpanded(record), true);
  ui.toggleGroomingBooking(record);
  assert.equal(ui.isGroomingBookingExpanded(record), false);
}
ui.activeTab = 'incoming';
assert.equal(ui.operationalBookings.length, 0);
ui.queuedList = [];
ui.inProgressList = [];
ui.activeGroomers = 1;
ui.activeGroomingPets = 0;
assert.equal(ui.isGroomerCapacityFull, false);
ui.activeGroomingPets = 1;
assert.equal(ui.isGroomerCapacityFull, true);
const selectedPet = { ...pet, groomingPreference: 'summer_cut', groomingPreferenceLabel: 'Summer Cut' };
const snapshot = JSON.stringify(selectedPet);
assert.equal(JSON.stringify(ui.groomingSelectionLabels(selectedPet)), JSON.stringify(['Summer Cut']));
assert.equal(JSON.stringify(selectedPet), snapshot);
assert.equal(ui.groomingSelectionLabels({}).length, 0);
assert.equal(ui.groomingPetIdentity({ ...pet, size: 'large', weight: '12 kg', furType: '—' }), 'Dog · Dachshund · Large · 12 kg');

const record = { ...booking, status: 'queued' };
for (const control of ['button', 'a', 'input', '[role="menu"]']) {
  ui.toggleGroomingCardFromPointer({ target: { closest: () => control } }, record);
  assert.equal(ui.isGroomingBookingExpanded(record), false, `${control} does not toggle disclosure`);
}
ui.toggleGroomingCardFromPointer({ target: { closest: () => null } }, record);
assert.equal(ui.isGroomingBookingExpanded(record), true);
ui.toggleGroomingCardFromPointer({ target: { closest: () => null } }, record);
assert.equal(ui.isGroomingBookingExpanded(record), false);

let restoredFocus = 0;
const event = { currentTarget: { focus() { restoredFocus++; } } };
ui.openGroomingMenu(record, event);
assert.equal(ui.groomingMenuBookingId, record.id);
ui.handleGroomingMenuKeydown({ key: 'Escape', preventDefault() {} });
assert.equal(ui.groomingMenuBookingId, null);
assert.equal(restoredFocus, 1);
ui.openGroomingMenu(record, event);
ui.toggleGroomingBooking(record);
assert.equal(ui.groomingMenuBookingId, null, 'Disclosure closes the secondary menu');
ui.openGroomingMenu(record, event);
ui.setTab('for-payment');
assert.equal(ui.groomingMenuBookingId, null, 'Changing stages dismisses any secondary menu');
async function startGroomingConfirmation() {
  const cancellation = context.adminDashboard();
  const calls = [];
  cancellation.cancelBooking = async (booking, reason) => calls.push([booking.id, reason]);
  cancellation.openActionConfirmModal({action:'cancel', booking:{id:901}});
  await cancellation.executeConfirmedBookingAction();
  assert.equal(calls.length,0,'Blank cancellation reason remains blocked');
  assert.equal(cancellation.actionConfirmModal.error,'Enter a cancellation reason.');
  cancellation.actionConfirmModal.cancellationReason = 'Customer requested cancellation';
  await cancellation.executeConfirmedBookingAction();
  assert.deepEqual(calls,[[901,'Customer requested cancellation']]);
  assert.equal(cancellation.actionConfirmModal.open,false);

  const intake = context.adminDashboard();
  let intakePayload;
  intake.runBookingAction = async (action, record, payload) => { intakePayload = { action, record, payload }; };
  await intake.checkInBooking(booking, [{ bookingPetId: 7, size: 'large', estimateFactors: ['thick_coat'] }], 'Keep dry');
  assert.equal(intakePayload.action, 'checkIn');
  assert.deepEqual(JSON.parse(JSON.stringify(intakePayload.payload)), { petSizes: [{ booking_pet_id: 7, size: 'large' }], internalStaffNote: 'Keep dry' });

  const { environment, deferred } = require('./admin-data-cache-regression.cjs');
  const e = environment();
  e.c.Intl = Intl;
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), e.c);
  const start = e.c.adminDashboard();
  start.$nextTick = () => {};
  start.pageMode = 'appointments';
  for (const method of ['registerBridge', 'loadConfig', 'bootstrapDashboard', 'startContentPolling', 'dispatchDashboardEvent']) {
    start[method] = () => {};
  }
  let refreshes = 0;
  start.loadAdminBookings = async () => { refreshes++; };
  await start.init();
  refreshes = 0;
  const zeus = { ...pet, petName: 'Zeus', groomingPreference: 'summer_cut', groomingPreferenceLabel: 'Summer Cut' };
  const ginger = { ...sibling, groomingPreference: null };
  const walkin = { ...pair, bookingType: 'Walk-In', pets: [zeus, ginger] };
  const before = JSON.stringify(walkin);
  start.queuedList = [walkin];
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.title, 'Start Grooming');
  assert.equal(start.actionConfirmModal.message, 'Start grooming for Zeus?');
  assert.equal(start.actionConfirmModal.confirmLabel, 'Yes, Start');
  assert.equal('estimateFactors' in start.actionConfirmModal.booking.actionPet, false);
  assert.equal(start.actionConfirmModal.booking.actionPet.groomingPreference, 'summer_cut');
  assert.equal(e.calls.length, 0, 'Opening the confirmation uses loaded booking data');
  start.closeActionConfirmModal();
  assert.equal(start.actionConfirmModal.open, false);
  assert.equal(e.calls.length, 0, 'Cancel sends no request');

  start.confirmStartGroomingPet(walkin, ginger);
  assert.equal(start.actionConfirmModal.message, 'Start grooming for Mark?');
  const held = deferred();
  let submitted;
  e.respond((endpoint, options) => { submitted = JSON.parse(options.body); return held.promise; });
  const pending = start.executeConfirmedBookingAction();
  assert.equal(start.actionConfirmModal.busy, true);
  start.closeActionConfirmModal();
  assert.equal(start.actionConfirmModal.open, true, 'Processing cannot dismiss the confirmation');
  await start.executeConfirmedBookingAction();
  assert.equal(start.actionConfirmModal.busy, true, 'A second confirmation must not clear the busy state');
  assert.equal(e.calls.length, 1, 'Duplicate confirmation sends only one mutation');
  assert.equal(e.calls[0].endpoint, '/admin/bookings/42/pets/8/start-grooming');
  assert.deepEqual(submitted, {}, 'Walk-in start sends no assessment');
  held.resolve({ success: true });
  await pending;
  assert.equal(refreshes, 1, 'Successful start keeps the single existing refresh');
  assert.equal(start.actionConfirmModal.open, false);
  assert.equal(JSON.stringify(walkin), before, 'Starting a pet never mutates either loaded assessment');

  const prereg = { ...walkin, bookingType: 'Pre-Register' };
  start.confirmStartGroomingPet(prereg, zeus);
  assert.equal(start.actionConfirmModal.title, 'Confirm Start Grooming');
  start.actionConfirmModal.booking.actionPet.groomingPreference = 'regular_trim';
  e.respond((endpoint, options) => { submitted = JSON.parse(options.body); return { success: true }; });
  await start.executeConfirmedBookingAction();
  assert.deepEqual(submitted, { grooming_preference: 'regular_trim' });
  assert.equal(refreshes, 2);

  start.queuedList = [{ ...booking, id: 1, queueNumber: 1 }, walkin];
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.title, 'Queue Order Warning');
  assert.equal(start.actionConfirmModal.variant, 'warning');
  assert.match(start.actionConfirmModal.message, /earlier queue still has unfinished pets/);
  start.closeActionConfirmModal();
  start.activeGroomers = 1;
  start.activeGroomingPets = 1;
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.open, false);
  assert.match(start.groomerCapacityError, /capacity is full/);
  assert.equal(e.calls.length, 2, 'Warnings and capacity checks add no requests');
  const pickup = deferred();
  e.respond(endpoint => {
    assert.equal(endpoint, '/admin/bookings/42/picked-up');
    return pickup.promise;
  });
  start.confirmPickedUp(walkin);
  const pickedUp = start.executePickedUp();
  assert.equal(start.pickupModal.busy, true);
  pickup.resolve({ success: true });
  await pickedUp;
  assert.equal(start.pickupModal.open, false, 'Successful pickup still closes its confirmation');
  assert.equal(refreshes, 3, 'Successful pickup retains its existing single refresh');
}
async function staticModalInteractions() {
  // Isolated, fixture-only browser: no localhost account, database, or camera.
  const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
  const html = fs.readFileSync(path.join(__dirname, '../pages/admin/appointments.html'), 'utf8');
  const main = html.match(/<main\b[\s\S]*?<\/main>/)[0];
  const alpineResponse = await fetch('https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js', { signal: AbortSignal.timeout(15000) });
  assert.equal(alpineResponse.ok, true);
  const alpine = await alpineResponse.text();
  const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
  try {
    for (const role of ['admin', 'grooming_receptionist', 'clinic_receptionist']) {
      const page = await browser.newPage();
      page.setDefaultTimeout(5000);
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.abort());
      await page.setContent(`<style>[x-cloak]{display:none!important}.fixed,.pickup-confirm-overlay{position:fixed;inset:0;background:#7778;display:flex;align-items:center;justify-content:center}.fixed>div,.pickup-confirm-card{background:white;width:600px;max-height:80vh;overflow:auto}</style><button id="opener">Open workflow</button><div inert id="already-inert">Unavailable</div>${main}`);
      await page.addScriptTag({ path: path.join(__dirname, '../scripts/components/admin-dashboard.js') });
      await page.addScriptTag({ path: path.join(__dirname, '../scripts/components/cash-payment.js') });
      await page.addScriptTag({ path: path.join(__dirname, '../scripts/components/product-form.js') });
      await page.addScriptTag({ path: path.join(__dirname, '../scripts/components/admin-notifications.js') });
      await page.evaluate(role => {
        const create = window.adminDashboard;
        window.API = { getRole: () => role === 'admin' ? 'admin' : 'staff' };
        window.PaymentInvoice = { template: '', print() { window.printed = true; } };
        window.AdminNotificationService = {
          subscribe: () => () => {},
          load: async () => ({ notifications: [], unread_count: 0, pagination: { has_more: false } }),
        };
        // Replace the camera hardware, keeping ProductForm's real lifecycle.
        window.scannerStops = 0;
        window.Html5Qrcode = class {
          start(constraints, config, decoded) { window.decodeBarcode = decoded; return Promise.resolve(); }
          stop() { window.scannerStops++; return Promise.resolve(); }
        };
        window.InventoryAPI = { findByBarcode: async () => ({ data: {
          item_id: 21, item_name: 'Test Shampoo', unit: 'bottle', category: 'grooming',
          is_active: true, selling_price: 100, saleable_quantity: 5,
        } }) };
        window.adminDashboard = () => {
          const ui = create();
          ui.init = () => {};
          ui.clinicOnly = false;
          return ui;
        };
      }, role);
      await page.addScriptTag({ content: alpine });
      await page.waitForFunction(() => !!window.Alpine && !!document.querySelector('main')._x_dataStack);
      const cases = [
        ['paymentModal.open', 'Close payment modal'],
        ['detailsModalOpen', 'Close Grooming Details'],
        ['receiptModal.open', 'Done'],
        ['actionConfirmModal.open', 'Go Back'],
        ['pickupModal.open', 'Cancel'],
      ];
      for (const [expression, closeLabel] of cases) {
        await page.locator('#opener').focus();
        await page.evaluate(expression => {
          const ui = Alpine.$data(document.querySelector('main'));
          ui.paymentModal.amountPaid = '1234';
          ui.paymentModal.notes = 'Keep this payment';
          ui.paymentModal.products = [{ item_id: 1, name: 'Shampoo', price: 50, stock: 5, quantity: 2 }];
          ui.paymentModal.error = 'Validation stays visible';
          ui.noteEditor.value = 'Unsaved note';
          ui.actionConfirmModal.action = 'cancel';
          ui.actionConfirmModal.title = 'Confirm action';
          ui.actionConfirmModal.confirmLabel = 'Confirm';
          ui.actionConfirmModal.cancellationReason = 'Keep this reason';
          const [key, property] = expression.split('.');
          if (property) ui[key][property] = true;
          else ui[key] = true;
        }, expression);
        const overlay = page.locator(`[x-show="${expression}"]`);
        await overlay.waitFor({ state: 'visible' });
        const snapshot = () => page.evaluate(() => {
          const ui = Alpine.$data(document.querySelector('main'));
          return JSON.stringify([ui.paymentModal, ui.receiptModal, ui.actionConfirmModal, ui.pickupModal, ui.noteEditor]);
        });
        const before = await snapshot();
        await overlay.click({ position: { x: 2, y: 2 } });
        await page.keyboard.press('Escape');
        assert.equal(await overlay.isVisible(), true, `${role}: ${expression} stays open`);
        assert.equal(await snapshot(), before, `${role}: outside click and Escape preserve state`);
        await page.waitForFunction(expression => document.querySelector(`[x-show="${expression}"]`).contains(document.activeElement), expression)
          .catch(async error => {
            console.error(role, expression, await page.evaluate(() => ({ focus: document.activeElement.outerHTML.slice(0, 200), active: Alpine.$data(document.querySelector('main')).activeGroomingModal })), errors);
            throw error;
          });
        assert.equal(await page.locator('#opener').evaluate(el => el.closest('[inert]') !== null), true);
        const close = overlay.getByRole('button', { name: closeLabel, exact: true });
        if (expression === 'receiptModal.open') {
          await overlay.getByRole('button', { name: 'Print Invoice', exact: true }).click();
          assert.equal(await page.evaluate(() => window.printed), true);
        }
        if (expression === 'paymentModal.open' || expression === 'actionConfirmModal.open' || expression === 'pickupModal.open') {
          await page.evaluate(expression => { Alpine.$data(document.querySelector('main'))[expression.split('.')[0]].busy = true; }, expression);
          if (expression === 'paymentModal.open') await close.click();
          else assert.equal(await close.isDisabled(), true);
          assert.equal(await overlay.isVisible(), true, 'Processing prevents explicit dismissal');
          await page.evaluate(expression => { Alpine.$data(document.querySelector('main'))[expression.split('.')[0]].busy = false; }, expression);
        }
        await close.focus();
        await page.keyboard.press('Shift+Tab');
        assert.equal(await overlay.evaluate(el => el.contains(document.activeElement)), true, 'Reverse Tab stays contained');
        await close.focus();
        await page.keyboard.press('Tab');
        assert.equal(await overlay.evaluate(el => el.contains(document.activeElement)), true, 'Tab stays contained');
        await close.focus();
        await page.keyboard.press('Enter');
        await overlay.waitFor({ state: 'hidden' });
        await page.waitForFunction(() => document.activeElement.id === 'opener');
        assert.equal(await page.locator('#opener').evaluate(el => el.closest('[inert]') !== null), false);
        assert.equal(await page.locator('#already-inert').getAttribute('inert'), '');
      }
      await page.locator('#opener').focus();
      await page.evaluate(() => {
        const ui = Alpine.$data(document.querySelector('main'));
        ui.paymentModal.showProductSearch = true;
        ui.paymentModal.open = true;
      });
      const payment = page.locator('[x-show="paymentModal.open"]');
      await payment.waitFor({ state: 'visible' });
      await page.waitForFunction(() => document.querySelector('[x-show="paymentModal.open"]').contains(document.activeElement));
      const scan = payment.getByRole('button', { name: 'Scan product barcode' });
      await scan.click();
      const scanner = page.locator('[x-show="paymentModal.scannerActive"]');
      await scanner.waitFor({ state: 'visible' });
      await page.waitForFunction(() => document.querySelector('[x-show="paymentModal.scannerActive"]').contains(document.activeElement));
      await page.waitForFunction(() => !!window.decodeBarcode);
      const paymentBeforeScan = await page.evaluate(() => {
        const modal = Alpine.$data(document.querySelector('main')).paymentModal;
        return JSON.stringify([modal.amountPaid, modal.notes, modal.products, modal.error]);
      });
      await scanner.click({ position: { x: 2, y: 2 } });
      await page.keyboard.press('Escape');
      assert.equal(await scanner.isVisible(), true);
      await page.keyboard.press('Tab');
      assert.equal(await scanner.evaluate(el => el.contains(document.activeElement)), true);
      assert.equal(await payment.evaluate(el => el.inert), true, 'Scanner makes payment inert');
      await scanner.getByRole('button', { name: 'Close barcode scanner' }).focus();
      await page.keyboard.press('Space');
      await scanner.waitFor({ state: 'hidden' });
      assert.equal(await payment.isVisible(), true);
      await page.waitForFunction(() => document.activeElement.getAttribute('aria-label') === 'Scan product barcode');
      assert.equal(await payment.evaluate(el => el.inert), false);
      assert.equal(await page.evaluate(() => window.scannerStops), 1, 'X releases the camera');
      assert.equal(await page.evaluate(() => {
        const modal = Alpine.$data(document.querySelector('main')).paymentModal;
        return JSON.stringify([modal.amountPaid, modal.notes, modal.products, modal.error]);
      }), paymentBeforeScan, 'Scanner dismissal preserves the payment draft');
      await scan.click();
      await page.waitForFunction(() => !!Alpine.$data(document.querySelector('main')).paymentModal._scanner);
      await page.evaluate(() => window.decodeBarcode('1234567890123'));
      await scanner.waitFor({ state: 'hidden' });
      await page.waitForFunction(() => Alpine.$data(document.querySelector('main')).paymentModal.products.some(line => line.item_id === 21));
      assert.equal(await payment.isVisible(), true, 'Successful scanning returns to the existing payment');
      assert.equal(await page.evaluate(() => window.scannerStops), 2, 'Successful scanning releases the camera');
      await payment.getByRole('button', { name: 'Cancel', exact: true }).click();
      await page.waitForFunction(() => document.activeElement.id === 'opener');

      await page.evaluate(() => {
        const ui = Alpine.$data(document.querySelector('main'));
        ui.loadAdminBookings = async () => {};
        ui.paymentModal.booking = { id: 42, ownerName: 'Test Customer' };
        ui.paymentModal.amountPaid = '500';
        ui.paymentModal.open = true;
      });
      await page.waitForFunction(() => document.querySelector('[x-show="paymentModal.open"]').contains(document.activeElement));
      await page.evaluate(() => Alpine.$data(document.querySelector('main')).completeGroomingPayment({
        final_price: 500, amount_paid: 500, change: 0, payment_method: 'cash', booking_status: 'released',
      }));
      await payment.waitFor({ state: 'hidden' });
      const receipt = page.locator('[x-show="receiptModal.open"]');
      await receipt.waitFor({ state: 'visible' });
      await page.waitForFunction(() => document.activeElement.textContent.trim() === 'Done');
      await receipt.getByRole('button', { name: 'Done', exact: true }).click();
      await page.waitForFunction(() => document.activeElement.id === 'opener');

      // The excluded interfaces retain their existing interactions after a modal.
      const bell = page.getByRole('button', { name: 'Notifications', exact: true });
      const preview = page.locator('#admin-notification-preview');
      await bell.click();
      await preview.waitFor({ state: 'visible' });
      await page.locator('#opener').click();
      await preview.waitFor({ state: 'hidden' });
      await bell.click();
      await preview.waitFor({ state: 'visible' });
      await page.keyboard.press('Escape');
      await preview.waitFor({ state: 'hidden' });
      const search = page.getByRole('searchbox', { name: 'Search customers, pets, or phone' });
      await page.evaluate(() => {
        const ui = Alpine.$data(document.querySelector('main'));
        ui.dashboardSearchQuery = 'Test';
        ui.openDashboardSearch();
      });
      const suggestions = page.locator('[x-show="dashboardSearchOpen"]');
      await suggestions.waitFor({ state: 'visible' });
      await page.locator('#opener').click();
      await suggestions.waitFor({ state: 'hidden' });
      await search.focus();
      await suggestions.waitFor({ state: 'visible' });
      await search.press('Escape');
      await suggestions.waitFor({ state: 'hidden' });
      assert.equal(await page.locator('input[type="date"]').isEnabled(), true);
      await page.evaluate(() => {
        const ui = Alpine.$data(document.querySelector('main'));
        ui.bookingsLoaded = true;
        ui.activeTab = 'queued';
        ui.queuedList = [{ id: 42, ownerName: 'Test Customer', status: 'queued', pets: [] }];
      });
      const more = page.getByRole('button', { name: 'More actions for Test Customer' });
      await more.click();
      await page.locator('[role="menu"]').waitFor({ state: 'visible' });
      await page.locator('#opener').click();
      await page.locator('[role="menu"]').waitFor({ state: 'hidden' });
      await more.click();
      await page.locator('[role="menu"]').waitFor({ state: 'visible' });
      await page.keyboard.press('Escape');
      await page.locator('[role="menu"]').waitFor({ state: 'hidden' });
      assert.deepEqual(errors, [], `${role}: no Alpine runtime errors`);
      await page.close();
      console.log(`Static Grooming modals: ${role} passed.`);
    }
  } finally { await browser.close(); }
}

startGroomingConfirmation().then(async () => {
  if (process.argv.includes('--browser')) await staticModalInteractions();
  console.log('Admin Grooming board regression tests passed.');
})
  .catch(error => { console.error(error); process.exitCode = 1; });
