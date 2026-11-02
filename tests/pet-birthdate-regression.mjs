import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as weightTools from '../scripts/components/pet-weight-size.js';
import { getPetBirthdateBounds, getPetBirthdateValidationMessage, createPetBirthdateField } from '../scripts/components/pet-birthdate.js';

for (const [now, min, max] of [
  ['2026-11-02T15:59:59Z', '1986-11-02', '2026-11-02'],
  ['2026-11-02T16:00:00Z', '1986-11-03', '2026-11-03'],
  ['2028-02-28T16:00:00Z', '1988-02-29', '2028-02-29'],
  ['2100-02-28T16:00:00Z', '2060-03-01', '2100-03-01'],
  ['2140-02-28T16:00:00Z', '2100-02-28', '2140-02-29'],
]) assert.deepEqual(getPetBirthdateBounds(new Date(now)), { min, max });

const now = new Date('2026-11-02T16:00:00Z');
for (const value of ['2026-11-03', '1986-11-03', '2024-02-29', '', null]) {
  assert.equal(getPetBirthdateValidationMessage(value, { now }), '');
}
for (const [value, expected] of [
  ['2026-11-04', 'Birthdate cannot be in the future.'],
  ['1986-11-02', 'Enter a birthdate within the last 40 years.'],
  ['1700-01-01', 'Enter a birthdate within the last 40 years.'],
  ['2026-02-30', 'Enter a valid birthdate.'],
  ['2026-2-03', 'Enter a valid birthdate.'],
  ['2026-11-03T00:00:00Z', 'Enter a valid birthdate.'],
]) assert.equal(getPetBirthdateValidationMessage(value, { now }), expected);
assert.equal(getPetBirthdateValidationMessage('1700-01-01', { now, originalValue: '1700-01-01' }), '');
assert.equal(getPetBirthdateValidationMessage('1700-01-02', { now, originalValue: '1700-01-01' }), 'Enter a birthdate within the last 40 years.');

function element(id) {
  const listeners = new Map(), attributes = new Map();
  return { id, value: '', validity: {}, dataset: {}, classList: { toggle() {} }, textContent: '', disabled: false,
    addEventListener(type, fn) { listeners.set(type, [...(listeners.get(type) || []), fn]); },
    emit(type, event = {}) { for (const fn of listeners.get(type) || []) fn(event); },
    setAttribute(key, value) { attributes.set(key, value); }, getAttribute(key) { return attributes.get(key) || null; },
    removeAttribute(key) { attributes.delete(key); if (['min', 'max'].includes(key)) delete this[key]; },
    setCustomValidity(value) { this.customError = value; },
    focus() { this.focused = true; },
  };
}
const input = element('birthdate'), errorElement = element('birthdateError'), form = element('form');
input.form = form;
let clock = now, invalidations = 0;
const field = createPetBirthdateField({ input, errorElement, now: () => clock, onInvalid: () => invalidations++ });
assert.equal(input.min, '1986-11-03'); assert.equal(input.max, '2026-11-03');
field.setValue('2026-11-04');
assert.equal(field.validate({ focus: true }), false);
assert.equal(input.value, '2026-11-04'); assert.equal(input.focused, true);
assert.equal(input.getAttribute('aria-invalid'), 'true');
assert.equal(input.getAttribute('aria-describedby'), 'birthdateError');
assert.equal(errorElement.textContent, 'Birthdate cannot be in the future.');
input.value = '2026-11-03'; input.emit('input');
assert.equal(errorElement.textContent, ''); assert.equal(input.customError, '');
input.value = ''; input.validity.badInput = true; input.emit('invalid', { preventDefault() {} });
assert.equal(errorElement.textContent, 'Enter a valid birthdate.');
input.validity.badInput = false;
field.setValue('1700-01-01', { originalValue: '1700-01-01' });
assert.equal(field.validate(), true);
assert.equal(input.min, '1986-11-03', 'Historical exceptions never widen calendar selection');
form.emit('click', { target: { closest: () => ({ type: 'submit', form }) } });
assert.equal(input.min, undefined, 'Unchanged legacy values bypass native range checks only during submission');
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(input.min, '1986-11-03'); assert.equal(input.max, '2026-11-03');
input.emit('input'); input.value = '1700-01-02';
assert.equal(field.validate(), false);
field.setValue('1986-11-03');
assert.equal(field.validate(), true);
clock = new Date('2026-11-03T16:00:00Z');
input.emit('pointerdown');
assert.equal(input.max, '2026-11-04', 'Reopening the picker refreshes an input left focused across midnight');
let blocked = false;
form.emit('submit', { preventDefault() { blocked = true; }, stopImmediatePropagation() {} });
assert.equal(blocked, true); assert.equal(input.min, '1986-11-04');
assert.ok(invalidations > 0);

const sanitized = element('restoredBirthdate');
let renderedValue = '';
Object.defineProperty(sanitized, 'value', {
  get: () => renderedValue,
  set(value) { renderedValue = value === '2026-02-30' ? '' : value; },
});
sanitized.form = element('restoredForm');
const restoredField = createPetBirthdateField({ input: sanitized, errorElement: element('restoredError'), now: () => now });
restoredField.setValue('2026-02-30');
assert.equal(restoredField.getValue(), '2026-02-30');
assert.equal(restoredField.validate(), false, 'A sanitized restored date cannot silently save as an optional empty value');
sanitized.value = '2026-11-03'; sanitized.emit('input');
assert.equal(restoredField.validate(), true);

// Exercise the real admin save/payload methods and the shared date control.
const adminInput = element('adminPetBirthdate'), adminError = element('adminPetBirthdateError');
adminInput.form = element('adminPetEditForm');
const adminField = createPetBirthdateField({ input: adminInput, errorElement: adminError, now: () => now });
const otherInput = element('other');
const controls = Object.fromEntries(['color', 'breed', 'furType', 'gender', 'size', 'species'].map(key => [key, {
  getValue: () => key === 'species' ? 'Dog' : '', getValidationMessage: () => '', showValidation() {},
}]));
controls.birthdate = adminField;
let writes = [];
const context = vm.createContext({ API: {
  async adminUpdatePet(id, payload) { writes.push({ id, payload }); return { pet: { pet_id: id, ...payload } }; },
  async adminAddCustomerPet(type, id, payload) { writes.push({ type, id, payload }); return { pet: { pet_id: 2, ...payload } }; },
} });
vm.runInContext(fs.readFileSync('scripts/components/admin-customers.js', 'utf8'), context);
context.fields = { controls, tools: { ...weightTools, normalizePetSize: value => value },
  elements: { birthdate: adminInput, breed: otherInput, furType: otherInput, weight: otherInput } };
vm.runInContext('adminCustomerPetFields = fields;', context);
const admin = vm.runInContext('adminCustomers()', context);
admin.petModal = { pet: { id: 1 }, form: { pet_name: 'Legacy', species: 'Dog' }, editing: true, saving: false, creating: false };
adminField.setValue('1700-01-01', { originalValue: '1700-01-01' });
await admin.saveEditPet();
assert.equal(writes.length, 1); assert.equal(writes[0].payload.birthdate, '1700-01-01');
adminInput.value = '1700-01-02';
await admin.saveEditPet();
assert.equal(writes.length, 1, 'Admin cannot replace a historical date with another invalid date');
assert.equal(adminInput.focused, true);
adminInput.value = '1986-11-03';
await admin.saveEditPet();
assert.equal(writes.length, 2); assert.equal(writes[1].payload.birthdate, '1986-11-03');
admin.petModal.creating = true; admin.petModal.owner = { recordType: 'unregistered', id: 42 };
adminField.setValue('1700-01-01');
await admin.saveEditPet();
assert.equal(writes.length, 2, 'New admin pets never inherit the legacy exception');
adminField.setValue('2026-11-03');
await admin.saveEditPet();
assert.equal(writes.length, 3); assert.equal(writes[2].type, 'unregistered');
console.log('Pet birthdate calendar, timezone, inline validation, legacy and midnight regressions passed');
