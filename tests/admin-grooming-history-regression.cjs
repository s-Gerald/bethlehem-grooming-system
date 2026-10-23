const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const modalBody = { scrollTop: 0 };
const dialog = { querySelector: (selector) => selector.includes('Close') ? { focus() {} } : modalBody };
const context = { Date, Intl, URLSearchParams, window: {}, document: { body: { style: { overflow: '' } }, querySelector: () => dialog } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-archive.js'), 'utf8'), context);
const ui = context.adminArchive();
ui.$nextTick = (callback) => callback();
ui.$refs = {};

// Repeat visits must keep their own booking-pet links, sizes and recorded prices.
const session = {
  id: 1, ownerName: 'Owner', bookingReference: 'BAC-20261003-0001', bookingType: 'Pre-Register',
  appointmentDate: '2026-10-03', appointmentTime: '8:00 AM - 9:00 AM',
  startedAt: '11:30 AM', completedAt: '12:30 PM',
  pets: [
    { bookingPetId: 11, petId: 1, petName: 'Hopper', confirmedSize: 'medium' },
    { bookingPetId: 12, petId: 2, petName: 'Rigby', confirmedSize: 'large' },
    { bookingPetId: 13, petId: 3, petName: 'Ocon', confirmedSize: 'small' },
  ],
  services: [
    { id: 1, bookingPetId: 11, name: 'Regular Dog Grooming', paidPrice: '650.00', priceAtBooking: '650.00', price: 9999 },
    { id: 2, bookingPetId: 11, name: 'Nail Clipping', paidPrice: '0.00', price: 9999 },
    { id: 3, bookingPetId: 12, name: 'Regular Dog Grooming', priceAtBooking: '850.00', price: 9999 },
    { id: 4, bookingPetId: 13, name: 'Regular Dog Grooming', paidPrice: '550.00', price: 9999 },
  ],
  productAddons: [{ itemName: 'Shampoo', quantity: 2, priceAtSale: 75, subtotal: 150, sellingPrice: 9999 }],
  paidAmount: 2200,
  payment: { id: 10, finalPrice: 2200, amountPaid: 2500, paymentMethod: 'cash' },
  specialNotes: 'Customer note', internalStaffNote: 'Staff note',
};
const repeat = {
  id: 2, ownerName: 'Owner', bookingReference: 'WI-20261004-001', bookingType: 'Walk-In', appointmentDate: '2026-10-04',
  pets: [{ bookingPetId: 21, petId: 1, petName: 'Hopper', confirmedSize: 'large' }],
  services: [{ id: 5, bookingPetId: 21, name: 'Regular Dog Grooming', paidPrice: 900 }],
  productAddons: [], payment: { id: 11, finalPrice: 900, paymentMethod: 'gcash' },
};
const original = JSON.stringify([session, repeat]);
ui.archivedList = [session, repeat];
ui.selectedYear = '2026';
assert.equal(ui.filteredCount, 2);
assert.equal(ui.groupedByDate.length, 2);
assert.equal(ui.petSummary(session), 'Hopper, Rigby +1 pet');
assert.equal(ui.petSummary(repeat), 'Hopper');
assert.equal(ui.serviceSummary(session), 'Regular Dog Grooming +1 more');
assert.equal(ui.serviceSummary(repeat), 'Regular Dog Grooming');
assert.equal(ui.sessionTime(session), '11:30 AM – 12:30 PM');
assert.equal(ui.sessionTime(repeat), 'Time not recorded');
assert.equal(ui.sessionTime({ startedAt: '11:59 PM', completedAt: '1:00 AM', startedAtIso: '2026-10-03T23:59:00+08:00', completedAtIso: '2026-10-04T01:00:00+08:00' }), '11:59 PM – 1:00 AM (Sun, Oct 4, 2026)');
assert.equal(ui.sessionTime({ startedAt: '11:59 PM', completedAt: '1:00 AM', startedAtIso: '2026-10-03T23:59:00+08:00', completedAtIso: '2026-10-05T01:00:00+08:00' }), '11:59 PM – 1:00 AM (Mon, Oct 5, 2026)');
ui.viewDetails(session);
assert.equal(context.document.body.style.overflow, 'hidden', 'The page stays still while the modal body scrolls');
assert.equal(ui.servicesForPet(session.pets[0]).map((line) => line.id).join(','), '1,2');
assert.equal(ui.formatServiceAvailedPrice(session.services[0]), '₱650.00');
assert.equal(ui.formatServiceAvailedPrice(session.services[1]), '₱0.00');
assert.equal(ui.formatServiceAvailedPrice(session.services[2]), '₱850.00');
assert.equal(ui.paymentAmount(), '₱2200.00');
assert.equal(ui.paymentMethod(), 'Cash');
assert.equal(ui.formatServicesAvailedTotal(), '₱2050.00');
ui.closeDetails();
assert.equal(context.document.body.style.overflow, '', 'Closing restores the page scrolling state');
ui.viewDetails(repeat);
assert.equal(ui.servicesForPet(repeat.pets[0])[0].id, 5);
assert.equal(ui.servicesForPet(session.pets[0]).length, 0);
assert.equal(ui.detailsBooking.pets[0].confirmedSize, 'large');
assert.equal(ui.paymentAmount(), '₱900.00');
assert.equal(ui.paymentMethod(), 'Gcash');
assert.equal(ui.formatServicesAvailedTotal(), '₱900.00');
assert.equal(ui.servicesForPet({}, { services: [{}] }).length, 0);
assert.equal(ui.formatServiceAvailedPrice({ paidPrice: null, price: 9999 }), 'Price unavailable');
assert.equal(ui.formatServicesAvailedTotal({ services: [{ paidPrice: 100 }, {}] }), 'Price unavailable');
assert.equal(ui.paymentAmount({ payment: { finalPrice: 0 }, paidAmount: 900 }), '₱0.00');
assert.equal(ui.paymentAmount({ paidAmount: 123 }), '₱123.00');
assert.equal(ui.paymentAmount({}), 'Not recorded');
const partial = { ...session, pets: [session.pets[0], { ...session.pets[1], groomingState: 'cancelled',
  cancellationReason: 'Customer decided not to proceed.', cancelledAt: '2026-10-16T09:00:00+08:00', cancelledBy: 'Staff Member' }],
  services: session.services.slice(0, 3), payment: null, paidAmount: null };
assert.equal(ui.getServicesAvailedTotal(partial), 650, 'Cancelled pet services do not contribute to the billable total');
assert.equal(ui.servicesForPet(partial.pets[1], partial).length, 1, 'Cancelled pets retain their booked services');
assert.equal(ui.cancellationFields(partial.pets[1]).map((field) => field.label).join(','), 'Cancellation reason,Cancelled,Cancelled by');
assert.ok(ui.groomingTimelineFields(partial.pets[1]).some((field) => field.value === 'Customer decided not to proceed.'));
ui.selectedMonth = '2026-10';
assert.equal(ui.filteredCount, 2);
ui.sortOrder = 'oldest';
assert.equal(ui.filteredList[0].id, 1);
ui.sortOrder = 'newest';
assert.equal(ui.filteredList[0].id, 2);
assert.equal(JSON.stringify([session, repeat]), original, 'Displaying history must never mutate stored records');

// Sparse historical records omit absent details without discarding real content or zero values.
for (const value of [null, undefined, '', '  ', '-', '—', 'Not recorded', 'None provided', 'No staff note added.']) {
  assert.equal(ui.groomingText(value), '');
}
assert.equal(ui.groomingText('Keep the tail long.'), 'Keep the tail long.');
assert.equal(ui.groomingText(0), '0');
assert.equal(ui.groomingText('None'), 'None', 'An actual recorded medical answer is retained');
assert.equal(ui.groomingTimelineFields({ groomingStartedAt: '—', groomingFinishedAt: 'Not recorded' }).length, 0);
assert.equal(ui.groomingTimelineFields({ groomingStartedAt: '9:15 AM' })[0].value, '9:15 AM');
assert.equal(ui.groomingTimelineFields({ groomingFinishedAtIso: '2026-10-03T09:45:00+08:00' })[0].label, 'Finished');
ui.detailsBooking = { bookingType: 'Walk-In', appointmentTime: '8:00 AM - 9:00 AM', queueNumber: 0 };
assert.equal(ui.groomingSessionFields.map(field => field.label).join(','), 'Entry source', 'Walk-ins omit arrival windows; absent queue and date are omitted');
assert.equal(ui.groomingSessionFields[0].value, 'Walk-in');
ui.detailsBooking = session;
assert.equal(ui.groomingSessionFields.map(field => field.label).join(','), 'Visit date,Entry source,Arrival window,Grooming started,Finished');
assert.equal(ui.groomingSessionFields.find(field => field.label === 'Entry source').value, 'Pre-registration');
ui.detailsBooking = repeat;
assert.equal(ui.groomingSessionFields.map(field => field.label).join(','), 'Visit date,Entry source');

const single = {
  startedAtIso: '2026-10-03T08:00:00+08:00', completedAtIso: '2026-10-03T09:00:00+08:00',
  pets: [{ groomingStartedAtIso: '2026-10-03T00:00:00.000Z', groomingFinishedAtIso: '2026-10-03T01:00:00.000Z' }],
};
assert.equal(ui.showSessionTime('start', single), false, 'The same event in different timezone notation appears once');
assert.equal(ui.showSessionTime('finish', single), false);
const staggered = { ...single, pets: [...single.pets, { groomingStartedAtIso: '2026-10-03T00:30:00Z', groomingFinishedAtIso: '2026-10-03T02:00:00Z' }] };
assert.equal(ui.showSessionTime('start', staggered), false);
assert.equal(ui.showSessionTime('finish', { ...staggered, completedAtIso: '2026-10-03T11:00:00+08:00' }), true, 'A distinct session event is retained');
assert.equal(ui.showSessionTime('finish', { ...single, completedAtIso: '2026-10-04T09:00:00+08:00' }), true, 'Equal clock times on different days are different events');
assert.equal(ui.showSessionTime('start', { startedAt: '8:00 AM', pets: [{ groomingStartedAt: '8:00 AM' }] }), false);
assert.equal(ui.showSessionTime('start', {}), false);
assert.equal(ui.petSize({ confirmedSize: 'medium', registeredSize: 'small' }), 'Medium · Clinic verified');
assert.equal(ui.petSize({ registeredSize: 'small', size: 'large', sizeVerified: true }), 'Small', 'Current profile verification must not relabel a legacy session');
assert.equal(ui.petSize({ size: 'large', sizeVerified: true }), 'Large · Profile size (session size not recorded)');
assert.equal(ui.petSize({}), 'Not recorded');
assert.equal(ui.showProductSubtotal({ quantity: 1, priceAtSale: '75.00', subtotal: 75 }), false);
assert.equal(ui.showProductSubtotal({ quantity: 2, priceAtSale: 75, subtotal: 120 }), true, 'Retain the recorded subtotal instead of multiplying current prices');
assert.equal(ui.showProductSubtotal({ quantity: 1, priceAtSale: 75, subtotal: 50 }), true);
assert.equal(ui.productAddonsTotal(session), 150);
assert.equal(ui.productAddonsTotal(repeat), 0);
assert.equal(ui.transactionUrl(session), './transactions.html?payment=10&reference=BAC-20261003-0001');
assert.equal(ui.transactionUrl({ ...session, payment: {} }), './transactions.html?reference=BAC-20261003-0001');
assert.equal(ui.transactionUrl({}), '');

let lastFocus;
const focusTarget = (name) => ({ focus: () => { lastFocus = name; }, getClientRects: () => [1] });
const close = focusTarget('close'), body = focusTarget('body'), link = focusTarget('link');
ui.$refs.groomingRecordDialog = { querySelectorAll: () => [close, body, link] };
let prevented = 0;
ui.trapDetailsFocus({ target: close, shiftKey: true, preventDefault: () => prevented++ });
assert.equal(lastFocus, 'link');
ui.trapDetailsFocus({ target: link, shiftKey: false, preventDefault: () => prevented++ });
assert.equal(lastFocus, 'close');
ui.trapDetailsFocus({ target: body, shiftKey: false, preventDefault: () => prevented++ });
assert.equal(prevented, 2, 'Tab can reach the transaction link normally');

(async () => {
  const consentUi = context.adminArchive();
  consentUi.$refs = { signedAgreement: { open: true } };
  let resolveFirst;
  context.API = { getGroomingSignedConsent: id => id === 1
    ? new Promise(resolve => { resolveFirst = resolve; })
    : Promise.resolve({ consent: { booking_id: 2, grooming_consent: true, sedation_consent: false,
      signer_name: 'Representative', signed_at: '2026-10-16T08:06:00Z' } }) };
  const firstRequest = consentUi.loadSignedConsent(1);
  assert.equal(consentUi.$refs.signedAgreement.open, false, 'Agreement starts collapsed for every record');
  await consentUi.loadSignedConsent(2);
  resolveFirst({ consent: { booking_id: 1, sedation_consent: true } });
  await firstRequest;
  assert.equal(consentUi.signedConsent.booking_id, 2, 'A slow previous request cannot show a different booking’s signature');
  assert.equal(consentUi.consentLabel('grooming'), 'Agreed');
  assert.equal(consentUi.consentLabel('sedation'), 'Not authorized');
  assert.equal(consentUi.consentSignedAt(), 'Oct 16, 2026 · 4:06 PM');
  consentUi.signedConsent.sedation_consent = true;
  assert.equal(consentUi.consentLabel('sedation'), 'Authorized');
  consentUi.signedConsent = null;
  assert.equal(consentUi.consentLabel('sedation'), 'Not recorded');
  assert.equal(consentUi.consentLabel('grooming'), 'Not recorded');
  assert.equal(consentUi.consentSignedAt(), 'Not recorded');
  context.API.getGroomingSignedConsent = async () => { throw new Error('Offline'); };
  await consentUi.loadSignedConsent(2);
  assert.equal(consentUi.consentError, 'Unable to load consent.');
  assert.equal(consentUi.consentLoading, false);

  let saved, restoredScroll, focused = 0;
  context.window.history = { state: { otherState: 'preserved' }, replaceState: (state) => { saved = state; context.window.history.state = state; } };
  context.window.scrollY = 420;
  context.window.addEventListener = () => {};
  context.window.removeEventListener = () => {};
  context.window.scrollTo = (x, y) => { restoredScroll = y; };
  context.document = { body: { style: { overflow: '' } }, querySelector: (selector) => selector.includes('data-history-booking') ? { focus: () => focused++ } : dialog };
  context.API = { loadAdminData: (key,load,render) => load().then(data=>{render(data);return data;}), getArchivedBookings: async (query) => {
    assert.equal(query.search, 'Owner');
    return { archived: [session, repeat], total: 2 };
  } };
  ui.viewDetails(session);
  ui.searchQuery = 'Owner'; ui.sortOrder = 'oldest'; ui.selectedYear = '2026'; ui.selectedMonth = '2026-10';
  ui.$refs.groomingRecordBody = { scrollTop: 300 };
  ui.rememberHistoryContext({ button: 0, ctrlKey: true });
  assert.equal(saved, undefined, 'Opening a new tab does not change this History entry');
  ui.rememberHistoryContext({ button: 0 });
  assert.equal(saved.otherState, 'preserved');
  assert.equal(saved.groomingHistory.modalScroll, 300);
  const restored = context.adminArchive();
  restored.$nextTick = (callback) => callback();
  restored.$refs = { groomingRecordBody: modalBody, groomingRecordClose: { focus() {} } };
  await restored.init();
  assert.equal(restored.searchQuery, 'Owner');
  assert.equal(restored.sortOrder, 'oldest');
  assert.equal(restored.selectedMonth, '2026-10');
  assert.equal(restored.detailsBooking.id, 1);
  assert.equal(restored.detailsModalOpen, true);
  assert.equal(restoredScroll, 420);
  assert.equal(restored.$refs.groomingRecordBody.scrollTop, 300);
  restored.closeDetails();
  assert.equal(focused, 1, 'Restored modal returns focus to its original record');
  // A page restored from the browser's back/forward cache does not run init again.
  context.window.history.state = { groomingHistory: { searchQuery: 'Owner', sortOrder: 'oldest', selectedYear: '2026', selectedMonth: '2026-10', bookingId: 1, scrollY: 420, modalScroll: 300 } };
  restored._restoreOnPageShow({ persisted: true });
  assert.equal(restored.detailsModalOpen, true);
  assert.equal(restored.$refs.groomingRecordBody.scrollTop, 300);
  console.log('Grooming history regression tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });

assert.equal(ui.paymentMethod({payment: {paymentMethod: 'qrph', paymentMethodLabel: 'Bank Transfer'}}), 'Bank Transfer');
assert.equal(ui.paymentMethod({payment: {paymentMethod: 'qrph', paymentMethodLabel: 'PayPal'}}), 'PayPal');
assert.equal(ui.paymentMethod({payment: {paymentMethod: 'qrph'}}), 'QR Payment');
