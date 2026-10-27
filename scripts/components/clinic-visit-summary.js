import { renderRegistrationQueueInformation } from "./registration-queue-information.js?v=fcfs-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3";
import { getClinicVisitSummaryMarkup } from "./clinic-visit-summary-card.js?registration=fcfs-20261027-v1&pet-care=20261027-v1";
import { readReviewedServiceDay, registrationDayNotice } from "../services/registration-service-day.js?v=rolling-20261027-v1&registration-rollover=20261027-v3";
import {
  CLINIC_VISIT_CONFIRMATION_KEY,
  CLINIC_VISIT_DRAFT_KEY,
  CLINIC_VISIT_PETS_KEY,
  formatClinicVisitReason,
  readClinicVisitDraft,
  requireCustomerSession,
  validateClinicVisitReason,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1";

const elements = {
  details: document.getElementById("clinicVisitSummaryDetails"),
  status: document.getElementById("clinicVisitSubmitStatus"),
  submit: document.getElementById("submitClinicVisitBtn"),
};

function showError(message) {
  elements.status.textContent = message;
  elements.status.className =
    "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
}

async function submitClinicVisit(draft) {
  const serviceDay = readReviewedServiceDay("clinic");
  if (!serviceDay) return;
  elements.submit.disabled = true;
  elements.submit.textContent = "Submitting...";

  try {
    const response = await API.submitClinicPreRegistration({
      service_day: serviceDay,
      pet_id: Number(draft.pet.id),
      chief_complaint: draft.chiefComplaint,
      common_concerns: draft.commonConcerns,
    });

    sessionStorage.setItem(
      CLINIC_VISIT_CONFIRMATION_KEY,
      JSON.stringify(response.appointment),
    );
    sessionStorage.removeItem(CLINIC_VISIT_DRAFT_KEY);
    sessionStorage.removeItem(CLINIC_VISIT_PETS_KEY);
    window.location.href = "./clinic-visit-confirmed.html";
  } catch (error) {
    elements.submit.disabled = false;
    elements.submit.textContent = "Submit Online Registration";
    if (registrationDayNotice(error, "clinic")) {
      elements.status.textContent = "";
      elements.status.className = "hidden";
      await refreshAvailability();
    } else {
      showError(error.message || "The clinic visit could not be submitted. Please try again.");
    }
  }
}

async function refreshAvailability() {
  elements.submit.disabled = true;
  const availability = await renderRegistrationQueueInformation(document.getElementById("clinicQueueInformation"), "clinic");
  elements.submit.disabled = !availability?.registration_available;
}

async function init() {
  if (!requireCustomerSession("./sign-in.html")) {
    return;
  }

  const draft = readClinicVisitDraft();

  if (!draft.pet?.id) {
    window.location.replace("./booking-pet-details.html?flow=clinic");
    return;
  }

  if (validateClinicVisitReason(draft.commonConcerns, draft.chiefComplaint)) {
    window.location.replace("./clinic-visit-reason.html");
    return;
  }

  elements.details.innerHTML = getClinicVisitSummaryMarkup({
    visitType: "Clinic Online Registration",
    pet: draft.pet,
    reason: formatClinicVisitReason(draft.commonConcerns, draft.chiefComplaint),
  });
  await refreshAvailability();
  elements.submit.addEventListener("click", () => submitClinicVisit(draft));
}

init();
