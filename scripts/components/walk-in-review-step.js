import { renderGroomingPreference } from "./grooming-service-selection.js?v=20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import { petGroomerLabel } from "./pet-groomer-selection.js?services=20261026&registration=fcfs-20261027-v1";
import {
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
  normalizePetSize,
} from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import {
  hasRequiredGroomingPreference,
  specialInstructionsError,
  buildBookingReviewPayload,
  getGroomingPricePresentation,
  formatPriceOption,
  getPackageById,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import { renderWalkInConsentStep } from "./walk-in-consent-step.js?capacity=20261010&pet-weight=pet-weight-size-20261011-v3&groomers=named-20261016&consent=20261017&session=walk-in-20261021&nav=walk-in-home-20261025&services=20261026&grooming=actual-20261026&pet-care=20261027-v1";

const state = {
  bookingDraft: null,
  petSelections: [],
  reviewPayload: null,
  onBack: null,
};

const WALK_IN_REVIEW_STORAGE_KEY = "walkInReviewStep";

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  The review payload is a frontend snapshot of the walk-in draft. When database
  support is ready, load this page from the saved draft and use backend-priced
  totals as the source of truth.
*/

let elements = {};

function getReviewMainMarkup() {
  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <a
        id="walkInReviewBackTopBtn"
        href="./dashboard.html"
        class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-slate-700"
      >
        &larr; Back to Home
      </a>

      <section data-session-public class="mt-4">
        <h1 class="text-3xl font-bold text-[#2f4b66]">Review your grooming visit</h1>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 4 of 5</span>
            <span>80% Complete</span>
          </div>
          <div class="h-2 w-full rounded-full bg-slate-200">
            <div class="h-2 rounded-full bg-[#315b7e]" style="width: 80%"></div>
          </div>
        </div>
      </section>

      <section data-session-surface class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Visit review</h2>
        </div>

        <div class="mb-6">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
              Schedule Summary
            </p>
            <p class="mt-2 text-sm text-slate-600">Walk-in visit</p>
          </div>
        </div>

        <div
          id="reviewNotice"
          class="mb-6 hidden rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600"
        >
          The price is finalized at the clinic.
        </div>

        <div id="reviewSelections" class="space-y-6"></div>

        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
            Price Summary
          </p>
          <h3
            id="totalPriceHeading"
            class="mt-3 text-2xl font-bold text-[#2f4b66]"
          >
            Total Price
          </h3>
          <p id="totalPriceText" class="mt-2 text-3xl font-bold text-[#244764]">
            P0
          </p>
          <p id="totalPriceSubtext" class="mt-3 text-sm text-slate-500">
            Pricing details will appear once services are selected.
          </p>
        </section>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <button
            id="walkInReviewBackBtn"
            type="button"
            class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
          >
            Back
          </button>

          <button
            id="confirmBookingBtn"
            type="button"
            disabled
            class="inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]"
          >
            Next Step
          </button>
        </div>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    reviewNotice: document.getElementById("reviewNotice"),
    reviewSelections: document.getElementById("reviewSelections"),
    totalPriceHeading: document.getElementById("totalPriceHeading"),
    totalPriceText: document.getElementById("totalPriceText"),
    totalPriceSubtext: document.getElementById("totalPriceSubtext"),
    confirmBookingBtn: document.getElementById("confirmBookingBtn"),
    backButton: document.getElementById("walkInReviewBackBtn"),
  };
}

function bindEvents() {
  elements.confirmBookingBtn?.addEventListener("click", handleConfirmClick);
  elements.backButton.addEventListener("click", handleBackClick);
}

function handleBackClick() {
  if (typeof state.onBack === "function") {
    state.onBack();
    return;
  }

  window.location.href = "./walk-in-grooming-services.html";
}

function handleConfirmClick() {
  if (!state.reviewPayload) {
    return;
  }

  if (state.reviewPayload.items.some((item) => !hasRequiredGroomingPreference(item.selection) || specialInstructionsError(item.pet, item.selection))) return;
  saveReviewDraft();
  window.history.pushState(null, "", "./walk-in-consent.html");
  renderWalkInConsentStep({
    reviewPayload: state.reviewPayload,
    onBack: () => {
      window.history.pushState(null, "", "./walk-in-booking-review.html");
      renderWalkInReviewStep({
        pets: state.bookingDraft.pets,
        petSelections: state.petSelections,
        onBack: state.onBack,
      });
    },
  });
}

function saveReviewDraft() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    sessionStorage keeps the review available for the consent and confirmation
    screens only. Replace this with a walk-in draft save / fetch when available.
  */
  sessionStorage.setItem(
    WALK_IN_REVIEW_STORAGE_KEY,
    JSON.stringify(state.reviewPayload),
  );
}

function renderEmptyState(message) {
  elements.reviewNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.reviewNotice.textContent = message;
  elements.reviewSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      ${escapeHtml(message)}
    </div>
  `;
  elements.totalPriceHeading.textContent = "Total Price";
  elements.totalPriceText.textContent = "P0";
  elements.totalPriceSubtext.textContent =
    "Review data is unavailable until the earlier walk-in steps are completed.";
  elements.confirmBookingBtn.disabled = true;
  elements.confirmBookingBtn.classList.add("opacity-50", "cursor-not-allowed");
}

function renderReviewNotice() {
  const price = getGroomingPricePresentation(state.reviewPayload.totalPricing);

  elements.reviewNotice.className =
    `${price.isEstimate ? "mb-6" : "hidden"} rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600`;
  elements.reviewNotice.textContent = price.disclaimer;
}

function renderReviewSelections() {
  elements.reviewSelections.innerHTML = state.reviewPayload.items
    .map((item, index) => renderPetReviewCard(item, index))
    .join("");
}

function renderPetReviewCard(item, index) {
  const price = getGroomingPricePresentation(item.pricing.total);
  const packageId = item.selection.servicePackage;
  const selectedPackage = packageId ? getPackageById(packageId) : null;
  const alaCarteLineItems = item.pricing.lineItems.filter(
    (lineItem) => lineItem.kind === "ala_carte",
  );
  const packageCard = selectedPackage
    ? renderPackageReview(selectedPackage, item)
    : "";
  const alaCarteCard =
    alaCarteLineItems.length > 0
      ? renderAlaCarteReview(alaCarteLineItems)
      : "";
  const missingSelectionNotice =
    !selectedPackage && item.pricing.lineItems.length === 0
      ? `
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
          No service is attached to this pet yet. Please go back to Step 3 and finish the selection.
        </div>
      `
      : "";

  return `
    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${
            index + 1
          }</p>
          <h3 class="mt-1 text-xl font-bold text-[#2f4b66]">${escapeHtml(
            item.pet.petName || "Unnamed Pet",
          )}</h3>
          <p class="mt-2 text-sm text-slate-500">${escapeHtml(
            formatPetTypeLabel(item.pet.petType),
          )} | ${escapeHtml(item.pet.breed || "Breed not specified")}</p>
        </div>

        <div class="flex flex-col gap-2 md:items-end">
          <span class="rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
            ${escapeHtml(formatPetSizeLabel(item.pet.size))}
          </span>
          ${item.pricing.hasSelection ? `<span class="text-xs font-semibold text-slate-500">${escapeHtml(price.label)}</span>` : ""}
          <span class="text-sm font-bold text-slate-500">${escapeHtml(
            item.pricing.hasSelection ? price.amount : "No price available",
          )}</span>
        </div>
      </div>

      <div class="mt-6 space-y-4">
        ${item.pet.color ? `<p class="text-sm text-slate-600">Color: ${escapeHtml(item.pet.color)}</p>` : ""}
        <p class="text-sm text-slate-600">Pet Groomer: ${escapeHtml(petGroomerLabel(item.selection))}</p>
        ${packageCard}
        ${renderGroomingPreference(item.selection)}
        ${item.selection.haircutRequested ? '<p class="text-sm font-semibold text-[#2f4b66]">Haircut requested</p>' : ""}
        ${alaCarteCard}
        ${missingSelectionNotice}

        <div class="rounded-2xl border border-slate-200 bg-white p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Grooming &amp; Visit Notes</p>
          <p class="mt-2 text-sm text-slate-600">${escapeHtml(item.selection.specialInstructions.trim() || "None provided")}</p>
        </div>
      </div>
    </article>
  `;
}

function renderPackageReview(selectedPackage, item) {
  const packageLineItem = item.pricing.lineItems.find(
    (lineItem) =>
      lineItem.kind === "package" && lineItem.serviceId === selectedPackage.id,
  );

  if (!packageLineItem) {
    return "";
  }

  const visiblePriceOptions = getVisiblePackagePriceOptions(
    selectedPackage,
    item.pet.size,
  );

  return `
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
        Selected Package
      </p>
      <h4 class="mt-2 text-lg font-semibold text-[#2f4b66]">${escapeHtml(
        selectedPackage.name,
      )}</h4>
      <div class="service-card__pricing">
        <span class="service-card__pricing-label">Size &amp; Price</span>
        <div class="service-card__price-grid">
          ${visiblePriceOptions
            .map((priceOption) =>
              renderReviewPricePill(priceOption, packageLineItem.pricing.selectedPriceOption),
            )
            .join("")}
        </div>
      </div>
    </div>
  `;
}

function getVisiblePackagePriceOptions(selectedPackage, petSize) {
  const normalizedSize = normalizePetSize(petSize);

  if (!normalizedSize) {
    return selectedPackage.priceOptions;
  }

  return selectedPackage.priceOptions.filter(
    (priceOption) => priceOption.sizeKey === normalizedSize,
  );
}

function renderReviewPricePill(priceOption, selectedPriceOption) {
  const isSelected =
    selectedPriceOption &&
    selectedPriceOption.label === priceOption.label &&
    selectedPriceOption.sizeKey === priceOption.sizeKey;

  return `
    <div class="service-card__price-pill ${isSelected ? "service-card__price-pill--active" : ""}">
      <span class="service-card__price-size">${escapeHtml(priceOption.label)}</span>
      <span class="service-card__price-value">${escapeHtml(
        formatPriceOption(priceOption),
      )}</span>
    </div>
  `;
}

function renderAlaCarteReview(alaCarteLineItems) {
  return `
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
        A la Carte Services
      </p>
      <div class="mt-3 space-y-2">
        ${alaCarteLineItems
          .map(
            (lineItem) => `
              <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3 py-3">
                <span class="font-semibold text-[#2f4b66]">${escapeHtml(
                  lineItem.serviceName,
                )}</span>
                <span class="text-sm font-bold text-slate-500">${escapeHtml(
                  lineItem.pricing.displayPrice,
                )}</span>
              </div>
            `,
          )
          .join("")}
      </div>
    </div>
  `;
}

function renderTotalPricing() {
  const price = getGroomingPricePresentation(state.reviewPayload.totalPricing);

  elements.totalPriceHeading.textContent = price.label;
  elements.totalPriceText.textContent = price.amount;

  elements.totalPriceSubtext.textContent = "";
  elements.totalPriceSubtext.classList.add("hidden");
}

function initReviewState({ pets = [], petSelections = [], onBack = null } = {}) {
  state.bookingDraft = {
    bookingType: "walk_in",
    pets: Array.isArray(pets) ? pets : [],
  };
  state.petSelections = Array.isArray(petSelections) ? petSelections : [];
  state.onBack = onBack;
  state.reviewPayload = state.bookingDraft.pets.length
    ? buildBookingReviewPayload(state.bookingDraft, state.petSelections)
    : null;
}

export async function renderWalkInReviewStep(options = {}) {
  if (!await API.waitForSessionVerification()) return;
  document.title = "Walk-in - Review";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.querySelector("main").outerHTML = getReviewMainMarkup();

  refreshElements();
  initReviewState(options);
  bindEvents();

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderEmptyState(
      "No pets were found for this walk-in review. Please go back to Step 2 and Step 3 before reviewing.",
    );
    return;
  }

  renderReviewNotice();
  renderReviewSelections();
  renderTotalPricing();
  const invalid = state.reviewPayload.items.some((item) => !hasRequiredGroomingPreference(item.selection) || specialInstructionsError(item.pet, item.selection));
  elements.confirmBookingBtn.disabled = invalid;
  elements.confirmBookingBtn.classList.toggle("opacity-50", invalid);
  elements.confirmBookingBtn.classList.toggle("cursor-not-allowed", invalid);
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.getElementById("confirmBookingBtn")) {
    return;
  }

  if (!await API.waitForSessionVerification()) {
    return;
  }

  refreshElements();
  initReviewState();
  bindEvents();
  renderEmptyState(
    "No pets were found for this walk-in review. Please go back to Step 2 and Step 3 before reviewing.",
  );
});
