import assert from 'node:assert/strict';
import fs from 'node:fs';
import { getSizeForWeight, getSizeForStoredWeight, formatSavedWeight, getPetWeightSubmission, getPetWeightSubmissionValidationMessage, getWeightValueValidationMessage, getSizeOptions, getWeightValidationMessage, syncPetWeightAndSize, showWeightValidationInField, getEnteredWeight } from '../scripts/components/pet-weight-size.js';

const cases = JSON.parse(fs.readFileSync('tests/Fixtures/weight-rules.json'));
const normalize = value => value.toLowerCase().replaceAll(' ', '_');
for (const [species, weight, size] of cases.pets) {
  assert.equal(normalize(getSizeForWeight(species, weight)), size);
  assert.equal(getWeightValidationMessage(species, weight), '');
}
for (const weight of cases.invalid_weights) {
  assert.equal(getSizeForWeight('Dog', weight), '');
  assert.ok(getWeightValidationMessage('Dog', weight));
}
for (const weight of cases.below_profile_minimum) {
  assert.equal(getWeightValidationMessage('Dog', weight), 'Weight must be at least 0.5 kg.');
  assert.equal(getWeightValidationMessage('Dog', weight, { clinical: true }), '');
  assert.equal(getSizeForStoredWeight('Dog', weight), 'Small');
}
for (const [species, weight, size] of cases.historical_pets) {
  assert.equal(normalize(getSizeForStoredWeight(species, weight)), size);
  assert.equal(getSizeForWeight(species, weight), '');
}
for (const [stored, expected] of [['4.100000000000', '4.1'], ['20.000000000000', '20'], ['4.999000000000', '4.999'], [null, '']]) {
  assert.equal(formatSavedWeight(stored), expected);
}
assert.deepEqual(getPetWeightSubmission({ id: '12', petType: 'Cat', weight: '4.999' }), {});
assert.equal(getPetWeightSubmissionValidationMessage({ id: '12', petType: 'Cat', weight: '4.999' }), '');
assert.deepEqual(getPetWeightSubmission({ id: 'new-pet', petType: 'Dog', weight: '4.200000' }), { weight: '4.200000' });
assert.equal(getPetWeightSubmissionValidationMessage({ id: 'new-pet', petType: 'Dog', weight: '4.200000' }), 'Enter weight using no more than 2 decimal places.');
assert.equal(getWeightValidationMessage('Dog', 0), 'Enter a weight greater than 0 kg.');
assert.equal(getWeightValidationMessage('Dog', 155.61), 'Weight cannot be more than 155.6 kg.');
assert.deepEqual(getSizeOptions('Cat').map(option => option.label), ['Small: under 5 kg', 'Medium: 5 kg and above']);
assert.deepEqual(getSizeOptions('Dog').map(option => option.label), ['Small: under 11 kg', 'Medium: 11 kg to under 26 kg', 'Large: 26 kg to under 51 kg', 'Extra Large: 51 kg and above']);

const error = { textContent: '', classList: { toggle() {} } };
const input = { id: 'weight', value: '4.25', readOnly: false, dataset: {}, classList: { toggle() {} }, setAttribute(name, value) { this[name] = value; }, ownerDocument: { getElementById() { return error; } } };
const control = { value: 'Medium', setOptions() {}, setValue(value) { this.value = value; }, setDisabled(value) { this.disabled = value; }, getValue() { return this.value; } };
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(control.getValue(), 'Small');
assert.equal(control.disabled, true);
assert.equal(getEnteredWeight(input), '4.25');
for (const value of ['4.', '4.2', '4.20', '4.25']) {
  input.value = value;
  syncPetWeightAndSize(input, 'Cat', control);
  assert.equal(input.value, value);
  assert.equal(error.textContent, '');
  assert.equal(control.getValue(), 'Small');
  assert.equal(control.disabled, true);
}
for (const value of ['4.780', '4.200000', '10.555']) {
  input.value = value;
  syncPetWeightAndSize(input, 'Cat', control);
  assert.equal(input.value, value);
  assert.equal(error.textContent, 'Enter weight using no more than 2 decimal places.');
  assert.equal(input['aria-invalid'], 'true');
}
input.value = '0.49';
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(input.value, '0.49');
assert.equal(error.textContent, 'Weight must be at least 0.5 kg.');
input.value = '0.50';
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(error.textContent, '');
assert.equal(control.getValue(), 'Small');
input.value = '4.25';
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(error.textContent, '');
assert.equal(input['aria-invalid'], 'false');
input.value = '-1';
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(input.value, '-1');
assert.equal(input.readOnly, false);
assert.equal(input['aria-invalid'], 'true');
assert.equal(error.textContent, 'Enter a weight greater than 0 kg.');
input.value = '';
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(control.disabled, false);
assert.equal(error.textContent, '');
control.setValue('Medium');
syncPetWeightAndSize(input, 'Cat', control);
assert.equal(control.getValue(), 'Medium');
assert.equal(input.value, '');
showWeightValidationInField(input, 'Enter a valid weight in kilograms.');
assert.equal(input.value, '');
assert.equal(input.readOnly, false);

for (const file of ['my-pets', 'booking-pet-step', 'walk-in-pet-step', 'admin-customers']) {
  const source = fs.readFileSync(`scripts/components/${file}.js`, 'utf8');
  assert.match(source, file === 'admin-customers' ? /tools\.syncPetWeightAndSize\(/ : /syncWeightSizeFields\(/);
  assert.doesNotMatch(source, /showWeightRangeInField|weightFieldMode|resetWeightFieldForEntry/);
  assert.match(source, /(?:sizeCombobox|controls\.size)\.getValue\(\)/);
}
for (const [file, id] of [['pages/client/my-pets.html', 'petWeight'], ['pages/admin/pet-edit-form.html', 'adminPetWeight'], ['pages/client/booking-pet-details.html', 'weight'], ['pages/client/grooming-pre-registration.html', 'weight'], ['pages/admin/walk-in-pet-details.html', 'weight']]) {
  const html = fs.readFileSync(file, 'utf8');
  assert.match(html, new RegExp(`for="${id}"`));
  assert.match(html, new RegExp(`aria-describedby="${id}Error"`));
  assert.match(html, /inputmode="decimal"/);
  assert.match(html, /text-slate-500">kg<\/span>/);
}
// Entries and changed dependencies need fresh URLs together; mixed cached versions cannot link.
const release = 'pet-weight-size-20261107-v1';
for (const file of ['my-pets', 'booking-pet-step', 'walk-in-pet-step', 'admin-customers']) {
  assert.ok(fs.readFileSync(`scripts/components/${file}.js`, 'utf8').includes(`./pet-weight-size.js?v=${release}`));
}
assert.ok(fs.readFileSync('scripts/components/grooming-pre-registration.js', 'utf8').includes(`./booking-pet-step.js?v=${release}`));
assert.ok(fs.readFileSync('scripts/components/booking-pet-step.js', 'utf8').includes(`../services/pet-service.js?v=${release}`));
assert.ok(fs.readFileSync('scripts/services/pet-service.js', 'utf8').includes(`../components/pet-weight-size.js?v=${release}`));
for (const file of ['pages/client/my-pets.html', 'pages/admin/clients.html', 'pages/client/booking-pet-details.html', 'pages/client/grooming-pre-registration.html', 'pages/admin/walk-in-pet-details.html']) {
  assert.ok(fs.readFileSync(file, 'utf8').includes(release), `${file} must refresh its changed controller`);
}
console.log('Pet decimal thresholds, validation, editable field values, automatic/manual size controls and shared flow wiring passed.');

const clinicHtml = fs.readFileSync('pages/admin/clinic.html', 'utf8');
assert.match(clinicHtml, /for="clinicWeight"/);
assert.match(clinicHtml, /id="clinicWeight"[^>]*type="text"[^>]*inputmode="decimal"/);
assert.match(clinicHtml, /aria-describedby="clinicWeightError"/);
assert.equal(getWeightValueValidationMessage('4.780'), 'Enter weight using no more than 2 decimal places.');
