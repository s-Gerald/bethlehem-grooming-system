const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const context = { window: {}, InventoryAPI: {}, API: {loadAdminData: (key, load, render) => load().then(render)}, console };
vm.createContext(context);
for (const name of ['inventory-alerts.js', 'admin-inventory-dashboard.js']) {
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
  assert.equal(page.expiryLabel({is_expired:true}), 'Expired');
  assert.equal(page.expiryLabel({days_until_expiry:0}), 'Expires today');
  context.InventoryAPI.getSummary = async () => { throw new Error('Unavailable'); };
  await page.load();
  assert.equal(page.error,'Unavailable');
  assert.equal(page.loading,false);

  const markup=fs.readFileSync(path.join(root,'pages/admin/inventory/sections/overview.html'),'utf8');
  assert.match(markup, />Processed By<\/th>/);
  assert.match(markup, /tx\.performed_by_name/);
  assert.match(markup, /href="#history"/);
  assert.match(markup, /href="#products\?filter=low-stock"/);
  assert.match(markup, /href="#products\?filter=expiry"/);
  assert.doesNotMatch(markup, /pagination|goTo\w+Page|Top Used|Most Used|data-lucide/i);
  console.log('frontend inventory overview regression checks passed');
})().catch(error => { console.error(error); process.exitCode=1; });
