const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const read = (file) => fs.readFileSync(path.join(__dirname, "..", file), "utf8");

function createPage(file, factory, api) {
  let pendingSearch;
  const context = {
    window: {}, console, Date, Intl, InventoryAPI: api,
    ProductForm: { normalizeBarcode: (code) => code },
    setTimeout: (callback) => { pendingSearch = callback; return 1; },
    clearTimeout: () => { pendingSearch = null; },
  };
  if (fs.existsSync(path.join(__dirname, "../scripts/components/product-search-dropdown.js"))) vm.runInNewContext(read("scripts/components/product-search-dropdown.js"), context);
  vm.runInNewContext(read(file), context);
  const page = context[factory]();
  page.$nextTick = callback => callback();
  page.$refs = { productSearch: { focus() {} } };
  return { page, runSearch: () => pendingSearch() };
}

const product = (overrides = {}) => ({
  item_id: 1, item_name: "Pet Shampoo", category: "grooming_supply", unit: "bottle",
  is_active: true, selling_price: "250.00", quantity_on_hand: "8.00",
  unexpired_quantity: 4, saleable_quantity: 4, ...overrides,
});

async function testGroomingSearchAndAdding() {
  const empty = product({ item_id: 2, quantity_on_hand: 0, saleable_quantity: 0 });
  const physicalLimit = product({ item_id: 3, quantity_on_hand: 1, saleable_quantity: 1, unexpired_quantity: 9,
    selling_price: "280.00", reorder_level: 15, low_stock: true });
  const deactivated = product({ item_id: 6, is_active: false });
  const excluded = [
    product({ item_id: 4, category: "medicine" }),
    product({ item_id: 5, category: "vaccine" }),
    product({ item_id: 7, selling_price: null }),
  ];
  const { page, runSearch } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {
    searchItems: async (...args) => {
      assert.deepEqual(args, ["Pet", true, "grooming", false]);
      return { data: [empty, product(), deactivated, ...excluded, physicalLimit] };
    },
  });
  page.paymentModal.open = true;
  page.paymentModal.productQuery = "Pet";
  page.searchPaymentProducts();
  await runSearch();
  assert.deepEqual(Array.from(page.paymentModal.productResults, (item) => item.item_id), [1, 3, 2, 6, 7]);
  assert.equal(page.isEligiblePaymentProduct(empty), true);
  assert.equal(page.hasPaymentProductStock(empty), false);
  for (const item of [empty, deactivated, ...excluded]) page.addPaymentProduct(item);
  assert.equal(page.paymentModal.products.length, 0);
  assert.equal(page.paymentModal.productQuery, "Pet");
  page.addPaymentProduct(product());
  assert.equal(page.paymentModal.products[0].price, 250);
  assert.equal(page.paymentModal.products[0].stock, 4);
  page.addPaymentProduct(physicalLimit);
  assert.equal(page.hasPaymentProductStock(physicalLimit), true);
  assert.deepEqual(Array.from(page.paymentModal.products, line => line.item_id), [3, 1]);
  assert.equal(page.paymentModal.products[0].price, 280);
  assert.equal(page.paymentModal.products[0].stock, 1);
  page.addPaymentProduct(physicalLimit);
  assert.equal(page.paymentModal.products[0].quantity, 1);
  assert.deepEqual(Array.from(page.paymentModal.products, line => line.item_id), [3, 1], 'Invalid duplicate adds retain the selected order');
  assert.match(page.paymentModal.productSearchError, /no more available stock/);
}

function testGroomingUnavailableStockLabels() {
  const { page } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {});
  for (const [item, label] of [
    [product({ quantity_on_hand: 0, saleable_quantity: 0, expired_quantity: 4 }), "Out of stock"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 1, low_stock: true }), "Expired"],
    [product({ is_active: false, saleable_quantity: 4 }), "Inactive"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 0, expiry_unknown_quantity: 1 }), "Unavailable stock"],
    [product({ quantity_on_hand: 1, saleable_quantity: 0, expired_quantity: 0, untracked_quantity: 1 }), "Unavailable stock"],
  ]) {
    assert.equal(page.hasPaymentProductStock(item), false);
    assert.equal(page.paymentProductStockLabel(item), label);
    page.addPaymentProduct(item);
  }
  assert.equal(page.paymentModal.products.length, 0);
}

async function testBarcodeStillChecksStockAndEligibility() {
  for (const item of [product(), product({ saleable_quantity: 0 }), product({ category: "vaccine" }), product({ is_active: false })]) {
    const { page } = createPage("scripts/components/admin-dashboard.js", "adminDashboard", {
      findByBarcode: async (barcode) => {
        assert.equal(barcode, "001234");
        return { data: item };
      },
    });
    page.paymentModal.open = true;
    await page.scanPaymentBarcode("001234");
    const available = item.is_active && item.category !== "vaccine" && item.saleable_quantity > 0;
    assert.equal(page.paymentModal.products.length, available ? 1 : 0);
    if (!available) assert.match(page.paymentModal.productSearchError, /unavailable/);
  }
}

async function testPosUsesSellableStock() {
  const empty = product({ item_id: 2, saleable_quantity: 0, unexpired_quantity: 8 });
  const nonexpiring = product({ item_id: 3, category: "pet_shop", saleable_quantity: "3.00", unexpired_quantity: 0 });
  const { page, runSearch } = createPage("scripts/components/admin-pos.js", "adminPos", {
    searchItems: async () => ({ data: [empty, product(), nonexpiring] }),
    findByBarcode: async () => ({ data: nonexpiring }),
  });
  page.searchQuery = "Pet";
  await page.onSearchInput();
  await runSearch();
  assert.deepEqual(Array.from(page.searchResults, (item) => item.item_id), [1, 3, 2]);
  assert.equal(page.hasProductStock(empty), false);
  assert.equal(page.hasProductStock(nonexpiring), true);
  page.addToCart(empty);
  assert.equal(page.cart.length, 0);
  page.addToCart(product());
  assert.equal(page.cart[0].stock, 4);
  assert.equal(page.cart[0].price, 250);
  await page.scanBarcode("001234");
  assert.deepEqual(Array.from(page.cart, line => line.item_id), [3, 1]);
  const scanned = page.cart.find(line => line.item_id === 3);
  assert.equal(scanned.stock, 3);
  assert.equal(scanned.quantity, 1);
  assert.equal(scanned.subtotal, 250);
  for (let i = 0; i < 4; i++) page.addToCart(nonexpiring);
  assert.equal(scanned.quantity, 3);
  assert.equal(scanned.subtotal, 750);
  page.addToCart(product({ item_id: 4, saleable_quantity: 0.5 }));
  assert.equal(page.cart.length, 2, 'Retail cannot add a fraction of one unit');
  assert.match(page.error, /one whole unit/);
  const original = page.cart.find(line => line.item_id === 1);
  original.quantity = 1.5; page.addToCart(product());
  assert.equal(original.quantity, 1.5, 'Invalid decimal input is rejected, never rounded');
  assert.match(page.lineError(original), /whole quantity/);
  assert.deepEqual(Array.from(page.cart, line => line.item_id), [3, 1], 'Invalid quantities do not promote a row');
}

async function testFocusSuggestionsAndDismissal() {
  for (const grooming of [true, false]) {
    let resolve;
    const {page} = createPage(grooming ? "scripts/components/admin-dashboard.js" : "scripts/components/admin-pos.js", grooming ? "adminDashboard" : "adminPos", {
      searchItems: async (query, inactive, context, suggestions) => {
        assert.equal(query, ""); assert.equal(suggestions, true);
        assert.equal(context, grooming ? "grooming" : "pos");
        return new Promise(done => {resolve = done;});
      },
    });
    page.paymentModal && (page.paymentModal.open = true);
    const pending = grooming ? page.focusPaymentProducts() : page.focusProducts();
    assert.equal(page.productDropdown.open, true);
    page.productDropdown.close();
    resolve({data: [product()]}); await pending;
    assert.equal((grooming ? page.paymentModal.productResults : page.searchResults).length, 0, "Dismissed suggestions cannot return late");
  }
}

async function testSuccessfulDefaultsAndSelectionStayClosed() {
  for (const grooming of [true, false]) {
    const {page} = createPage(grooming ? 'scripts/components/admin-dashboard.js' : 'scripts/components/admin-pos.js', grooming ? 'adminDashboard' : 'adminPos', {
      searchItems: async () => ({data: [product(), product({item_id: 2, is_active: false}), product({item_id: 3, category: 'medicine'}), product({item_id: 4, saleable_quantity: 0})]}),
    });
    if (grooming) page.paymentModal.open = true;
    await (grooming ? page.focusPaymentProducts() : page.focusProducts());
    assert.deepEqual(Array.from(grooming ? page.paymentModal.productResults : page.searchResults, item => item.item_id), [1]);
    if (grooming) page.addPaymentProduct(product()); else page.addToCart(product());
    assert.equal(page.productDropdown.open, false);
    assert.equal(grooming ? page.paymentModal.productQuery : page.searchQuery, '');
    if (grooming) assert.equal(page.paymentProductsTotal, 250); else assert.equal(page.totalAmount, 250);
  }
}

async function testClosedGroomingSearchCannotAffectNextModal() {
  let reject;
  const {page, runSearch} = createPage('scripts/components/admin-dashboard.js', 'adminDashboard', {
    searchItems: () => new Promise((resolve, fail) => {reject = fail;}),
  });
  page.paymentModal.open = true; page.paymentModal.productQuery = 'Pet';
  page.searchPaymentProducts(); const pending = runSearch();
  page.closePaymentModal(); page.paymentModal.open = true; page.paymentModal.productQuery = 'Pet';
  reject(new Error('Old request failed')); await pending;
  assert.equal(page.paymentModal.productSearchError, '');
  assert.equal(page.paymentModal.productResults.length, 0);
}

function testPopupFitsFourRowsAndCleansPlacementListeners() {
  const events = new Map();
  const context = {window: {innerHeight: 800, innerWidth: 1024, addEventListener(type, callback) {events.set(type, callback);}, removeEventListener(type) {events.delete(type);}}, getComputedStyle: () => ({overflowY: 'auto'})};
  vm.runInNewContext(read('scripts/components/product-search-dropdown.js'), context);
  const parent = {parentElement: null, getBoundingClientRect: () => ({top: 100, bottom: 760}), addEventListener(type, callback) {events.set('parent-' + type, callback);}, removeEventListener(type) {events.delete('parent-' + type);}};
  const input = {parentElement: parent, getBoundingClientRect: () => ({left: 40, top: 300, bottom: 344, width: 320})};
  const dropdown = context.ProductSearchDropdown.create(); dropdown.show(input, {});
  assert.equal(dropdown.listStyle, 'max-height:224px', 'Four compact rows fit without covering the footer');
  assert.equal(events.size, 2);
  dropdown.show(input, {}); assert.equal(events.size, 2, 'Opening again does not accumulate listeners');
  events.get('parent-scroll')(); assert.equal(dropdown.open, false);
  assert.equal(events.size, 0);
}

function testKeyboardSkipsUnavailableProducts() {
  const context = {window: {}};
  vm.runInNewContext(read('scripts/components/product-search-dropdown.js'), context);
  const dropdown = context.ProductSearchDropdown.create();
  dropdown.show();
  let selected = null;
  const rows = [product({item_id: 2, is_active: false}), product(), product({item_id: 3, saleable_quantity: 0})];
  const eligible = item => item.is_active && item.saleable_quantity >= 1;
  const event = key => ({key, preventDefault() {}, stopPropagation() {}});
  dropdown.key(event('ArrowDown'), rows, eligible, item => {selected = item;});
  assert.equal(dropdown.activeId, 1);
  dropdown.key(event('ArrowDown'), rows, eligible, item => {selected = item;});
  assert.equal(dropdown.activeId, 1);
  dropdown.key(event('Enter'), rows, eligible, item => {selected = item;});
  assert.equal(selected.item_id, 1);
  selected = null; rows[1].is_active = false;
  dropdown.key(event('Enter'), rows, eligible, item => {selected = item;});
  assert.equal(selected, null, 'A product becoming unavailable cannot be selected by Enter');
  dropdown.key(event('Escape'), rows, eligible, () => {});
  assert.equal(dropdown.open, false);
  dropdown.show(); dropdown.key(event('Tab'), rows, eligible, () => {});
  assert.equal(dropdown.open, false);
}

(async () => {
  await testGroomingSearchAndAdding();
  testGroomingUnavailableStockLabels();
  await testBarcodeStillChecksStockAndEligibility();
  await testPosUsesSellableStock();
  await testFocusSuggestionsAndDismissal();
  testKeyboardSkipsUnavailableProducts();
  testPopupFitsFourRowsAndCleansPlacementListeners();
  await testSuccessfulDefaultsAndSelectionStayClosed();
  await testClosedGroomingSearchCannotAffectNextModal();
  console.log("Product search regression checks passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
