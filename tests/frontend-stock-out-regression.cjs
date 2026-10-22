const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(projectRoot, "scripts/components/admin-stock-out.js"),
  "utf8",
);
const stockOutPage = fs.readFileSync(
  path.join(projectRoot, "pages/admin/inventory/sections/stock-out.html"),
  "utf8",
);

function createPage(inventoryApi = {}, toastMessages = []) {
  const context = {
    InventoryAPI: inventoryApi,
    clearTimeout,
    setTimeout,
    window: {
      showSuccessToast: (message) => toastMessages.push(message),
      showToast: (message, variant) => context.notifications.push({ message, variant }),
    },
    notifications: [],
  };
  vm.createContext(context);
  vm.runInContext(source, context, {
    filename: "scripts/components/admin-stock-out.js",
  });

  const page = context.adminStockOut();
  page.$nextTick = (callback) => callback();
  page.$el = { querySelector: () => null };
  page.toastMessages = toastMessages;
  page.notifications = context.notifications;
  return page;
}

function product() {
  return {
    item_id: 1,
    item_name: "Dog Food",
    unit: "pack",
    category: "food",
    quantity_on_hand: "13.00",
    selling_price: "100.00",
  };
}

function testPendingStockOutReducesDisplayedAndValidatedAvailability() {
  const page = createPage();
  const item = product();

  page.pickItem(item);
  assert.equal(page.availableForReason(), 13);

  page.qty = "10";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.pending[0].quantity, 10);

  page.pickItem(item);
  assert.equal(page.availableForReason(), 3);

  page.qty = "4";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.entryError, "Insufficient stock. Please check available inventory.");

  page.qty = "3";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.pending[0].quantity, 13);

  page.pickItem(item);
  assert.equal(page.availableForReason(), 0);
}

function testStockOutRejectsDecimalQuantities() {
  for (const qty of ["0.04", "1.5", "2.25"]) {
    const page = createPage();
    page.pickItem(product());
    page.qty = qty;

    page.addToPending();

    assert.equal(page.pending.length, 0);
    assert.equal(page.entryError, "Enter a whole number quantity.");
  }
}

function testExpiredReasonDependsOnProductCategory() {
  const page = createPage();
  page.selected = { ...product(), category: "pet_shop" };
  page.reason = "expired";
  assert.equal(page.canUseExpired(), false);

  page.qty = "1";
  page.addToPending();
  assert.equal(page.pending.length, 1);
  assert.equal(page.pending[0].reason, "used");

  page.selected = { ...product(), category: "food" };
  assert.equal(page.canUseExpired(), true);
}

function testSellingPriceOnlyAppliesToSoldReason() {
  const page = createPage();
  page.pickItem(product());
  assert.equal(page.sellingPrice, "100.00");
  page.qty = "1";
  page.sellingPrice = "150";
  page.reason = "used";
  page.addToPending();

  assert.equal(page.pending[0].selling_price, null);

  page.pickItem(product());
  page.qty = "1";
  page.reason = "sold";
  page.sellingPrice = "150";
  page.addToPending();

  assert.equal(page.pending[1].selling_price, 150);

  assert.match(stockOutPage, /:value="selected\?\.selling_price \?\? ''" type="text" readonly/);
  assert.doesNotMatch(stockOutPage, /x-model="sellingPrice" type="number"/);
}

async function testSuccessfulStockOutShowsReusableSuccessToast() {
  const page = createPage({ stockOut: async () => {} });
  page.pending = [{ item_id: 1, quantity: 2, reason: "used", selling_price: null, notes: null }];

  await page.submit();

  assert.deepEqual(page.toastMessages, ["Stock-out recorded successfully."]);
  assert.equal(page.pending.length, 0);
}

async function testFailedStockOutKeepsPendingAndShowsError() {
  const page = createPage({ stockOut: async () => { throw new Error('Stock is reserved'); } });
  page.pending = [{ item_id: 1, quantity: 2, reason: 'used' }];
  await page.submit();
  assert.equal(page.pending.length, 1);
  assert.deepEqual(page.toastMessages, []);
  assert.deepEqual(page.notifications, [{ message: 'Stock is reserved', variant: 'error' }]);
}

(async () => {
  testPendingStockOutReducesDisplayedAndValidatedAvailability();
  testStockOutRejectsDecimalQuantities();
  testExpiredReasonDependsOnProductCategory();
  testSellingPriceOnlyAppliesToSoldReason();
  await testSuccessfulStockOutShowsReusableSuccessToast();
  await testFailedStockOutKeepsPendingAndShowsError();
  console.log("frontend stock-out regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
