import { getPetWeightSubmission, getPetWeightSubmissionValidationMessage } from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";
import { getClinicVisitSummaryMarkup } from "./clinic-visit-summary-card.js?registration=fcfs-20261027-v1&pet-care=20261027-v1";
import { formatClinicVisitReason } from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1";

const WALK_IN_OWNER_KEY = "walkInOwnerStep";
const WALK_IN_CLINIC_CONFIRMED_KEY = "walkInClinicConfirmation";

const state = {
  pet: null,
  chiefComplaint: "",
  commonConcerns: [],
  onBack: null,
};

let elements = {};

function getMarkup(pet, commonConcerns, chiefComplaint) {
  return `
    <main class="mx-auto max-w-5xl px-4 py-8 md:px-6 lg:px-8">
      <a
        id="clinicConsentBackTopBtn"
        href="./dashboard.html"
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

        ${getClinicVisitSummaryMarkup({
          visitType: "Clinic Walk-in",
          pet,
          reason: formatClinicVisitReason(commonConcerns, chiefComplaint),
        })}

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

          <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
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

async function handleSubmit(event) {
  event.preventDefault();

  if (!elements.checkbox.checked) {
    syncSubmitButton();
    return;
  }

  elements.submitBtn.disabled = true;
  elements.submitBtn.textContent = "Submitting…";

  const owner = getStoredOwner();

  if (!owner.firstName) {
    showError("Walk-in data is incomplete. Please restart from Step 1.");
    resetSubmitButton();
    return;
  }

  const weightMessage = getPetWeightSubmissionValidationMessage(state.pet);
  if (weightMessage) {
    showError(weightMessage);
    resetSubmitButton();
    return;
  }

  const payload = {
    fname:             owner.firstName,
    lname:             owner.lastName,
    mname:             owner.middleInitial || null,
    email:             owner.email || null,
    phone:             owner.phone,
    owner_record_type: owner.ownerRecordType || "new",
    customer_user_id: owner.customerUserId || null,
    unregistered_customer_id: owner.unregisteredCustomerId || null,
    confirm_similar_name: Boolean(owner.confirmSimilarName),
    pet_id:             state.pet?.petId || null,
    pet_name:          state.pet?.petName || "",
    species:           state.pet?.petType || "",
    breed:             state.pet?.breed || null,
    fur_type:          state.pet?.furType || null,
    ...getPetWeightSubmission(state.pet),
    size:              normalizeSizeForApi(state.pet?.size),
    color:             state.pet?.color || null,
    medical_conditions: state.pet?.medicalNotes || null,
    chief_complaint:   state.chiefComplaint,
    common_concerns:   state.commonConcerns,
    terms_agreed:      true,
  };

  try {
    const response = await API.submitClinicWalkIn(payload);

    sessionStorage.setItem(
      WALK_IN_CLINIC_CONFIRMED_KEY,
      JSON.stringify({
        appointment_reference: response.appointment_reference,
        queue_number:          response.queue_number,
        appointment_id:        response.appointment_id,
        appointment_date:      response.appointment_date,
        status:                response.status,
        checked_in_at:         response.checked_in_at,
        returning_customer:    response.returning_customer,
        submitted_at:          new Date().toISOString(),
        owner: {
          fullName: response.owner?.name || "",
          phone:    response.owner?.phone || "",
          email:    response.owner?.email || "",
        },
        pet: response.pet,
        chief_complaint: response.chief_complaint,
        common_concerns: response.common_concerns,
        terms_agreed: payload.terms_agreed,
      }),
    );

    window.location.href = "./walk-in-clinic-confirmed.html";
  } catch (error) {
    showError(error.message || "Failed to submit clinic walk-in. Please try again.");
    resetSubmitButton();
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
  window.location.href = "./walk-in-pet-details.html";
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);
  elements.checkbox.addEventListener("change", syncSubmitButton);
  elements.backButton.addEventListener("click", handleBackClick);
}

export async function renderWalkInClinicConsentStep({ pet = null, commonConcerns = [], chiefComplaint = "", onBack = null } = {}) {
  if (!await API.waitForSessionVerification()) return;
  state.pet = pet;
  state.chiefComplaint = chiefComplaint;
  state.commonConcerns = commonConcerns;
  state.onBack = onBack;

  document.title = "Walk-in Clinic — Review & Confirm";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.querySelector("main").outerHTML = getMarkup(pet, commonConcerns, chiefComplaint);

  refreshElements();
  bindEvents();
  syncSubmitButton();
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.getElementById("clinicConsentForm")) {
    return;
  }

  if (!await API.waitForSessionVerification()) return;

  // Loaded directly — try to restore from sessionStorage
  try {
    const saved = JSON.parse(sessionStorage.getItem("walkInClinicComplaint") || "{}");
    state.pet = saved.pet || null;
    state.chiefComplaint = saved.chiefComplaint || "";
    state.commonConcerns = saved.commonConcerns || [];
  } catch {
    // ignore
  }

  document.querySelector("main").outerHTML = getMarkup(state.pet, state.commonConcerns, state.chiefComplaint);
  refreshElements();
  bindEvents();
  syncSubmitButton();
});
