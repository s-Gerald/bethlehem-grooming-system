const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

function page({ deliveryFailed = false, noStorage = false, deliveryStatus = null, signup = false, editing = false, reviewing = false, missingGrant = false, phone = '' } = {}) {
  let now = 100000;
  const intervals = new Map();
  const timeouts = new Map();
  const calls = [];
  const storage = new Map([
    ['pendingVerificationEmail', 'customer@gmail.com'],
    ['pendingVerificationPhone', '09171234542'],
    ['pendingRegistrationEditToken', 'private-edit-credential'],
    ['pendingVerificationResendUntil', String(now + (deliveryFailed ? 0 : 45000))],
    ['pendingVerificationDeliveryFailed', deliveryFailed ? '1' : '0'],
  ]);
  if (editing) storage.set('pendingSignupDetails', JSON.stringify({ firstName: 'Test', lastName: 'Customer', email: 'customer@gmail.com', phone }));
  if (reviewing) {
    storage.set('pendingSignupDetails', JSON.stringify({ firstName: 'Saved', lastName: 'Customer', email: 'customer@gmail.com', phone }));
    if (!missingGrant) storage.set('pendingRegistrationRecoveryToken', 'ownership-grant');
  }
  let nextId = 0;
  let ready;
  const elements = new Map();
  const document = {
    activeElement: null,
    createElement: () => element(`generated-${++nextId}`),
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
        getAttribute(name) { return this.attributes[name] || ''; },
        append(...children) { this.children = children; },
        insertAdjacentElement(_where, el) { this.adjacent = el; },
        dispatchEvent(event) { handlers[event.type]?.call(this, event); },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, handler) { handlers[name] = handler; },
        setSelectionRange(start, end) { this.selection = [start, end]; },
        focus() { document.activeElement = this; handlers.focus?.(); },
        checkValidity() { return id === 'signupForm' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value); },
        reportValidity() {},
        setCustomValidity(message) { this.validationMessage = message; },
        querySelector() { return null; },
        querySelectorAll() { return cells; },
      });
    }
    return elements.get(id);
  }
  const cells = Array.from({ length: 6 }, (_, i) => element(`cell${i}`));
  const window = {
    location: { search: reviewing ? '?review=1' : editing ? '?edit=1' : '', hash: '', replace(target) { calls.push(['redirect', target]); } },
    setInterval(callback) { const id = ++nextId; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    clearTimeout(id) { timeouts.delete(id); },
    setTimeout(callback, ms) { const id = ++nextId; timeouts.set(id, { callback, at: now + ms }); return id; },
  };
  const API = {
    async register(payload) { calls.push(['register', payload]); return { requires_verification: true, registration_token: 'rotated-edit-token' }; },
    async updatePendingRegistration(payload, token, grant) { calls.push(['update', payload, token, grant]); return grant ? { token: 'session', user: { role: 'customer' } } : { requires_verification: true, registration_token: 'rotated-edit-token' }; },
    getEmailDeliveryToken: () => deliveryStatus ? 'private-delivery-receipt' : '',
    emailDeliveryStatus: async () => ({ status: deliveryStatus }),
    async resendVerification(email) { calls.push(['send', email]); return { resend_after: 45 }; },
    async verifyEmail(email, code) { calls.push(['verify', email, code]); return { token: 'session', user: { role: 'customer' } }; },
  };
  const context = vm.createContext({
    window, document, API, URLSearchParams, Event,
    Date: { now: () => now },
    sessionStorage: {
      getItem(key) { if (noStorage) throw Error('blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (noStorage) throw Error('blocked'); storage.set(key, value); },
      removeItem(key) { if (noStorage) throw Error('blocked'); storage.delete(key); },
    },
  });
  if (signup) {
    for (const [id, value] of Object.entries({ firstName: 'Test', lastName: 'Customer', email: 'customer@gmail.com', phone, password: 'strong-password', confirmPassword: 'strong-password' })) element(id).value = value;
  }
  for (const source of signup ? ['scripts/utils/account-email.js', 'scripts/auth/client/signup.js'] : ['scripts/auth/verification-code.js', 'scripts/auth/email-delivery.js', 'scripts/auth/client/signup-verification.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, source), 'utf8'), context, { filename: source });
  }
  ready();
  return {
    element, cells, API, calls, storage, activeElement: () => document.activeElement,
    dispatch(id, event, extra = {}) { return element(id).handlers[event].call(element(id), { preventDefault() {}, ...extra }); },
    advance(ms) {
      now += ms;
      for (const [id, timeout] of [...timeouts]) if (timeout.at <= now) { timeouts.delete(id); timeout.callback(); }
      for (const callback of [...intervals.values()]) callback();
    },
    type(value) { element('signupCode').value = value; this.dispatch('signupCode', 'input'); },
  };
}

(async () => {
  const signupHtml = fs.readFileSync(path.join(root, 'pages/client/signup.html'), 'utf8');
  const requiredFields = { firstName: 'First Name', lastName: 'Last Name', email: 'Email Address', password: 'Password', confirmPassword: 'Confirm Password' };
  const optionalFields = { username: 'Username', phone: 'Mobile Number' };
  for (const [id, title] of Object.entries({ ...requiredFields, ...optionalFields })) {
    const label = signupHtml.match(new RegExp(`<label\\s+for="${id}"[^>]*>([\\s\\S]*?)</label>`))[1];
    const text = label.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    const input = signupHtml.match(new RegExp(`<input\\s[^>]*id="${id}"[^>]*>`))[0];
    if (id in requiredFields) {
      assert.equal(text, `${title} *`);
      assert.match(label, /<span class="text-red-500">\*<\/span>/);
      assert.match(input, /\srequired(?:\s|\/|>)/);
    } else {
      assert.equal(text, `${title} (Optional)`);
      assert.doesNotMatch(label, /text-red|\*/);
      assert.doesNotMatch(input, /\srequired(?:\s|\/|>)/);
    }
  }
  assert.match(signupHtml, /Track your pet’s grooming status/);
  assert.doesNotMatch(signupHtml, /Track pre-registrations and visit status/);
  assert.ok(signupHtml.indexOf('for="email"') < signupHtml.indexOf('for="username"'));

  for (const editing of [false, true]) {
    for (const phone of ['', '09171234567']) {
      const signup = page({ signup: true, editing, phone });
      signup.dispatch('phone', 'input');
      assert.equal(signup.element('phone').validationMessage, '');
      await signup.dispatch('signupForm', 'submit');
      assert.equal(signup.calls[0]?.[0], editing ? 'update' : 'register');
      assert.equal(signup.calls[0][1].phone, phone || null);
      assert.equal(signup.calls[0][1].email, 'customer@gmail.com');
      if (editing) assert.equal(signup.calls[0][2], 'private-edit-credential');
      assert.equal(signup.storage.get('pendingRegistrationEditToken'), 'rotated-edit-token');
      assert.deepEqual(signup.calls.at(-1), ['redirect', './verify-email.html']);
      assert.equal(signup.calls.length, 2, 'One signup request and the existing verification redirect');
    }
  }
  const invalidEmail = page({ signup: true });
  invalidEmail.element('email').value = '123@123.com';
  await invalidEmail.dispatch('signupForm', 'submit');
  assert.equal(invalidEmail.calls.length, 0, 'Unsupported providers cannot start signup or OTP');
  assert.equal(invalidEmail.element('email').attributes['aria-invalid'], 'true');
  assert.ok(invalidEmail.element('emailError').textContent);
  invalidEmail.element('email').value = 'ab@gmail.com';
  invalidEmail.dispatch('email', 'input');
  assert.equal(invalidEmail.element('emailError').textContent, '');
  assert.equal(invalidEmail.element('email').attributes['aria-invalid'], 'false');
  invalidEmail.dispatch('email', 'blur');
  assert.equal(invalidEmail.element('emailError').textContent, 'Use at least 3 characters before @.');
  invalidEmail.element('email').value = 'abc@gmail.com';
  invalidEmail.dispatch('email', 'input');
  await invalidEmail.dispatch('signupForm', 'submit');
  assert.equal(invalidEmail.calls[0][0], 'register');

  const staleEmail = page({ signup: true });
  let rejectStale;
  staleEmail.API.register = () => new Promise((_resolve, reject) => { rejectStale = reject; });
  const staleSubmission = staleEmail.dispatch('signupForm', 'submit');
  staleEmail.element('email').value = 'corrected@gmail.com';
  staleEmail.dispatch('email', 'input');
  rejectStale({ errors: { email: ['This email is already in use.'] } });
  await staleSubmission;
  assert.equal(staleEmail.element('emailError').textContent, '');
  assert.equal(staleEmail.element('email').attributes['aria-invalid'], 'false');

  const invalidPhone = page({ signup: true, phone: '08123456789' });
  await invalidPhone.dispatch('signupForm', 'submit');
  assert.equal(invalidPhone.calls.length, 0);
  assert.match(invalidPhone.element('signupMessage').textContent, /Phone number must/);
  invalidPhone.element('phone').value = '';
  invalidPhone.dispatch('phone', 'input');
  assert.equal(invalidPhone.element('phone').validationMessage, '');
  await invalidPhone.dispatch('signupForm', 'submit');
  assert.equal(invalidPhone.calls[0][1].phone, null);

  const abandoned = page({ signup: true });
  abandoned.API.register = async () => ({ requires_verification: true, resend_after: 0 });
  await abandoned.dispatch('signupForm', 'submit');
  assert.equal(abandoned.element('signupRecovery').classList.contains('hidden'), true, 'A public response cannot establish an unfinished registration');
  assert.equal(abandoned.element('firstName').value, 'Test');
  assert.equal(abandoned.element('password').value, 'strong-password');
  assert.deepEqual(abandoned.calls, [['redirect', './verify-email.html']], 'Reuse verification without a second request or status-specific panel');
  assert.equal(abandoned.storage.has('pendingRegistrationEditToken'), false);
  assert.equal(abandoned.storage.has('pendingSignupDetails'), false, 'Unproven submitted details cannot become recovered account details');

  const reviewing = page({ signup: true, reviewing: true });
  assert.equal(reviewing.element('firstName').value, 'Saved');
  assert.equal(reviewing.element('email').readOnly, true);
  assert.equal(reviewing.element('signupRecovery').classList.contains('hidden'), false, 'Email-proven recovery retains its existing panel design');
  await reviewing.dispatch('signupRecoveryContinue', 'click');
  assert.equal(reviewing.activeElement(), reviewing.element('firstName'));
  assert.equal(reviewing.calls.length, 0, 'The confirmed email needs no second verification email');
  await reviewing.dispatch('signupForm', 'submit');
  assert.equal(reviewing.calls[0][0], 'update');
  assert.equal(reviewing.calls[0][3], 'ownership-grant');
  assert.deepEqual(reviewing.calls.at(-1), ['redirect', './dashboard.html']);
  assert.equal(reviewing.storage.has('pendingRegistrationRecoveryToken'), false);

  const unprovenReview = page({ signup: true, reviewing: true, missingGrant: true });
  assert.equal(unprovenReview.element('signupRecovery').classList.contains('hidden'), true);
  assert.deepEqual(unprovenReview.calls, [['redirect', './verify-email.html?recover=1']], 'A lost recovery grant returns to email proof');

  const ownership = page();
  ownership.API.verifyEmail = async () => ({ requires_registration_review: true, registration_recovery_token: 'ownership-grant',
    details: { first_name: 'Saved', last_name: 'Customer', email: 'customer@gmail.com', phone: null, username: null } });
  ownership.type('123456');
  await ownership.dispatch('signupCodeForm', 'submit');
  assert.deepEqual(ownership.calls, [['redirect', './signup.html?review=1']]);
  assert.equal(ownership.storage.get('pendingRegistrationRecoveryToken'), 'ownership-grant');
  assert.equal(JSON.parse(ownership.storage.get('pendingSignupDetails')).firstName, 'Saved');

  const interrupted = page({ signup: true });
  let failSignup;
  let submissions = 0;
  interrupted.API.register = () => { submissions++; return new Promise((_resolve, reject) => { failSignup = reject; }); };
  const pendingSignup = interrupted.dispatch('signupForm', 'submit');
  await interrupted.dispatch('signupForm', 'submit');
  assert.equal(submissions, 1);
  assert.equal(interrupted.element('signupSubmit').disabled, true);
  failSignup({ status: 422, errors: { email: ['Enter a valid email address.'] } });
  await pendingSignup;
  assert.equal(interrupted.element('signupSubmit').disabled, false);
  assert.equal(interrupted.element('email').value, 'customer@gmail.com');
  assert.equal(interrupted.element('password').value, 'strong-password');
  assert.equal(interrupted.element('email').attributes['aria-invalid'], 'true');
  assert.equal(interrupted.element('emailError').textContent, 'Enter a valid email address.');

  const expiredCode = page();
  expiredCode.API.verifyEmail = async () => { throw { status: 422, expired: true }; };
  expiredCode.type('123456');
  await expiredCode.dispatch('signupCodeForm', 'submit');
  assert.equal(expiredCode.element('signupCodeMessage').textContent, 'This code has expired. Request a new one.');
  assert.deepEqual(expiredCode.calls, []);

  const p = page();
  assert.equal(p.element('verificationTitle').textContent, 'Verify your email');
  assert.equal(p.element('verificationDescription').textContent, 'Enter the 6-digit code for');
  assert.equal(p.element('verifyCodeEmail').textContent, 'c***@gmail.com');
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
  assert.equal(p.element('signupCodeMessage').attributes.role, 'status', 'A successful resend is a status confirmation, not an error alert');
  assert.deepEqual(p.calls, [['send', 'customer@gmail.com']]);
  assert.equal(p.element('signupResend').textContent, 'Resend in 0:45');
  p.type('654321');
  await p.dispatch('signupCodeForm', 'submit');
  assert.deepEqual(p.calls.at(-1), ['verify', 'customer@gmail.com', '654321']);
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
  assert.equal(transportAccepted.element('verifyCodeEmail').textContent, 'c***@gmail.com');
  assert.deepEqual(transportAccepted.calls, [], 'Delivery receipt alone cannot authenticate');

  const throttled = page({ deliveryFailed: true });
  assert.match(throttled.element('signupCodeMessage').textContent, /Couldn't send the code/);
  assert.equal(throttled.element('signupResend').disabled, false);
  throttled.API.resendVerification = async () => { throw { status: 429, retryAfter: 90 }; };
  await throttled.dispatch('signupResend', 'click');
  assert.equal(throttled.element('signupResend').textContent, 'Resend in 1:30');
  assert.equal(throttled.element('signupCodeMessage').textContent, 'Too many attempts. Try again later.');
  assert.equal(throttled.element('signupCodeMessage').attributes.role, 'alert', 'A failed resend must still announce its error');
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
  recovery.element('verificationEmail').value = 'restore@gmail.com';
  await recovery.dispatch('signupResend', 'click');
  assert.deepEqual(recovery.calls, [['send', 'restore@gmail.com']]);

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
