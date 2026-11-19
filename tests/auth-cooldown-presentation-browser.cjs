// Optional real-browser coverage using production markup/scripts and isolated API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const admin = read('pages/admin/settings.html');
const modal = admin.match(/<form\s+@submit.prevent="confirmSecurityCredentialChange\(\)"[\s\S]*?<\/form>/)[0];
const customer = read('pages/client/settings.html').match(/<dialog\s+id="settingsConfirmation"[\s\S]*?<\/dialog>/)[0];
const server = http.createServer((request, response) => {
  const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
  if (pathname === '/fixture') { response.end('<!doctype html><html><head></head><body></body></html>'); return; }
  const file = path.resolve(root, '.' + pathname);
  if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    response.writeHead(404); response.end(); return;
  }
  response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : file.endsWith('.svg') ? 'image/svg+xml' : 'application/octet-stream');
  response.end(fs.readFileSync(file));
});
const styles = '<link rel="stylesheet" href="/css/output.css"><link rel="stylesheet" href="/css/components/verification-code.css">';

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge', headless: true });
  try {
    for (const width of [320, 375, 414, 768]) {
      const page = await browser.newPage({ viewport: { width, height: 850 } });
      await page.goto(origin + '/fixture');
      await page.addScriptTag({ url: origin + '/scripts/auth/verification-code.js' });
      await page.addScriptTag({ url: origin + '/scripts/components/admin-settings.js' });
      await page.evaluate(() => {
        window.confirmCalls = window.resendCalls = 0;
        window.API = { getUserRole: () => 'admin', getCachedStaffIdentity: () => ({ user: {role: 'admin', admin_type: 'owner'} }),
          confirmSecurityCredentialChange: async () => { window.confirmCalls++; throw {status: 429, retryAfter: 60}; },
          resendSecurityCredentialChangeCode: async () => { window.resendCalls++; } };
        window.cooldownFixture = () => { const state = adminSettings(); state.init = () => {}; return state; };
      });
      await page.setContent(`${styles}<main x-data="cooldownFixture()" style="padding:16px;display:flex;justify-content:center">${modal}</main>`);
      await page.addScriptTag({url: 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js'});
      await page.waitForFunction(() => !!window.Alpine && !!Alpine.$data(document.querySelector('main')).securityVerification);
      await page.clock.install({ time: new Date('2026-11-19T12:00:00Z') });
      await page.clock.pauseAt(new Date('2026-11-19T12:00:00Z'));
      await page.evaluate(async () => {
        window.review = Alpine.$data(document.querySelector('main'));
        review.openSecurityVerification({change_id: 1, confirmation_email: 'o***@gmail.com', purpose: 'Owner username change', resend_after: 37});
        review.securityVerification.digits = ['1','2','3','4','5','6'];
        await review.confirmSecurityCredentialChange();
      });
      const warning = page.locator('#securityVerificationCooldown');
      const confirm = page.getByRole('button', {name: 'Confirm change', exact: true});
      assert.equal(await warning.innerText(), 'Too many incorrect codes. Try again in 1:00.');
      assert.equal(await confirm.isDisabled(), true);
      assert.equal(await confirm.locator('span').evaluate(element => element.getBoundingClientRect().height <= 20), true, `${width}px confirmation label stays on one line`);
      const announcement = await page.evaluate(() => review.securityVerification.cooldownStatus);
      await page.clock.runFor(1000);
      assert.equal(await warning.innerText(), 'Too many incorrect codes. Try again in 0:59.');
      assert.equal(await page.getByRole('button', {name: 'Resend in 0:36'}).isDisabled(), true);
      assert.equal(await page.evaluate(() => review.securityVerification.cooldownStatus), announcement);
      await page.locator('#securityCodeDigit5').fill('7');
      assert.equal(await confirm.isDisabled(), true);
      assert.equal(await page.evaluate(() => window.confirmCalls), 1);
      const presentation = await warning.evaluate(element => ({color: getComputedStyle(element).color,
        background: getComputedStyle(element).backgroundColor, weight: getComputedStyle(element).fontWeight,
        size: getComputedStyle(element).fontSize, timerWeight: getComputedStyle(element.querySelector('span')).fontWeight,
        above: element.previousElementSibling.tagName, live: element.getAttribute('aria-live')}));
      assert.deepEqual(presentation, {color:'rgb(146, 64, 14)', background:'rgb(255, 251, 235)', weight:'400', size:'14px', timerWeight:'600', above:'FIELDSET', live:'off'});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${width}px has no horizontal overflow`);
      if (width === 375) {
        const output = path.join(root, 'storage/app/testing/auth-cooldown-ui.png');
        fs.mkdirSync(path.dirname(output), {recursive: true});
        await page.screenshot({path: output, fullPage: true});
      }
      await page.clock.runFor(36000);
      assert.equal(await page.getByRole('button', {name:'Resend code', exact:true}).isEnabled(), true);
      assert.equal(await confirm.isDisabled(), true);
      await page.clock.runFor(23000);
      await page.clock.runFor(20); // Flush Alpine's deferred x-show transition under the frozen clock.
      assert.equal(await warning.isVisible(), false);
      assert.equal(await confirm.isEnabled(), true);
      assert.deepEqual(await page.evaluate(() => [window.confirmCalls, window.resendCalls]), [1, 0]);
      await page.evaluate(async () => {
        API.confirmSecurityCredentialChange = async () => { throw {status:422, message:'The security code is incorrect.'}; };
        await review.confirmSecurityCredentialChange();
      });
      const error = page.locator('[x-text="securityVerification.error"]');
      assert.equal(await error.innerText(), 'The security code is incorrect.');
      assert.equal(await error.evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(254, 242, 242)');
      await page.close();
    }
    // The customer dialog also executes its production script against real DOM.
    const page = await browser.newPage({viewport:{width:375,height:850}});
    await page.goto(origin + '/fixture');
    await page.setContent(`${styles}${customer}<button id="opener">Change email</button>`);
    await page.addScriptTag({url:origin + '/scripts/auth/verification-code.js'});
    await page.addScriptTag({url:origin + '/scripts/components/customer-settings-confirmation.js'});
    await page.clock.install({time:new Date('2026-11-19T12:00:00Z')});
    await page.clock.pauseAt(new Date('2026-11-19T12:00:00Z'));
    await page.evaluate(() => {
      window.API = { confirmCustomerSecurityChange: async () => { throw new Error('Must not submit automatically'); } };
      window.confirmation = createCustomerSettingsConfirmation({onChallenge(){}, resetPassword(input){input.value='';}});
      confirmation.open('email', document.getElementById('opener'), {challenge:{id:'test', email:'new@gmail.com',expires_at:'2099-01-01',retry_after:60,resend_after:37}});
    });
    await page.locator('#settingsVerificationCode').fill('012345');
    assert.equal(await page.locator('#settingsCodeCooldown').innerText(), 'Too many incorrect codes. Try again in 1:00.');
    await page.clock.runFor(1000);
    assert.equal(await page.locator('#settingsCodeCooldown').innerText(), 'Too many incorrect codes. Try again in 0:59.');
    assert.equal(await page.locator('#settingsConfirmationSubmit').innerText(), 'Verify email');
    assert.equal(await page.locator('#settingsVerificationResend').innerText(), 'Resend code in 0:36');
    await page.clock.runFor(59000);
    assert.equal(await page.locator('#settingsCodeCooldown').isVisible(), false);
    assert.equal(await page.locator('#settingsConfirmationSubmit').isEnabled(), true);
    await page.close();
    const phone = await browser.newPage({viewport:{width:375,height:850}});
    await phone.goto(origin + '/fixture');
    await phone.setContent(`${styles}<button id="phoneOpener">Verify phone</button>`);
    await phone.clock.install({time:new Date('2026-11-19T12:00:00Z')});
    await phone.clock.pauseAt(new Date('2026-11-19T12:00:00Z'));
    await phone.evaluate(async origin => {
      window.phoneRequests = 0;
      window.API = {getPhoneVerificationState: async () => ({allowed_prefixes:['0917'], challenge:{success:true, challenge_id:'test', status:'active',
        can_verify:true, retry_after:60, resend_after:37, expires_in:120, masked_destination:'0917 •••• 567'}}),
        verifyPhoneVerification: async () => { window.phoneRequests++; }, resendPhoneVerification: async () => { window.phoneRequests++; }};
      const {openPhoneVerification} = await import(origin + '/scripts/components/customer-phone-verification.js');
      await openPhoneVerification(document.getElementById('phoneOpener'), {phone:'09171234567'}, {navigate:false});
    }, origin);
    assert.equal(await phone.locator('#phoneVerificationCodeCooldown').innerText(), 'Too many incorrect codes. Try again in 1:00.');
    await phone.clock.runFor(1000);
    assert.equal(await phone.locator('#phoneVerificationCodeCooldown').innerText(), 'Too many incorrect codes. Try again in 0:59.');
    assert.equal(await phone.locator('#phoneVerificationResend').innerText(), 'Resend Code in 0:36');
    assert.equal(await phone.locator('#phoneVerificationExpiry').innerText(), 'Code expires in 1m 59s');
    await phone.clock.runFor(36000);
    assert.equal(await phone.locator('#phoneVerificationResend').isEnabled(), true);
    assert.equal(await phone.locator('#phoneVerificationVerify').isDisabled(), true);
    await phone.clock.runFor(23000);
    assert.equal(await phone.locator('#phoneVerificationCodeCooldown').isVisible(), false);
    await phone.locator('#phoneVerificationCode').fill('012345');
    assert.equal(await phone.locator('#phoneVerificationVerify').isEnabled(), true);
    assert.equal(await phone.evaluate(() => window.phoneRequests), 0);
    await phone.close();
    console.log('Authentication cooldown browser checks passed at 320/375/414/768px.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
