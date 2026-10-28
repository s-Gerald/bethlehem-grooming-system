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
for (const [input, expected] of JSON.parse(fs.readFileSync('tests/Fixtures/human-names.json'))) {
  assert.equal(names.normalize(input), expected, input);
}
for (const name of ['123', 'Gerald２', 'Maria٢ Clara', 'Ⅷ', '²', '---', 'ʼ', '💙', 'Jo\nSmith']) assert.ok(names.validate(name), name);
for (const name of ['Gerald', 'de la Cruz', 'McDonald', 'Peña', '李', 'élodie']) assert.equal(names.validate(name), '', name);
assert.ok(names.validate(''));
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
assert.equal(elements.get('nameNameError').textContent, '', 'Clearing does not immediately show a required error.');
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
