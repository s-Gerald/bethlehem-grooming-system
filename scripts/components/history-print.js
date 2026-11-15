import { bethlehemPrintHeader, confirmationFields } from "./confirmation-presentation.js?v=report-20261115";
import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";

export function fields(entries) {
  const recorded = entries.filter(([, value]) => value != null && value !== "");
  return recorded.length ? confirmationFields(recorded) : "";
}

function pairs(record) {
  return record.map(({label, value}) => [label, value]);
}

export function section(title, content, heading = "h2") {
  return content ? `<section class="report-section"><${heading}>${escapeHtml(title)}</${heading}>${content}</section>` : "";
}

export function subject(name, subtitle, content) {
  return `<section class="report-subject">
    <header class="report-subject-header">
      <h2>${escapeHtml(name)}</h2>
      ${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ""}
    </header>${content}
  </section>`;
}

export function table(title, columns, rows, amountColumn = false) {
  if (!rows.length) return "";
  return `<table class="report-table${amountColumn ? " report-table--amount" : ""}" aria-label="${escapeHtml(title)}">
    <thead><tr>${columns.map(column => `<th scope="col">${escapeHtml(column)}</th>`).join("")}</tr></thead>
    <tbody>${rows.map(row => `<tr>${row.map(value => `<td>${escapeHtml(value)}</td>`).join("")}</tr>`).join("")}</tbody>
  </table>`;
}

export function consentRecord(consent, responses, signedAt) {
  const response = responses.length
    ? fields([...responses, ["Signed by", consent?.signer_name || "Not recorded"],
      ["Signed at", signedAt]])
    : "<p>Consent record not available for this visit.</p>";
  const signature = consent?.signature_image;
  return `<section class="report-section report-consent">
    <h2>Consent &amp; Signature</h2>
    <div class="report-consent-content">${response}
      ${signature ? `<figure class="report-signature"><figcaption>Signature</figcaption>
        <img src="${escapeHtml(signature)}" alt="Customer signature" />
      </figure>` : ""}
    </div>
  </section>`;
}

function groomingPetRecord(archive, pet) {
  const subtitle = [pet.species, pet.breed, archive.petSize(pet), pet.groomingStateLabel]
    .map(value => archive.groomingText(value)).filter(Boolean).join(" · ");
  const details = fields([
    ["Pet groomer", pet.petGroomerName || "Any available groomer"],
    ["Body condition", archive.petBodyCondition(pet)],
    ["Weight", archive.groomingText(pet.weight)],
    ["Fur type", archive.groomingText(pet.furType)],
    ...pairs(archive.groomingTimelineFields(pet)),
  ]);
  const services = archive.servicesForPet(pet).map(service => {
    const main = service.serviceKind === "package";
    const preference = main && pet.groomingPreferenceLabel ? `\nGrooming preference: ${pet.groomingPreferenceLabel}` : "";
    return [`${service.name ?? ""}${preference}`, main ? "Main service" : "À la carte", archive.formatServiceAvailedPrice(service)];
  });
  const notes = fields([
    ["Allergies & special care notes", archive.groomingText(pet.medicalConditions), null, true],
    [archive.detailsBooking.bookingType === "Walk-In" ? "Grooming & visit notes" : "Pet instructions",
      archive.groomingText(pet.specialInstructions), null, true],
  ]);
  return subject(pet.petName || "Pet", subtitle, section("Grooming Details", details, "h3")
    + section("Services", table("Services", ["Service", "Type", "Amount"], services, true), "h3")
    + section("Notes", notes, "h3"));
}

function groomingRecord(archive) {
  const booking = archive.detailsBooking;
  const products = booking.productAddons || [];
  const paid = archive.paymentAmount(booking);
  return [
    section("Visit Overview", fields([
      ["Owner", archive.groomingText(booking.ownerName)],
      ["Status", booking.status === "cancelled" ? "Cancelled" : "Completed"],
      ["Grooming reference", archive.groomingText(booking.bookingReference)],
      ["Pets", booking.pets?.length],
      ...pairs(archive.groomingSessionFields),
      ["Owner account", booking.ownerAccountDeleted ? "Account deleted" : ""],
    ])),
    ...(booking.pets || []).map(pet => groomingPetRecord(archive, pet)),
    section("Product Add-ons", table("Product Add-ons", ["Product", "Unit price × quantity", "Amount"], products.map(line => [
      line.itemName, window.RecordedProductPricing.formatPriceQuantity(line), window.RecordedProductPricing.formatLineTotal(line),
    ]), true)),
    section("Notes", fields([
      ["Customer note", archive.groomingText(booking.specialNotes), null, true],
      ["Internal staff note", archive.groomingText(booking.internalStaffNote), null, true],
    ])),
    consentRecord(archive.signedConsent, [
      ["Grooming consent", archive.consentLabel("grooming")],
      ["Sedation consent", archive.consentLabel("sedation")],
    ], archive.consentSignedAt(archive.signedConsent)),
    section("Payment Summary", fields([
      ...(products.length ? [["Grooming services", archive.formatServicesAvailedTotal(booking)],
        ["Product add-ons", archive.formatPeso(archive.productAddonsTotal(booking))]] : []),
      ["Total paid", paid === "Not recorded" ? "" : paid],
      ["Status", booking.payment || booking.paid ? "Paid" : "Unpaid"],
    ])),
  ].join("");
}

function filenames(files) {
  return files.map(file => file.file_name).filter(Boolean).join("\n");
}

function clinicPaymentFields(archive, visit, grouped) {
  if (!archive.clinicHasPayment(visit)) return "";
  return fields([
    [grouped ? "Patient subtotal" : "Amount", archive.clinicMoney(visit.total_amount)],
    ["Payment status", visit.payment_status_label],
  ]);
}

function clinicPatientRecord(archive, {visit, care}, grouped, sessionDay) {
  const record = visit.record;
  const subtitle = [visit.pet?.species, visit.pet?.breed, grouped ? visit.appointment_reference : ""]
    .map(value => archive.clinicText(value)).filter(Boolean).join(" · ");
  const vitals = archive.clinicVitals(visit);
  const treatments = (care?.treatments || []).map(item =>
    `<div class="report-entry"><h4>${escapeHtml(item.treatment_name || item.vaccine_name)} · ${item.treatment_type === "medicine" ? "Medicine" : "Vaccine"}</h4>
      ${fields(pairs(archive.clinicTreatmentFields(item)))}</div>`).join("");
  const services = (care?.services || []).map(service => [
    archive.clinicServiceName(service), service.quantity ?? 1,
    [archive.clinicText(service.notes), filenames(archive.clinicServiceAttachments(service, visit))].filter(Boolean).join("\n"),
    archive.clinicServicePrice(service),
  ]);
  const diagnostics = archive.clinicDiagnostics(visit).map(entry => fields([
    ["Diagnostic", archive.clinicText(entry.diagnostic)],
    ["Result", archive.clinicText(entry.result)],
    ["Notes", archive.clinicText(entry.notes), null, true],
  ])).join("");
  const prescriptions = archive.clinicPrescriptions(visit).map(item =>
    `<div class="report-entry"><h4>${escapeHtml(item.medicine)}</h4>${fields(pairs(archive.clinicPrescriptionFields(item)))}</div>`).join("");
  const treatmentNotes = archive.clinicText(record?.treatment_given);
  const patientDate = sessionDay ? {...visit, appointment_date: null} : visit;

  return subject(visit.pet?.name || "Patient", subtitle, [
    grouped ? fields([["Status", visit.final_status_label],
      [sessionDay ? "Visit time" : "Visit date and time", archive.formatClinicVisit(patientDate, "")]]) : "",
    section("Clinical Summary", fields([
      ["Attending veterinarian", archive.clinicText(record?.primary_veterinarian_name)],
      ...pairs(archive.clinicSummaryFields(visit)),
    ]), "h3"),
    section("Medical Record Attachments", fields([
      ["Files", filenames(archive.clinicMedicalAttachments(visit, care)), null, true],
    ]), "h3"),
    section("Vitals", table("Vitals", vitals.map(vital => vital.label), vitals.length ? [vitals.map(vital => vital.value)] : []), "h3"),
    section("Treatment", (treatmentNotes ? `<p class="report-narrative">${escapeHtml(treatmentNotes)}</p>` : "") + treatments, "h3"),
    section("Clinic Services", table("Clinic Services", ["Service", "Qty", "Notes and files", "Price"], services, true), "h3"),
    section("Diagnostics", diagnostics, "h3"),
    section("Prescriptions", prescriptions, "h3"),
    section("Confinement", fields(pairs(archive.clinicConfinementFields(care?.confinement))), "h3"),
    section("Follow-up & Discharge", fields([
      ["Follow-up date", archive.formatAppointmentDate(archive.clinicText(record?.follow_up_date), "")],
      ["Instructions", archive.clinicText(record?.follow_up_notes), null, true],
    ]), "h3"),
    grouped ? clinicPaymentFields(archive, visit, true) : "",
  ].join(""));
}

function clinicRecord(archive) {
  const visit = archive.detailsClinicRecord;
  const session = archive.detailsClinicSession;
  const patients = archive.clinicDetailPatients;
  const consent = archive.clinicSignedConsent;
  const firstDay = patients[0]?.visit.appointment_date;
  const sessionDay = session && firstDay && patients.every(({visit}) => visit.appointment_date === firstDay) ? firstDay : "";
  return [
    section("Visit Overview", fields([
      ["Owner", archive.clinicText(visit.ownerName)],
      ["Patients", patients.length],
      ["Visit day", archive.formatAppointmentDate(sessionDay, "")],
      ...(!session ? [["Clinic reference", visit.appointment_reference], ["Status", visit.final_status_label],
        ["Visit date and time", archive.formatClinicVisit(visit, "")]] : []),
    ])),
    ...patients.map(patient => clinicPatientRecord(archive, patient, !!session, sessionDay)),
    consentRecord(consent, consent ? [["Clinic agreement", consent.clinic_consent ? "Agreed" : "Not agreed"]] : [],
      consent ? archive.consentSignedAt(consent) : ""),
    section("Payment Summary", session
      ? fields([["Total paid", session.paid_at ? archive.clinicMoney(session.total_amount) : ""]])
      : clinicPaymentFields(archive, visit, false)),
  ].join("");
}

export function printHistoryRecord(type, archive, stillOpen, trigger) {
  return printVisitReport(type, type === "grooming" ? groomingRecord(archive) : clinicRecord(archive), stillOpen, trigger);
}

export async function printVisitReport(type, content, stillOpen, trigger) {
  if (document.querySelector("[data-history-print]")) return;
  const record = document.createElement("article");
  record.className = "history-print print-report";
  record.dataset.historyPrint = "";
  const title = type === "grooming" ? "GROOMING VISIT RECORD" : "CLINIC VISIT RECORD";
  record.innerHTML = bethlehemPrintHeader(title) + content;

  await Promise.all([...record.querySelectorAll("img")].map(image => image.decode().catch(() => {})));
  if (!stillOpen()) return;
  const cleanup = () => {
    record.remove();
    document.body.classList.remove("history-record-printing");
    window.removeEventListener("afterprint", cleanup);
    if (stillOpen()) trigger?.focus({preventScroll: true});
  };
  document.body.append(record);
  document.body.classList.add("history-record-printing");
  window.addEventListener("afterprint", cleanup, {once: true});
  try {
    window.print();
  } catch (error) {
    cleanup();
    throw error;
  }
}
