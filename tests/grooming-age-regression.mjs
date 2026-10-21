import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import * as age from "../scripts/services/grooming-age.js";
import * as petService from "../scripts/services/pet-service.js";
import * as tabs from "../scripts/components/pet-selection-tabs.js";
import * as weight from "../scripts/components/pet-weight-size.js";

const NativeDate = Date;
globalThis.Date = class extends NativeDate {
  constructor(...args) { super(...(args.length ? args : ["2026-10-16T00:00:00+08:00"])); }
};
for (const [birthdate, today, eligible] of [
  [null, "2026-10-16", true], ["", "2026-10-16", true],
  ["invalid", "2026-10-16", true], ["2026-02-30", "2026-10-16", true],
  ["2026-06-16", "2026-10-16", false], ["2026-04-17", "2026-10-16", false],
  ["2026-04-16", "2026-10-16", true], ["2024-10-16", "2026-10-16", true],
  ["2026-04-16T00:00:00.000000Z", "2026-10-16", true],
  ["2025-08-31", "2026-02-27", false], ["2025-08-31", "2026-02-28", true],
  ["2023-08-31", "2024-02-28", false], ["2023-08-31", "2024-02-29", true],
  ["2024-02-29", "2024-08-28", false], ["2024-02-29", "2024-08-29", true],
  ["2026-04-30", "2026-10-27", false], // Over 180 days, still below six months.
  ["2026-11-16", "2026-10-16", false],
]) {
  assert.equal(age.isEligibleForGrooming({ birthdate }, today), eligible, `${birthdate} on ${today}`);
}
assert.equal(age.formatPetAge({ birthdate: "2026-06-16" }), "4 months");
assert.equal(age.formatPetAge({ birthdate: "2024-10-16" }), "2 years");
assert.equal(age.formatPetAge({ birthdate: "2025-10-16" }), "1 year");
assert.equal(age.formatPetAge({}), "");

function storage() {
  const values = new Map();
  return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
}

function element() {
  const classes = new Set();
  return {
    value: "", textContent: "", innerHTML: "", dataset: {}, children: [], listeners: {},
    classList: {
      add: (...names) => names.forEach(name => classes.add(name)),
      remove: (...names) => names.forEach(name => classes.delete(name)),
      toggle: (name, on) => on ? classes.add(name) : classes.delete(name),
      contains: name => classes.has(name),
    },
    setAttribute() {}, addEventListener(name, handler) { this.listeners[name] = handler; },
    appendChild(child) { this.children.push(child); },
  };
}

function combobox({ input }) {
  return {
    setValue(value) { input.value = value; }, getValue: () => input.value,
    setDisabled() {}, setOptions() {}, update() {}, reset() { input.value = ""; },
    getValidationMessage: () => "", showValidation() {},
  };
}

const read = name => fs.readFileSync(new URL(`../scripts/components/${name}.js`, import.meta.url), "utf8")
  .replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, "").replace(/^export /gm, "");
const underage = { id: "young", petName: "Puppy", petType: "Dog", birthdate: "2026-06-16" };
const exact = { id: "six", petName: "Six Months", petType: "Dog", birthdate: "2026-04-16" };
const unknown = { id: "unknown", petName: "Unknown", petType: "Cat" };

function petStep(clinic = false) {
  globalThis.sessionStorage = storage();
  globalThis.localStorage = storage();
  const nodes = new Map();
  const get = id => {
    if (!nodes.has(id)) nodes.set(id, { ...element(), id, ownerDocument: { getElementById: get } });
    return nodes.get(id);
  };
  const form = get("addPetForm");
  for (const id of ["petType", "petName", "breed", "weight", "furType", "size", "medicalNotes"]) form[id] = get(id);
  form.reset = () => { for (const input of Object.values(form)) if (input?.value !== undefined) input.value = ""; };
  const button = element();
  button.dataset.petId = underage.id;
  let navigated;
  const context = vm.createContext({
    ...petService, ...tabs, ...weight, ...age, GROOMING_MAX_PETS: 10,
    getStoredBookingPets: petService.getBookingPets, saveStoredBookingPets: petService.saveBookingPets,
    getGroomingSchedule: petService.getBookingSchedule, addStoredPet: petService.addPetToBooking,
    removeStoredPet: petService.removePetFromBooking, syncWeightSizeFields: weight.syncPetWeightAndSize,
    CLINIC_VISIT_PETS_KEY: "clinicPets", sessionStorage, URLSearchParams, console,
    createFixedOptionCombobox: combobox, createBreedCombobox: combobox, createBreedCoatCombobox: combobox,
    formatBookingSchedule: () => "", formatPetSizeLabel: value => value, normalizePetSize: value => value,
    readClinicVisitDraft: () => ({}), updateClinicVisitDraft() {},
    isGroomingSinglePage: () => true, goToGroomingStep: step => { navigated = step; },
    window: { location: { search: clinic ? "?flow=clinic" : "" } },
    document: {
      getElementById: get, createElement: element,
      querySelectorAll: selector => selector === ".select-existing-pet-btn" ? [button] : [],
    },
  });
  vm.runInContext(read("booking-pet-step"), context);
  petService.saveSavedPets([underage, exact, unknown]);
  return { context, get, button, target: () => navigated };
}

const ui = petStep();
ui.context.renderExistingPets();
const cards = ui.get("existingPetList").children;
assert.equal(cards.length, 3, "Every saved pet remains visible");
assert.match(cards[0].className, /opacity-80/);
assert.match(cards[0].innerHTML, /Under 6 months/);
assert.match(cards[0].innerHTML, /disabled[\s\S]*Not eligible/);
assert.match(cards[0].innerHTML, /Age:<\/span> 4 months/);
assert.equal((cards[0].innerHTML.match(/Grooming is for pets 6 months and older\./g) || []).length, 1);
assert.doesNotMatch(cards[0].innerHTML + cards[0].className, /red-|error/);
assert.doesNotMatch(cards[2].innerHTML, /Age:|Under 6|Not eligible|warning/);
assert.match(cards[2].innerHTML, /Select Pet/);
assert.equal(ui.get("selectAllExistingPetsBtn").textContent, "Select All Eligible");
ui.button.listeners.click(); // Exercise the handler even if a disabled button were bypassed.
assert.equal(petService.getBookingPets().length, 0);
assert.equal(ui.get("petStepError").textContent, age.GROOMING_MINIMUM_AGE_MESSAGE);
assert.doesNotMatch(ui.get("petStepError").className, /red-/);
ui.context.handleSelectAllExistingPets();
assert.deepEqual(petService.getBookingPets().map(pet => pet.id), ["six", "unknown"]);

petService.saveSavedPets([underage, ...Array.from({ length: 10 }, (_, i) => ({ id: `eligible-${i}` }))]);
petService.saveBookingPets([]);
ui.context.renderExistingPets();
assert.equal(ui.get("selectAllExistingPetsBtn").disabled, false, "The limit counts only eligible pets");
ui.context.handleSelectAllExistingPets();
assert.equal(petService.getBookingPets().length, 10);

petService.saveBookingPets([underage, exact]);
ui.context.handleNext();
assert.equal(ui.target(), undefined, "Stale underage selections cannot continue");
ui.context.loadPetsFromApi = async () => {};
await ui.context.syncSavedPets();
assert.deepEqual(petService.getBookingPets().map(pet => pet.id), ["six"]);
ui.context.handleNext();
assert.equal(ui.target(), "services");
const values = { petType: "Dog", petName: "New Pet", birthdate: "" };
assert.equal(ui.context.validatePetForm(values), "");
assert.equal(ui.context.validatePetForm({ ...values, birthdate: underage.birthdate }), age.GROOMING_MINIMUM_AGE_MESSAGE);

// Blank birthdates still save and select through the actual Add New Pet handler.
let submitted;
ui.context.API = { getCustomerToken: () => true, addPet: async payload => { submitted = payload; return { pet: unknown }; } };
ui.context.normalizeApiPet = pet => pet;
ui.get("petType").value = "Dog";
ui.get("petName").value = "New Pet";
await ui.context.handleAddPetSubmit({ preventDefault() {} });
assert.equal(submitted.birthdate, null);
assert.ok(petService.getBookingPets().some(pet => pet.id === "unknown"));
submitted = undefined;
ui.get("petType").value = "Dog";
ui.get("petName").value = "Young Pet";
ui.get("birthdate").value = underage.birthdate;
const selectedCount = petService.getBookingPets().length;
await ui.context.handleAddPetSubmit({ preventDefault() {} });
assert.equal(submitted, undefined, "An underage Save & Select never reaches the API");
assert.equal(petService.getBookingPets().length, selectedCount);
assert.equal(ui.get("petStepError").textContent, age.GROOMING_MINIMUM_AGE_MESSAGE);

const clinic = petStep(true);
clinic.context.renderExistingPets();
assert.doesNotMatch(clinic.get("existingPetList").children[0].innerHTML, /Under 6|Not eligible|Age:/);
clinic.button.listeners.click();
assert.equal(petService.getBookingPets("clinicPets")[0].id, "young");
assert.equal(clinic.context.validatePetForm({ ...values, birthdate: underage.birthdate }), "Only 1 pets are allowed per visit.");
petService.saveBookingPets([], "clinicPets");
assert.equal(clinic.context.validatePetForm({ ...values, birthdate: underage.birthdate }), "");
console.log("Grooming age: calendar boundaries, card states, selection guards, Select All, stale drafts, optional birthdates, and clinic isolation passed.");
