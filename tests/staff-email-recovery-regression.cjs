const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../scripts/auth/email-recovery.js'), 'utf8');

async function page({ token = 'a'.repeat(64), verify, complete } = {}) {
  const elements = new Map(), events = new Map(), calls = [];
  const element = id => {
    if (!elements.has(id)) {
      const classes = new Set(['emailRecoveryForm', 'recoveryError'].includes(id) ? ['hidden'] : []);
      elements.set(id, { value: '', type: 'password', disabled: false, textContent: '', attributes: {}, handlers: {},
        classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
        addEventListener(name, fn) { this.handlers[name] = fn; },
        setAttribute(name, value) { this.attributes[name] = value; }, focus() { calls.push(['focus', id]); } });
    }
    return elements.get(id);
  };
  const window = { location: { hash: '#' + token, pathname: '/pages/client/confirm-email-recovery.html' }, addEventListener: (name, fn) => events.set(name, fn) };
  const API = {
    async verifyStaffEmailRecovery(value) { calls.push(['verify', value]); return verify ? verify(value) : { email: 'new@example.test' }; },
    async completeStaffEmailRecovery(value, password) { calls.push(['complete', value, password]); return complete ? complete(value, password) : { status: 'completed' }; },
  };
  vm.runInNewContext(source, { window, API, history: { replaceState(a, b, url) { calls.push(['scrub', url]); } }, document: {
    getElementById: element, addEventListener(name, fn) { events.set(name, fn); },
  } });
  await events.get('DOMContentLoaded')();
  return { element, calls, events, submit: () => element('emailRecoveryForm').handlers.submit({ preventDefault() {} }) };
}

(async () => {
  const invalid = await page({ token: 'forged' });
  assert.equal(invalid.calls.some(call => call[0] === 'verify'), false);
  assert.equal(invalid.element('emailRecoveryForm').classList.contains('hidden'), true);
  const expired = await page({ verify: () => { throw new Error('Expired'); } });
  assert.match(expired.element('recoveryMessage').textContent, /expired or unavailable/);
  const current = await page();
  assert.equal(current.calls[0][0], 'scrub', 'Fragment is removed before any token API request');
  assert.equal(current.element('recoveryEmail').value, 'new@example.test');
  current.element('recoveryPassword').value = 'ExistingPassword!234';
  current.element('recoveryPassword').handlers.input();
  current.element('recoveryReveal').handlers.click();
  assert.equal(current.element('recoveryPassword').type, 'text');
  await current.submit();
  assert.equal(current.element('recoveryTitle').textContent, 'Email Updated');
  assert.equal(current.element('recoveryPassword').value, '');
  assert.equal(current.element('recoveryPassword').type, 'password');
  assert.equal(current.element('emailRecoveryForm').classList.contains('hidden'), true);
  await current.submit();
  assert.equal(current.calls.filter(call => call[0] === 'complete').length, 1);

  let resolve, attempts = 0;
  const waiting = await page({ complete: () => { attempts++; return new Promise(done => { resolve = done; }); } });
  waiting.element('recoveryPassword').value = 'Password';
  const first = waiting.submit(); await waiting.submit();
  assert.equal(attempts, 1);
  assert.equal(waiting.element('recoveryPassword').disabled, true);
  resolve({ status: 'completed' }); await first;

  const wrong = await page({ complete: () => { throw { data: { status: 'invalid_password' }, message: 'The current account password is incorrect.' }; } });
  wrong.element('recoveryPassword').value = 'wrong'; await wrong.submit();
  assert.match(wrong.element('recoveryError').textContent, /incorrect/);
  assert.equal(wrong.element('recoveryPassword').value, '');
  assert.equal(wrong.element('emailRecoveryForm').classList.contains('hidden'), false);
  const locked = await page({ complete: () => { throw { data: { status: 'locked' }, message: 'Too many attempts.' }; } });
  locked.element('recoveryPassword').value = 'wrong'; await locked.submit();
  assert.equal(locked.element('emailRecoveryForm').classList.contains('hidden'), true);
  const abandoned = await page(); abandoned.element('recoveryPassword').value = 'Secret';
  abandoned.events.get('pagehide')(); await abandoned.submit();
  assert.equal(abandoned.element('recoveryPassword').value, '');
  assert.equal(abandoned.calls.some(call => call[0] === 'complete'), false);
  abandoned.events.get('pageshow')({ persisted: true });
  assert.equal(abandoned.element('emailRecoveryForm').classList.contains('hidden'), true);
  console.log('Staff email recovery page regression tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
