const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const context = { window: {}, Date, Intl, URLSearchParams, AbortController };
for (const file of ["payment-invoice.js", "admin-transactions.js"]) {
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components", file), "utf8"), context);
}
const ui = context.adminTransactions();
const tx = {
  id: 1, reference: "BAC-20261003-0002", ownerName: "Gerald Senining", petName: "Ocon",
  dateKey: "2026-10-03", paidAt: "2026-10-03 11:47:00", paymentMethod: "cash", paymentMethodLabel: "Cash",
  finalPrice: 2010, amountPaid: 3000, changeGiven: 990, groomingServicesTotal: 850, productAddonsTotal: 1160,
  productAddons: [{ itemName: "Royal Canin", quantity: 1, subtotal: 1160, priceAtSale: 1160 }],
  paymentSummary: { pets: [{ booking_pet_id: 11, pet_name: "Ocon", pet_species: "dog",
    final_pet_charge: null, original_pet_subtotal: 850,
    service_breakdown: [{ booking_service_id: 12, label: "Regular Dog Grooming", price_at_booking: 550 },
      { booking_service_id: 13, label: "Facial Trimming", price_at_booking: 150 },
      { booking_service_id: 14, label: "Anal Sac Draining", price_at_booking: 150 }],
  }] },
};
const before = JSON.stringify(tx);
assert.equal(ui.compactPetNames(tx), "Ocon");
assert.equal(ui.compactPetNames({ petName: "Hopper, Rigby, Ocon" }), "Hopper, Rigby, Ocon");
assert.equal(ui.compactPetNames({ petName: "Hopper, Rigby, Ocon, Buddy" }), "Hopper, Rigby +2 more");
assert.equal(ui.compactPetNames({}), "—");
// The payment summary is authoritative; duplicate names can belong to different pets.
assert.equal(ui.compactPetNames({ petName: "stale", paymentSummary: { pets: [{ pet_name: "Max" }, { pet_name: "Max" }] } }), "Max, Max");

let opened = 0, closed = 0, focused = 0, printed = 0;
ui.$nextTick = (callback) => callback();
ui.$refs = { detailsDialog: { showModal: () => opened++, close: () => closed++, querySelector: () => ui.$refs.detailsBody }, detailsBody: { scrollTop: 99 } };
context.document = { body: { style: { overflow: "auto" } } };
ui.openDetails(tx, { focus: () => focused++ });
assert.equal(opened, 1);
assert.equal(ui.$refs.detailsBody.scrollTop, 0);
assert.equal(context.document.body.style.overflow, "hidden");
assert.equal(ui.receiptModal.finalPrice, 2010, "Invoice total uses the recorded charge, not cash tendered");
assert.equal(ui.receiptModal.amountPaid, 3000);
assert.equal(ui.receiptModal.change, 990);
assert.equal(ui.receiptModal.pets[0].subtotal, 850, "Unfinished early-payment pet uses the existing service subtotal");
assert.equal(ui.receiptModal.pets[0].lines[0].price, 550);
assert.equal(ui.receiptModal.products[0].subtotal, 1160);
assert.equal(ui.receiptModal.bookingReference, tx.reference);
assert.equal(ui.receiptModal.contactNumber, undefined, "Do not invent missing contact information");
assert.equal(ui.formatInvoicePeso(tx.finalPrice), "₱2,010.00");
context.window.PaymentInvoice.print = () => { assert.equal(ui.selectedTransaction, tx); printed++; };
ui.printInvoice();
assert.equal(printed, 1);
assert.equal(JSON.stringify(tx), before, "Inspecting and printing must not mutate transaction data");
ui.closeDetails();
assert.equal(closed, 1);
assert.equal(focused, 1);
assert.equal(context.document.body.style.overflow, "auto");
assert.equal(ui.selectedTransaction, null);
ui.printInvoice();
assert.equal(printed, 1, "Printing requires a selected transaction");
const qrTransaction = { ...tx, id: 3, paymentMethod: 'qrph', paymentMethodLabel: 'QR Payment',
  qrPayment: {external_reference: 'BANK123456', verified_by_name: 'Staff Verifier', verified_at: '2026-10-23 10:00:00'} };
ui.openDetails(qrTransaction, {focus() {}});
assert.equal(ui.selectedTransaction.qrPayment.external_reference, 'BANK123456');
assert.equal(ui.receiptModal, null, 'New QR payments must not build printable invoices');
ui.printInvoice();
assert.equal(printed, 1, 'QR payments cannot print through the shared Cash invoice');
ui.closeDetails();
const legacyQr = {...tx, paymentMethod: 'qrph', paymentMethodLabel: 'QR Payment', isTest: true};
ui.openDetails(legacyQr, {focus() {}});
assert.equal(ui.receiptModal.finalPrice, 2010, 'Historical gateway records retain their existing invoice behavior');
context.window.PaymentInvoice.print = () => printed++;
ui.printInvoice();
assert.equal(printed, 2, 'Only the new verified QR record suppresses invoices');
ui.closeDetails();
const noProducts = { ...tx, id: 2, reference: "BAC-20261002-0002", dateKey: "2026-10-02", productAddons: [], finalPrice: 700 };
ui.openDetails(noProducts, { focus() {} });
assert.equal(ui.receiptModal.products.length, 0);
assert.equal(ui.receiptModal.bookingReference, noProducts.reference, "Opening another record replaces the invoice source");
ui.closeDetails();
ui.transactions = [tx, noProducts];
assert.equal(ui.collectionTotal, 2710);
assert.equal(ui.groupedTransactions[0].dateKey, "2026-10-03");
assert.equal(ui.groupedTransactions[0].total, 2010);
assert.equal(ui.groupedTransactions[1].total, 700);

const clinicTx = {
  id: 2, key: 'clinic-2', transactionType: 'clinic_payment', reference: 'CL-20261003-002',
  ownerName: 'Erwin Francisco', petName: 'Mark', dateKey: '2026-10-03', paidAt: '2026-10-03 14:00:00',
  paymentMethod: 'cash', paymentMethodLabel: 'Cash', processedByName: 'Admin Bethlehem',
  finalPrice: 725.50, amountPaid: 1000, changeGiven: 274.50,
  charges: [{id: 10, description: 'Consultation', quantity: '1.00', unit_price: '500.00', amount: '500.00'},
    {id: 11, description: 'Clinic supplies', quantity: '2.00', unit_price: '112.75', amount: '225.50'}],
};
const clinicBefore = JSON.stringify(clinicTx);
assert.equal(ui.transactionName(clinicTx), 'Erwin Francisco');
assert.equal(ui.transactionDetails(clinicTx), 'Mark');
ui.openDetails(clinicTx, {focus() {}});
assert.equal(ui.receiptModal.caseReference, clinicTx.reference);
assert.equal(ui.receiptModal.patientName, 'Mark');
assert.equal(ui.receiptModal.charges[1].amount, '225.50');
assert.equal(ui.receiptModal.processedBy, 'Admin Bethlehem');
assert.equal(ui.receiptModal.finalPrice, 725.50);
assert.equal(ui.receiptModal.amountPaid, 1000);
assert.equal(ui.receiptModal.pets, undefined);
assert.equal(ui.paymentChargeQuantity(clinicTx.charges[1]), '2');
context.window.PaymentInvoice.print = id => assert.equal(id, 'clinicPaymentInvoice');
ui.printInvoice();
assert.equal(JSON.stringify(clinicTx), clinicBefore);
ui.closeDetails();
assert.equal(ui.formatTransactionDate({paidAt: null, dateKey: 'unknown'}), '—');
ui.transactions = [{...clinicTx, dateKey: 'unknown', paidAt: null}, clinicTx];
assert.equal(ui.groupedTransactions[0].dateKey, '2026-10-03');
assert.equal(ui.groupedTransactions[1].dateKey, 'unknown');

const html = fs.readFileSync(path.join(__dirname, "../pages/admin/transactions.html"), "utf8");
const history = html.split('<!-- Compact history:')[1].split('</main>')[0].split('<dialog')[0];
for (const field of ["amountPaid", "changeGiven", "groomingServicesTotal", "productAddonsTotal", "service_breakdown", "<details"]) {
  assert.ok(!history.includes(field), `${field} must stay out of history rows`);
}
assert.ok(html.includes('x-html="window.PaymentInvoice.template"'));
assert.ok(html.includes('x-html="window.PaymentInvoice.clinicTemplate"'));
assert.ok(html.includes('x-show="Number(charge.quantity) > 1"'));
const clinicBreakdown = html.split('id="transaction-clinic-breakdown-title"')[1].split('</section>')[0];
assert.ok(clinicBreakdown.includes('charge.amount'));
assert.ok(!clinicBreakdown.includes('unit_price'));
const filters = ['All Transactions', 'Grooming Payments', 'Clinic Payments', 'Product Sales'];
assert.deepEqual([...filters].sort((a, b) => html.indexOf(a) - html.indexOf(b)), filters);
assert.ok(html.includes('selectedTransaction.productAddons.length > 1'));
assert.ok(!html.split('<!-- Transactions Content -->')[1].includes('data-lucide'));
(async () => {
  let request;
  context.API = { getTransactions: async (query) => { request = query; return { transactions: [tx, noProducts] }; } };
  context.document.querySelector = () => ({ focus() {} });
  const createPage = (search) => {
    context.window.location = { search };
    const page = context.adminTransactions();
    page.$nextTick = (callback) => callback(); page.$refs = ui.$refs;
    return page;
  };
  const direct = createPage('');
  await direct.init();
  assert.equal(request.search, '');
  assert.equal(request.period, 'day');
  assert.equal(direct.selectedTransaction, null, 'Sidebar navigation does not open a modal');
  const linked = createPage('?payment=2&reference=BAC-20261002-0002');
  await linked.init();
  assert.equal(request.search, noProducts.reference);
  assert.equal(request.date, '', 'Older payments must not be restricted to today');
  assert.equal(linked.selectedTransaction.id, 2);
  linked.closeDetails();
  const byReference = createPage('?reference=BAC-20261003-0002');
  await byReference.init();
  assert.equal(byReference.selectedTransaction.id, 1);
  byReference.closeDetails();
  const missingId = createPage('?payment=999&reference=BAC-20261003-0002');
  await missingId.init();
  assert.equal(missingId.selectedTransaction, null, 'A different payment with the same reference must not be substituted');
  assert.equal(missingId.errorMessage, 'The linked transaction could not be found.');
  context.API.getTransactions = async query => {request = query; return {transactions: [clinicTx, noProducts]};};
  const clinicLink = createPage('?clinic=2&reference=CL-20261003-002');
  await clinicLink.init();
  assert.equal(request.transaction_type, 'clinic_payment');
  assert.equal(request.search, clinicTx.reference);
  assert.equal(request.date, '');
  assert.equal(clinicLink.selectedTransaction, clinicTx, 'Clinic and grooming IDs may overlap');
  clinicLink.closeDetails();
  context.API.getTransactions = async () => ({transactions: [clinicTx]});
  const groomingLink = createPage('?payment=2&reference=CL-20261003-002');
  await groomingLink.init();
  assert.equal(groomingLink.selectedTransaction, null, 'A clinic appointment cannot substitute for a grooming payment');
  await proofChecks();
  console.log('Admin transaction details regression tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });

assert.match(context.window.PaymentInvoice.formatPaymentDateTime('2026-10-22T17:30:00Z'), /Oct 23, 2026.*1:30 AM/);
assert.match(context.window.PaymentInvoice.formatPaymentDateTime('2026-10-23 09:15:00'), /Oct 23, 2026.*9:15 AM/);
async function proofChecks() {
  context.URL = URL;
  let reads = 0;
  context.API = {getQrPaymentProof: async id => { reads++; assert.equal(id, 19); return {blob: new Blob(['receipt'], {type: 'image/png'})}; }};
  const proofTx = {...qrTransaction, qrPayment: {...qrTransaction.qrPayment, id: 19, proof: {name: 'receipt.png', mime: 'image/png'}}};
  ui.openDetails(proofTx, {focus() {}});
  assert.equal(reads, 0, 'Opening transaction details never downloads the receipt');
  await ui.viewPaymentProof();
  assert.equal(reads, 1);
  const url = ui.proofUrl;
  assert.match(url, /^blob:/);
  await ui.viewPaymentProof();
  assert.equal(reads, 1, 'Repeated view reuses the current private preview');
  ui.closeDetails();
  assert.equal(ui.proofUrl, '');
  await assert.rejects(fetch(url), /fetch failed/, 'Closing transaction details releases the attachment');
  let resolve;
  context.API.getQrPaymentProof = () => new Promise(done => { resolve = done; });
  ui.openDetails(proofTx, {focus() {}});
  const pending = ui.viewPaymentProof();
  ui.closeDetails();
  resolve({blob: new Blob(['late'], {type: 'image/png'})});
  await pending;
  assert.equal(ui.proofUrl, '', 'A late proof fetch cannot reopen a closed detail view');
}
