import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { clinicVisitFields, healthDate } from "../scripts/components/customer-health-records.js";

const page = fs.readFileSync(new URL("../pages/client/grooming-history.html", import.meta.url), "utf8");
const script = page.match(/<script type="module">([\s\S]*?)<\/script>/)[1]
  .replace(/^\s*import .*?;\s*/m, "");
const clinic = {
  appointment_reference: "CL-20261015-001", appointment_date: "2026-10-15", status: "completed",
  pet: { pet_id: 1, pet_name: "Mochi", species: "cat" }, chief_complaint: "Reduced appetite",
  diagnosis: "Gastritis", treatment_given: "Fluids administered", follow_up_date: "2026-10-20",
  prescriptions: [{ medicine: "Omeprazole", dose: "1 capsule", frequency: "Once daily", instructions: "Before meals" }],
  vet_notes: "PRIVATE", findings: "INTERNAL", attachments: ["radiograph.png"],
};
const booking = {
  booking_id: 4, booking_reference: "GR-20261016-004", booking_date: "2026-10-16", status: "archived",
  number_of_pets: 2, paid: true,
  pets: [
    { booking_pet_id: 41, pet_name: "Mochi", species: "cat", services: [{ service_name: "Selected service" }] },
    { booking_pet_id: 42, pet_name: "Bruno", species: "dog", services: [{ service_name: "Bath" }] },
  ],
  payment_summary: { status: "paid", payment_method_label: "Cash", pets: [
    { booking_pet_id: 41, final_pet_charge: 800, service_breakdown: [{ label: "Full grooming", price_at_booking: 800 }] },
    { booking_pet_id: 42, final_pet_charge: 500, service_breakdown: [{ label: "Bath", price_at_booking: 500 }] },
  ] },
};

async function run({ failClinic = false, failGrooming = false, empty = false } = {}) {
  const elements = new Map();
  function element(id) {
    if (elements.has(id)) return elements.get(id);
    const classes = new Set();
    const item = {
      value: id === "historyServiceFilter" ? "all" : "", innerHTML: "", textContent: "", disabled: false,
      listeners: {}, attributes: {},
      classList: { add: (...names) => names.forEach((name) => classes.add(name)), remove: (...names) => names.forEach((name) => classes.delete(name)), contains: (name) => classes.has(name), toggle() {} },
      addEventListener(name, callback) { this.listeners[name] = callback; },
      setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute() {},
      querySelectorAll: () => [], querySelector: () => null, focus() { this.focused = true; },
    };
    elements.set(id, item);
    return item;
  }
  let clinicFails = failClinic;
  const calls = [];
  const context = vm.createContext({
    Intl, Date, console, clinicVisitFields, healthDate,
    document: { getElementById: element, body: element("body"), addEventListener() {} },
    window: { matchMedia: () => ({ matches: false, addEventListener() {} }), requestAnimationFrame() {} },
    API: {
      async getBookingHistory() { if (failGrooming) throw Error("Offline"); return { history: empty ? [] : [booking] }; },
      async getCustomerClinicHistory() { if (clinicFails) throw Error("Offline"); return { medical_records: empty ? [] : [clinic] }; },
      async loadCustomerData(endpoint, load, render) { calls.push(endpoint); const data = await load(); render(data); },
    },
  });
  vm.runInContext(script, context);
  await vm.runInContext("loadHistory()", context);
  return { element, context, calls, restoreClinic() { clinicFails = false; } };
}

assert.deepEqual(clinicVisitFields({ vet_notes: "PRIVATE", vitals: { heart_rate_bpm: 120 } }), []);
assert.equal(healthDate(null), "Not recorded");
assert.match(clinicVisitFields(clinic).find(([label]) => label === "Medications")[1], /Omeprazole.*Once daily/);

// Exercise the pet-focused renderers with sparse and complete clinic data.
const profileSource = fs.readFileSync(new URL("../scripts/components/pet-details.js", import.meta.url), "utf8");
const profileContext = vm.createContext({
  clinicVisitFields, healthDate,
  isMissing: (value) => value === null || value === undefined || String(value).trim() === "",
  escapeHtml: (value) => String(value ?? "").replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;"),
  titleCase: (value) => String(value).replace(/\b\w/g, (letter) => letter.toUpperCase()),
  formatWeight: (value) => `${value} kg`, displayValue: (value) => value ?? "Not provided.",
});
vm.runInContext(profileSource.slice(profileSource.indexOf("  const renderMedicalRecord ="), profileSource.indexOf("  const loadMedicalRecords =")), profileContext);
vm.runInContext(profileSource.slice(profileSource.indexOf("  const vaccinationStatusDetails ="), profileSource.indexOf("  const loadVaccinations =")), profileContext);
const healthVisit = vm.runInContext(`renderMedicalRecord(${JSON.stringify({ ...clinic, vitals: { weight_kg: 4.25, temperature_c: 38.5, heart_rate_bpm: 120 } })})`, profileContext);
assert.match(healthVisit, /Gastritis|Fluids administered|Omeprazole|4.25 kg/);
assert.doesNotMatch(healthVisit, /38.5|120|PRIVATE|INTERNAL|radiograph|Vital Signs/);
const sparseVisit = vm.runInContext('renderMedicalRecord({ appointment_date: "2026-10-15", status: "completed" })', profileContext);
assert.doesNotMatch(sparseVisit, /Reason for visit|Diagnosis|Care provided|Medications|Follow-up|Not provided/);
const vaccination = vm.runInContext(`renderVaccinationRecord(${JSON.stringify({ vaccine_name: "Rabies", due_status: "current", administered_date: "2026-10-01", next_due_date: "2027-10-01", manufacturer: "PRIVATE", dose_amount: 1, route: "subcutaneous", batch_number: "BATCH-001" })})`, profileContext);
assert.match(vaccination, /Rabies|Current|October 1, 2026|October 1, 2027/);
assert.doesNotMatch(vaccination, /PRIVATE|subcutaneous|BATCH-001|Manufacturer|Route|Dose|Batch/);

const ui = await run();
const list = () => ui.element("historyList").innerHTML;
assert.equal(ui.element("historySummary").textContent, "3 records found");
assert.equal(ui.calls.length, 2, "One request per service, independent of the number of pets");
assert.ok(list().indexOf("Grooming") < list().indexOf("Clinic"), "Events sort by date");
assert.doesNotMatch(list(), /Gastritis|Fluids administered|Omeprazole|PRIVATE|INTERNAL|radiograph/);

ui.element("historyServiceFilter").value = "clinic";
ui.element("historyServiceFilter").listeners.change();
assert.equal(ui.element("historySummary").textContent, "1 record found");
assert.doesNotMatch(list(), /Grooming|Bruno/);
ui.element("historySearch").value = "CL-20261015";
ui.element("historySearch").listeners.input();
assert.match(list(), /Mochi/);
ui.element("historyDateFilter").value = "2026-10-16";
ui.element("historyDateFilter").listeners.change();
assert.match(list(), /No history found/);
ui.element("historyClearFilters").listeners.click();
assert.equal(ui.element("historyServiceFilter").value, "all");
assert.equal(ui.element("historySummary").textContent, "3 records found");
ui.element("historySearch").value = "Bruno";
ui.element("historySearch").listeners.input();
assert.match(list(), /Bruno/);
assert.doesNotMatch(list(), /Mochi/);

const trigger = { dataset: { historyType: "clinic", historyKey: clinic.appointment_reference }, focus() { this.focused = true; } };
ui.element("historyList").listeners.click({ target: { closest: () => trigger } });
assert.match(ui.element("historyModalContent").innerHTML, /Gastritis|Omeprazole/);
assert.doesNotMatch(ui.element("historyModalContent").innerHTML, /PRIVATE|INTERNAL|radiograph|Full grooming|Bath|Vaccinations/);
vm.runInContext("closeHistoryModal()", ui.context);
assert.equal(trigger.focused, true);
const groomingDetails = vm.runInContext(`buildHistoryModalContent(${JSON.stringify(booking)}, ${JSON.stringify(booking.pets[0])})`, ui.context);
assert.match(groomingDetails, /Full grooming/);
assert.doesNotMatch(groomingDetails, /800|500|Payment Method|Total|Reviewed adjustment|Gastritis|Bath/);
assert.doesNotMatch(groomingDetails, /bg-portal-active/);
const preferredSession = structuredClone(booking);
preferredSession.pets[0].grooming_preference_label = "Summer Cut";
preferredSession.payment_summary.pets[0].service_breakdown = [
  { label: "Nail Clipping", service_kind: "ala_carte" },
  { label: "Regular Dog Grooming", service_kind: "package" },
  { label: "Ear Cleaning", service_kind: "ala_carte" },
];
const preferredDetails = vm.runInContext(`buildHistoryModalContent(${JSON.stringify(preferredSession)}, ${JSON.stringify(preferredSession.pets[0])})`, ui.context);
assert.match(preferredDetails, /Regular Dog Grooming<div[^>]*><span[^>]*>Summer Cut<\/span><\/div><\/li>/);
assert.doesNotMatch(preferredDetails, /summer_cut/);
assert.equal(preferredDetails.split(">Summer Cut</span>").length - 1, 1);
const siblingDetails = vm.runInContext(`buildHistoryModalContent(${JSON.stringify(preferredSession)}, ${JSON.stringify(preferredSession.pets[1])})`, ui.context);
assert.doesNotMatch(siblingDetails, /Summer Cut|summer_cut/);
const preferredCard = vm.runInContext(`buildHistoryCard(${JSON.stringify({ type: "grooming", booking: preferredSession, pet: preferredSession.pets[0] })})`, ui.context);
assert.doesNotMatch(preferredCard, /Summer Cut|summer_cut/);
const laterPet = { ...preferredSession.pets[0], grooming_preference_label: "Semi-Kalbo" };
const laterDetails = vm.runInContext(`buildHistoryModalContent(${JSON.stringify(preferredSession)}, ${JSON.stringify(laterPet)})`, ui.context);
assert.match(laterDetails, />Semi-Kalbo<\/span>/);
assert.doesNotMatch(laterDetails, /Summer Cut|semi_kalbo/);
const escaped = vm.runInContext(`buildClinicHistoryDetails(${JSON.stringify({ ...{ type: "clinic", record: clinic }, pet: { pet_name: "<img onerror=alert(1)>" }, reference: "<visit>", date: "2026-10-15" })})`, ui.context);
assert.match(escaped, /&lt;img/);
assert.doesNotMatch(escaped, /<img/);

const partial = await run({ failClinic: true });
assert.match(partial.element("historyList").innerHTML, /Full grooming/);
assert.equal(partial.element("historyLoadError").classList.contains("hidden"), false);
assert.match(partial.element("historyLoadErrorText").textContent, /Clinic history could not be loaded/);
partial.restoreClinic();
await partial.element("historyRetry").listeners.click();
assert.equal(partial.element("historySummary").textContent, "3 records found");
assert.equal(partial.element("historyLoadError").classList.contains("hidden"), true);
const failed = await run({ failClinic: true, failGrooming: true });
assert.equal(failed.element("historyLoadingScreen").classList.contains("hidden"), true);
assert.equal(failed.element("historyList").innerHTML, "");
const empty = await run({ empty: true });
assert.match(empty.element("historyList").innerHTML, /No history found/);
console.log("Customer Health Records and History regression tests passed.");
