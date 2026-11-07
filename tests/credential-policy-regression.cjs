const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync('scripts/utils/credential-policy.js', 'utf8'), context);
const policy = context.CredentialPolicy;
assert.equal(policy.password('orchard'), 'Use at least 8 characters.');
assert.equal(policy.password('orchards'), '');
assert.equal(policy.password('violet orchard', 15), 'Use at least 15 characters.');
assert.equal(policy.password('violet orchards', 15), '');
assert.equal(policy.password('orchard'.padEnd(64, 'x')), '');
assert.equal(policy.password('orchard'.padEnd(65, 'x')), 'Use no more than 64 characters.');
for (const value of ['orchard café', 'orchard😀', 'orchard\n', 'orchard\0']) {
  assert.equal(policy.password(value), 'Use English letters, numbers, or standard symbols only.');
}
for (const value of [' orchard meadow', 'orchard meadow ']) assert.equal(policy.password(value), 'Remove spaces at the beginning or end.');
for (const value of ['two  internal spaces', '[]{}\\\'"!@#$%^&*()-_=+;:,<>/?`~.|', 'onlylowercase', '7492603851']) assert.equal(policy.password(value), '');
assert.equal(policy.username('abc.'), 'End with a letter or number.');
assert.equal(policy.username('1abc'), 'Begin with a letter.');
assert.equal(policy.username('a'.repeat(31)), 'Use 3–30 characters.');
assert.equal(policy.username('legacy user.', true, 'legacy user.'), '');
assert.equal(policy.username('', false), '');
assert.equal(policy.confirmation('orchard', 'orchards'), 'Passwords do not match.');

// Exercise feedback events, including dependent confirmation and stale server errors.
function input(id) {
  const listeners = {}, attrs = {}, classes = new Set();
  return { id, value: '', listeners, attrs, classList: { toggle(k, on) { on ? classes.add(k) : classes.delete(k); } },
    setAttribute(k, v) { attrs[k] = v; }, getAttribute(k) { return attrs[k] || ''; },
    addEventListener(k, f) { (listeners[k] ||= []).push(f); },
    fire(k) { for (const f of listeners[k] || []) f(); }, focus() { this.focused = true; } };
}
const field = input('new'), feedback = { classList: { toggle() {} } };
const binding = policy.bind(field, { feedback, validate: () => policy.password(field.value) });
assert.equal(feedback.textContent, undefined);
field.fire('blur'); assert.equal(feedback.textContent, 'Enter a new password.');
field.value = 'short'; field.fire('input'); assert.equal(feedback.textContent, 'Use at least 8 characters.');
field.value = ''; field.fire('input'); assert.equal(feedback.textContent, '');
field.fire('blur'); assert.equal(feedback.textContent, 'Enter a new password.');
field.value = 'orchards'; field.fire('input'); assert.equal(field.attrs['aria-invalid'], 'false');
binding.setError('This password is not safe. Choose another.');
field.value = 'orchard meadow'; field.fire('input'); assert.equal(feedback.textContent, '');
const confirm = input('confirm'), confirmFeedback = { classList: { toggle() {} } };
const confirmationBinding = policy.bind(confirm, { feedback: confirmFeedback, validate: () => policy.confirmation(confirm.value, field.value) });
field.addEventListener('input', () => confirmationBinding.refresh());
confirm.value = 'different'; confirm.fire('blur'); assert.equal(confirmFeedback.textContent, 'Passwords do not match.');
field.value = 'different'; field.fire('input'); assert.equal(confirmFeedback.textContent, '');
assert.equal(confirmationBinding.validate(), true);
console.log('Credential format and dynamic feedback regressions passed.');
