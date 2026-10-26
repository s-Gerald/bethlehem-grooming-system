import { renderServiceSelections, updateSpecialInstructions, validateSpecialInstructions, focusSpecialInstructions } from "./grooming-service-selection.js?v=20261026&grooming=actual-20261026";
import { loadPetGroomers, normalizePetGroomerSelections, hasPetGroomerSelection } from "./pet-groomer-selection.js?services=20261026";
import {
  BOOKING_STEP_THREE_KEY,
  formatPetTypeLabel,
  getBookingDraft,
  readSessionJson,
} from "../services/booking-draft-service.js?flow=grooming-order-20261016";
import { formatBookingSchedule } from "../services/booking-format-service.js";
import { goToGroomingStep } from "./grooming-flow-navigation.js";
import {
  buildStepThreeDraftPayload,
  hasCompletedRequiredServiceSelection,
  updatePetServiceSelection,
  loadGroomingCatalogue,
  selectedPricingSignature,
  createEmptyPetServiceSelection,
  normalizeStepThreeDraft,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026";

const elements = {
  form: document.getElementById("bookingServicesForm"),
  scheduleSummaryText: document.getElementById("scheduleSummaryText"),
  petSummaryText: document.getElementById("petSummaryText"),
  serviceNotice: document.getElementById("serviceNotice"),
  bookingDateInput: document.getElementById("bookingDate"),
  bookingTimeInput: document.getElementById("bookingTime"),
  petIdInput: document.getElementById("petId"),
  petTypeInput: document.getElementById("servicePetType"),
  petServiceSelections: document.getElementById("petServiceSelections"),
  nextButton:
    document.getElementById("reviewBookingBtn") ||
    document.querySelector('#bookingServicesForm button[type="submit"]'),
};

const state = {
  bookingDraft: null,
  petSelections: [],
  catalogueReady: false,
};

export async function refreshBookingServicesStep() {
  state.bookingDraft = getBookingDraft();

  populateHiddenInputs(state.bookingDraft);
  populateSummary(state.bookingDraft);

  if (!Array.isArray(state.bookingDraft?.pets) || state.bookingDraft.pets.length === 0) {
    renderMissingPetState();
    return;
  }

  state.petSelections = normalizeStepThreeDraft(
    readSessionJson(BOOKING_STEP_THREE_KEY),
    state.bookingDraft,
  );

  await loadServicePricing();
}

export function initBookingServicesStep() {
  refreshBookingServicesStep();
  bindEvents();
}

async function loadServicePricing() {
  state.catalogueReady = false;
  syncNextButtonState(false);
  elements.petServiceSelections.innerHTML = '<p class="py-6 text-sm text-slate-500" role="status">Loading grooming pricing...</p>';
  try {
    await Promise.all([loadGroomingCatalogue(), loadPetGroomers()]);
    state.petSelections = normalizeStepThreeDraft({petSelections: state.petSelections}, state.bookingDraft);
    normalizePetGroomerSelections(state.petSelections);
    state.catalogueReady = true;
    renderPetServiceSelections();
    updateServiceNotice();
  } catch (error) {
    state.catalogueReady = false;
    elements.petServiceSelections.innerHTML = `<div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700" role="alert">
      Unable to load grooming services and Pet Groomer availability. <button type="button" data-retry-pricing class="font-semibold underline">Retry</button>
    </div>`;
    syncNextButtonState(false);
  }
}

function bindEvents() {
  elements.petServiceSelections.addEventListener("click", handleSelectionClick);
  elements.petServiceSelections.addEventListener("change", handleSelectionChange);
  elements.petServiceSelections.addEventListener("input", handleSelectionInput);
  elements.form.addEventListener("submit", handleSubmit);
}

function populateHiddenInputs(data) {
  elements.bookingDateInput.value = data?.bookingDate || "";
  elements.bookingTimeInput.value = data?.bookingTime || "";
  elements.petIdInput.value = data?.petIds?.join(",") || data?.petId || "";
  elements.petTypeInput.value = data?.petTypes?.join(",") || data?.petType || "";
}

function populateSummary(data) {
  if (elements.scheduleSummaryText && (data?.bookingDate || data?.bookingTime)) {
    elements.scheduleSummaryText.textContent =
      data.bookingScheduleText ||
      formatBookingSchedule(data.bookingDate, data.bookingTime) ||
      "No schedule selected yet.";
  }

  const selectedPets = Array.isArray(data?.pets) ? data.pets : [];

  if (!elements.petSummaryText) {
    return;
  }

  if (selectedPets.length > 1) {
    elements.petSummaryText.textContent = `${selectedPets.length} pets selected: ${selectedPets
      .map((pet) => `${pet.petName || "Unnamed Pet"} (${formatPetTypeLabel(pet.petType)})`)
      .join(", ")}`;
    return;
  }

  if (selectedPets.length === 1) {
    const firstPet = selectedPets[0];

    elements.petSummaryText.textContent = `${firstPet.petName || "Unnamed Pet"} · ${formatPetTypeLabel(
      firstPet.petType,
    )} · ${firstPet.breed || "Breed not specified"}`;
  }
}

function renderMissingPetState() {
  elements.petServiceSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      Please return to Pet Information and select at least one pet.
    </div>
  `;

  elements.serviceNotice.hidden = false;
  elements.serviceNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.serviceNotice.textContent =
    "Please complete Pet Information first.";

  syncNextButtonState(false);
}

function renderPetServiceSelections(petId = null) {
  renderServiceSelections(elements.petServiceSelections, state.bookingDraft?.pets || [], state.petSelections, false, petId);
}

function getSelectionByPetId(petId) {
  return (
    state.petSelections.find((selection) => selection.petId === petId) ||
    createEmptyPetServiceSelection({ id: petId })
  );
}

function updateServiceNotice(validationMessage = "") {
  const pets = Array.isArray(state.bookingDraft?.pets) ? state.bookingDraft.pets : [];
  const readyPetCount = pets.filter((pet) =>
    hasCompletedRequiredServiceSelection(pet, getSelectionByPetId(pet.id)) && hasPetGroomerSelection(getSelectionByPetId(pet.id)),
  ).length;

  const allPetsReady = readyPetCount === pets.length && pets.length > 0;
  const hasValidationError = Boolean(validationMessage);

  syncNextButtonState(allPetsReady);

  elements.serviceNotice.hidden = !hasValidationError;
  elements.serviceNotice.className = "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.serviceNotice.textContent = validationMessage;
}

function syncNextButtonState(isEnabled) {
  if (!elements.nextButton) {
    return;
  }

  elements.nextButton.disabled = !isEnabled;
  elements.nextButton.setAttribute("aria-disabled", String(!isEnabled));
  elements.nextButton.classList.toggle("opacity-50", !isEnabled);
  elements.nextButton.classList.toggle("cursor-not-allowed", !isEnabled);
}

function handleSelectionClick(event) {
  if (event.target.closest("[data-retry-pricing]")) { loadServicePricing(); return; }
  if (!state.catalogueReady) return;
  const target = event.target;

  if (
    !(target instanceof HTMLInputElement) ||
    target.dataset.role !== "service-package"
  ) {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === target.dataset.petId,
  );

  if (!selection || selection.servicePackage !== target.value) {
    return;
  }

  event.preventDefault();
  target.checked = false;
  selection.servicePackage = "";
  updatePetServiceSelection(selection, { dataset: { role: "" } });

  renderPetServiceSelections(target.dataset.petId);
  updateServiceNotice();
  saveCurrentStepDraft();
}

function handleSelectionChange(event) {
  const target = event.target;
  const petId = target.dataset.petId;

  if (target.dataset.role === "special-instructions") return;
  if (!petId) {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === petId,
  );

  if (!selection) {
    return;
  }

  if (target.dataset.role === "pet-groomer") selection.groomerId = target.value ? Number(target.value) : null;
  const guideToInstructions = updatePetServiceSelection(selection, target);
  const card = elements.petServiceSelections.querySelector(`[data-pet-card="${CSS.escape(petId)}"]`);
  if (["grooming-preference", "haircut-requested", "pet-groomer"].includes(target.dataset.role)) {
    updateSpecialInstructions(card, { id: petId }, selection);
  } else renderPetServiceSelections(petId);
  if (guideToInstructions) focusSpecialInstructions(card);
  updateServiceNotice();
  saveCurrentStepDraft();
}

function handleSelectionInput(event) {
  const target = event.target;
  const petId = target.dataset.petId;

  if (!petId || target.dataset.role !== "special-instructions") {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === petId,
  );

  if (!selection) {
    return;
  }

  selection.specialInstructions = target.value;
  const card = target.closest('[data-pet-card]');
  if (card.querySelector('[data-role="instructions-error"]').hidden === false) {
    updateSpecialInstructions(card, { petName: state.bookingDraft.pets.find((pet) => pet.id === petId)?.petName }, selection, true);
  }

  saveCurrentStepDraft();
}

function handleSubmit(event) {
  event.preventDefault();
  if (!state.catalogueReady) return;
  if (!validateSpecialInstructions(elements.petServiceSelections, state.bookingDraft.pets, state.petSelections)) return;
  if (state.petSelections.some((selection) => !hasPetGroomerSelection(selection))) {
    updateServiceNotice("Select an available Pet Groomer for each pet.");
    return;
  }

  const incompletePets = state.bookingDraft.pets.filter((pet) => {
    const selection = getSelectionByPetId(pet.id);
    return !hasCompletedRequiredServiceSelection(pet, selection);
  });

  if (incompletePets.length > 0) {
    updateServiceNotice("Select services and a Haircut Preference where required for each pet.");

    const firstIncompletePet = incompletePets[0];
    const firstCard = elements.petServiceSelections.querySelector(
      `[data-pet-card="${CSS.escape(firstIncompletePet.id)}"]`,
    );

    firstCard?.scrollIntoView({ behavior: globalThis.matchMedia?.("(prefers-reduced-motion: reduce)").matches ? "instant" : "smooth", block: "start" });
    firstCard?.querySelector("input:not(:disabled)")?.focus({ preventScroll: true });
    return;
  }

  saveCurrentStepDraft();
  goToGroomingStep("schedule");
}

function saveCurrentStepDraft() {
  sessionStorage.setItem("groomingSelectedPricing", selectedPricingSignature(state.bookingDraft, state.petSelections));
  sessionStorage.removeItem("groomingReviewedPricing");
  sessionStorage.setItem(
    BOOKING_STEP_THREE_KEY,
    JSON.stringify(buildStepThreeDraftPayload(state.petSelections)),
  );
}
