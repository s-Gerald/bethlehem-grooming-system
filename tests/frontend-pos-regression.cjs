const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const read = file => fs.readFileSync(require('node:path').join(__dirname, '..', file), 'utf8');
const product = {item_id: 1, item_name: 'Shampoo', is_active: true, category: 'pet_shop', unit: 'bottle', selling_price: 250, saleable_quantity: 4};
const receipt = {pos_id: 1, reference: 'POS-000001', cashier_name: 'Recorded Cashier', total_amount: '500.00', amount_tendered: '1000.00', change_amount: '500.00', created_at: '2026-10-07T15:42:00+08:00', items: [{id: 1, item_name: 'Recorded Shampoo', quantity: '2.00', price_at_sale: '250.00', subtotal: '500.00'}]};
function setup(api = {}) {
  let timer, opened = 0, closed = 0, focused = 0;
  const context = { window: {location: {origin: 'http://localhost'}}, Intl, Date, URL, URLSearchParams,
    InventoryAPI: {validateCart: async () => ({data: [product]}), ...api}, setTimeout: callback => {timer = callback; return 1;}, clearTimeout: () => {timer = null;},
    document: { currentScript: {src: 'http://localhost/scripts/components/product-sale-invoice.js'}, body: {style: {overflow: 'auto'}} },
  };
  for (const file of ['product-search-dropdown.js', 'cash-payment.js', 'product-form.js', 'payment-invoice.js', 'product-sale-invoice.js', 'admin-pos.js', 'admin-transactions.js'])
    vm.runInNewContext(read('scripts/components/' + file), context);
  const ui = context.adminPos();
  ui.$nextTick = callback => callback();
  ui.$refs = { productSearch: {focus() {focused++;}}, successDialog: {showModal() {opened++;}, close() {closed++;}} };
  return {context, ui, runSearch: () => timer(), opened: () => opened, closed: () => closed, focused: () => focused};
}
function testNewestFirstProducts() {
  const {ui} = setup();
  const products = [1, 2, 3].map(item_id => ({...product, item_id, item_name: `Product ${item_id}`}));
  products.forEach(item => ui.addToCart(item));
  const order = () => Array.from(ui.cart, line => line.item_id);
  assert.deepEqual(order(), [3, 2, 1]);
  const first = ui.cart.find(line => line.item_id === 1);
  ui.addToCart(products[0]);
  assert.deepEqual(order(), [1, 3, 2]);
  assert.equal(ui.cart[0], first);
  assert.equal(first.quantity, 2);
  ui.addToCart({...products[1], saleable_quantity: 1});
  assert.deepEqual(order(), [1, 3, 2], 'A failed re-add leaves order unchanged');
  assert.equal(ui.cart[2].quantity, 1);
  ui.cart[2].quantity = 2; ui.updateLineSubtotal(ui.cart[2]);
  assert.deepEqual(order(), [1, 3, 2], 'Quantity edits do not reorder');
  assert.equal(ui.totalAmount, 1250);
  ui.removeFromCart(1);
  assert.deepEqual(order(), [1, 2]);
}
async function testCheckoutAndDuplicateGuard() {
  let calls = 0, validationCalls = 0, resolveValidation, resolveSale, submitted;
  const app = setup({validateCart: () => {validationCalls++; return new Promise(resolve => {resolveValidation = resolve;});},
    processSale: body => {calls++; submitted = body; return new Promise(resolve => {resolveSale = resolve;});} });
  const ui = app.ui;
  for (const changes of [{category: 'medicine'}, {category: 'vaccine'}, {is_active: false}, {selling_price: null}, {selling_price: 0}, {saleable_quantity: 0}]) ui.addToCart({...product, ...changes});
  assert.equal(ui.cart.length, 0);
  ui.addToCart(product);
  for (const quantity of [0, -1, 5, 1.02, 1.5, 2.75, 1.001, 'invalid']) {
    ui.cart[0].quantity = quantity; ui.updateLineSubtotal(ui.cart[0]); ui.amountTendered = '1000';
    assert.equal(ui.canProcess, false);
  }
  ui.cart[0].quantity = 2; ui.updateLineSubtotal(ui.cart[0]);
  ui.amountTendered = '100'; assert.equal(ui.canProcess, false);
  ui.amountTendered = '1000000'; assert.equal(ui.canProcess, false);
  ui.amountTendered = '1000'; assert.equal(ui.canProcess, true);
  const pending = ui.processSale();
  await ui.processSale();
  assert.equal(validationCalls, 1, 'Duplicate clicks during validation are blocked');
  assert.equal(calls, 0, 'No sale is submitted before validation');
  resolveValidation({data: [product]}); await new Promise(setImmediate);
  assert.equal(calls, 1, 'Double clicks create one request');
  assert.equal(ui.submitting, true);
  assert.equal(submitted.items[0].expected_price, 250);
  assert.equal(submitted.items[0].expected_stock, 4);
  assert.equal(ui.notice, '', 'Unchanged carts do not interrupt staff');
  assert.equal(submitted.total_amount, undefined);
  assert.equal(submitted.change_amount, undefined);
  assert.equal(submitted.items[0].price_at_sale, undefined);
  resolveSale({data: receipt}); await pending;
  assert.equal(ui.receipt, receipt);
  assert.equal(ui.cart.length, 0);
  assert.equal(app.opened(), 1);
  let printedId;
  app.context.window.PaymentInvoice.print = id => {printedId = id;};
  ui.printInvoice(); assert.equal(printedId, 'productSaleInvoice');
  await ui.processSale(); assert.equal(calls, 1);
  ui.newSale();
  assert.equal(ui.receipt, null); assert.equal(ui.receiptOpen, false);
  assert.equal(ui.amountTendered, ''); assert.equal(ui.notes, '');
}
async function testStaleSearchRefreshAndErrors() {
  let resolveOld;
  const app = setup({ searchItems: (query, inactive, context) => {
    assert.equal(inactive, true); assert.equal(context, 'pos');
    return query === 'old' ? new Promise(resolve => {resolveOld = resolve;}) : Promise.resolve({data: [product]});
  }, validateCart: async () => ({data: [{...product, selling_price: 300, saleable_quantity: 1}]}),
  processSale: async () => {throw Object.assign(new Error('Invalid'), {status: 422, errors: {items: ['Stock changed. Reduce quantity.']}});},
  });
  const ui = app.ui;
  ui.searchQuery = 'old'; ui.onSearchInput(); const old = app.runSearch();
  ui.searchQuery = 'new'; ui.onSearchInput(); await app.runSearch();
  resolveOld({data: []}); await old;
  assert.equal(ui.searchResults.length, 1, 'An older result cannot erase the current search');
  ui.addToCart(product); ui.cart[0].quantity = 2; ui.updateLineSubtotal(ui.cart[0]);
  ui.amountTendered = '1000'; await ui.processSale();
  assert.equal(ui.cart[0].quantity, 2, 'Do not silently reduce staff quantities');
  assert.match(ui.notice, /Shampoo: stock changed from 4 to 1 bottle; price changed from ₱250.00 to ₱300.00/);
  assert.match(ui.lineError(ui.cart[0]), /Reduce/);
  ui.cart[0].quantity = 1; ui.updateLineSubtotal(ui.cart[0]); ui.amountTendered = '500';
  await ui.processSale();
  assert.equal(ui.error, 'Stock changed. Reduce quantity.');
  assert.equal(ui.cart.length, 1); assert.equal(ui.submitting, false);
}
async function testChangedCartsRequireReviewAndCanThenComplete() {
  for (const changes of [{selling_price: 300}, {saleable_quantity: 3}, {selling_price: 300, saleable_quantity: 3}]) {
    let sales = 0;
    const current = {...product, ...changes};
    const {ui} = setup({validateCart: async () => ({data: [current]}), processSale: async () => {sales++; return {data: receipt};}});
    ui.addToCart(product); ui.amountTendered = '1000'; ui.notes = 'Keep notes';
    await ui.processSale();
    assert.equal(sales, 0); assert.equal(ui.receipt, null); assert.equal(ui.cart[0].quantity, 1);
    assert.equal(ui.notes, 'Keep notes'); assert.equal(ui.amountTendered, '1000');
    assert.match(ui.notice, /Shampoo:/); assert.match(ui.notice, /Review the updated cart/);
    assert.equal(ui.notice.includes('price changed'), 'selling_price' in changes);
    assert.equal(ui.notice.includes('stock changed'), 'saleable_quantity' in changes);
    await ui.processSale(); assert.equal(sales, 1, 'Only the next checkout attempt may complete');
  }
  const {ui} = setup({validateCart: async () => ({data: [{...product, is_active: false}]})});
  ui.addToCart(product); ui.amountTendered = '1000'; await ui.processSale();
  assert.match(ui.notice, /Shampoo: no longer available/); assert.equal(ui.canProcess, false);
}
async function testValidationRetryAndCheckoutRace() {
  let fail = true, sales = 0;
  const {ui} = setup({validateCart: async () => {
    if (fail) throw Object.assign(new Error('Offline'), {status: 0});
    return {data: [product]};
  }, processSale: async () => {sales++; throw Object.assign(new Error('Cart changed'), {
    status: 422, code: 'POS_CART_CHANGED', data: {code: 'POS_CART_CHANGED', data: [{...product, saleable_quantity: 3, selling_price: 275}]},
  });}});
  ui.addToCart(product); ui.amountTendered = '1000';
  await ui.processSale();
  assert.equal(sales, 0); assert.equal(ui.validationFailed, true); assert.equal(ui.canProcess, false);
  assert.match(ui.error, /Retry validation/);
  fail = false; await ui.revalidateCart();
  assert.equal(sales, 0, 'Retry validates only; it never submits a sale');
  assert.equal(ui.validationFailed, false); assert.equal(ui.canProcess, true); assert.equal(ui.notice, '');
  await ui.processSale();
  assert.equal(sales, 1); assert.equal(ui.validationFailed, false);
  assert.match(ui.notice, /stock changed/); assert.match(ui.notice, /price changed/);
  assert.equal(ui.cart[0].price, 275); assert.equal(ui.cart[0].stock, 3); assert.equal(ui.receipt, null);

  for (const status of [503, 403]) {
    const app = setup({validateCart: async () => {throw Object.assign(new Error('Blocked'), {status});}});
    app.ui.addToCart(product); app.ui.amountTendered = '1000'; await app.ui.processSale();
    assert.equal(app.ui.validationFailed, status === 503, 'Only network/server validation failures get Retry');
  }
  const app = setup({processSale: async () => {throw Object.assign(new Error('Offline'), {status: 0});}});
  app.ui.addToCart(product); app.ui.amountTendered = '1000'; await app.ui.processSale();
  assert.equal(app.ui.validationFailed, false, 'An uncertain checkout result must never offer validation Retry');
  assert.match(app.ui.error, /Check Records/);
}
function testCashPaymentParity() {
  const {ui, context} = setup();
  ui.amountTendered = '500'; assert.equal(ui.canProcess, false, 'Empty carts cannot complete a sale');
  assert.equal(ui.changeAmount, 0);
  ui.addToCart(product); ui.cart[0].quantity = 2; ui.updateLineSubtotal(ui.cart[0]);
  assert.equal(ui.paymentMaximumAmount, 2000);
  for (const [cash, change] of [['500', 0], ['500.01', 0.01], ['850.25', 350.25], ['1000', 500], ['2000', 1500]]) {
    ui.amountTendered = cash;
    assert.equal(ui.canProcess, true, cash); assert.equal(ui.changeAmount, change, cash);
  }
  for (const cash of ['', '0', '499.99', '-1', '500.001', '500.000', '2000.01', '999999', '9'.repeat(400), '1e10', 'NaN']) {
    ui.amountTendered = cash;
    assert.equal(ui.canProcess, false, cash); assert.equal(ui.changeAmount, 0, 'Invalid cash never calculates change');
  }
  ui.amountTendered = '499.99'; assert.equal(ui.cashError, 'Cash received is less than the amount due.');
  ui.amountTendered = '2000.01'; assert.equal(ui.cashError, 'Amount paid cannot exceed ₱2,000.00.');
  const event = {target: {value: '2000.01'}};
  ui.enforcePaymentAmountLimit(event);
  assert.equal(ui.amountTendered, '2000'); assert.equal(event.target.value, '2000');
  assert.equal(ui.changeAmount, 1500, 'Change uses the validated cap, never the excessive raw value');
  ui.amountTendered = '9'.repeat(100); ui.enforcePaymentAmountLimit();
  assert.equal(ui.amountTendered, '2000');
  ui.amountTendered = '9'.repeat(400); ui.enforcePaymentAmountLimit();
  assert.equal(ui.canProcess, false); assert.equal(ui.changeAmount, 0);
  ui.amountTendered = '500.001'; ui.enforcePaymentAmountLimit(); assert.match(ui.cashError, /decimal places/);
  for (const [total, maximum] of [[1600, 3000], [12755, 14000]])
    assert.equal(context.window.CashPayment.maximumFor(total), maximum);
}
async function testCameraScannerUsesExistingLookup() {
  let callback, starts = 0, stops = 0, lookup;
  const app = setup({findByBarcode: async (...args) => {lookup = args; return {data: product};}});
  app.context.requestAnimationFrame = callback => callback();
  app.context.Html5Qrcode = class {
    constructor(reader) {assert.equal(reader, 'pos-qr-reader');}
    start(constraints, config, decoded) {starts++; callback = decoded; return Promise.resolve();}
    stop() {stops++; return Promise.resolve();}
  };
  app.ui.openScanner(); app.ui.openScanner(); await Promise.resolve();
  assert.equal(starts, 1); assert.equal(app.ui.scannerActive, true);
  await callback('1234567890123');
  assert.deepEqual(lookup, ['1234567890123', false, 'pos']);
  assert.equal(stops, 1); assert.equal(app.ui.scannerActive, false); assert.equal(app.ui.cart.length, 1);
  await callback('1234567890123');
  assert.equal(app.ui.cart[0].quantity, 1, 'Repeated camera frames cannot add the product twice');
}
function testProductHistoryInvoice() {
  const app = setup();
  const page = app.context.adminTransactions();
  page.$nextTick = callback => callback();
  page.$refs = { detailsDialog: {showModal() {}, close() {}, querySelector: () => ({scrollTop: 10})} };
  const tx = {id: 1, key: 'pos-1', reference: receipt.reference, transactionType: 'product_sale', receipt, items: receipt.items, finalPrice: 500, amountPaid: 1000, changeGiven: 500};
  page.openDetails(tx, null);
  assert.equal(page.receiptModal, null, 'POS does not build a grooming/customer invoice');
  assert.equal(page.transactionName(tx), 'Product Sale');
  assert.equal(page.transactionDetails(tx), 'Recorded Shampoo ×2');
  let printedId;
  app.context.window.PaymentInvoice.print = id => {printedId = id;};
  page.printInvoice(); assert.equal(printedId, 'productSaleInvoice');
  page.transactions = [tx]; page.totalCount = 55; page.serverCollectionTotal = 2000; page.serverDateTotals = {'2026-10-07': 1500};
  assert.equal(page.collectionTotal, 2000); assert.equal(page.collectionCount, 55);
  const template = app.context.window.ProductSaleInvoice.template;
  assert.match(template, /receipt.items/); assert.match(template, /line.price_at_sale/);
  assert.doesNotMatch(template, /Bill to|ownerName|\bpet\b|phone|email|\bTIN\b|\bBIR\b|tax breakdown/i);
}
function testPaymentAndScannerIntegration() {
  const markup = read('pages/admin/inventory/sections/sell-product.html');
  assert.doesNotMatch(markup, /Add a product to enable checkout\./);
  assert.doesNotMatch(markup, /Refresh stock and prices|refreshCart/);
  assert.match(markup, /min="1" step="1"/);
  assert.match(markup, /x-show="validationFailed"/);
  assert.match(markup, /@click="openScanner\(\)" aria-label="Scan barcode"/);
  assert.match(markup, /phosphor\.svg(?:\?[^"#\s]+)?#barcode/);
  for (const page of ['appointments', 'dashboard']) {
    const html = read(`pages/admin/${page}.html`);
    assert.ok(html.indexOf('scripts/components/cash-payment.js') >= 0);
    assert.ok(html.indexOf('scripts/components/cash-payment.js') < html.indexOf('scripts/components/admin-dashboard.js'));
  }
}
async function testPaymentInputInteractions() {
  const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
  const path = require('node:path');
  const alpine = process.env.ALPINE_PATH ? fs.readFileSync(process.env.ALPINE_PATH, 'utf8')
    : await (await fetch('https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js', {signal: AbortSignal.timeout(15000)})).text();
  const browser = await chromium.launch({headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? {channel: process.env.PLAYWRIGHT_CHANNEL} : {})});
  try {
    for (const [workflow, file, id, change, canPay] of [
      ['grooming', 'pages/admin/appointments.html', 'grooming-cash-received', 'paymentChange', 'canSubmitPayment'],
      ['early', 'pages/admin/appointments.html', 'grooming-cash-received', 'paymentChange', 'canSubmitPayment'],
      ['clinic', 'pages/admin/clinic.html', 'clinic-cash-received', 'paymentChange', 'canConfirmPayment'],
      ['pos', 'pages/admin/inventory/sections/sell-product.html', 'pos-cash', 'changeAmount', 'canProcess'],
    ]) {
      const page = await browser.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      // Exercise the shipped input/bindings and real payment components without API calls.
      const inputTag = read(file).match(new RegExp(`<input\\b[^>]*\\bid="${id}"[^>]*>`))[0];
      await page.route('https://payment-input.example/', route => route.fulfill({contentType: 'text/html', body: `<style>#scroll{height:220px;overflow:auto}input{display:block;margin:20px;width:240px;height:40px}</style>
        <main data-money-input-root x-data="paymentFixture()"><div id="scroll">${inputTag}<div style="height:1200px"></div></div>
        <output id="change" x-text="${change}"></output><button id="confirm" :disabled="!${canPay}">Confirm</button></main>`}));
      await page.goto('https://payment-input.example/');
      await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
      for (const name of ['cash-payment', 'admin-dashboard', 'admin-clinic', 'admin-pos', 'inventory-money-input'])
        await page.addScriptTag({path: path.join(__dirname, `../scripts/components/${name}.js`)});
      await page.evaluate(workflow => {
        window.paymentFixture = () => {
          const ui = workflow === 'pos' ? adminPos() : workflow === 'clinic' ? adminClinicPage() : adminDashboard();
          ui.init = () => {};
          if (workflow === 'pos') ui.cart = [{item_id: 1, item_name: 'Treats', unit: 'pack', price: 350.5, stock: 3, quantity: 1, subtotal: 350.5}];
          else if (workflow === 'clinic') {
            ui.payment.open = true; ui.payment.case = {id: 1, total_amount: 350.5, status: 'for_payment'};
          } else {
            ui.paymentModal.isEarlyPayment = workflow === 'early';
            ui.paymentModal.petBreakdown = [{lines: [{amount: '350.50', minAmount: 0, maxAmount: null}]}];
          }
          return ui;
        };
        document.addEventListener('alpine:init', () => Alpine.store('clinicAccess', {permissions: {payment: true}}));
      }, workflow);
      await page.addScriptTag({content: alpine});
      const input = page.locator(`#${id}`), confirm = page.locator('#confirm');
      assert.equal(await input.getAttribute('inputmode'), 'decimal', `${workflow}: mobile decimal keyboard`);
      await input.fill('500.25');
      await page.waitForFunction(() => document.querySelector('#change').textContent === '149.75');
      assert.equal(await confirm.isEnabled(), true, workflow);
      await input.hover();
      await page.mouse.wheel(0, 110);
      await page.waitForFunction(() => document.querySelector('#scroll').scrollTop > 0);
      assert.equal(await input.inputValue(), '500.25', `${workflow}: wheel preserves focused cash`);
      assert.equal(await input.evaluate(el => document.activeElement === el), true, 'Wheel must not blur');
      await input.press('ArrowUp');
      assert.equal(await input.inputValue(), '500.25', `${workflow}: ArrowUp must not increment`);
      await input.press('ArrowDown');
      assert.equal(await input.inputValue(), '500.25', `${workflow}: ArrowDown must not decrement`);
      await confirm.focus();
      await page.locator('#scroll').evaluate(el => {el.scrollTop = 0;});
      await input.hover();
      await page.mouse.wheel(0, 110);
      await page.waitForFunction(() => document.querySelector('#scroll').scrollTop > 0);
      assert.equal(await input.inputValue(), '500.25', `${workflow}: unfocused wheel preserves cash`);
      await page.locator('#scroll').evaluate(el => {el.scrollTop = 0;});
      await input.click();
      await input.press('ControlOrMeta+A');
      await input.press('Backspace');
      await input.pressSequentially('500.');
      assert.equal(await input.inputValue(), '500.', `${workflow}: preserve partial decimal`);
      assert.equal(await input.evaluate(el => Alpine.evaluate(el, el.getAttribute('x-model'))), '500.', 'Alpine preserves partial text');
      await input.pressSequentially('25');
      await page.waitForFunction(() => document.querySelector('#change').textContent === '149.75');
      await input.press('Home'); await input.press('ArrowRight'); await input.press('Delete');
      assert.equal(await input.inputValue(), '50.25');
      assert.equal(await input.evaluate(el => el.selectionStart), 1, 'Editing must preserve caret');
      for (const invalid of ['', '.', '350.49', '500.001']) {
        await input.fill(invalid);
        assert.equal(await confirm.isEnabled(), false, `${workflow}: reject ${invalid}`);
        assert.equal(await page.locator('#change').textContent(), '0');
      }
      await input.fill('2000.01');
      assert.equal(await input.inputValue(), '2000', `${workflow}: preserve existing live cap`);
      assert.equal(await page.locator('#change').textContent(), '1649.5');
      await input.fill('350.50');
      assert.equal(await confirm.isEnabled(), true, `${workflow}: correction clears validation`);
      assert.equal(await page.locator('#change').textContent(), '0');
      await page.evaluate(() => navigator.clipboard.writeText('500.25'));
      await input.press('ControlOrMeta+A'); await input.press('ControlOrMeta+V');
      await page.waitForFunction(() => document.querySelector('#change').textContent === '149.75');
      assert.equal(await input.inputValue(), '500.25', `${workflow}: native decimal paste`);
      if (workflow !== 'pos') for (const invalid of ['-500', 'abc', '1e3']) {
        await input.fill(invalid);
        assert.equal(await confirm.isEnabled(), false, `${workflow}: reject ${invalid}`);
      }
      await input.fill('500.25');
      await input.press('Tab');
      assert.equal(await confirm.evaluate(el => document.activeElement === el), true, 'Tab remains usable');
      assert.deepEqual(errors, [], workflow);
      console.log(`${workflow}: payment input keyboard, wheel, scrolling, editing and validation passed`);
      await page.close();
    }
  } finally { await browser.close(); }
}
(async () => {
  testNewestFirstProducts();
  await testCheckoutAndDuplicateGuard(); await testStaleSearchRefreshAndErrors(); await testChangedCartsRequireReviewAndCanThenComplete(); await testValidationRetryAndCheckoutRace(); testCashPaymentParity(); await testCameraScannerUsesExistingLookup(); testProductHistoryInvoice(); testPaymentAndScannerIntegration();
  if (process.argv.includes('--browser')) await testPaymentInputInteractions();
  console.log('Retail POS checkout and invoice regression checks passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
