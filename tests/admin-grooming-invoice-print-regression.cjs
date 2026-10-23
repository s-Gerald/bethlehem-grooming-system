const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/cash-payment.js"), "utf8"), context);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/payment-invoice.js"), "utf8"), context);
vm.runInNewContext(source, context);
const ui = context.adminDashboard();

assert.equal(ui.formatInvoicePeso(4620), "₱4,620.00");
assert.equal(ui.formatInvoicePeso("1050.5"), "₱1,050.50");
assert.equal(ui.formatInvoicePeso(0), "₱0.00");
assert.equal(ui.formatPeso(4620), "₱4620.00", "Shared screen formatting stays unchanged");
assert.equal(ui.formatInvoiceDate("Oct 3, 2026 12:39 AM"), "Oct 3, 2026");
assert.equal(ui.formatInvoiceDate("unavailable recorded timestamp"), "unavailable recorded timestamp");
assert.equal(ui.formatInvoiceDate(""), "", "Do not invent a timestamp");

const product = { itemName: "Royal Canin Fit 32", quantity: 2, subtotal: 2320 };
const originalProduct = JSON.stringify(product);
assert.equal(ui.formatInvoiceProductUnitPrice(product), "₱1,160.00");
assert.equal(ui.formatInvoiceProductUnitPrice({ quantity: 3, subtotal: 100 }), "₱33.33");
for (const quantity of [0, -1, "invalid", Infinity, undefined]) {
  assert.equal(ui.formatInvoiceProductUnitPrice({ quantity, subtotal: 2320 }), "—");
}
assert.equal(JSON.stringify(product), originalProduct, "Unit price formatting must not mutate product records");

const html = fs.readFileSync(path.join(__dirname, "../pages/admin/appointments.html"), "utf8");
assert.ok(html.includes('x-html="window.PaymentInvoice.template"'), "Grooming uses the shared invoice template");
const invoice = context.window.PaymentInvoice.template;
assert.ok(invoice.includes('Grooming reference'));
assert.doesNotMatch(invoice, /Booking reference|Appointment/i);
const settledPayment = { finalPrice: 850, amountPaid: 1000, change: 150 };
for (const [label, expected] of [
  ["Invoice total", "₱850.00"],
  ["Amount paid", "₱850.00"],
  ["Balance due", "₱0.00"],
  ["Cash received", "₱1,000.00"],
  ["Change", "₱150.00"],
]) {
  const expression = invoice.match(new RegExp(`<dt>${label}</dt><dd x-text="([^"]+)"`))[1];
  assert.equal(
    new Function("receiptModal", "formatInvoicePeso", `return ${expression}`)(settledPayment, ui.formatInvoicePeso),
    expected,
    `${label} must use its recorded financial meaning`,
  );
}

const printingClasses = new Set();
const listeners = new Map();
let printed = 0;
let removed = 0;
let fallback;
let appended;
const clone = {
  setAttribute: (name) => assert.equal(name, "x-ignore", "The print snapshot must not reinitialize Alpine bindings"),
  classList: { add: (value) => printingClasses.add(value) },
  remove: () => removed++,
};
context.document = {
  getElementById(id) {
    assert.equal(id, "paymentInvoice");
    return { cloneNode(deep) { assert.equal(deep, true); return clone; } };
  },
  body: {
    appendChild: (value) => { appended = value; },
    classList: { add: (value) => printingClasses.add(value), remove: (value) => printingClasses.delete(value) },
  },
};
Object.assign(context.window, {
  addEventListener: (name, callback) => listeners.set(name, callback),
  removeEventListener: (name) => listeners.delete(name),
  print() {
    assert.equal(appended, clone, "Print the recorded invoice clone");
    assert.ok(printingClasses.has("payment-invoice-printing"));
    assert.ok(printingClasses.has("payment-invoice-print-clone"));
    printed++;
  },
  setTimeout: (callback) => { fallback = callback; },
});
ui.printReceipt();
listeners.get("afterprint")();
fallback();
assert.equal(printed, 1);
assert.equal(removed, 1, "Cleanup stays safe when both afterprint and the fallback run");
assert.ok(!printingClasses.has("payment-invoice-printing"));
assert.ok(!listeners.has("afterprint"));

ui.printReceipt();
fallback();
assert.equal(removed, 2, "Fallback cleans up when afterprint is unavailable");
assert.ok(!printingClasses.has("payment-invoice-printing"));
console.log("Admin grooming invoice print regression tests passed.");

(async () => {
  const {applyGroomingCatalogue} = await import('../scripts/services/grooming-service.js');
  context.applyPaymentCatalogue(applyGroomingCatalogue(JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8').replace(/^\uFEFF/, '')).data));
  ui.$nextTick = () => {};
  ui.loadAdminBookings = async () => {};
  ui.setTab = () => {};
  const submissions = [];
  context.API = {
    payNow: async (id, payload) => {submissions.push({id, payload, flow: 'early'}); return {final_price: 500, amount_paid: 600, change: 100, payment_method: 'cash', booking_status: 'checked_in'};},
    processPayment: async (id, payload) => {submissions.push({id, payload, flow: 'final'}); return {final_price: 500, amount_paid: 600, change: 100, payment_method: 'cash', booking_status: 'released'};},
  };
  const booking = {id: 1, ownerName: 'Cash Customer', bookingReference: 'TEST-CASH',
    pets: [{bookingPetId: 1, petName: 'Mochi', species: 'Cat', size: 'small'}],
    services: [{bookingServiceId: 1, bookingPetId: 1, slug: 'cat_full_grooming', priceAtBooking: '500.00'}]};
  for (const early of [true, false]) {
    ui.paymentModal = {...ui.paymentModal, open: true, booking, isEarlyPayment: early, busy: false, error: '',
      petBreakdown: ui.buildPaymentBreakdown(booking, {lockFixedPrices: true}), products: [], amountPaid: ''};
    assert.equal(ui.canSubmitPayment, false, 'Cash requires tendered money');
    ui.paymentModal.amountPaid = '600';
    assert.equal(ui.canSubmitPayment, true);
    const pending = ui.submitPayment();
    await ui.submitPayment();
    await pending;
    assert.equal(ui.receiptModal.paymentMethod, 'Cash');
    assert.equal(ui.receiptModal.change, 100);
    assert.equal(submissions.at(-1).payload.amount_paid, 600);
    assert.equal(submissions.at(-1).payload.payment_method, 'cash');
    assert.equal(submissions.at(-1).flow, early ? 'early' : 'final');
  }
  assert.equal(submissions.length, 2, 'Repeated clicks submit once per payment');
  const {createQrPayment} = await import('../scripts/components/qr-payment.js');
  global.API = {getUserRole: () => 'staff'};
  const methodQr = createQrPayment();
  methodQr.settings = {enabled: true, version: 2, accepted_methods: ['GCash', 'Maya', 'Bank Transfer']};
  let qrImageReads = 0;
  global.API.getQrPaymentImage = async () => {qrImageReads++; return {blob: new Blob(['official QR'])};};
  ui.paymentModal = {...ui.paymentModal, busy: false, paymentMethod: 'cash', qr: methodQr};
  ui.selectPaymentMethod('Unconfigured');
  assert.equal(ui.paymentModal.paymentMethod, 'cash');
  assert.equal(qrImageReads, 0, 'Unsupported choices never fetch the QR image');
  ui.selectPaymentMethod('Maya');
  await Promise.resolve();
  assert.equal(ui.paymentModal.paymentMethod, 'qrph');
  assert.equal(ui.paymentMethodLabel, 'Maya');
  methodQr.verified = true;
  ui.selectPaymentMethod('GCash');
  assert.equal(methodQr.verified, false, 'Changing the source requires renewed verification');
  assert.equal(methodQr.payload().payment_source, 'GCash');
  assert.equal(qrImageReads, 1, 'All configured methods reuse the same lazily loaded QR image');
  ui.selectPaymentMethod('cash');
  assert.equal(ui.paymentMethodLabel, 'Cash');
  assert.equal(methodQr.paymentSource, '');
  methodQr.dispose();
  for (const early of [true, false]) {
    const qr = createQrPayment();
    Object.assign(qr, {settings: {enabled: true, version: 2, accepted_methods: ['GCash', 'Maya', 'Bank Transfer']}, paymentSource: early ? 'Maya' : 'Bank Transfer', amount: '',
      externalReference: 'BANK123456', transactionAt: '2026-10-23T10:30', verified: false});
    ui.paymentModal = {...ui.paymentModal, open: true, booking, isEarlyPayment: early, busy: false, error: '',
      petBreakdown: ui.buildPaymentBreakdown(booking, {lockFixedPrices: true}), products: [], amountPaid: '', paymentMethod: 'qrph', qr};
    assert.equal(ui.canSubmitPayment, false, 'QR requires explicit verification');
    qr.verified = true;
    if (early) qr.receiptFile = new Blob(['synthetic proof'], {type: 'image/png'});
    assert.equal(ui.canSubmitPayment, true, 'Verified QR does not require Cash Received');
    context.API[early ? 'payNow' : 'processPayment'] = async (id, payload) => {
      submissions.push({id, payload});
      return {final_price: 500, amount_paid: 500, change: 0, payment_method: 'qrph', payment_method_label: early ? 'Maya' : 'Bank Transfer',
        qr_payment: {external_reference: 'BANK123456', verified_by_name: 'Staff Verifier', verified_at: '2026-10-23 10:31:00'}};
    };
    await ui.submitPayment();
    assert.equal(submissions.at(-1).payload.payment_method, 'qrph');
    assert.equal(submissions.at(-1).payload.amount_paid, 500);
    assert.equal(submissions.at(-1).payload.qr_verified, true);
    assert.equal(submissions.at(-1).payload.observed_amount, null);
    if (early) assert.equal(await submissions.at(-1).payload.receipt.text(), 'synthetic proof');
    assert.equal(Object.hasOwn(submissions.at(-1).payload, 'verification_source'), false, 'Early and final submissions never send a source choice');
    assert.equal(submissions.at(-1).payload.payment_source, early ? 'Maya' : 'Bank Transfer');
    assert.equal(ui.receiptModal.paymentMethod, early ? 'Maya' : 'Bank Transfer');
    assert.equal(ui.receiptModal.qrPayment.external_reference, 'BANK123456');
    const printCount = printed;
    ui.printReceipt();
    assert.equal(printed, printCount, 'QR success cannot print a Cash invoice');
  }
  const {environment} = require('./admin-data-cache-regression.cjs');
  const apiEnvironment = environment();
  apiEnvironment.c.Blob = Blob; apiEnvironment.c.FormData = FormData;
  const uploaded = new Blob(['synthetic receipt'], {type: 'image/png'});
  for (const method of ['processPayment', 'payNow']) {
    apiEnvironment.respond((endpoint, options) => {
      assert.match(endpoint, /bookings\/9\/pay/);
      assert.equal(options.body.get('service_prices[0][booking_service_id]'), '3');
      assert.equal(options.body.get('service_prices[0][amount]'), '850');
      assert.equal(options.body.get('qr_verified'), '1');
      assert.equal(options.body.get('observed_amount'), null);
      assert.equal(options.body.get('receipt').name, 'Payment proof.png');
      assert.equal(options.headers['Content-Type'], undefined, 'The browser supplies the multipart boundary');
      return {success: true};
    });
    await apiEnvironment.API[method](9, {receipt: uploaded, qr_verified: true, observed_amount: null,
      service_prices: [{booking_service_id: 3, amount: 850}]});
  }
  delete global.API;
})().catch(error => {console.error(error); process.exitCode = 1;});
