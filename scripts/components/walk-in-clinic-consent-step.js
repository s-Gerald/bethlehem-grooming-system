import { mountClinicConsent } from "./clinic-consent.js?v=20261115";
import { tooLong } from "../utils/short-notes.js?v=500-20261110";
import { WalkInOwnerValidation } from "../utils/walk-in-owner-validation.js?v=names-20261110";
import { getPetWeightSubmission, getPetWeightSubmissionValidationMessage } from "./pet-weight-size.js?v=pet-weight-size-20261107-v1";
import { getClinicVisitSummaryMarkup } from "./clinic-visit-summary-card.js?owner-session=20261111";
import { clinicRegistrationError, CLINIC_MAX_PATIENTS, validateClinicVisitReason } from "../services/clinic-visit-service.js?owner-session=20261111";
import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";

const WALK_IN_OWNER_KEY = "walkInOwnerStep";
const WALK_IN_CLINIC_CONFIRMED_KEY = "walkInClinicConfirmation";

const state = {
  patients: [],
  onBack: null,
};

let elements = {};
let consent;
let submitting = false;
let ownerValid = false;
let patientError = "";

function getMarkup(patients) {
  const owner = getStoredOwner();
  return `
    <main class="registration-step mx-auto max-w-5xl px-4 py-8 md:px-6 lg:px-8">
      <a
        id="clinicConsentBackTopBtn"
        href="/admin/dashboard"
        class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-slate-700"
      >
        &larr; Back to Home
      </a>

      <section data-session-public class="mt-4">
        <h1 class="text-3xl font-bold text-[#2f4b66]">Review &amp; Confirm</h1>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 4 of 4</span>
            <span>100% Complete</span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full w-full rounded-full bg-[#315b7e]"></div>
          </div>
        </div>
      </section>

      <section data-session-surface class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Clinic Visit Summary</h2>
        </div>

        <p class="mb-4 text-sm text-slate-600">${escapeHtml([owner.firstName, owner.lastName].filter(Boolean).join(" "))} &middot; ${patients.length} ${patients.length === 1 ? "pet" : "pets"}</p>
        ${patients.map(({ pet, commonConcerns, chiefComplaint }) => getClinicVisitSummaryMarkup({
          pet, concerns: commonConcerns, details: chiefComplaint,
        })).join("")}

        <form id="clinicConsentForm" class="space-y-6" novalidate>
          <div id="clinicConsentFields"></div>
          <p id="clinicConsentStatus" role="alert" hidden></p>

          <div class="registration-actions">
            <button
              id="clinicConsentBackBtn"
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
            >
              Back
            </button>

            <button
              id="clinicConsentSubmitBtn"
              type="submit"
              disabled
              class="inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67] disabled:cursor-not-allowed disabled:opacity-50"
            >
              Check In
            </button>
          </div>
        </form>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    form: document.getElementById("clinicConsentForm"),
    submitBtn: document.getElementById("clinicConsentSubmitBtn"),
    statusMsg: document.getElementById("clinicConsentStatus"),
    backButton: document.getElementById("clinicConsentBackBtn"),
  };
}

function syncSubmitButton() {
  elements.backButton.disabled = submitting;
  elements.submitBtn.disabled = submitting || !ownerValid || !!patientError || !consent?.valid();
}

function getStoredOwner() {
  try {
    return JSON.parse(sessionStorage.getItem(WALK_IN_OWNER_KEY) || "{}");
  } catch {
    return {};
  }
}

function patientValidationError() {
  if (!state.patients.length || state.patients.length > CLINIC_MAX_PATIENTS
      || state.patients.some(({ commonConcerns, chiefComplaint }) => validateClinicVisitReason(commonConcerns, chiefComplaint))) {
    return "Review the selected pets and provide a reason for each before checking in.";
  }
  return state.patients.map(({ pet }) => getPetWeightSubmissionValidationMessage(pet)).find(Boolean) || "";
}

async function handleSubmit(event) {
  event.preventDefault();
  if (submitting || !consent.validate()) return;

  const owner = getStoredOwner();
  if (!WalkInOwnerValidation.validate(owner, 'clinic')) return;

  if (!owner.firstName || !owner.lastName) {
    showError("Walk-in data is incomplete. Please restart from Step 1.");
    return;
  }

  patientError = patientValidationError();
  if (patientError) {
    syncSubmitButton();
    showError(patientError);
    return;
  }

  submitting = true;
  syncSubmitButton();
  elements.submitBtn.textContent = "Checking in…";
  elements.statusMsg.hidden = true;

  const payload = {
    fname:             owner.firstName,
    lname:             owner.lastName,
    email:             owner.email || null,
    phone:             owner.phone,
    owner_record_type: owner.ownerRecordType || "new",
    customer_user_id: owner.customerUserId || null,
    unregistered_customer_id: owner.unregisteredCustomerId || null,
    confirm_similar_name: Boolean(owner.confirmSimilarName),
    patients: state.patients.map(({ pet, commonConcerns, chiefComplaint }) => ({
      pet_id: pet.petId || null,
      pet_name: pet.petName,
      species: pet.petType,
      breed: pet.breed || null,
      fur_type: pet.furType || null,
      ...getPetWeightSubmission(pet),
      size: normalizeSizeForApi(pet.size),
      ...(!pet.petId && pet.bodyCondition ? { body_condition: pet.bodyCondition } : {}),
      color: pet.color || null,
      ...(pet.petId && tooLong(pet.medicalNotes) ? {} : { medical_conditions: pet.medicalNotes || null }),
      chief_complaint: chiefComplaint,
      common_concerns: commonConcerns,
    })),
    ...consent.payload(),
  };

  try {
    const response = await API.submitClinicWalkIn(payload);

    consent.clearDraft();
    sessionStorage.setItem(
      WALK_IN_CLINIC_CONFIRMED_KEY,
      JSON.stringify({ ...response.session, terms_agreed: payload.terms_agreed }),
    );
    sessionStorage.removeItem("walkInClinicComplaint");
    sessionStorage.removeItem("walkInPetStep");
    sessionStorage.removeItem(WALK_IN_OWNER_KEY);

    window.location.href = "/admin/walk-in/clinic/confirmed";
  } catch (error) {
    if (WalkInOwnerValidation.serverErrors(owner, error.errors ?? {}, 'clinic')) {
      ownerValid = false;
    } else if (!await consent.handleError(error)) {
      showError(clinicRegistrationError(error, state.patients) || "Unable to check in this clinic visit. Please try again.");
    }
    resetSubmitButton();
  }
}

function normalizeSizeForApi(size) {
  if (!size) return null;
  return size.toLowerCase().replace(/\s+/g, "_");
}

function showError(message) {
  elements.statusMsg.hidden = false;
  elements.statusMsg.textContent = message;
  elements.statusMsg.className = "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
}

function resetSubmitButton() {
  submitting = false;
  elements.submitBtn.textContent = "Check In";
  syncSubmitButton();
}

function handleBackClick() {
  if (typeof state.onBack === "function") {
    state.onBack();
    return;
  }
  window.location.href = "/admin/walk-in/pets";
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);
  elements.backButton.addEventListener("click", handleBackClick);
}

export async function renderWalkInClinicConsentStep({ patients, onBack }) {
  if (!await API.waitForSessionVerification()) return;
  state.patients = patients;
  state.onBack = onBack;

  document.title = "Walk-in Clinic — Review & Confirm";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.querySelector("main").outerHTML = getMarkup(patients);

  refreshElements();
  consent = undefined;
  submitting = false;
  const owner = getStoredOwner();
  ownerValid = WalkInOwnerValidation.validate(owner, 'clinic') && !!owner.firstName && !!owner.lastName;
  patientError = patientValidationError();
  if (!owner.firstName || !owner.lastName) showError("Walk-in data is incomplete. Please restart from Step 1.");
  else if (patientError) showError(patientError);

  consent = mountClinicConsent({
    host: document.getElementById("clinicConsentFields"),
    storageKey: "walkInClinicConsent",
    staff: true,
    signerName: [owner.firstName, owner.lastName].filter(Boolean).join(" "),
    onChange: syncSubmitButton,
  });
  bindEvents();
  syncSubmitButton();
}
