import { renderRegistrationQueueInformation } from "./registration-queue-information.js?v=fcfs-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3&urls=20261103";
import { getClinicVisitSummaryMarkup } from "./clinic-visit-summary-card.js?owner-session=20261111";
import { readReviewedServiceDay, registrationDayNotice } from "../services/registration-service-day.js?v=rolling-20261027-v1&registration-rollover=20261027-v3";
import {
  CLINIC_VISIT_CONFIRMATION_KEY,
  CLINIC_VISIT_DRAFT_KEY,
  CLINIC_VISIT_PETS_KEY,
  CLINIC_MAX_PATIENTS,
  clinicRegistrationError,
  readClinicVisitDraft,
  requireCustomerSession,
  validateClinicVisitReason,
} from "../services/clinic-visit-service.js?owner-session=20261111";

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
      patients: draft.patients.map(({ pet, chiefComplaint, commonConcerns }) => ({
        pet_id: Number(pet.id), chief_complaint: chiefComplaint, common_concerns: commonConcerns,
      })),
    });

    sessionStorage.setItem(
      CLINIC_VISIT_CONFIRMATION_KEY,
      JSON.stringify(response.session),
    );
    sessionStorage.removeItem(CLINIC_VISIT_DRAFT_KEY);
    sessionStorage.removeItem(CLINIC_VISIT_PETS_KEY);
    window.location.href = "/account/registration/clinic/confirmed";
  } catch (error) {
    elements.submit.disabled = false;
    elements.submit.textContent = "Submit Queue Registration";
    if (registrationDayNotice(error, "clinic")) {
      elements.status.textContent = "";
      elements.status.className = "hidden";
      await refreshAvailability();
    } else {
      showError(clinicRegistrationError(error, draft.patients) || "Clinic queue registration could not be submitted. Please try again.");
    }
  }
}

async function refreshAvailability() {
  elements.submit.disabled = true;
  const availability = await renderRegistrationQueueInformation(document.getElementById("clinicQueueInformation"), "clinic");
  elements.submit.disabled = !availability?.registration_available;
}

async function init() {
  if (!await requireCustomerSession("/sign-in")) {
    return;
  }

  const draft = readClinicVisitDraft();

  if (!draft.patients?.length || draft.patients.length > CLINIC_MAX_PATIENTS) {
    window.location.replace("/account/registration/clinic/pets?flow=clinic");
    return;
  }

  if (draft.patients.some(({ commonConcerns, chiefComplaint }) => validateClinicVisitReason(commonConcerns, chiefComplaint))) {
    window.location.replace("/account/registration/clinic/reason");
    return;
  }

  elements.details.innerHTML = `<p class="mb-1 text-sm text-slate-600">Clinic Queue Registration · ${draft.patients.length} ${draft.patients.length === 1 ? "pet" : "pets"}</p>
    <p id="clinicReviewOwner" class="mb-4 text-sm font-semibold text-[#2f4b66]" hidden></p>`
    + draft.patients.map(({ pet, commonConcerns, chiefComplaint }) => getClinicVisitSummaryMarkup({
      pet, concerns: commonConcerns, details: chiefComplaint,
    })).join("");
  API.getMe?.().then(({ user }) => {
    const owner = document.getElementById("clinicReviewOwner");
    const name = [user?.first_name, user?.last_name].filter(Boolean).join(" ");
    owner.textContent = name;
    owner.hidden = !name;
  }).catch(() => {});
  await refreshAvailability();
  elements.submit.addEventListener("click", () => submitClinicVisit(draft));
}

init();
