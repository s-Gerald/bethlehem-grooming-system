import {
  CLINIC_VISIT_CONFIRMATION_KEY, formatClinicVisitDate, requireCustomerSession,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1";
import {
  confirmationFields, confirmationSection, formatConfirmationLabel,
  formatConfirmationTimestamp, ownerConfirmationFields, readConfirmationData,
  renderConfirmation,
} from "./confirmation-presentation.js?registration=fcfs-20261027-v1&registration-ui=20261027-v3";

export function clinicReasonConfirmation(appointment) {
  const concerns = Array.isArray(appointment.common_concerns) ? appointment.common_concerns : [];
  let details = String(appointment.chief_complaint ?? "").trim();
  // Current API summaries prefix the free text with the selected concerns.
  // Only remove that exact prefix; preserve separately supplied free text.
  const summary = concerns.join(", ");
  if (summary && details === summary) details = "";
  else if (summary && details.startsWith(summary + "\n")) details = details.slice(summary.length).trim();
  return confirmationFields([
    ["Selected concerns", concerns.join(" · ") || "None provided"],
    ["Additional details", details || "None provided"],
  ]).replace('class="confirmation-fields"', 'class="confirmation-fields confirmation-fields--reason"');
}

export async function initClinicVisitConfirmation() {
  const walkIn = document.body.dataset.confirmationContext === "walk-in";
  if (walkIn && !await API.waitForSessionVerification()) return;
  if (!walkIn && !requireCustomerSession("./sign-in.html")) return;
  const appointment = readConfirmationData(walkIn ? "walkInClinicConfirmation" : CLINIC_VISIT_CONFIRMATION_KEY);
  const root = document.getElementById("confirmationRoot");
  if (!appointment?.appointment_reference) {
    if (!walkIn) window.location.replace("./dashboard.html");
    else if (root) root.innerHTML = '<h1>Confirmation unavailable</h1><p role="status">No saved clinic walk-in confirmation was found.</p>';
    return;
  }
  if (!root) return;
  const patient = appointment.pet || {};
  const checkedInAt = formatConfirmationTimestamp(appointment.checked_in_at || appointment.submitted_at || appointment.created_at);
  const currentStatus = appointment.status === "waiting_to_arrive" ? "Waiting to Arrive" : formatConfirmationLabel(appointment.status) || "Not available";
  const sections = [
    confirmationSection("Owner Information", ownerConfirmationFields(appointment.owner)),
    confirmationSection("Patient Information", confirmationFields([
      ["Patient name", patient.name || "Not provided"],
      ["Species", formatConfirmationLabel(patient.species) || "Not provided"],
      ["Breed", patient.breed || "Not specified"],
      ...(patient.gender ? [["Sex", formatConfirmationLabel(patient.gender)]] : []),
      ...(patient.birthdate ? [["Birth date", formatClinicVisitDate(patient.birthdate)]] : []),
      ...(patient.weight != null ? [["Weight", patient.weight + " kg"]] : []),
    ])),
    confirmationSection("Reason for Visit", clinicReasonConfirmation(appointment)),
    confirmationSection(walkIn ? "Check-in Details" : "Registration Details", confirmationFields(walkIn ? [
      ["Entry source", "Walk-in"],
      ["Queue number", appointment.queue_number ?? "Not assigned"],
      ["Checked in at", checkedInAt || "Not available"],
      ["Current status", currentStatus],
    ] : [
      ["Entry source", "Online Registration"],
      ["Visit day", formatClinicVisitDate(appointment.appointment_date)],
      ["Queue number", appointment.queue_number ?? "Not assigned"],
      ["Status", currentStatus],
      ...(checkedInAt ? [["Submitted online", checkedInAt]] : []),
    ])),
    typeof appointment.terms_agreed === "boolean" ? confirmationSection("Clinic Visit Agreement", confirmationFields([
      ["Clinic visit terms", appointment.terms_agreed ? "Agreed" : "Not agreed"],
    ])) : "",
    !walkIn ? confirmationSection("What Happens Next", "<p>Show your registration reference when you arrive. Your queue number is assigned after check-in.</p>") : "",
  ].join("");
  renderConfirmation(root, {
    title: walkIn ? "Clinic Walk-in Checked In" : "Online Registration Received",
    printTitle: walkIn ? "CLINIC WALK-IN CHECK-IN CONFIRMATION" : "Clinic Online Registration Confirmation",
    message: walkIn ? "The patient has been checked in and added to the clinic queue." : "",
    reference: appointment.appointment_reference, status: walkIn ? "Checked In" : "Waiting to Arrive",
    walkIn, queueNumber: appointment.queue_number, sections,
    primaryHref: walkIn ? "./clinic.html?tab=active-cases" : "./dashboard.html",
    primaryLabel: walkIn ? "Go to Active Cases" : "Return to Dashboard",
  });
}

if (typeof document !== "undefined") initClinicVisitConfirmation();
