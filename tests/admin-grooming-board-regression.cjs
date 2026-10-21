const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), context);
const ui = context.adminDashboard();
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
const booking = { id: 42, queueNumber: 3, ownerName: 'Erwin Francisco', pets: [pet], dropOffTime: '1:22 AM' };

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
assert.equal(ui.actionConfirmModal.title, 'Cancel pre-registration?');
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
assert.equal(ui.incomingPetSummary(multiple), '3 pets · Hopper + 2 more');
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
ui.groomingWorkload = { state: 'On track' };
ui.activeGroomers = 1;
ui.activeGroomingPets = 0;
assert.equal(ui.groomingWorkloadMessage, '', 'Normal workload stays quiet');
ui.activeGroomingPets = 1;
ui.queuedList = [booking];
assert.equal(ui.groomingWorkloadMessage, 'Groomer capacity reached');
ui.groomingWorkload.state = 'At risk';
assert.equal(ui.groomingWorkloadMessage, 'Schedule at risk');
ui.groomingWorkload.state = 'Needs staff action';
assert.equal(ui.groomingWorkloadMessage, 'Current grooming workload needs attention');
context.window.GroomingEstimates = { formatTimeWindow: () => '6:00 PM' };
ui.groomingWorkload.closing_time = '2026-10-16T18:00:00+08:00';
ui.groomingWorkload.projected_last_completion = '2026-10-16T18:30:00+08:00';
assert.match(ui.groomingWorkloadMessage, /Projected completion exceeds grooming hours/);
assert.match(ui.groomingWorkloadMessage, /6:00 PM/);

ui.applyDashboardData({
  incomingList: [booking], queuedList: [], inProgressList: [],
  groomerCapacity: { groomers_on_duty: 2, active_pets: 0 },
  groomingWorkload: { state: 'On track', pets: [], waiting: 0, in_progress: 0,
    closing_time: '2026-10-16T17:00:00+08:00', projected_last_completion: null, fits: false },
});
for (const tab of ['queued', 'in-progress']) {
  ui.setTab(tab);
  assert.equal(ui.groomingWorkloadMessage, '', 'Closed hours and an Incoming booking do not create a workload warning');
  assert.equal(ui.showGroomingWorkload, false, 'A groomer count alone does not create a workload row');
}
ui.queuedList = [booking];
ui.groomingWorkload = { state: 'Needs staff action', pets: { 7: { projected_completion: null } } };
assert.equal(ui.groomingWorkloadMessage, 'Current grooming workload needs attention');
assert.equal(ui.showGroomingWorkload, true, 'Unfinished work with unknown estimates keeps its warning visible');
ui.groomingWorkload = { state: 'At risk', pets: { 7: { projected_completion: '2026-10-16T16:45:00+08:00' } } };
assert.equal(ui.groomingWorkloadMessage, 'Schedule at risk');
assert.equal(ui.showGroomingWorkload, true, 'Actual at-risk work remains visible');
ui.queuedList = [];
ui.groomingWorkload = { state: 'On track', pets: { 7: { projected_completion: '2026-10-16T18:00:00+08:00' } } };
assert.equal(ui.showGroomingWorkload, true, 'Useful completion metrics remain visible without a warning');

context.window.GroomingEstimates.factors = () => [
  { value: 'thick_coat', label: 'Thick coat' },
  { value: 'matted_tangled', label: 'Matted / tangled' },
];
const selectedPet = { ...pet, groomingEstimate: {
  preference: 'summer_cut', preferenceLabel: 'Summer Cut', factors: ['matted_tangled', 'thick_coat', 'matted_tangled'],
  minMinutes: 120, maxMinutes: 240,
} };
const snapshot = JSON.stringify(selectedPet);
assert.equal(JSON.stringify(ui.groomingSelectionLabels(selectedPet)), JSON.stringify(['Summer Cut', 'Matted / tangled', 'Thick coat']));
assert.equal(JSON.stringify(selectedPet), snapshot, 'Read-only labels never alter saved selections or duration');
assert.equal(ui.groomingSelectionLabels({}).length, 0);
assert.equal(ui.groomingSelectionLabels({ groomingEstimate: { preferenceLabel: '—', factors: ['unknown'] } }).length, 0);
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
  const { environment, deferred } = require('./admin-data-cache-regression.cjs');
  const e = environment();
  e.c.Intl = Intl;
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), e.c);
  const start = e.c.adminDashboard();
  start.$nextTick = () => {};
  start.pageMode = 'appointments';
  for (const method of ['registerBridge', 'loadConfig', 'bootstrapDashboard', 'startContentPolling', 'restoreGroomingOnlinePayment', 'dispatchDashboardEvent']) {
    start[method] = () => {};
  }
  let refreshes = 0;
  start.loadAdminBookings = async () => { refreshes++; };
  await start.init();
  refreshes = 0;
  const zeus = { ...pet, petName: 'Zeus', groomingEstimate: { minMinutes: 60, maxMinutes: 75,
    formatted: '1 hr–1 hr 15 min', preference: 'summer_cut', factors: ['thick_coat'] } };
  const ginger = { ...sibling, groomingEstimate: { minMinutes: 90, maxMinutes: 90,
    formatted: '1 hr 30 min', preference: null, factors: [] } };
  const walkin = { ...pair, bookingType: 'Walk-In', pets: [zeus, ginger] };
  const before = JSON.stringify(walkin);
  start.queuedList = [walkin];
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.title, 'Start Grooming');
  assert.equal(start.actionConfirmModal.message, 'Start grooming for Zeus?');
  assert.equal(start.getPetEstimateLabel(start.actionConfirmModal.booking.actionPet), '1 hr–1 hr 15 min');
  assert.equal(start.actionConfirmModal.confirmLabel, 'Yes, Start');
  assert.equal('estimateFactors' in start.actionConfirmModal.booking.actionPet, false);
  assert.equal('groomingPreference' in start.actionConfirmModal.booking.actionPet, false);
  assert.equal(e.calls.length, 0, 'Opening the confirmation uses loaded booking data');
  start.closeActionConfirmModal();
  assert.equal(start.actionConfirmModal.open, false);
  assert.equal(e.calls.length, 0, 'Cancel sends no request');

  start.confirmStartGroomingPet(walkin, ginger);
  assert.equal(start.actionConfirmModal.message, 'Start grooming for Mark?');
  assert.equal(start.getPetEstimateLabel(start.actionConfirmModal.booking.actionPet), '1 hr 30 min');
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
  assert.equal(JSON.stringify(start.actionConfirmModal.booking.actionPet.estimateFactors), '["thick_coat"]');
  start.actionConfirmModal.booking.actionPet.estimateFactors = ['extra_handling'];
  start.actionConfirmModal.booking.actionPet.groomingPreference = 'regular_trim';
  e.respond((endpoint, options) => { submitted = JSON.parse(options.body); return { success: true }; });
  await start.executeConfirmedBookingAction();
  assert.deepEqual(submitted, { estimate_factors: ['extra_handling'], grooming_preference: 'regular_trim' });
  assert.equal(refreshes, 2);

  start.queuedList = [{ ...booking, id: 1, queueNumber: 1 }, walkin];
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.title, 'Queue Order Warning');
  assert.equal(start.actionConfirmModal.variant, 'warning');
  assert.match(start.actionConfirmModal.message, /earlier queue still has unfinished pets/);
  assert.equal(start.getPetEstimateLabel(start.actionConfirmModal.booking.actionPet), '1 hr–1 hr 15 min');
  start.closeActionConfirmModal();
  start.activeGroomers = 1;
  start.activeGroomingPets = 1;
  start.confirmStartGroomingPet(walkin, zeus);
  assert.equal(start.actionConfirmModal.open, false);
  assert.match(start.groomerCapacityError, /capacity is full/);
  assert.equal(e.calls.length, 2, 'Warnings and capacity checks add no requests');
}
startGroomingConfirmation().then(() => console.log('Admin Grooming board regression tests passed.'))
  .catch(error => { console.error(error); process.exitCode = 1; });
