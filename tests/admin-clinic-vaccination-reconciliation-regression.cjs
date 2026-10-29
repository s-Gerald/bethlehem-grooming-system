const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const source = fs.readFileSync(path.join(root, "scripts/components/admin-vaccinations.js"), "utf8");
const sandbox = {
  window: { addEventListener() {}, dispatchEvent() {}, lucide: null },
  CustomEvent: class { constructor(type) { this.type = type; } },
  clinicLocalDate: (date) => date.toISOString().slice(0, 10),
  setTimeout, clearTimeout, URLSearchParams,
  API: {},
  document: { addEventListener() {} },
  crypto: { randomUUID: require("node:crypto").randomUUID },
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), sandbox);
vm.runInContext(fs.readFileSync(path.join(root, "scripts/components/admin-clinic.js"), "utf8"), sandbox);
vm.runInContext(fs.readFileSync(path.join(root, "scripts/components/product-search-dropdown.js"), "utf8"), sandbox);
vm.runInContext(source, sandbox);

function component() {
  const instance = sandbox.adminClinicVaccinations();
  instance.$nextTick = (callback) => callback();
  instance.$refs = {};
  instance.$store = { clinicAccess: { permissions: { clinical: true } }, clinicFeedback: { confirm: async () => true, notify() {} } };
  return instance;
}

const vaccine = {
  item_id: 301, category: "vaccine", item_name: "Rabies", unit: "vial", is_active: true, quantity_on_hand: 4, stock_status: "available",
  available_quantity: 3, next_batch: { batch_number: "EARLY", expiry_date: "2027-12-01" },
};
const draft = {
  id: 7, state: "draft", inventory_item_id: 301, vaccine_name: "Rabies",
  administered_date: sandbox.vaccinationToday(), dose_amount: 1, dose_unit: "mL",
  inventory_quantity: 1, clinic_appointment_id: 201,
};

(async () => {
  const discovery = component();
  discovery.formModal.open = true;
  discovery.vaccineOptions = [vaccine, {...vaccine, item_id: 302, category: 'medicine'}, {...vaccine, item_id: 303, is_active: false}, {...vaccine, item_id: 304, stock_status: 'expired', available_quantity: 0}];
  await discovery.focusTreatmentProducts();
  assert.deepEqual(Array.from(discovery.productResults, item => item.item_id), [301]);
  discovery.productQuery = 'Rabies'; discovery.searchTreatmentProducts();
  assert.deepEqual(Array.from(discovery.productResults, item => item.item_id), [301, 303, 304]);
  discovery.selectVaccine({...vaccine, category: 'medicine'});
  assert.equal(discovery.selectedVaccine, null);
  discovery.formModal.unavailableVaccineName = 'Old vaccine';
  discovery.changeTreatmentType('medicine');
  assert.equal(discovery.formModal.unavailableVaccineName, '', 'Switching category clears the old unavailable-product notice');
  assert.equal(discovery.productDropdown.open, false);
  assert.equal(discovery.productResults.length, 0);
  let resolveMedicine;
  sandbox.API.clinicMedicineOptions = () => new Promise(resolve => {resolveMedicine = resolve;});
  const pendingMedicine = discovery.focusTreatmentProducts();
  discovery.changeTreatmentType('vaccine');
  resolveMedicine({data: [{...vaccine, category: 'medicine'}]}); await pendingMedicine;
  assert.equal(discovery.medicineResults.length, 0);
  discovery.recordKind = 'historical'; discovery.changeSource();
  assert.equal(discovery.productDropdown.open, false);

  const defaults = component(); defaults.formModal.open = true;
  defaults.vaccineOptions = Array.from({length: 20}, (_, index) => ({...vaccine, item_id: index + 1}));
  assert.equal(defaults.productResults.length, 12);
  defaults.changeTreatmentType('medicine');
  defaults.appointment = {id: 201, weight_kg: 4};
  const pricedMedicine = {...vaccine, category: 'medicine', pricing_method: 'flat', selling_price: 100};
  sandbox.API.clinicMedicineOptions = async (query, suggestions) => {
    assert.equal(query, ''); assert.equal(suggestions, true);
    return {data: [pricedMedicine, vaccine, {...pricedMedicine, item_id: 305, is_active: false}]};
  };
  await defaults.focusTreatmentProducts();
  assert.deepEqual(Array.from(defaults.productResults, item => item.item_id), [301]);
  for (const item of [vaccine, {...pricedMedicine, stock_status: 'legacy_unit'}, {...pricedMedicine, available_quantity: 0}, {...pricedMedicine, selling_price: null}]) {
    defaults.selectMedicine(item); assert.equal(defaults.selectedMedicine, null);
  }
  const weightMedicine = {...pricedMedicine, pricing_method: 'weight_based', weight_pricing_mode: 'ranges', weight_prices: [{min_weight_kg: 1, max_weight_kg: 5, price: 100}]};
  defaults.appointment.weight_kg = null;
  defaults.selectMedicine(weightMedicine); assert.equal(defaults.selectedMedicine, null, 'A missing weight cannot produce a valid product charge');
  defaults.selectMedicine(weightMedicine, true);
  assert.match(defaults.medicineQuote.error, /weight/, 'Restored drafts retain their existing unresolved-price guidance');

  const doses = component();
  doses.form.dose_amount = '2';
  doses.validateDoseFields('input');
  assert.equal(doses.formErrors.dose_unit, undefined, 'Do not interrupt first-time typing');
  doses.validateDoseFields('blur');
  assert.ok(doses.formErrors.dose_unit);
  doses.form.dose_unit = 'mL';
  doses.validateDoseFields('input');
  assert.equal(doses.formErrors.dose_unit, undefined, 'Selecting a unit clears its server/client error immediately');
  doses.form.dose_amount = '0.5'; doses.form.dose_unit = 'capsule';
  doses.validateDoseFields('blur');
  assert.ok(doses.formErrors.dose_amount);
  doses.form.dose_unit = 'tablet'; doses.validateDoseFields('input');
  assert.equal(doses.formErrors.dose_amount, undefined, 'A different unit revalidates fractional amounts');
  for (const amount of ['0', '-1', '1e2', 'Infinity', 'NaN', '0x10', '1.0001', '100000']) {
    doses.form.dose_amount = amount; doses.validateDoseFields('blur');
    assert.ok(doses.formErrors.dose_amount, `Reject dose ${amount}`);
  }
  doses.form.dose_amount = '0.5'; doses.form.dose_unit = 'other';
  doses.validateDoseFields('blur');
  assert.ok(doses.formErrors.dose_unit_other);
  doses.form.dose_unit_other = 'measured scoop'; doses.validateDoseFields('input');
  assert.equal(doses.formErrors.dose_unit_other, undefined);
  assert.equal(doses.buildPayload().dose_unit, 'other');
  assert.equal(doses.buildPayload().dose_unit_other, 'measured scoop');
  doses.form.dose_unit_other = 'CAPSULE'; doses.validateDoseFields('blur');
  assert.ok(doses.formErrors.dose_amount, 'Other cannot bypass whole-count rules for a recognized unit');
  doses.form.dose_unit_other = 'measured scoop'; doses.validateDoseFields('input');
  assert.equal(doses.formErrors.dose_amount, undefined);
  doses.validateForm(true);
  assert.equal(doses.formErrors.dose_amount, undefined);
  let resolveOptions;
  sandbox.API.getAdminVaccinationOptions = () => new Promise((resolve) => { resolveOptions = resolve; });
  const loading = component();
  const pending = loading.loadOptions();
  loading.openEditForm(draft);
  assert.equal(loading.form.inventory_item_id, "301");
  assert.equal(loading.formModal.unavailableVaccineName, "");
  resolveOptions({ vaccines: [vaccine], veterinarians: [], current_user_id: 2 });
  await pending;
  assert.equal(loading.selectedVaccine.item_id, 301);
  assert.equal(loading.formModal.unavailableVaccineName, "");
  assert.equal(loading.validateForm(), true);
  const payload = loading.buildPayload();
  for (const field of ["administered_date", "vaccine_name", "manufacturer", "batch_number", "product_expiry_date", "administered_by_name"]) {
    assert.equal(Object.hasOwn(payload, field), false, `Inventory payload must leave ${field} to the server`);
  }
  assert.equal(loading.vaccineSelectable({ ...vaccine, available_quantity: 0 }), false);
  assert.equal(loading.vaccineSelectable({ ...vaccine, is_active: false }), false);
  assert.equal(loading.vaccineSelectable({ ...vaccine, unit: "bottle" }), true);
  for (const [item, expected, selectable] of [
    [{ ...vaccine, quantity_on_hand: 0, available_quantity: 0, stock_status: "out_of_stock" }, "Out of stock", false],
    [{ ...vaccine, quantity_on_hand: 8, available_quantity: 0, stock_status: "expired" }, "Expired", false],
    [{ ...vaccine, unit: "ml", quantity_on_hand: 8, available_quantity: 0, stock_status: "legacy_unit" }, "Legacy unit · review Inventory", false],
    [{ ...vaccine, quantity_on_hand: 8, available_quantity: 2, stock_status: "low_stock" }, "Low stock · 2 vials available", true],
    [{ ...vaccine, quantity_on_hand: 12, available_quantity: 3 }, "3 vials available", true],
  ]) {
    assert.equal(loading.productAvailabilityLabel(item), expected);
    assert.equal(loading.productSelectable(item), selectable);
  }
  loading.form.dose_amount = ""; loading.form.dose_unit = "";
  assert.equal(loading.validateForm(false), true, 'An incomplete dose is permitted in a draft');
  assert.equal(loading.validateForm(true), false, 'An administered Inventory treatment needs a documented dose');
  loading.form.dose_amount = 2.5;
  assert.equal(loading.validateForm(), false);
  loading.form.dose_unit = "mL";
  assert.equal(loading.validateForm(true), true);
  assert.equal(loading.buildPayload().inventory_quantity, "1");
  loading.form.dose_amount = 7.5;
  assert.equal(loading.buildPayload().inventory_quantity, "1");
  loading.changeInventoryQuantity(1); loading.changeInventoryQuantity(1); loading.changeInventoryQuantity(1);
  assert.equal(loading.form.inventory_quantity, 3);
  loading.form.inventory_quantity = 4;
  assert.equal(loading.validateForm(true), false);
  for (const quantity of [0, 0.25, 0.5, 1.25]) {
    loading.form.inventory_quantity = quantity;
    assert.equal(loading.validateForm(true), false);
  }
  for (const field of ["administered_by_user_id", "administered_by_name"]) assert.equal(Object.hasOwn(payload, field), false);

  const historical = component();
  historical.openEditForm({ ...draft, inventory_item_id: null, administered_date: "2020-01-01", manufacturer: "Original", batch_number: "OLD", administered_by_user_id: null, administered_by_name: "External vet" });
  assert.equal(historical.recordKind, "historical");
  historical.form.next_due_date = "2020-02-01";
  historical.form.dose_amount = "";
  historical.form.dose_unit = "";
  assert.equal(historical.validateForm(), true);
  const historicalPayload = historical.buildPayload();
  assert.equal(historicalPayload.inventory_item_id, null);
  assert.equal(historicalPayload.administered_date, "2020-01-01");
  assert.equal(historicalPayload.manufacturer, "Original");
  assert.equal(historicalPayload.batch_number, "OLD");
  assert.equal(Object.hasOwn(historicalPayload, "administered_by_name"), false);

  let publications = [];
  sandbox.API.createAdminPetVaccination = async () => ({ vaccination: { id: 8 } });
  sandbox.API.publishAdminPetVaccination = async (...args) => { publications.push(args); return { message: "Published" }; };
  sandbox.API.stockOut = () => { throw new Error("Clinic must not perform a separate stock-out request"); };
  const current = component();
  current.pet = { id: 101 };
  current.appointment = { id: 201, status: "in_consultation" };
  current.vaccineOptions = [vaccine];
  current.openEditForm(draft);
  current.openEditForm({...draft, dose_unit: 'legacy scoop'});
  assert.equal(current.form.dose_unit, 'legacy scoop');
  assert.equal(current.buildPayload().dose_unit, 'legacy scoop');
  assert.equal(current.validateForm(false), true);
  current.openEditForm({...draft, dose_unit: 'other'});
  assert.equal(current.validateForm(false), true, 'The historical literal other stays editable');
  assert.equal(current.buildPayload().dose_unit, 'other');
  current.openEditForm(draft);
  current.loadVaccinations = async () => {};
  current.loadOptions = async () => {};
  sandbox.API.updateAdminPetVaccination = async () => ({ vaccination: { id: draft.id } });
  await current.saveTreatment();
  assert.equal(publications.length, 0);
  assert.equal(current.appointment.status, "in_consultation");

  const medicine = { item_id: 302, category: "medicine", item_name: "Medicine", unit: "bottle", available_quantity: 2, quantity_on_hand: 2, is_active: true, stock_status: "available", next_batch: { batch_number: "MED", expiry_date: "2027-12-01" },
    strength_concentration: "10 mg/mL", pricing_method: "weight_based", weight_pricing_mode: "ranges",
    weight_prices: [{ min_weight_kg: 1, max_weight_kg: 5, price: 250 }] };
  const treatment = component();
  treatment.pet = { id: 101 };
  treatment.appointment = { id: 201, status: "in_consultation", weight_kg: 4 };
  treatment.currentUserId = 2;
  treatment.openCreateForm();
  treatment.changeTreatmentType("medicine");
  treatment.selectMedicine(medicine);
  assert.equal(treatment.medicineQuote.price, 250);
  assert.equal(treatment.form.dose_unit, "");
  assert.equal(treatment.form.inventory_quantity, 1);
  treatment.form.dose_amount = 0.5;
  assert.equal(treatment.validateForm(), false, "Stock medicine must require physical quantity");
  treatment.form.inventory_quantity = "0.25";
  assert.equal(treatment.validateForm(), false, "Fractional stock use is rejected");
  treatment.form.inventory_quantity = 1;
  treatment.form.dose_unit = "mL";
  assert.equal(treatment.validateForm(true), true);
  const medicinePayload = treatment.buildPayload();
  assert.equal(medicinePayload.treatment_type, "medicine");
  assert.equal(medicinePayload.expected_price, 250);
  assert.equal(medicinePayload.expected_weight_kg, 4);
  assert.equal(medicinePayload.inventory_quantity, "1");
  assert.equal(treatment.buildPayload().request_key, medicinePayload.request_key, "Retries keep the same request key");
  assert.equal(Object.hasOwn(medicinePayload, "batch_number"), false);
  treatment.loadVaccinations = async () => {};
  treatment.loadOptions = async () => {};
  let retries = [];
  sandbox.API.createAdminPetVaccination = async (petId, payload) => {
    retries.push(payload.request_key);
    if (retries.length === 1) throw new Error("Lost response");
    return { vaccination: { id: 12 } };
  };
  await treatment.saveTreatment();
  assert.equal(treatment.formModal.open, true);
  await treatment.saveTreatment();
  assert.equal(retries[0], retries[1]);

  sandbox.API.clinicMedicineOptions = async () => ({ data: [medicine] });
  treatment.openEditForm({ ...draft, treatment_type: "medicine", inventory_item_id: 302,
    inventory_quantity: "1.00", vaccine_name: "Medicine", request_key: medicinePayload.request_key });
  await treatment.restoreMedicineSelection();
  assert.equal(treatment.selectedMedicine.item_id, 302);
  assert.equal(treatment.form.inventory_quantity, 1, "Restoring selection preserves stock used");
  assert.equal(treatment.form.dose_amount, 1, "Restoring selection preserves clinical dosage");
  assert.equal(treatment.form.request_key, medicinePayload.request_key);
  assert.equal(treatment.validateForm(), true);
  assert.equal(treatment.buildPayload().expected_price, 250);

  treatment.recordKind = "historical";
  treatment.changeSource();
  treatment.form.vaccine_name = "External medicine";
  treatment.form.administered_date = "2020-01-01";
  treatment.form.administered_by_name = "External provider";
  assert.equal(treatment.validateForm(), true);
  const externalPayload = treatment.buildPayload();
  assert.equal(externalPayload.inventory_item_id, null);
  assert.equal(Object.hasOwn(externalPayload, "expected_price"), false);
  assert.equal(externalPayload.inventory_quantity, null);
  assert.equal(externalPayload.administered_date, "2020-01-01");
  treatment.changeTreatmentType("vaccine");
  assert.equal(treatment.selectedMedicine, null);
  assert.equal(treatment.form.inventory_item_id, "");
  assert.equal(treatment.form.inventory_quantity, 1);

  const page = fs.readFileSync(path.join(root, "pages/admin/clinic.html"), "utf8");
  assert.ok(page.includes("No treatments recorded for this case."));
  assert.ok(!page.includes("Create a draft to begin this patient's"));
  assert.ok(page.includes("Add Treatment"));
  assert.ok(!page.includes("Record Treatment"));
  assert.ok(page.includes("Current Visit Treatments"));
  assert.ok(page.includes(':aria-pressed="treatmentType'));

  const css = fs.readFileSync(path.join(root, "css/output.css"), "utf8");
  const layers = new Set([...page.matchAll(/\bz-\[(2\d\d)\]/g)].map(match => Number(match[1])));
  assert.deepEqual([...layers].sort((a, b) => a - b), [200, 210, 230, 250]);
  for (const layer of layers) {
    const selector = `.z-\\[${layer}\\] {`;
    const offset = css.indexOf(selector);
    assert.ok(offset >= 0, `Compiled CSS must include ${selector}`);
    assert.ok(css.slice(offset, css.indexOf("}", offset)).includes(`z-index: ${layer}`));
  }
  console.log("Clinic treatment reconciliation regression checks passed");
})().catch((error) => { console.error(error); process.exitCode = 1; });
