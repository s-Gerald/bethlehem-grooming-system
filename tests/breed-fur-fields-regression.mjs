import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import vm from "node:vm";

// Only the catalogue request is replaced; exercise the actual shared controls.
const catalogue = JSON.parse(readFileSync(new URL("../scripts/data/breed-coat-options.json", import.meta.url)));
let releaseCatalogue;
const pendingCatalogue = new Promise(resolve => { releaseCatalogue = resolve; });
let catalogueRequests = 0;
globalThis.fetch = async () => {
  catalogueRequests++;
  return { ok: true, json: () => pendingCatalogue };
};
const { createBreedCombobox } = await import("../scripts/components/breed-combobox.js?pet-fields=20261026-v1");
const { createBreedCoatCombobox } = await import("../scripts/components/breed-coat-combobox.js?pet-fields=20261026-v1");
const { breedCoatCatalogueReady, getCoatOptions, OTHER_BREED, UNKNOWN_BREED, getMixedBreed } =
  await import("../scripts/components/breed-coat-catalogue.js?pet-fields=20261026-v1");

// Minimal DOM boundary, following the existing vanilla component regression tests.
function element(value = "") {
  const listeners = new Map();
  const classes = new Set();
  const attributes = new Map();
  return {
    value, disabled: false, readOnly: true, dataset: {}, children: [], style: {},
    removeAttribute(name) { attributes.delete(name); },
    scrollIntoView() { this.scrolledIntoView = true; },
    classList: {
      add(...names) { names.forEach(name => classes.add(name)); },
      remove(...names) { names.forEach(name => classes.delete(name)); },
      contains(name) { return classes.has(name); },
      toggle(name, force = !classes.has(name)) {
        if (force) classes.add(name); else classes.delete(name);
      },
    },
    set innerHTML(value) { this.children = []; },
    setAttribute(name, value) { attributes.set(name, value); },
    getAttribute(name) { return attributes.get(name); },
    appendChild(child) { this.children.push(child); },
    addEventListener(name, listener) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(listener);
    },
    dispatchEvent(event) {
      for (const listener of listeners.get(event.type) || []) listener(event);
      return true;
    },
    focus() { document.activeElement = this; },
    contains(target) { return target === this || this.children.some(child => child.contains(target)); },
    closest(selector) {
      return selector === "[data-breed-value]" && this.dataset.breedValue !== undefined ? this
        : selector === "[data-coat-value]" && this.dataset.coatValue !== undefined ? this : null;
    },
    querySelectorAll() { return this.children.filter(child => child.dataset.breedValue || child.dataset.coatValue); },
  };
}

globalThis.document = { createElement: () => element(), addEventListener() {}, activeElement: null };
globalThis.window = { setTimeout };

function form({ species = "", breed = "", coat = "", integration } = {}) {
  const petTypeInput = element(species);
  const breedInput = element(breed);
  const coatInput = element(coat);
  const breedRoot = element();
  const breedList = element();
  const breedToggle = element();
  const coatRoot = element();
  const coatList = element();
  const coatToggle = element();
  const nodes = new Map();
  const getElementById = id => {
    if (!nodes.has(id)) nodes.set(id, element());
    return nodes.get(id);
  };
  for (const prefix of ["breed", "petBreed", "adminPetBreed"]) {
    nodes.set(`${prefix}Combobox`, breedRoot);
    nodes.set(`${prefix}Options`, breedList);
    nodes.set(`${prefix}DropdownButton`, breedToggle);
  }
  for (const prefix of ["furType", "petFurType", "adminPetFurType"]) {
    nodes.set(`${prefix}Combobox`, coatRoot);
    nodes.set(`${prefix}Options`, coatList);
    nodes.set(`${prefix}DropdownButton`, coatToggle);
  }
  const elements = { petType: petTypeInput, species: petTypeInput, breed: breedInput, furType: coatInput,
    petName: element(), birthdate: element(), color: element(), weight: element(), medicalNotes: element() };
  const context = {
    document: { getElementById }, elements, petBreed: breedInput, petSpecies: petTypeInput, petFurType: coatInput,
    createBreedCombobox, createBreedCoatCombobox, tools: { createBreedCombobox, createBreedCoatCombobox },
  };
  const source = integration && readFileSync(new URL(`../scripts/components/${integration}.js`, import.meta.url), "utf8");
  const fromIntegration = name => vm.runInNewContext(
    source.match(new RegExp(`${name}\\(\\{[\\s\\S]*?\\n\\s*\\}\\)`))[0], context,
  );
  const breedControl = integration ? fromIntegration("createBreedCombobox") : createBreedCombobox({
    root: breedRoot, input: breedInput, listbox: breedList, toggleButton: breedToggle,
    petTypeInput, getPetType: () => petTypeInput.value,
  });
  const coatControl = integration ? fromIntegration("createBreedCoatCombobox") : createBreedCoatCombobox({
    root: coatRoot, breedInput, petTypeInput, input: coatInput, listbox: coatList, toggleButton: coatToggle,
  });
  const change = (input, value, type = "change") => {
    input.value = value;
    input.dispatchEvent(new Event(type));
  };
  function chooseBreed(value) {
    const option = element();
    option.dataset.breedValue = value;
    breedRoot.dispatchEvent({ type: "click", target: option });
  }
  function chooseCoat(value) {
    coatToggle.dispatchEvent(new Event("click"));
    const option = coatList.children.find(child => child.dataset.coatValue === value);
    assert.ok(option, `Fur option ${value} should be offered`);
    coatRoot.dispatchEvent({ type: "click", target: option });
  }
  return { petTypeInput, breedInput, coatInput, breedToggle, coatToggle, breedList, breedControl, coatControl,
    chooseBreed, chooseCoat, change, elements, context, source };
}

const loadingSavedPet = form({ species: "Cat", breed: "Bengal", coat: "Longhair Coat" });
const loadingNewPet = form({ species: "Dog" });
loadingNewPet.chooseBreed("Beagle");
const loadingCustomPet = form({ species: "Cat" });
loadingCustomPet.chooseBreed(OTHER_BREED);
loadingCustomPet.change(loadingCustomPet.breedInput, "Custom cat", "input");
const loadingReopenedPet = form({ species: "Dog" });
loadingReopenedPet.chooseBreed("Beagle");
loadingReopenedPet.breedControl.setValue("Beagle");
loadingReopenedPet.coatControl.update({ preserveValue: true, autoSelect: false });
releaseCatalogue(catalogue);
await breedCoatCatalogueReady;

test("catalogue loading preserves saved edits and updates choices already made by the user", () => {
  assert.equal(loadingSavedPet.breedInput.value, "Bengal");
  assert.equal(loadingSavedPet.coatInput.value, "Longhair Coat");
  assert.equal(loadingNewPet.coatInput.value, "Smooth Short Coat");
  assert.equal(loadingCustomPet.coatInput.disabled, true);
  assert.equal(loadingCustomPet.coatInput.value, "");
  assert.equal(loadingReopenedPet.coatInput.value, "Smooth Short Coat");
});

test("no species disables both controls, and species enables only Breed", () => {
  const ui = form();
  assert.equal(ui.breedInput.disabled, true);
  assert.equal(ui.breedToggle.disabled, true);
  assert.equal(ui.coatInput.disabled, true);
  ui.change(ui.petTypeInput, "Dog");
  assert.equal(ui.breedInput.disabled, false);
  assert.equal(ui.coatInput.disabled, true);
  assert.deepEqual(getCoatOptions("Beagle", ""), []);
});

for (const [species, single, coat, multi, multiCoat] of [
  ["Dog", "French Bulldog", "Smooth Short Coat", "German Shepherd", "Long Stock Coat"],
  ["Cat", "Persian", "Long Dense Coat", "Bengal", "Longhair Coat"],
]) {
  test(`${species}: known single coats auto-select; multiple coats require a choice`, () => {
    const ui = form({ species });
    ui.chooseBreed(OTHER_BREED);
    assert.equal(ui.breedInput.readOnly, false);
    ui.change(ui.breedInput, single.slice(0, 3), "input");
    assert.ok(ui.breedList.children.some(child => child.dataset.breedValue === single));
    ui.chooseBreed(single);
    assert.equal(ui.coatInput.value, coat);
    assert.equal(ui.coatInput.disabled, false);
    ui.chooseBreed(multi);
    assert.equal(ui.coatInput.value, "");
    ui.chooseCoat(multiCoat);
    assert.equal(ui.coatInput.value, multiCoat);
    assert.equal(ui.coatControl.getValidationMessage(), "");
  });

  test(`${species}: Mixed and Unknown offer optional fur; custom breeds have no unsupported options`, () => {
    const ui = form({ species });
    for (const breed of [getMixedBreed(species), UNKNOWN_BREED]) {
      ui.chooseBreed(breed);
      assert.equal(ui.breedInput.readOnly, true);
      assert.equal(ui.coatInput.disabled, false);
      assert.equal(ui.coatInput.value, "");
      assert.equal(ui.coatControl.getValidationMessage(), "");
    }
    ui.chooseBreed(OTHER_BREED);
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.disabled, true);
    ui.change(ui.breedInput, "My custom breed", "input");
    assert.equal(ui.coatInput.disabled, true);
    assert.equal(ui.coatToggle.disabled, true);
    assert.equal(ui.coatInput.value, "");
    assert.equal(ui.coatControl.getValidationMessage(), "");
    assert.deepEqual(getCoatOptions("My custom breed", species), []);
    ui.change(ui.breedInput, "123", "input");
    assert.ok(ui.breedControl.getValidationMessage());
  });

  test(`${species}: opening and refreshing an edit preserves saved values until a dependency changes`, async () => {
    const ui = form({ species, breed: multi, coat: multiCoat });
    await Promise.resolve();
    assert.equal(ui.breedInput.value, multi);
    assert.equal(ui.coatInput.value, multiCoat);
    ui.change(ui.petTypeInput, species);
    ui.change(ui.breedInput, multi);
    assert.equal(ui.coatInput.value, multiCoat);
    ui.chooseBreed(single);
    assert.equal(ui.coatInput.value, coat);
    ui.change(ui.petTypeInput, species === "Dog" ? "Cat" : "Dog");
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.value, "");
    assert.equal(ui.coatInput.disabled, true);
    ui.change(ui.petTypeInput, "");
    assert.equal(ui.breedInput.disabled, true);
  });

  test(`${species}: typing a known breed applies its exact options`, () => {
    const ui = form({ species });
    ui.chooseBreed(OTHER_BREED);
    ui.change(ui.breedInput, ` ${single.toLowerCase()} `, "input");
    assert.equal(ui.coatInput.value, coat);
    ui.change(ui.breedInput, multi, "input");
    assert.equal(ui.coatInput.value, "");
    ui.chooseCoat(multiCoat);
    assert.equal(ui.coatInput.value, multiCoat);
  });
}

for (const [species, popular] of [
  ["Dog", ["Shih Tzu", "Pomeranian", "Golden Retriever", "Labrador Retriever", "Siberian Husky", "American Bully", "Poodle", "Chihuahua"]],
  ["Cat", ["Persian", "Siamese", "British Shorthair", "Maine Coon", "Ragdoll", "Bengal", "Domestic Shorthair", "Scottish Fold"]],
]) {
  test(`${species}: Other opens popular breeds, filters all matches, and clearing restores defaults`, () => {
    const ui = form({ species });
    ui.chooseBreed(OTHER_BREED);
    const values = () => ui.breedList.querySelectorAll().map(option => option.dataset.breedValue);
    assert.equal(document.activeElement, ui.breedInput);
    assert.equal(ui.breedInput.getAttribute("aria-expanded"), "true");
    assert.deepEqual(values().slice(0, 8), popular);
    ui.change(ui.breedInput, species === "Dog" ? "  SHIH   t " : " PERS ", "input");
    assert.deepEqual(values().filter(value => ![getMixedBreed(species), UNKNOWN_BREED, OTHER_BREED].includes(value)), [popular[0]]);
    ui.change(ui.breedInput, "", "input");
    assert.deepEqual(values().slice(0, 8), popular);
    ui.change(ui.breedInput, "a", "input");
    assert.ok(values().filter(value => ![getMixedBreed(species), UNKNOWN_BREED].includes(value)).length > 4);
  });
}

test("editable keyboard navigation retains focus, scrolls options into view, and only Enter selects", () => {
  const ui = form({ species: "Dog" });
  ui.chooseBreed(OTHER_BREED);
  const key = key => ui.breedInput.dispatchEvent({ type: "keydown", key, preventDefault() {} });
  for (let index = 0; index < 5; index++) key("ArrowDown");
  assert.equal(document.activeElement, ui.breedInput);
  assert.equal(ui.breedInput.value, "");
  const active = ui.breedList.children.find(option => option.id === ui.breedInput.getAttribute("aria-activedescendant"));
  assert.equal(active?.dataset.breedValue, "Siberian Husky");
  assert.equal(active.scrolledIntoView, true);
  assert.equal(active.getAttribute("aria-selected"), "true");
  key("Escape");
  assert.equal(ui.breedInput.getAttribute("aria-expanded"), "false");
  assert.equal(ui.breedInput.getAttribute("aria-activedescendant"), undefined);
  key("ArrowUp");
  key("Enter");
  assert.equal(ui.breedInput.value, UNKNOWN_BREED);
  assert.equal(ui.breedInput.getAttribute("aria-expanded"), "false");
});

test("fur requirement comes from saved breed and errors clear after correction", () => {
  const ui = form({ species: "Dog", breed: "golden retriever" });
  assert.equal(ui.coatInput.getAttribute("aria-required"), "true");
  assert.equal(ui.coatInput.getAttribute("aria-invalid"), "false");
  assert.equal(ui.coatControl.showValidation(), false);
  assert.equal(ui.coatControl.getValidationMessage(), "Select a fur type.");
  ui.chooseCoat("Wavy Coat");
  assert.equal(ui.coatInput.getAttribute("aria-invalid"), "false");
  ui.chooseBreed("My custom breed");
  assert.equal(ui.coatInput.value, "");
  assert.equal(ui.coatInput.getAttribute("aria-required"), "false");
  assert.equal(ui.coatControl.getValidationMessage(), "");
});

test("search ranks prefix matches first and pointer selection retains input focus", () => {
  const ui = form({ species: "Dog" });
  ui.chooseBreed(OTHER_BREED);
  ui.change(ui.breedInput, "sh", "input");
  assert.deepEqual(ui.breedList.children.slice(0, 4).map(option => option.dataset.breedValue),
    ["Shar Pei", "Shetland Sheepdog", "Shiba Inu", "Shih Tzu"]);
  const option = ui.breedList.children[3];
  let prevented = false;
  ui.breedList.dispatchEvent({ type: "pointerdown", target: option, preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  ui.chooseBreed(option.dataset.breedValue);
  assert.equal(document.activeElement, ui.breedInput);
  assert.equal(ui.breedInput.value, "Shih Tzu");
  assert.equal(ui.breedInput.getAttribute("aria-expanded"), "false");
  assert.equal(ui.coatControl.getValidationMessage(), "Select a fur type.");
});

test("restoring incomplete single-coat profiles fills the determined coat; unsupported stored custom coats clear", async () => {
  const single = form({ species: "Dog", breed: "Beagle" });
  const custom = form({ species: "Cat", breed: "Custom cat", coat: "Shorthair Coat" });
  await Promise.resolve();
  assert.equal(single.coatInput.value, "Smooth Short Coat");
  assert.equal(single.coatControl.getValidationMessage(), "");
  assert.equal(custom.coatInput.value, "");
  assert.equal(custom.coatInput.disabled, true);
  assert.equal(custom.coatControl.getValidationMessage(), "");
});

test("Beagle auto-selects and Dachshund leaves its multiple fur types unselected", () => {
  const ui = form({ species: "Dog" });
  ui.chooseBreed("Beagle");
  assert.equal(ui.coatInput.value, "Smooth Short Coat");
  ui.chooseBreed("Dachshund");
  assert.equal(ui.coatInput.value, "");
  ui.chooseCoat("Wirehaired Coat");
});

test("clearing a saved fur type on species change notifies form consumers", () => {
  const ui = form({ species: "Dog", breed: "Beagle", coat: "Smooth Short Coat" });
  const changes = [];
  ui.coatInput.addEventListener("change", () => changes.push(ui.coatInput.value));
  ui.change(ui.petTypeInput, "Cat");
  assert.deepEqual(changes, [""]);
});

test("an edit retains a coat still available after a breed change", () => {
  const ui = form({ species: "Cat", breed: "Bengal", coat: "Longhair Coat" });
  ui.chooseBreed("American Curl");
  assert.equal(ui.coatInput.value, "Longhair Coat");
  assert.equal(ui.coatControl.getValidationMessage(), "");
});

for (const integration of ["my-pets", "admin-customers", "booking-pet-step", "walk-in-pet-step"]) {
  test(`${integration}: actual field wiring enforces the species dependency`, () => {
    const ui = form({ integration });
    assert.equal(ui.breedInput.disabled, true);
    ui.change(ui.petTypeInput, "Dog");
    assert.equal(ui.breedInput.disabled, false);
    ui.chooseBreed("Beagle");
    assert.equal(ui.coatInput.value, "Smooth Short Coat");
    ui.change(ui.petTypeInput, "Cat");
    assert.equal(ui.breedInput.value, "");
    assert.equal(ui.coatInput.value, "");
    ui.chooseBreed("Persian");
    assert.equal(ui.coatInput.value, "Long Dense Coat");
  });

  for (const [species, breed, coat] of [
    ["Dog", "Beagle", "Smooth Short Coat"], ["Dog", "German Shepherd", "Long Stock Coat"],
    ["Dog", "Mixed Breed / Aspin", "Wire Coat"], ["Dog", UNKNOWN_BREED, "Wavy Coat"],
    ["Dog", "Custom dog", ""],
    ["Cat", "Persian", "Long Dense Coat"], ["Cat", "Bengal", "Longhair Coat"],
    ["Cat", "Mixed Breed / Puspin", "Rex Coat"], ["Cat", UNKNOWN_BREED, "Fine Down Coat"],
    ["Cat", "Custom cat", ""],
    ["Dog", "German Shepherd", ""], ["Cat", "Bengal", ""],
  ]) {
    test(`${integration}: restores ${species} / ${breed} / ${coat || "unset fur"} and preserves it on repeated species events`, async () => {
      const ui = form({ integration });
      const petTypeCombobox = { setValue(value) { ui.petTypeInput.value = value; } };
      const noop = () => {};
      const weightTools = { breedCoatCatalogueReady, initializeWeightField: noop, formatSavedWeight: () => "",
        getSizeForWeight: noop };
      const shared = { ...ui.context, ...weightTools, breedCombobox: ui.breedControl, breedCoatCombobox: ui.coatControl,
        petTypeCombobox, colorCombobox: { setValue: noop }, genderCombobox: { setValue: noop }, sizeCombobox: { setValue: noop },
        syncWeightAndSize: noop, setStoredSize: noop };
      if (integration === "my-pets") {
        const handler = ui.source.slice(ui.source.indexOf("async function openEditModal("), ui.source.indexOf("  function showModal()"));
        const open = vm.runInNewContext(`(${handler.trim()})`, {
          ...shared, allPets: [{ pet_id: 1, species, breed, fur_type: coat }], editingPet: null,
          syncEditVerifiedIndicators: noop, petModalTitle: element(), petBirthdate: element(), petWeight: element(),
          normalizeSpecies: value => value, hideFormError: noop, showModal: noop,
        });
        await open(1);
        await open(1);
      } else if (integration === "admin-customers") {
        const handler = ui.source.slice(ui.source.indexOf("    async startEditPet()"), ui.source.indexOf("    cancelEditPet()"));
        const edit = vm.runInNewContext(`({${handler}}).startEditPet`, {
          adminCustomerPetFields: { tools: weightTools, elements: ui.elements,
            controls: { color: { setValue: noop }, species: petTypeCombobox, gender: shared.genderCombobox, breed: ui.breedControl, furType: ui.coatControl } },
        });
        const owner = { petModal: { pet: { species, breed, furType: coat } }, initializePetFormFields: async () => true,
          normalizePetSpecies: value => value, syncPetWeightAndSize: noop, setStoredPetSize: noop, refreshIcons: noop };
        await edit.call(owner);
        await edit.call(owner);
      } else {
        const name = integration === "booking-pet-step" ? "restoreAddPetFormDraft" : "restorePetStepDraft";
        const handler = ui.source.slice(ui.source.indexOf(`function ${name}()`), ui.source.indexOf("function validatePetForm("));
        const draft = { newPetForm: { petType: species, breed, furType: coat } };
        const restore = vm.runInNewContext(`(${handler.trim()})`, {
          ...shared, state: {}, readStepTwoDraft: () => draft, readPetStepDraft: () => draft,
        });
        restore();
        restore();
      }
      assert.equal(ui.breedInput.value, breed);
      assert.equal(ui.coatInput.value, coat);
      assert.equal(ui.breedInput.disabled, false);
      ui.change(ui.petTypeInput, species);
      assert.equal(ui.breedInput.value, breed);
      assert.equal(ui.coatInput.value, coat);
      ui.change(ui.petTypeInput, species === "Dog" ? "Cat" : "Dog");
      assert.equal(ui.breedInput.value, "");
      assert.equal(ui.coatInput.value, "");
    });
  }
}

test("all form instances reuse the catalogue request without fetching on input", () => {
  assert.equal(catalogueRequests, 1);
});
