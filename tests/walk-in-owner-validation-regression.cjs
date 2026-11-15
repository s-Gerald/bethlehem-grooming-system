const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const read = file => fs.readFileSync(file, 'utf8');
function setup(type) {
  const owner = { appointmentType: type, ownerRecordType: 'new', firstName: 'Ana', lastName: 'Cruz', phone: '09171234567', email: 'ana@gmail.com' };
  const stored = new Map([
    ['walkInOwnerStep', JSON.stringify(owner)], ['walkInPetStep', '{"pets":[{"petName":"Mochi"}]}'],
    ['walkInReviewStep', '{"items":[{}]}'], ['walkInConsentStep', '{"signature_strokes":[[[1,2],[3,4]]]}'],
    ['walkInClinicComplaint', '{"chiefComplaint":"Cough"}'],
    ['walkInClinicConsent', '{"terms_agreed":true,"consent_version":"clinic-v1","agreement_hash":"reviewed","signature_strokes":[[[1,2],[3,4]]]}'],
  ]);
  const window = { location: { href: '' } };
  const context = vm.createContext({ window, document: { addEventListener() {} },
    sessionStorage: { getItem: key => stored.get(key), setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) },
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
  const row = { pet: { id: 1, petName: 'Mochi' }, chiefComplaint: 'Cough', commonConcerns: ['Other'] };
  stored.set('walkInClinicComplaint', JSON.stringify({ patients: [row] }));
  vm.runInContext(read('scripts/components/walk-in-clinic-complaint-step.js').replace(/^import .*;$/gm, '').replace(/^export /gm, ''), context);
  context.savePatients([row]);
  assert.equal(JSON.parse(stored.get('walkInClinicConsent')).terms_agreed, true, 'Continuing after an owner correction preserves agreement for the same visit.');
  context.savePatients([{ ...row, chiefComplaint: 'Limping' }]);
  assert.equal(stored.has('walkInClinicConsent'), false, 'Changes to the visit require another review.');
}
async function checkClinicConsent() {
  const { owner, stored, window, context } = setup('clinic');
  const { CLINIC_MAX_PATIENTS, validateClinicVisitReason, clinicRegistrationError } = await import('../scripts/services/clinic-visit-service.js');
  let submissions = 0;
  let consentValid = true;
  const signed = JSON.parse(stored.get('walkInClinicConsent'));
  context.signedConsent = {
    valid: () => consentValid, validate: () => consentValid, clearDraft: () => stored.delete('walkInClinicConsent'),
    payload: () => signed, handleError: async () => false,
  };
  Object.assign(context, {
    CLINIC_MAX_PATIENTS, validateClinicVisitReason, clinicRegistrationError,
    tooLong: require('../scripts/utils/short-notes.js').tooLong,
    API: { submitClinicWalkIn: async () => { submissions++; throw { errors: { lname: ['Enter a valid last name.'] } }; } },
    getPetWeightSubmissionValidationMessage: () => '', getPetWeightSubmission: () => ({}),
  });
  vm.runInContext(read('scripts/components/walk-in-clinic-consent-step.js').replace(/^import .*;$/gm, '').replace(/^export /gm, ''), context);
  vm.runInContext("elements = { submitBtn: {}, backButton: {}, statusMsg: {} }; consent = signedConsent; ownerValid = true; state.patients = [{ pet: { petName: 'Mochi' }, commonConcerns: ['Other'], chiefComplaint: 'Cough' }];", context);
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
  assert.deepEqual(JSON.parse(stored.get('walkInClinicConsent')), signed);

  const { submitBtn, statusMsg } = vm.runInContext('elements', context);
  const [patient] = vm.runInContext('state.patients', context);
  stored.set('walkInOwnerStep', JSON.stringify(owner));
  window.location.href = '';
  vm.runInContext('ownerValid = true', context);
  consentValid = false;
  context.syncSubmitButton();
  await context.handleSubmit(event);
  assert.equal(submitBtn.disabled, true);
  assert.equal(submissions, 1, 'An unsigned review cannot reach the API.');
  consentValid = true;
  context.syncSubmitButton();
  assert.equal(submitBtn.disabled, false);

  patient.commonConcerns = [];
  await context.handleSubmit(event);
  assert.equal(submissions, 1);
  assert.equal(statusMsg.textContent, 'Review the selected pets and provide a reason for each before checking in.');
  patient.commonConcerns = ['Other'];

  let rejectRequest;
  context.API.submitClinicWalkIn = () => {
    submissions++;
    return new Promise((_, reject) => { rejectRequest = reject; });
  };
  const pending = context.handleSubmit(event);
  assert.equal(submissions, 2);
  assert.equal(submitBtn.disabled, true);
  assert.equal(submitBtn.textContent, 'Checking in…');
  await context.handleSubmit(event);
  assert.equal(submissions, 2, 'Check In remains locked while the request is pending.');
  rejectRequest({});
  await pending;
  assert.equal(statusMsg.textContent, 'Unable to check in this clinic visit. Please try again.');
  assert.equal(submitBtn.textContent, 'Check In');
  assert.equal(submitBtn.disabled, false);
  assert.equal(window.location.href, '');

  context.API.submitClinicWalkIn = async payload => {
    submissions++;
    for (const [field, value] of Object.entries(signed)) assert.deepEqual(payload[field], value);
    assert.equal(payload.patients[0].chief_complaint, 'Cough');
    return { session: { queue_number: 4 } };
  };
  await context.handleSubmit(event);
  assert.equal(submissions, 3);
  assert.equal(stored.has('walkInClinicConsent'), false);
  assert.equal(window.location.href, '/admin/walk-in/clinic/confirmed');
  assert.deepEqual(JSON.parse(stored.get('walkInClinicConfirmation')), { queue_number: 4, terms_agreed: true });
  console.log('Walk-in final name checks, preserved drafts and Clinic check-in states passed.');
}
checkClinicConsent().catch(error => { console.error(error); process.exitCode = 1; });
