const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");

const sandbox = { window: {}, Intl, Date, Map, Object, String, Number };
vm.runInNewContext(
  fs.readFileSync(path.join(__dirname, "../scripts/components/client-notification-ui.js"), "utf8"),
  sandbox,
);
const ui = sandbox.window.ClientNotificationUI;
const body = { appendChild(node) { node.parentElement = this; } };
sandbox.document = { body, documentElement: { clientWidth: 0 } };
sandbox.window.innerHeight = 844;
for (const [viewport, right, bottom, expectedWidth, expectedLeft] of [
  [320, 62, 120, 296, 12],
  [375, 126, 148, 351, 12],
  [693, 126, 148, 384, 12],
  [1425, 1160, 74, 384, 776],
  [1425, 1440, 74, 384, 1029],
]) {
  sandbox.document.documentElement.clientWidth = viewport;
  sandbox.window.innerWidth = viewport + 15; // A scrollbar is not available panel space.
  const panel = { style: {}, parentElement: {} };
  ui.positionDropdown({ getBoundingClientRect: () => ({ right, bottom }) }, panel);
  assert.equal(panel.style.width, `${expectedWidth}px`);
  assert.equal(panel.style.left, `${expectedLeft}px`);
  assert.equal(panel.style.right, 'auto');
  assert.equal(panel.style.top, `${bottom + 8}px`);
  assert.equal(panel.parentElement, body, 'The fixed panel escapes the sticky header containing block');
}
const today = ui.dateKey(new Date());
const earlier = ui.dateKey(new Date(Date.now() - 2 * 86400000));
const notifications = [
  { id: 1, type: "grooming_finished", display_message: "Max is Finished with grooming.", pet_names: ["Max"], is_read: false, created_at: `${today} 09:30:00` },
  { id: 2, type: "booking_cancelled", display_message: "<script>alert(1)</script>", is_read: true, created_at: `${earlier} 11:00:00` },
];

const dropdown = { innerHTML: "" };
ui.render(dropdown, notifications, "all", true);
assert.match(dropdown.innerHTML, /Today/);
assert.match(dropdown.innerHTML, /Earlier/);
assert.match(dropdown.innerHTML, /phosphor\.svg#check-circle/);
assert.match(dropdown.innerHTML, /phosphor\.svg#x-circle/);
assert.match(dropdown.innerHTML, /<strong class="client-notification-emphasis">Max<\/strong>/);
assert.doesNotMatch(dropdown.innerHTML, /<script>/);
assert.match(dropdown.innerHTML, /&lt;script&gt;/);

const unread = { innerHTML: "" };
ui.render(unread, notifications, "unread", true);
assert.match(unread.innerHTML, /Max<\/strong> is/);
assert.doesNotMatch(unread.innerHTML, /alert\(1\)/);

const page = { innerHTML: "" };
ui.render(page, notifications, "all", false);
assert.match(page.innerHTML, /data-client-notification-index="0"/);
assert.match(page.innerHTML, /data-client-notification-index="1"/);
console.log("Client notification UI regression checks passed.");

const updates = { innerHTML: "" };
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["Andy"],
  message: "Bethlehem Animal Clinic updated Andrew's breed, fur type, and color.", is_read: false }], "all", true, () => "Legacy dashboard formatter");
for (const text of ["Andrew", "breed", "fur type", "color"]) {
  assert.ok(updates.innerHTML.includes(`<strong class="client-notification-emphasis">${text}</strong>`));
}
assert.ok(!updates.innerHTML.includes('<strong class="client-notification-emphasis">Bethlehem'));
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["<img src=x onerror=alert(1)>"],
  message: "Bethlehem Animal Clinic updated <img src=x onerror=alert(1)>'s weight.", is_read: false }]);
assert.doesNotMatch(updates.innerHTML, /<img|<script/);
assert.match(updates.innerHTML, /&lt;img/);
ui.render(updates, [{ type: "pet_information_updated", pet_names: ["Andy"],
  message: "Bethlehem Animal Clinic changed your pet's name from Andrew to Andy.", is_read: false }]);
assert.match(updates.innerHTML, /<strong class="client-notification-emphasis">Andrew<\/strong> to <strong class="client-notification-emphasis">Andy<\/strong>/);

// Optional localhost browser coverage; all API traffic uses synthetic customer fixtures.
async function testCustomerHeadersInBrowser() {
  const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
  const { profile, pets, bookings } = require('./customer-data-cache-regression.cjs');
  const browser = await chromium.launch({channel:'msedge',headless:true});
  const context = await browser.newContext({viewport:{width:375,height:844}});
  const origin = 'http://127.0.0.1:8000', errors = [];
  const items = Array.from({length:24},(_,index)=>({id:index+1,type:'booking_cancelled',title:'Registration update',
    message:`Fixture notification ${index+1}. Your registration status has been updated.`,is_read:index>1,
    created_at:new Date().toISOString(),destination:'/account/pets'}));
  let notificationReads = 0;
  try {
    await context.addInitScript(()=>{localStorage.setItem('customer_token','1|customer-header-fixture');localStorage.setItem('user_role','customer');});
    await context.route('**/api/**',async route=>{
      const url = new URL(route.request().url());
      assert.equal(url.origin,origin,'Fixture requests stay on localhost');
      let data;
      if (url.pathname.startsWith('/api/session')) data={locked:false,role:'customer',user_id:1,
        server_now:new Date().toISOString(),idle_at:new Date(Date.now()+1800000).toISOString(),expires_at:new Date(Date.now()+43200000).toISOString()};
      else if(url.pathname==='/api/me') data=profile;
      else if(url.pathname==='/api/pets') data=url.searchParams.get('archived')==='1'?{pets:[]}:pets;
      else if(url.pathname==='/api/pets/1') data={pet:pets.pets[0]};
      else if(url.pathname==='/api/pets/1/medical-records') data={medical_records:[]};
      else if(url.pathname==='/api/clinic/history') data={medical_records:[]};
      else if(url.pathname==='/api/pets/1/vaccinations') data={vaccinations:[]};
      else if(url.pathname==='/api/booking/history') data=bookings;
      else if(url.pathname==='/api/booking/grooming-capacity') data={capacity:{max:20,used:0,remaining:20},queue:{active:0}};
      else if(url.pathname==='/api/pre-registration/access') data={allowed:true,sms_verification:{available:false,enforced:false}};
      else if(url.pathname==='/api/customer/notifications') {
        notificationReads++; data={notifications:items,unread_count:items.filter(item=>!item.is_read).length,has_more:false};
      } else if(url.pathname==='/api/customer/notifications/read-all') {
        items.forEach(item=>{item.is_read=true;});data={success:true};
      } else throw new Error(`Unexpected fixture API: ${url.pathname}`);
      await route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
    });
    const page=await context.newPage();
    page.on('pageerror',error=>errors.push(error.message));
    const inspect = () => page.evaluate(()=>{
      const rect=selector=>document.querySelector(selector)?.getBoundingClientRect().toJSON();
      const layout=document.querySelector('.customer-header-layout');
      const padding=getComputedStyle(layout);
      const panel=document.getElementById('notifDropdown');
      const r=panel.getBoundingClientRect();
      return {width:document.documentElement.clientWidth,height:innerHeight,overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth,
        layout:rect('.customer-header-layout'),paddingLeft:parseFloat(padding.paddingLeft),paddingRight:parseFloat(padding.paddingRight),
        heading:rect('.customer-header-heading'),title:rect('.customer-header-heading h2'),toggle:rect('#clientSidebarToggle'),bell:rect('#notifBellBtn'),cta:rect('#preRegisterButton'),
        search:rect('.customer-header-search'),panel:r.toJSON(),footer:rect('#notifDropdown a[href="/account/notifications"]'),markAll:rect('#notifMarkAllRead'),
        panelOnTop:panel.contains(document.elementFromPoint(r.left+20,r.top+20)),
        scrollable:document.getElementById('notifList').scrollHeight>document.getElementById('notifList').clientHeight};
    });
    const assertLayout = async (width,height) => {
      await page.setViewportSize({width,height});
      await page.evaluate(()=>new Promise(requestAnimationFrame));
      await page.locator('.customer-header-layout').evaluate(el=>Promise.all(el.closest('main').getAnimations().map(animation=>animation.finished)));
      const g=await inspect();
      assert.equal(g.overflow,false,`${page.url()} ${width}: no horizontal scrollbar`);
      assert.ok(Math.abs(g.cta.right-(g.layout.right-g.paddingRight))<=1,`${width}: registration action reaches the right content edge`);
      assert.ok(g.bell.right<g.cta.left,`${width}: bell immediately precedes registration`);
      assert.ok(g.bell.height>=44 && g.cta.height>=44);
      if(width<=1180){
        assert.ok(g.bell.top>=g.heading.bottom,`${width}: actions occupy the second row`);
        assert.ok(g.title.left>=g.toggle.right+8,`${width}: sidebar toggle does not overlap heading`);
        if(g.search){
          assert.ok(g.search.top>=Math.max(g.bell.bottom,g.cta.bottom),`${width}: search follows the actions`);
          assert.ok(Math.abs(g.search.left-(g.layout.left+g.paddingLeft))<=1);
          assert.ok(Math.abs(g.search.right-g.cta.right)<=1);
        }
      }else if(g.search){
        assert.ok(g.search.right<g.bell.left,'Desktop retains search before bell and registration');
        assert.ok(Math.abs(g.search.top-g.bell.top)<=2);
      }
      assert.ok(g.panel.left>=11.5 && g.panel.right<=g.width-11.5,`${width}: both dropdown edges stay padded (${g.panel.left}, ${g.panel.right})`);
      assert.ok(Math.abs(g.panel.top-g.bell.bottom-8)<=1,`${width}: panel stays below its bell (panel ${g.panel.top}, bell ${g.bell.bottom})`);
      assert.ok(g.panel.bottom<=g.height-11.5,`${width}: dropdown footer remains inside viewport`);
      assert.ok(g.footer.bottom<=g.panel.bottom && g.markAll.left>=g.panel.left && g.markAll.right<=g.panel.right,'Notification header and footer controls are not clipped');
      assert.equal(g.panelOnTop,true,'Dropdown is above sticky header/content');
      assert.equal(g.scrollable,true,'Long notification lists keep their vertical scroll');
    };
    for(const route of ['/account','/account/pets','/account/settings','/account/grooming-history','/account/pets/details?pet_id=1','/account/notifications']){
      await page.goto(origin+route,{waitUntil:'domcontentloaded'});
      await page.waitForFunction(()=>!document.documentElement.hasAttribute('data-session-gate'));
      await page.evaluate(()=>document.fonts.ready);
      const bell=page.locator('#notifBellBtn'),panel=page.locator('#notifDropdown');
      assert.equal((await page.locator('#preRegisterButton').innerText()).trim(),'Start Queue Registration');
      assert.equal(await page.locator('#preRegisterButton').getAttribute('href'),'/account/registration');
      await bell.click();
      await page.waitForFunction(()=>document.querySelectorAll('#notifList [data-client-notification-index]').length===24);
      const readsBeforeResize=notificationReads;
      for(const width of [320,375,390,414,430,640,768,1024,1180,1181,1280,1440]) await assertLayout(width,900);
      for(const zoom of [1.25,1.5,2]) await assertLayout(Math.round(1440/zoom),Math.round(900/zoom));
      assert.equal(notificationReads,readsBeforeResize,'Layout and viewport changes never fetch notifications');
      if(process.env.LOCAL_REVIEW_SCREENSHOTS){
        fs.mkdirSync(process.env.LOCAL_REVIEW_SCREENSHOTS,{recursive:true});
        await assertLayout(375,844);
        await page.screenshot({path:path.join(process.env.LOCAL_REVIEW_SCREENSHOTS,`${new URL(page.url()).pathname.split('/').pop()}-375.png`)});
      }
      await page.keyboard.press('Escape');
      assert.equal(await panel.isVisible(),false);
      assert.equal(await bell.getAttribute('aria-expanded'),'false');
      assert.equal(await page.evaluate(()=>document.activeElement.id),'notifBellBtn');
      await bell.click();
      await page.locator('.customer-header-heading').click();
      assert.equal(await panel.isVisible(),false,'Outside click dismisses the dropdown');
      console.log(`${route}: responsive header, viewport-safe dropdown, zoom-equivalent sizes and dismissal passed`);
    }
    await page.locator('#notifBellBtn').click();
    await page.locator('#notifDropdown [data-client-notification-tab="unread"]').click();
    assert.equal(await page.locator('#notifList [data-client-notification-index]').count(),2);
    await page.locator('#notifMarkAllRead').click();
    await page.getByText('No unread notifications.',{exact:true}).waitFor();
    assert.equal(await page.locator('#notifBadge').isVisible(),false);
    assert.deepEqual(errors,[]);
  }finally{await context.close();await browser.close();}
}
if(process.argv.includes('--browser')) testCustomerHeadersInBrowser().catch(error=>{console.error(error);process.exitCode=1;});
