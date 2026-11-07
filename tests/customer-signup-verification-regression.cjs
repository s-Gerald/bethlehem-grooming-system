const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

function page({ deliveryFailed = false, noStorage = false, deliveryStatus = null, signup = true, signIn = false, magicLink = false, reviewing = false, missingGrant = false, phone = '', search = '', initialStorage = {}, resumeState = 'pending' } = {}) {
  let now = 100000;
  const intervals = new Map();
  const timeouts = new Map();
  const calls = [];
  const storage = new Map();
  for (const [key, value] of Object.entries(initialStorage)) storage.set(key, value);
  let nextId = 0;
  const ready = [];
  const elements = new Map();
  const documentHandlers = {};
  const document = {
    activeElement: null,
    querySelector() { return null; },
    querySelectorAll(selector) { return !signup && !signIn && selector.startsWith("a[") ? [element("verificationBackAction")] : []; },
    createElement: () => element(`generated-${++nextId}`),
    addEventListener(name, callback) { if (name === 'DOMContentLoaded') ready.push(callback); else documentHandlers[name] = callback; },
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
        appendChild(child) { elements.set(child.id, child); },
        parentElement: {
          appendChild(child) { elements.set(child.id, child); },
          insertAdjacentElement(_where, child) { elements.set(child.id, child); },
        },
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
    registrationMagicLinkToken: magicLink ? 'email-link' : '',
    crypto: require('node:crypto').webcrypto,
    addEventListener(name, callback) { windowHandlers[name] = callback; },
    location: { search: reviewing ? '?review=1' : search, hash: '', replace(target) { calls.push(['redirect', target]); } },
    setInterval(callback) { const id = ++nextId; intervals.set(id, callback); return id; },
    clearInterval(id) { intervals.delete(id); },
    clearTimeout(id) { timeouts.delete(id); },
    setTimeout(callback, ms) { const id = ++nextId; timeouts.set(id, { callback, at: now + ms }); return id; },
  };
  const API = {
    async cancelRegistration(token) { calls.push(['cancel', token]); return {success:true}; },
    async register(payload) { calls.push(['register', payload]); return {requires_verification:true, email:payload.email, state:'pending', registration_token:payload.registration_token, registration_expires_at:new Date(now+3600000).toISOString(), resend_after:60}; },
    async finalizeRegistration(payload, token) { calls.push(['finalize', payload, token]); return {token:'11|cookie',user:{role:'customer'}}; },
    getEmailDeliveryToken: () => '',
    emailDeliveryStatus: async () => ({status:deliveryStatus || 'sent'}),
    async resendVerification(token) { calls.push(['send',token]); return {resend_after:60}; },
    async registrationStatus(token) { calls.push(['status',token]); return {state:resumeState,email:'customer@gmail.com',registration_expires_at:new Date(now+3600000).toISOString()}; },
    async verifyEmail(token) { calls.push(['verify',token]); return {registration_approved:true}; },
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
  if (signup && !initialStorage.bethlehem_signup_attempt) {
    element('terms').checked = true;
    for (const [id, value] of Object.entries({ firstName: 'Test', lastName: 'Customer', email: 'customer@gmail.com', phone, password: 'strong-password', confirmPassword: 'strong-password' })) element(id).value = value;
  }
  for (const source of ['scripts/auth/client/signup-context.js', 'scripts/auth/verification-code.js', 'scripts/auth/email-delivery.js', ...(magicLink ? ['scripts/auth/client/verify-email.js'] : ['scripts/utils/credential-policy.js', 'scripts/utils/human-name.js', 'scripts/utils/account-email.js', 'scripts/utils/philippine-mobile.js', 'scripts/auth/client/signup-verification.js', 'scripts/auth/client/signup.js'])]) {
    vm.runInContext(fs.readFileSync(path.join(root, source), 'utf8'), context, {filename:source});
  }
  for (const callback of ready) callback();
  if (signup && !reviewing && !initialStorage.bethlehem_signup_attempt) {
    element('terms').checked = true;
    for (const [id,value] of Object.entries({firstName:'Test',lastName:'Customer',email:'customer@gmail.com',phone,password:'strong-password',confirmPassword:'strong-password'})) element(id).value=value;
    element('phone').handlers.input?.call(element('phone'), {type:'input'});
  }
  return {
    context, element, API, calls, storage, windowHandlers, window, document, documentHandlers, intervals, activeElement: () => document.activeElement,
    dispatch(id, event, extra = {}) { return element(id).handlers[event].call(element(id), { preventDefault() {}, ...extra }); },
    advance(ms) {
      now += ms;
      for (const [id, timeout] of [...timeouts]) if (timeout.at <= now) { timeouts.delete(id); timeout.callback(); }
      for (const callback of [...intervals.values()]) callback();
    },
  };
}

const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const normal = page();
  await normal.dispatch('signupForm','submit');
  assert.equal(normal.calls.filter(c=>c[0]==='redirect').length,0,'Waiting must stay on original page');
  const started = normal.calls.find(c=>c[0]==='register')[1];
  assert.deepEqual(Object.keys(started).sort(),['email','registration_token']);
  assert.equal(normal.element('signupWaitingState').classList.contains('hidden'),false);
  assert.equal(normal.element('signupTitle').textContent,'Check your email');
  assert.equal(normal.element('signupDescription').textContent,'Open the verification link sent to');
  assert.equal(normal.element('signupMaskedEmail').textContent,'c***@gmail.com');
  assert.equal(normal.activeElement(),normal.element('signupTitle'));
  assert.ok([...normal.storage.values()].every(value=>!value.includes('strong-password')&&!value.includes('Test')&&!value.includes('Customer')));
  normal.API.registrationStatus = async () => ({state:'approved'});
  await normal.windowHandlers.focus(); await flush();
  assert.equal(normal.calls.filter(c=>c[0]==='finalize').length,1);
  const finalized = normal.calls.find(c=>c[0]==='finalize');
  assert.equal(finalized[1].password,'strong-password');
  assert.equal(finalized[1].terms_accepted,true);
  assert.equal(finalized[2],started.registration_token);
  assert.deepEqual(normal.calls.at(-1),['redirect','/account']);
  await normal.windowHandlers.focus();
  assert.equal(normal.calls.filter(c=>c[0]==='finalize').length,1);

  const duplicate = page(); let resolveStart;
  duplicate.API.register = payload => { duplicate.calls.push(['register',payload]); return new Promise(resolve=>{resolveStart=resolve;}); };
  const starting=duplicate.dispatch('signupForm','submit');
  await duplicate.dispatch('signupForm','submit');
  assert.equal(duplicate.calls.filter(c=>c[0]==='register').length,1);
  resolveStart({requires_verification:true,state:'pending',email:'customer@gmail.com',registration_expires_at:new Date(3700000).toISOString()});
  await starting;

  const poll=page(); await poll.dispatch('signupForm','submit'); await flush();
  const before=poll.calls.filter(c=>c[0]==='status').length;
  poll.document.hidden=true; poll.documentHandlers.visibilitychange(); poll.advance(15000); await flush();
  assert.equal(poll.calls.filter(c=>c[0]==='status').length,before);
  poll.document.hidden=false; await poll.documentHandlers.visibilitychange(); await flush();
  assert.equal(poll.calls.filter(c=>c[0]==='status').length,before+1);
  poll.advance(5000); await flush();
  assert.equal(poll.calls.filter(c=>c[0]==='status').length,before+2);

  const edit=page(); await edit.dispatch('signupForm','submit');
  assert.equal(edit.element('signupResend').disabled,true);
  edit.advance(19000); await flush();
  assert.equal(edit.element('signupResend').textContent,'Resend link in 41s');
  await edit.dispatch('signupResend','click');
  assert.equal(edit.calls.filter(c=>c[0]==='send').length,0);
  edit.advance(41000); await flush();
  assert.equal(edit.element('signupResend').disabled,false);
  await edit.dispatch('signupResend','click');
  assert.equal(edit.calls.filter(c=>c[0]==='send').length,1);
  assert.equal(edit.element('signupResend').disabled,true);
  await edit.dispatch('signupEdit','click');
  assert.equal(edit.element('password').value,'strong-password');
  assert.equal(edit.element('signupForm').classList.contains('hidden'),false);
  assert.equal(edit.element('signupWaitingState').classList.contains('hidden'),true);
  assert.equal(edit.activeElement(),edit.element('signupTitle'));
  edit.element('username').value='changeduser';
  edit.API.registrationStatus=async()=>({state:'approved'});
  await edit.dispatch('signupForm','submit'); await flush();
  assert.equal(edit.calls.filter(c=>c[0]==='register').length,1);
  assert.equal(edit.calls.find(c=>c[0]==='finalize')[1].username,'changeduser');

  const changed=page(); await changed.dispatch('signupForm','submit');
  const oldToken=changed.calls.find(c=>c[0]==='register')[1].registration_token;
  await changed.dispatch('signupEdit','click'); changed.element('email').value='other@gmail.com';
  await changed.dispatch('signupForm','submit');
  assert.equal(changed.calls.filter(c=>c[0]==='register').length,2);
  assert.notEqual(changed.calls.filter(c=>c[0]==='register')[1][1].registration_token,oldToken);

  const saved={token:'a'.repeat(64),expiresAt:3700000,resendUntil:160000};
  const recovery=page({initialStorage:{bethlehem_signup_attempt:JSON.stringify(saved)}}); await flush();
  assert.equal(recovery.element('email').value,'customer@gmail.com');
  assert.match(recovery.element('signupDescription').textContent,/re-enter/i);
  assert.equal(recovery.element('password').value,'');
  assert.equal(recovery.calls.filter(c=>c[0]==='finalize').length,0);
  recovery.API.registrationStatus=async()=>({state:'approved'});
  recovery.element('firstName').value='Test';recovery.element('lastName').value='Customer';recovery.element('terms').checked=true;
  recovery.element('password').value='fresh-owner-password'; recovery.element('confirmPassword').value='fresh-owner-password';
  await recovery.dispatch('signupForm','submit'); await flush();
  assert.equal(recovery.calls.filter(c=>c[0]==='register').length,0);
  assert.equal(recovery.calls.find(c=>c[0]==='finalize')[1].password,'fresh-owner-password');

  const stopped=page(); await stopped.dispatch('signupForm','submit');
  stopped.windowHandlers.pagehide();
  stopped.API.registrationStatus=async()=>({state:'approved'});
  await stopped.windowHandlers.focus(); await flush();
  assert.equal(stopped.calls.filter(c=>c[0]==='finalize').length,0);
  assert.equal(stopped.element('password').value,'');

  const noStorage=page({noStorage:true}); await noStorage.dispatch('signupForm','submit');
  noStorage.API.registrationStatus=async()=>({state:'approved'});
  await noStorage.windowHandlers.focus(); await flush();
  assert.deepEqual(noStorage.calls.at(-1),['redirect','/account']);

  const retry=page();let starts=0;
  retry.API.register=async payload=>{starts++;if(starts===1)throw {status:503,message:'Queue temporarily unavailable'};return {requires_verification:true,state:'pending',email:payload.email};};
  await retry.dispatch('signupForm','submit');
  await retry.dispatch('signupForm','submit');
  assert.equal(starts,2,'An initial failed issuance must be retryable with the same opaque credential');
  assert.equal(retry.element('signupWaitingState').classList.contains('hidden'),false);

  const expired=page(); await expired.dispatch('signupForm','submit');
  expired.API.registrationStatus=async()=>{throw {code:'registration_expired',message:'Registration expired. Start again.'};};
  await expired.windowHandlers.focus();
  assert.equal(expired.element('signupWaitingState').classList.contains('hidden'),true);
  assert.equal(expired.element('signupForm').classList.contains('hidden'),false);
  assert.equal(expired.element('signupMessage').textContent,'Registration expired. Start again.');

  const fieldFailure=page();await fieldFailure.dispatch('signupForm','submit');
  fieldFailure.API.registrationStatus=async()=>({state:'approved'});
  fieldFailure.API.finalizeRegistration=async()=>{throw {errors:{
    username:['That username is already in use.'],
    password:['Choose a different password.'],
    password_confirmation:['Confirm your password again.'],
  }};};
  await fieldFailure.windowHandlers.focus();await flush();
  assert.equal(fieldFailure.element('signupForm').classList.contains('hidden'),false);
  assert.equal(fieldFailure.element('signupWaitingState').classList.contains('hidden'),true);
  assert.equal(fieldFailure.element('usernameServerError').textContent,'That username is already in use.');
  assert.equal(fieldFailure.element('password').value,'strong-password');
  assert.equal(fieldFailure.element('passwordServerError').textContent,'Choose a different password.');
  assert.equal(fieldFailure.element('confirmPasswordServerError').textContent,'Confirm your password again.');

  const passwords=page();
  for (const [id, value, message] of [
    ['password','short','Use at least 8 characters.'],
    ['confirmPassword','different','Passwords do not match.'],
  ]) {
    passwords.element(id).value=value;
    passwords.dispatch(id,'blur');
    assert.equal(passwords.element(`${id}ServerError`).textContent,message);
    assert.equal(passwords.element(id).getAttribute('aria-invalid'),'true');
    passwords.element(id).value='strong-password';
    passwords.dispatch(id,'input');
    assert.equal(passwords.element(`${id}ServerError`).hidden,true);
  }
  for (const id of ['password','confirmPassword']) passwords.element(id).type='password';
  passwords.dispatch('togglePasswordVisibility','click');
  assert.equal(passwords.element('password').type,'text');
  assert.equal(passwords.element('confirmPassword').type,'password');
  passwords.dispatch('togglePasswordVisibility','click');
  passwords.dispatch('toggleConfirmPasswordVisibility','click');
  assert.equal(passwords.element('password').type,'password');
  assert.equal(passwords.element('confirmPassword').type,'text');

  const completed=page({initialStorage:{bethlehem_signup_attempt:JSON.stringify(saved)},resumeState:'completed'});
  await flush();
  assert.equal(completed.calls.filter(c=>c[0]==='finalize').length,1);
  assert.deepEqual(completed.calls.at(-1),['redirect','/account']);
  assert.equal(completed.storage.has('bethlehem_signup_attempt'),false);

  const approvedReload=page({initialStorage:{bethlehem_signup_attempt:JSON.stringify(saved)},resumeState:'approved'});
  await flush();
  assert.match(approvedReload.element('signupDescription').textContent,/Email verified/);
  assert.equal(approvedReload.calls.filter(c=>c[0]==='finalize').length,0);

  const delayed=page();let deliveryChecks=0;
  const initialRegister=delayed.API.register;
  delayed.API.register=async payload=>({...await initialRegister(payload),email_delivery_token:'receipt'});
  delayed.API.emailDeliveryStatus=async()=>{deliveryChecks++;return {status:'queued'};};
  await delayed.dispatch('signupForm','submit');
  for(let i=0;i<27;i++){delayed.advance(5000);await flush();}
  assert.equal(deliveryChecks,24,'Delivery polling must retain the existing bounded feedback window');
  assert.match(delayed.element('signupWaitingMessage').textContent,/delayed/i);

  const verifier=page({magicLink:true});
  assert.equal(verifier.calls.length,0,'GET must not approve');
  await verifier.dispatch('verifyLinkSubmit','click');
  assert.equal(verifier.element('successTitle').textContent,'Email verified!');
  assert.match(verifier.element('successMessage').textContent,/Return to the device/);
  assert.equal(verifier.calls.filter(c=>c[0]==='finalize'||c[0]==='redirect').length,0);

  console.log('Verify-first signup frontend regressions passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
