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

        <div
          id="clinicConsentStatus"
          class="mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600"
        >
          Please review the information above and agree to the terms to proceed.
        </div>

        <form id="clinicConsentForm" class="space-y-6" novalidate>
          <section class="rounded-2xl border border-[#91aeca] bg-white p-5">
            <h3 class="mb-4 text-lg font-semibold text-[#2f4b66]">Clinic Visit Agreement</h3>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
              <ul class="space-y-3 text-sm leading-7 text-slate-700">
                <li>&bull; I confirm that the information provided is accurate and complete.</li>
                <li>&bull; I understand that the veterinary consultation fee will be assessed at the clinic.</li>
                <li>&bull; I consent to examination and diagnostic procedures as recommended by the veterinarian.</li>
                <li>&bull; I understand that in-patient or emergency procedures may require additional consent.</li>
                <li>&bull; I agree to settle the consultation and any applicable fees before leaving the clinic.</li>
              </ul>
            </div>

            <label class="mt-4 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
              <input
                id="clinicConsentCheckbox"
                type="checkbox"
                class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
              />
              <span class="text-sm text-slate-700">
                I have read and agree to the clinic visit terms. <span class="text-red-500">*</span>
              </span>
            </label>
          </section>

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
              class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white"
            >
              Submit Walk-in
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
    checkbox: document.getElementById("clinicConsentCheckbox"),
    submitBtn: document.getElementById("clinicConsentSubmitBtn"),
    statusMsg: document.getElementById("clinicConsentStatus"),
    backButton: document.getElementById("clinicConsentBackBtn"),
  };
  elements.checkbox.checked = Boolean(JSON.parse(sessionStorage.getItem("walkInClinicComplaint") || "{}").termsAgreed);
}

function syncSubmitButton() {
  const checked = elements.checkbox.checked;
  elements.submitBtn.disabled = !checked;
  elements.submitBtn.className = checked
    ? "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]"
    : "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";

  elements.statusMsg.textContent = checked
    ? "All good. You can now submit this clinic walk-in."
    : "Please agree to the clinic visit terms before submitting.";
  elements.statusMsg.className = checked
    ? "mb-6 rounded-2xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700"
    : "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";
}

function getStoredOwner() {
  try {
    return JSON.parse(sessionStorage.getItem(WALK_IN_OWNER_KEY) || "{}");
  } catch {
    return {};
  }
}

function storeTermsAgreement() {
  const complaint = JSON.parse(sessionStorage.getItem('walkInClinicComplaint') || '{}');
  sessionStorage.setItem('walkInClinicComplaint', JSON.stringify({ ...complaint, termsAgreed: elements.checkbox.checked }));
}

async function handleSubmit(event) {
  event.preventDefault();

  if (!elements.checkbox.checked) {
    syncSubmitButton();
    return;
  }

  elements.submitBtn.disabled = true;
  elements.submitBtn.textContent = "Submitting…";

  const owner = getStoredOwner();
  storeTermsAgreement();

  if (!WalkInOwnerValidation.validate(owner, 'clinic')) { resetSubmitButton(); return; }

  if (!owner.firstName) {
    showError("Walk-in data is incomplete. Please restart from Step 1.");
    resetSubmitButton();
    return;
  }

  if (!state.patients.length || state.patients.length > CLINIC_MAX_PATIENTS
      || state.patients.some(({ commonConcerns, chiefComplaint }) => validateClinicVisitReason(commonConcerns, chiefComplaint))) {
    resetSubmitButton();
    showError("Review the selected pets and provide a reason for each before submitting.");
    return;
  }
  const weightMessage = state.patients.map(({ pet }) => getPetWeightSubmissionValidationMessage(pet)).find(Boolean);
  if (weightMessage) {
    resetSubmitButton();
    showError(weightMessage);
    return;
  }

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
    terms_agreed:      true,
  };

  try {
    const response = await API.submitClinicWalkIn(payload);

    sessionStorage.setItem(
      WALK_IN_CLINIC_CONFIRMED_KEY,
      JSON.stringify({ ...response.session, terms_agreed: payload.terms_agreed }),
    );
    sessionStorage.removeItem("walkInClinicComplaint");
    sessionStorage.removeItem("walkInPetStep");
    sessionStorage.removeItem(WALK_IN_OWNER_KEY);

    window.location.href = "/admin/walk-in/clinic/confirmed";
  } catch (error) {
    if (WalkInOwnerValidation.serverErrors(owner, error.errors, 'clinic')) { resetSubmitButton(); return; }
    resetSubmitButton();
    showError(clinicRegistrationError(error, state.patients) || "Failed to submit clinic walk-in. Please try again.");
  }
}

function normalizeSizeForApi(size) {
  if (!size) return null;
  return size.toLowerCase().replace(/\s+/g, "_");
}

function showError(message) {
  elements.statusMsg.textContent = message;
  elements.statusMsg.className = "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
}

function resetSubmitButton() {
  elements.submitBtn.disabled = false;
  elements.submitBtn.textContent = "Submit Walk-in";
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
  elements.checkbox.addEventListener("change", () => {
    storeTermsAgreement();
    syncSubmitButton();
  });
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
  bindEvents();
  syncSubmitButton();
}
