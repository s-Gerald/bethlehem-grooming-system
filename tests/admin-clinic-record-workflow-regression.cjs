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
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js', 'admin-vaccinations.js'])
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), context);
const access = {permissions: {access: true, clinical: true, intake: true}};
const attach = state => {
  state.$store = {clinicAccess: structuredClone(access), clinicFeedback: {confirm: async () => true, notify() {}}};
  state.$nextTick = callback => callback(); state.$refs = {};
  return state;
};
const appt = id => ({id, pet: {id: 7, name: 'Mochi'}, status: 'in_consultation', vitals: {weight_kg: 12}});
const product = {is_active: true, category: "vaccine", item_id: 5, item_name: 'Rabies', available_quantity: 10, unit: 'vial', next_batch: {expiry_date: '2099-01-01'}};
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
  assert.equal(state.recordKind, 'manual', 'New treatments default to Manual treatment');
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
  state.recordKind = 'inventory'; state.changeSource(); state.selectVaccine(product);
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
    const vaccination = {id:10, state:'draft', administered_date: context.vaccinationToday(),
      product_expiry_date: payload.inventory_item_id ? product.next_batch.expiry_date : payload.product_expiry_date, ...payload};
    savedRecords = [vaccination]; return {vaccination};
  };
  context.API.publishAdminPetVaccination = async () => {throw new Error('Individual treatments must not be published');};
  context.API.updateAdminPetVaccination = async (pet,id,payload) => {
    writes.push(['update',pet,id,payload]);
    const vaccination = {...savedRecords.find(record => record.id === id), id,state:'draft',...payload};
    savedRecords = [vaccination]; return {vaccination};
  };
  state.recordKind = 'inventory'; state.changeSource(); state.selectVaccine(product);state.form.notes = 'Current visit';
  state.form.dose_amount = '0.5'; state.form.dose_unit = 'mL';
  assert.equal(await state.saveTreatment(), false);
  assert.equal(writes.length, 0);
  assert.match(state.formErrors.route, /Select an administration route/);
  state.form.route = 'subcutaneous';
  state.validateAdministrationFields('route');
  assert.equal(state.formErrors.route, undefined);
  assert.equal(state.siteRequired, true);
  assert.equal(await state.saveTreatment(), false);
  assert.match(state.formErrors.administration_site, /Enter the administration site/);
  state.form.route = 'intranasal';
  state.validateAdministrationFields('route');
  assert.equal(state.siteRequired, false);
  assert.equal(state.formErrors.administration_site, undefined);
  assert.equal(state.formErrorSummary, '');
  state.form.administration_site = 'Left side';
  state.form.route = 'intramuscular';
  state.validateAdministrationFields('route');
  assert.equal(state.siteRequired, true);
  assert.equal(state.form.administration_site, 'Left side');
  state.applyBackendValidation({errors: {medicines: ['The pet weight or medicine price has changed.']}}, 'Unable to save.');
  assert.match(state.formErrors.inventory_item_id, /pet weight or medicine price/, 'Price review errors point to the product selector');
  state.formErrors = {}; state.formErrorSummary = '';
  assert.equal(state.hasUnfinishedTreatment(),true);
  await state.saveTreatment();
  assert.equal(state.formModal.open,true);
  assert.equal(state.formModal.mode,'create');
  const retryKey = writes[0][2].request_key;
  failCreate = false;await state.saveTreatment();
  assert.deepEqual(writes.map(call => call[0]), ['create','create']);
  assert.equal(writes[1][2].request_key,retryKey,'Lost responses retry the same vaccine identity');
  assert.equal(writes[0][2].clinic_appointment_id,91);
  assert.equal(writes[0][2].complete_entry,true);
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
  assert.equal(writes.at(-1)[2].complete_entry,undefined);

  state.openForAppointment(appt(91)); await settle(); modal.open = true;
  state.records[0] = {...state.records[0], record_source: 'inventory', inventory_item_id: 5, product_expiry_date: '2099-01-01', dose_amount: '0.5', dose_unit: 'mL',
    route: 'intramuscular', administration_site: null};
  const finishCount = caseSaves.length;
  await modal.saveRecord(true);
  assert.equal(caseSaves.length, finishCount, 'An already loaded incomplete draft blocks payment before the API call');
  assert.equal(state.formModal.recordId, state.records[0].id);
  assert.match(state.formErrors.administration_site, /Enter the administration site/);
  state.form.administration_site = 'Right thigh';
  state.validateAdministrationFields('administration_site');
  assert.equal(modal.modalError, '');
  state.resetInlineForm();

  state.openForAppointment(appt(91));await settle();
  let confirmRemove=false;
  state.$store.clinicFeedback.confirm=async dialog=>{assert.equal(dialog.title,'Remove treatment?');return confirmRemove;};
  context.API.deleteAdminPetVaccination=async (pet,id)=>{writes.push(['delete',pet,id]);};
  await state.removeTreatment(state.records[0]);assert.notEqual(writes.at(-1)[0],'delete');
  confirmRemove=true;await state.removeTreatment(state.records[0]);assert.equal(writes.at(-1)[0],'delete');
  assert.equal(state.records.length,0);
  state.recordKind='manual';state.changeSource();state.form.vaccine_name='Ceftriaxone';state.treatmentType='medicine';
  state.form.route = 'subcutaneous';
  assert.equal(state.siteRequired, true, 'Manual treatments require the same clinical injection details');
  state.form.dose_amount='0.8'; state.form.dose_unit='mL'; state.form.administration_site='Right thigh';
  state.form.product_expiry_date='2099-01-01';
  state.form.manufacturer='Rocephin'; state.form.batch_number='LOT-2026-051';
  for (const price of ['abc','₱350','-1','+1','1e5','350.501','0','10000.01','3.5.0','350.','.50']) {
    state.form.manual_price=price;
    assert.ok(state.formValidationErrors(true).manual_price, `Reject invalid Manual price ${price}`);
  }
  for (const price of ['350','350.5','350.50','10000.00','0.01']) {
    state.form.manual_price=price;
    assert.equal(state.formValidationErrors(true).manual_price, undefined, `Accept valid Manual price ${price}`);
  }
  state.form.manual_price='';
  assert.ok(state.formValidationErrors(true).manual_price);
  assert.equal(state.formValidationErrors(false).manual_price, undefined, 'Incomplete case drafts may omit the price');
  state.form.manual_price='350.50';
  const billing = attach(context.clinicCaseWorkspace());
  billing.$store.clinicAccess.permissions.billing_view = true;
  billing.syncWorkspace(true, appt(91), 'vaccinations');
  let billingReads = 0, serverPrice = '350.50';
  context.API.getClinicCaseCharges = async id => {
    assert.equal(id, 91); ++billingReads;
    return {charges: {data: serverPrice ? [{id: 30, kind: 'medicine', description: 'Ceftriaxone', vaccination_record_id: 10, amount: serverPrice}] : [], current_page: 1, last_page: 1}, total_amount: serverPrice || '0.00'};
  };
  // Execute the actual workspace event binding, including its case/permission guard.
  const clinicHtml = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const billingBinding = clinicHtml.match(/@clinic-treatment-updated\.window="([^"]+)"/)[1];
  const refreshBilling = vm.runInContext(`(function($event) { with(this) { ${billingBinding} } })`, context);
  context.window.addEventListener('clinic-treatment-updated', event => refreshBilling.call(billing, event));
  const billingEvents = () => events.filter(event => event.type === 'clinic-treatment-updated');
  let beforeRefresh = billingEvents().length;
  await state.saveTreatment();
  await settle();
  assert.equal(billingEvents().length, beforeRefresh + 1, 'Successful Add refreshes Billing once');
  assert.equal(billingEvents().at(-1).detail.id, 91);
  assert.equal(billingReads, 1); assert.equal(billing.billingTotal, '350.50');
  assert.equal(billing.treatmentCharges[0].description, 'Ceftriaxone');
  assert.equal(writes.at(-1)[2].inventory_item_id,null);
  assert.equal(writes.at(-1)[2].manual_price,'350.50', 'Dose does not multiply the final charge');
  assert.equal(writes.at(-1)[2].record_source,'manual');
  assert.equal(writes.at(-1)[2].manufacturer, 'Rocephin'); assert.equal(writes.at(-1)[2].batch_number, 'LOT-2026-051');
  assert.equal(Object.hasOwn(writes.at(-1)[2], 'administered_date'), false, 'Current administration date is server controlled');
  state.openEditForm(state.records[0]); state.form.manual_price='400'; beforeRefresh=billingEvents().length; serverPrice='400.00';
  assert.equal(state.form.manufacturer, 'Rocephin'); assert.equal(state.form.batch_number, 'LOT-2026-051');
  await state.saveTreatment(); assert.equal(billingEvents().length,beforeRefresh+1, 'Successful Update refreshes Billing once');
  await settle();
  assert.equal(billingReads, 2); assert.equal(billing.billingTotal, '400.00');
  beforeRefresh=billingEvents().length;
  serverPrice=null;
  await state.removeTreatment(state.records[0]); assert.equal(billingEvents().length,beforeRefresh+1,'Successful Remove refreshes Billing once');
  await settle();
  assert.equal(billingReads, 3); assert.equal(billing.billingTotal, '0.00'); assert.equal(billing.treatmentCharges.length, 0);
  context.window.dispatchEvent(new context.CustomEvent('clinic-treatment-updated', {detail: {id: 90}}));
  assert.equal(billingReads, 3, 'Other-case mutations never refetch this case Billing');
  const count=writes.length;state.$store.clinicAccess.permissions.clinical=false;await state.saveTreatment();
  assert.equal(writes.length,count,'Read-only roles cannot add treatments');
  state.$store.clinicAccess.permissions.clinical=true;state.appointment.paid=true;
  await state.saveTreatment();assert.equal(writes.length,count,'Paid cases cannot receive treatment entries');

  assert.equal(context.clinicTreatmentSummary({dose_amount:'0.500',dose_unit:'mL',route:'intramuscular'}),'0.5 mL \u00b7 IM');
  assert.equal(context.clinicTreatmentSummary({route:'oral'}),'Oral');
  const fields = Object.fromEntries(context.clinicTreatmentDetails({treatment_type:'medicine',manufacturer:'Maker',batch_number:'LOT',product_expiry_date:'2027-01-01',notes:'Saved note',clinic_appointment_reference:'CL-91',inventory_quantity:'1.00',inventory_unit:'bottle'}).map(field => [field.label,field.value]));
  assert.equal(fields.Manufacturer,'Maker');assert.equal(fields['Batch / Lot'],'LOT');assert.equal(fields.Notes,'Saved note');assert.equal(fields.Case,'CL-91');assert.equal(fields.Quantity,'1 bottle');
  const manualFields = Object.fromEntries(context.clinicTreatmentDetails({record_source:'manual',manual_price:'350.50',dose_amount:'0.8',dose_unit:'mL'}).map(field=>[field.label,field.value]));
  assert.equal(manualFields.Source, 'Manual treatment'); assert.equal(manualFields.Price, '₱350.50'); assert.equal(manualFields.Dose,'0.8 mL');
  assert.equal(Object.hasOwn(manualFields, 'Product'), false, 'Current Manual treatments omit a meaningless Product row');
  const manualDetails = Object.fromEntries(context.clinicTreatmentDetails({record_source:'manual',manufacturer:'Rocephin',batch_number:'LOT-2026-051'}).map(field=>[field.label,field.value]));
  assert.equal(manualDetails.Brand, 'Rocephin'); assert.equal(manualDetails['Batch / Lot'], 'LOT-2026-051');
  assert.equal(Object.hasOwn(manualDetails, 'Manufacturer'), false);
  assert.equal(context.clinicTreatmentDetails({record_source:'historical',product_name:'Legacy product'}).find(field=>field.label==='Product').value,'Legacy product');
  assert.equal(context.clinicTreatmentDetails({record_source:'manual',product_name:'Stored product snapshot'}).find(field=>field.label==='Product').value,'Stored product snapshot');
  assert.equal(context.clinicTreatmentDetails({record_source:'historical'}).find(field=>field.label==='Source').value,'Historical / external');

  const cold = attach(context.adminClinicVaccinations());
  cold.inline = true; cold.init();
  const coldRecords = deferred();
  context.API.getAdminPetVaccinations = () => coldRecords.promise;
  const recordsReady = cold.openForAppointment(appt(92));
  context.window.dispatchEvent(new context.CustomEvent('clinic-treatment-focus', {detail: {
    id: 92, message: 'Select an administration route.', errors: {'treatments.11.route': ['Select an administration route.']}, recordsReady,
  }}));
  coldRecords.resolve({vaccinations: [{id: 11, state: 'draft', clinic_appointment_id: 92, inventory_item_id: 5}]});
  await settle();
  assert.equal(cold.formModal.recordId, 11, 'Backend validation opens the draft even when treatments were still loading');
  assert.match(cold.formErrors.route, /Select an administration route/);

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
