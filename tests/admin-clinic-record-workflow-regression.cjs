const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const deferred = () => {let resolve; const promise = new Promise(done => {resolve = done;}); return {promise, resolve};};
const settle = () => new Promise(resolve => setImmediate(resolve));
const events = [], writes = [], listeners = new Map();
const context = {console, setTimeout, clearTimeout, crypto: webcrypto,
  document: {addEventListener() {}, body: {style: {overflow: 'auto'}}},
  window: {addEventListener(type, listener) {listeners.set(type, [...(listeners.get(type) || []), listener]);},
    dispatchEvent(event) {events.push(event); for (const listener of listeners.get(event.type) || []) listener(event);}},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  API: {getUserRole: () => 'staff'},
};
vm.createContext(context);
for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js', 'admin-vaccinations.js'])
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), context);
const access = {permissions: {access: true, clinical: true, intake: true}};
const attach = state => {
  state.$store = {clinicAccess: structuredClone(access), clinicFeedback: {confirm: async () => true, notify() {}}};
  state.$nextTick = callback => callback(); state.$refs = {};
  return state;
};
const appt = id => ({id, pet: {id: 7, name: 'Mochi'}, status: 'in_consultation', vitals: {weight_kg: 12}});
const product = {item_id: 5, item_name: 'Rabies', available_quantity: 10, unit: 'vial'};
(async () => {
  const intakeRecord = attach(context.adminClinicModal());
  const intakeTreatment = attach(context.adminClinicVaccinations());
  for (const status of ['waiting_to_arrive', 'checked_in', 'in_consultation']) {
    intakeRecord.currentAppt = {id: 90, status};
    intakeTreatment.appointment = {id: 90, status};
    assert.equal(intakeRecord.canEditClinical, status === 'in_consultation');
    assert.equal(intakeTreatment.caseOngoing(), status === 'in_consultation');
  }
  const state = attach(context.adminClinicVaccinations()); state.inline = true; state.init();
  context.API.getAdminVaccinationOptions = async () => ({vaccines: [product]});
  const reads = [], older = deferred();
  context.API.getAdminPetVaccinations = async (pet, id) => {
    reads.push([pet, id]);
    return id === 90 ? older.promise : {vaccinations: [
      {id: 1, clinic_appointment_id: 90}, {id: 2, clinic_appointment_id: 91}, {id: 3, clinic_appointment_id: null},
    ]};
  };
  state.openForAppointment(appt(90));
  assert.equal(state.formModal.open, true, 'Selecting Treatment immediately opens entry');
  assert.equal(state.formModal.clinicAppointmentId, 90);
  state.selectVaccine(product);
  assert.equal(writes.length, 0, 'Selection has no stock or billing side effects');
  state.openForAppointment(appt(91)); await settle();
  assert.equal(state.formModal.clinicAppointmentId, 91, 'Switching visits for the same pet resets the case association');
  assert.equal(state.selectedProduct, null);
  assert.deepEqual(Array.from(state.records, row => row.id), [2]);
  older.resolve({vaccinations: [{id: 9, clinic_appointment_id: 90}]}); await settle();
  assert.deepEqual(Array.from(state.records, row => row.id), [2], 'An old visit response cannot replace the current visit');
  assert.deepEqual(reads, [[7,90],[7,91]]);

  let savedRecords = [], failCreate = true;
  context.API.getAdminPetVaccinations = async () => ({vaccinations: savedRecords});
  context.API.createAdminPetVaccination = async (pet, payload) => {
    writes.push(['create',pet,payload]);
    if (failCreate) throw new Error('Lost response');
    const vaccination = {id:10, state:'draft', ...payload}; savedRecords = [vaccination]; return {vaccination};
  };
  context.API.publishAdminPetVaccination = async () => {throw new Error('Individual treatments must not be published');};
  context.API.updateAdminPetVaccination = async (pet,id,payload) => {
    writes.push(['update',pet,id,payload]); const vaccination = {id,state:'draft',...payload}; savedRecords = [vaccination]; return {vaccination};
  };
  state.selectVaccine(product);state.form.notes = 'Current visit';
  state.form.dose_amount = '0.5'; state.form.dose_unit = 'mL';
  assert.equal(state.hasUnfinishedTreatment(),true);
  await state.saveTreatment();
  assert.equal(state.formModal.open,true);
  assert.equal(state.formModal.mode,'create');
  const retryKey = writes[0][2].request_key;
  failCreate = false;await state.saveTreatment();
  assert.deepEqual(writes.map(call => call[0]), ['create','create']);
  assert.equal(writes[1][2].request_key,retryKey,'Lost responses retry the same vaccine identity');
  assert.equal(writes[0][2].clinic_appointment_id,91);
  assert.equal(state.records.length,2,'Adding preserves existing treatments');
  assert.equal(state.appointment.status,'in_consultation');
  assert.equal(state.formModal.mode,'create');assert.equal(state.form.notes,'');assert.equal(state.selectedProduct,null);
  assert.equal(state.hasUnfinishedTreatment(),false);
  state.openEditForm(state.records[0]);state.form.notes = 'Edited';await state.saveTreatment();
  assert.equal(writes.at(-1)[0],'update');assert.equal(state.records[0].notes,'Edited');
  assert.equal(state.treatmentDetails(state.records[0]).some(field=>field.label==='Status'),false);
  assert.equal(state.doseLabel({}),'—');

  const correction = {...state.records[0], state: 'draft', dose_amount: '2', dose_unit: '', clinic_appointment_id: 91};
  state.records[0] = correction;
  context.window.dispatchEvent(new context.CustomEvent('clinic-treatment-focus', {detail: {
    id: 91, message: 'Dose unit required.', errors: {[`treatments.${correction.id}.dose_unit`]: ['Dose unit required.']},
  }}));
  assert.equal(state.formModal.recordId, correction.id, 'Finalization opens the exact saved treatment that failed');
  assert.equal(state.formErrors.dose_unit, 'Dose unit required.');
  state.form.dose_unit = 'mL'; state.validateDoseFields('input');
  assert.equal(state.formErrors.dose_unit, undefined);
  assert.equal(state.formErrorSummary, '', 'Correcting a finalization error immediately clears its summary');
  state.resetInlineForm();

  // The case footer blocks unfinished entries, confirms once, and submits the whole medical record.
  vm.runInContext(`clinicPetWeightToolsReady = Promise.resolve({getWeightValueValidationMessage: () => ''});`,context);
  const modal = attach(context.adminClinicModal());modal.$el = {querySelector: () => null};modal.apptId=91;
  modal.init();
  modal.currentAppt=appt(91);modal.open=true;
  modal.treatmentValidationError = true; modal.modalError = 'Dose unit required.';
  state.formErrors = {dose_unit: 'Dose unit required.'};
  state.form.dose_amount = '0.5'; state.form.dose_unit = 'mL'; state.validateDoseFields('input');
  assert.equal(modal.modalError, '', 'Correcting Treatment clears the related case footer summary');
  state.resetInlineForm();
  Object.assign(modal.form,{primary_veterinarian_id:1,weight_kg:'12',temperature_c:38.5,heart_rate_bpm:80,respiratory_rate_bpm:20});
  const confirmations = [], caseSaves=[];
  modal.$store.clinicFeedback.confirm = async dialog => {confirmations.push(dialog);return false;};
  context.API.clinicSaveRecord = async (id,payload) => {caseSaves.push([id,payload]);};
  modal.form.weight_kg='13';modal.selectSection('vaccinations');
  assert.equal(state.appointment.weight_kg,'13','Treatment pricing uses the current Medical Record weight');
  modal.form.weight_kg='12';
  state.form.notes='Unadded';await modal.saveRecord(true);
  assert.equal(caseSaves.length,0);assert.equal(confirmations.length,0);
  assert.equal(modal.activeSection,'vaccinations');assert.match(state.formErrorSummary,/Add or discard/);
  state.resetInlineForm();await modal.saveRecord(true);
  assert.equal(caseSaves.length,0,'Cancel confirmation never finalizes');
  assert.equal(confirmations[0].title,'Send case to payment?');
  modal.$store.clinicFeedback.confirm = async () => true;await modal.saveRecord(true);
  assert.equal(caseSaves[0][1].finish_case,true);assert.equal(caseSaves[0][1].weight_kg,'12');
  assert.equal(modal.saving,false);
  state.openForAppointment(appt(91));await settle();modal.open=true;modal.form.weight_kg='';
  await modal.saveRecord(true);assert.equal(caseSaves.length,1);assert.equal(modal.activeSection,'medical');
  modal.form.weight_kg='12';state.form.notes='Unfinished draft';
  await modal.saveRecord();assert.equal(caseSaves.at(-1)[1].finish_case,false,'Draft saves never transition');
  assert.equal(writes.at(-1)[2].notes,'Unfinished draft','Save as Draft retains the current treatment entry');

  state.openForAppointment(appt(91));await settle();
  let confirmRemove=false;
  state.$store.clinicFeedback.confirm=async dialog=>{assert.equal(dialog.title,'Remove treatment?');return confirmRemove;};
  context.API.deleteAdminPetVaccination=async (pet,id)=>{writes.push(['delete',pet,id]);};
  await state.removeTreatment(state.records[0]);assert.notEqual(writes.at(-1)[0],'delete');
  confirmRemove=true;await state.removeTreatment(state.records[0]);assert.equal(writes.at(-1)[0],'delete');
  assert.equal(state.records.length,0);
  state.recordKind='historical';state.changeSource();state.form.vaccine_name='External vaccine';state.form.administered_date='2020-01-01';
  await state.saveTreatment();assert.equal(writes.at(-1)[2].inventory_item_id,null);
  const count=writes.length;state.$store.clinicAccess.permissions.clinical=false;await state.saveTreatment();
  assert.equal(writes.length,count,'Read-only roles cannot add treatments');
  state.$store.clinicAccess.permissions.clinical=true;state.appointment.paid=true;
  await state.saveTreatment();assert.equal(writes.length,count,'Paid cases cannot receive treatment entries');

  assert.equal(context.clinicTreatmentSummary({dose_amount:'0.500',dose_unit:'mL',route:'intramuscular'}),'0.5 mL \u00b7 IM');
  assert.equal(context.clinicTreatmentSummary({route:'oral'}),'Oral');
  const fields = Object.fromEntries(context.clinicTreatmentDetails({treatment_type:'medicine',manufacturer:'Maker',batch_number:'LOT',product_expiry_date:'2027-01-01',notes:'Saved note',clinic_appointment_reference:'CL-91',inventory_quantity:'1.00',inventory_unit:'bottle'}).map(field => [field.label,field.value]));
  assert.equal(fields.Manufacturer,'Maker');assert.equal(fields['Batch / Lot'],'LOT');assert.equal(fields.Notes,'Saved note');assert.equal(fields.Case,'CL-91');assert.equal(fields.Quantity,'1 bottle');

  const profile = attach(context.adminClinicPage());
  const owner = {id:8,recordType:'registered',fullName:'Ana'};
  const historyReads=[];
  context.API.adminGetPetProfile = async (pet,tab,page) => {historyReads.push([pet,tab,page]);return tab ? {data:[{id:1,clinic_appointment_id:80,notes:'Saved',service_snapshot:{name:'Saved service'}}],current_page:page,last_page:2}:{medical_records:[]};};
  context.API.getCustomerDetails = async () => ({customer:{...owner,pets:[{id:7,name:'Mochi'}]}});
  context.API.getAdminPetVaccinations = async () => ({vaccinations:[{id:11,treatment_type:'medicine',notes:'Saved medicine'}]});
  await profile.openProfile({pet:{id:7},owner});assert.equal(historyReads.length,1);
  profile.selectHistory('services');await settle();assert.equal(historyReads.length,2);
  profile.selectHistory('medical');profile.selectHistory('services');await settle();assert.equal(historyReads.length,2,'Revisiting a loaded history tab does not refetch');
  await profile.loadHistory('services',2);assert.equal(profile.histories.services.rows.length,2);
  profile.selectHistory('treatment');await settle();assert.equal(profile.histories.treatment.rows[0].treatment_type,'medicine');
  await profile.viewDetail(profile.histories.treatment.rows[0],null,'treatment');assert.equal(profile.treatmentDetails(profile.detail.record).find(field => field.label==='Notes').value,'Saved medicine');profile.backToProfile();assert.equal(profile.historyTab,'treatment');
  const delayed = deferred();context.API.adminGetPetProfile = () => delayed.promise;
  profile.selectHistory('confinement');profile.closeProfile();
  context.API.adminGetPetProfile = async () => ({medical_records:[]});await profile.openProfile({pet:{id:7},owner});
  delayed.resolve({data:[{id:100}],current_page:1,last_page:1});await settle();
  assert.equal(profile.histories.confinement.rows.length,0,'A closed profile response cannot populate a new profile');
  const monitoring = deferred();context.API.getClinicConfinementObservations = () => monitoring.promise;
  context.API.adminGetPetCaseDetails = async () => ({case:{id:80},confinement:{id:1}});
  await profile.viewDetail({id:1,clinic_appointment_id:80},null,'confinement');profile.backToProfile();
  monitoring.resolve({data:[{id:1}],current_page:1,last_page:1});await settle();assert.equal(profile.detail.record,null,'Late monitoring never reopens a closed detail');
  console.log('Patient Record separation, case isolation, working visits, shared finalization, reset, retry, permissions and lazy history checks passed');
})().catch(error=>{console.error(error);process.exitCode=1;});
