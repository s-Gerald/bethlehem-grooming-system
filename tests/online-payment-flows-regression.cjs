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
for (const name of ['cash-payment', 'online-payment', 'payment-invoice', 'admin-clinic', 'admin-pos']) vm.runInNewContext(fs.readFileSync(`scripts/components/${name}.js`, 'utf8'), context);
(async () => {
  const clinic = context.adminClinicPage();
  clinic.$store = {clinicAccess: {permissions: {payment: true}}}; clinic.$nextTick = fn => fn();
  clinic.payment = {open: true, case: {id: 1, total_amount: 500}, method: 'online', amountPaid: '', saving: false, recorded: false};
  assert.equal(clinic.canConfirmPayment, true, 'Online payment does not require cash');
  await clinic.recordPayment();
  assert.equal(onlineCalls, 1); assert.equal(cashCalls, 0); assert.equal(opened, 'https://checkout.paymongo.com/cs_clinic');
  assert.equal(clinic.payment.recorded, false);
  await clinic.applyClinicOnlineStatus({online_payment: {id: 2, status: 'paid'}, appointment: {id: 1, total_amount: 500, provider_payment_id: 'pay_clinic_confirmed', payment_method: 'online', payment_method_label: 'Online Payment', paid: true, status: 'completed'}});
  assert.equal(clinic.payment.recorded, true); assert.equal(clinic.receiptModal.paymentMethod, 'Online Payment');
  assert.equal(clinic.receiptModal.providerPaymentId, 'pay_clinic_confirmed');
  const pos = context.adminPos(); pos.$nextTick = fn => fn(); pos.$refs = {};
  pos.cart = [{item_id: 1, item_name: 'Treats', unit: 'pack', price: 80, stock: 3, quantity: 2, subtotal: 160}]; pos.paymentMethod = 'online';
  assert.equal(pos.canProcess, true);
  await pos.processSale();
  assert.equal(opened, 'https://checkout.paymongo.com/cs_pos'); assert.equal(cashCalls, 0);
  assert.equal(pos.receiptOpen, false); assert.equal(pos.cart.length, 1);
  await pos.processSale(); assert.equal(onlineCalls, 2, 'Pending payment prevents duplicate submissions');
  let receipts = 0; pos.$refs.successDialog = {showModal() {receipts++;}};
  await pos.applyPosOnlineStatus({online_payment: {id: 3, status: 'paid'}, receipt: {pos_id: 8, payment_method: 'online', payment_method_label: 'Online Payment', total_amount: 160}});
  assert.equal(pos.receiptOpen, true); assert.equal(receipts, 1); assert.equal(pos.cart.length, 0);
  assert.equal(pos.receipt.payment_method, 'online');
  assert.equal(pos.receipt.payment_method_label, 'Online Payment');
  for (const isTest of [true, false]) {
    clinic.completeClinicPayment({id: 1, total_amount: 500, payment_method: 'online', payment_method_label: 'Online Payment', is_test: isTest});
    assert.equal(clinic.receiptModal.isTest, isTest);
    assert.equal(clinic.receiptModal.paymentMethod, 'Online Payment');
    await pos.applyPosOnlineStatus({online_payment: {id: 3, status: 'paid'}, receipt: {pos_id: 8, payment_method: 'online', payment_method_label: 'Online Payment', total_amount: 160, is_test: isTest}});
    assert.equal(pos.receipt.is_test, isTest);
    assert.equal(pos.receipt.total_amount, 160);
  }
  await pos.applyPosOnlineStatus({online_payment: {id: 4, status: 'expired'}, cart: [{item_id: 1, name: 'Treats', unit: 'pack', quantity: 2, price_at_sale: 80, subtotal: 160}]});
  assert.equal(pos.cart.length, 1, 'An expired return restores its cart for review');
  await testPosRecovery();
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

async function testPosRecovery() {
  const api = context.InventoryAPI;
  const helper = context.window.OnlinePayment;
  const original = {...api};
  const get = helper.get;
  const assign = context.window.location.assign;
  const makePos = () => {
    const ui = context.adminPos(); ui.$nextTick = fn => fn(); ui.$refs = {};
    ui.cart = [{item_id: 1, item_name: 'Treats', unit: 'pack', price: 80, stock: 3, quantity: 2, subtotal: 160}];
    ui.paymentMethod = 'online'; return ui;
  };
  const pending = {id: 3, status: 'pending', amount: '160.00', checkout_url: 'https://checkout.paymongo.com/cs_pos'};
  const cart = [{item_id: 1, name: 'Treats', unit: 'pack', quantity: 2, price_at_sale: 80, subtotal: 160}];
  try {
    const ordered = makePos();
    const extra = {...ordered.cart[0], item_id: 2, item_name: 'Shampoo', quantity: 1, subtotal: 80};
    ordered.cart.unshift(extra);
    const visible = ordered.cart;
    const savedCart = [...cart, {item_id: 2, name: 'Shampoo', unit: 'pack', quantity: 1, price_at_sale: 80, subtotal: 80}];
    await ordered.applyPosOnlineStatus({online_payment: pending, cart: savedCart});
    assert.equal(ordered.cart, visible, 'A server snapshot in another order must preserve the visible cart');
    assert.equal(ordered.cart[0], extra, 'Polling must retain newest-first row identity');
    assert.deepEqual(savedCart.map(line => line.item_id), [1, 2], 'Stored snapshots are not reordered');
    const ui = makePos(); const line = ui.cart[0];
    await ui.applyPosOnlineStatus({online_payment: pending, cart});
    assert.equal(ui.cart[0].stock, 3, 'Pending status must preserve the original cart stock and quantities');
    await ui.applyPosOnlineStatus({online_payment: pending, cart});
    assert.equal(ui.cart[0], line, 'Polling reuses the visible cart instead of rebuilding its inputs');
    assert.equal(ui.posPaymentTitle, 'Payment Pending');
    assert.equal(ui.posPaymentMessage, 'Waiting for payment confirmation.');
    ui.clearCart(); ui.removeFromCart(0); ui.addToCart({item_id: 1});
    assert.equal(ui.cart.length, 1); assert.equal(ui.canProcess, false);

    const interrupted = makePos(); let creates = 0;
    api.createOnlinePayment = async () => {creates++; throw Object.assign(new Error('Offline'), {status: 0});};
    api.onlinePaymentStatus = async () => {throw new Error('Offline');};
    await interrupted.processSale();
    assert.ok(interrupted.onlinePayment, 'An unknown checkout outcome must keep the POS locked');
    assert.equal(interrupted.canProcess, false);
    interrupted.clearCart(); await interrupted.processSale();
    assert.equal(creates, 1, 'Recovery cannot create another payment session');
    assert.equal(interrupted.cart.length, 1); assert.ok(interrupted.requestKey);
    assert.equal(interrupted.canOpenPosCheckout, false, 'Unknown attempts never offer an unusable checkout link');
    const requestKey = interrupted.requestKey;
    api.onlinePaymentStatus = async () => ({online_payment: null});
    await interrupted.checkPosPaymentStatus();
    assert.ok(interrupted.onlinePayment, 'A lookup racing an interrupted create must not unlock a second checkout');
    assert.equal(interrupted.requestKey, requestKey, 'A not-yet-visible attempt retains its recovery key');
    api.onlinePaymentStatus = async (id, key) => {
      assert.equal(key, interrupted.requestKey); return {online_payment: pending, cart};
    };
    await interrupted.checkPosPaymentStatus();
    assert.equal(interrupted.onlinePayment.id, 3);
    assert.equal(interrupted.cart[0].subtotal, 160);

    const restored = makePos(); restored.cart = [];
    helper.get = () => ({id: 3, requestKey: 'saved-key', outcome: 'success'});
    api.onlinePaymentStatus = async () => {throw new Error('Offline');};
    await restored.init();
    assert.ok(restored.onlinePayment, 'Failed refresh recovery must not unlock a new sale');
    assert.equal(restored.receiptOpen, false, 'A success return never settles the sale');
    api.onlinePaymentStatus = async () => ({online_payment: pending, cart, notes: 'Saved notes'});
    await restored.checkPosPaymentStatus();
    assert.equal(restored.cart[0].item_name, 'Treats'); assert.equal(restored.notes, 'Saved notes');
    assert.equal(restored.paymentMethod, 'online');

    api.cancelOnlinePayment = async () => {throw new Error('Still pending');};
    await restored.cancelPosOnlinePayment();
    assert.equal(restored.onlinePayment.status, 'pending', 'A failed cancellation keeps the reservation locked');
    assert.equal(restored.error, 'Still pending', 'Cancellation failures remain actionable inline');
    api.onlinePaymentStatus = async () => {throw new Error('Offline');};
    await restored.checkPosPaymentStatus();
    assert.match(restored.error, /Check Status/, 'Restarting polling must not erase the failed status feedback');
    api.cancelOnlinePayment = async () => ({online_payment: {...pending, status: 'cancelled'}, cart});
    await restored.cancelPosOnlinePayment();
    assert.equal(restored.onlinePayment, null); assert.equal(restored.cart.length, 1);
    assert.equal(restored.cart[0].quantity, 2);
    let validations = 0;
    api.validateCart = async () => {validations++; return {data: []};};
    await restored.processSale();
    assert.equal(validations, 1, 'The next checkout must revalidate released stock and pricing');
    assert.equal(creates, 1);

    const rejected = makePos();
    api.validateCart = original.validateCart;
    api.createOnlinePayment = async () => {throw Object.assign(new Error('Stock changed'), {status: 422});};
    api.onlinePaymentStatus = async () => ({online_payment: null});
    await rejected.processSale();
    assert.equal(rejected.onlinePayment, null, 'A verified rejected create with no attempt restores editing');
    assert.equal(rejected.requestKey, null); assert.equal(rejected.cart.length, 1);

    api.validateCart = original.validateCart; api.createOnlinePayment = original.createOnlinePayment;
    context.window.location.assign = () => {throw new Error('Navigation blocked');};
    const blocked = makePos();
    await blocked.processSale();
    assert.equal(blocked.onlinePayment.id, 3, 'A redirect failure retains the created checkout');
    assert.match(blocked.error, /Open Checkout/);
    assert.doesNotThrow(() => blocked.openPosCheckout(), 'Manual retry reports navigation errors inline');
    const reconciliation = makePos();
    await reconciliation.applyPosOnlineStatus({online_payment: {...pending, status: 'paid', reconciliation_reason: 'stock'}, cart});
    assert.equal(reconciliation.receiptOpen, false); assert.equal(reconciliation.cart.length, 1);
    assert.equal(reconciliation.canProcess, false);
    assert.equal(reconciliation.posPaymentTitle, 'Payment received');
    const unconfirmed = makePos();
    await unconfirmed.applyPosOnlineStatus({online_payment: {...pending, checkout_url: null, reconciliation_reason: 'checkout_creation_unconfirmed'}, cart});
    assert.equal(unconfirmed.canOpenPosCheckout, false); assert.equal(unconfirmed.posPaymentTitle, 'Checkout needs review');
    let checks = 0, finish;
    api.onlinePaymentStatus = () => {checks++; return new Promise(resolve => {finish = resolve;});};
    const first = unconfirmed.checkPosPaymentStatus();
    await unconfirmed.checkPosPaymentStatus();
    assert.equal(checks, 1, 'Repeated status actions cannot start overlapping requests');
    finish({online_payment: pending}); await first;
  } finally {
    Object.assign(api, original); helper.get = get; context.window.location.assign = assign;
  }
}
