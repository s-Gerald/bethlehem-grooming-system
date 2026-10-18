import { renderEstimateSelection, updateEstimateSelection, renderSelectionsPreservingFocus } from "./grooming-estimate-selection.js?catalog=services-20261017-2";
import { loadPetGroomers, normalizePetGroomerSelections, hasPetGroomerSelection, renderPetGroomerSelection } from "./pet-groomer-selection.js";
import {
  escapeHtml,
  formatPetSizeLabel,
  formatPetTypeLabel,
} from "../services/booking-draft-service.js";
import {
  hasRequiredGroomingPreference,
  estimatePetGrooming,
  loadGroomingCatalogue,
  normalizeStepThreeDraft,
  calculatePetSelectionPricing,
  getGroomingPricePresentation,
  createEmptyPetServiceSelection,
  formatPriceOption,
  getAlaCarteServiceById,
  getAlaCarteServicesByPetType,
  getPackageById,
  getPackageAlaCarteRules,
  getPackagesByPetType,
} from "../services/grooming-service.js?v=managed-services-20261017-2";
import { renderWalkInReviewStep } from "./walk-in-review-step.js?v=grooming-pricing-20261006&capacity=20261010&pet-weight=pet-weight-size-20261011-v3&groomers=named-20261016&consent=20261017&catalog=services-20261017-2";

const state = {
  pets: [],
  petSelections: [],
  catalogueReady: false,
};

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Service selections and pricing are currently calculated from frontend state.
  When backend persistence is added, store selected package IDs, a la carte IDs,
  and pet notes on the walk-in draft, then recalculate trusted totals server-side.
*/

let elements = {};

function getServicesMainMarkup() {
  return `
    <main class="mx-auto max-w-6xl px-4 py-8 md:px-6 lg:px-8">
      <section>
        <button
          id="walkInServicesBackTopBtn"
          type="button"
          class="inline-flex items-center gap-2 text-sm font-medium text-[#315b7e] hover:underline"
        >
          &larr; Back to Pet Information
        </button>

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

      <section class="mt-6 rounded-[28px] border border-slate-200 bg-[#d9e8f4] p-5 shadow-sm md:p-8">
        <div class="mb-6">
          <h2 class="text-2xl font-bold text-[#2f4b66]">Grooming Services</h2>
        </div>

        <div
          id="serviceNotice"
          class="mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600"
        >
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Service Rules</p>
          <p class="mt-2">Choose one package, A la Carte services, or both. Items already included in the selected package will be unavailable.</p>
        </div>

        <form id="walkInServicesForm" class="space-y-8" novalidate>
          <section>
            <div class="mb-3">
              <h3 class="text-base font-semibold text-[#2f4b66]">
                Service Selection per Pet
              </h3>
            </div>

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
    backButtons: [
      document.getElementById("walkInServicesBackTopBtn"),
      document.getElementById("walkInServicesBackBtn"),
    ].filter(Boolean),
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
  elements.backButtons.forEach((button) => {
    button.addEventListener("click", () => {
      window.location.href = "./walk-in-pet-details.html";
    });
  });
}

function renderMissingPetState() {
  elements.petServiceSelections.innerHTML = `
    <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
      No pet was found for this walk-in step. Please go back to Step 2 and add at least one pet before continuing.
    </div>
  `;

  elements.serviceNotice.className =
    "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
  elements.serviceNotice.textContent =
    "Please complete Step 2 first so each pet can receive its own grooming selection.";

  syncNextButtonState(false);
}

function renderPetServiceSelections() {
  renderSelectionsPreservingFocus(elements.petServiceSelections, state.pets
    .map((pet, index) => renderPetSelectionCard(pet, index))
    .join(""),
    state.pets.map((pet) => `${pet.petName}: ${estimatePetGrooming(getSelectionByPetId(pet.id), pet)?.formatted || "Complete the service selection"}`).join(". "));
}

function renderPetSelectionCard(pet, index) {
  const selection = getSelectionByPetId(pet.id);
  const petPricing = calculatePetSelectionPricing(selection, pet);
  const selectionSummary = getPetSelectionSummary(selection, petPricing);
  const allowsAlaCarteOnly = petCanUseAlaCarteOnly(pet);
  const packageCards = getPackagesByPetType(pet.petType)
    .map((service) => renderPackageCard(pet, selection, service))
    .join("");
  const alaCarteServices = getAlaCarteServicesByPetType(pet.petType);

  return `
    <article
      data-pet-card="${escapeHtml(pet.id)}"
      class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm md:p-6"
    >
      <div class="mb-6">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Pet ${index + 1}</p>
            <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
              <h3 class="text-2xl font-bold text-[#2f4b66]">${escapeHtml(pet.petName || "Unnamed Pet")}</h3>
              <p class="text-base font-semibold text-slate-600">${escapeHtml(formatPetTypeLabel(pet.petType))} · ${escapeHtml(pet.breed || "Breed not specified")}</p>
            </div>
          </div>
          <span class="shrink-0 rounded-full bg-[#edf5fc] px-3 py-1 text-xs font-semibold text-[#315b7e]">
            ${escapeHtml(formatPetSizeLabel(pet.size))} · ${pet.sizeVerified ? "Clinic verified" : "Estimated from weight"}
          </span>
        </div>
        ${pet.sizeVerified ? "" : '<p class="mt-2 text-xs text-slate-500">Size may be confirmed by clinic staff at check-in.</p>'}
      </div>

      ${renderPetGroomerSelection(pet, selection, true)}
      <section>
        <div class="mb-3">
          <h4 class="text-base font-semibold text-[#2f4b66]">
            ${
              allowsAlaCarteOnly
                ? "Package Options"
                : 'Service Package <span class="text-red-500">*</span>'
            }
          </h4>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
          ${packageCards}
        </div>
        ${renderEstimateSelection(pet, selection, true)}
      </section>

      ${
        alaCarteServices.length > 0
          ? `
            <section class="mt-8">
              <div class="mb-3">
                <h4 class="text-base font-semibold text-[#2f4b66]">A la Carte Menu</h4>
              </div>

              <div class="space-y-2">
                ${alaCarteServices
                  .map((service) =>
                    renderAlaCarteCard(pet, selection, service),
                  )
                  .join("")}
              </div>
            </section>
          `
          : ""
      }

      <section class="mt-8 grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(260px,0.55fr)] md:items-stretch">
        <div>
          <div class="mb-3">
            <h4 class="text-base font-semibold text-[#2f4b66]">
              Grooming &amp; Visit Notes <span class="font-normal text-slate-500">(optional)</span>
            </h4>
          </div>
          <textarea
            data-role="special-instructions"
            data-pet-id="${escapeHtml(pet.id)}"
            rows="4"
            maxlength="1000"
            placeholder="Add grooming, handling, or owner-specific notes for this visit."
            class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20"
          >${escapeHtml(selection.specialInstructions || "")}</textarea>
        </div>
        <aside data-role="selection-summary" aria-live="polite" class="flex flex-col rounded-2xl border border-[#b8cadb] bg-[#f4f8fc] p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">${selectionSummary.price ? escapeHtml(selectionSummary.priceLabel) : "Selected Package &amp; Price"}</p>
          <p class="mt-2 text-base font-semibold leading-relaxed text-[#2f4b66]">${escapeHtml(selectionSummary.label)}</p>
          ${selectionSummary.price ? `<p class="mt-auto pt-4 text-right text-lg font-bold text-[#2f4b66]">${escapeHtml(selectionSummary.price)}</p>` : ""}
        </aside>
      </section>
    </article>
  `;
}

function renderPackageCard(pet, selection, service) {
  const isChecked = selection.servicePackage === service.id;
  const selectedSizeLabel = formatPetSizeLabel(pet.size);

  return `
    <div class="flex h-full flex-col gap-3">
      <label
        class="service-card ${isChecked ? "service-card--selected" : ""} flex h-full cursor-pointer items-start gap-3 rounded-2xl border border-[#91aeca] bg-white p-4 transition hover:border-[#315b7e] hover:shadow-sm"
      >
        <input
          type="radio"
          name="service_package_${escapeHtml(pet.id)}"
          value="${escapeHtml(service.id)}"
          data-role="service-package"
          data-pet-id="${escapeHtml(pet.id)}"
          class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]"
          ${isChecked ? "checked" : ""}
        />
        <div class="service-card__content">
          <h4 class="font-semibold text-[#2f4b66]">${escapeHtml(service.name)}</h4>
          <ul class="mt-2 space-y-1 text-sm text-slate-600">
            ${service.descriptionItems
              .map((item) => `<li>&bull; ${escapeHtml(item)}</li>`)
              .join("")}
          </ul>
          <div class="service-card__pricing">
            <span class="service-card__pricing-label">Size &amp; Price</span>
            <div class="service-card__price-grid">
              ${service.priceOptions
                .map((priceOption) => renderPricePill(priceOption, selectedSizeLabel))
                .join("")}
            </div>
          </div>
        </div>
      </label>
    </div>
  `;
}

function renderPricePill(priceOption, selectedSizeLabel) {
  const shouldHighlight = priceOption.label === selectedSizeLabel;

  return `
    <div class="service-card__price-pill ${shouldHighlight ? "service-card__price-pill--active" : ""}">
      <span class="service-card__price-size">${escapeHtml(priceOption.label)}</span>
      <span class="service-card__price-value">${escapeHtml(
        formatPriceOption(priceOption),
      )}</span>
    </div>
  `;
}

function renderAlaCarteCard(pet, selection, service) {
  const isChecked = selection.alaCarteServices.includes(service.id);
  const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
  const isDisabled =
    Boolean(selection.servicePackage) &&
    packageAlaCarteRules.canCombineWithAlaCarte &&
    packageAlaCarteRules.includedAlaCarteServiceIds.includes(service.id);
  const labelClasses = [
    isChecked ? "service-option--selected" : "",
    "flex items-center gap-3 rounded-xl border px-3 py-2 text-sm transition",
    isDisabled
      ? "cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400"
      : "cursor-pointer border-slate-200 text-slate-600 hover:border-[#315b7e] hover:bg-slate-50",
  ]
    .filter(Boolean)
    .join(" ");
  const titleClasses = isDisabled ? "font-semibold text-slate-400" : "font-semibold text-[#2f4b66]";
  const priceClasses = isDisabled ? "mt-1 block text-sm text-slate-400" : "mt-1 block text-sm text-slate-500";

  return `
    <label class="${labelClasses}">
      <input
        type="checkbox"
        value="${escapeHtml(service.id)}"
        data-role="ala-carte"
        data-pet-id="${escapeHtml(pet.id)}"
        class="h-4 w-4 shrink-0 accent-[#315b7e]"
        ${isDisabled ? "disabled" : ""}
        ${isChecked ? "checked" : ""}
      />
      <div class="flex min-w-0 flex-1 items-center justify-between gap-3">
        <div class="min-w-0">
          <span class="${titleClasses}">${escapeHtml(service.name)}</span>
          <span class="${priceClasses}">${escapeHtml(formatPriceOption(service.priceOptions[0]))}</span>
        </div>
        ${
          isDisabled
            ? '<span class="ml-auto shrink-0 rounded-full bg-slate-100 px-2 py-1 text-right text-xs font-medium text-slate-400">Included in Package</span>'
            : ""
        }
      </div>
    </label>
  `;
}

function getSelectionByPetId(petId) {
  return (
    state.petSelections.find((selection) => selection.petId === petId) ||
    createEmptyPetServiceSelection({ id: petId })
  );
}

function petCanUseAlaCarteOnly(pet) {
  return getAlaCarteServicesByPetType(pet?.petType).length > 0;
}

function petRequiresPackage(pet) {
  return !petCanUseAlaCarteOnly(pet);
}

function hasCompletedRequiredServiceSelection(pet, selection) {
  if (!pet || !hasRequiredGroomingPreference(selection) || !hasPetGroomerSelection(selection, true)) {
    return false;
  }

  if (petRequiresPackage(pet)) {
    return Boolean(selection?.servicePackage);
  }

  return calculatePetSelectionPricing(selection, pet).hasSelection;
}

function getPetSelectionSummary(selection, petPricing) {
  if (!petPricing.hasSelection) {
    return { label: "No service selected yet.", price: "" };
  }

  const alaCarteLabels = selection.alaCarteServices
    .map((serviceId) => getAlaCarteServiceById(serviceId)?.name)
    .filter(Boolean);

  const presentation = getGroomingPricePresentation(petPricing.total);
  const price = presentation.amount;

  if (selection.servicePackage) {
    const selectedPackage = getPackageById(selection.servicePackage);
    const label =
      alaCarteLabels.length > 0
        ? `${selectedPackage?.name || "Selected package"} + ${alaCarteLabels.join(", ")}`
        : selectedPackage?.name || "Selected package";

    return { label, price, priceLabel: presentation.label };
  }

  if (alaCarteLabels.length > 0) {
    return { label: alaCarteLabels.join(", "), price, priceLabel: presentation.label };
  }

  return { label: "No service selected yet.", price: "" };
}

function updateServiceNotice(validationMessage = "") {
  const readyPetCount = state.pets.filter((pet) =>
    hasCompletedRequiredServiceSelection(pet, getSelectionByPetId(pet.id)),
  ).length;

  const allPetsReady = readyPetCount === state.pets.length && state.pets.length > 0;
  const hasValidationError = Boolean(validationMessage);

  syncNextButtonState(allPetsReady);

  elements.serviceNotice.className = hasValidationError
    ? "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
    : "mb-6 rounded-2xl border border-[#9bb9d3] bg-white px-4 py-3 text-sm text-slate-600";

  elements.serviceNotice.innerHTML = "";

  if (hasValidationError) {
    const error = document.createElement("p");
    error.className = "font-semibold";
    error.textContent = validationMessage;
    elements.serviceNotice.appendChild(error);
    return;
  }

  const title = document.createElement("p");
  title.className = "text-xs font-semibold uppercase tracking-wide text-slate-400";
  title.textContent = "Service Rules";
  elements.serviceNotice.appendChild(title);

  const message = document.createElement("p");
  message.className = "mt-2";
  message.textContent =
    "Choose one package, A la Carte services, or both. Items already included in the selected package will be unavailable.";
  elements.serviceNotice.appendChild(message);
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
  selection.groomingPreference = "";

  renderPetServiceSelections();
  updateServiceNotice();
}

function handleSelectionChange(event) {
  const target = event.target;
  const petId = target.dataset.petId;

  if (!petId) {
    return;
  }

  const selection = state.petSelections.find(
    (petSelection) => petSelection.petId === petId,
  );

  if (!selection) {
    return;
  }

  updateEstimateSelection(selection, target);
  if (target.dataset.role === "pet-groomer") selection.groomerId = Number(target.value);

  if (target.dataset.role === "service-package") {
    selection.servicePackage = target.value;
    selection.groomingPreference = "";
    const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
    const blockedServiceIds = new Set(packageAlaCarteRules.includedAlaCarteServiceIds);

    selection.alaCarteServices = packageAlaCarteRules.canCombineWithAlaCarte
      ? selection.alaCarteServices.filter((serviceId) => !blockedServiceIds.has(serviceId))
      : [];
  }

  if (target.dataset.role === "ala-carte") {
    const petCard = elements.petServiceSelections.querySelector(
      `[data-pet-card="${CSS.escape(petId)}"]`,
    );
    const packageAlaCarteRules = getPackageAlaCarteRules(selection.servicePackage);
    const blockedServiceIds = new Set(packageAlaCarteRules.includedAlaCarteServiceIds);

    selection.alaCarteServices = Array.from(
      petCard?.querySelectorAll('[data-role="ala-carte"]:checked') || [],
    )
      .map((item) => item.value)
      .filter((serviceId) =>
        packageAlaCarteRules.canCombineWithAlaCarte
          ? !blockedServiceIds.has(serviceId)
          : true,
      );

    if (selection.alaCarteServices.length > 0 && !packageAlaCarteRules.canCombineWithAlaCarte) {
      selection.servicePackage = "";
      selection.groomingPreference = "";
    }
  }

  renderPetServiceSelections();
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
}

function handleSubmit(event) {
  event.preventDefault();
  if (!state.catalogueReady) return;

  if (state.petSelections.some((selection) => !hasPetGroomerSelection(selection, true))) {
    updateServiceNotice("Select an available Pet Groomer for each pet.");
    return;
  }
  const incompletePets = state.pets.filter((pet) => {
    const selection = getSelectionByPetId(pet.id);
    return !hasCompletedRequiredServiceSelection(pet, selection);
  });

  if (incompletePets.length > 0) {
    const packageRequiredPets = incompletePets.filter((pet) => petRequiresPackage(pet));
    const flexibleServicePets = incompletePets.filter(
      (pet) => !petRequiresPackage(pet),
    );
    const validationMessages = [];

    if (packageRequiredPets.length > 0) {
      validationMessages.push(
        `Please select a grooming package for ${packageRequiredPets
          .map((pet) => pet.petName || "each remaining dog")
          .join(", ")} before continuing.`,
      );
    }

    if (flexibleServicePets.length > 0) {
      validationMessages.push(
        `Please select one package or at least one a la carte service for ${flexibleServicePets
          .map((pet) => pet.petName || "each remaining pet")
          .join(", ")} before continuing.`,
      );
    }

    updateServiceNotice("Choose services and a grooming preference where required for every pet. " + validationMessages.join(" "));
    return;
  }

  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This only passes in-memory selections to the review screen. Replace or pair
    it with a draft-save call once walk-in service selections are database-backed.
  */
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

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    Frontend access checks are for navigation only. The eventual service-draft
    endpoint should authorize admin/staff users independently.
  */
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
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

export function renderWalkInServicesStep({ pets = [], petSelections = [] } = {}) {
  document.title = "Walk-in - Grooming Services";
  document.body.className = "min-h-screen bg-slate-50 text-slate-800";
  document.body.innerHTML = getServicesMainMarkup();

  refreshElements();
  initServicesState(pets, petSelections);
  bindEvents();

  if (state.pets.length === 0) {
    renderMissingPetState();
  } else {
    loadServicePricing();
  }

}

document.addEventListener("DOMContentLoaded", () => {
  if (!document.getElementById("walkInServicesForm")) {
    return;
  }

  if (!guardAdminAccess()) {
    return;
  }

  refreshElements();
  initServicesState([]);
  bindEvents();
  renderMissingPetState();

});
