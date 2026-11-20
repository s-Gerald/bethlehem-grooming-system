const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const calls = [];
let response;
const closeButton = {focus: () => calls.push('close-focus')};
const trigger = {focus: () => calls.push('trigger-focus')};
const context = {Date, Intl, URLSearchParams, Set,
  window: {}, document: {body: {style: {overflow: 'auto'}}},
  API: {adminGetPetCaseDetails: async (...args) => {calls.push(args); return response;}},
  viewClinicAttachment: async (...args) => calls.push(args),
};
vm.createContext(context);
vm.runInContext(fs.readFileSync('scripts/components/clinic-record-presentation.js', 'utf8'), context);
vm.runInContext(fs.readFileSync('scripts/components/admin-archive.js', 'utf8'), context);
const ui = context.adminArchive();
ui.$nextTick = callback => callback();
ui.$refs = {clinicVisitClose: closeButton, clinicVisitBody: {scrollTop: 100}};
const visit = {id: 9, pet: {id: 2, name: 'Charlie', weight: 18}, status: 'completed', total_amount: '1950.00',
  chief_complaint: 'Skin irritation', treatment_count: 2, service_count: 1,
  vitals: {weight_kg: '5.30', temperature_c: '40.0', heart_rate_bpm: 80},
  record: {chief_complaint: 'Persistent itching', working_diagnosis: 'Allergic dermatitis',
    attachments: [{id: 1, file_name: 'skin.png'}, {id: 2, file_name: 'chest.jpg', clinic_case_service_id: 5},
      {id: 3, file_name: 'abdomen.jpeg', clinic_case_service_id: 99}]}};
const original = JSON.stringify(visit);
response = {treatments: [{id: 1, state: 'published', record_source: 'inventory', treatment_type: 'medicine',
    inventory_quantity: '5.00', inventory_unit: 'vial', dose_amount: 20, dose_unit: 'mL'}, {id: 2, state: 'draft'}, {id: 3, state: 'voided', voided_at: '2026-10-15'}],
  services: [{id: 5, status: 'performed', service_snapshot: {name: 'Chest X-Ray'}, clinic_service: {name: 'Renamed service'},
    quantity: 2, applied_price: '350'}, {id: 6, status: 'cancelled'}]};

(async () => {
  ui.viewClinicRecord(visit, trigger);
  await new Promise(setImmediate);
  assert.equal(context.document.body.style.overflow, 'hidden');
  assert.equal(ui.$refs.clinicVisitBody.scrollTop, 0);
  assert.equal(ui.clinicReason(visit), 'Persistent itching');
  assert.equal(ui.clinicDiagnosis(visit), 'Allergic dermatitis');
  assert.equal(ui.clinicCareSummary(visit), '2 treatments · 1 service');
  assert.equal(ui.clinicVitals(ui.detailsClinicRecord).map(field => field.label).join(','), 'Weight,Temperature,Heart rate');
  assert.equal(ui.clinicVitals(ui.detailsClinicRecord)[0].value, '5.30 kg');
  assert.equal(ui.clinicCare.treatments.length, 1, 'Draft and voided care must not read as provided');
  const inventoryTreatment = ui.clinicCare.treatments[0];
  for (const treatment_type of ['medicine', 'vaccine']) {
    const fields = ui.clinicTreatmentFields({...inventoryTreatment, treatment_type});
    assert.equal(fields.find(field => field.label === 'Quantity')?.value, '5 vial');
    assert.equal(fields.find(field => field.label === 'Dose')?.value, '20 mL');
    const patientFields = context.clinicTreatmentDetails({...inventoryTreatment, treatment_type});
    for (const label of ['Quantity', 'Dose']) assert.equal(fields.find(field => field.label === label).value, patientFields.find(field => field.label === label).value, `History and Patient Records agree on ${label}`);
    assert.equal(fields.findIndex(field => field.label === 'Quantity') + 1, fields.findIndex(field => field.label === 'Dose'), 'Quantity sits next to Dose');
  }
  for (const [inventory_quantity, inventory_unit, expected] of [[2, 'bottle', '2 bottle'], [3, 'tablet', '3 tablet'], [5, null, '5']]) {
    assert.equal(ui.clinicTreatmentFields({...inventoryTreatment, inventory_quantity, inventory_unit}).find(field => field.label === 'Quantity').value, expected);
  }
  for (const record_source of ['manual', 'historical']) {
    for (const inventory_quantity of [undefined, null, '']) {
      const fields = ui.clinicTreatmentFields({record_source, inventory_quantity, inventory_unit: 'vial', quantity: 1, dose_amount: 20, dose_unit: 'mL'});
      assert.equal(fields.some(field => field.label === 'Quantity'), false, 'No quantity is inferred from billing or clinical dose');
      assert.equal(fields.find(field => field.label === 'Dose').value, '20 mL');
    }
  }
  assert.equal(ui.clinicCare.services.length, 1);
  assert.equal(ui.clinicServiceName(ui.clinicCare.services[0]), 'Chest X-Ray', 'Use the encounter snapshot');
  assert.match(ui.clinicServicePrice(ui.clinicCare.services[0]), /700\.00/);
  assert.equal(ui.clinicMedicalAttachments(ui.detailsClinicRecord, ui.clinicCare).map(file => file.id).join(','), '1,3', 'Unmatched attachments remain accessible');
  assert.equal(ui.clinicServiceAttachments({id: 5}, visit).map(file => file.id).join(','), '2');
  await ui.openClinicAttachment(visit.record.attachments[1]);
  assert.equal(calls.at(-1)[0], 9, 'Attachment downloads must use the selected encounter');
  assert.equal(calls.at(-1)[1].id, 2);
  assert.equal(ui.clinicHasPayment(visit), true);
  assert.equal(ui.clinicTransactionUrl({...visit, paid: true, appointment_reference: 'CL-20261015-009'}), '/admin/transactions?clinic=9&reference=CL-20261015-009');
  assert.equal(ui.clinicTransactionUrl({...visit, paid: false}), '');
  assert.equal(ui.clinicTransactionUrl({...visit, paid: true, status: 'for_payment'}), '');
  assert.match(ui.clinicMoney(1950), /1,950\.00/);
  assert.equal(JSON.stringify(visit), original);
  ui.closeClinicRecord();
  assert.equal(context.document.body.style.overflow, 'auto');
  assert.equal(calls.at(-1), 'trigger-focus');

  ui.viewClinicRecord({...visit, pet: null, pet_id: 2}, trigger);
  await new Promise(setImmediate);
  assert.deepEqual(calls.at(-1), [2, 9], 'Legacy encounters can load care using the recorded pet ID without a profile snapshot.');
  assert.equal(ui.clinicCare.treatments.length, 1);
  ui.closeClinicRecord();

  const cancelled = {id: 10, pet: {id: 2, weight: 18}, status: 'cancelled', total_amount: 0,
    chief_complaint: 'Limping / injury', record: null, vitals: null};
  response = {treatments: [], services: []};
  ui.viewClinicRecord(cancelled, trigger);
  await new Promise(setImmediate);
  assert.equal(ui.clinicSummaryFields(ui.detailsClinicRecord).length, 1);
  assert.equal(ui.clinicSummaryFields(ui.detailsClinicRecord)[0].value, 'Limping / injury');
  assert.equal(ui.clinicVitals(ui.detailsClinicRecord).length, 0, 'Never substitute the current profile weight');
  assert.equal(ui.clinicHasCare(ui.detailsClinicRecord, ui.clinicCare), false);
  assert.equal(ui.clinicHasPayment(cancelled), false, 'Default zero/unpaid values are not payment history');
  assert.equal(ui.clinicCareSummary(cancelled), '');
  assert.equal(ui.clinicPrescriptions(ui.detailsClinicRecord).length, 0);
  assert.equal(ui.clinicDiagnostics(ui.detailsClinicRecord).length, 0);
  ui.closeClinicRecord();

  let resolve;
  response = new Promise(done => {resolve = done;});
  ui.viewClinicRecord(visit, trigger);
  ui.closeClinicRecord();
  resolve({services: [{id: 5, status: 'performed'}]});
  await new Promise(setImmediate);
  assert.equal(ui.clinicCare, null, 'Late responses cannot reopen or populate a closed encounter');

  context.API.adminGetPetCaseDetails = async () => {throw new Error('Connection interrupted');};
  ui.viewClinicRecord(visit, trigger);
  await new Promise(setImmediate);
  assert.equal(ui.clinicCareError, 'Connection interrupted');
  assert.equal(ui.clinicMedicalAttachments(ui.detailsClinicRecord, ui.clinicCare).length, 3, 'Attachments survive care loading failures');
  context.API.adminGetPetCaseDetails = async () => ({services: [], treatments: []});
  await ui.loadClinicCare();
  assert.equal(ui.clinicCareError, '');
  assert.equal(ui.clinicCareLoading, false);
  ui.closeClinicRecord();
  const sessionLookups = [];
  const consent = {id: 1, agreement_snapshot: {operating_hours: '8:00 AM – 5:00 PM'}};
  const session = {id: 30, patients: [visit, cancelled]};
  const groupedVisit = {...visit, clinic_visit_group_id: 30, session};
  const emptyCare = {treatments: [], services: []};
  const patients = [visit, cancelled].map(visit => ({visit, care: emptyCare}));
  context.API.getArchivedClinicSessionDetails = async id => {
    sessionLookups.push(id);
    return {session: {id: 30}, patients, consent};
  };
  ui.viewClinicRecord(groupedVisit, trigger);
  await new Promise(setImmediate);
  assert.equal(ui.clinicSignedConsent, consent);
  ui.viewClinicPatient({id: 10});
  assert.equal(ui.detailsClinicRecord.id, 10);
  assert.equal(ui.clinicSignedConsent, consent);
  assert.deepEqual(sessionLookups, [visit.id], 'A patient change reuses the loaded session and signed agreement.');
  ui.closeClinicRecord();
  ui.viewClinicRecord(cancelled, trigger);
  assert.equal(ui.clinicSignedConsent, null);
  assert.deepEqual(sessionLookups, [visit.id], 'Legacy visits have no group request.');
  ui.closeClinicRecord();

  context.API.getArchivedClinicSessionDetails = async () => { throw new Error('offline'); };
  ui.viewClinicRecord(groupedVisit, trigger);
  await new Promise(setImmediate);
  assert.equal(ui.clinicCareError, 'offline');
  context.API.getArchivedClinicSessionDetails = async () => ({session: {id: 30}, patients, consent: null});
  await ui.loadClinicCare();
  assert.equal(ui.clinicCareError, '');
  assert.equal(ui.clinicSignedConsent, null, 'A group with no signed record also shows the unavailable state.');
  ui.closeClinicRecord();
  console.log('Clinic history regression checks passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
