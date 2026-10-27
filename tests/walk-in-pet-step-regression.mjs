import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import * as weightTools from "../scripts/components/pet-weight-size.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const page = readFileSync(path.join(root, "pages/admin/walk-in-pet-details.html"), "utf8");
const source = readFileSync(path.join(root, "scripts/components/walk-in-pet-step.js"), "utf8");
const ownerSource = readFileSync(path.join(root, "scripts/components/walk-in-owner-step.js"), "utf8");
const tabsSource = readFileSync(path.join(root, "scripts/components/pet-selection-tabs.js"), "utf8");
const version = "walk-in-owner-mode-20260924";

assert.match(page, new RegExp(`walk-in-pet-step\\.js\\?v=${version}`));
assert.match(source, new RegExp(`breed-combobox\\.js\\?v=${version}`));
assert.match(source, new RegExp(`pet-selection-tabs\\.js\\?v=${version}`));
assert.match(page, /id="existingPetsLoading"[\s\S]*id="existingPetCards"/);
assert.match(page, /id="savePetBtn"[\s\S]*Add Pet/);
assert.match(page, /id="nextBtn"[\s\S]*Next Step/);

function ownerDraft(selectedCustomer, appointmentType) {
  const start = ownerSource.indexOf("function saveOwnerDraft(");
  const end = ownerSource.indexOf("\nfunction escapeHtml", start);
  assert.ok(start >= 0 && end > start);
  let stored;
  const context = vm.createContext({
    selectedCustomer,
    appointmentType,
    values: { firstName: "A", lastName: "Customer", phone: "09171234567" },
    WALK_IN_OWNER_STORAGE_KEY: "walkInOwnerStep",
    getOwnerDisplayName: () => "A Customer",
    getAppointmentType: () => appointmentType,
    sessionStorage: { setItem(key, value) { assert.equal(key, "walkInOwnerStep"); stored = value; } },
  });
  vm.runInContext(`${ownerSource.slice(start, end)}\nsaveOwnerDraft(values, selectedCustomer);`, context);
  return JSON.parse(stored);
}

function makeElement(initialClasses = []) {
  const classes = new Set(initialClasses);
  const attributes = new Map();
  return {
    value: "",
    innerHTML: "",
    textContent: "",
    dataset: {},
    children: [],
    disabled: false,
    events: {},
    classList: {
      add(...names) { names.forEach((name) => classes.add(name)); },
      remove(...names) { names.forEach((name) => classes.delete(name)); },
      contains(name) { return classes.has(name); },
      toggle(name, force) {
        if (force === undefined ? !classes.has(name) : force) classes.add(name);
        else classes.delete(name);
      },
    },
    addEventListener(name, callback) { this.events[name] = callback; },
    focus() { this.focused = true; },
    setAttribute(name, value) { attributes.set(name, value); },
    getAttribute(name) { return attributes.get(name); },
    appendChild(child) { this.children.push(child); },
  };
}

function combobox({ input }) {
  let value = "";
  return {
    setValue(next) { value = next ?? ""; input.value = value; },
    getValue() { return value; },
    setOptions() {},
    setDisabled(disabled) { input.disabled = disabled; },
    reset() { value = ""; input.value = ""; },
    update() {},
    showValidation() {},
    getValidationMessage() { return ""; },
  };
}

async function runStep(owner, { draft = null, apiPets = null } = {}) {
  const elements = new Map();
  const getElement = (id) => {
    if (!elements.has(id)) {
      elements.set(id, makeElement(
        ["petSelectionTabs", "addPetSection", "existingPetsSection", "existingPetsLoading"].includes(id)
          ? ["hidden"] : [],
      ));
      elements.get(id).id = id;
      elements.get(id).ownerDocument = { getElementById: getElement };
    }
    return elements.get(id);
  };
  const form = getElement("addPetForm");
  for (const id of ["petType", "petName", "breed", "weight", "furType", "size", "medicalNotes"]) {
    form[id] = getElement(id);
  }
  form.reset = () => {
    for (const id of ["petType", "petName", "breed", "weight", "furType", "size", "medicalNotes"]) form[id].value = "";
  };

  const stored = new Map([
    ["walkInOwnerStep", JSON.stringify(owner)],
    ...(draft ? [["walkInPetStep", JSON.stringify(draft)]] : []),
  ]);
  let resolvePets;
  let rejectPets;
  const pendingPets = new Promise((resolve, reject) => {
    resolvePets = resolve;
    rejectPets = reject;
  });
  const calls = [];
  const context = vm.createContext({
    document: {
      readyState: "complete",
      getElementById: getElement,
      querySelectorAll() { return []; },
      createElement() { return makeElement(); },
      addEventListener() {},
    },
    window: { lucide: null, location: { href: "" }, history: { pushState(a, b, url) { this.url = url; } } },
    sessionStorage: {
      getItem(key) { return stored.get(key) ?? null; },
      setItem(key, value) { stored.set(key, value); },
      removeItem(key) { stored.delete(key); },
    },
    API: {
      waitForSessionVerification: async () => true,
      getCustomerDetails(id) { calls.push(["registered", id]); return pendingPets; },
      getUnregisteredCustomerDetails(id) { calls.push(["unregistered", id]); return pendingPets; },
    },
  });

  const renderedSteps = [];
  const stubs = {
    "./walk-in-services-step.js": { renderWalkInServicesStep() { renderedSteps.push("grooming"); } },
    "./walk-in-clinic-complaint-step.js": { renderWalkInClinicComplaintStep() { renderedSteps.push("clinic"); } },
    "./breed-combobox.js": { createBreedCombobox: combobox },
    "./breed-coat-combobox.js": { createBreedCoatCombobox: combobox },
    "./fixed-option-combobox.js": { createFixedOptionCombobox: combobox },
    "./color-combobox.js": { createColorCombobox: ({ input }) => ({ ...combobox({ input }), getValue: () => input.value.trim().replace(/\s+/g, " ") }) },
    "../services/booking-draft-service.js": { formatPetSizeLabel: (value) => value },
    "./pet-weight-size.js": weightTools,
  };
  const main = new vm.SourceTextModule(source + '\nexport { handleAddPetSubmit, syncWeightAndSize, handleBack, handleNext };', { context, identifier: "walk-in-pet-step.js" });
  await main.link((specifier) => {
    if (specifier.startsWith("./pet-selection-tabs.js")) {
      return new vm.SourceTextModule(tabsSource, { context, identifier: specifier });
    }
    const exports = stubs[specifier.split("?")[0]];
    assert.ok(exports, `Unexpected import: ${specifier}`);
    return new vm.SyntheticModule(Object.keys(exports), function initialize() {
      for (const [name, value] of Object.entries(exports)) this.setExport(name, value);
    }, { context, identifier: specifier });
  });
  await main.evaluate();

  const ui = (id) => getElement(id);
  if (apiPets) {
    resolvePets({ customer: { pets: apiPets } });
    await new Promise((resolve) => setImmediate(resolve));
  }
  return { ui, calls, resolvePets, rejectPets, handlers: main.namespace, window: context.window, renderedSteps, stored };
}

const savedPet = { id: 42, petName: "Mochi", species: "Dog", breed: "Poodle", size: "small", sizeVerified: true };
const registeredOwner = ownerDraft({ recordType: "registered", id: 7, pets: [savedPet] }, "grooming");
assert.equal(registeredOwner.ownerRecordType, "registered");
assert.equal(registeredOwner.customerUserId, 7);
const registered = await runStep(registeredOwner);
assert.deepEqual(registered.calls, [["registered", 7]]);
assert.equal(registered.ui("petSelectionTabs").classList.contains("hidden"), false);
assert.equal(registered.ui("existingPetsSection").classList.contains("hidden"), false);
assert.equal(registered.ui("addPetSection").classList.contains("hidden"), true);
assert.equal(registered.ui("showExistingPetBtn").getAttribute("aria-pressed"), "true");
assert.equal(registered.ui("existingPetsLoading").classList.contains("hidden"), false);
assert.match(registered.ui("existingPetCards").innerHTML, /Mochi/);
registered.resolvePets({ customer: { pets: [savedPet] } });
await new Promise((resolve) => setImmediate(resolve));
assert.equal(registered.ui("existingPetsLoading").classList.contains("hidden"), true);
assert.match(registered.ui("existingPetCards").innerHTML, /Mochi/);

const failedRefresh = await runStep(registeredOwner);
failedRefresh.rejectPets(new Error("Network unavailable"));
await new Promise((resolve) => setImmediate(resolve));
assert.equal(failedRefresh.ui("existingPetsLoading").classList.contains("hidden"), true);
assert.match(failedRefresh.ui("existingPetCards").innerHTML, /Mochi/);

const unregisteredOwner = ownerDraft({ recordType: "unregistered", id: 9, pets: [] }, "clinic");
assert.equal(unregisteredOwner.ownerRecordType, "unregistered");
assert.equal(unregisteredOwner.unregisteredCustomerId, 9);
const unregistered = await runStep(unregisteredOwner, { apiPets: [savedPet] });
assert.deepEqual(unregistered.calls, [["unregistered", 9]]);
assert.equal(unregistered.ui("showExistingPetBtn").getAttribute("aria-pressed"), "true");
assert.match(unregistered.ui("existingPetCards").innerHTML, /Mochi/);
assert.equal(unregistered.ui("selectedPetCount").textContent, "0 / 1 selected");

const newGroomingOwner = ownerDraft(null, "grooming");
assert.equal(newGroomingOwner.ownerRecordType, "new");
const newOwner = await runStep(newGroomingOwner, { draft: { pets: [{
  id: "new-pet", isNew: true, petName: "Fluffy", petType: "Cat", breed: "",
  furType: "Long", weight: "", size: "small", color: "Brown/White", medicalNotes: "",
}] } });
assert.deepEqual(newOwner.calls, []);
assert.equal(newOwner.ui("petSelectionTabs").classList.contains("hidden"), true);
assert.equal(newOwner.ui("addPetSection").classList.contains("hidden"), false);
assert.equal(newOwner.ui("existingPetsSection").classList.contains("hidden"), true);
assert.equal(newOwner.ui("nextBtn").disabled, false);
const selectedCard = newOwner.ui("selectedPetCards").children[0].innerHTML;
assert.match(selectedCard, /Fluffy/);
assert.match(selectedCard, /Color:.*Brown\/White/);
assert.match(selectedCard, /Fur Type:.*Long/);
assert.doesNotMatch(selectedCard, /Allergies &amp; Special Care Notes|Weight:|Breed:/);

const newClinicOwner = await runStep(ownerDraft(null, "clinic"));
assert.deepEqual(newClinicOwner.calls, []);
assert.equal(newClinicOwner.ui("petSelectionTabs").classList.contains("hidden"), true);
assert.equal(newClinicOwner.ui("addPetSection").classList.contains("hidden"), false);
assert.equal(newClinicOwner.ui("selectedPetCount").textContent, "0 / 1 selected");

for (const appointmentType of ["grooming", "clinic"]) {
  const flow = await runStep(ownerDraft(null, appointmentType), { draft: { pets: [{
    id: "new-pet", isNew: true, petName: "Fluffy", petType: "Cat", breed: "",
    furType: "Long", weight: "", size: "small", medicalNotes: "",
  }] } });
  flow.ui("backBtn").events.click();
  assert.equal(flow.window.location.href, `./walk-in-booking.html?flow=${appointmentType}`);
  assert.equal(flow.ui("ownerBackLink").events.click, undefined, "Home retains native anchor navigation");
  flow.handlers.handleNext();
  assert.deepEqual(flow.renderedSteps, [appointmentType], "Pet step enters only the originating workflow");
  assert.equal(flow.window.history.url, appointmentType === "clinic"
    ? "./walk-in-pet-details.html?step=clinic-complaint"
    : "./walk-in-grooming-services.html");
}

const directClinic = await runStep({ ...ownerDraft(null, "clinic"), directEntry: "clinic" });
const directDraft = [...directClinic.stored];
directClinic.ui("backBtn").events.click();
assert.equal(directClinic.window.location.href, "./clinic.html", "Direct clinic entry retains its bottom Back shortcut");
assert.equal(directClinic.ui("ownerBackLink").events.click, undefined);
assert.deepEqual([...directClinic.stored], directDraft, "Back preserves the direct-entry draft");

for (const appointmentType of ["grooming", "clinic"]) {
  for (const weight of ["4.780", "4.200000", "10.555"]) {
    const flow = await runStep(ownerDraft(null, appointmentType), { draft: {
      pets: [], newPetForm: { petType: "Dog", petName: "Decimal puppy", breed: "Mixed Breed / Aspin", color: "Light Brown", medicalNotes: "Shampoo allergy", weight },
    } });
    assert.equal(flow.ui("color").value, "Light Brown");
    assert.equal(flow.ui("medicalNotes").value, "Shampoo allergy");
    assert.equal(flow.ui("weight").value, weight);
    assert.equal(flow.ui("weightError").textContent, "Enter weight using no more than 2 decimal places.");
    await flow.handlers.handleAddPetSubmit({ preventDefault() {} });
    assert.equal(flow.ui("weight").value, weight);
    assert.equal(flow.ui("weight").focused, true, "Submission focuses the first invalid field");
    assert.equal(flow.ui("selectedPetCards").children.length, 0);
    flow.ui("weight").value = "4.10";
    flow.handlers.syncWeightAndSize();
    assert.equal(flow.ui("weightError").textContent, "");
    assert.equal(flow.ui("size").value, "Small");
    assert.equal(flow.ui("size").disabled, true);
    await flow.handlers.handleAddPetSubmit({ preventDefault() {} });
    assert.equal(flow.ui("selectedPetCards").children.length, 1);
    const saved = JSON.parse(flow.stored.get("walkInPetStep")).pets[0];
    assert.equal(saved.color, "Light Brown");
    assert.equal(saved.medicalNotes, "Shampoo allergy");
  }
}
console.log("Walk-in customer selection, restored precision errors, blocked submissions and exact size derivation passed.");
