import assert from "node:assert/strict";
import { mountClinicVisitReasonFields } from "../scripts/components/clinic-visit-reason-fields.js";
import { CLINIC_CONCERNS, validateClinicVisitReason } from "../scripts/services/clinic-visit-service.js";

function classList() {
  const values = new Set();
  return {
    toggle(name, enabled) {
      if (enabled) values.add(name);
      else values.delete(name);
    },
    contains(name) { return values.has(name); },
  };
}

function createContainer() {
  const buttons = CLINIC_CONCERNS.map((concern) => {
    const listeners = {};
    return {
      dataset: { clinicConcern: concern },
      classList: classList(),
      attributes: {},
      addEventListener(name, callback) { listeners[name] = callback; },
      setAttribute(name, value) { this.attributes[name] = value; },
      click() { listeners.click(); },
      focus() { this.focused = true; },
    };
  });
  const details = { value: "", focus() { this.focused = true; } };
  const error = { textContent: "", classList: classList() };
  return {
    buttons,
    error,
    innerHTML: "",
    querySelectorAll() { return buttons; },
    querySelector(selector) {
      return selector === "#clinicVisitDetails" ? details : error;
    },
  };
}

const container = createContainer();
const fields = mountClinicVisitReasonFields(container, "Mochi");
assert.match(container.innerHTML, /What brings Mochi in today\?/);
assert.match(container.innerHTML, /Common concerns/);
assert.match(container.innerHTML, /Select all that apply/);
assert.equal(fields.validate(), false);
assert.equal(container.error.textContent, "Select at least one common concern.");

const vomiting = container.buttons.find((button) => button.dataset.clinicConcern === "Vomiting");
const diarrhea = container.buttons.find((button) => button.dataset.clinicConcern === "Diarrhea");
vomiting.click();
diarrhea.click();
assert.deepEqual(fields.concerns(), ["Vomiting", "Diarrhea"]);
assert.equal(vomiting.attributes["aria-pressed"], "true");
assert.equal(vomiting.classList.contains("bg-[#315b7e]"), true);
assert.equal(fields.validate(), true);

vomiting.click();
assert.deepEqual(fields.concerns(), ["Diarrhea"]);
assert.equal(vomiting.attributes["aria-pressed"], "false");
assert.equal(vomiting.classList.contains("bg-white"), true);

const restored = createContainer();
const restoredFields = mountClinicVisitReasonFields(restored, "<Mochi>", ["Other", "Vaccination"], "Started today");
assert.match(restored.innerHTML, /What brings &lt;Mochi&gt; in today\?/);
assert.deepEqual(restoredFields.concerns(), ["Vaccination", "Other"]);
assert.equal(restoredFields.details.value, "Started today");
assert.equal(validateClinicVisitReason(["Other", "Other"]), "Select valid common concerns.");
assert.equal(validateClinicVisitReason(["Other"], "🐾".repeat(300)), "");
assert.equal(validateClinicVisitReason(["Other"], "x".repeat(301)), "Maximum 300 characters allowed.");
