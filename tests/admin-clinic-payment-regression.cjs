const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const events = [], submissions = [], prints = [];
const recorded = {
  id: 4, status: 'completed', paid: true, appointment_reference: 'CL-20261015-004',
  ownerName: 'Recorded Owner', pet: {name: 'Recorded Patient', species: 'Dog', breed: 'Mixed'},
  total_amount: '350.50', payment_method: 'cash', cash_received: '500.00', change: 149.50,
  paid_at: '2026-10-15T03:00:00+00:00', processed_by: {name: 'Grace · Clinic Receptionist'},
  charges: [{id: 1, description: 'Clinic consultation', quantity: 1, unit_price: '350.50', amount: '350.50'}],
};
let fail = false, complete;
const context = {
  console, Intl, Date,
  document: {activeElement: null, body: {style: {overflow: 'auto'}}, getElementById: () => null, addEventListener() {}},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {dispatchEvent: event => events.push(event)},
  API: {clinicRecordPayment: async (id, payload) => {
    submissions.push({id, payload});
    await new Promise(resolve => {complete = resolve;});
    if (fail) throw {errors: {total_amount: ['Charges changed.']}};
    return {appointment: structuredClone(recorded)};
  }},
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
for (const file of ['cash-payment.js', 'payment-invoice.js', 'admin-clinic.js']) {
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), context);
}
context.window.PaymentInvoice.print = id => prints.push(id);
function page() {
  const state = context.adminClinicPage();
  state.$store = {clinicAccess: {permissions: {payment: true}}, clinicFeedback: {}};
  state.$nextTick = callback => callback();
  state.loadActiveCases = async () => true;
  state.activeCases = [{...recorded, paid: false, status: 'for_payment', ownerName: 'Old owner', charges: []}];
  return state;
}
(async () => {
  const state = page();
  assert.equal(state.forPaymentCount, 1);
  state.reviewPayment(state.activeCases[0]);
  assert.equal(context.document.body.style.overflow, 'hidden');
  assert.equal(state.canConfirmPayment, false);
  for (const invalid of ['', '0', '-1', '350.49', '350.501', 'abc', '2000.01', '1000000']) {
    state.payment.amountPaid = invalid;
    assert.equal(state.canConfirmPayment, false, invalid);
    assert.equal(state.paymentChange, 0);
  }
  state.payment.amountPaid = '500';
  assert.equal(state.paymentChange, 149.5);
  assert.equal(state.canConfirmPayment, true);
  const pending = state.recordPayment();
  assert.equal(state.canConfirmPayment, false);
  await state.recordPayment();
  state.closePayment();
  assert.equal(state.payment.open, true, 'Saving cannot be dismissed');
  assert.equal(submissions.length, 1, 'Saving cannot be submitted twice');
  assert.deepEqual(JSON.parse(JSON.stringify(submissions[0].payload)), {total_amount: '350.50', payment_method: 'cash', amount_paid: 500});
  complete(); await pending;
  assert.equal(state.payment.open, true, 'Success persists until Done');
  assert.equal(state.payment.recorded, true);
  assert.equal(state.forPaymentCount, 0);
  assert.equal(state.receiptModal.ownerName, 'Recorded Owner');
  assert.equal(state.receiptModal.processedBy, 'Grace · Clinic Receptionist');
  assert.equal(state.receiptModal.charges[0].description, 'Clinic consultation');
  state.payment.amountPaid = '999';
  assert.equal(state.receiptModal.amountPaid, '500.00', 'Receipt is independent of the form');
  state.printClinicInvoice();
  assert.deepEqual(prints, ['clinicPaymentInvoice']);
  await state.recordPayment();
  assert.equal(submissions.length, 1, 'Success cannot be submitted again');
  state.closePayment();
  assert.equal(context.document.body.style.overflow, 'auto');

  const retry = page();
  retry.reviewPayment(retry.activeCases[0]); retry.payment.amountPaid = '500';
  fail = true;
  const rejected = retry.recordPayment(); complete(); await rejected;
  assert.equal(retry.payment.open, true);
  assert.equal(retry.payment.recorded, false);
  assert.equal(retry.payment.error, 'Charges changed.');
  assert.equal(retry.payment.saving, false);

  const zero = page();
  zero.reviewPayment({...recorded, status: 'for_payment', total_amount: 0});
  zero.payment.amountPaid = '0';
  assert.equal(zero.canConfirmPayment, true, 'Zero-charge case can still complete');
  zero.$store.clinicAccess.permissions.payment = false;
  assert.equal(zero.canConfirmPayment, false);

  const clinic = context.window.PaymentInvoice.clinicTemplate;
  for (const label of ['Case reference', 'Patient', 'Clinic Charges', 'Processed by', 'Invoice total', 'Balance due']) assert.ok(clinic.includes(label));
  for (const term of ['Grooming', 'Product Add-ons', 'bookingReference', 'pet_sizes', 'service_prices']) assert.ok(!clinic.includes(term), term);
  assert.ok(context.window.PaymentInvoice.template.includes('Grooming Services'));
  assert.ok(context.window.PaymentInvoice.template.includes('Product Add-ons'));
  console.log('Clinic payment regression tests passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
