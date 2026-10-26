import { initGroomingConsent } from "./grooming-consent.js?v=20261017";
import { getPetWeightSubmission, getPetWeightSubmissionValidationMessage } from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";
const state = {
  reviewPayload: null,
  onBack: null,
};

const WALK_IN_OWNER_STORAGE_KEY = "walkInOwnerStep";
const WALK_IN_REVIEW_STORAGE_KEY = "walkInReviewStep";
const WALK_IN_CONSENT_STORAGE_KEY = "walkInConsentStep";
const WALK_IN_CONFIRMATION_STORAGE_KEY = "walkInBookingConfirmation";
let consent;

let elements = {};

function getConsentMainMarkup() {
  return `
    <main class="mx-auto max-w-5xl px-4 py-8 md:px-6 lg:px-8">
      <a
        id="walkInConsentBackTopBtn"
        href="./dashboard.html"
        class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-slate-700"
      >
        &larr; Back to Home
      </a>

      <section data-session-public class="mt-4">
        <h1 class="text-3xl font-bold text-[#2f4b66]">Consent &amp; Agreement</h1>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 5 of 5</span>
            <span>Final step</span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full w-full rounded-full bg-[#315b7e]"></div>
          </div>
        </div>
      </section>

      <section data-session-surface class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6 grid gap-4 md:grid-cols-2">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
              Schedule Type
            </p>
            <p class="mt-2 text-sm text-slate-600">Walk-in visit</p>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
              Schedule Summary
            </p>
            <p id="bookingSummaryText" class="mt-2 text-sm text-slate-600">
              No visit details available yet.
            </p>
          </div>
        </div>

        <p id="consentStatusMessage" role="alert" class="mb-4 text-sm text-red-600" hidden></p>

        <form id="walkInConsentForm" class="space-y-6" novalidate>
          <div id="groomingConsentFields" class="space-y-6"></div>

          <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <button
              id="walkInConsentBackBtn"
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
            >
              Back
            </button>

            <button
              id="submitBookingButton"
              type="submit"
              disabled
              class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white"
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
    form: document.getElementById("walkInConsentForm"),
    submitBookingButton: document.getElementById("submitBookingButton"),
    consentStatusMessage: document.getElementById("consentStatusMessage"),
    bookingSummaryText: document.getElementById("bookingSummaryText"),
    backButton: document.getElementById("walkInConsentBackBtn"),
  };
}

function populateSummary() {
  const items = Array.isArray(state.reviewPayload?.items)
    ? state.reviewPayload.items
    : [];

  if (items.length > 1) {
    elements.bookingSummaryText.textContent = `${items.length} pets selected: ${items
      .map(
        (item) =>
          `${item.pet?.petName || "Unnamed Pet"} (${getSelectedServiceSummary(item)})`,
      )
      .join(", ")}`;
    return;
  }

  if (items.length === 1) {
    const [item] = items;
    elements.bookingSummaryText.textContent = `${item.pet?.petName || "Unnamed Pet"} | ${formatServiceName(
      item.pet?.petType || "No pet type",
    )} | ${getSelectedServiceSummary(item)}`;
    return;
  }

  elements.bookingSummaryText.textContent = "No visit details available yet.";
}

function getSelectedServiceSummary(item) {
  const selectedServices = (item?.pricing?.lineItems || [])
    .map((lineItem) => lineItem.serviceName)
    .filter(Boolean);

  return selectedServices.length > 0
    ? selectedServices.join(", ")
    : "No service selected";
}

function formatServiceName(value) {
  if (!value) return "";
  return String(value)
    .replaceAll("_", " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function validateConsentForm(valid = false) {
  elements.submitBookingButton.disabled = !valid;
  elements.submitBookingButton.className = valid
    ? "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]"
    : "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";
}

async function handleSubmit(event) {
  event.preventDefault();

  if (!consent.valid) {
    consent.validate(true);
    validateConsentForm();
    return;
  }

  const consentPayload = consent.payload();
  if (!await consent.confirmSubmission() || !consent.valid) return;
  consent.setBusy(true);

  elements.submitBookingButton.disabled = true;
  elements.submitBookingButton.textContent = "Submitting…";

  try {
    const owner        = getStoredData(WALK_IN_OWNER_STORAGE_KEY);
    const reviewPayload = state.reviewPayload || getStoredData(WALK_IN_REVIEW_STORAGE_KEY);

    if (!owner || !Array.isArray(reviewPayload?.items) || reviewPayload.items.length === 0) {
      showSubmitError("Walk-in data is incomplete. Please restart from Step 1.");
      resetSubmitButton();
      return;
    }

    const weightMessage = (reviewPayload.items || []).map(item => getPetWeightSubmissionValidationMessage(item.pet)).find(Boolean);
    if (weightMessage) {
      showSubmitError(weightMessage);
      resetSubmitButton();
      return;
    }
    const payload  = buildApiPayload(owner, reviewPayload, consentPayload);
    const response = await API.submitWalkIn(payload);

    // Merge real server fields with the shape the confirmation page expects
    sessionStorage.setItem(
      WALK_IN_CONFIRMATION_STORAGE_KEY,
      JSON.stringify(buildConfirmationPayload(response, reviewPayload)),
    );
    window.location.href = "./walk-in-booking-confirmed.html";
  } catch (error) {
    showSubmitError(error.data?.detail ? `${error.message} ${error.data.detail}` : error.message || "Failed to register walk-in. Please try again.");
    resetSubmitButton();
  }
}

function buildApiPayload(owner, reviewPayload, consentPayload) {
  return {
    fname: owner.firstName,
    lname: owner.lastName,
    mname: owner.middleInitial || null,
    email: owner.email || null,
    phone: owner.phone,
    owner_record_type: owner.ownerRecordType || "new",
    customer_user_id: owner.customerUserId || null,
    unregistered_customer_id: owner.unregisteredCustomerId || null,
    confirm_similar_name: Boolean(owner.confirmSimilarName),

    pets: buildWalkInPets(reviewPayload),

    ...consentPayload,
  };
}

export function buildWalkInPets(reviewPayload) {
  return (reviewPayload.items || []).map((item) => ({
      pet_id:              item.pet.petId || null,
      pet_name:             item.pet.petName,
      species:              item.pet.petType,
      breed:                item.pet.breed    || null,
      fur_type:             item.pet.furType  || null,
      ...getPetWeightSubmission(item.pet),
      size:                 normalizeSizeForApi(item.pet.size),
      medical_conditions:   item.pet.medicalNotes || null,
      special_instructions: item.selection.specialInstructions?.trim() || null,
      grooming_preference: item.selection.groomingPreference || null,
      groomer_id: item.selection.groomerId ?? null,
      haircut_requested: item.selection.haircutRequested === true,
      services:             item.pricing.lineItems.map((lineItem) => ({
        service_slug: lineItem.serviceId,
      })),
    }));
}

function normalizeSizeForApi(size) {
  const map = {
    Small:        "small",
    Medium:       "medium",
    Large:        "large",
    "Extra Large": "extra_large",
  };
  return map[size] || (size ? size.toLowerCase().replace(/\s+/g, "_") : null);
}

function buildConfirmationPayload(response, reviewPayload) {
  const items = Array.isArray(reviewPayload?.items) ? reviewPayload.items : [];

  return {
    // Real server fields
    booking_reference: response.booking_reference,
    queue_number:      response.queue_number,
    booking_id:        response.booking_id,
    consent:           response.consent,
    booking_date:      response.booking_date,
    booking_time:      "",
    status:            response.status,
    booking_type:      "walk_in",
    number_of_pets:    items.length,
    total_amount:      response.total_amount,
    returning_customer: response.returning_customer,
    submitted_at:      new Date().toISOString(),

    // Owner in the shape the confirmation page reads
    owner: {
      fullName: response.owner?.name || "",
      phone:    response.owner?.phone || "",
      email:    response.owner?.email || "",
    },

    // Pet rows for the pet info table (uses frontend field names)
    pets: items.map((item) => ({
      petName: item.pet.petName,
      petType: item.pet.petType,
      breed:   item.pet.breed || "",
      size:    item.pet.size  || "",
      weight:  item.pet.weight,
      sizeVerified: item.pet.sizeVerified,
    })),

    // Service rows for the service table
    review: {
      pets: items.map((item, index) => {
        const packageLine    = item.pricing.lineItems.find((l) => l.kind === "package");
        const alaCarteLines  = item.pricing.lineItems.filter((l) => l.kind === "ala_carte");
        return {
          petName:              item.pet.petName,
          servicePackage:       packageLine?.serviceName || null,
          groomingPreference: item.selection.groomingPreference,
          haircutRequested: item.selection.haircutRequested === true,
          groomerId: item.selection.groomerId,
          petGroomerName: response.pets?.[index]?.pet_groomer?.first_name,
          groomingPreferenceLabel: response.pets?.[index]?.grooming_preference_label,
          alaCarteServices:     alaCarteLines.map((l) => l.serviceName),
          pricing:              item.pricing.total,
          specialInstructions:  item.selection.specialInstructions || "",
        };
      }),
      totalPricing: reviewPayload?.totalPricing || null,
    },
  };
}

function showSubmitError(message) {
  elements.consentStatusMessage.hidden = false;
  elements.consentStatusMessage.textContent = message;
  elements.consentStatusMessage.className =
    "mb-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700";
}

function resetSubmitButton() {
  consent.setBusy(false);
  elements.submitBookingButton.textContent = "Check In";
}

function handleBackClick() {
  if (typeof state.onBack === "function") {
    state.onBack();
    return;
  }

  window.location.href = "./walk-in-booking-review.html";
}

function buildWalkInConfirmation(consentPayload) {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    The reference number, date, and status below are frontend placeholders.
    Replace them with the official booking fields returned after database save.
  */
  const submittedAt = new Date();
  const reviewPayload =
    state.reviewPayload || getStoredData(WALK_IN_REVIEW_STORAGE_KEY);
  const owner = getStoredData(WALK_IN_OWNER_STORAGE_KEY);
  const items = Array.isArray(reviewPayload?.items) ? reviewPayload.items : [];

  const selectedDate = formatLocalDateKey(submittedAt);
  const selectedTime = `${String(submittedAt.getHours()).padStart(2, "0")}:${String(
    submittedAt.getMinutes(),
  ).padStart(2, "0")}`;

  return {
    booking_reference: createFrontEndReference(submittedAt),
    booking_date: selectedDate,
    booking_time: selectedTime,
    submitted_at: submittedAt.toISOString(),
    booking_type: "walk_in",
    status: "confirmed",
    number_of_pets: items.length,
    owner,
    pets: items.map((item) => item.pet).filter(Boolean),
    review: buildConfirmationReview(reviewPayload),
    consent: consentPayload,
  };
}

function buildConfirmationReview(reviewPayload) {
  const items = Array.isArray(reviewPayload?.items) ? reviewPayload.items : [];

  return {
    pets: items.map((item, index) => {
      const lineItems = Array.isArray(item.pricing?.lineItems)
        ? item.pricing.lineItems
        : [];
      const packageLine = lineItems.find(
        (lineItem) => lineItem.kind === "package",
      );

      return {
        petId: item.pet?.id || "",
        petName: item.pet?.petName || "",
        petType: item.pet?.petType || "",
        breed: item.pet?.breed || "",
        size: item.pet?.size || "",
        servicePackage:
          packageLine?.serviceName || item.selection?.servicePackage || "",
        alaCarteServices: lineItems
          .filter((lineItem) => lineItem.kind === "ala_carte")
          .map((lineItem) => lineItem.serviceName),
        specialInstructions: String(
          item.selection?.specialInstructions || "",
        ).trim(),
        haircutRequested: item.selection?.haircutRequested === true,
        pricing: item.pricing?.total || null,
      };
    }),
    totalPricing: reviewPayload?.totalPricing || null,
    notices: reviewPayload?.notices || [],
    isEstimate: Boolean(reviewPayload?.isEstimate),
  };
}

function createFrontEndReference(date) {
  const datePart = formatLocalDateKey(date).replaceAll("-", "");
  const timePart = [
    date.getHours(),
    date.getMinutes(),
    date.getSeconds(),
  ]
    .map((part) => String(part).padStart(2, "0"))
    .join("");

  return `WALK-IN-${datePart}-${timePart}`;
}

function formatLocalDateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, "0"),
    String(date.getDate()).padStart(2, "0"),
  ].join("-");
}

function getStoredData(key) {
  try {
    const rawValue = sessionStorage.getItem(key);
    return rawValue ? JSON.parse(rawValue) : null;
  } catch (error) {
    console.error(`Failed to parse sessionStorage key: ${key}`, error);
    return null;
  }
}

function bindEvents() {
  elements.form.addEventListener("submit", handleSubmit);
  elements.backButton.addEventListener("click", handleBackClick);
}

function initSignature() {
  const owner = getStoredData(WALK_IN_OWNER_STORAGE_KEY);
  consent = initGroomingConsent({
    storageKey: WALK_IN_CONSENT_STORAGE_KEY,
    staff: true,
    signerName: [owner?.firstName, owner?.lastName].filter(Boolean).join(" "),
    onChange: validateConsentForm,
  });
}

function initConsentState({ reviewPayload = null, onBack = null } = {}) {
  state.reviewPayload =
    reviewPayload || getStoredData(WALK_IN_REVIEW_STORAGE_KEY);
  state.onBack = onBack;
}

export async function renderWalkInConsentStep(options = {}) {
  if (!await API.waitForSessionVerification()) return;
  document.title = "Walk-in - Consent & Agreement";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.querySelector("main").outerHTML = getConsentMainMarkup();

  refreshElements();
  initConsentState(options);
  initSignature();
  populateSummary();
  bindEvents();
  validateConsentForm();
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.getElementById("walkInConsentForm")) {
    return;
  }

  if (!await API.waitForSessionVerification()) {
    return;
  }

  refreshElements();
  initConsentState();
  initSignature();
  populateSummary();
  bindEvents();
  validateConsentForm();
});
