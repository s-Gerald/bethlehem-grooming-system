import { renderWalkInServicesStep } from "./walk-in-services-step.js?v=grooming-pricing-20261006&capacity=20261010&pet-weight=pet-weight-size-20261011-v3&groomers=named-20261016&consent=20261017&catalog=services-20261017-2";
import { renderWalkInClinicComplaintStep } from "./walk-in-clinic-complaint-step.js?pet-weight=pet-weight-size-20261011-v3";
import { createBreedCombobox } from "./breed-combobox.js?v=walk-in-owner-mode-20260924";
import { createBreedCoatCombobox } from "./breed-coat-combobox.js";
import { createFixedOptionCombobox } from "./fixed-option-combobox.js";
import { showPetSelectionSection } from "./pet-selection-tabs.js?v=walk-in-owner-mode-20260924";
import { formatPetSizeLabel } from "../services/booking-draft-service.js";
import {
  formatSavedWeight,
  getPetWeightSubmissionValidationMessage,
  getSizeForStoredWeight,
  getEnteredWeight,
  getSizeForWeight,
  getSizeOptions,
  getWeightFieldValidationMessage,
  getWeightValidationMessage,
  initializeWeightField,
  syncPetWeightAndSize as syncWeightSizeFields,
  showWeightValidationInField,
} from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";

const MAX_PETS_PER_BOOKING = getOwnerAppointmentType() === "clinic" ? 1 : 10;
const WALK_IN_PET_STORAGE_KEY = "walkInPetStep";

const elements = {
  petSelectionTabs: document.getElementById("petSelectionTabs"),
  petSelectionLimitText: document.getElementById("petSelectionLimitText"),
  showExistingPetBtn: document.getElementById("showExistingPetBtn"),
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepError: document.getElementById("petStepError"),
  addPetSection: document.getElementById("addPetSection"),
  addPetForm: document.getElementById("addPetForm"),
  petType: document.getElementById("petType"),
  petName: document.getElementById("petName"),
  breed: document.getElementById("breed"),
  weight: document.getElementById("weight"),
  furType: document.getElementById("furType"),
  size: document.getElementById("size"),
  medicalNotes: document.getElementById("medicalNotes"),
  selectedPetCount: document.getElementById("selectedPetCount"),
  selectedPetsEmptyState: document.getElementById("selectedPetsEmptyState"),
  selectedPetCards: document.getElementById("selectedPetCards"),
  existingPetsSection: document.getElementById("existingPetsSection"),
  existingPetsOwnerText: document.getElementById("existingPetsOwnerText"),
  existingPetsCount: document.getElementById("existingPetsCount"),
  existingPetsLoading: document.getElementById("existingPetsLoading"),
  existingPetCards: document.getElementById("existingPetCards"),
  backBtn: document.getElementById("backBtn"),
  ownerBackLink: document.getElementById("ownerBackLink"),
  nextBtn: document.getElementById("nextBtn"),
  fieldErrors: {
    petType: document.getElementById("petTypeError"),
    petName: document.getElementById("petNameError"),
    size: document.getElementById("sizeError"),
  },
};

const petTypeCombobox = createFixedOptionCombobox({
  root: document.getElementById("petTypeCombobox"),
  input: elements.petType,
  listbox: document.getElementById("petTypeOptions"),
  toggleButton: document.getElementById("petTypeDropdownButton"),
  placeholder: "Select pet type",
  options: [
    { value: "Dog", label: "Dog" },
    { value: "Cat", label: "Cat" },
  ],
});

const breedCombobox = createBreedCombobox({
  root: document.getElementById("breedCombobox"),
  input: elements.breed,
  listbox: document.getElementById("breedOptions"),
  toggleButton: document.getElementById("breedDropdownButton"),
  errorElement: document.getElementById("breedError"),
  petTypeInput: elements.petType,
  getPetType: () => elements.petType.value,
});

const breedCoatCombobox = createBreedCoatCombobox({
  root: document.getElementById("furTypeCombobox"),
  breedInput: elements.breed,
  petTypeInput: elements.petType,
  input: elements.furType,
  listbox: document.getElementById("furTypeOptions"),
  toggleButton: document.getElementById("furTypeDropdownButton"),
  errorElement: document.getElementById("furTypeError"),
});

const sizeCombobox = createFixedOptionCombobox({
  root: document.getElementById("sizeCombobox"),
  input: elements.size,
  listbox: document.getElementById("sizeOptions"),
  toggleButton: document.getElementById("sizeDropdownButton"),
  placeholder: "Select size",
  options: [],
  displaySelectedLabel: false,
});

const state = {
  pets: [],
  existingPets: [],
  existingPetsLoading: false,
  owner: null,
};
let hasSubmittedOnce = false;

function isExistingCustomer() {
  return state.owner?.ownerRecordType === "registered"
    || state.owner?.ownerRecordType === "unregistered";
}

/*
  BACKEND TEAMMATE + CLAUDE CODE:
  Walk-in pet records are held in memory while the staff/admin user moves
  through the frontend flow. Persist these pets under the walk-in booking draft
  once a database-backed draft endpoint exists.
*/

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function normalizeText(value) {
  return String(value || "").trim().replace(/\s+/g, " ");
}

function createPetId() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This temporary browser ID only links pets between frontend steps. Replace
    it with the database pet ID or draft pet ID returned by the backend.
  */
  if (window.crypto?.randomUUID) {
    return window.crypto.randomUUID();
  }

  return `walk-in-pet-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function showStepError(message) {
  elements.petStepError.textContent = message;
  elements.petStepError.classList.remove("hidden");
}

function clearStepError() {
  elements.petStepError.textContent = "";
  elements.petStepError.classList.add("hidden");
}

function getFormValues(form) {
  return {
    petType: form.petType.value,
    petName: normalizeText(form.petName.value),
    breed: normalizeText(form.breed.value),
    weight: getEnteredWeight(elements.weight),
    furType: form.furType.value,
    size: sizeCombobox.getValue(),
    medicalNotes: normalizeText(form.medicalNotes.value),
  };
}

function readPetStepDraft() {
  try {
    return JSON.parse(sessionStorage.getItem(WALK_IN_PET_STORAGE_KEY) || "null");
  } catch {
    return null;
  }
}

function savePetStepDraft() {
  sessionStorage.setItem(
    WALK_IN_PET_STORAGE_KEY,
    JSON.stringify({
      pets: state.pets,
      newPetForm: getFormValues(elements.addPetForm),
    }),
  );
}

function restorePetStepDraft() {
  const draft = readPetStepDraft();
  const values = draft?.newPetForm || {};

  state.pets = Array.isArray(draft?.pets) ? draft.pets : [];
  petTypeCombobox.setValue(values.petType || "");
  elements.petName.value = values.petName || "";
  breedCombobox.setValue(values.petType ? values.breed || "" : "");

  if (values.weight) {
    elements.weight.value = values.weight;
  }

  elements.furType.value = values.petType ? values.furType || "" : "";
  breedCoatCombobox.update({ preserveValue: true, autoSelect: false });

  syncWeightAndSize({ clearManualSize: true });
  if (values.size && !getSizeForWeight(elements.petType.value, elements.weight.value)) {
    sizeCombobox.setValue(values.size);
  }

  elements.medicalNotes.value = values.medicalNotes || "";
}

function validatePetForm(values) {
  const errors = {};

  if (!values.petType.trim()) {
    errors.petType = "Pet type is required.";
  }

  if (!values.petName.trim()) {
    errors.petName = "Pet name is required.";
  } else {
    const normalizedName = values.petName.trim().toLowerCase();
    const duplicate = [...state.existingPets, ...state.pets].some((pet) =>
      String(pet.petName || "").trim().toLowerCase() === normalizedName,
    );
    if (duplicate) errors.petName = "This owner already has a pet with this name.";
  }

  const breedValidationMessage = breedCombobox.getValidationMessage();
  if (breedValidationMessage) {
    errors.breed = breedValidationMessage;
  }

  const furTypeValidationMessage = breedCoatCombobox.getValidationMessage();
  if (furTypeValidationMessage) {
    errors.furType = furTypeValidationMessage;
  }

  const weightValidationMessage = getWeightFieldValidationMessage(elements.weight)
    || getWeightValidationMessage(values.petType, values.weight);
  if (weightValidationMessage) {
    errors.weight = weightValidationMessage;
  }

  const allowedSizes = getSizeOptions(values.petType).map(({ value }) => value);
  if (!values.size.trim()) {
    errors.size = "Size is required.";
  } else if (allowedSizes.length > 0 && !allowedSizes.includes(values.size)) {
    errors.size = `${values.petType || "Selected pet type"} size must be one of: ${allowedSizes.join(", ")}.`;
  }

  if (state.pets.length >= MAX_PETS_PER_BOOKING) {
    errors.limit = `Only ${MAX_PETS_PER_BOOKING} pets are allowed per walk-in schedule.`;
  }

  return errors;
}

function createPetObject(formData) {
  return {
    id: createPetId(),
    petType: formData.petType,
    petName: formData.petName,
    breed: formData.breed,
    weight: formData.weight,
    furType: formData.furType,
    size: formData.size,
    sizeVerified: true,
    medicalNotes: formData.medicalNotes,
    petId: null,
    isNew: true,
  };
}

function normalizeExistingPet(pet) {
  return {
    id: `saved-pet-${pet.id}`,
    petId: pet.id,
    petType: pet.species || "",
    petName: pet.petName || "",
    breed: pet.breed || "",
    weight: formatSavedWeight(pet.weight),
    furType: pet.furType || "",
    size: pet.sizeVerified
      ? pet.size || ""
      : (getSizeForStoredWeight(pet.species, pet.weight).toLowerCase().replaceAll(" ", "_") || pet.size || ""),
    sizeVerified: Boolean(pet.sizeVerified),
    medicalNotes: pet.medicalConditions || "",
    isNew: false,
  };
}

function renderExistingPets() {
  if (!isExistingCustomer()) return;

  elements.existingPetsOwnerText.textContent = `Choose a saved pet for ${state.owner.fullName || "this customer"}, or use Add New Pet.`;
  elements.existingPetsLoading.classList.toggle("hidden", !state.existingPetsLoading);
  elements.existingPetsCount.textContent = `${state.existingPets.length} saved pet${state.existingPets.length === 1 ? "" : "s"}`;

  if (state.existingPets.length === 0) {
    if (state.existingPetsLoading) {
      elements.existingPetCards.innerHTML = "";
      return;
    }
    elements.existingPetCards.innerHTML = `
      <div class="md:col-span-2 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-6 text-center text-sm text-slate-500">
        This customer has no saved pets yet. Use Add New Pet to create one.
      </div>
    `;
    return;
  }

  elements.existingPetCards.innerHTML = state.existingPets.map((pet, index) => {
    const selected = state.pets.some((selectedPet) => selectedPet.petId === pet.petId);
    return `
      <article class="rounded-2xl border border-slate-200 bg-[#f8fbfd] p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h4 class="font-semibold text-[#2f4b66]">${escapeHtml(pet.petName)}</h4>
            <p class="mt-1 text-sm text-slate-500">${escapeHtml(pet.petType || "Pet")}${pet.breed ? ` &middot; ${escapeHtml(pet.breed)}` : ""}</p>
            <p class="mt-1 text-xs text-slate-400">${escapeHtml(pet.size || "Size not provided")} · ${pet.sizeVerified ? "Clinic verified" : "Estimated from weight"}${pet.weight ? ` &middot; ${escapeHtml(formatSavedWeight(pet.weight))} kg` : ""}</p>
          </div>
          <button
            type="button"
            data-select-existing-pet="${index}"
            ${selected ? "disabled" : ""}
            class="rounded-xl px-3 py-2 text-xs font-semibold transition ${selected ? "cursor-not-allowed bg-green-100 text-green-700" : "bg-[#315b7e] text-white hover:bg-[#274864]"}"
          >${selected ? "Selected" : "Select"}</button>
        </div>
      </article>
    `;
  }).join("");
}

function loadExistingOwnerPets() {
  try {
    state.owner = JSON.parse(sessionStorage.getItem("walkInOwnerStep") || "null");
  } catch {
    state.owner = null;
  }

  state.existingPets = Array.isArray(state.owner?.existingPets)
    ? state.owner.existingPets.map(normalizeExistingPet)
    : [];
  state.existingPetsLoading = isExistingCustomer();
}

async function refreshExistingOwnerPets() {
  if (!isExistingCustomer()) return;

  try {
    const response = state.owner.ownerRecordType === "registered"
      ? await API.getCustomerDetails(state.owner.customerUserId)
      : await API.getUnregisteredCustomerDetails(state.owner.unregisteredCustomerId);
    if (Array.isArray(response.customer?.pets)) {
      state.existingPets = response.customer.pets
        .filter((pet) => !pet.isArchived)
        .map(normalizeExistingPet);
    }
  } catch {
    // Keep the pet list passed from Step 1 if refreshing it fails.
  } finally {
    state.existingPetsLoading = false;
    renderExistingPets();
  }
}

function renderSelectedPets() {
  elements.selectedPetCards.innerHTML = "";
  elements.selectedPetCount.textContent = `${state.pets.length} / ${MAX_PETS_PER_BOOKING} selected`;

  const hasPets = state.pets.length > 0;
  elements.selectedPetsEmptyState.classList.toggle("hidden", hasPets);
  elements.nextBtn.disabled = !hasPets;
  elements.nextBtn.classList.toggle("opacity-50", !hasPets);
  elements.nextBtn.classList.toggle("cursor-not-allowed", !hasPets);

  state.pets.forEach((pet) => {
    const showEnteredDetails = !isExistingCustomer() && pet.isNew;
    const enteredDetails = showEnteredDetails
      ? [
        ["Pet Type", pet.petType],
        ["Breed", pet.breed],
        ["Fur Type", pet.furType],
        ["Weight", pet.weight ? `${formatSavedWeight(pet.weight)} kg` : ""],
        ["Size", pet.size ? formatPetSizeLabel(pet.size) : ""],
        ["Medical Conditions / Special Needs", pet.medicalNotes],
      ].filter(([, value]) => String(value ?? "").trim())
        .map(([label, value]) => `<p><span class="font-semibold text-slate-700">${label}:</span> ${escapeHtml(value)}</p>`)
        .join("")
      : "";
    const card = document.createElement("article");
    card.className =
      "rounded-2xl border border-[#c6dbef] bg-[#f8fbfe] p-3";

    card.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
          ${showEnteredDetails ? `<div class="mt-2 grid gap-1 text-xs text-slate-600">${enteredDetails}</div>` : `
          <p class="mt-1 text-sm text-slate-500">${escapeHtml(pet.petType || "Pet")}${pet.breed ? ` · ${escapeHtml(pet.breed)}` : ""}</p>
          <p class="mt-1 text-xs text-slate-500">${pet.size
            ? `Size: ${escapeHtml(formatPetSizeLabel(pet.size))}`
            : pet.weight ? `Weight: ${escapeHtml(formatSavedWeight(pet.weight))} kg` : "Size not provided"}</p>`}
        </div>

        <button
          type="button"
          data-remove-pet-id="${escapeHtml(pet.id)}"
          class="remove-selected-pet-btn rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100"
        >
          Remove
        </button>
      </div>

    `;

    elements.selectedPetCards.appendChild(card);
  });

  bindRemoveButtons();
  renderExistingPets();
  savePetStepDraft();
}

function bindRemoveButtons() {
  const buttons = document.querySelectorAll(".remove-selected-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.removePetId;

      state.pets = state.pets.filter((pet) => pet.id !== petId);
      renderSelectedPets();
      clearStepError();
    });
  });
}

function setFieldError(fieldName, message) {
  const errorElement = elements.fieldErrors[fieldName];
  const input = elements[fieldName];

  if (!errorElement) {
    return;
  }

  if (message) {
    input?.setAttribute("aria-invalid", "true");
    input?.classList.add("border-red-400");
    errorElement.textContent = message;
    errorElement.className = "mt-2 text-sm text-red-600";
    return;
  }

  input?.setAttribute("aria-invalid", "false");
  input?.classList.remove("border-red-400");
  errorElement.textContent = "";
  errorElement.className = "mt-2 hidden text-sm text-red-600";
}

function renderValidationErrors(values, { showAll = false } = {}) {
  const errors = validatePetForm(values);
  const shouldShow = showAll || hasSubmittedOnce;

  Object.keys(elements.fieldErrors).forEach((fieldName) => {
    const message = shouldShow ? errors[fieldName] || "" : "";
    setFieldError(fieldName, message);
  });

  if (shouldShow) {
    breedCombobox.showValidation(errors.breed || "");
    breedCoatCombobox.showValidation(errors.furType || "");
  }

  if (showAll || (hasSubmittedOnce && errors.limit)) {
    showStepError(errors.limit || "Please put valid inputs.");
    return;
  }

  if (showAll) {
    showStepError("Please put valid inputs.");
    return;
  }

  clearStepError();
}

function resetAddPetForm() {
  elements.addPetForm.reset();
  petTypeCombobox.reset();
  breedCombobox.reset();
  breedCoatCombobox.reset();
  sizeCombobox.reset();
  initializeWeightField(elements.weight);
  syncWeightAndSize({ clearManualSize: true });
  savePetStepDraft();
}

function handleAddPetSubmit(event) {
  event.preventDefault();
  hasSubmittedOnce = true;

  const formValues = getFormValues(elements.addPetForm);
  const errors = validatePetForm(formValues);

  if (Object.keys(errors).length > 0) {
    if (errors.weight) {
      showWeightValidationInField(elements.weight, errors.weight);
    }
    renderValidationErrors(formValues, { showAll: true });
    return;
  }

  const newPet = createPetObject(formValues);
  state.pets = [...state.pets, newPet];

  resetAddPetForm();
  renderSelectedPets();
  clearStepError();
}

function handleBack() {
  try {
    const owner = JSON.parse(sessionStorage.getItem("walkInOwnerStep") || "{}");
    if (owner.directEntry === "clinic") {
      window.location.href = "./clinic.html";
      return;
    }
  } catch { /* fall through */ }

  window.location.href = `./walk-in-booking.html?flow=${getOwnerAppointmentType()}`;
}

function getOwnerAppointmentType() {
  try {
    const owner = JSON.parse(sessionStorage.getItem("walkInOwnerStep") || "{}");
    return owner.appointmentType || "grooming";
  } catch {
    return "grooming";
  }
}

function handleNext() {
  const weightMessage = state.pets.map(getPetWeightSubmissionValidationMessage).find(Boolean);
  if (weightMessage) {
    showStepError(weightMessage);
    return;
  }
  if (state.pets.length === 0) {
    showStepError("Please add at least one pet before continuing.");
    return;
  }

  const appointmentType = getOwnerAppointmentType();

  if (appointmentType === "clinic") {
    if (state.pets.length > 1) {
      showStepError("Clinic walk-in supports one pet per visit. Please remove extra pets.");
      return;
    }
    clearStepError();
    savePetStepDraft();
    // The complaint UI is rendered in this document. Keep a reloadable URL
    // instead of advertising an HTML file that does not exist.
    window.history.pushState(null, "", "./walk-in-pet-details.html?step=clinic-complaint");
    renderWalkInClinicComplaintStep({ pet: state.pets[0] });
    return;
  }

  clearStepError();
  savePetStepDraft();
  window.history.pushState(null, "", "./walk-in-grooming-services.html");
  renderWalkInServicesStep({ pets: state.pets });
}

function syncWeightAndSize({ clearManualSize = false } = {}) {
  syncWeightSizeFields(elements.weight, elements.petType.value, sizeCombobox, { clearManualSize });
}

function validateWeightOnCommit() {
  syncWeightAndSize();
  return !getWeightFieldValidationMessage(elements.weight);
}

function handleManualSizeChange() {
  syncWeightAndSize();
}

function guardAdminAccess() {
  /*
    BACKEND TEAMMATE + CLAUDE CODE:
    This only protects the page in the browser. Backend walk-in pet APIs should
    still require an authorized admin/staff token.
  */
  const token = API.getAdminToken?.();
  const role = API.getUserRole?.();

  if (token && (role === "admin" || role === "staff")) {
    return true;
  }

  window.location.href = "../client/sign-in.html";
  return false;
}

function showExistingPetSection() {
  if (!isExistingCustomer()) return;
  showPetSelectionSection({
    existingButton: elements.showExistingPetBtn,
    addButton: elements.showAddPetBtn,
    existingSection: elements.existingPetsSection,
    addSection: elements.addPetSection,
  }, "existing");
  renderExistingPets();
}

function showAddPetSection() {
  showPetSelectionSection({
    existingButton: elements.showExistingPetBtn,
    addButton: elements.showAddPetBtn,
    existingSection: elements.existingPetsSection,
    addSection: elements.addPetSection,
  }, "add");
}

function bindEvents() {
  elements.showExistingPetBtn.addEventListener("click", showExistingPetSection);
  elements.showAddPetBtn.addEventListener("click", showAddPetSection);
  elements.addPetForm.addEventListener("submit", handleAddPetSubmit);
  elements.addPetForm.addEventListener("input", savePetStepDraft);
  elements.addPetForm.addEventListener("change", savePetStepDraft);
  elements.backBtn.addEventListener("click", handleBack);
  elements.ownerBackLink.addEventListener("click", (event) => {
    event.preventDefault();
    handleBack();
  });
  elements.nextBtn.addEventListener("click", handleNext);
  elements.existingPetCards?.addEventListener("click", (event) => {
    const button = event.target.closest("[data-select-existing-pet]");
    if (!button || button.disabled || state.pets.length >= MAX_PETS_PER_BOOKING) return;
    const pet = state.existingPets[Number(button.dataset.selectExistingPet)];
    if (!pet || state.pets.some((selectedPet) => selectedPet.petId === pet.petId)) return;
    state.pets = [...state.pets, { ...pet }];
    renderSelectedPets();
    clearStepError();
  });

  const handlePetInputs = (event) => {
    if (event.target === elements.petType && event.type === "change") {
      syncWeightAndSize({ clearManualSize: true });
      validateWeightOnCommit();
    } else if (event.target === elements.weight) {
      syncWeightAndSize();
      if (event.type === "change") {
        validateWeightOnCommit();
      }
    } else if (event.target === elements.size) {
      handleManualSizeChange();
    }

    const formValues = getFormValues(elements.addPetForm);
    renderValidationErrors(formValues, { showAll: false });
  };

  elements.petType.addEventListener("input", handlePetInputs);
  elements.petType.addEventListener("change", handlePetInputs);
  elements.petName.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("input", handlePetInputs);
  elements.breed.addEventListener("change", handlePetInputs);
  elements.weight.addEventListener("input", handlePetInputs);
  elements.weight.addEventListener("change", handlePetInputs);
  elements.furType.addEventListener("input", handlePetInputs);
  elements.size.addEventListener("change", handlePetInputs);
}

function autoSelectClinicPet() {
  const rawId = sessionStorage.getItem("clinicPreselectedPetId");
  if (!rawId) return;
  sessionStorage.removeItem("clinicPreselectedPetId");

  const petId = Number(rawId);
  const pet = state.existingPets.find(p => p.petId === petId);
  if (!pet) return;
  if (state.pets.some(p => p.petId === pet.petId)) return;

  state.pets = [...state.pets, { ...pet }];
}

function initWalkInPetStep() {
  if (!guardAdminAccess()) {
    return;
  }

  bindEvents();
  loadExistingOwnerPets();
  elements.petSelectionLimitText.textContent = MAX_PETS_PER_BOOKING === 1
    ? "One pet per clinic walk-in visit."
    : "Maximum of 10 pets per walk-in visit.";
  elements.petSelectionTabs.classList.toggle("hidden", !isExistingCustomer());
  if (isExistingCustomer()) showExistingPetSection();
  else showAddPetSection();

  initializeWeightField(elements.weight);
  syncWeightAndSize();
  restorePetStepDraft();
  autoSelectClinicPet();
  renderSelectedPets();
  if (isExistingCustomer()) refreshExistingOwnerPets();

}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initWalkInPetStep, { once: true });
} else {
  initWalkInPetStep();
}
