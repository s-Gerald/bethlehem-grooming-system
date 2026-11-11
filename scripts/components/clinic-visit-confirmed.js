import {
  CLINIC_VISIT_CONFIRMATION_KEY, formatClinicVisitDate, requireCustomerSession,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261107-v1&registration=fcfs-20261027-v1&remember=20261029&urls=20261103&short-notes=500-20261110";
import {
  clinicReasonConfirmation, confirmationFields, confirmationSection, formatConfirmationLabel,
  formatConfirmationTimestamp, ownerConfirmationFields, readConfirmationData,
  renderConfirmation,
} from "./confirmation-presentation.js?visit-details=20261029&registration=fcfs-20261027-v1&registration-ui=20261027-v3&mobile-format=20261110&urls=20261103&owner-name=20261108";

export async function initClinicVisitConfirmation() {
  const walkIn = document.body.dataset.confirmationContext === "walk-in";
  if (walkIn && !await API.waitForSessionVerification()) return;
  if (!walkIn && !await requireCustomerSession("/sign-in")) return;
  const confirmation = readConfirmationData(walkIn ? "walkInClinicConfirmation" : CLINIC_VISIT_CONFIRMATION_KEY);
  const root = document.getElementById("confirmationRoot");
  const patients = confirmation?.patients ?? (confirmation?.appointment_reference ? [confirmation] : []);
  if (!patients.length) {
    if (!walkIn) window.location.replace("/account");
    else if (root) root.innerHTML = '<h1>Confirmation unavailable</h1><p role="status">No saved clinic walk-in confirmation was found.</p>';
    return;
  }
  if (!root) return;
  const grouped = patients.length > 1;
  const first = patients[0];
  const sections = [
    confirmationSection("Owner Information", ownerConfirmationFields(confirmation.owner)),
    confirmationSection(walkIn ? "Check-in Details" : "Registration Details", confirmationFields([
      ["Entry source", walkIn ? "Walk-in" : "Online Queue Registration"],
      ["Visit day", formatClinicVisitDate(confirmation.service_day || first.appointment_date)],
      ...(!walkIn && first.created_at ? [["Submitted online", formatConfirmationTimestamp(first.created_at)]] : []),
    ])),
    ...patients.map((appointment) => patientSection(appointment, walkIn, grouped)),
    typeof confirmation.terms_agreed === "boolean" ? confirmationSection("Clinic Visit Agreement", confirmationFields([
      ["Clinic visit terms", confirmation.terms_agreed ? "Agreed" : "Not agreed"],
    ])) : "",
    !walkIn ? confirmationSection("What Happens Next", "<p>Your queue number will be assigned after check-in.</p>") : "",
  ].join("");
  renderConfirmation(root, {
    title: walkIn ? "Clinic Walk-in Checked In" : "Queue Registration Received",
    printTitle: walkIn ? "CLINIC WALK-IN CHECK-IN CONFIRMATION" : "Clinic Queue Registration Confirmation",
    message: grouped ? `${patients.length} pets ${walkIn ? "checked in" : "registered"}` : "",
    reference: first.appointment_reference, status: walkIn ? "Checked In" : "Waiting to Arrive",
    walkIn, queueNumber: first.queue_number, showOperational: !grouped, sections,
    primaryHref: walkIn ? "/admin/clinic?tab=active-cases" : "/account",
    primaryLabel: walkIn ? "Go to Active Cases" : "Return to Dashboard",
  });
}

function patientSection(appointment, walkIn, grouped) {
  const patient = appointment.pet || {};
  const checkedInAt = formatConfirmationTimestamp(appointment.checked_in_at || appointment.submitted_at || appointment.created_at);
  const currentStatus = appointment.status === "waiting_to_arrive" ? "Waiting to Arrive" : formatConfirmationLabel(appointment.status) || "Not available";
  return confirmationSection(grouped ? patient.name || "Patient" : "Patient Information", confirmationFields([
    ...(!grouped ? [["Patient name", patient.name || "Not provided"]] : []),
    ["Registration reference", appointment.appointment_reference],
    ["Species", formatConfirmationLabel(patient.species) || "Not provided"],
    ["Breed", patient.breed || "Not specified"],
    ["Queue number", appointment.queue_number ?? "Not assigned"],
    [walkIn ? "Current status" : "Status", currentStatus],
    ...(walkIn && checkedInAt ? [["Checked in at", checkedInAt]] : []),
    ...(patient.gender ? [["Sex", formatConfirmationLabel(patient.gender)]] : []),
    ...(patient.birthdate ? [["Birth date", formatClinicVisitDate(patient.birthdate)]] : []),
    ...(patient.weight != null ? [["Weight", patient.weight + " kg"]] : []),
  ]) + clinicReasonConfirmation(appointment));
}

if (typeof document !== "undefined") initClinicVisitConfirmation();
