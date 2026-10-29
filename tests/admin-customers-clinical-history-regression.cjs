const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return {promise, resolve}; };
const calls = [], focus = [];
let now = Date.now();
const ClockDate = class extends Date { static now() { return now; } };
const context = vm.createContext({console, Date: ClockDate, URL, setTimeout, clearTimeout,
  document: {activeElement: null}, window: {}, API: {
    getAdminPetProfile: async (...args) => { calls.push(['profile', ...args]); return {grooming_records: []}; },
    adminGetPetProfile: async (...args) => { calls.push(['history', ...args]); return {data: [{appointment_id: 9, appointment_reference: 'CL-9'}], current_page: 1, last_page: 2}; },
    adminGetPetCaseDetails: async (...args) => { calls.push(['case', ...args]); return {case: {id: 9, appointment_reference: 'CL-9', appointment_date: '2026-10-01', status: 'completed'}, medical_record: {diagnosis: 'Sprain'}, treatments: [{id: 1, treatment_type: 'medicine'}], services: [{id: 2}], confinement: {id: 3}}; },
    getClinicConfinementObservations: async (...args) => { calls.push(['monitoring', ...args]); return {data: [{id: args[1], notes: 'Resting'}], current_page: args[1], last_page: 2}; },
  }});
for (const name of ['clinic-record-presentation.js', 'admin-customers.js']) {
  const file = 'scripts/components/' + name;
  if (fs.existsSync(file)) vm.runInContext(fs.readFileSync(file, 'utf8'), context);
}
const ui = context.adminCustomers();
ui.resetPetHistory();
ui.$nextTick = fn => fn(); ui.$refs = {petBody: {scrollTop: 250}, petHistoryBack: {focus: () => focus.push('back')}};
ui.petModal.open = true; ui.petModal.pet = {id: 7};
(async () => {
  await ui.loadPetProfile();
  assert.deepEqual(calls[0], ['profile', 7, 'grooming'], 'Opening a pet avoids fetching full clinical histories');
  assert.equal(calls.length, 1);
  await ui.selectPetProfileTab('medical');
  assert.deepEqual(calls.at(-1), ['history', 7, 'clinical', 1, 'history']);
  await ui.selectPetProfileTab('medical');
  assert.equal(calls.length, 2, 'The loaded list is reused');
  const trigger = {focus: opts => focus.push(opts.preventScroll)};
  await ui.viewPetHistory(ui.petHistories.medical.rows[0], trigger);
  assert.deepEqual(calls.at(-1), ['case', 7, 9, 'history']);
  assert.equal(ui.detail.case.appointment_reference, 'CL-9');
  assert.equal(ui.detail.treatments[0].treatment_type, 'medicine');
  assert.equal(ui.detail.services.length, 1);
  assert.equal(ui.detail.confinement, null, 'Medical details do not render or load confinement monitoring');
  assert.equal(calls.filter(call => call[0] === 'monitoring').length, 0);
  ui.backToPetHistory(); assert.equal(ui.$refs.petBody.scrollTop, 250); assert.equal(focus.at(-1), true);
  await ui.viewPetHistory({appointment_id: 9}, trigger);
  assert.equal(calls.filter(call => call[0] === 'case').length, 1, 'Details reuse a bounded in-memory request');
  ui.backToPetHistory();
  await ui.viewPetHistory({id: 12, record_type: 'historical', treatment: {id: 12, vaccine_name: 'External treatment', administered_date: '2025-10-01'}}, trigger);
  assert.equal(ui.detail.caseId, null); assert.equal(ui.detail.treatments[0].id, 12);
  assert.equal(calls.filter(call => call[0] === 'case').length, 1);
  ui.backToPetHistory();
  await ui.viewPetHistory({id: 3, clinic_appointment_id: 9, admitted_at: '2026-10-01T10:00:00', reason: 'Observation'}, trigger, 'confinement');
  assert.equal(ui.detail.confinement.id, 3); assert.equal(ui.detail.medical, null);
  await ui.loadHistoryObservations(2);
  assert.equal(ui.detail.observations.length, 2); assert.equal(ui.detail.page, 2);
  ui.backToPetHistory();
  context.API.adminGetPetProfile = async () => { throw new Error('offline'); };
  await ui.loadPetHistory('confinements'); assert.equal(ui.petHistories.confinements.error, 'offline');
  context.API.adminGetPetProfile = async () => ({data: [], current_page: 1, last_page: 1});
  await ui.loadPetHistory('confinements'); assert.equal(ui.petHistories.confinements.error, '');
  context.API.adminGetPetProfile = async (petId, kind, page) => ({data: [{id: page}], current_page: page, last_page: 2});
  await ui.loadPetHistory('confinements'); await ui.loadPetHistory('confinements', 2);
  assert.deepEqual(Array.from(ui.petHistories.confinements.rows, row => row.id), [1, 2]);
  context.API.adminGetPetProfile = async () => { throw new Error('refresh failed'); };
  await ui.loadPetHistory('confinements', 1);
  assert.equal(ui.petHistories.confinements.retryPage, 1, 'A failed refresh retries page one, not the next history page');
  assert.equal(ui.petHistories.confinements.rows.length, 2, 'Refresh errors retain the existing list');
  now += 15001;
  await ui.viewPetHistory({appointment_id: 9}, trigger);
  assert.equal(calls.filter(call => call[0] === 'case').length, 2, 'Selected details revalidate after the short cache lifetime');
  ui.backToPetHistory();
  for (const [medical, treatments, services] of [[true, false, false], [true, true, false], [true, false, true], [false, true, true], [false, true, false], [false, false, true], [true, true, true]]) {
    now += 15001;
    context.API.adminGetPetCaseDetails = async () => ({case: {id: 9}, medical_record: medical ? {diagnosis: 'Sprain'} : null, treatments: treatments ? [{id: 1, treatment_type: 'vaccine'}] : [], services: services ? [{id: 2}] : []});
    await ui.viewPetHistory({appointment_id: 9}, trigger);
    assert.equal(Boolean(ui.detail.medical), medical);
    assert.equal(Boolean(ui.detail.treatments.length), treatments);
    assert.equal(Boolean(ui.detail.services.length), services);
    ui.backToPetHistory();
  }
  const monitoring = deferred(); context.API.getClinicConfinementObservations = () => monitoring.promise;
  const oldMonitoring = ui.viewPetHistory({id: 8, clinic_appointment_id: 11}, trigger, 'confinement');
  ui.backToPetHistory();
  monitoring.resolve({data: [{id: 99}], current_page: 1, last_page: 1}); await oldMonitoring;
  assert.equal(ui.detail.record, null, 'Late monitoring cannot reopen a detail view after Back');
  let pendingCaseRequests = 0;
  const next = deferred(); context.API.adminGetPetCaseDetails = () => { pendingCaseRequests++; return next.promise; };
  const pending = ui.viewPetHistory({appointment_id: 10}, trigger);
  now += 15001;
  const samePendingCase = ui.viewPetHistory({appointment_id: 10}, trigger);
  assert.equal(pendingCaseRequests, 1, 'Slow in-flight case requests stay deduplicated beyond the cache lifetime');
  ui.resetPetHistory();
  next.resolve({case: {id: 10}, medical_record: {diagnosis: 'late'}}); await Promise.all([pending, samePendingCase]);
  assert.equal(ui.detail.record, null, 'Late responses cannot overwrite a newly opened context, including the same pet');
  const labels = ui.caseTreatmentFields({treatment_type: 'vaccine', vaccine_name: 'Rabies', state: 'published', published_at: '2026-10-01', dose_amount: 1, dose_unit: 'mL', administered_date: '2026-10-01'});
  assert.equal(labels.some(field => /publish/i.test(field.label + ' ' + field.value)), false);
  assert.equal(labels.some(field => field.label === 'Administration Date'), true);
  assert.equal(labels.some(field => field.label === 'Dose' && field.value === '1 mL'), true);
  const profile = deferred(); context.API.getAdminPetProfile = () => profile.promise;
  const staleProfile = ui.loadPetProfile(); ui.resetPetHistory();
  profile.resolve({grooming_records: [{booking_reference: 'stale'}]}); await staleProfile;
  assert.equal(ui.petModal.groomingRecords.length, 0, 'Reopening the same pet rejects its old profile response');
  const focusedTabs = [];
  ui.petModal.activeTab = 'overview';
  ui.petProfileTabKeydown({key: 'End', preventDefault() {}, currentTarget: {querySelectorAll: () => [0, 1, 2, 3].map(index => ({focus: () => focusedTabs.push(index)}))}});
  assert.equal(ui.petModal.activeTab, 'confinements'); assert.equal(focusedTabs.at(-1), 3);
  console.log('Customers clinical history: lazy loading, case sections, historical treatments, monitoring, retries, focus and stale responses passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
