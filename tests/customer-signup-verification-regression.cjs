const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

function page({ deliveryFailed = false, noStorage = false, deliveryStatus = null } = {}) {
  let now = 100000;
  const intervals = new Map();
  const timeouts = new Map();
  const calls = [];
  const storage = new Map([
    ['pendingVerificationEmail', 'customer@example.test'],
    ['pendingVerificationPhone', '09171234542'],
    ['pendingRegistrationEditToken', 'private-edit-credential'],
    ['pendingVerificationResendUntil', String(now + (deliveryFailed ? 0 : 45000))],
    ['pendingVerificationDeliveryFailed', deliveryFailed ? '1' : '0'],
  ]);
  let nextId = 0;
  let ready;
  const elements = new Map();
  const document = {
    activeElement: null,
    addEventListener(name, callback) { if (name === 'DOMContentLoaded') ready = callback; },
    getElementById: element,
  };
  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set(['hidden']);
      const handlers = {};
      elements.set(id, {
        id, value: '', textContent: '', disabled: false, handlers, attributes: {},
        classList: {
          add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
          contains(name) { return classes.has(name); },
          toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, handler) { handlers[name] = handler; },
        setSelectionRange(start, end) { this.selection = [start, end]; },
        focus() { document.activeElement = this; handlers.focus?.(); },
        checkValidity() { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value); },
        reportValidity() {},
        querySelectorAll() { return cells; },
      });
    }
    return elements.get(id);
  }
  const cells = Array.from({ length: 6 }, (_, i) => element(`cell${i}`));
  const window = {
    location: { search: '', hash: '', replace(target) { calls.push(['redirect', target]); } },
    setInterval(callback) { const id = ++nextId; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    clearTimeout(id) { timeouts.delete(id); },
    setTimeout(callback, ms) { const id = ++nextId; timeouts.set(id, { callback, at: now + ms }); return id; },
  };
  const API = {
    getEmailDeliveryToken: () => deliveryStatus ? 'private-delivery-receipt' : '',
    emailDeliveryStatus: async () => ({ status: deliveryStatus }),
    async resendVerification(email) { calls.push(['send', email]); return { resend_after: 45 }; },
    async verifyEmail(email, code) { calls.push(['verify', email, code]); return { token: 'session', user: { role: 'customer' } }; },
  };
  const context = vm.createContext({
    window, document, API, URLSearchParams,
    Date: { now: () => now },
    sessionStorage: {
      getItem(key) { if (noStorage) throw Error('blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (noStorage) throw Error('blocked'); storage.set(key, value); },
      removeItem(key) { if (noStorage) throw Error('blocked'); storage.delete(key); },
    },
  });
  for (const source of ['scripts/auth/verification-code.js', 'scripts/auth/email-delivery.js', 'scripts/auth/client/signup-verification.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, source), 'utf8'), context, { filename: source });
  }
  ready();
  return {
    element, cells, API, calls, storage,
    dispatch(id, event, extra = {}) { return element(id).handlers[event]({ preventDefault() {}, ...extra }); },
    advance(ms) {
      now += ms;
      for (const [id, timeout] of [...timeouts]) if (timeout.at <= now) { timeouts.delete(id); timeout.callback(); }
      for (const callback of [...intervals.values()]) callback();
    },
    type(value) { element('signupCode').value = value; this.dispatch('signupCode', 'input'); },
  };
}

(async () => {
  const p = page();
  assert.equal(p.element('verificationTitle').textContent, 'Verify your email');
  assert.equal(p.element('verificationDescription').textContent, 'Enter the 6-digit code sent to');
  assert.equal(p.element('verifyCodeEmail').textContent, 'c***@example.test');
  assert.equal(p.element('signupResend').textContent, 'Resend in 0:45');
  assert.equal(p.element('signupCodeSubmit').disabled, true);
  p.type('12a34b567');
  assert.equal(p.element('signupCode').value, '123456');
  assert.deepEqual(p.cells.map(c => c.textContent), ['1', '2', '3', '4', '5', '6']);
  assert.equal(p.cells[5].classList.contains('is-active'), true);
  assert.equal(p.element('signupCodeSubmit').disabled, false);
  let prevented = false;
  p.dispatch('signupCode', 'paste', { preventDefault() { prevented = true; }, clipboardData: { getData: () => '98 76 54' } });
  assert.equal(prevented, true);
  assert.equal(p.element('signupCode').value, '987654');
  assert.deepEqual(p.cells.map(c => c.textContent), ['9', '8', '7', '6', '5', '4']);

  p.advance(45000);
  assert.equal(p.element('signupResend').disabled, false);
  assert.equal([...p.storage.values()].includes('123456'), false, 'OTP must never enter storage');
  for (const digits of ['123456', '12']) {
    const pending = page({ deliveryFailed: true });
    pending.type(digits);
    let reject;
    let requests = 0;
    pending.API.resendVerification = () => { requests++; return new Promise((_resolve, fail) => { reject = fail; }); };
    const sending = pending.dispatch('signupResend', 'click');
    assert.equal(pending.element('signupCode').value, '', 'Clear before the network responds');
    assert.deepEqual(pending.cells.map(cell => cell.textContent), ['', '', '', '', '', '']);
    assert.equal(pending.element('signupCodeSubmit').disabled, true);
    assert.equal(pending.element('signupCode').attributes['aria-invalid'], 'false');
    assert.equal(pending.element('signupResend').textContent, 'Sending...');
    await pending.dispatch('signupResend', 'click');
    assert.equal(requests, 1);
    reject({ status: 503 });
    await sending;
    assert.equal(pending.element('signupCode').value, '');
    assert.match(pending.element('signupCodeMessage').textContent, /Couldn't send/);
  }
  await p.dispatch('signupResend', 'click');
  assert.deepEqual(p.calls, [['send', 'customer@example.test']]);
  assert.equal(p.element('signupResend').textContent, 'Resend in 0:45');
  p.type('654321');
  await p.dispatch('signupCodeForm', 'submit');
  assert.deepEqual(p.calls.at(-1), ['verify', 'customer@example.test', '654321']);
  assert.equal(p.storage.size, 0);
  assert.equal(p.element('stateSuccess').classList.contains('hidden'), false);
  p.advance(1500);
  assert.deepEqual(p.calls.at(-1), ['redirect', './dashboard.html']);

  const transportFailure = page({ deliveryStatus: 'failed' });
  await new Promise(resolve => setImmediate(resolve));
  assert.match(transportFailure.element('signupCodeMessage').textContent, /Couldn't send the code/);
  assert.doesNotMatch(transportFailure.element('verificationDescription').textContent, /We sent/);
  assert.deepEqual(transportFailure.calls, [], 'Delivery failure cannot authenticate or redirect');
  const transportAccepted = page({ deliveryStatus: 'sent' });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(transportAccepted.element('signupCodeMessage').textContent, '');
  assert.equal(transportAccepted.element('verifyCodeEmail').textContent, 'c***@example.test');
  assert.deepEqual(transportAccepted.calls, [], 'Delivery receipt alone cannot authenticate');

  const throttled = page({ deliveryFailed: true });
  assert.match(throttled.element('signupCodeMessage').textContent, /Couldn't send the code/);
  assert.equal(throttled.element('signupResend').disabled, false);
  throttled.API.resendVerification = async () => { throw { status: 429, retryAfter: 90 }; };
  await throttled.dispatch('signupResend', 'click');
  assert.equal(throttled.element('signupResend').textContent, 'Resend in 1:30');
  assert.equal(throttled.element('signupCodeMessage').textContent, 'Too many attempts. Try again later.');
  throttled.advance(90000);
  throttled.API.resendVerification = async () => { throw { status: 503 }; };
  await throttled.dispatch('signupResend', 'click');
  assert.equal(throttled.element('signupResend').disabled, false);
  assert.match(throttled.element('signupCodeMessage').textContent, /Couldn't send the code/);
  throttled.API.verifyEmail = async () => { throw { status: 429 }; };
  throttled.type('123456');
  await throttled.dispatch('signupCodeForm', 'submit');
  assert.equal(throttled.element('signupCodeMessage').textContent, 'Too many attempts. Try again later.');
  assert.equal(throttled.element('signupCode').attributes['aria-invalid'], 'true');

  const recovery = page({ noStorage: true });
  assert.equal(recovery.element('verificationEmailRecovery').classList.contains('hidden'), false);
  recovery.element('verificationEmail').value = 'restore@example.test';
  await recovery.dispatch('signupResend', 'click');
  assert.deepEqual(recovery.calls, [['send', 'restore@example.test']]);

  const recoveredDelivery = page({ deliveryFailed: true, deliveryStatus: 'sent' });
  recoveredDelivery.API.resendVerification = async () => ({ resend_after: 45, email_delivery_token: 'new-private-receipt' });
  await recoveredDelivery.dispatch('signupResend', 'click');
  assert.notEqual(recoveredDelivery.storage.get('pendingVerificationDeliveryFailed'), '1', 'A successful private resend must clear the persisted failure of the initial request');

  for (const status of ['queued', 'sending', 'retrying', 'sent']) {
    const quiet = page({ deliveryStatus: status });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(quiet.element('signupCodeMessage').textContent, '', `${status} must not show a warning`);
    quiet.type('123456');
    assert.equal(quiet.element('signupCodeSubmit').disabled, false, 'Tracking cannot block OTP entry');
  }
  const delayed = page({ deliveryStatus: 'queued' });
  await new Promise(resolve => setImmediate(resolve));
  for (let i = 1; i < 24; i++) {
    delayed.advance(5000);
    await new Promise(resolve => setImmediate(resolve));
  }
  assert.match(delayed.element('signupCodeMessage').textContent, /Email is delayed/);
  delayed.type('123456');
  assert.equal(delayed.element('signupCodeSubmit').disabled, false);
  const expiredTab = page();
  expiredTab.advance(120000);
  assert.equal(expiredTab.element('signupResend').disabled, false, 'Countdown uses elapsed time, including background tabs');
  console.log('Customer signup verification regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
