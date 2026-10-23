const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let onlineCalls = 0, cashCalls = 0, opened = '';
const context = {URL, URLSearchParams, Date, Intl, console, setTimeout: () => 1, clearTimeout() {},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  document: {body: {style: {}}, getElementById: () => null},
  window: {location: {href: 'https://bethlehem.example/pages/admin/clinic.html', assign: url => {opened = url;}},
    sessionStorage: {getItem: () => null, setItem() {}, removeItem() {}}, history: {replaceState() {}},
    dispatchEvent() {}, crypto: {randomUUID: () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'}},
  API: {createClinicOnlinePayment: async () => {onlineCalls++; return {online_payment: {id: 2, status: 'pending', amount: '500.00', checkout_url: 'https://checkout.paymongo.com/cs_clinic'}};},
    clinicOnlinePaymentStatus: async () => ({online_payment: {id: 2, status: 'pending', amount: '500.00'}}),
    clinicRecordPayment: async () => {cashCalls++; throw new Error('Cash endpoint must not be called');}},
  InventoryAPI: {validateCart: async () => ({data: [{item_id: 1, item_name: 'Treats', category: 'pet_shop', unit: 'pack', is_active: true, selling_price: 80, saleable_quantity: 3}]}),
    createOnlinePayment: async body => {onlineCalls++; assert.equal(body.payment_method, 'online'); assert.equal(body.amount_tendered, undefined); return {online_payment: {id: 3, status: 'pending', amount: '160.00', checkout_url: 'https://checkout.paymongo.com/cs_pos'}};},
    onlinePaymentStatus: async () => ({online_payment: {id: 3, flow: 'online', status: 'pending', amount: '160.00'}}),
    processSale: async () => {cashCalls++;}},
};
for (const name of ['cash-payment', 'online-payment', 'admin-clinic', 'admin-pos']) vm.runInNewContext(fs.readFileSync(`scripts/components/${name}.js`, 'utf8'), context);
(async () => {
  const clinic = context.adminClinicPage();
  clinic.$store = {clinicAccess: {permissions: {payment: true}}}; clinic.$nextTick = fn => fn();
  clinic.payment = {open: true, case: {id: 1, total_amount: 500}, method: 'online', amountPaid: '', saving: false, recorded: false};
  assert.equal(clinic.canConfirmPayment, true, 'Online payment does not require cash');
  await clinic.recordPayment();
  assert.equal(onlineCalls, 1); assert.equal(cashCalls, 0); assert.equal(opened, 'https://checkout.paymongo.com/cs_clinic');
  assert.equal(clinic.payment.recorded, false);
  await clinic.applyClinicOnlineStatus({online_payment: {id: 2, status: 'paid'}, appointment: {id: 1, total_amount: 500, payment_method: 'online', payment_method_label: 'Online Payment · GCash', paid: true, status: 'completed'}});
  assert.equal(clinic.payment.recorded, true); assert.equal(clinic.receiptModal.paymentMethod, 'Online Payment · GCash');
  const pos = context.adminPos(); pos.$nextTick = fn => fn(); pos.$refs = {};
  pos.cart = [{item_id: 1, item_name: 'Treats', unit: 'pack', price: 80, stock: 3, quantity: 2, subtotal: 160}]; pos.paymentMethod = 'online';
  assert.equal(pos.canProcess, true);
  await pos.processSale();
  assert.equal(opened, 'https://checkout.paymongo.com/cs_pos'); assert.equal(cashCalls, 0);
  assert.equal(pos.receiptOpen, false); assert.equal(pos.cart.length, 1);
  await pos.processSale(); assert.equal(onlineCalls, 2, 'Pending payment prevents duplicate submissions');
  let receipts = 0; pos.$refs.successDialog = {showModal() {receipts++;}};
  await pos.applyPosOnlineStatus({online_payment: {id: 3, status: 'paid'}, receipt: {pos_id: 8, payment_method: 'online', payment_method_label: 'Online Payment · QR Ph', total_amount: 160}});
  assert.equal(pos.receiptOpen, true); assert.equal(receipts, 1); assert.equal(pos.cart.length, 0);
  assert.equal(pos.receipt.payment_method, 'online');
  await pos.applyPosOnlineStatus({online_payment: {id: 4, status: 'expired'}, cart: [{item_id: 1, name: 'Treats', unit: 'pack', quantity: 2, price_at_sale: 80, subtotal: 160}]});
  assert.equal(pos.cart.length, 1, 'An expired return restores its cart for review');
  // Inventory defers component initialization until its section becomes visible.
  let restores = 0;
  context.window.adminPos = () => ({init: async () => {restores++;}, searchQuery: '', error: '', closeSuccess() {}});
  context.window.location.hash = '#sell-product';
  vm.runInNewContext(fs.readFileSync('scripts/components/admin-inventory.js', 'utf8'), context);
  const section = context.adminInventorySection('sell-product');
  await section.activate({section: 'sell-product'}, 0);
  assert.equal(restores, 1, 'Opening the real Inventory section must recover pending payments');
  await section.activate({section: 'sell-product'}, 0);
  assert.equal(restores, 1, 'Cash carts do not refetch payment state on every activation');
  console.log('Clinic and POS online payment flows passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
