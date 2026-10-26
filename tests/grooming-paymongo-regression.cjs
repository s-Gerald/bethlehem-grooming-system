const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const timers = new Map();
let nextTimer = 0;
const calls = [];
let status = { success: true, online_payment: { id: 1, status: 'pending', amount: '500.00', flow: 'early', checkout_url: 'https://checkout.paymongo.com/cs_fixture' } };
let createError = null;
let releaseCreate = null;
let holdCreate = false;
const storage = (entries = []) => {
  const values = new Map(entries);
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, String(value)), removeItem: key => values.delete(key) };
};
const context = {
  console, Date, Intl, URL, URLSearchParams,
  window: { addEventListener() {}, removeEventListener() {}, sessionStorage: storage(), history: {replaceState() {}},
    location: { href: 'http://localhost:5173/pages/admin/appointments.html', assign() {}, protocol: 'http:', hostname: 'localhost', port: '5173', origin: 'http://localhost:5173', pathname: '/tests' } },
  localStorage: storage([['admin_token', 'test-admin-session'], ['user_role', 'admin']]),
  sessionStorage: storage(),
  setTimeout: (callback) => { timers.set(++nextTimer, callback); return nextTimer; },
  clearTimeout: (id) => timers.delete(id),
  fetch: async (url, options) => {
    const payload = options.body ? JSON.parse(options.body) : null;
    calls.push({ url, method: options.method, id: Number(/bookings\/(\d+)/.exec(url)[1]), payload });
    assert.equal(options.headers.Authorization, 'Bearer test-admin-session');
    let data = status;
    let code = 200;
    if (options.method === 'POST' && url.endsWith('/online-payment')) {
      if (holdCreate) await new Promise(resolve => { releaseCreate = resolve; });
      if (createError) { data = createError.data; code = createError.status; }
    } else if (options.method === 'DELETE') {
      data = { success: true, online_payment: { ...status.online_payment, status: 'cancelled' } };
    } else if (options.method === 'POST') {
      data = { final_price: 500, amount_paid: 600, change: 100, payment_method: 'cash', booking_status: url.endsWith('/pay-now') ? 'checked_in' : 'released' };
    }
    return { ok: code < 400, status: code, json: async () => data };
  },
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(`${__dirname}/../scripts/api.js`, 'utf8'), context);
for (const file of ['cash-payment.js', 'online-payment.js', 'admin-dashboard.js']) {
  vm.runInContext(fs.readFileSync(`${__dirname}/../scripts/components/${file}`, 'utf8'), context, {
    importModuleDynamically: async specifier => {
      assert.match(specifier, /^\.\.\/services\/grooming-service\.js/, 'Opening payments only imports the existing pricing catalogue');
      return import(require('node:url').pathToFileURL(`${__dirname}/../scripts/services/grooming-service.js`).href);
    },
  });
}

async function main() {
  const { applyGroomingCatalogue } = await import('../scripts/services/grooming-service.js?grooming=actual-20261026');
  const catalogue = JSON.parse(fs.readFileSync(`${__dirname}/grooming-catalogue-fixture.json`, 'utf8').replace(/^\uFEFF/, '')).data;
  context.applyPaymentCatalogue(applyGroomingCatalogue(catalogue));
  const ui = context.adminDashboard();
  ui.$nextTick = () => {};
  ui.loadAdminBookings = async () => {};
  ui.setTab = () => {};
  const booking = { id: 1, ownerName: 'Test Customer', bookingReference: 'TEST-ONLINE',
    pets: [{ bookingPetId: 1, petName: 'Mochi', species: 'Cat', size: 'small' }],
    services: [{ bookingServiceId: 1, bookingPetId: 1, slug: 'cat_full_grooming', priceAtBooking: '500.00' }] };
  const prepare = (method) => {
    ui.paymentModal = { ...ui.paymentModal, open: true, booking, isEarlyPayment: true, busy: false, error: '',
      petBreakdown: ui.buildPaymentBreakdown(booking, { lockFixedPrices: true }), products: [],
      paymentMethod: method, amountPaid: '', onlinePayment: null };
  };
  for (const early of [true, false]) {
    prepare('cash');
    ui.paymentModal.isEarlyPayment = early;
    for (const unsupported of ['qrph', 'GCash', 'Maya', 'Bank Transfer', 'unknown']) {
      ui.selectPaymentMethod(unsupported);
      assert.equal(ui.paymentModal.paymentMethod, 'cash');
    }
    ui.selectPaymentMethod('online');
    assert.equal(ui.paymentMethodLabel, 'Online Payment');
    ui.selectPaymentMethod('qrph');
    assert.equal(ui.paymentModal.paymentMethod, 'online');
    ui.paymentModal.paymentMethod = 'qrph';
    ui.paymentModal.amountPaid = '600';
    const before = calls.length;
    assert.equal(ui.canSubmitPayment, false);
    await ui.submitPayment();
    assert.equal(calls.length, before, 'Stale manual QR state cannot submit either flow');
  }
  prepare('cash');
  assert.equal(ui.canSubmitPayment, false, 'Cash still requires tendered money');
  ui.paymentModal.amountPaid = '600';
  assert.equal(ui.canSubmitPayment, true);
  await ui.submitPayment();
  assert.equal(ui.receiptModal.paymentMethod, 'Cash');
  assert.equal(ui.receiptModal.change, 100);
  assert.equal(calls.findLast(call => call.method === 'POST').payload.amount_paid, 600);

  prepare('online');
  assert.equal(ui.paymentModal.error, '', 'Selecting online payment has no default configuration error');
  assert.equal(ui.canSubmitPayment, true, 'Online payment never requires amount tendered');
  await ui.submitPayment();
  assert.ok(calls.findLast(call => call.method === 'POST').url.endsWith('/admin/bookings/1/online-payment'));
  assert.equal(calls.findLast(call => call.method === 'POST').payload.final_price, 500);
  assert.equal(calls.findLast(call => call.method === 'POST').payload.flow, 'early');
  assert.deepEqual(calls.findLast(call => call.method === 'POST').payload.service_prices, [{ booking_service_id: 1, amount: 500 }]);
  assert.deepEqual(calls.findLast(call => call.method === 'POST').payload.pet_sizes, [{ booking_pet_id: 1, size: 'small' }]);
  assert.equal(calls.findLast(call => call.method === 'POST').payload.payment_method, 'online');
  assert.equal('amount_paid' in calls.findLast(call => call.method === 'POST').payload, false);
  assert.equal(ui.paymentModal.onlinePayment.status, 'pending');
  assert.equal(ui.canSubmitPayment, false, 'Active checkout prevents another payment');
  assert.equal(timers.size, 1);
  await ui.cancelOnlinePayment();
  assert.equal(ui.paymentModal.onlinePayment, null);
  assert.equal(timers.size, 0);

  prepare('online');
  createError = { status: 422, data: { message: 'The given data was invalid.', errors: { payment: ['Online payment is not configured.'] } } };
  status = { success: true, online_payment: null };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, 'Online payment is not configured.', 'Show the actual backend payment error');
  assert.equal(ui.paymentModal.busy, false);
  assert.equal(ui.paymentModal.paymentMethod, 'online', 'An online error never silently switches to Cash');
  assert.equal(ui.canSubmitPayment, true, 'A failed online request can be retried without changing the form');
  ui.paymentModal.petBreakdown[0].lines[0].amount = '';
  assert.equal(ui.canSubmitPayment, false, 'Retrying still requires valid current service prices');
  ui.paymentModal.petBreakdown[0].lines[0].amount = '500.00';
  createError = null;
  status = { success: true, online_payment: { id: 2, status: 'pending', amount: '500.00', flow: 'early', checkout_url: 'https://checkout.paymongo.com/cs_retry' } };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, '', 'Successful creation clears the previous error');
  assert.equal(ui.paymentModal.onlinePayment.checkout_url, 'https://checkout.paymongo.com/cs_retry');
  await ui.cancelOnlinePayment();

  prepare('online');
  ui.paymentModal.booking = { ...booking, id: 27 };
  ui.paymentModal.isEarlyPayment = false;
  ui.paymentModal.petBreakdown[0].bookingPetId = 42;
  ui.paymentModal.petBreakdown[0].sizeKey = 'medium';
  ui.updatePaymentPetSize(ui.paymentModal.petBreakdown[0]);
  ui.paymentModal.petBreakdown[0].lines[0].bookingServiceId = 81;
  ui.paymentModal.products = [{ item_id: 91, name: 'Selected product', price: 125.50, quantity: 2 }];
  ui.paymentModal.notes = 'Current visit notes';
  status = { success: true, online_payment: { id: 3, status: 'pending', amount: '851.00', flow: 'final', checkout_url: 'https://checkout.paymongo.com/cs_another_booking' } };
  holdCreate = true;
  const creating = ui.submitPayment();
  assert.equal(ui.paymentModal.busy, true);
  assert.equal(ui.canSubmitPayment, false);
  const requestCount = calls.length;
  await ui.submitPayment();
  assert.equal(calls.length, requestCount, 'Duplicate submission does not issue another request');
  assert.equal(calls.findLast(call => call.method === 'POST').id, 27);
  assert.equal(calls.findLast(call => call.method === 'POST').payload.final_price, 851);
  assert.equal(calls.findLast(call => call.method === 'POST').payload.flow, 'final');
  assert.equal(calls.findLast(call => call.method === 'POST').payload.notes, 'Current visit notes');
  assert.deepEqual(calls.findLast(call => call.method === 'POST').payload.service_prices, [{ booking_service_id: 81, amount: 600 }]);
  assert.deepEqual(calls.findLast(call => call.method === 'POST').payload.pet_sizes, [{ booking_pet_id: 42, size: 'medium' }]);
  assert.deepEqual(calls.findLast(call => call.method === 'POST').payload.products, [{ item_id: 91, quantity: 2 }]);
  releaseCreate();
  await creating;
  holdCreate = false;
  assert.equal(ui.paymentModal.busy, false);
  assert.equal(ui.paymentModal.onlinePayment.checkout_url, 'https://checkout.paymongo.com/cs_another_booking');
  await ui.cancelOnlinePayment();

  prepare('online');
  createError = { status: 503, data: { message: 'Payment provider temporarily unavailable.' } };
  status = { success: true, online_payment: null };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, 'Payment provider temporarily unavailable.');
  assert.equal(ui.canSubmitPayment, true);
  createError = null;
  status = { success: true, online_payment: null };
  await ui.submitPayment();
  assert.ok(ui.paymentModal.error, 'A response without a checkout must not silently succeed');

  status = { success: false, message: 'Checkout is unavailable for this visit.', online_payment: null };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, 'Checkout is unavailable for this visit.');

  createError = { status: 502, data: { message: 'Unable to create online payment. Try again.' } };
  status = { success: true, online_payment: { id: 4, status: 'pending', amount: '500.00', flow: 'early', checkout_url: 'https://checkout.paymongo.com/cs_recovered' } };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, '', 'A checkout recovered by status clears the failed create response');
  assert.equal(ui.paymentModal.onlinePayment.checkout_url, 'https://checkout.paymongo.com/cs_recovered');
  assert.equal(ui.canSubmitPayment, false, 'Recovered checkout remains locked against another charge');
  createError = null;
  await ui.cancelOnlinePayment();

  prepare('online');
  createError = { status: 502, data: { message: 'Unable to create online payment. Try again.' } };
  status = { success: true, online_payment: { id: 5, status: 'pending', amount: '500.00', flow: 'early', checkout_url: null, reconciliation_reason: 'checkout_creation_unconfirmed' } };
  await ui.submitPayment();
  assert.equal(ui.paymentModal.error, 'Checkout creation needs reconciliation. Contact an administrator.');
  assert.equal(ui.canSubmitPayment, false, 'An unconfirmed backend attempt stays locked against duplicate charges');
  createError = null;
  ui.closePaymentModal();

  prepare('cash');
  ui.paymentModal.isEarlyPayment = false;
  ui.paymentModal.amountPaid = '600';
  await ui.submitPayment();
  assert.ok(calls.findLast(call => call.method === 'POST').url.endsWith('/admin/bookings/1/pay'));
  assert.equal(calls.findLast(call => call.method === 'POST').payload.amount_paid, 600);
  assert.equal(ui.receiptModal.paymentMethod, 'Cash');
  assert.equal(ui.receiptModal.change, 100);

  prepare('online');
  status = { success: true, online_payment: { id: 1, status: 'pending', amount: '500.00', flow: 'early', checkout_url: 'https://checkout.paymongo.com/cs_fixture' } };

  await ui.submitPayment();
  ui.closePaymentModal();
  assert.equal(timers.size, 0, 'Closing the modal stops polling');
  assert.equal(ui.paymentModal.open, false);

  prepare('online');
  await ui.submitPayment();
  status = { online_payment: { ...status.online_payment, status: 'paid' }, payment: {
    final_price: '500.00', amount_paid: null, change: null, payment_method: 'online', payment_method_label: 'Online Payment',
    product_addons: [], paid_at: 'Oct 16, 2026 1:00 PM', booking_status: 'checked_in',
  } };
  const callback = [...timers.values()][0];
  timers.clear();
  await callback();
  assert.equal(ui.paymentModal.open, false);
  assert.equal(ui.receiptModal.open, true);
  assert.equal(ui.receiptModal.paymentMethod, 'Online Payment');
  assert.equal(ui.receiptModal.amountPaid, null);
  assert.equal(ui.receiptModal.change, null);
  assert.equal(timers.size, 0, 'Paid webhook status stops polling');
  prepare('cash');
  await ui.applyOnlinePaymentStatus(status);
  assert.equal(ui.receiptModal.paymentMethod, 'Online Payment', 'Reopening a completed checkout restores the recorded method');
  assert.equal(ui.receiptModal.amountPaid, null);
  assert.equal(ui.receiptModal.change, null);

  for (const isTest of [true, false]) {
    prepare('online');
    await ui.applyOnlinePaymentStatus({...status, payment: {...status.payment, is_test: isTest}});
    assert.equal(ui.receiptModal.isTest, isTest);
    assert.equal(ui.receiptModal.paymentMethod, 'Online Payment');
    assert.equal(ui.receiptModal.finalPrice, 500);
  }

  status = { online_payment: { ...status.online_payment, status: 'pending' } };
  prepare('online');
  await ui.submitPayment();
  let resolveStalePoll;
  const originalStatus = context.API.groomingOnlinePaymentStatus;
  context.API.groomingOnlinePaymentStatus = () => new Promise((resolve) => { resolveStalePoll = resolve; });
  const staleCallback = [...timers.values()][0];
  timers.clear();
  const inFlightPoll = staleCallback();
  await ui.cancelOnlinePayment();
  resolveStalePoll(status);
  await inFlightPoll;
  assert.equal(ui.paymentModal.onlinePayment, null, 'An old pending poll cannot revive a cancelled checkout');
  assert.equal(timers.size, 0);

  prepare('online');
  await ui.applyOnlinePaymentStatus({online_payment: {id: 99, status: 'pending', amount: '660.00', flow: 'early'},
    checkout_summary: {pets: [{booking_pet_id: 1, pet_name: 'Mochi', pet_species: 'Cat', confirmed_size: 'small',
      service_breakdown: [{booking_service_id: 1, label: 'Frozen Grooming', price_at_booking: '500.00'}]}]},
    checkout_products: [{item_id: 10, name: 'Frozen Treats', quantity: 2, priceCents: 8000}],
  });
  assert.equal(ui.paymentTotalDue, 660, 'Refresh uses the frozen checkout total');
  assert.equal(ui.paymentModal.petBreakdown[0].lines[0].name, 'Frozen Grooming');
  assert.equal(ui.paymentModal.products[0].price, 80);
  assert.equal(ui.canSubmitPayment, false);
  await ui.applyOnlinePaymentStatus({online_payment: {status: 'cancelled'}});
  assert.equal(ui.paymentModal.products.length, 0, 'Confirmed cancellation restores the editable draft');
  assert.equal(ui.paymentTotalDue, 500);
  ui.closePaymentModal();

  context.API.groomingOnlinePaymentStatus = originalStatus;
  status = {success: true, online_payment: null};
  global.API = {adminRequest: async () => JSON.parse(fs.readFileSync(`${__dirname}/grooming-catalogue-fixture.json`, 'utf8').replace(/^\uFEFF/, ''))};
  for (const early of [true, false]) {
    ui.closePaymentModal();
    const before = calls.length;
    await ui.openPaymentModal(booking, early);
    assert.equal(ui.paymentModal.open, true);
    assert.equal(ui.paymentModal.error, '');
    assert.equal(ui.paymentModal.isEarlyPayment, early);
    assert.equal(ui.paymentModal.paymentMethod, 'cash');
    assert.equal(calls.length, before + 1, 'Opening only checks the existing online checkout status');
    ui.selectPaymentMethod('online');
    ui.selectPaymentMethod('cash');
    assert.equal(calls.length, before + 1, 'Changing methods makes no QR/config/image requests');
    assert.equal(timers.size, 0, 'No manual QR background resources are initialized');
  }
  delete global.API;
  ui.closePaymentModal();
  const html = fs.readFileSync(`${__dirname}/../pages/admin/appointments.html`, 'utf8');
  const modal = html.slice(html.indexOf('<!-- Payment Modal -->'), html.indexOf('<!-- Grooming product barcode scanner -->'));
  assert.doesNotMatch(modal, /QR Ph|Coming soon|iframe/i);
  assert.match(modal, /Create Online Payment/);
  assert.match(modal, /Confirm ' \+ paymentMethodLabel \+ ' Payment/);
  const activeMethodValues = [...modal.matchAll(/name="grooming-payment-method" value="([^"]+)"/g)].map(match => match[1]);
  assert.deepEqual(activeMethodValues, ['cash', 'online']);
  assert.match(modal, /:disabled="paymentModal.busy \|\| !!paymentModal.onlinePayment"/);
  console.log('Grooming Cash and PayMongo UI regression tests passed.');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
