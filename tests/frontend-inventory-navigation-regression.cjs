const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root=path.resolve(__dirname,'..');
const read=(name)=>fs.readFileSync(path.join(root,name),'utf8');

function setup() {
  const calls=[];
  const scripts=[];
  const panels=[];
  const listeners=new Map();
  const stockItem={item_id:1,item_name:'Medicine',is_active:true,category:'medicine',unit:'box',quantity_on_hand:20,selling_price:50};
  const pageResponse={data:[stockItem],page:1,last_page:3,total:25};
  let fail=false;
  let blockMarkup;
  const context={
    console,URL,URLSearchParams,Date,clearTimeout,setTimeout,CustomEvent:class {constructor(type){this.type=type;}},
    location:{hash:'#overview'},
    scrollTo:()=>{},
    addEventListener:(type,listener)=>{listeners.set(type,listener);},
    removeEventListener:(type)=>listeners.delete(type),
    dispatchEvent:(event)=>listeners.get(event.type)?.(event),
    API:{enforceAdminPageAccess:()=>true,adminRequest:async (method,url)=>{
      calls.push([method,url]);
      if(fail)throw new Error('Rejected');
      if(url.startsWith('/inventory/summary'))return {total_items:25,low_stock_count:25,expiry_alert_count:1,recent_transactions:[]};
      if(url.startsWith('/inventory/items/'))return {data:{...stockItem}};
      return pageResponse;
    }},
    fetch:async (url)=>{calls.push(['markup',url]);if(blockMarkup)await blockMarkup;return {ok:true,text:async()=>''};},
    history:{replaceState:(_,__,hash)=>{context.location.hash=hash;}},
    document:{title:'',head:{appendChild(script){
      scripts.push(script.src);
      if(!script.src.startsWith('https:')) {
        context.document.currentScript={src:'http://localhost/scripts/components/'+script.src.split('/').pop()};
        const filename=script.src.split('/').pop().split('?')[0];
        vm.runInContext(read('scripts/components/'+filename),context);
      }
      queueMicrotask(()=>script.onload());
    }},createElement(){return {dataset:{},style:{},attrs:{},setAttribute(key,value){this.attrs[key]=value;},remove(){}};}},
    Alpine:{mutateDom:fn=>fn(),initTree(panel){
      const section=panel.dataset.inventorySection;
      panel.state=context.adminInventorySection(section);
      panel.state.$nextTick=fn=>fn();
      panel.state.$el={querySelector:()=>null};
      panel.state.$refs={productSearch:{focus(){}},successDialog:{close(){},showModal(){}}};
      panel.state.init();
    },$data:panel=>panel.state},
  };
  context.window=context;
  vm.createContext(context);
  vm.runInContext(read('scripts/services/inventory-service.js'),context);
  vm.runInContext(read('scripts/components/admin-inventory.js'),context);
  const shell=context.adminInventory();
  shell.$refs={panels:{appendChild:panel=>panels.push(panel),querySelector(selector){
    const section=selector.match(/="([^"]*)"/)[1];
    return panels.find(panel=>panel.dataset.inventorySection===section);
  }}};
  shell.$nextTick=fn=>fn();
  const changeListener=()=>{shell.inventoryRevision++;};
  listeners.set('inventory:changed',changeListener);
  return {context,shell,calls,scripts,panels,stockItem,
    fail:()=>{fail=true;}, unblock:promise=>{blockMarkup=promise;},
    visit:async hash=>{context.location.hash=hash;await shell.showSection();},
    state:section=>panels.find(panel=>panel.dataset.inventorySection===section)?.state};
}

async function testLazyNavigationAndMutationRefresh() {
  const app=setup();
  await app.visit('#overview');
  assert.deepEqual(app.calls.filter(c=>c[0]==='GET').map(c=>c[1]),[
    '/inventory/summary?page=1','/inventory/low-stock?page=1','/inventory/alerts/expiry?page=1',
  ]);
  assert.equal(app.panels.length,1);
  assert.equal(app.scripts.some(url=>url.includes('qrcode')),false);
  await app.visit('#products');
  const products=app.state('products');
  products.q='Medicine';products.currentPage=2;
  await app.visit('#stock-in');
  const stockIn=app.state('stock-in');
  stockIn.selected={...app.stockItem};stockIn.qty='3';stockIn.expiryDate='2030-01-01';stockIn.notes='Keep this draft';
  assert.equal(stockIn.needsExpiry,true,'the needsExpiry getter must survive adaptation');
  stockIn.addToPending();
  assert.equal(stockIn.pending.length,1);
  await app.visit('#stock-out');
  const stockOut=app.state('stock-out');
  stockOut.selected={...app.stockItem};stockOut.qty='2';stockOut.notes='Keep selected entry';
  await app.visit('#history');
  await app.visit('#overview');
  const countBeforeReturn=app.calls.length;
  await app.visit('#products');
  assert.equal(app.calls.length,countBeforeReturn,'cached navigation must not fetch again');
  assert.equal(app.state('products'),products);
  assert.equal(products.q,'Medicine');assert.equal(products.currentPage,2);
  await app.visit('#stock-in');
  assert.equal(stockIn.pending.length,1);
  app.context.showSuccessToast=()=>{};
  await stockIn.submit();
  assert.equal(app.shell.inventoryRevision,1);
  assert.equal(stockIn.pending.length,0);
  await app.visit('#products');
  assert.match(app.calls.at(-1)[1], /page=2&q=Medicine/);
  const refreshedCount=app.calls.length;
  await app.visit('#history');
  assert.equal(app.calls.length,refreshedCount+1);
  await app.visit('#overview');
  assert.equal(app.calls.length,refreshedCount+4);
  app.stockItem.quantity_on_hand=23;app.stockItem.selling_price=60;
  await app.visit('#stock-out');
  assert.equal(stockOut.selected.quantity_on_hand,23);
  assert.equal(stockOut.sellingPrice,60);
  assert.equal(stockOut.qty,'2');assert.equal(stockOut.notes,'Keep selected entry');
  await app.context.InventoryAPI.stockOut([]);
  assert.equal(app.shell.inventoryRevision,2);
  app.fail();
  await assert.rejects(app.context.InventoryAPI.stockIn([]),/Rejected/);
  assert.equal(app.shell.inventoryRevision,2,'failed requests must not invalidate cached sections');
}

async function testDeepLinksAndAlertPaging() {
  const app=setup();
  await app.visit('#history');
  assert.equal(app.panels.length,1);
  assert.match(app.calls.find(c=>c[0]==='GET')[1], /inventory\/transactions/);
  assert.equal(app.scripts.some(url=>url.includes('dashboard')),false);
  await app.visit('#products?filter=low-stock');
  assert.match(app.calls.at(-1)[1], /low_stock=1/);
  await app.visit('#products?filter=expiry');
  assert.equal(app.state('products').expiryItems.length,1);
  assert.equal(app.calls.at(-1)[1],'/inventory/alerts/expiry?page=1');
  await app.state('products').load(2);
  assert.equal(app.calls.at(-1)[1],'/inventory/alerts/expiry?page=2');
  await app.visit('#products');
  assert.doesNotMatch(app.calls.at(-1)[1], /low_stock/);
  await app.visit('#invalid');
  assert.equal(app.context.location.hash,'#overview');
}

async function testDefaultAndDirectActionRoutes() {
  const overview=setup();
  overview.context.location.hash='';
  await overview.shell.init();
  assert.equal(overview.context.location.hash,'#overview');
  assert.equal(overview.shell.activeSection,'overview');

  for (const [section,title] of [['stock-in','Stock In'],['stock-out','Stock Out']]) {
    const app=setup();
    app.context.location.hash='#'+section;
    await app.shell.init();
    assert.equal(app.context.location.hash,'#'+section,'direct action links must survive initialization/refresh');
    assert.equal(app.shell.activeSection,section);
    assert.equal(app.context.document.title,`${title} | Bethlehem Animal Clinic`);
    assert.equal(app.panels.length,1);
    assert.equal(app.panels[0].dataset.inventorySection,section);
    assert.equal(app.scripts.some(url=>url.includes('dashboard')),false,'action views must remain independently lazy loaded');
    await app.visit('#overview');
    assert.equal(app.shell.activeSection,'overview');
  }
}

async function testSellProductUsesTheShellAndInvalidatesStockViews() {
  const app=setup();
  await app.visit('#sell-product');
  assert.equal(app.shell.activeSection,'sell-product');
  assert.equal(app.context.document.title,'Point of Sale | Bethlehem Animal Clinic');
  assert.equal(app.scripts.some(url=>url.includes('admin-pos.js')),true);
  assert.equal(app.scripts.some(url=>url.includes('product-sale-invoice.js')),true);
  assert.equal(app.scripts.some(url=>url.includes('dashboard')),false);
  const pos=app.state('sell-product');
  pos.cart=[{item_id:1,quantity:2}];pos.amountTendered='500';
  await app.context.InventoryAPI.validateCart({items:[{item_id:1,quantity:2}]});
  assert.equal(app.shell.inventoryRevision,0,'Read-only validation must not invalidate stock views');
  await app.visit('#products');
  await app.context.InventoryAPI.processSale({items:[]});
  assert.equal(app.shell.inventoryRevision,1,'Completed POS requests refresh stock views');
  await app.visit('#sell-product');
  assert.equal(app.state('sell-product'),pos);
  assert.equal(pos.cart[0].quantity,2);
  assert.equal(pos.amountTendered,'500');
  const calls=app.calls.length;
  await app.visit('#products');
  assert.equal(app.calls.length,calls+1,'Products reload after a POS deduction');
}

async function testRapidNavigationDoesNotLoadHiddenData() {
  const app=setup();
  let resolve;
  app.unblock(new Promise(r=>{resolve=r;}));
  app.context.location.hash='#products';
  const first=app.shell.showSection();
  app.context.location.hash='#history';
  const second=app.shell.showSection();
  resolve();
  await Promise.all([first,second]);
  assert.equal(app.shell.activeSection,'history');
  assert.deepEqual(app.calls.filter(c=>c[0]==='GET').map(c=>c[1]),['/inventory/transactions?page=1']);
}

async function testCameraStopsEvenWhenNavigationInterruptsStartup() {
  for (const section of ['stock-in','stock-out','sell-product']) {
    const app=setup();
    await app.visit('#'+section);
    const state=app.state(section);
    const frames=[];
    let starts=0, stops=0, started=false, resolveStartup;
    app.context.requestAnimationFrame=callback=>frames.push(callback);
    app.context.Html5Qrcode=class {
      start(){starts++;return new Promise(resolve=>{resolveStartup=()=>{started=true;resolve();};});}
      stop(){stops++;if(!started)throw new Error('Still starting');return Promise.resolve();}
    };
    state.openScanner();
    state.leave();
    while(frames.length)frames.shift()();
    assert.equal(starts,0,'leaving before the animation frame must cancel camera startup');
    state.openScanner();
    while(frames.length)frames.shift()();
    assert.equal(starts,1);
    state.leave();
    resolveStartup();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(stops,2,'camera startup must stop after navigation even if the first stop was too early');
    assert.equal(state.scannerActive,false);
  }
}

function testSidebarNavigation() {
  const context={window:{location:{pathname:'/pages/admin/inventory/inventory.html',hash:'#products'}},document:{},URL};
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-sidebar.js'),context);
  const sidebar=context.adminSidebar();
  assert.equal(sidebar.activePage,'inventory');
  assert.equal(sidebar.inventoryExpanded,true);
  assert.equal(sidebar.inventorySection,'products');
  context.window.location.hash='#invalid';
  assert.equal(context.getAdminInventorySection(),'overview');
  context.window.location.pathname='/pages/admin/inventory/pos.html';
  assert.equal(context.getAdminInventorySection(),'');
  let group;
  const link={href:'http://localhost/pages/admin/inventory/inventory.html',className:'sidebar-link',innerHTML:'<i data-lucide="box"></i><span>Inventory</span>',getAttribute:()=>"activePage === 'inventory' ? 'active' : ''",replaceWith:element=>{group=element;}};
  context.document.querySelector=()=>link;
  context.document.createElement=()=>({attrs:{},children:[],setAttribute(key,value){this.attrs[key]=value;},append(...children){this.children.push(...children);},appendChild(child){this.children.push(child);}});
  context.installAdminInventoryNavigation();
  assert.equal(group.children[0].attrs['aria-controls'],'inventory-submenu');
  assert.deepEqual(group.children[1].children.map(child=>child.textContent),['Overview','Products','Point of Sale','Stock Movements']);
  assert.equal(group.children[1].children[1].href,'/pages/admin/inventory/inventory.html#products');
  assert.equal(group.children[1].children[1].attrs['@click'],'sidebarOpen = false');
  assert.match(group.children[1].children[1].attrs[':aria-current'],/inventorySection === 'products'/);

  const mobileTitle=read('pages/admin/inventory/inventory.html').match(/x-text="([^"]*\[inventorySection\][^"]*)"/)[1];
  for (const [section,title] of [['stock-in','Stock In'],['stock-out','Stock Out'],['sell-product','Point of Sale'],['history','Stock Movements']]) {
    context.window.location.pathname='/pages/admin/inventory/inventory.html';
    context.window.location.hash='#'+section;
    const actionSidebar=context.adminSidebar();
    assert.equal(actionSidebar.activePage,'inventory');
    assert.equal(actionSidebar.inventoryExpanded,true);
    assert.equal(actionSidebar.inventorySection,section);
    if (section.startsWith('stock-')) for (const child of group.children[1].children) {
      assert.equal(vm.runInNewContext(child.attrs[':class'],actionSidebar),'','no destination may be falsely highlighted in an action view');
      assert.equal(vm.runInNewContext(child.attrs[':aria-current'],actionSidebar),null);
    }
    assert.equal(vm.runInNewContext(mobileTitle,actionSidebar),title);
  }
}

function testUnifiedEntryAndSectionMarkup() {
  const inventoryDirectory=path.join(root,'pages/admin/inventory');
  assert.deepEqual(fs.readdirSync(inventoryDirectory).filter(name=>name.endsWith('.html')).sort(),['inventory.html','pos.html']);
  assert.match(read('pages/admin/inventory/inventory.html'), /x-data="adminInventory\(\)"/);
  for(const section of ['overview','products','sell-product','stock-in','stock-out','history']) {
    const fragment=read(`pages/admin/inventory/sections/${section}.html`);
    assert.doesNotMatch(fragment, /data-lucide|<span[^>]*>Back<\/span>|<script|<main/);
  }
  const sidebar=read('scripts/components/admin-sidebar.js');
  assert.match(sidebar, /aria-expanded/);
  assert.match(sidebar, /aria-current/);

  const overview=read('pages/admin/inventory/sections/overview.html');
  const shell=read('pages/admin/inventory/inventory.html');
  const header=shell.slice(shell.indexOf('data-admin-heading'), shell.indexOf('</section>', shell.indexOf('data-admin-heading')));
  assert.match(header,/Monitor products, stock levels, alerts, and recent activity\./);
  assert.match(header,/href="#stock-in"[^>]*>Stock In<\/a>/);
  assert.match(header,/href="#stock-out"[^>]*>Stock Out<\/a>/);
  assert.match(overview,/href="#stock-in"[^>]*>Restock<\/a>/);
  assert.doesNotMatch(overview,/New Sale|href=["'][^"']*pos\.html|scannerActive|pending\.length/i);
  for (const section of ['stock-in','stock-out']) {
    const start=shell.indexOf(`activeSection === '${section}'`);
    const header=shell.slice(start, shell.indexOf('</section>',start));
    assert.match(header,/href="#overview"[^>]*>.*Back to Overview<\/a>/);
    assert.match(header,/focus-visible:outline/);
  }
}

(async()=>{
  await testLazyNavigationAndMutationRefresh();
  await testDeepLinksAndAlertPaging();
  await testDefaultAndDirectActionRoutes();
  await testSellProductUsesTheShellAndInvalidatesStockViews();
  await testRapidNavigationDoesNotLoadHiddenData();
  await testCameraStopsEvenWhenNavigationInterruptsStartup();
  testSidebarNavigation();
  testUnifiedEntryAndSectionMarkup();
  console.log('frontend unified inventory regression checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
