const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-inventory-items.js"),
  "utf8",
);
const productFormSource = fs.readFileSync(
  path.join(projectRoot, "scripts/components/product-form.js"),
  "utf8",
);

function createPage(inventoryApi = {}) {
  for (const name of ['getItems', 'getExpiryAlerts']) {
    if (!inventoryApi[name]) continue;
    const read = inventoryApi[name];
    inventoryApi[name] = async (params, render) => { const data = await read(params); render?.(data); return data; };
  }
  const context = {
    InventoryAPI: inventoryApi,
    clearTimeout,
    setTimeout,
    window: { showToast: () => {} },
  };

  vm.createContext(context);
  vm.runInContext(productFormSource, context, {
    filename: "scripts/components/product-form.js",
  });
  context.ProductForm = context.window.ProductForm;
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-inventory-items.js",
  });

  const page = context.adminInventoryItems();
  page.$nextTick = (callback) => callback();
  return page;
}

function validForm(overrides = {}) {
  return {
    item_id: null,
    item_name: "Validated product",
    barcode: "0012345678901",
    category: "medicine",
    medicine_type: "general_medication", dose_unit: "tablet", pricing_method: "flat",
    unit: "piece",
    description: "",
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "0",
    ...overrides,
  };
}

async function testClientRejectsInvalidProductFormValuesWithoutSubmitting() {
  const invalidForms = [
    [validForm({ barcode: "01234567890123" }), /Barcode must contain 1 to 13 digits/],
    [validForm({ unit_cost: "" }), /Unit cost must be a valid amount/],
    [validForm({ selling_price: "0" }), /Selling price must be a valid amount/],
    [validForm({ reorder_level: "1.5" }), /Minimum stock must be a whole number/],
    [validForm({ reorder_level: "" }), /Minimum stock must be a whole number/],
  ];

  for (const [form, expectedError] of invalidForms) {
    let submitted = false;
    const page = createPage({
      createItem: async () => { submitted = true; },
    });
    page.form = form;

    await page.submitForm();

    assert.equal(submitted, false);
    assert.match(page.modal.error, expectedError);
  }
}

function testBarcodeInputKeepsOnlyThirteenDigits() {
  const page = createPage();
  const input = { value: "00ab12345678901234" };

  page.handleBarcodeInput({ target: input });

  assert.equal(page.form.barcode, "0012345678901");
  assert.equal(input.value, "0012345678901");
}

async function testEditingAnIntegerMinimumStockStoredAsDecimalCanBeSavedUnchanged() {
  const updates = [];
  const page = createPage({
    updateItem: async (_id, payload) => { updates.push(payload); },
    getItems: async () => ({ data: [], page: 1, last_page: 1, total: 0 }),
  });

  page.openEdit({
    item_id: 7,
    item_name: "Stored decimal minimum stock",
    barcode: "0012345678901",
    category: "medicine",
    medicine_type: "general_medication", dose_unit: "tablet", pricing_method: "flat",
    unit: "piece",
    description: null,
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "5.00",
  });

  await page.submitForm();

  assert.equal(page.form.reorder_level, "5");
  assert.equal(updates[0].reorder_level, 5);
}

async function testClientPreservesLeadingZeroBarcodeAndReloadsTheLastValidPage() {
  const payloads = [];
  const requestedPages = [];
  const page = createPage({
    createItem: async (payload) => { payloads.push(payload); },
    getItems: async ({ page: requestedPage }) => {
      requestedPages.push(requestedPage);
      if (requestedPage === 2) {
        return { data: [], page: 2, last_page: 1, total: 15 };
      }
      return { data: [{ item_id: 1 }], page: 1, last_page: 1, total: 15 };
    },
  });

  page.form = {
    item_id: null,
    item_name: "Leading zero barcode",
    barcode: "0012345678901",
    category: "medicine",
    medicine_type: "general_medication", dose_unit: "tablet", pricing_method: "flat",
    unit: "piece",
    description: "",
    unit_cost: "1.25",
    selling_price: "2.50",
    reorder_level: "0",
  };

  await page.submitForm();
  await page.load(2);

  assert.equal(payloads[0].barcode, "0012345678901");
  assert.deepEqual(requestedPages, [1, 2, 1]);
  assert.equal(page.currentPage, 1);
  assert.equal(page.total, 15);
}

async function testPageRecoveryKeepsLoadingUntilTheFallbackResponseArrives() {
  let requestCount = 0;
  let resolveFallback;
  const page = createPage({
    getItems: async () => {
      requestCount += 1;
      if (requestCount === 1) {
        return { data: [], page: 2, last_page: 1, total: 15 };
      }
      return new Promise((resolve) => { resolveFallback = resolve; });
    },
  });

  const loadPromise = page.load(2);
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(requestCount, 2);
  assert.equal(page.loading, true);

  resolveFallback({ data: [{ item_id: 1 }], page: 1, last_page: 1, total: 15 });
  await loadPromise;

  assert.equal(page.loading, false);
}

function testRowNumbersFollowTheCurrentPaginationAndResetWithFilters() {
  const page = createPage();

  page.currentPage = 2;
  assert.equal(page.rowNumber(0), 11);
  assert.equal(page.rowNumber(9), 20);

  page.currentPage = 1;
  assert.equal(page.rowNumber(0), 1);
}

async function testDetailsLoadsFreshInformationAndUsesTheExistingEditForm() {
  let resolveDetails;
  let calls = 0;
  const page = createPage({ getItem: async (id) => {
    assert.equal(id, 7);
    calls += 1;
    return new Promise(resolve => { resolveDetails = resolve; });
  } });
  const row = { item_id: 7, item_name: 'Old product name', quantity_on_hand: '20.00' };
  let restored = 0, focused = 0;
  page.$refs = { detailsClose: { focus: () => focused++ } };
  const loading = page.openDetails(row, { currentTarget: { focus: () => restored++ } });
  assert.equal(page.details.open, true);
  assert.equal(page.details.loading, true);
  assert.equal(page.details.item, null);
  assert.equal(focused, 1);
  const fresh = { ...validForm({ item_id: 7 }), item_name: 'Fresh product name', quantity_on_hand: '17.00', saleable_quantity: 14, current_batches: [] };
  resolveDetails({ data: fresh });
  await loading;
  assert.equal(page.details.loading, false);
  assert.equal(page.details.item, fresh);
  assert.equal(page.details.name, 'Fresh product name');
  page.editDetails();
  assert.equal(page.details.open, false);
  assert.equal(page.modal.open, true);
  assert.equal(page.form.item_name, 'Fresh product name');
  assert.equal(restored, 0);
  page.modal.open = false;
  const reopening = page.openDetails(row);
  resolveDetails({ data: fresh });
  await reopening;
  page.closeDetails();
  assert.equal(calls, 2);
  assert.equal(restored, 1);
}

async function testDetailsIgnoresClosedAndSupersededRequests() {
  const responses = [];
  const page = createPage({ getItem: () => new Promise(resolve => responses.push(resolve)) });
  const first = page.openDetails({ item_id: 1, item_name: 'First' });
  page.closeDetails();
  responses[0]({ data: { item_id: 1 } });
  await first;
  assert.equal(page.details.open, false);
  assert.equal(page.details.item, null);
  const slow = page.openDetails({ item_id: 1, item_name: 'First' });
  const fast = page.openDetails({ item_id: 2, item_name: 'Second' });
  responses[2]({ data: { item_id: 2, item_name: 'Second fresh' } });
  await fast;
  responses[1]({ data: { item_id: 1, item_name: 'First stale' } });
  await slow;
  assert.equal(page.details.item.item_id, 2);
  assert.equal(page.details.name, 'Second fresh');
}

async function testDetailsFailureCanBeRetriedAndEscapeRestoresFocus() {
  let fail = true, restored = 0;
  const page = createPage({ getItem: async () => {
    if (fail) throw new Error('Unavailable');
    return { data: { item_id: 1, item_name: 'Recovered' } };
  } });
  const row = { item_id: 1, item_name: 'Product' };
  await page.openDetails(row, { currentTarget: { focus: () => restored++ } });
  assert.equal(page.details.error, 'Unavailable');
  assert.equal(page.details.loading, false);
  fail = false;
  await page.openDetails(row);
  assert.equal(page.details.error, '');
  assert.equal(page.details.item.item_name, 'Recovered');
  let prevented = false;
  page.detailsKeydown({ key: 'Escape', preventDefault: () => { prevented = true; } });
  assert.equal(page.details.open, false);
  assert.equal(prevented, true);
  assert.equal(restored, 1);
}

function testDetailsTrapsKeyboardFocus() {
  const page = createPage();
  let firstFocus = 0, lastFocus = 0, prevented = 0;
  const first = { focus: () => firstFocus++, getClientRects: () => [1] };
  const last = { focus: () => lastFocus++, getClientRects: () => [1] };
  page.$refs = { detailsDialog: { querySelectorAll: () => [first, last] } };
  page.detailsKeydown({ key: 'Tab', target: last, preventDefault: () => prevented++ });
  page.detailsKeydown({ key: 'Tab', target: first, shiftKey: true, preventDefault: () => prevented++ });
  assert.equal(firstFocus, 1);
  assert.equal(lastFocus, 1);
  assert.equal(prevented, 2);
}

async function testReactivateRefreshesDetailsAndKeepsFocusInsideTheModal() {
  let focused = 0, reactivated = 0;
  const fresh = { item_id: 1, item_name: 'Active product', is_active: true };
  const page = createPage({
    reactivateItem: async id => { assert.equal(id, 1); reactivated++; return { data: fresh }; },
    getItems: async () => ({ data: [], page: 1, last_page: 1, total: 0 }),
  });
  page.showToast = () => {};
  page.$refs = { detailsClose: { focus: () => focused++ } };
  page.details = { open: true, busy: false, item: { item_id: 1, is_active: false } };
  await page.doReactivate(page.details.item);
  assert.equal(reactivated, 1);
  assert.equal(page.details.item.is_active, true);
  assert.equal(page.details.item.item_name, fresh.item_name);
  assert.equal(page.details.busy, false);
  assert.equal(focused, 1);
  page._detailsTrigger = { isConnected: false };
  page.$refs.productsAdd = { focus: () => focused++ };
  page.closeDetails();
  assert.equal(focused, 2);
}

(async () => {
  await testClientRejectsInvalidProductFormValuesWithoutSubmitting();
  testBarcodeInputKeepsOnlyThirteenDigits();
  await testEditingAnIntegerMinimumStockStoredAsDecimalCanBeSavedUnchanged();
  await testClientPreservesLeadingZeroBarcodeAndReloadsTheLastValidPage();
  await testPageRecoveryKeepsLoadingUntilTheFallbackResponseArrives();
  testRowNumbersFollowTheCurrentPaginationAndResetWithFilters();
  await testDetailsLoadsFreshInformationAndUsesTheExistingEditForm();
  await testDetailsIgnoresClosedAndSupersededRequests();
  await testDetailsFailureCanBeRetriedAndEscapeRestoresFocus();
  testDetailsTrapsKeyboardFocus();
  await testReactivateRefreshesDetailsAndKeepsFocusInsideTheModal();
  console.log("frontend inventory item regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
