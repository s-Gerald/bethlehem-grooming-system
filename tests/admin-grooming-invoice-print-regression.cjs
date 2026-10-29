const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: {}, console, Date, Intl, clearTimeout };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/utils/philippine-mobile.js'), 'utf8'), context);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/cash-payment.js"), "utf8"), context);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/recorded-product-pricing.js'), 'utf8'), context);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/payment-invoice.js"), "utf8"), context);
vm.runInNewContext(source, context);
const ui = context.adminDashboard();
const selected = [1, 2, 3].map(item_id => ({item_id, item_name: `Product ${item_id}`, is_active: true,
  category: 'pet_shop', unit: 'pack', selling_price: 45, saleable_quantity: 4}));
selected.forEach(item => ui.addPaymentProduct(item));
const selectedOrder = () => Array.from(ui.paymentModal.products, line => line.item_id);
assert.deepEqual(selectedOrder(), [3, 2, 1]);
const firstSelected = ui.paymentModal.products[2];
ui.addPaymentProduct(selected[0]);
assert.deepEqual(selectedOrder(), [1, 3, 2]);
assert.equal(ui.paymentModal.products[0], firstSelected);
assert.equal(firstSelected.quantity, 2);
ui.addPaymentProduct({...selected[1], saleable_quantity: 1});
assert.deepEqual(selectedOrder(), [1, 3, 2]);
assert.equal(ui.paymentModal.products[2].quantity, 1);
ui.changePaymentProductQuantity(ui.paymentModal.products[1], 1);
assert.deepEqual(selectedOrder(), [1, 3, 2]);
assert.equal(ui.paymentProductsTotal, 225);
ui.removePaymentProduct(1);
assert.deepEqual(selectedOrder(), [1, 2]);
ui.paymentModal.products = [];
assert.equal(ui.formatMobileNumber('09171234567'), '0917 123 4567');
assert.equal(ui.formatMobileNumber('08971234567'), '0897 123 4567');
assert.equal(ui.formatMobileNumber('09031234567'), '09031234567', 'Legacy contacts outside the policy are not rewritten.');
assert.equal(ui.formatMobileNumber(null), '—');

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
assert.equal(ui.formatInvoiceProductUnitPrice({ quantity: 3, subtotal: 100 }), "—", 'An indivisible cent total cannot establish a legacy unit price');
for (const [line, expected] of [
  [{priceAtSale: '45.00', quantity: 2, subtotal: 89.99, selling_price: 55}, '₱45.00'],
  [{price_at_sale: '80.00', quantity: 1, subtotal: 80}, '₱80.00'],
  [{priceAtSale: '33.33', quantity: 3, subtotal: 100}, '₱33.33'],
  [{quantity: 3, subtotal: '0.30'}, '₱0.10'],
  [{quantity: 2, subtotal: '90.01'}, '—'],
  [{quantity: 1, subtotal: null}, '—'],
  [{quantity: 1, subtotal: ''}, '—'],
  [{quantity: 2, subtotal: 0}, '₱0.00'],
  [{priceAtSale: 'invalid', quantity: 2, subtotal: 90}, '—'],
  [{priceAtSale: -1, quantity: 2, subtotal: 90}, '—'],
]) assert.equal(ui.formatInvoiceProductUnitPrice(line), expected);
for (const quantity of [0, -1, "invalid", Infinity, undefined]) {
  assert.equal(ui.formatInvoiceProductUnitPrice({ quantity, subtotal: 2320 }), "—");
}
assert.equal(JSON.stringify(product), originalProduct, "Unit price formatting must not mutate product records");

const html = fs.readFileSync(path.join(__dirname, "../pages/admin/appointments.html"), "utf8");
assert.ok(html.includes('x-html="window.PaymentInvoice.template"'), "Grooming uses the shared invoice template");
const invoice = context.window.PaymentInvoice.template;
const servicesTable = invoice.split('<h2>Grooming Services</h2>')[1].split('</table>')[0];
assert.deepEqual([...servicesTable.matchAll(/<th scope="col">([^<]+)<\/th>/g)].map(match => match[1]), ['Description', 'Amount']);
assert.equal([...servicesTable.matchAll(/<td\b/g)].length, 2, 'Each grooming service has one description and recorded charge');
const productsTable = invoice.split('<h2>Product Add-ons</h2>')[1].split('</table>')[0];
assert.deepEqual([...productsTable.matchAll(/<th scope="col">([^<]+)<\/th>/g)].map(match => match[1].toLowerCase()), ['description', 'qty', 'unit price', 'amount']);
const successProducts = html.split('<p class="receipt-success-group-title">Product Add-ons</p>')[1].split('</template>')[0];
const priceQuantity = successProducts.match(/<small[^>]*x-text="([^"]+)"/)[1];
const lineTotal = successProducts.match(/<strong x-text="([^"]+)"/)[1];
for (const [line, detail, total] of [
  [{itemName: 'Shampoo', priceAtSale: '80.00', quantity: 1, subtotal: '80.00'}, '₱80.00 × 1', '₱80.00'],
  [{itemName: 'Treats', priceAtSale: '45.00', quantity: 2, subtotal: '89.99'}, '₱45.00 × 2', '₱89.99'],
  [{quantity: 3, subtotal: '100.00'}, '— × 3', '₱100.00'],
]) {
  assert.equal(vm.runInNewContext(priceQuantity, {window: context.window, line}), detail);
  assert.equal(vm.runInNewContext(lineTotal, {window: context.window, line}), total);
}
// Evaluate the actual Alpine text binding (or static text) against recorded snapshots.
function renderedText(markup, attribute, value, bindings) {
  const tag = markup.match(new RegExp(`<[^>]*${attribute}="${value}"[^>]*>[^<]*</[^>]+>`))[0];
  const expression = tag.match(/x-text="([^"]+)"/);
  return expression
    ? new Function(...Object.keys(bindings), `return ${expression[1]}`)(...Object.values(bindings))
    : tag.replace(/<[^>]+>/g, '');
}
context.document = {currentScript: {src: 'http://localhost/scripts/components/product-sale-invoice.js'}};
context.URL = URL;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/product-sale-invoice.js'), 'utf8'), context);
const clinicHtml = fs.readFileSync(path.join(__dirname, '../pages/admin/clinic.html'), 'utf8');
for (const template of [invoice, context.window.PaymentInvoice.clinicTemplate]) {
  const expression = template.match(/<p x-show="receiptModal.contactNumber" x-text="([^"]+)"/)[1];
  assert.equal(new Function('window', 'receiptModal', `return ${expression}`)(context.window, {contactNumber:'09171234567'}), '0917 123 4567');
  assert.equal(new Function('window', 'receiptModal', `return ${expression}`)(context.window, {contactNumber:'08971234567'}), '0897 123 4567');
}
for (const [source, variable] of [['row.owner?.phone', 'row'], ['profile.owner?.phone', 'profile']]) {
  const expression = [...clinicHtml.matchAll(/x-text="([^"]+)"/g)].map(match => match[1]).find(value => value.includes(source));
  assert.equal(new Function('window', variable, `return ${expression}`)(context.window, {owner:{phone:'09181234567'}}), '0918 123 4567');
}
const posHtml = fs.readFileSync(path.join(__dirname, '../pages/admin/inventory/sections/sell-product.html'), 'utf8');
for (const [isTest, expected] of [[true, 'Paid · Test'], [false, 'Paid'], [undefined, 'Paid']]) {
  for (const template of [invoice, context.window.PaymentInvoice.clinicTemplate]) {
    assert.equal(renderedText(template, 'class', 'payment-invoice-status', {receiptModal: {isTest}}), expected);
  }
  assert.equal(renderedText(context.window.ProductSaleInvoice.template, 'class', 'payment-invoice-status', {receipt: {is_test: isTest}}), expected);
  assert.equal(renderedText(html, 'class', 'receipt-success-title', {receiptModal: {isTest}}), 'Payment recorded');
  assert.equal(renderedText(html, 'class', 'receipt-success-label', {receiptModal: {isTest}}), 'Amount Paid');
  assert.equal(renderedText(clinicHtml, 'id', 'clinic-payment-recorded-title', {receiptModal: {isTest}}), 'Payment recorded');
  assert.equal(renderedText(clinicHtml, 'class', 'receipt-success-label', {receiptModal: {isTest}}), 'Amount Paid');
  assert.equal(renderedText(posHtml, 'id', 'pos-success-title', {receipt: {is_test: isTest}}), 'Sale completed');
  assert.equal(renderedText(posHtml.split('class="pos-success-content"')[1], 'class', 'pos-helper', {receipt: {is_test: isTest}}), 'Amount Paid');
}
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

async function verifyInvoiceLayouts() {
  // Fixture-only browser: no localhost navigation, accounts, or payment submission.
  const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
  const alpineResponse = await fetch('https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js');
  assert.equal(alpineResponse.ok, true);
  const alpine = await alpineResponse.text();
  const output = path.join(__dirname, '../storage/app/invoice-pricing-qa');
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {})});
  try {
    let page;
    const snapshot = {bookingReference: 'WI-PRINT-FIXTURE', ownerName: 'Invoice fixture', paidAt: 'Oct 29, 2026 10:00 AM',
      paymentMethod: 'Cash', amountPaid: 302000, change: 980.03, finalPrice: 301019.97, groomingSubtotal: 850,
      productsSubtotal: 300169.97, pets: [{id: 1, name: 'Mochi', species: 'Cat',
        lines: [{id: 1, name: 'Cat Full Grooming', price: 500}, {id: 2, name: 'Bath and Blow Dry', price: 350}], subtotal: 850}],
      products: [{itemName: 'Pedigree Adult Wet Dog Food Pouch 130g', quantity: 2, priceAtSale: '45.00', subtotal: '90.00'},
        {itemName: 'Dog Shampoo', quantity: 1, priceAtSale: '80.00', subtotal: '80.00'},
        {itemName: 'Premium cat food with a deliberately long recorded product name and package description 85g', quantity: 3, priceAtSale: '99999.99', subtotal: '299999.97'}]};
    const scripts = ['recorded-product-pricing.js', 'payment-invoice.js'].map(file => fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'));
    const mount = async (markup, css, receipt) => {
      await page?.close();
      page = await browser.newPage();
      await page.route('**/*', route => route.abort());
      await page.setContent(`<style>${css}</style><main class="payment-invoice-print-clone" x-data="invoiceFixture()">${markup}</main>`);
      for (const script of scripts) await page.addScriptTag({content: script});
      await page.addScriptTag({content: `window.invoiceFixture = () => ({receiptModal: ${JSON.stringify(receipt)}, formatInvoiceDate: window.PaymentInvoice.formatDate, formatInvoicePeso: window.PaymentInvoice.formatPeso, formatInvoiceProductUnitPrice: window.PaymentInvoice.formatProductUnitPrice});`});
      await page.addScriptTag({content: alpine});
      await page.waitForFunction(count => document.querySelectorAll('.payment-invoice-products tbody tr, .receipt-success-service small').length === count, receipt.products.length);
    };
    const printCss = fs.readFileSync(path.join(__dirname, '../css/components/print-information.css'), 'utf8');
    for (const paper of ['A4', 'Letter']) {
      for (const long of [false, true]) {
        const receipt = structuredClone(snapshot);
        if (long) receipt.products = Array.from({length: 45}, (_, index) => ({itemName: `Product row ${index + 1} with a long recorded package description`, quantity: 2, priceAtSale: '45.00', subtotal: '90.00'}));
        if (long) { receipt.productsSubtotal = 4050; receipt.finalPrice = 4900; receipt.amountPaid = 5000; receipt.change = 100; }
        await mount('<div x-html="window.PaymentInvoice.template"></div>', printCss + `@media print {@page grooming-invoice {size:${paper} portrait;margin:12mm}}`, receipt);
        await page.evaluate(() => document.body.className = 'payment-invoice-printing');
        await page.emulateMedia({media: 'print'});
        await page.locator('.payment-invoice-logo').evaluate((image, logo) => image.src = logo, 'data:image/png;base64,' + fs.readFileSync(path.join(__dirname, '../assets/images/clinic/Bethlehem_Logo-256.png')).toString('base64'));
        const serviceHeaders = await page.locator('.payment-invoice-services-table th').allTextContents();
        assert.deepEqual(serviceHeaders, ['Description', 'Amount']);
        assert.equal(await page.locator('.payment-invoice-products tbody tr').count(), receipt.products.length);
        assert.equal(await page.evaluate(() => [...document.querySelectorAll('.payment-invoice-table tr')].every(row => row.scrollWidth <= row.clientWidth + 1)), true, `${paper}: table rows fit`);
        await page.pdf({path: path.join(output, `${paper.toLowerCase()}-${long ? 'long' : 'short'}.pdf`), preferCSSPageSize: true, printBackground: true, displayHeaderFooter: false});
      }
    }
    const start = html.indexOf('<div x-show="receiptModal.products.length > 0" class="receipt-success-group">');
    const end = html.indexOf('<div x-show="receiptModal.pets.length > 1 || receiptModal.products.length > 1"', start);
    const screenCss = fs.readFileSync(path.join(__dirname, '../css/output.css'), 'utf8') + fs.readFileSync(path.join(__dirname, '../css/custom.css'), 'utf8');
    await page.emulateMedia({media: 'screen'});
    await mount(html.slice(start, end), screenCss + 'main{max-width:620px;margin:auto;padding:20px}', snapshot);
    for (const width of [320, 375, 414, 768]) {
      await page.setViewportSize({width, height: 850});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${width}: no horizontal overflow`);
      assert.ok((await page.locator('.receipt-success-service small').allTextContents()).includes('₱80.00 × 1'));
      if (width === 375 || width === 768) await page.screenshot({path: path.join(output, `products-${width}.png`), fullPage: true});
    }
    console.log('Invoice A4/Letter and 320/375/414/768px product layouts passed.');
  } finally { await browser.close(); }
}

(async () => {
  const {applyGroomingCatalogue} = await import('../scripts/services/grooming-service.js?grooming=actual-20261026&registration=fcfs-20261027-v1');
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
  for (const early of [true, false]) {
    ui.paymentModal = {...ui.paymentModal, open: true, booking, isEarlyPayment: early, busy: false, error: '',
      petBreakdown: ui.buildPaymentBreakdown(booking, {lockFixedPrices: true}), products: [],
      paymentMethod: 'cash', amountPaid: '600'};
    for (const method of ['qrph', 'GCash', 'Maya', 'Bank Transfer']) {
      ui.selectPaymentMethod(method);
      assert.equal(ui.paymentModal.paymentMethod, 'cash', 'Legacy sources are never selectable');
    }
    ui.paymentModal.paymentMethod = 'qrph';
    const before = submissions.length;
    assert.equal(ui.canSubmitPayment, false);
    await ui.submitPayment();
    assert.equal(submissions.length, before, 'Stale QR state never submits');
    assert.ok(ui.paymentModal.error);
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
  if (process.argv.includes('--layout')) await verifyInvoiceLayouts();
})().catch(error => {console.error(error); process.exitCode = 1;});
