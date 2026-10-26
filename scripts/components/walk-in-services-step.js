import { renderServiceSelections, updateSpecialInstructions, validateSpecialInstructions, focusSpecialInstructions } from "./grooming-service-selection.js?v=20261026&grooming=actual-20261026";
import { loadPetGroomers, normalizePetGroomerSelections, hasPetGroomerSelection } from "./pet-groomer-selection.js?services=20261026";
import {
  hasCompletedRequiredServiceSelection,
  updatePetServiceSelection,
  loadGroomingCatalogue,
  normalizeStepThreeDraft,
  createEmptyPetServiceSelection,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026";
import { renderWalkInReviewStep } from "./walk-in-review-step.js?v=grooming-pricing-20261006&capacity=20261010&pet-weight=pet-weight-size-20261011-v3&groomers=named-20261016&consent=20261017&catalog=services-20261017-2&session=walk-in-20261021&nav=walk-in-home-20261025&services=20261026&grooming=actual-20261026";

const state = {
  pets: [],
  petSelections: [],
  catalogueReady: false,
};

let elements = {};

function getServicesMainMarkup() {
  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <section data-session-public>
        <a
          id="walkInServicesBackTopBtn"
          href="./dashboard.html"
          class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-slate-700"
        >
          &larr; Back to Home
        </a>

        <div class="mt-4">
          <h1 class="text-3xl font-bold text-[#2f4b66]">
            Select your Grooming Services
          </h1>
        </div>

        <div class="mt-6">
          <div class="mb-2 flex items-center justify-between text-xs font-medium text-slate-500">
            <span>Step 3 of 5</span>
            <span>60% Complete</span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
            <div class="h-full rounded-full bg-[#315b7e]" style="width: 60%"></div>
          </div>
        </div>
      </section>

      <section data-session-surface class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Grooming Services</h2>
        </div>

        <div id="serviceNotice" role="alert" hidden></div>

        <form id="walkInServicesForm" class="space-y-8" novalidate>
          <section>

            <div id="petServiceSelections" class="space-y-6"></div>
          </section>

          <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <button
              id="walkInServicesBackBtn"
              type="button"
              class="inline-flex items-center justify-center rounded-xl border border-[#315b7e] px-5 py-3 text-sm font-medium text-[#315b7e] transition hover:bg-white"
            >
              Back
            </button>

            <button
              id="walkInServicesNextBtn"
              type="submit"
              disabled
              class="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white opacity-50 transition hover:bg-[#274a67]"
            >
              Next Step
            </button>
          </div>
        </form>
      </section>
    </main>
  `;
}

function refreshElements() {
  elements = {
    form: document.getElementById("walkInServicesForm"),
    serviceNotice: document.getElementById("serviceNotice"),
    petServiceSelections: document.getElementById("petServiceSelections"),
    nextButton: document.getElementById("walkInServicesNextBtn"),
    backButton: document.getElementById("walkInServicesBackBtn"),
  };
}

function normalizePet(pet, index) {
  return {
    ...pet,
    id: pet?.id || `walk-in-pet-${index + 1}`,
    petName: pet?.petName || "",
    petType: pet?.petType || "",
    breed: pet?.breed || "",
    size: pet?.size || "",
    petId: pet?.petId || null,
    weight: pet?.weight ?? "",
    furType: pet?.furType || "",
    medicalNotes: pet?.medicalNotes || "",
  };
}

async function loadServicePricing() {
  state.catalogueReady = false;
  syncNextButtonState(false);
  elements.petServiceSelections.innerHTML = '<p class="py-6 text-sm text-slate-500" role="status">Loading grooming pricing...</p>';
  try {
    await Promise.all([loadGroomingCatalogue(), loadPetGroomers()]);
    state.petSelections = normalizeStepThreeDraft({petSelections: state.petSelections}, {pets: state.pets});
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
  if (!elements.form || !elements.petServiceSelections) {
    return;
  }

  elements.petServiceSelections.addEventListener("click", handleSelectionClick);
  elements.petServiceSelections.addEventListener("change", handleSelectionChange);
  elements.petServiceSelections.addEventListener("input", handleSelectionInput);
  elements.form.addEventListener("submit", handleSubmit);
  elements.backButton.addEventListener("click", () => {
    window.location.href = "./walk-in-pet-details.html";
  });
}

function renderMissingPetState() {
  elements.petServiceSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      No pet was found for this walk-in step. Please go back to Step 2 and add at least one pet before continuing.
    </div>
  `;

  elements.serviceNotice.hidden = false;
  elements.serviceNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.serviceNotice.textContent =
    "Please complete Step 2 first so each pet can receive its own grooming selection.";

  syncNextButtonState(false);
}

function renderPetServiceSelections(petId = null) {
  renderServiceSelections(elements.petServiceSelections, state.pets, state.petSelections, true, petId);
}

function getSelectionByPetId(petId) {
  return (
    state.petSelections.find((selection) => selection.petId === petId) ||
    createEmptyPetServiceSelection({ id: petId })
  );
}

function updateServiceNotice(validationMessage = "") {
  const readyPetCount = state.pets.filter((pet) =>
    hasCompletedRequiredServiceSelection(pet, getSelectionByPetId(pet.id)) && hasPetGroomerSelection(getSelectionByPetId(pet.id), true),
  ).length;

  const allPetsReady = readyPetCount === state.pets.length && state.pets.length > 0;
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
    updateSpecialInstructions(card, { petName: state.pets.find((pet) => pet.id === petId)?.petName }, selection, true);
  }

}

function handleSubmit(event) {
  event.preventDefault();
  if (!state.catalogueReady) return;
  if (!validateSpecialInstructions(elements.petServiceSelections, state.pets, state.petSelections)) return;

  if (state.petSelections.some((selection) => !hasPetGroomerSelection(selection, true))) {
    updateServiceNotice("Select an available Pet Groomer for each pet.");
    return;
  }
  const incompletePets = state.pets.filter((pet) => {
    const selection = getSelectionByPetId(pet.id);
    return !hasCompletedRequiredServiceSelection(pet, selection);
  });

  if (incompletePets.length > 0) {
    updateServiceNotice("Select services and a Haircut Preference where required for each pet.");
    return;
  }

  window.history.pushState(null, "", "./walk-in-booking-review.html");
  renderWalkInReviewStep({
    pets: state.pets,
    petSelections: state.petSelections,
    onBack: () => {
      window.history.pushState(null, "", "./walk-in-grooming-services.html");
      renderWalkInServicesStep({
        pets: state.pets,
        petSelections: state.petSelections,
      });
    },
  });
}

function initServicesState(pets, petSelections = []) {
  state.pets = Array.isArray(pets) ? pets.map(normalizePet) : [];
  state.petSelections = state.pets.map((pet) => {
    const existingSelection = petSelections.find(
      (selection) => selection?.petId === pet.id,
    );

    return existingSelection || createEmptyPetServiceSelection(pet);
  });
}

export async function renderWalkInServicesStep({ pets = [], petSelections = [] } = {}) {
  if (!await API.waitForSessionVerification()) return;
  document.title = "Walk-in - Grooming Services";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.querySelector("main").outerHTML = getServicesMainMarkup();

  refreshElements();
  initServicesState(pets, petSelections);
  bindEvents();

  if (state.pets.length === 0) {
    renderMissingPetState();
  } else {
    loadServicePricing();
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  if (!document.getElementById("walkInServicesForm")) {
    return;
  }

  if (!await API.waitForSessionVerification()) {
    return;
  }

  refreshElements();
  initServicesState([]);
  bindEvents();
  renderMissingPetState();

});
