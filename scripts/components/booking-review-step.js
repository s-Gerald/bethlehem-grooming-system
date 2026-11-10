import { renderRegistrationQueueInformation } from "./registration-queue-information.js?v=fcfs-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3&urls=20261103";
import { formatSavedWeight } from "./pet-weight-size.js?v=pet-weight-size-20261107-v1";
import { renderBodyConditionChip } from "./pet-body-condition.js?body-condition=20261110-v2";
import { renderGroomingPreference } from "./grooming-service-selection.js?v=20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&estimates=20261031&estimate-ui=20261031-v2&overdue=20261031-v1&urls=20261103&pet-profile=20261110-v1&body-condition=20261110-v2&short-notes=500-20261110&size-availability=20261110";
import { hasPetGroomerSelection, petGroomerLabel } from "./pet-groomer-selection.js?services=20261026&registration=fcfs-20261027-v1";
import {
  BOOKING_STEP_THREE_KEY,
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
  getBookingDraft,
  readSessionJson,
} from "../services/booking-draft-service.js?flow=grooming-order-20261016&registration=fcfs-20261027-v1";
import { goToGroomingStep } from "./grooming-flow-navigation.js";
import {
  hasCompletedRequiredServiceSelection,
  specialInstructionsError,
  petGroomingSizeError,
  unavailablePackageMessage,
  selectedPricingSignature,
  buildBookingReviewPayload,
  getGroomingPricePresentation,
  formatPriceOption,
  normalizeStepThreeDraft,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&short-notes=500-20261110&size-availability=20261110";

/**
 * Booking Review Step Controller
 *
 * Purpose:
 * Builds the Step 4 review preview from the active booking draft and Step 2
 * grooming selections.
 *
 * Backend developer guide:
 * This page currently assembles a client-side review payload and stores it in
 * sessionStorage. In production, rebuild the review from the saved draft on the
 * backend, recompute totals and pricing notes there, and return the final review
 * payload that consent and submission should use.
 */
const REVIEW_STORAGE_KEY = "bookingStep4Review";
const LEGACY_REVIEW_STORAGE_KEY = "bookingReview";

const elements = {
  reviewNotice: document.getElementById("reviewNotice"),
  reviewSelections: document.getElementById("reviewSelections"),
  totalPriceHeading: document.getElementById("totalPriceHeading"),
  totalPriceText: document.getElementById("totalPriceText"),
  totalPriceSubtext: document.getElementById("totalPriceSubtext"),
  confirmBookingBtn: document.getElementById("confirmBookingBtn"),
};

const state = {
  bookingDraft: null,
  petSelections: [],
  reviewPayload: null,
  revision: 0,
};

export async function refreshBookingReviewStep(availability) {
  const revision = ++state.revision;
  state.reviewPayload = null;
  elements.confirmBookingBtn.disabled = true;
  state.bookingDraft = getBookingDraft();

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderEmptyState(
      "Please complete Pet Information and Grooming Services before reviewing.",
    );
    return;
  }

  if (availability?.error) {
    renderEmptyState(availability.error);
    const retry = document.createElement("button");
    retry.type = "button";
    retry.textContent = "Retry";
    retry.className = "mt-3 font-semibold text-[#315b7e] underline";
    retry.addEventListener("click", () => goToGroomingStep("review"));
    elements.reviewSelections.append(retry);
    return;
  }
  state.petSelections = normalizeStepThreeDraft(
    readSessionJson(BOOKING_STEP_THREE_KEY),
    state.bookingDraft,
    { keepUnavailablePackages: true },
  );
  for (const [index, pet] of state.bookingDraft.pets.entries()) {
    const selection = state.petSelections[index];
    if (petGroomingSizeError(pet)) {
      renderEmptyState(`${petGroomingSizeError(pet)} Return to Pet Information to update your selection.`);
      return;
    }
    if (!hasCompletedRequiredServiceSelection(pet, selection) || !hasPetGroomerSelection(selection) || specialInstructionsError(pet, selection)) {
      renderEmptyState(unavailablePackageMessage(pet, selection)
        || "Return to Grooming Services to check the selections for each pet.");
      return;
    }
  }
  const previousSignature = sessionStorage.getItem("groomingReviewedPricing") || sessionStorage.getItem("groomingSelectedPricing");
  const latestSignature = selectedPricingSignature(state.bookingDraft, state.petSelections);
  elements.reviewNotice.classList.add("hidden");
  elements.reviewSelections.innerHTML = '<p class="py-6 text-sm text-slate-500" role="status">Preparing your registration summary…</p>';
  const registration = await renderRegistrationQueueInformation(document.getElementById("groomingQueueInformation"), "grooming");
  if (revision !== state.revision || elements.reviewSelections.closest("[data-grooming-step]")?.hidden) return;

  sessionStorage.setItem("groomingReviewedPricing", latestSignature);
  state.reviewPayload = buildBookingReviewPayload(
    state.bookingDraft,
    state.petSelections,
  );

  renderReviewNotice();
  if (previousSignature && previousSignature !== latestSignature) {
    elements.reviewNotice.classList.remove("hidden");
    elements.reviewNotice.textContent += " A grooming price was updated. Please review the latest pricing before confirming.";
  }
  renderReviewSelections();
  renderTotalPricing();
  saveReviewDraft();
  elements.confirmBookingBtn.disabled = !registration?.registration_available;
  elements.confirmBookingBtn.classList.remove("opacity-50", "cursor-not-allowed");
}

export function initBookingReviewStep(availability) {
  bindEvents();
  return refreshBookingReviewStep(availability);
}

function bindEvents() {
  elements.confirmBookingBtn.addEventListener("click", handleConfirmClick);
}

function renderEmptyState(message) {
  elements.reviewNotice.classList.add("hidden");
  elements.reviewSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700" role="alert">
      ${escapeHtml(message)}
    </div>
  `;
  elements.totalPriceHeading.textContent = "Total Price";
  elements.totalPriceText.textContent = "Unavailable";
  elements.totalPriceSubtext.classList.remove("hidden");
  elements.totalPriceSubtext.textContent =
    "Review data is unavailable until the earlier queue registration steps are completed.";
  elements.confirmBookingBtn.disabled = true;
  elements.confirmBookingBtn.classList.add("opacity-50", "cursor-not-allowed");
}

function renderReviewNotice() {
  if (!state.reviewPayload) {
    return;
  }

  const price = getGroomingPricePresentation(state.reviewPayload.totalPricing);

  const estimateNotice = sessionStorage.getItem("groomingForecastNotice");
  sessionStorage.removeItem("groomingForecastNotice");
  elements.reviewNotice.className =
    `${price.isEstimate || estimateNotice ? "mb-6" : "hidden"} rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600`;
  elements.reviewNotice.textContent = [price.disclaimer, estimateNotice].filter(Boolean).join(" ");
}

function renderReviewSelections() {
  elements.reviewSelections.innerHTML = state.reviewPayload.items
    .map((item, index) => renderPetReviewCard(item, index))
    .join("");
}

function renderPetReviewCard(item, index) {
  const price = getGroomingPricePresentation(item.pricing.total);
  const packageLineItem = item.pricing.lineItems.find(lineItem => lineItem.kind === "package");
  const alaCarteLineItems = item.pricing.lineItems.filter(
    (lineItem) => lineItem.kind === "ala_carte",
  );
  const packageCard = packageLineItem
    ? renderPackageReview(packageLineItem)
    : "";
  const alaCarteCard =
    alaCarteLineItems.length > 0
      ? renderAlaCarteReview(alaCarteLineItems)
      : "";
  return `
    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${
            index + 1
          }</p>
          <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h3 class="break-words text-2xl font-bold text-[#2f4b66]">${escapeHtml(
              item.pet.petName || "Unnamed Pet",
            )}</h3>
            <p class="text-base font-semibold text-slate-600">${escapeHtml(
              formatPetTypeLabel(item.pet.petType),
            )} · ${escapeHtml(item.pet.breed || "Breed not specified")}</p>
            <p class="mt-2 text-xs font-semibold text-slate-600">
              Size: ${escapeHtml(item.pet.size ? formatPetSizeLabel(item.pet.size) : "Not specified")}${item.pet.sizeVerified ? " · Size verified" : ""} ${renderBodyConditionChip(item.pet.bodyCondition, item.pet.bodyConditionVerified)}
            </p>
          </div>
        </div>

        <div class="flex shrink-0 flex-col items-start gap-2 sm:items-end">
          ${item.pricing.hasSelection ? `<span class="text-xs font-semibold text-slate-500">${price.isEstimate ? "Estimated pet subtotal" : "Pet subtotal"}</span>` : ""}
          <span class="text-lg font-semibold text-[#2f4b66]">${escapeHtml(
            item.pricing.hasSelection ? price.amount : "No price available",
          )}</span>
        </div>
      </div>
      <p class="mt-2 text-sm text-slate-600">Weight: ${escapeHtml(item.pet.weight ? `${formatSavedWeight(item.pet.weight)} kg` : "Not specified")}</p>
      <p class="mt-2 text-sm text-slate-600">Pet Groomer: ${escapeHtml(petGroomerLabel(item.selection))}</p>

      <div class="mt-6 space-y-4">
        ${packageCard}
        ${renderGroomingPreference(item.selection)}
        ${item.selection.haircutRequested ? '<p class="text-sm font-semibold text-[#2f4b66]">Haircut requested</p>' : ""}
        ${alaCarteCard}

        ${
          item.selection.specialInstructions.trim()
            ? `
              <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Special Instructions
                </p>
                <p class="note-text mt-2 text-sm text-slate-600">${escapeHtml(
                  item.selection.specialInstructions.trim(),
                )}</p>
              </div>
            `
            : ""
        }
      </div>
    </article>
  `;
}

function renderPackageReview(packageLineItem) {
  const { selectedPriceOption, availablePriceOptions } = packageLineItem.pricing;
  const visiblePriceOptions = selectedPriceOption ? [selectedPriceOption] : availablePriceOptions;

  return `
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
        Selected Package
      </p>
      <h4 class="mt-2 text-lg font-semibold text-[#2f4b66]">${escapeHtml(
        packageLineItem.serviceName,
      )}</h4>
      <div class="service-card__pricing">
        <span class="service-card__pricing-label">Size &amp; Price</span>
        <div class="service-card__price-grid">
          ${visiblePriceOptions
            .map((priceOption) =>
              renderReviewPricePill(priceOption, selectedPriceOption),
            )
            .join("")}
        </div>
      </div>
    </div>
  `;
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
                <span class="text-sm text-slate-500">${escapeHtml(
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

function saveReviewDraft() {
  const reviewDraft = {
    pets: state.reviewPayload?.items.map((item) => ({
      petId: item.pet.id,
      petName: item.pet.petName,
      petType: item.pet.petType,
      breed: item.pet.breed,
      size: item.pet.size,
      sizeVerified: item.pet.sizeVerified,
      weight: item.pet.weight,
      servicePackage: item.selection.servicePackage,
      selectedServiceNames: item.pricing.lineItems.filter((line) => line.kind === "package").map((line) => line.serviceName),
      alaCarteServiceNames: item.pricing.lineItems.filter((line) => line.kind === "ala_carte").map((line) => line.serviceName),
      groomingPreference: item.selection.groomingPreference,
      haircutRequested: item.selection.haircutRequested,
      groomerId: item.selection.groomerId,
      petGroomerName: petGroomerLabel(item.selection),
      alaCarteServices: [...item.selection.alaCarteServices],
      addOns: [...item.selection.addOns],
      specialInstructions: item.selection.specialInstructions.trim(),
      pricing: {
        minAmount: item.pricing.total.minAmount,
        maxAmount: item.pricing.total.maxAmount,
        isEstimate: item.pricing.total.isEstimate,
      },
    })),
    totalPricing: state.reviewPayload?.totalPricing || {
      minAmount: 0,
      maxAmount: 0,
      isEstimate: false,
    },
    notices: state.reviewPayload?.notices || [],
  };

  const serializedReviewDraft = JSON.stringify(reviewDraft);

  sessionStorage.setItem(REVIEW_STORAGE_KEY, serializedReviewDraft);
  sessionStorage.setItem(LEGACY_REVIEW_STORAGE_KEY, serializedReviewDraft);
}

function handleConfirmClick() {
  if (!state.reviewPayload || elements.confirmBookingBtn.disabled) return;
  goToGroomingStep("consent");
}
