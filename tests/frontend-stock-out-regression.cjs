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
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/components/product-form.js'), 'utf8'), context);
  context.ProductForm = context.window.ProductForm;
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
    usable_quantity: 13, saleable_quantity: 13, reserved_quantity: 0,
    current_batches: [{transaction_id: 10, remaining_quantity: 13, is_unexpired: true, expiry_date: "2030-01-01"}],
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
  assert.equal(page.entryError, "Maximum available: 3 pack");

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
  const picks = createPage();
  const item = {...product(), quantity_on_hand: 25, usable_quantity: 20, saleable_quantity: 20, expired_quantity: 5,
    current_batches: [
      {transaction_id: 1, remaining_quantity: 5, is_expired: true, is_unexpired: false, expiry_date: '2020-01-01'},
      {transaction_id: 2, remaining_quantity: 5, is_unexpired: true, expiry_date: '2030-01-01'},
      {transaction_id: 3, remaining_quantity: 15, is_unexpired: true, expiry_date: '2031-01-01'},
    ]};
  picks.pickItem(item); picks.qty = '21'; assert.equal(picks.canAdd, false); assert.match(picks.quantityError, /20/);
  picks.qty = '6'; picks.receiptId = '2'; assert.equal(picks.availableForReason(), 5); assert.equal(picks.canAdd, false);
  picks.receiptId = ''; picks.qty = '12'; assert.equal(picks.canAdd, true); picks.addToPending();
  assert.deepEqual(JSON.parse(JSON.stringify(picks.pending[0].receipt_allocations)), [{receipt_id: 2, quantity: 5}, {receipt_id: 3, quantity: 7}]);
  picks.pickItem(item); assert.equal(picks.availableForReason(), 8); picks.qty = '9'; assert.equal(picks.canAdd, false);
  picks.reason = 'expired'; picks.qty = '1'; assert.equal(picks.canAdd, false);
  picks.receiptId = '1'; assert.equal(picks.availableForReason(), 5); assert.equal(picks.canAdd, true);
  picks.qty = '6'; assert.equal(picks.canAdd, false); assert.equal(picks.qty, '6');
  const order = createPage();
  const ordered = {...item, quantity_on_hand: 20, usable_quantity: 20, current_batches: item.current_batches.slice(1)};
  order.pickItem(ordered); order.qty = '1'; order.addToPending();
  order.pickItem(ordered); order.reason = 'damaged'; order.receiptId = '2'; order.qty = '4'; order.addToPending();
  order.pickItem(ordered); order.reason = 'used'; order.qty = '5'; order.addToPending();
  assert.equal(order.pending.length, 3, 'Later automatic picks retain their position after a physical receipt pick');
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
