const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = new Map(), elements = new Map();
function field(id = 'name') {
  const attrs = new Map(), classes = new Set();
  const input = { id, value: '', defaultValue: '', selectionStart: 0, selectionEnd: 0,
    matches: selector => selector === 'input[data-human-name]',
    setAttribute: (key, value) => attrs.set(key, value), getAttribute: key => attrs.get(key),
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    insertAdjacentElement(where, error) { elements.set(error.id, error); },
    classList: { toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); } },
    dispatchEvent: event => emit(event.type, input, event), focus() {},
  };
  elements.set(id, input); return input;
}
function emit(type, target, options = {}) {
  const event = { target, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }, ...options };
  listeners.get(type)?.(event); return event;
}
const context = { Event: class { constructor(type) { this.type = type; } }, window: {}, document: {
  addEventListener: (type, listener) => listeners.set(type, listener),
  getElementById: id => elements.get(id), createElement: () => field('error'),
} };
vm.runInNewContext(fs.readFileSync('scripts/utils/human-name.js', 'utf8'), context);
const names = context.window.HumanName;
for (const [type, limit, label] of [['first_name', 40, 'First name'], ['last_name', 80, 'Last name']]) {
  assert.equal(names.validate('É'.repeat(limit), { type }), '');
  assert.equal(names.validate('É'.repeat(limit + 1), { type }), `${label} must be ${limit} characters or fewer.`);
  assert.equal(names.validate('', { type }), `Enter your ${label.toLowerCase()}.`);
}
for (const [input, expected] of JSON.parse(fs.readFileSync('tests/Fixtures/human-names.json'))) {
  assert.equal(names.normalize(input), expected, input);
}
for (const name of ['123', 'Gerald２', 'Maria٢ Clara', 'Ⅷ', '²', '---', 'ʼ', '💙', 'Jo\nSmith']) assert.ok(names.validate(name), name);
for (const name of ['Gerald', 'de la Cruz', 'McDonald', 'Peña', '李', 'élodie']) assert.equal(names.validate(name), '', name);
assert.ok(names.validate(''));
assert.equal(names.validate(['Ana']), 'Enter a valid first name.');
const input = field();
emit('focusin', input);
input.value = 'gerald'; emit('input', input);
assert.equal(input.value, 'gerald', 'Typing does not change capitalization.');
emit('blur', input); assert.equal(input.value, 'Gerald');
input.value = 'Maria２ ClaraⅧ'; emit('input', input, { inputType: 'insertFromPaste' });
assert.equal(input.value, 'Maria Clara');
assert.equal(elements.get('nameNameError').textContent, "Numbers aren't allowed.");
assert.equal(input.getAttribute('aria-invalid'), 'true');
assert.match(input.getAttribute('aria-describedby'), /nameNameError/);
input.value += 's'; emit('input', input);
assert.equal(elements.get('nameNameError').textContent, '');
input.value = '123'; emit('input', input); assert.equal(input.value, '');
input.value = ''; emit('input', input);
assert.ok(elements.get('nameNameError').textContent, 'An existing error stays visible until corrected.');
emit('blur', input); assert.ok(elements.get('nameNameError').textContent);
input.value = '李２'; emit('compositionstart', input); emit('input', input, { isComposing: true });
assert.equal(input.value, '李２', 'IME composition is not interrupted.');
emit('compositionend', input); assert.equal(input.value, '李');
assert.equal(elements.get('nameNameError').textContent, "Numbers aren't allowed.");
input.value = '---'; emit('input', input);
const form = { querySelectorAll: () => [input] };
assert.equal(emit('submit', form).prevented, true, 'Unsupported pasted values cannot submit.');
input.value = 'de la Cruz'; emit('input', input); emit('blur', input);
assert.equal(input.value, 'de la Cruz');
input.value = 'GERALD'; names.reset(input); emit('focusin', input); emit('blur', input);
assert.equal(input.value, 'GERALD', 'Merely opening an existing field does not reformat it.');
console.log('Human name conformance passed.');

const untouched = field('untouched');
emit('focusin', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), undefined);
untouched.value = '---'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'false');
emit('blur', untouched);
untouched.value = '--'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'true');
untouched.value = 'Anne-Marie'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'false');
const other = field('other');
other.setAttribute('data-human-name', 'last_name');
other.value = 'A'.repeat(81); emit('blur', other);
assert.equal(other.getAttribute('aria-invalid'), 'true');
untouched.value = ''; emit('blur', untouched);
untouched.value = '---'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'true');
assert.equal(other.getAttribute('aria-invalid'), 'true', 'Editing one field preserves another field’s error.');
untouched.value = 'Ana'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'false');
assert.equal(other.getAttribute('aria-invalid'), 'true');
const historical = other.value;
names.bind(other, null, { isUnchanged: value => value === historical });
assert.equal(names.finish(other), true, 'Unchanged historical names remain valid during unrelated edits.');
other.value = 'Legacy2';
names.bind(other, null, { isUnchanged: value => value === 'Legacy2' });
assert.equal(names.finish(other), true);
assert.equal(other.value, 'Legacy2', 'Leaving an unchanged legacy field does not sanitize stored identity.');

names.showError(untouched, 'The server rejected this first name.', { server: true });
emit('blur', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'true', 'Blur cannot clear a server error for the same value.');
untouched.value = 'Anne'; emit('input', untouched);
assert.equal(untouched.getAttribute('aria-invalid'), 'false');
