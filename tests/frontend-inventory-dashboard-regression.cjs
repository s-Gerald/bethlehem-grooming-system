const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const context = { window: {}, InventoryAPI: {}, API: {loadAdminData: (key, load, render) => load().then(render)}, console };
vm.createContext(context);
for (const name of ['inventory-alerts.js', 'inventory-movement.js', 'admin-inventory-dashboard.js', 'admin-inventory-transactions.js']) {
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', name), 'utf8'), context);
}

(async () => {
  const calls = [];
  const records = Array.from({length:10}, (_,i) => ({item_id:i, transaction_id:i}));
  context.InventoryAPI = {
    getSummary: async (params, render) => { calls.push('summary'); const data = {total_items:43,low_stock_count:37,expiry_alert_count:12,recent_transactions:records,low_stock_items:records,expiry_items:records}; render(data); return data; },
    getLowStock: async ({page}) => { calls.push(['low-stock',page]); return {data:records}; },
    getExpiryAlerts: async ({page}) => { calls.push(['expiry',page]); return {data:records}; },
  };
  const page = context.adminInventoryDashboard();
  await page.load();
  assert.deepEqual(calls, ['summary']);
  for (const list of ['lowStockItems','expiryItems','recentTransactions']) {
    assert.equal(page[list].length,5);
    assert.deepEqual(Array.from(page[list], item => item.item_id), [0,1,2,3,4]);
  }
  assert.equal(page.totalItems,43);
  assert.equal(page.lowStockCount,37);
  assert.equal(page.expiryCount,12);
  page.$nextTick = callback => callback();
  let scrolls = 0;
  page.$refs = {alerts: {scrollIntoView: () => scrolls++}};
  assert.equal(typeof page.showAlert, 'function', 'Summary alert cards activate their existing Overview view');
  page.showAlert('expiry'); assert.equal(page.activeTab, 'expiry');
  page.showAlert('low_stock'); assert.equal(page.activeTab, 'low_stock');
  assert.equal(scrolls, 2);
  assert.deepEqual(calls, ['summary'], 'Switching summary views reuses the already loaded alerts');
  const history = context.adminInventoryTransactions();
  for (const [reason, label, style] of [
    ['purchase', 'Purchase', 'background-color:#dcfce7;color:#166534'],
    ['return', 'Return', 'background-color:#dbeafe;color:#1d4ed8'],
    ['used', 'Used', 'background-color:#fef3c7;color:#92400e'],
    ['sold', 'Sold', 'background-color:#d1fae5;color:#065f46'],
    ['expired', 'Expired', 'background-color:#fee2e2;color:#b91c1c'],
    ['damaged', 'Damaged', 'background-color:#fee2e2;color:#b91c1c'],
    ['adjustment', 'Adjustment', 'background-color:#f1f5f9;color:#475569'],
  ]) {
    assert.equal(page.reasonLabel(reason), label);
    assert.equal(page.reasonStyle(reason), style);
    assert.equal(history.reasonLabel(reason), label);
    assert.equal(history.reasonStyle(reason), style);
  }
  assert.equal(page.expiryLabel({is_expired:true}), 'Expired');
  assert.equal(page.expiryLabel({days_until_expiry:0}), 'Expires today');
  context.InventoryAPI.getSummary = async () => { throw new Error('Unavailable'); };
  await page.load();
  assert.equal(page.error,'Unavailable');
  assert.equal(page.loading,false);

  const markup=fs.readFileSync(path.join(root,'pages/admin/inventory/sections/overview.html'),'utf8');
  assert.match(markup, />Processed By<\/th>/);
  assert.match(markup, /tx\.performed_by_name/);
  for (const label of ['View all low-stock products', 'View all expiry alerts', 'View full history']) {
    assert.equal(markup.includes(label), false, `${label} footer link is removed`);
  }
  assert.match(markup, /<a href="#products\?filter=low-stock"[^>]*x-text="item\.item_name"><\/a>/);
  assert.match(markup, /<a href="#products\?filter=expiry"[^>]*x-text="item\.item_name"><\/a>/);
  assert.match(markup, /<a :href="inventoryStockHref\('stock-in', item\.item_id\)"[^>]*title="Restock"[^>]*:aria-label="'Restock ' \+ item\.item_name"/);
  assert.doesNotMatch(markup, /pagination|goTo\w+Page|Top Used|Most Used|data-lucide/i);
  console.log('frontend inventory overview regression checks passed');
})().catch(error => { console.error(error); process.exitCode=1; });
