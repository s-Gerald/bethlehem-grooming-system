import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { createPetSaveConfirmation } from "../scripts/components/pet-save-confirmation.js";
import * as weightTools from "../scripts/components/pet-weight-size.js";
import * as petService from "../scripts/services/pet-service.js";
import * as clinicService from "../scripts/services/clinic-visit-service.js";
import * as ageTools from "../scripts/services/grooming-age.js";
import * as selectionTools from "../scripts/components/pet-selection-tabs.js";
import { normalizePetSize, formatPetSizeLabel } from "../scripts/services/booking-draft-service.js";

function node() {
  const listeners = new Map(), classes = new Set();
  return {
    value: "", textContent: "", dataset: {}, children: [],
    classList: {
      add(...names) { names.forEach(name => classes.add(name)); },
      remove(...names) { names.forEach(name => classes.delete(name)); },
      toggle(name, value) { value ? classes.add(name) : classes.delete(name); },
    },
    setAttribute() {}, removeAttribute() {}, focus() {},
    appendChild(child) { this.children.push(child); },
    addEventListener(type, fn) { listeners.set(type, [...(listeners.get(type) || []), fn]); },
    async dispatch(type) { for (const fn of listeners.get(type) || []) await fn({ preventDefault() {} }); },
  };
}

function storage() {
  const values = new Map();
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}

function mount(clinic) {
  const nodes = new Map();
  const get = id => {
    if (!nodes.has(id)) nodes.set(id, Object.assign(node(), { id, ownerDocument: { getElementById: key => get(key) } }));
    return nodes.get(id);
  };
  globalThis.localStorage = storage();
  globalThis.sessionStorage = storage();
  const form = get("addPetForm");
  for (const id of ["petType", "petName", "gender", "birthdate", "breed", "color", "weight", "furType", "size", "medicalNotes"]) form[id] = get(id);
  form.reset = () => { for (const value of Object.values(form)) if (value?.value !== undefined) value.value = ""; };
  const combobox = ({ input }) => ({
    getValue: () => input.value, getValidationMessage: () => "", showValidation() {},
    setValue(value) { input.value = value || ""; }, reset() { input.value = ""; },
    setOptions() {}, update() {}, setDisabled() {},
  });
  let writes = 0, save = async payload => ({ pet: { pet_id: 1, ...payload } });
  const context = vm.createContext({
    ...weightTools, ...clinicService, ...ageTools, ...selectionTools,
    normalizePetSize, formatPetSizeLabel, createPetSaveConfirmation,
    syncWeightSizeFields: weightTools.syncPetWeightAndSize,
    GROOMING_MAX_PETS: petService.MAX_PETS_PER_BOOKING,
    getSavedPets: petService.getSavedPets, getStoredBookingPets: petService.getBookingPets,
    saveStoredBookingPets: petService.saveBookingPets, addStoredPet: petService.addPetToBooking,
    removeStoredPet: petService.removePetFromBooking, addPetToSavedPets: petService.addPetToSavedPets,
    createColorCombobox: combobox, createBreedCombobox: combobox,
    createBreedCoatCombobox: combobox, createFixedOptionCombobox: combobox,
    sessionStorage, URLSearchParams,
    isGroomingSinglePage: () => true,
    window: { location: { search: clinic ? "?flow=clinic" : "" } },
    document: { getElementById: get, querySelectorAll: () => [], createElement: node },
    API: { getCustomerToken: () => "fixture", addPet: payload => { writes++; return save(payload); } },
  });
  const source = fs.readFileSync("scripts/components/booking-pet-step.js", "utf8")
    .replace(/^import[\s\S]*?;\r?\n/gm, "").replace(/^export /gm, "");
  vm.runInContext(source, context);
  context.bindEvents();
  const selected = () => petService.getBookingPets(clinic ? clinicService.CLINIC_VISIT_PETS_KEY : undefined);
  return { get, selected, submit: () => form.dispatch("submit"), writes: () => writes, setSave(fn) { save = fn; } };
}

for (const clinic of [false, true]) {
  const ui = mount(clinic), button = ui.get("savePetBtn"), warning = ui.get("petSaveWarning");
  const originalButton = button;
  assert.equal(button.textContent, "Save & Select Pet");
  assert.equal(warning.hidden, true);
  await ui.submit();
  assert.equal(warning.hidden, true, "Invalid forms never ask for acknowledgment");
  assert.equal(ui.writes(), 0);
  ui.get("petType").value = "Dog";
  ui.get("petName").value = "Milo";
  ui.get("birthdate").value = "2020-01-01";
  await ui.submit();
  assert.equal(warning.hidden, false);
  assert.equal(button.textContent, "Confirm");
  assert.equal(ui.writes(), 0);
  assert.equal(ui.selected().length, 0);
  ui.get("medicalNotes").value = "Gentle handling";
  await ui.get("addPetForm").dispatch("change");
  assert.equal(warning.hidden, true, "Custom dropdown change events reset confirmation");
  await ui.submit();
  ui.get("breed").value = "Beagle"; // Silent programmatic update.
  await ui.submit();
  assert.equal(ui.writes(), 0, "Stale acknowledgments never save");
  ui.setSave(async () => { throw new Error("Temporary failure"); });
  await ui.submit();
  assert.equal(ui.writes(), 1);
  assert.equal(ui.get("petName").value, "Milo");
  assert.equal(ui.get("petStepError").textContent, "Temporary failure");
  assert.equal(button.disabled, false);
  assert.equal(button.textContent, "Save & Select Pet");
  await ui.submit();
  assert.equal(ui.writes(), 1, "Retry requires acknowledgment again");
  let finish;
  ui.setSave(payload => new Promise(resolve => { finish = () => resolve({ pet: { pet_id: 1, ...payload } }); }));
  const pending = ui.submit();
  assert.equal(button.disabled, true);
  await ui.get("addPetForm").dispatch("input");
  assert.equal(button.disabled, true, "Input during saving keeps the submit button disabled");
  await ui.submit();
  assert.equal(ui.writes(), 2, "Repeated submits during saving never duplicate a request");
  finish();
  await pending;
  assert.equal(ui.selected().length, 1);
  assert.equal(petService.getSavedPets().length, 1);
  assert.equal(ui.get("selectedPetCount").textContent, clinic ? "1 / 1 selected" : "1 / 10 selected");
  assert.equal(ui.get("nextBtn").disabled, false);
  assert.equal(button, originalButton);
  assert.equal(button.textContent, "Save & Select Pet");
  assert.equal(warning.hidden, true);
  console.log(`${clinic ? "Clinic" : "Grooming"} pet acknowledgment, retry and selection passed`);
}
