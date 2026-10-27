const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({ window: {}, document: {}, Event: class { constructor(type) { this.type = type; } } });
vm.runInContext(fs.readFileSync('scripts/utils/account-email.js', 'utf8'), context);
const email = context.window.AccountEmail;
for (const domain of ['gmail.com','outlook.com','hotmail.com','live.com','msn.com','yahoo.com','yahoo.com.ph','ymail.com','icloud.com','me.com','mac.com']) {
  assert.equal(email.validate('123@' + domain), '');
}
assert.equal(email.validate('ab@gmail.com'), 'Use at least 3 characters before @.');
assert.equal(email.validate('a'.repeat(64)+'@gmail.com'), '');
assert.equal(email.validate('a'.repeat(65)+'@gmail.com'), 'Use no more than 64 characters before @.');
assert.equal(email.validate('123@123.com'), 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.');
assert.equal(email.validate(''), 'Enter your email address.');
assert.equal(email.validate('', { required: false }), '');
for (const value of ['a..bc@gmail.com', '.abc@gmail.com', 'abc.@gmail.com', 'abc@@gmail.com', 'abc.gmail.com', 'ab c@gmail.com', 'abc@-gmail.com', 'abc@mail.gmail.com']) assert.ok(email.validate(value), value);
assert.equal(email.validate('Gerald+Clinic@GMAIL.COM'), '');
assert.equal(email.normalize('  Gerald+Clinic@GMAIL.COM  '), 'Gerald+Clinic@gmail.com');
for (const [typo, correct] of [['gmial.com','gmail.com'],['gamil.com','gmail.com'],['outlok.com','outlook.com'],['hotmial.com','hotmail.com'],['yaho.com','yahoo.com']]) {
  assert.equal(email.suggestion('Gerald+'+'Clinic@'+typo), correct);
  assert.equal(email.applySuggestion('Gerald+Clinic@'+typo), 'Gerald+Clinic@'+correct);
}
assert.equal(email.suggestion('abc@gmail.com'), '');
assert.equal(email.suggestion('abc@unknown.com'), '');
// Exercise the real field controller against a small event-capable DOM fixture.
function element() {
  return { value: '', hidden: true, attributes: {}, handlers: {}, children: [], className: '',
    setAttribute(k,v) { this.attributes[k]=v; }, getAttribute(k) { return this.attributes[k] || ''; },
    addEventListener(k,fn) { (this.handlers[k] ||= []).push(fn); },
    dispatchEvent(event) { for (const fn of this.handlers[event.type] || []) fn(event); },
    insertAdjacentElement(_where, el) { this.adjacent = el; }, append(...els) { this.children.push(...els); },
    focus() { this.focused = true; },
  };
}
const error = element();
context.document.getElementById = () => error;
context.document.createElement = element;
const input = element(); input.id='testEmail'; input.setAttribute('aria-describedby','testEmailError');
const field = email.bind(input);
assert.equal(error.textContent, undefined, 'Untouched field has no validation error');
input.value = 'abc@gmial.com'; input.dispatchEvent(new context.Event('blur'));
assert.ok(error.textContent); assert.equal(input.attributes['aria-invalid'], 'true');
assert.equal(input.value, 'abc@gmial.com', 'Suggestion never silently rewrites identity');
assert.equal(input.adjacent.hidden, false);
const action = input.adjacent.children[1]; action.dispatchEvent(new context.Event('click'));
assert.equal(input.value, 'abc@gmail.com'); assert.equal(input.attributes['aria-invalid'], 'false');
assert.equal(input.adjacent.hidden, true);
input.value='ab@gmail.com'; field.validate();
input.value='a@gmail.com'; input.dispatchEvent(new context.Event('input'));
assert.equal(error.textContent, '', 'Editing dismisses rather than replacing the error');
assert.equal(input.attributes['aria-invalid'], 'false');
input.dispatchEvent(new context.Event('blur')); assert.ok(error.textContent);
input.value='abc@gmail.com'; input.dispatchEvent(new context.Event('input')); input.dispatchEvent(new context.Event('blur'));
assert.equal(error.textContent, '');
assert.equal(email.bind(input), field, 'Binding is idempotent');
console.log('Shared email entry and field interaction regressions passed.');
