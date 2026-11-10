const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const read = file => fs.readFileSync(file, 'utf8');
function setup(type) {
  const owner = { appointmentType: type, ownerRecordType: 'new', firstName: 'Ana', lastName: 'Cruz', phone: '09171234567', email: 'ana@gmail.com' };
  const stored = new Map([
    ['walkInOwnerStep', JSON.stringify(owner)], ['walkInPetStep', '{"pets":[{"petName":"Mochi"}]}'],
    ['walkInReviewStep', '{"items":[{}]}'], ['walkInConsentStep', '{"signature_strokes":[[[1,2],[3,4]]]}'],
    ['walkInClinicComplaint', '{"chiefComplaint":"Cough","termsAgreed":true}'],
  ]);
  const window = { location: { href: '' } };
  const context = vm.createContext({ window, document: { addEventListener() {} },
    sessionStorage: { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value) },
  });
  vm.runInContext(read('scripts/utils/human-name.js'), context);
  vm.runInContext(read('scripts/utils/walk-in-owner-validation.js').replace(/^import .*;$/gm, '').replace(/^export /gm, ''), context);
  const validation = vm.runInContext('WalkInOwnerValidation', context);
  return { owner, stored, window, validation, context };
}
for (const type of ['grooming', 'clinic']) {
  const { owner, stored, window, validation } = setup(type);
  const continuation = [...stored].filter(([key]) => key !== 'walkInOwnerStep');
  assert.equal(validation.validate(owner), true);
  owner.firstName = 'A'.repeat(41); stored.set('walkInOwnerStep', JSON.stringify(owner));
  assert.equal(validation.validate(owner), false);
  assert.equal(window.location.href, `/admin/walk-in/customer?flow=${type}`);
  assert.equal(JSON.parse(stored.get('walkInOwnerStep')).nameErrors.firstName.message, 'First name must be 40 characters or fewer.');
  assert.deepEqual([...stored].filter(([key]) => key !== 'walkInOwnerStep'), continuation);
  const corrected = { ...owner, firstName: 'Ana' };
  stored.set('walkInOwnerStep', JSON.stringify(corrected)); window.location.href = '';
  assert.equal(validation.serverErrors(owner, { fname: ['Old error'] }), false, 'Stale response cannot overwrite corrected name.');
  assert.equal(window.location.href, '');
  assert.equal(validation.serverErrors(corrected, { lname: ['Last name must be 80 characters or fewer.'] }), true);
  assert.equal(JSON.parse(stored.get('walkInOwnerStep')).nameErrors.lastName.value, 'Cruz');
  assert.deepEqual([...stored].filter(([key]) => key !== 'walkInOwnerStep'), continuation);
  assert.equal(validation.validate({ ...corrected, ownerRecordType: 'registered', firstName: 'Legacy2'.repeat(20) }), true);
}
{
  const { context, stored, window } = setup('clinic');
  window.history = { pushState() {} };
  context.renderWalkInClinicConsentStep = () => {};
  stored.set('walkInClinicComplaint', JSON.stringify({ chiefComplaint: 'Cough', commonConcerns: [], pet: { petName: 'Mochi' }, termsAgreed: true }));
  vm.runInContext(read('scripts/components/walk-in-clinic-complaint-step.js').replace(/^import .*;$/gm, '').replace(/^export /gm, ''), context);
  vm.runInContext("state = { pet: { petName: 'Mochi' }, fields: { validate: () => true, concerns: () => [], details: { value: 'Cough' } } };", context);
  context.handleSubmit({ preventDefault() {} });
  assert.equal(JSON.parse(stored.get('walkInClinicComplaint')).termsAgreed, true, 'Continuing after an owner correction preserves agreement for the same visit.');
  vm.runInContext("state.fields.details.value = 'Limping';", context);
  context.handleSubmit({ preventDefault() {} });
  assert.notEqual(JSON.parse(stored.get('walkInClinicComplaint')).termsAgreed, true, 'Changes to the visit require another review.');
}
async function checkClinicConsent() {
  const { owner, stored, window, context } = setup('clinic');
  let submissions = 0;
  Object.assign(context, {
    API: { submitClinicWalkIn: async () => { submissions++; throw { errors: { lname: ['Enter a valid last name.'] } }; } },
    getPetWeightSubmissionValidationMessage: () => '', getPetWeightSubmission: () => ({}),
  });
  vm.runInContext(read('scripts/components/walk-in-clinic-consent-step.js').replace(/^import .*;$/gm, '').replace(/^export /gm, ''), context);
  vm.runInContext("elements = { checkbox: { checked: true }, submitBtn: {}, statusMsg: {} }; state.pet = { petName: 'Mochi' };", context);
  const event = { preventDefault() {} };
  stored.set('walkInOwnerStep', JSON.stringify({ ...owner, firstName: undefined }));
  await context.handleSubmit(event);
  assert.equal(submissions, 0, 'Missing owner names cannot reach final Clinic submission.');
  assert.equal(JSON.parse(stored.get('walkInOwnerStep')).nameErrors.firstName.value, '', 'The owner input restores a missing name as empty.');
  stored.delete('walkInOwnerStep');
  await context.handleSubmit(event);
  assert.equal(submissions, 0);
  assert.equal(window.location.href, '/admin/walk-in/customer?flow=clinic');
  assert.equal(JSON.parse(stored.get('walkInOwnerStep')).appointmentType, 'clinic');
  stored.set('walkInOwnerStep', JSON.stringify(owner));
  await context.handleSubmit(event);
  assert.equal(submissions, 1);
  assert.equal(window.location.href, '/admin/walk-in/customer?flow=clinic');
  assert.equal(JSON.parse(stored.get('walkInOwnerStep')).nameErrors.lastName.message, 'Enter a valid last name.');
  assert.equal(JSON.parse(stored.get('walkInClinicComplaint')).termsAgreed, true);
  console.log('Walk-in final name checks, field recovery, stale responses and preserved drafts passed.');
}
checkClinicConsent().catch(error => { console.error(error); process.exitCode = 1; });
