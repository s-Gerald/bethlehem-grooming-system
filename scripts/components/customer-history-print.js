import { consentRecord, fields, printVisitReport, section, subject, table } from "./history-print.js?v=customer-record";
import { clinicVisitSections, hasHealthValue, healthDate } from "./customer-health-records.js?v=history-record-billing";
import { clinicHistoryBilling, clinicHistoryPayment, groomingHistoryPets, groomingHistoryProducts, groomingPetBilling, historyPaymentFields, historyTimestamp, petGroomingTimes } from "./customer-history-billing.js?v=record-billing";
import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";

function billingTable(title, columns, lines) {
  return table(title, columns, lines.map(({ name, detail, preference, amount }) => [
    preference ? `${name}\nGrooming preference: ${preference}` : name, detail, amount,
  ]), true);
}

function paymentSummary(payment) {
  return section("Payment Summary", fields(historyPaymentFields(payment).map(([label, value]) =>
    [label, value, label === "Total paid" ? "customer-history-total-paid" : null])));
}

function recordedConsent(consent, agreements) {
  const responses = agreements.filter(([, value]) => typeof value === "boolean")
    .map(([label, value]) => [label, value ? "Agreed" : "Not agreed"]);
  return consentRecord(consent, responses, historyTimestamp(consent?.signed_at) || "Not recorded");
}

function careFields(entries) {
  return fields(entries.filter(([, value]) => hasHealthValue(value))
    .map(([label, value, options]) => [label, value, null, options?.wide]));
}

function clinicPatient(patient) {
  const cancelled = patient.status === "cancelled";
  const identity = [patient.pet?.species, patient.breed, patient.appointment_reference, cancelled ? "Cancelled" : null]
    .filter(Boolean).join(" · ");
  if (cancelled) return subject(patient.pet?.pet_name || "Patient", identity, "");

  const care = clinicVisitSections(patient).map(([title, entries, groups = []]) => section(title,
    careFields(entries) + groups.map(([label, items]) => items.length ? `<div class="report-entry">
      <h4>${escapeHtml(label)}</h4>
      ${items.map(item => `<div class="report-entry"><strong>${escapeHtml(item.name)}</strong>${careFields(item.details)}</div>`).join("")}
    </div>` : "").join(""), "h3")).join("");
  const billing = clinicHistoryBilling(patient);
  const vitals = patient.vitals;
  return subject(patient.pet?.pet_name || "Patient", identity, care
    + section("Vitals", careFields([
      ["Temperature (°C)", vitals?.temperature_c], ["Heart rate (bpm)", vitals?.heart_rate_bpm],
      ["Respiratory rate (breaths/min)", vitals?.respiratory_rate_bpm], ["Body condition score", vitals?.body_condition_score],
    ]), "h3")
    + section("Billing breakdown", billingTable("Billing breakdown", ["Item", "Qty × unit price", "Amount"], billing.lines)
      + fields([["Patient subtotal", billing.subtotal]]), "h3"));
}

function clinicReport(event, details) {
  const patients = details.patients || [];
  return section("Visit Overview", fields([
    ["Owner", details.owner_name], ["Visit day", healthDate(event.date)],
    ["Clinic reference", !details.grouped ? patients[0]?.appointment_reference : null],
    ["Patients", patients.length > 1 ? patients.length : null], ["Status", "Completed"],
  ])) + patients.map(clinicPatient).join("")
    + recordedConsent(details.consent, [["Clinic agreement", details.consent?.clinic_consent]])
    + paymentSummary(clinicHistoryPayment(details));
}

function groomingPet(booking, pet) {
  const billing = groomingPetBilling(booking, pet);
  const times = petGroomingTimes(booking, pet);
  const identity = [pet.species, pet.breed === "—" ? "" : pet.breed, pet.confirmed_size || pet.size,
    pet.grooming_status === "cancelled" ? "Cancelled" : null].filter(Boolean).join(" · ");
  return subject(pet.pet_name || "Pet", identity,
    section("Grooming Details", fields([
      ["Pet groomer", pet.pet_groomer?.first_name],
      ["Body condition", pet.body_condition],
      ["Weight", hasHealthValue(pet.weight) ? `${pet.weight} kg` : ""],
      ["Fur type", pet.fur_type],
      ["Grooming started", historyTimestamp(times.start)],
      ["Grooming finished", historyTimestamp(times.finish)],
    ]), "h3")
    + section("Services & add-ons", billingTable("Services & add-ons", ["Service", "Type", "Amount"], billing.lines)
      + fields([["Pet subtotal", billing.subtotal]]), "h3")
    + section("Notes", fields([
      ["Allergies & special care notes", pet.medical_conditions, null, true],
      ["Pet instructions", pet.special_instructions, null, true],
    ]), "h3"));
}

function groomingReport(event, details) {
  const { booking, pet } = event;
  const pets = groomingHistoryPets(booking, pet);
  return section("Visit Overview", fields([
    ["Owner", details.owner_name], ["Visit day", healthDate(booking.booking_date || event.date)],
    ["Grooming reference", booking.booking_reference], ["Pets", pets.length > 1 ? pets.length : null],
    ["Status", "Completed"], ["Dropped off", historyTimestamp(booking.dropped_off_timestamp)],
  ])) + pets.map(patient => groomingPet(booking, patient)).join("")
    + section("Products Purchased", billingTable("Products Purchased", ["Product", "Qty × unit price", "Amount"], groomingHistoryProducts(booking)))
    + section("Visit Notes", fields([["Customer note", booking.special_notes, null, true]]))
    + recordedConsent(details.consent, [
      ["Grooming consent", details.consent?.grooming_consent], ["Sedation consent", details.consent?.sedation_consent],
    ])
    + paymentSummary(booking.payment_summary);
}

export function printCustomerHistoryRecord(event, details, stillOpen, trigger) {
  const content = event.type === "clinic" ? clinicReport(event, details) : groomingReport(event, details);
  return printVisitReport(event.type, content, stillOpen, trigger);
}
