import assert from "node:assert/strict";
import { groomingPetConfirmation } from "../scripts/components/booking-confirmed-step.js";
import { clinicReasonConfirmation, initClinicVisitConfirmation } from "../scripts/components/clinic-visit-confirmed.js";
import {
  confirmationFields, ownerConfirmationFields, formatConfirmationTimestamp,
  renderConfirmation,
} from "../scripts/components/confirmation-presentation.js";

const concerns = ["Routine check-up", "Vaccination"];
const reason = clinicReasonConfirmation({ common_concerns: concerns, chief_complaint: concerns.join(", ") + "\nAnnual vaccination and check-up." });
assert.match(reason, /Routine check-up · Vaccination/);
assert.match(reason, /Additional details<\/dt><dd>Annual vaccination and check-up\./);
assert.equal((reason.match(/Routine check-up/g) || []).length, 1);
assert.match(clinicReasonConfirmation({ common_concerns: concerns, chief_complaint: concerns.join(", ") }), /Additional details<\/dt><dd>None provided/);
assert.match(clinicReasonConfirmation({ common_concerns: concerns, chief_complaint: "Free text without a summary" }), /Free text without a summary/);
assert.match(clinicReasonConfirmation({ chief_complaint: "Legacy details" }), /Legacy details/);
assert.match(clinicReasonConfirmation({ common_concerns: concerns, chief_complaint: "Vaccination follow-up" }), /Vaccination follow-up/);

const grooming = groomingPetConfirmation({ petName: "<Mochi>", petType: "dog", breed: "Shih Tzu", size: "small", weight: 5, sizeVerified: true }, {
  servicePackage: "regular_dog_grooming", groomingPreference: "summer_cut",
  groomingEstimate: { preferenceLabel: "Summer Cut", formatted: "1 hr 15 min" },
  alaCarteServices: ["anal_sac_draining"], pricing: { minAmount: 500, maxAmount: 650 },
  specialInstructions: "No <perfume>",
});
assert.match(grooming, /&lt;Mochi&gt;/);
assert.match(grooming, /Small · Clinic verified/);
assert.match(grooming, /Weight<\/dt><dd>5 kg/);
assert.match(grooming, /Grooming preference<\/dt><dd>Summer Cut/);
assert.match(grooming, /Estimated grooming time<\/dt><dd>1 hr 15 min/);
assert.match(grooming, /₱500 - ₱650/);
assert.match(grooming, /No &lt;perfume&gt;/);
assert.doesNotMatch(grooming, /<button|aria-pressed/);
const missing = groomingPetConfirmation({ petName: "Mochi", size: "small" });
assert.doesNotMatch(missing, /Estimated from weight|Clinic verified|Weight<\/dt>|Grooming preference/);
assert.match(groomingPetConfirmation({ size: "small", sizeVerified: false }), /Small · Estimated/);
assert.match(ownerConfirmationFields({ name: "Maria Santos", phone: "639171234567" }), /Maria Santos/);
assert.match(ownerConfirmationFields({ name: "Maria Santos", phone: "639171234567" }), /0917-123-4567/);
assert.match(confirmationFields([["Queue number", 0]]), /<dd>0<\/dd>/);
assert.match(formatConfirmationTimestamp("2026-10-10T02:30:00Z"), /10:30 AM/);
assert.equal(formatConfirmationTimestamp("invalid"), "");

let focused = false;
let printCallback;
let printed = false;
let restoreTitle;
globalThis.document = { body: { classList: { add() {} } }, title: "Original" };
globalThis.window = {
  addEventListener(name, callback) { if (name === "afterprint") restoreTitle = callback; },
  removeEventListener() {}, print() { printed = true; }, setTimeout() {},
};
const root = { innerHTML: "", querySelector(selector) {
  return selector === "#confirmationHeading" ? { focus() { focused = true; } } : { addEventListener(name, callback) { printCallback = callback; } };
} };
document.body.dataset = {confirmationContext: 'walk-in'};
document.getElementById = () => root;
globalThis.API = {waitForSessionVerification: async () => true};
globalThis.sessionStorage = {getItem: () => JSON.stringify({appointment_reference: 'CL-20261024-004',
  status: 'checked_in', queue_number: 4, checked_in_at: '2026-10-24T02:30:00Z',
  owner: {name: 'Ana Santos'}, pet: {name: 'Mochi', species: 'cat'}})};
await initClinicVisitConfirmation();
assert.match(root.innerHTML, /Queue number<\/dt><dd>4/);
assert.match(root.innerHTML, /Patient name<\/dt><dd>Mochi/);
assert.match(root.innerHTML, /Current status<\/dt><dd>Checked In/);
assert.match(root.innerHTML, /Checked in at<\/dt><dd>October 24, 2026 at 10:30 AM/);
assert.doesNotMatch(root.innerHTML, /<dd>Active/);
assert.match(root.innerHTML, /Print Confirmation/);
assert.match(root.innerHTML, /href="\.\/clinic\.html\?tab=active-cases">Go to Active Cases<\/a>/);
assert.equal((root.innerHTML.match(/class="confirmation-button\b/g) || []).length, 2);
assert.doesNotMatch(root.innerHTML, /Register Another Walk-in|confirmation-another|walk-in-booking\.html/);
assert.equal(focused, true);
printCallback();
assert.equal(printed, true);
restoreTitle();
assert.equal(document.title, "Clinic Walk-in Checked In");
renderConfirmation(root, { title: "Grooming Walk-in Checked In", printTitle: "GROOMING WALK-IN CHECK-IN CONFIRMATION", message: "Checked in", reference: "GR-2", status: "Checked In", walkIn: true, queueNumber: 27, sections: grooming, primaryHref: "./appointments.html", primaryLabel: "Go to Grooming" });
assert.match(root.innerHTML, /Queue number<\/dt><dd>27/);
assert.match(root.innerHTML, /href="\.\/appointments\.html">Go to Grooming<\/a>/);
assert.match(root.innerHTML, /Print Confirmation/);
assert.equal((root.innerHTML.match(/class="confirmation-button\b/g) || []).length, 2);
assert.doesNotMatch(root.innerHTML, /Register Another Walk-in|confirmation-another|walk-in-booking\.html/);
console.log("Confirmation regression checks passed.");
