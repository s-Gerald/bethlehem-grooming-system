const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../scripts/components/admin-clinic.js'), 'utf8');
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return {promise, resolve};
};
const record = {appointment_id: 10, status: 'completed', working_diagnosis: 'Otitis', attachments: [{id: 1}]};
const pet = {id: 7, petName: 'Mochi', species: 'Dog', breed: 'Mixed', gender: 'female', birthdate: '2021-02-03',
  weight: 12.5, size: 'medium', sizeVerified: true, color: 'Brown', furType: 'short_coat',
  isNeutered: true, neuteredDate: '2022-05-06', isDeceased: false, medicalConditions: ' Sensitive skin '};
const owner = {id: 8, recordType: 'registered', fullName: 'Ana Santos', phone: '09171234567', email: 'ana@example.test'};

function setup() {
  const calls = [], focused = [], events = [];
  const context = {
    document: {body: {style: {overflow: 'auto'}}, addEventListener() {}, activeElement: null},
    window: {addEventListener() {}, dispatchEvent: event => events.push(event)},
    CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
    API: {
      loadAdminData: (key, load, render) => load().then(render),
      adminGetPetProfile: async id => {calls.push(['history', id]); return {medical_records: [record]};},
      getCurrentPetClinicCase: async () => ({case: null}),
      adminGetPetCaseDetails: async (petId, caseId) => {calls.push(['case', petId, caseId]); return {case: {id: caseId}, medical_record: record};},
      getCustomerDetails: async id => {calls.push(['registered', id]); return {customer: {...owner, pets: [pet]}};},
      getUnregisteredCustomerDetails: async id => {calls.push(['unregistered', id]); return {customer: {...owner, pets: [pet]}};},
    },
    setTimeout, clearTimeout, console,
  };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
  vm.runInContext(source, context);
  const state = context.adminClinicPage();
  state.$nextTick = callback => callback();
  state.$refs = {profileBody: {scrollTop: 0}, profileClose: {focus: () => focused.push('close')}, profileBack: {focus: () => focused.push('back')}};
  state.$store = {clinicAccess: {permissions: {access: true, intake: true, clinical: true}}, clinicFeedback: {confirm: async () => true, notify() {}}};
  const trigger = {focus: () => focused.push('trigger')};
  return {context, state, calls, focused, events, trigger};
}

(async () => {
  const app = setup();
  await app.state.openProfile({pet: {id: 7, petName: 'Mochi'}, owner}, app.trigger);
  assert.deepEqual(app.calls, [['history', 7], ['registered', 8]]);
  assert.equal(app.state.profile.pet.color, 'Brown', 'Profile loads full pet data rather than the directory summary');
  assert.equal(app.state.patientName, 'Mochi');
  assert.equal(app.state.patientConditions, 'Sensitive skin');
  assert.equal(app.state.profile.records[0], record, 'Full completed record data is reused without a second fetch');
  const fields = Object.fromEntries(app.state.patientInformation().map(field => [field.label, field.value]));
  assert.equal(fields.Weight, '12.5 kg');
  assert.equal(fields.Gender, 'Female');
  assert.equal(fields['Fur Type'], 'Short Coat');
  assert.equal(fields['Neutered / Spayed'], 'Yes');
  assert.ok(fields['Neutered Date']);
  assert.equal(fields.Deceased, 'No');
  assert.equal('Deceased Date' in fields, false);
  assert.equal('Phone' in fields, false, 'Owner contact belongs outside patient information');
  assert.equal('Pet Name' in fields, false, 'Patient identity is only in the header');
  assert.equal(app.context.document.body.style.overflow, 'hidden');
  assert.equal(app.focused.at(-1), 'close');

  app.state.$refs.profileBody.scrollTop = 340;
  const rowButton = {focus: () => app.focused.push('record')};
  await app.state.viewDetail(record, {querySelector: () => rowButton});
  assert.equal(app.state.detail.record, record);
  assert.equal(app.state.profile.open, true, 'Drill-down stays inside the same workspace');
  assert.equal('open' in app.state.detail, false, 'There is no second detail-modal state');
  assert.equal(app.state.$refs.profileBody.scrollTop, 0);
  assert.equal(app.focused.at(-1), 'back');
  assert.deepEqual(app.calls.at(-1), ['case', 7, 10], 'Details read the selected case through the pet-scoped API');
  app.state.backToProfile();
  assert.equal(app.state.detail.record, null);
  assert.equal(app.state.profile.records.length, 1);
  assert.equal(app.state.$refs.profileBody.scrollTop, 340);
  assert.equal(app.focused.at(-1), 'record');
  app.state.closeProfile();
  assert.equal(app.context.document.body.style.overflow, 'auto');
  assert.equal(app.focused.at(-1), 'trigger');

  await app.state.openProfile({pet: {id: 7}, owner: {...owner, recordType: 'unregistered'}}, app.trigger);
  assert.deepEqual(app.calls.at(-1), ['unregistered', 8], 'Walk-in customers use their existing details API');
  app.state.profile.pet = {...pet, isNeutered: false, isDeceased: true, deceasedDate: '2026-01-01', medicalConditions: null};
  const deceased = Object.fromEntries(app.state.patientInformation().map(field => [field.label, field.value]));
  assert.ok(deceased['Deceased Date']);
  assert.equal('Neutered Date' in deceased, false);
  assert.equal(app.state.patientConditions, '');
  app.state.closeProfile();

  const treatment = setup();
  const treatmentRows = [
    {id: 1, treatment_type: 'vaccine', treatment_name: 'Rabies'},
    {id: 2, treatment_type: 'medicine', treatment_name: 'Amoxicillin'},
    {id: 3, treatment_type: null, vaccine_name: 'Legacy treatment'},
    {id: 4, treatment_type: 'unknown'},
    {id: 5},
  ];
  const originalTreatments = JSON.stringify(treatmentRows);
  let treatmentReads = 0;
  treatment.context.API.getAdminPetVaccinations = async () => {
    treatmentReads++;
    return {vaccinations: treatmentRows, current_page: 1, last_page: 2};
  };
  await treatment.state.openProfile({pet: {id: 7}, owner});
  assert.equal(treatment.state.treatmentTypeFilter, 'all');
  await treatment.state.loadHistory('treatment');
  const treatmentIds = () => Array.from(treatment.state.filteredTreatmentRows, row => row.id);
  assert.deepEqual(treatmentIds(), [1, 2, 3, 4, 5], 'All types includes legacy records');
  assert.deepEqual(treatmentRows.map(row => treatment.state.treatmentTypeLabel(row)), ['Vaccine', 'Medicine', '—', '—', '—']);
  treatment.state.treatmentTypeFilter = 'vaccine';
  assert.deepEqual(treatmentIds(), [1]);
  treatment.state.treatmentTypeFilter = 'medicine';
  assert.deepEqual(treatmentIds(), [2]);
  await treatment.state.viewDetail(treatment.state.filteredTreatmentRows[0], null, 'treatment');
  assert.equal(treatment.state.detail.record, treatmentRows[1], 'View opens the selected filtered record');
  treatment.state.backToProfile();
  assert.equal(treatment.state.treatmentTypeFilter, 'medicine', 'Returning from details preserves the filter');
  treatment.state.selectHistory('medical');
  treatment.state.selectHistory('treatment');
  assert.equal(treatmentReads, 1, 'Filtering and revisiting the tab never fetch history');
  assert.equal(JSON.stringify(treatmentRows), originalTreatments, 'Filtering leaves loaded records intact');
  const nextTreatments = deferred();
  treatment.context.API.getAdminPetVaccinations = () => nextTreatments.promise;
  const loadingTreatments = treatment.state.loadHistory('treatment', 2);
  assert.equal(treatment.state.histories.treatment.loading, true);
  nextTreatments.resolve({vaccinations: [{id: 6, treatment_type: 'medicine'}], current_page: 2, last_page: 2});
  await loadingTreatments;
  assert.deepEqual(treatmentIds(), [2, 6], 'Newly loaded matching rows honor the active filter');
  assert.equal(treatment.state.histories.treatment.page, 2);
  assert.equal(treatment.state.histories.treatment.lastPage, 2);
  treatment.state.histories.treatment.rows = [treatmentRows[0]];
  assert.deepEqual(treatmentIds(), [], 'Medicine filter can have no matching records');
  treatment.state.histories.treatment.rows = [treatmentRows[1]];
  treatment.state.treatmentTypeFilter = 'vaccine';
  assert.deepEqual(treatmentIds(), [], 'Vaccine filter can have no matching records');
  treatment.context.API.getCustomerDetails = async () => ({customer: {...owner, pets: [{...pet, id: 9}]}});
  await treatment.state.openProfile({pet: {id: 9}, owner});
  assert.equal(treatment.state.treatmentTypeFilter, 'all', 'Opening a different patient resets the filter');
  assert.deepEqual(treatmentIds(), []);
  treatment.state.closeProfile();

  const delayed = setup(), history = deferred();
  delayed.context.API.adminGetPetProfile = () => history.promise;
  const opening = delayed.state.openProfile({pet: {id: 7}, owner}, delayed.trigger);
  assert.equal(delayed.state.profile.loading, true);
  delayed.state.closeProfile();
  delayed.context.API.adminGetPetProfile = async () => ({medical_records: []});
  await delayed.state.openProfile({pet: {id: 7}, owner}, delayed.trigger);
  history.resolve({medical_records: [record]});
  await opening;
  assert.equal(delayed.state.profile.records.length, 0, 'A closed profile response cannot overwrite a new profile');
  delayed.state.closeProfile();

  const failed = setup();
  failed.context.API.getCustomerDetails = async () => {throw new Error('Unable to load customer');};
  await failed.state.openProfile({pet: {id: 7}, owner}, failed.trigger);
  assert.equal(failed.state.profile.error, 'Unable to load customer');
  assert.equal(failed.state.profile.loading, false);
  failed.state.closeProfile();

  const workflow = setup();
  await workflow.state.openProfile({pet: {id: 7}, owner}, workflow.trigger);
  const transitions = [];
  let ongoing = [];
  workflow.context.API.getCurrentPetClinicCase = async () => ({case: ongoing[0] || null});
  workflow.context.API.getActiveClinicCases = async () => ({cases: ongoing});
  workflow.context.API.createClinicCase = async payload => {
    transitions.push(['create', payload.pet_id, payload.case_type]);
    const visit = {id: 90, status: 'checked_in', queue_number: 4, pet: {id: 7}};
    ongoing = [visit];
    return {case: visit};
  };
  workflow.context.API.clinicCheckIn = async () => {throw new Error('A newly created checked-in case needs no second check-in');};
  workflow.context.API.startClinicCase = async id => {transitions.push(['start', id]); ongoing = [{...ongoing[0], status: 'in_consultation'}]; return {case: ongoing[0]};};
  await workflow.state.checkInPatient();
  assert.deepEqual(transitions, [['create', 7, 'consultation']], 'Check-in never automatically starts consultation');
  assert.equal(workflow.state.currentClinicVisit.status, 'checked_in');
  assert.equal(workflow.state.currentClinicVisit.queue_number, 4);
  assert.equal(workflow.state.activeCount, 1);
  assert.equal(workflow.events.some(event => event.type === 'clinic-open-modal'), false);
  assert.equal(workflow.state.profile.open, true, 'Check-in keeps patient/history and the operational case separate');
  assert.equal(workflow.state.profileActionLabel, 'Start Consultation');
  await workflow.state.checkInPatient();
  assert.equal(transitions.length, 1, 'An ongoing case prevents repeat creation');
  await workflow.state.runProfileAction();
  assert.deepEqual(transitions.at(-1), ['start', 90]);
  assert.equal(workflow.events.at(-1).type, 'clinic-open-modal', 'Starting from the profile opens the returned active case automatically');
  assert.equal(workflow.events.at(-1).detail.appt.status, 'in_consultation');
  assert.equal(workflow.state.profile.open, false);
  assert.equal(workflow.state.profileActionLabel, 'Open Case');
  assert.equal(workflow.state.caseActionLabel(ongoing[0]), 'Open Case');
  const starts = transitions.length;
  workflow.state.openProfileCase();
  assert.equal(transitions.length, starts, 'Reopening does not start consultation again');
  ongoing = [{...ongoing[0], status: 'for_payment'}];
  workflow.state.activeCases = ongoing;
  await workflow.state.loadProfileVisit();
  assert.equal(workflow.state.profileActionLabel, 'Open Case');
  const openedEvents = workflow.events.length;
  workflow.state.openProfileCase();
  await workflow.state.runProfileAction();
  assert.equal(workflow.events.length, openedEvents + 2, 'For Payment preserves the existing Open Case action');
  assert.equal(transitions.length, starts, 'Opening a payment case does not restart consultation');

  const waiting = setup();
  await waiting.state.openProfile({pet: {id: 7}, owner}, waiting.trigger);
  let waitingVisit = {id: 2, status: 'waiting_to_arrive', pet: {id: 7}};
  waiting.state.arrivals = [waitingVisit];
  waiting.context.API.getActiveClinicCases = async () => waitingVisit.status === 'waiting_to_arrive'
    ? {cases: [], arrivals: [waitingVisit]} : {cases: [waitingVisit], arrivals: []};
  waiting.context.API.getCurrentPetClinicCase = async () => waitingVisit.status === 'waiting_to_arrive'
    ? {case: null, arrival: waitingVisit} : {case: waitingVisit, arrival: null};
  waiting.context.API.createClinicCase = async () => {throw new Error('Waiting arrivals must reuse their case');};
  waiting.context.API.clinicCheckIn = async id => {assert.equal(id, 2); waitingVisit = {...waitingVisit, status: 'checked_in', queue_number: 5}; return {appointment: waitingVisit};};
  waiting.context.API.startClinicCase = async () => {throw new Error('Check-in must stop at checked_in');};
  assert.equal(waiting.state.activeCount, 0);
  assert.equal(waiting.state.waitingCases.length, 1);
  await waiting.state.checkInPatient();
  assert.equal(waiting.state.activeCount, 1);
  assert.equal(waiting.state.expectedCount, 0);
  assert.equal(waiting.state.currentClinicVisit.queue_number, 5);

  const checkIn = setup();
  const arrival = {id: 4, status: 'waiting_to_arrive', pet: {id: 7, name: 'Mochi'}};
  checkIn.state.arrivals = [arrival];
  checkIn.state.tab = 'expected';
  checkIn.context.API.clinicCheckIn = async () => ({appointment: {...arrival, status: 'checked_in', queue_number: 1}});
  const refresh = deferred();
  checkIn.context.API.getActiveClinicCases = () => refresh.promise;
  const checkingIn = checkIn.state.checkInCase(arrival);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(checkIn.state.expectedCount, 0, 'Successful check-in removes the arrival before the list request finishes');
  assert.equal(checkIn.state.activeCount, 1, 'Active count updates immediately from the successful check-in response');
  assert.equal(checkIn.state.caseRows.length, 0);
  refresh.resolve({cases: [{...arrival, status: 'checked_in', queue_number: 1}], arrivals: []});
  await checkingIn;
  assert.equal(checkIn.state.activeCount, 1, 'Refreshing does not duplicate the checked-in patient');

  const failedCheckIn = setup();
  failedCheckIn.state.arrivals = [arrival];
  failedCheckIn.context.API.clinicCheckIn = async () => {throw new Error('Clinic is not accepting check-ins');};
  await failedCheckIn.state.checkInCase(arrival);
  assert.equal(failedCheckIn.state.expectedCount, 1, 'Rejected check-in leaves the pre-registration in Expected');
  assert.equal(failedCheckIn.state.activeCount, 0);

  const failedRefresh = setup();
  failedRefresh.state.arrivals = [arrival];
  failedRefresh.context.API.clinicCheckIn = async () => ({appointment: {...arrival, status: 'checked_in', queue_number: 1}});
  failedRefresh.context.API.getActiveClinicCases = async () => {throw new Error('Temporary connection failure');};
  await failedRefresh.state.checkInCase(arrival);
  assert.equal(failedRefresh.state.expectedCount, 0);
  assert.equal(failedRefresh.state.activeCount, 1, 'A failed refresh must retain the confirmed check-in and its count');

  const pendingRace = setup();
  await pendingRace.state.openProfile({pet: {id: 7}, owner}, pendingRace.trigger);
  pendingRace.context.API.getActiveClinicCases = async () => ({cases: [], arrivals: [arrival]});
  pendingRace.context.API.createClinicCase = async () => {throw {code: 'pre_registration_exists', data: {arrival}};};
  pendingRace.context.API.clinicCheckIn = async () => {throw new Error('A new pre-registration conflict must require its own Check In action');};
  await pendingRace.state.checkInPatient();
  assert.equal(pendingRace.state.currentClinicVisit.id, 4);
  assert.equal(pendingRace.state.profileActionLabel, 'Check In Patient');
  assert.equal(pendingRace.state.profileActionError, '');

  const race = setup();
  await race.state.openProfile({pet: {id: 7}, owner}, race.trigger);
  race.context.API.getActiveClinicCases = async () => ({cases: []});
  race.context.API.createClinicCase = async () => {throw {code: 'active_case_exists', data: {case: {id: 3, status: 'in_consultation', pet: {id: 7}}}};};
  race.context.API.clinicCheckIn = race.context.API.startClinicCase = async () => {throw new Error('An existing case conflict must not change status');};
  await race.state.checkInPatient();
  assert.equal(race.state.profileActionLabel, 'Open Case');
  assert.equal(race.state.currentClinicVisit.status, 'in_consultation');
  assert.equal(race.state.profile.open, true);

  const denied = setup();
  await denied.state.openProfile({pet: {id: 7}, owner}, denied.trigger);
  denied.state.$store.clinicAccess.permissions.intake = false;
  denied.context.API.createClinicCase = async () => {throw new Error('No intake permission');};
  await denied.state.checkInPatient();
  assert.equal(denied.state.currentClinicVisit, null);
  assert.equal(denied.state.profileActionLabel, '');
  denied.state.profileVisit = {id: 1, pet: {id: 7}, status: 'checked_in'};
  denied.state.$store.clinicAccess.permissions.clinical = false;
  assert.equal(denied.state.profileActionLabel, '', 'Reception uses Patient Records for read-only history');
  denied.state.$store.clinicAccess.permissions.access = false;
  await denied.state.runProfileAction();
  denied.state.openProfileCase();
  assert.equal(denied.events.some(event => event.type === 'clinic-open-modal'), false);
  assert.equal(denied.state.caseActionLabel({status: 'checked_in'}), '');
  denied.state.runCaseAction({id: 1, status: 'checked_in'});
  denied.state.runCaseAction({id: 1, status: 'waiting_to_arrive'});
  denied.state.runCaseAction({id: 1, status: 'for_payment'});
  assert.equal(denied.events.some(event => event.type === 'clinic-open-modal'), false, 'Denied row actions never fall back to opening a case');

  assert.equal(denied.state.casePatientLabel({status: 'waiting_to_arrive', queue_number: 8}), 'Patient');
  assert.equal(denied.state.casePatientLabel({status: 'checked_in', queue_number: 5, pet: {name: 'Max'}}), '#5 · Max');
  const markup = fs.readFileSync(path.join(__dirname, '../pages/admin/clinic.html'), 'utf8');
  assert.doesNotMatch(markup, /Current Clinic Visit|Open Active Case|caseOpened\(/);

  const filtering = setup();
  filtering.state.activeCases = ['waiting_to_arrive', 'checked_in', 'in_consultation', 'for_payment', 'completed'].map((status, id) => ({id, status}));
  filtering.state.activeCases.push({id: 6, status: 'in_consultation', confinement: {discharged_at: null}});
  filtering.state.arrivals = [{id: 7, status: 'waiting_to_arrive'}, {id: 8, status: 'cancelled'}, {id: 9, status: 'expired'}];
  assert.equal(filtering.state.activeCount, 2);
  assert.deepEqual(Array.from(filtering.state.visibleActiveCases, item => item.status), ['checked_in', 'in_consultation']);
  assert.equal(filtering.state.waitingCases.length, 1);
  assert.equal(filtering.state.caseRows.length, 2, 'Active contains only checked-in and consultation patients');
  assert.equal(filtering.state.activeCount, filtering.state.caseRows.length);
  filtering.state.tab = 'expected';
  assert.equal(filtering.state.expectedCount, 1);
  assert.deepEqual(Array.from(filtering.state.caseRows, item => item.id), [7]);
  filtering.state.tab = 'for-payment';
  assert.deepEqual(Array.from(filtering.state.caseRows, item => item.status), ['for_payment']);
  assert.equal(filtering.state.forPaymentCount, filtering.state.caseRows.length);

  const gummy = setup();
  let current = {id: 56, appointment_reference: 'CL-20261016-001', appointment_date: '2026-10-16',
    started_on: '2026-10-16', status: 'in_consultation', pet: {id: 7, name: 'Gummy'}};
  let creates = 0;
  gummy.context.API.getCurrentPetClinicCase = async () => ({case: current});
  gummy.context.API.createClinicCase = async () => {creates++; throw Error('An ongoing case must never be replaced');};
  gummy.context.API.getActiveClinicCases = async () => ({cases: [current], today: '2026-10-17', confined_count: 0});
  await gummy.state.openProfile({pet: {id: 7}, owner}, gummy.trigger);
  assert.equal(gummy.state.profileActionLabel, 'Open Case', 'Gummy resumes the October 16 consultation on October 17');
  await gummy.state.loadActiveCases();
  assert.equal(gummy.state.activeCount, 1);
  assert.equal(gummy.state.caseStartedLabel(current), 'Checked in Oct 16');
  assert.equal(gummy.state.caseActionLabel(current), 'Open Case');
  gummy.state.closeProfile();
  gummy.state.activeCases = [];
  await gummy.state.openProfile({pet: {id: 7}, owner}, gummy.trigger);
  assert.equal(gummy.state.currentClinicVisit.id, 56, 'Reopening resolves the authoritative case independently of any list');
  assert.equal(gummy.state.profileActionLabel, 'Open Case');
  await gummy.state.checkInPatient();
  assert.equal(creates, 0);

  current = {...current, confinement: {admitted_at: '2026-10-16T10:00:00+08:00', discharged_at: null}};
  gummy.context.API.getActiveClinicCases = async () => ({cases: [], today: '2026-10-17', confined_count: 1});
  gummy.context.API.getClinicConfinedCases = async () => ({data: [current], total: 1, current_page: 1, last_page: 1});
  gummy.state.closeProfile();
  await gummy.state.openProfile({pet: {id: 7}, owner}, gummy.trigger);
  await gummy.state.loadActiveCases();
  await gummy.state.loadConfinedCases();
  assert.equal(gummy.state.activeCount, 0);
  assert.equal(gummy.state.confinedCount, gummy.state.confinedCases.length);
  assert.equal(gummy.state.profileActionLabel, 'Open Case', 'Confinement never makes a pet check-in eligible');
  await gummy.state.runProfileAction();
  assert.equal(gummy.events.at(-1).detail.appt.id, 56);

  current = null;
  await gummy.state.openProfile({pet: {id: 7}, owner}, gummy.trigger);
  assert.equal(gummy.state.profileActionLabel, 'Check In Patient', 'A closed case permits a legitimate later visit');

  const verifying = setup(), verification = deferred();
  verifying.context.API.getCurrentPetClinicCase = () => verification.promise;
  const profileOpening = verifying.state.openProfile({pet: {id: 7}, owner}, verifying.trigger);
  assert.equal(verifying.state.profileActionLabel, '', 'No check-in action appears before the current-case lookup completes');
  verifying.state.closeProfile();
  verification.resolve({case: {id: 56, pet: {id: 7}, status: 'in_consultation'}});
  await profileOpening;
  assert.equal(verifying.state.profileVisitLoaded, false, 'A response from a closed profile cannot populate case state');

  const unavailable = setup();
  unavailable.context.API.getCurrentPetClinicCase = async () => {throw Error('Case lookup unavailable');};
  await unavailable.state.openProfile({pet: {id: 7}, owner}, unavailable.trigger);
  assert.equal(unavailable.state.profileActionLabel, '');
  assert.equal(unavailable.state.profile.error, 'Case lookup unavailable');

  const double = setup(), creation = deferred();
  let doubleCreates = 0, created = null;
  double.context.API.getCurrentPetClinicCase = async () => ({case: created});
  double.context.API.getActiveClinicCases = async () => ({cases: created ? [created] : []});
  double.context.API.createClinicCase = async () => {doubleCreates++; return creation.promise;};
  await double.state.openProfile({pet: {id: 7}, owner}, double.trigger);
  const firstCheckIn = double.state.checkInPatient();
  await double.state.checkInPatient();
  await Promise.resolve();
  created = {id: 91, status: 'checked_in', pet: {id: 7}};
  creation.resolve({case: created});
  await firstCheckIn;
  await double.state.checkInPatient();
  assert.equal(doubleCreates, 1, 'Double clicks and repeat check-in create at most one case');

  const rollover = setup();
  const carried = ['checked_in', 'in_consultation', 'for_payment'].map((status, id) => ({id,
    status, appointment_date: '2026-10-16', started_on: '2026-10-16'}));
  let today = '2026-10-16';
  rollover.context.API.getActiveClinicCases = async () => ({cases: carried, today});
  await rollover.state.loadActiveCases();
  assert.equal(rollover.state.caseStartedLabel(carried[1]), '');
  today = '2026-10-17';
  await rollover.state.loadActiveCases();
  assert.equal(rollover.state.activeCount, 2);
  assert.equal(rollover.state.forPaymentCount, 1);
  assert.equal(rollover.state.caseStartedLabel(carried[1]), 'Checked in Oct 16');
  assert.equal(rollover.state.caseStartedLabel(carried[2]), '');
  assert.equal(rollover.state.caseStartedLabel({...carried[0], started_on: '2025-12-31'}), 'Checked in Dec 31, 2025');

  console.log('Patient Profile browsing, authoritative ongoing cases, carry-over dates, confinement, double check-in, counters and rollover regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
