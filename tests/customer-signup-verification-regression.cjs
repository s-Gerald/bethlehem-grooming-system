const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

function page({ deliveryFailed = false, noStorage = false, deliveryStatus = null, signup = false, signIn = false, reviewing = false, missingGrant = false, phone = '', search = '', initialStorage = {} } = {}) {
  let now = 100000;
  const intervals = new Map();
  const timeouts = new Map();
  const calls = [];
  const storage = new Map([
    ['pendingVerificationEmail', 'customer@gmail.com'],
    ['pendingVerificationPhone', '09171234542'],
    ['pendingRegistrationEditToken', 'private-edit-credential'],
    ['pendingVerificationResendUntil', String(now + (deliveryFailed ? 0 : 60000))],
    ['pendingVerificationDeliveryFailed', deliveryFailed ? '1' : '0'],
  ]);
  if (!signup) storage.set('pendingSignupDetails', JSON.stringify({email:'customer@gmail.com'}));
  if (reviewing) {
    storage.set('pendingSignupDetails', JSON.stringify({ firstName: 'Saved', lastName: 'Customer', email: 'customer@gmail.com', phone }));
    if (!missingGrant) storage.set('pendingRegistrationRecoveryToken', 'ownership-grant');
    storage.set('pendingRegistrationRecoveryUntil', String(now + 600000));
  }
  for (const [key, value] of Object.entries(initialStorage)) storage.set(key, value);
  let nextId = 0;
  let ready;
  const elements = new Map();
  const document = {
    activeElement: null,
    querySelector() { return null; },
    createElement: () => element(`generated-${++nextId}`),
    addEventListener(name, callback) { if (name === 'DOMContentLoaded') ready = callback; },
    getElementById: element,
  };
  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set(['hidden']);
      const handlers = {};
      elements.set(id, {
        id, value: '', textContent: '', validationMessage: '', disabled: false, handlers, attributes: {},
        classList: {
          add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
          contains(name) { return classes.has(name); },
          toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
        getAttribute(name) { return this.attributes[name] || ''; },
        append(...children) { this.children = children; },
        parentElement: {appendChild(child) { elements.set(child.id, child); }},
        insertAdjacentElement(_where, el) { this.adjacent = el; },
        dispatchEvent(event) { handlers[event.type]?.call(this, event); },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, handler) { const previous = handlers[name]; handlers[name] = function (...args) { previous?.apply(this, args); return handler.apply(this, args); }; },
        setSelectionRange(start, end) { this.selection = [start, end]; this.selectionStart = start; this.selectionEnd = end; },
        focus() { document.activeElement = this; handlers.focus?.(); },
        checkValidity() { return id === 'signupForm' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value); },
        reportValidity() {},
        setCustomValidity(message) { this.validationMessage = message; },
        querySelector() { return null; },
        querySelectorAll() { return []; },
      });
    }
    return elements.get(id);
  }
  const windowHandlers = {};
  const window = {
    addEventListener(name, callback) { windowHandlers[name] = callback; },
    location: { search: reviewing ? '?review=1' : search, hash: '', replace(target) { calls.push(['redirect', target]); } },
    setInterval(callback) { const id = ++nextId; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    clearTimeout(id) { timeouts.delete(id); },
    setTimeout(callback, ms) { const id = ++nextId; timeouts.set(id, { callback, at: now + ms }); return id; },
  };
  const API = {
    async requestRegistrationReview(email) { calls.push(['review-intent', email]); return {success:true}; },
    async register(payload) { calls.push(['register', payload]); return { requires_verification: true, registration_state:'requested', resend_after:60 }; },
    async updatePendingRegistration(payload, grant) { calls.push(['update', payload, grant]); return { token: 'session', user: { role: 'customer' } }; },
    getEmailDeliveryToken: () => deliveryStatus ? 'private-delivery-receipt' : '',
    emailDeliveryStatus: async () => ({ status: deliveryStatus }),
    async resendVerification(email) { calls.push(['send', email]); return { resend_after: 60 }; },
    async registrationSession() { return null; },
    async signIn() { throw {status: 429, code: 'sign_in_password_cooldown', retryAfter: 576}; },
  };
  const context = vm.createContext({
    window, document, API, URLSearchParams, Event,
    Date: { now: () => now, parse: Date.parse },
    performance: { now: () => now },
    setTimeout: window.setTimeout, clearTimeout: window.clearTimeout,
    setInterval: window.setInterval, clearInterval: window.clearInterval,
    localStorage: {
      getItem(key) { if (noStorage) throw Error('blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (noStorage) throw Error('blocked'); storage.set(key, value); },
      removeItem(key) { if (noStorage) throw Error('blocked'); storage.delete(key); },
    },
    sessionStorage: {
      getItem(key) { if (noStorage) throw Error('blocked'); return storage.get(key) ?? null; },
      setItem(key, value) { if (noStorage) throw Error('blocked'); storage.set(key, value); },
      removeItem(key) { if (noStorage) throw Error('blocked'); storage.delete(key); },
    },
  });
  if (signup) {
    for (const [id, value] of Object.entries({ firstName: 'Test', lastName: 'Customer', email: 'customer@gmail.com', phone, password: 'strong-password', confirmPassword: 'strong-password' })) element(id).value = value;
  }
  for (const source of signup ? ['scripts/utils/human-name.js', 'scripts/utils/account-email.js', 'scripts/utils/philippine-mobile.js', 'scripts/auth/client/signup.js'] : ['scripts/auth/verification-code.js', 'scripts/auth/email-delivery.js', signIn ? 'scripts/auth/sign-in.js' : 'scripts/auth/client/signup-verification.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, source), 'utf8'), context, { filename: source });
  }
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/auth/client/signup-context.js'), 'utf8'), context);
  ready();
  return {
    element, API, calls, storage, windowHandlers, window, intervals, activeElement: () => document.activeElement,
    dispatch(id, event, extra = {}) { return element(id).handlers[event].call(element(id), { preventDefault() {}, ...extra }); },
    advance(ms) {
      now += ms;
      for (const [id, timeout] of [...timeouts]) if (timeout.at <= now) { timeouts.delete(id); timeout.callback(); }
      for (const callback of [...intervals.values()]) callback();
    },
  };
}

(async () => {
  const returning = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:3700000})}});
  assert.equal(returning.element('signupContinuation').classList.contains('hidden'), false);
  await returning.dispatch('signupForm', 'submit');
  assert.equal(returning.calls.length, 0, 'A recognized return cannot submit another public signup');
  returning.element('firstName').value = 'John';
  await returning.dispatch('signupEdit', 'click');
  assert.deepEqual(returning.calls, [['review-intent','customer@gmail.com'], ['redirect','./verify-email.html']]);
  const proposedContext = JSON.parse(returning.storage.get('pendingSignupContext'));
  assert.equal(proposedContext.draft.firstName, 'John');
  assert.equal(proposedContext.expiresAt, 3700000, 'Returning does not extend the original context');
  for (const value of returning.storage.values()) assert.doesNotMatch(String(value), /strong-password|confirmPassword/);

  const mismatched = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'other@gmail.com', expiresAt:3700000})}});
  assert.equal(mismatched.element('signupContinuation').classList.contains('hidden'), true);
  await mismatched.dispatch('signupForm', 'submit');
  assert.equal(mismatched.calls[0][0], 'register');
  const expiredContext = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:100000})}});
  assert.equal(expiredContext.element('signupContinuation').classList.contains('hidden'), true);
  await expiredContext.dispatch('signupForm', 'submit');
  assert.equal(expiredContext.calls[0][0], 'register');

  const liveContext = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:101000})}});
  liveContext.advance(1000);
  liveContext.windowHandlers.focus();
  assert.equal(liveContext.element('signupContinuation').classList.contains('hidden'), true);

  const draftReview = page({signup:true, reviewing:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'CUSTOMER@gmail.com', expiresAt:3700000, review:true,
    draft:{firstName:'John', username:'john', phone:'', password:'must-not-prefill', confirmPassword:'must-not-prefill', email:'attacker@gmail.com'}})}});
  assert.equal(draftReview.element('firstName').value, 'John');
  assert.equal(draftReview.element('lastName').value, 'Customer');
  assert.equal(draftReview.element('email').value, 'customer@gmail.com');
  assert.equal(draftReview.element('password').value, '');
  assert.equal(draftReview.element('confirmPassword').value, '');
  assert.match(draftReview.element('signupDescription').textContent, /proposed changes/);
  draftReview.element('password').value = 'owner-password';
  draftReview.element('confirmPassword').value = 'owner-password';
  await draftReview.dispatch('signupForm','submit');
  assert.equal(draftReview.calls[0][1].first_name, 'John');
  assert.equal(draftReview.calls[0][1].password, 'owner-password');
  assert.equal(draftReview.storage.has('pendingSignupContext'), false);

  for (const pendingRegistrationRecoveryUntil of ['100000','NaN','3700000']) {
    const expiredReview = page({signup:true, reviewing:true, initialStorage:{pendingRegistrationRecoveryUntil}});
    assert.deepEqual(expiredReview.calls, [['redirect','./verify-email.html?recover=1']]);
  }
  const expiredDraft = page({signup:true, reviewing:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:100000, review:true, draft:{firstName:'Stale'}})}});
  assert.equal(expiredDraft.element('firstName').value, 'Saved');
  const wrongDraft = page({signup:true, reviewing:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'other@gmail.com', expiresAt:3700000, review:true, draft:{firstName:'Wrong'}})}});
  assert.equal(wrongDraft.element('firstName').value, 'Saved');

  const rapidEdit = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:3700000})}});
  let finishIntent;
  let intents = 0;
  rapidEdit.API.requestRegistrationReview = () => { intents++; return new Promise(resolve => {finishIntent = resolve;}); };
  const editing = rapidEdit.dispatch('signupEdit','click');
  await rapidEdit.dispatch('signupEdit','click');
  assert.equal(intents, 1);
  assert.equal(rapidEdit.element('signupContinue').disabled, true);
  finishIntent({success:true});
  await editing;

  const failedEdit = page({signup:true, initialStorage:{pendingSignupContext:JSON.stringify({email:'customer@gmail.com', expiresAt:3700000})}});
  failedEdit.API.requestRegistrationReview = async () => {throw {message:'Try again.'};};
  await failedEdit.dispatch('signupEdit','click');
  assert.equal(failedEdit.calls.length, 0);
  assert.equal(JSON.parse(failedEdit.storage.get('pendingSignupContext')).review, undefined);
  assert.equal(failedEdit.element('signupEdit').disabled, false);

  const waitingDeadline = page({initialStorage:{pendingVerificationState:'resumed', pendingVerificationRegistrationUntil:'101000'}});
  waitingDeadline.advance(1000);
  assert.equal(waitingDeadline.element('verificationTitle').textContent, 'Registration expired');
  assert.equal(waitingDeadline.element('signupResend').disabled, true);
  const freshRecovery = page({search:'?recover=1', initialStorage:{pendingVerificationState:'expired', pendingVerificationRegistrationUntil:'100000'}});
  assert.equal(freshRecovery.element('verificationEmailRecovery').classList.contains('hidden'), false, 'Stale signup context cannot hide email recovery');
  assert.equal(freshRecovery.element('signupResend').disabled, false);
  const entry = page({ signup: true });
  const input = entry.element('phone');
  for (const [digits, visible] of [['0','0'], ['09','09'], ['0917','0917'], ['09171','0917 1'],
    ['091712','0917 12'], ['0917123','0917 123'], ['09171234','0917 123 4'], ['09171234567','0917 123 4567'],
    ['08971234567','0897 123 4567']]) {
    input.value = digits; input.setSelectionRange(digits.length, digits.length);
    entry.dispatch('phone', 'input', { inputType: 'insertText' });
    assert.equal(input.value, visible);
    assert.equal(input.selectionStart, visible.length);
  }
  assert.equal(input.attributes.maxlength, '13');
  assert.equal(input.attributes.placeholder, '09XX XXX XXXX');
  input.value = '0917 123 4567'; input.setSelectionRange(5, 5);
  let prevented = false;
  entry.dispatch('phone', 'beforeinput', {inputType:'deleteContentBackward', preventDefault() { prevented=true; }});
  assert.equal(prevented, true);
  assert.equal(input.value, '0911 234 567');
  assert.equal(input.selectionStart, 3);
  input.value = '0917 123 4567'; input.setSelectionRange(4, 4);
  entry.dispatch('phone', 'beforeinput', {inputType:'deleteContentForward'});
  assert.equal(input.value, '0917 234 567');
  assert.equal(input.selectionStart, 4);
  input.value = '0917 123 4567'; input.setSelectionRange(0, 4);
  entry.dispatch('phone', 'paste', {clipboardData:{getData:()=> '0896'}});
  assert.equal(input.value, '0896 123 4567');
  assert.equal(input.selectionStart, 4);
  input.setSelectionRange(0, 13);
  entry.dispatch('phone', 'paste', {clipboardData:{getData:()=> '+63 897-123-4567'}});
  assert.equal(input.value, '0897 123 4567');
  input.value = '0917 123 4567'; input.setSelectionRange(6, 9);
  entry.dispatch('phone', 'paste', {clipboardData:{getData:()=> '99'}});
  assert.equal(input.value, '0917 199 4567');
  assert.equal(input.selectionStart, 8);
  entry.dispatch('phone', 'compositionstart');
  input.value = '0897 123 4567x'; input.setSelectionRange(14,14);
  entry.dispatch('phone', 'input', {isComposing:true});
  assert.equal(input.value, '0897 123 4567x');
  entry.dispatch('phone', 'compositionend');
  assert.equal(input.value, '0897 123 4567');
  input.value = '091712345678'; input.setSelectionRange(12, 12);
  entry.dispatch('phone', 'input', {inputType:'insertReplacementText'});
  assert.equal(input.value, '0917 123 4567', 'Autofill keeps a maximum of 11 actual digits.');
  input.value = '0917 193 4567'; input.setSelectionRange(7, 7);
  entry.dispatch('phone', 'input', {inputType:'insertText'});
  assert.equal(input.selectionStart, 7, 'Editing in the middle must not move focus to the end.');
  input.value = '0917 123 4567'; input.setSelectionRange(13, 13); entry.dispatch('phone', 'input');
  input.value = '0917123 4567'; input.setSelectionRange(4,4);
  entry.dispatch('phone', 'input', {inputType:'deleteContentBackward'});
  assert.equal(input.value, '0911 234 567', 'Separator deletion also works without beforeinput.');
  input.value = '0897 123 4567'; input.setSelectionRange(13,13); entry.dispatch('phone', 'input');
  await entry.dispatch('signupForm', 'submit');
  assert.equal(entry.calls[0][1].phone, '08971234567');
  const mobile = page({ signup: true }).window.PhilippineMobile;
  for (const [raw, expected] of [['09171234567','0917 123 4567'], ['08971234567','0897 123 4567'],
    ['0918-123-4567','0918 123 4567'], ['+63 917 123 4567','0917 123 4567'],
    ['09031234567','09031234567'], ['02 8123 4567','02 8123 4567'], ['0917 •••• 567','0917 •••• 567'], ['Not provided','Not provided']]) {
    assert.equal(mobile.format(raw), expected);
  }
  assert.equal(mobile.format(null, '—'), '—');
  assert.equal(mobile.validate('0897 123 4567'), '');
  assert.equal(mobile.validate('0918 123 4567'), '');
  const mobilePolicy = JSON.parse(fs.readFileSync(path.join(root, 'resources/data/philippine-mobile-policy.json'), 'utf8'));
  for (let prefix = 800; prefix < 1000; prefix++) {
    const localPrefix = `0${prefix}`;
    assert.equal(!mobile.validate(`${localPrefix}1234567`), mobilePolicy.prefixes.includes(localPrefix), 'Built frontend policy must match the shared source.');
  }
  assert.equal(mobile.validate('09171234567\n'), 'Enter an 11-digit mobile number.');
  assert.equal(mobile.validate('', {required:false}), '');
  assert.equal(mobile.validate(''), 'Enter a mobile number.');
  const formatter = page().window.VerificationCode.formatCountdown;
  for (const [seconds, expected] of [[576, '9m 36s'], [120, '2m 00s'], [65, '1m 05s'], [60, '1m 00s'], [45, '45s'], [10, '10s'], [1, '1s'], [0, ''], [-10, ''], [3600, '1h 00m 00s'], [80760, '22h 26m 00s']]) {
    assert.equal(formatter(seconds), expected);
  }
  const signIn = page({signIn:true});
  signIn.element('identifier').value = 'customer@gmail.com';
  signIn.element('password').value = 'strong-password';
  await signIn.dispatch('signin', 'submit');
  assert.equal(signIn.element('signInBtnLabel').textContent, 'Sign In');
  assert.equal(signIn.element('sharedSignInSubmit').disabled, true);
  assert.equal(signIn.element('sharedSignInMessage').textContent, 'Too many attempts. Try again in 9m 36s.');
  signIn.advance(511000);
  assert.match(signIn.element('sharedSignInMessage').textContent, /1m 05s/);
  signIn.advance(65000);
  assert.equal(signIn.element('sharedSignInSubmit').disabled, false);
  assert.equal(signIn.intervals.size, 0);
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

  for (const reviewing of [false, true]) {
    for (const phone of ['', '09171234567', '08951234567', '08961234567', '08971234567', '08981234567', '09991234567', '09071234567', '09221234567', '08131234567', '08171234567', '09271234567', '09561234567', '09661234567', '09771234567']) {
      const signup = page({ signup: true, reviewing, phone });
      signup.element('password').value = 'strong-password';
      signup.element('confirmPassword').value = 'strong-password';
      signup.dispatch('phone', 'input');
      assert.equal(signup.element('phone').validationMessage, '');
      await signup.dispatch('signupForm', 'submit');
      assert.equal(signup.calls[0]?.[0], reviewing ? 'update' : 'register');
      assert.equal(signup.calls[0][1].phone, phone || null);
      assert.equal(signup.calls[0][1].email, 'customer@gmail.com');
      if (reviewing) assert.equal(signup.calls[0][2], 'ownership-grant');
      else {
        assert.equal(signup.storage.get('pendingVerificationState'), 'requested');
        assert.equal(signup.storage.has('pendingSignupDetails'), false, 'Public input never becomes an authorized saved profile');
        assert.equal(signup.storage.has('pendingSignupContext'), true);
      }
      assert.deepEqual(signup.calls.at(-1), ['redirect', reviewing ? './dashboard.html' : './verify-email.html']);
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

  for (const phone of ['56775675767', '12345678901', '08123456789', '09031234567']) {
    const invalid = page({ signup: true, phone });
    await invalid.dispatch('signupForm', 'submit');
    assert.equal(invalid.calls.length, 0);
    assert.equal(invalid.element('phoneServerError').textContent, 'Enter a valid Philippine mobile number.');
    invalid.element('phone').value = phone.slice(0, -1); invalid.dispatch('phone', 'input');
    assert.equal(invalid.element('phoneServerError').textContent, '');
  }
  const invalidPhone = page({ signup: true, phone: '0917123456' });
  await invalidPhone.dispatch('signupForm', 'submit');
  assert.equal(invalidPhone.calls.length, 0);
  assert.equal(invalidPhone.element('phoneServerError').textContent, 'Enter an 11-digit mobile number.');
  invalidPhone.element('phone').value = '';
  invalidPhone.dispatch('phone', 'input');
  assert.equal(invalidPhone.element('phone').validationMessage, '');
  await invalidPhone.dispatch('signupForm', 'submit');
  assert.equal(invalidPhone.calls[0][1].phone, null);

  const abandoned = page({ signup: true });
  abandoned.API.register = async () => ({ requires_verification: true, resend_after: 0 });
  await abandoned.dispatch('signupForm', 'submit');
  assert.equal(abandoned.element('signupContinuation').classList.contains('hidden'), true, 'A public response cannot establish an unfinished registration');
  assert.equal(abandoned.element('firstName').value, 'Test');
  assert.equal(abandoned.element('password').value, 'strong-password');
  assert.deepEqual(abandoned.calls, [], 'A response without authorized pending context stays on signup');
  assert.match(abandoned.element('signupMessage').textContent, /Registration couldn't continue/);
  assert.equal(abandoned.storage.get('pendingRegistrationEditToken'), 'private-edit-credential', 'An inconclusive response cannot erase an existing credential');
  assert.equal(abandoned.storage.has('pendingSignupDetails'), false, 'Unproven submitted details cannot become recovered account details');

  const obsoleteResponse = page({signup:true});
  obsoleteResponse.API.register = async () => ({success:true});
  await obsoleteResponse.dispatch('signupForm','submit');
  assert.deepEqual(obsoleteResponse.calls, [['redirect','./sign-in.html']], 'An unexpected registration response must not claim activation');

  const reviewing = page({ signup: true, reviewing: true });
  assert.equal(reviewing.element('firstName').value, 'Saved');
  assert.equal(reviewing.element('email').readOnly, true);
  assert.equal(reviewing.element('password').value, '', 'Review requires password reentry');
  assert.equal(reviewing.calls.length, 0, 'The confirmed email needs no second verification email');
  reviewing.element('password').value = 'new-password';
  reviewing.element('confirmPassword').value = 'new-password';
  await reviewing.dispatch('signupForm', 'submit');
  assert.equal(reviewing.calls[0][0], 'update');
  assert.equal(reviewing.calls[0][2], 'ownership-grant');
  assert.deepEqual(reviewing.calls.at(-1), ['redirect', './dashboard.html']);
  assert.equal(reviewing.storage.has('pendingRegistrationRecoveryToken'), false);

  const unprovenReview = page({ signup: true, reviewing: true, missingGrant: true });
  assert.deepEqual(unprovenReview.calls, [['redirect', './verify-email.html?recover=1']], 'A lost recovery grant returns to email proof');

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

  const pending = page();
  assert.equal(pending.element('verificationTitle').textContent, 'Registration pending');
  assert.doesNotMatch(pending.element('verificationDescription').textContent, /We sent/);
  assert.equal(pending.element('verificationBackAction').attributes.href, './signup.html');
  assert.equal(pending.element('verifyCodeEmail').textContent, 'c***@gmail.com');
  assert.equal(pending.element('signupResend').textContent, 'Resend link in 1m 00s');
  assert.deepEqual(pending.calls, [], 'Waiting must never automatically verify or send');
  pending.windowHandlers.pagehide({persisted:true});
  pending.advance(10000);
  pending.windowHandlers.pageshow({persisted:true});
  assert.equal(pending.element('signupResend').textContent, 'Resend link in 50s');
  assert.equal(pending.intervals.size, 1, 'Restoring a cached page restarts exactly one deadline timer');
  pending.advance(60000);
  await pending.dispatch('signupLinkForm', 'submit');
  assert.deepEqual(pending.calls, [['send', 'customer@gmail.com']]);
  assert.equal(pending.element('signupResend').textContent, 'Resend link in 1m 00s');

  const resumed = page({initialStorage:{pendingVerificationState:'resumed', pendingVerificationLinkUntil:'102000'}});
  assert.equal(resumed.element('verificationTitle').textContent, 'Registration in progress');
  assert.match(resumed.element('verificationDescription').textContent, /latest verification link/);
  assert.equal(resumed.element('verificationInstruction').textContent, '');
  resumed.advance(2000);
  assert.equal(resumed.element('verificationDescription').textContent, 'This link has expired. Request a new one.');
  const resumedResend = page({deliveryFailed:true, initialStorage:{pendingVerificationState:'resumed', pendingVerificationLinkUntil:'1900000'}});
  await resumedResend.dispatch('signupLinkForm', 'submit');
  resumedResend.advance(60000);
  assert.equal(resumedResend.intervals.size, 0, 'A new request must retire the old link-guidance timer');

  const throttled = page({deliveryFailed: true});
  throttled.API.resendVerification = async () => { throw {status:429, retryAfter:480, code:'email_rate_limited'}; };
  await throttled.dispatch('signupLinkForm', 'submit');
  assert.equal(throttled.element('signupResend').textContent, 'Resend link');
  assert.equal(throttled.element('signupResend').disabled, true);
  assert.match(throttled.element('signupLinkMessage').textContent, /Too many requests.*8m 00s/);
  throttled.advance(480000);
  assert.equal(throttled.element('signupResend').disabled, false);
  assert.equal(throttled.element('signupLinkMessage').textContent, '');

  const expired = page({deliveryFailed:true});
  let expiredSends = 0;
  expired.API.resendVerification = async () => { expiredSends++; throw {status:422, code:'registration_expired', message:'This registration has expired. Start again.'}; };
  await expired.dispatch('signupLinkForm', 'submit');
  assert.equal(expired.element('signupLinkMessage').textContent, 'This registration has expired. Start again.');
  assert.equal(expired.element('signupResend').disabled, true);
  assert.equal(expired.element('verificationBackAction').attributes.href, './signup.html');
  expired.advance(60000);
  await expired.dispatch('signupLinkForm', 'submit');
  assert.equal(expiredSends, 1, 'An expired registration must not offer a resend that revives it.');
  const restoredExpiry = page({deliveryFailed:true, deliveryStatus:'sent', initialStorage:{pendingVerificationState:'expired'}});
  restoredExpiry.windowHandlers.pagehide({persisted:true});
  restoredExpiry.windowHandlers.pageshow({persisted:true});
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(restoredExpiry.element('verificationTitle').textContent, 'Registration expired');
  assert.equal(restoredExpiry.element('signupLinkMessage').textContent, 'This registration has expired. Start again.');
  assert.equal(restoredExpiry.element('signupResend').disabled, true);

  const busy = page({deliveryFailed:true});
  let resolveSend;
  let sends = 0;
  busy.API.resendVerification = () => { sends++; return new Promise(resolve => {resolveSend=resolve;}); };
  const sending = busy.dispatch('signupLinkForm', 'submit');
  await busy.dispatch('signupLinkForm', 'submit');
  assert.equal(sends, 1);
  assert.equal(busy.element('signupResend').textContent, 'Sending verification email...');
  resolveSend({resend_after:60});
  await sending;

  const recovery = page({noStorage:true});
  assert.equal(recovery.element('verificationEmailRecovery').classList.contains('hidden'), false);
  recovery.element('verificationEmail').value='restore@gmail.com';
  await recovery.dispatch('signupLinkForm', 'submit');
  assert.deepEqual(recovery.calls, [['send', 'restore@gmail.com']]);

  const delivery = page({deliveryStatus:'failed'});
  await new Promise(resolve => setImmediate(resolve));
  assert.match(delivery.element('signupLinkMessage').textContent, /Couldn't send the verification email/);
  assert.deepEqual(delivery.calls, []);

  for (const [status, heading] of [['queued', 'Sending verification email'], ['sent', 'Check your email'], ['failed', 'Email not sent'], ['superseded', 'Request a new link']]) {
    const state = page({deliveryStatus:status});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.element('verificationTitle').textContent, heading);
    if (status !== 'sent') assert.doesNotMatch(state.element('verificationDescription').textContent, /We sent/);
  }

  const safeHandoff = page();
  await new Promise(resolve => setImmediate(resolve));
  safeHandoff.API.registrationSession = async (email, acknowledge) => {
    assert.equal(email, 'customer@gmail.com'); assert.equal(acknowledge, true);
    return {session_token:'11|cookie', user:{role:'customer',email}};
  };
  await safeHandoff.windowHandlers.focus();
  assert.deepEqual(safeHandoff.calls, [['redirect','./dashboard.html']]);

  const concurrent = page();
  let checks = 0;
  let finishCheck;
  concurrent.API.registrationSession = () => { checks++; return new Promise(resolve => { finishCheck = resolve; }); };
  const focused = concurrent.windowHandlers.focus();
  concurrent.windowHandlers.focus();
  concurrent.windowHandlers.pageshow({persisted:false});
  assert.equal(checks, 1);
  finishCheck(null);
  await focused;
  await Promise.resolve();
  assert.equal(checks, 1, 'Concurrent focus events do not schedule another failed session check');

  const fields = page({signup:true});
  fields.API.register = async () => {throw {status:422,errors:{phone:['This phone is already in use.']}};};
  await fields.dispatch('signupForm','submit');
  assert.equal(fields.element('phoneServerError').textContent, 'This phone is already in use.');
  fields.element('phone').value = '09171234567';
  fields.dispatch('phone','input');
  assert.equal(fields.element('phoneServerError').textContent, '');
  assert.equal(fields.element('phone').attributes['aria-invalid'], 'false');
  console.log('Customer magic-link waiting, signup validation and recovery regression checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
