const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const fields = new Map();
function field(id) {
  if (!fields.has(id)) fields.set(id, { value: '', textContent: '', disabled: false,
    setAttribute() {}, focus() {} });
  return fields.get(id);
}
let now = 100000, requests = 0;
const timers = new Map();
const context = {
  Date: { now: () => now },
  document: { activeElement: null },
  window: { setInterval(fn) { timers.set(1, fn); return 1; }, clearInterval(id) { timers.delete(id); },
    PhilippineMobile: { unformat: v => v, formatError: () => '' } },
  API: { verifyPhoneVerification() { requests++; }, sendPhoneVerification() { requests++; }, resendPhoneVerification() { requests++; } },
  testDialog: { querySelector: selector => field(selector.slice('#phoneVerification'.length)), open: false },
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/auth/verification-code.js'), 'utf8'), context);
const source = fs.readFileSync(path.join(__dirname, '../scripts/components/customer-phone-verification.js'), 'utf8')
  .replace(/^import .*;\r?\n/gm, '').replace(/export /g, '').replace(/import\.meta\.url/g, '"https://example.test/"');
vm.runInContext(source + '\ndialog = testDialog; allowedPrefixes = ["0917"]; codeControl = { clear() {} };', context);
field('Phone').value = '09171234567';
field('Code').value = '123456';
for (const seconds of [60, 180, 300]) {
  context.setTiming({ challenge_id: 'same-code', status: 'active', can_verify: true,
    retry_after: seconds, resend_after: 60, expires_in: 600 });
  vm.runInContext('step = "challenge";', context);
  context.updateControls();
  assert.equal(field('Verify').disabled, true);
  assert.match(field('CodeCooldown').innerHTML, /Too many incorrect codes/);
  const announcement = field('CooldownStatus').textContent;
  now += 1000; timers.get(1)();
  assert.match(field('CodeCooldown').innerHTML, new RegExp('>' + (seconds === 60 ? '0:59' : seconds === 180 ? '2:59' : '4:59') + '<'));
  assert.equal(field('CooldownStatus').textContent, announcement);
  assert.match(field('VerifyLabel').textContent, /Try again in/);
  now += (seconds - 2) * 1000;
  timers.get(1)();
  assert.match(field('CodeCooldown').innerHTML, />0:01</);
  now += 1000;
  timers.get(1)();
  assert.equal(field('Verify').disabled, false, 'The same unexpired code becomes usable automatically');
  assert.equal(field('Code').value, '123456');
  assert.equal(field('CodeCooldown').hidden, true);
  assert.equal(field('Message').textContent, '');
  assert.equal(requests, 0, 'Countdown completion never sends or verifies');
}
console.log('Phone authentication cooldown regression tests passed.');
