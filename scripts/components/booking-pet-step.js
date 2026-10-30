import { createColorCombobox } from "./color-combobox.js?pet-care=20261027-v1";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=fcfs-20261026&registration=fcfs-20261027-v1&registration-rollover=20261027-v3";
import {
  MAX_PETS_PER_BOOKING as GROOMING_MAX_PETS,
  getSavedPets,
  getBookingPets as getStoredBookingPets,
  saveBookingPets as saveStoredBookingPets,
  createPetObject,
  addPetToSavedPets,
  addPetToBooking as addStoredPet,
  removePetFromBooking as removeStoredPet,
  loadPetsFromApi,
} from "../services/pet-service.js?v=pet-weight-size-20261011-v3&pet-fields=20261030-v1";
import { formatPetSizeLabel, normalizePetSize } from "../services/booking-draft-service.js?flow=grooming-order-20261016&registration=fcfs-20261027-v1";
import {
  CLINIC_VISIT_PETS_KEY,
  normalizeApiPet,
  readClinicVisitDraft,
  requireCustomerSession,
  updateClinicVisitDraft,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1&remember=20261029";
import { createBreedCombobox } from "./breed-combobox.js?pet-fields=20261030-v1";
import { createBreedCoatCombobox } from "./breed-coat-combobox.js?pet-fields=20261030-v1";
import { createFixedOptionCombobox } from "./fixed-option-combobox.js";
import { canSelectAllSavedPets, showPetSelectionSection } from "./pet-selection-tabs.js";
import { goToGroomingStep, isGroomingSinglePage } from "./grooming-flow-navigation.js";
import { formatPetAge, isEligibleForGrooming, GROOMING_MINIMUM_AGE_MESSAGE } from "../services/grooming-age.js";
import {
  formatSavedWeight,
  getPetWeightSubmissionValidationMessage,
  getEnteredWeight,
  getSizeForWeight,
  getSizeOptions,
  getWeightFieldValidationMessage,
  getWeightValidationMessage,
  initializeWeightField,
  syncPetWeightAndSize as syncWeightSizeFields,
  showWeightValidationInField,
} from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";

/**
 * Booking Pet Step Controller
 *
 * Purpose:
 * Handles pet information (Grooming Step 1, Clinic Step 1):
 * - choose existing pet
 * - add new pet
 * - display selected pets
 * - save selected pets into current booking draft
 *
 * Backend developer guide:
 * Frontend currently uses sessionStorage as a temporary draft store.
 * In production, this should connect to a booking draft or session endpoint.
 *
 * Recommended backend validations:
 * 1. Enforce max 10 pets per booking
 * 2. Verify selected pet belongs to authenticated user
 * 3. Save newly added pet to user's pet list
 * 4. Attach chosen pets to active booking draft
 */

const IS_CLINIC_VISIT = new URLSearchParams(window.location.search).get("flow") === "clinic";
const SELECTED_PETS_STORAGE_KEY = IS_CLINIC_VISIT
  ? CLINIC_VISIT_PETS_KEY
  : undefined;
const MAX_PETS_PER_BOOKING = IS_CLINIC_VISIT ? 1 : GROOMING_MAX_PETS;
let savedPetsLoading = false;

function getBookingPets() {
  return getStoredBookingPets(SELECTED_PETS_STORAGE_KEY);
}

function saveBookingPets(pets) {
  return saveStoredBookingPets(pets, SELECTED_PETS_STORAGE_KEY);
}

function addPetToBooking(pet) {
  if (!IS_CLINIC_VISIT && !isEligibleForGrooming(pet)) {
    throw new Error(GROOMING_MINIMUM_AGE_MESSAGE);
  }
  return addStoredPet(pet, {
    storageKey: SELECTED_PETS_STORAGE_KEY,
    maxPets: MAX_PETS_PER_BOOKING,
  });
}

function removePetFromBooking(petId) {
  return removeStoredPet(petId, SELECTED_PETS_STORAGE_KEY);
}

const elements = {
  backLink: document.getElementById("petStepBackLink"),
  backLabel: document.getElementById("petStepBackLabel"),
  pageTitle: document.getElementById("petStepPageTitle"),
  savedPetsInstruction: document.getElementById("savedPetsInstruction"),
  selectedPetsTitle: document.getElementById("selectedPetsTitle"),
  showExistingPetBtn: document.getElementById("showExistingPetBtn"),
  showAddPetBtn: document.getElementById("showAddPetBtn"),
  petStepError: document.getElementById("petStepError"),

  existingPetSection: document.getElementById("existingPetSection"),
  existingPetEmptyState: document.getElementById("existingPetEmptyState"),
  existingPetsLoading: document.getElementById("existingPetsLoading"),
  existingPetList: document.getElementById("existingPetList"),
  selectAllExistingPetsBtn: document.getElementById("selectAllExistingPetsBtn"),

  addPetSection: document.getElementById("addPetSection"),
  addPetForm: document.getElementById("addPetForm"),
  petType: document.getElementById("petType"),
  petName: document.getElementById("petName"),
  gender: document.getElementById("gender"),
  birthdate: document.getElementById("birthdate"),
  breed: document.getElementById("breed"),
  color: document.getElementById("color"),
  weight: document.getElementById("weight"),
  furType: document.getElementById("furType"),
  size: document.getElementById("size"),
  medicalNotes: document.getElementById("medicalNotes"),
  sizeError: document.getElementById("sizeError"),
  savePetBtn: document.getElementById("savePetBtn"),

  selectedPetCount: document.getElementById("selectedPetCount"),
  selectedPetsEmptyState: document.getElementById("selectedPetsEmptyState"),
  selectedPetCards: document.getElementById("selectedPetCards"),
  removeAllSelectedPetsBtn: document.getElementById("removeAllSelectedPetsBtn"),

  backBtn: document.getElementById("backBtn"),
  nextBtn: document.getElementById("nextBtn"),
};

const petTypeCombobox = createFixedOptionCombobox({
  root: document.getElementById("petTypeCombobox"),
  input: elements.petType,
  listbox: document.getElementById("petTypeOptions"),
  toggleButton: document.getElementById("petTypeDropdownButton"),
  placeholder: "Select species",
  options: [
    { value: "Dog", label: "Dog" },
    { value: "Cat", label: "Cat" },
  ],
});

const genderCombobox = createFixedOptionCombobox({
  root: document.getElementById("genderCombobox"),
  input: elements.gender,
  listbox: document.getElementById("genderOptions"),
  toggleButton: document.getElementById("genderDropdownButton"),
  placeholder: "Select gender",
  options: [
    { value: "male", label: "Male" },
    { value: "female", label: "Female" },
  ],
  displaySelectedLabel: true,
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

const colorCombobox = createColorCombobox({
  input: document.getElementById("color"),
  listbox: document.getElementById("colorOptions"),
  errorElement: document.getElementById("colorError"),
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

const BOOKING_STEP_TWO_KEY = "bookingStep2";
let activePetSection = "";

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function showStepError(message) {
  elements.petStepError.textContent = message;
  elements.petStepError.className = message === GROOMING_MINIMUM_AGE_MESSAGE
    ? "mb-6 text-sm text-slate-600"
    : "mb-6 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700";
  elements.petStepError.classList.remove("hidden");
}

function clearStepError() {
  elements.petStepError.textContent = "";
  elements.petStepError.classList.add("hidden");
}

function buildStepTwoDraft() {
  const bookingPets = getBookingPets();
  const firstPet = bookingPets[0] || null;
  const petTypes = [
    ...new Set(bookingPets.map((pet) => pet.petType).filter(Boolean)),
  ];

  return {
    pets: bookingPets,
    petIds: bookingPets.map((pet) => pet.id).filter(Boolean),
    petTypes,
    petId: firstPet?.id || "",
    petType: firstPet?.petType || "",
    petName: firstPet?.petName || "",
    petBreed: firstPet?.breed || "",
    newPetForm: getFormValues(elements.addPetForm),
    activePetSection,
  };
}

function saveStepTwoDraft() {
  if (IS_CLINIC_VISIT) {
    return updateClinicVisitDraft({
      pet: getBookingPets()[0] || null,
      newPetForm: getFormValues(elements.addPetForm),
      activePetSection,
    });
  }

  const draft = buildStepTwoDraft();
  sessionStorage.setItem(BOOKING_STEP_TWO_KEY, JSON.stringify(draft));
  return draft;
}

function showExistingPetSection() {
  activePetSection = "existing";
  showPetSelectionSection({
    existingButton: elements.showExistingPetBtn,
    addButton: elements.showAddPetBtn,
    existingSection: elements.existingPetSection,
    addSection: elements.addPetSection,
  }, "existing");
  renderExistingPets();
  saveStepTwoDraft();
}

function showAddPetSection() {
  activePetSection = "add";
  showPetSelectionSection({
    existingButton: elements.showExistingPetBtn,
    addButton: elements.showAddPetBtn,
    existingSection: elements.existingPetSection,
    addSection: elements.addPetSection,
  }, "add");
  saveStepTwoDraft();
}

function formatWeightLabel(weight) {
  const value = formatSavedWeight(weight);

  if (!value) {
    return "Not specified";
  }

  return /\bkg\b/i.test(value) ? value : `${value} kg`;
}

function buildPetSummaryHtml(pet) {
  const age = !IS_CLINIC_VISIT ? formatPetAge(pet) : "";
  return `
    <div class="grid gap-2 text-sm text-slate-600">
      ${age ? `<p><span class="font-semibold text-slate-700">Age:</span> ${escapeHtml(age)}</p>` : ""}
      <p><span class="font-semibold text-slate-700">Type:</span> ${escapeHtml(
        pet.petType || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Breed:</span> ${escapeHtml(
        pet.breed || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Weight:</span> ${escapeHtml(
        formatWeightLabel(pet.weight),
      )}</p>
      <p><span class="font-semibold text-slate-700">Fur Type:</span> ${escapeHtml(
        pet.furType || "Not specified",
      )}</p>
      <p><span class="font-semibold text-slate-700">Size:</span> ${escapeHtml(
        pet.size || "Not specified",
      )} · ${pet.sizeVerified ? "Clinic verified" : "Estimated from weight"}</p>
      <p><span class="font-semibold text-slate-700">Allergies &amp; Special Care Notes:</span> ${escapeHtml(
        pet.medicalNotes || "None",
      )}</p>
    </div>
  `;
}

function renderExistingPets() {
  const savedPets = getSavedPets();
  const bookingPets = getBookingPets();

  elements.existingPetList.innerHTML = "";
  elements.existingPetsLoading.classList.toggle("hidden", !savedPetsLoading);
  elements.existingPetEmptyState.classList.add("hidden");
  syncSelectAllButtonState(savedPets, bookingPets);

  if (savedPetsLoading) {
    elements.selectAllExistingPetsBtn.classList.add("hidden");
    return;
  }

  if (savedPets.length === 0) {
    elements.existingPetEmptyState.classList.remove("hidden");
    return;
  }

  elements.existingPetEmptyState.classList.add("hidden");

  savedPets.forEach((pet) => {
    const isSelected = bookingPets.some(
      (selectedPet) => selectedPet.id === pet.id,
    );
    const bookingIsFull = bookingPets.length >= MAX_PETS_PER_BOOKING;
    const underage = !IS_CLINIC_VISIT && !isEligibleForGrooming(pet);

    const wrapper = document.createElement("article");
    wrapper.className =
      `rounded-2xl border border-slate-200 p-4 shadow-sm ${underage ? "bg-slate-50 opacity-80" : "bg-white"}`;

    wrapper.innerHTML = `
      <div class="mb-3 flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
        </div>
        <button
          type="button"
          data-pet-id="${escapeHtml(pet.id)}"
          class="select-existing-pet-btn rounded-xl px-4 py-2 text-xs font-semibold transition ${underage ? "whitespace-nowrap" : ""} ${
            underage || isSelected
              ? "cursor-not-allowed bg-slate-300 text-slate-500"
              : bookingIsFull
                ? "cursor-not-allowed bg-slate-300 text-slate-500"
                : "bg-[#315b7e] text-white hover:bg-[#274864]"
          }"
          ${underage || isSelected || bookingIsFull ? "disabled" : ""}
        >
          ${underage ? "Not eligible" : isSelected ? "Already Selected" : bookingIsFull ? "Limit Reached" : "Select Pet"}
        </button>
      </div>

      ${underage ? '<span class="mb-3 inline-block rounded-full bg-slate-200 px-2 py-1 text-xs font-medium text-slate-600">Under 6 months</span>' : ""}
      ${buildPetSummaryHtml(pet)}
      ${underage ? '<p class="mt-3 text-xs text-slate-600">Grooming is for pets 6 months and older.</p>' : ""}
    `;

    elements.existingPetList.appendChild(wrapper);
  });

  bindExistingPetButtons();
}

function syncSelectAllButtonState(
  savedPets = getSavedPets(),
  bookingPets = getBookingPets(),
) {
  const eligiblePets = IS_CLINIC_VISIT ? savedPets : savedPets.filter((pet) => isEligibleForGrooming(pet));
  const canSelectAll = !IS_CLINIC_VISIT
    && canSelectAllSavedPets(eligiblePets, bookingPets, MAX_PETS_PER_BOOKING);
  elements.selectAllExistingPetsBtn.textContent = eligiblePets.length < savedPets.length
    ? "Select All Eligible" : "Select All";
  elements.selectAllExistingPetsBtn.classList.toggle("hidden", !canSelectAll);
  elements.selectAllExistingPetsBtn.disabled = !canSelectAll;
}

function bindExistingPetButtons() {
  const buttons = document.querySelectorAll(".select-existing-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.petId;
      const savedPets = getSavedPets();
      const selectedPet = savedPets.find((pet) => pet.id === petId);

      if (!selectedPet) {
        showStepError("Selected pet could not be found.");
        return;
      }

      try {
        addPetToBooking(selectedPet);
        renderExistingPets();
        renderSelectedPets();
        clearStepError();
      } catch (error) {
        showStepError(error.message);
      }
    });
  });
}

function handleSelectAllExistingPets() {
  const savedPets = getSavedPets();
  const eligiblePets = savedPets.filter((pet) => IS_CLINIC_VISIT || isEligibleForGrooming(pet));
  const bookingPets = getBookingPets();
  const remainingSlots = MAX_PETS_PER_BOOKING - bookingPets.length;

  if (savedPets.length === 0) {
    syncSelectAllButtonState(savedPets, bookingPets);
    return;
  }

  if (remainingSlots <= 0) {
    showStepError(`Only ${MAX_PETS_PER_BOOKING} pets are allowed per visit.`);
    syncSelectAllButtonState(savedPets, bookingPets);
    return;
  }

  const selectedPetIds = new Set(bookingPets.map((pet) => pet.id));
  const unselectedSavedPets = eligiblePets.filter(
    (pet) => !selectedPetIds.has(pet.id),
  );
  if (!canSelectAllSavedPets(eligiblePets, bookingPets, MAX_PETS_PER_BOOKING)) {
    syncSelectAllButtonState(savedPets, bookingPets);
    return;
  }

  if (unselectedSavedPets.length === 0) {
    syncSelectAllButtonState(savedPets, bookingPets);
    return;
  }

  try {
    unselectedSavedPets.forEach((pet) => addPetToBooking(pet));
    renderExistingPets();
    renderSelectedPets();
    clearStepError();
  } catch (error) {
    showStepError(error.message);
  }
}

function renderSelectedPets() {
  const bookingPets = getBookingPets();

  elements.selectedPetCards.innerHTML = "";
  elements.selectedPetCount.textContent = `${bookingPets.length} / ${MAX_PETS_PER_BOOKING} selected`;
  elements.removeAllSelectedPetsBtn.classList.toggle(
    "hidden",
    bookingPets.length < 2,
  );
  elements.removeAllSelectedPetsBtn.disabled = bookingPets.length < 2;
  saveStepTwoDraft();

  if (bookingPets.length === 0) {
    elements.selectedPetsEmptyState.classList.remove("hidden");
    elements.nextBtn.disabled = true;
    elements.nextBtn.classList.add("opacity-50", "cursor-not-allowed");
    return;
  }

  elements.selectedPetsEmptyState.classList.add("hidden");
  const blocked = !IS_CLINIC_VISIT && (savedPetsLoading || bookingPets.some((pet) => !isEligibleForGrooming(pet)));
  elements.nextBtn.disabled = blocked;
  elements.nextBtn.classList.toggle("opacity-50", blocked);
  elements.nextBtn.classList.toggle("cursor-not-allowed", blocked);

  bookingPets.forEach((pet) => {
    const card = document.createElement("article");
    card.className =
      "rounded-2xl border border-[#c6dbef] bg-[#f8fbfe] p-3";

    card.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div>
          <h4 class="text-base font-bold text-[#2f4b66]">${escapeHtml(
            pet.petName,
          )}</h4>
          <p class="mt-1 text-sm text-slate-500">${escapeHtml(pet.petType || "Pet")}${pet.breed ? ` · ${escapeHtml(pet.breed)}` : ""}</p>
          <p class="mt-1 text-xs text-slate-500">${pet.size
            ? `${pet.sizeVerified ? "Size" : "Estimated Size"}: ${escapeHtml(formatPetSizeLabel(pet.size))}`
            : `Weight: ${escapeHtml(formatWeightLabel(pet.weight))}`}</p>
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
}

function handleRemoveAllSelectedPets() {
  const bookingPets = getBookingPets();

  if (bookingPets.length === 0) {
    elements.removeAllSelectedPetsBtn.disabled = true;
    return;
  }

  saveBookingPets([]);
  renderExistingPets();
  renderSelectedPets();
  clearStepError();
}

function bindRemoveButtons() {
  const buttons = document.querySelectorAll(".remove-selected-pet-btn");

  buttons.forEach((button) => {
    button.addEventListener("click", () => {
      const petId = button.dataset.removePetId;

      removePetFromBooking(petId);
      renderExistingPets();
      renderSelectedPets();
      clearStepError();
    });
  });
}

function getFormValues(form) {
  return {
    petType: form.petType.value,
    petName: form.petName.value,
    gender: genderCombobox.getValue(),
    birthdate: elements.birthdate.value,
    breed: form.breed.value,
    color: colorCombobox.getValue(),
    weight: getEnteredWeight(elements.weight),
    furType: form.furType.value,
    size: sizeCombobox.getValue(),
    medicalNotes: form.medicalNotes.value,
  };
}

function readStepTwoDraft() {
  if (IS_CLINIC_VISIT) {
    return readClinicVisitDraft();
  }

  try {
    return JSON.parse(sessionStorage.getItem(BOOKING_STEP_TWO_KEY) || "null");
  } catch {
    return null;
  }
}

function restoreAddPetFormDraft() {
  const draft = readStepTwoDraft();
  const values = draft?.newPetForm || {};

  petTypeCombobox.setValue(values.petType || "");
  elements.petName.value = values.petName || "";
  genderCombobox.setValue(values.gender || "");
  elements.birthdate.value = values.birthdate || "";
  breedCombobox.setValue(values.petType ? values.breed || "" : "");
  colorCombobox.setValue(values.color);

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
  if (!values.petType.trim()) {
    return "Pet type is required.";
  }

  if (!values.petName.trim()) {
    return "Pet name is required.";
  }

  if (!IS_CLINIC_VISIT && !isEligibleForGrooming(values)) {
    return GROOMING_MINIMUM_AGE_MESSAGE;
  }

  const colorMessage = colorCombobox.getValidationMessage();
  if (colorMessage) return colorMessage;
  if (values.medicalNotes.length > 1000) return "Care notes must be 1,000 characters or fewer.";

  const breedValidationMessage = breedCombobox.getValidationMessage();
  if (breedValidationMessage) {
    return breedValidationMessage;
  }

  const furTypeValidationMessage = breedCoatCombobox.getValidationMessage();
  if (furTypeValidationMessage) {
    return furTypeValidationMessage;
  }

  const weightValidationMessage = getWeightFieldValidationMessage(elements.weight)
    || getWeightValidationMessage(values.petType, values.weight);
  if (weightValidationMessage) {
    return weightValidationMessage;
  }

  const allowedSizes = getSizeOptions(values.petType).map(({ value }) => value);
  if (values.size && !allowedSizes.includes(values.size)) {
    return "Select a size available for this pet type.";
  }

  if (getBookingPets().length >= MAX_PETS_PER_BOOKING) {
    return `Only ${MAX_PETS_PER_BOOKING} pets are allowed per visit.`;
  }

  return "";
}

function resetAddPetForm() {
  elements.addPetForm.reset();
  petTypeCombobox.reset();
  genderCombobox.reset();
  breedCombobox.reset();
  breedCoatCombobox.reset();
  colorCombobox.reset();
  sizeCombobox.reset();
  initializeWeightField(elements.weight);
  syncWeightAndSize({ clearManualSize: true });
  setFieldError(elements.size, elements.sizeError, "");
  saveStepTwoDraft();
}

async function handleAddPetSubmit(event) {
  event.preventDefault();

  const formValues = getFormValues(elements.addPetForm);
  const validationMessage = validatePetForm(formValues);

  if (validationMessage) {
    colorCombobox.showValidation();
    breedCombobox.showValidation();
    breedCoatCombobox.showValidation();
    const weightMessage = getWeightFieldValidationMessage(elements.weight)
      || getWeightValidationMessage(formValues.petType, formValues.weight);
    if (weightMessage) {
      showWeightValidationInField(elements.weight, weightMessage);
    }
    if (validationMessage === colorCombobox.getValidationMessage()) {
      clearStepError();
      elements.color.focus();
    } else if (validationMessage === breedCoatCombobox.getValidationMessage()) {
      clearStepError();
      elements.furType.focus();
    } else if (validationMessage === breedCombobox.getValidationMessage()) {
      clearStepError();
      elements.breed.focus();
    } else {
      showStepError(validationMessage);
    }
    return;
  }

  let newPet = null;

  try {
    elements.savePetBtn.disabled = true;
    elements.savePetBtn.textContent = "Confirming...";

    if (IS_CLINIC_VISIT || API.getCustomerToken?.()) {
      const response = await API.addPet({
        pet_name: formValues.petName.trim(),
        species: formValues.petType,
        gender: formValues.gender || null,
        birthdate: formValues.birthdate || null,
        breed: formValues.breed.trim() || null,
        color: formValues.color.trim() || null,
        weight: formValues.weight || null,
        fur_type: formValues.furType || null,
        size: normalizePetSize(formValues.size) || null,
        medical_conditions: formValues.medicalNotes.trim() || null,
      });
      newPet = normalizeApiPet(response.pet);
      addPetToSavedPets(newPet);
    } else {
      newPet = createPetObject(formValues);
      addPetToSavedPets(newPet);
    }

    addPetToBooking(newPet);

    resetAddPetForm();
    renderExistingPets();
    renderSelectedPets();
    clearStepError();
  } catch (error) {
    if (error.errors?.color?.[0]) {
      clearStepError();
      colorCombobox.showValidation(error.errors.color[0]);
      elements.color.focus();
      return;
    }
    if (error.errors?.fur_type?.[0]) {
      clearStepError();
      breedCoatCombobox.showValidation(error.errors.fur_type[0]);
      elements.furType.focus();
      return;
    }
    if (error.errors?.weight?.[0]) showWeightValidationInField(elements.weight, error.errors.weight[0]);
    showStepError(error.message);
  } finally {
    elements.savePetBtn.disabled = false;
    elements.savePetBtn.textContent = "Confirm";
  }
}

function handleBack() {
  if (!IS_CLINIC_VISIT && isGroomingSinglePage()) {
    window.location.href = "./pre-register.html";
    return;
  }
  window.location.href = IS_CLINIC_VISIT
    ? "./pre-register.html"
    : "./grooming-pre-registration.html";
}

function handleNext() {
  const bookingPets = getBookingPets();

  if (!IS_CLINIC_VISIT && bookingPets.some((pet) => !isEligibleForGrooming(pet))) {
    showStepError(GROOMING_MINIMUM_AGE_MESSAGE);
    return;
  }
  if (!IS_CLINIC_VISIT && savedPetsLoading) return;

  const weightMessage = bookingPets.map(getPetWeightSubmissionValidationMessage).find(Boolean);
  if (weightMessage) {
    showStepError(weightMessage);
    return;
  }

  if (bookingPets.length === 0) {
    showStepError("Please select or add at least one pet before continuing.");
    return;
  }

  if (IS_CLINIC_VISIT && bookingPets.length !== 1) {
    showStepError("Please select exactly one pet for the clinic visit.");
    return;
  }

  clearStepError();
  saveStepTwoDraft();
  if (!IS_CLINIC_VISIT && isGroomingSinglePage()) {
    goToGroomingStep("services");
    return;
  }
  window.location.href = IS_CLINIC_VISIT
    ? "./clinic-visit-reason.html"
    : "./grooming-pre-registration.html?step=services";
}

function setFieldError(input, errorElement, message) {
  if (!input || !errorElement) {
    return;
  }

  input.setAttribute("aria-invalid", String(Boolean(message)));
  input.classList.toggle("border-red-400", Boolean(message));
  errorElement.textContent = message;
  errorElement.classList.toggle("hidden", !message);
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

function bindEvents() {
  elements.showExistingPetBtn.addEventListener("click", showExistingPetSection);
  elements.showAddPetBtn.addEventListener("click", showAddPetSection);
  elements.selectAllExistingPetsBtn.addEventListener(
    "click",
    handleSelectAllExistingPets,
  );
  elements.removeAllSelectedPetsBtn.addEventListener(
    "click",
    handleRemoveAllSelectedPets,
  );
  elements.addPetForm.addEventListener("submit", handleAddPetSubmit);
  elements.addPetForm.addEventListener("input", saveStepTwoDraft);
  elements.addPetForm.addEventListener("change", saveStepTwoDraft);
  elements.backBtn.addEventListener("click", handleBack);
  elements.nextBtn.addEventListener("click", handleNext);

  elements.petType.addEventListener("change", () => {
    syncWeightAndSize({ clearManualSize: true });
    validateWeightOnCommit();
  });
  elements.weight.addEventListener("input", () => syncWeightAndSize());
  elements.weight.addEventListener("change", validateWeightOnCommit);
  elements.size.addEventListener("change", handleManualSizeChange);
}

async function initStepState() {
  initializeWeightField(elements.weight);
  syncWeightAndSize();

  if (!Array.isArray(getBookingPets())) {
    saveBookingPets([]);
  }

  restoreAddPetFormDraft();
  savedPetsLoading = true;
  renderSelectedPets();
  showExistingPetSection();

  // Sync pets from the backend into localStorage before rendering the list
  await syncSavedPets();

  if (getSavedPets().length === 0) showAddPetSection();
  else showExistingPetSection();
}

async function syncSavedPets() {
  await loadPetsFromApi();
  savedPetsLoading = false;
  const savedPetsById = new Map(getSavedPets().map((pet) => [pet.id, pet]));
  const currentPets = getBookingPets();
  const refreshedPets = currentPets.map((pet) => savedPetsById.get(pet.id) || pet);
  const eligiblePets = IS_CLINIC_VISIT ? refreshedPets : refreshedPets.filter((pet) => isEligibleForGrooming(pet));
  if (eligiblePets.length !== currentPets.length || eligiblePets.some((pet, index) => pet !== currentPets[index])) {
    saveBookingPets(eligiblePets);
  }
  if (eligiblePets.length < refreshedPets.length) showStepError(GROOMING_MINIMUM_AGE_MESSAGE);
  renderSelectedPets();

  renderExistingPets();
}

function configurePageForFlow() {
  if (!IS_CLINIC_VISIT) {
    return;
  }

  document.title = "Clinic Visit - Pet Information";
  elements.backLink.href = "./dashboard.html";
  elements.backLabel.textContent = "Back to Home";
  elements.pageTitle.textContent = "Pet Information";
  elements.savedPetsInstruction.textContent =
    "Select one pet from your saved pet list.";
  elements.selectedPetsTitle.textContent = "Selected Pet";
}

export async function initBookingPetStep() {
  if (IS_CLINIC_VISIT && await initBookingFormAccessGuard({ dashboardPath: "./dashboard.html" })) return;
  if (IS_CLINIC_VISIT && !await requireCustomerSession("./sign-in.html")) {
    return;
  }

  configurePageForFlow();
  bindEvents();
  await initStepState();
}

export async function refreshBookingPetStep() {
  savedPetsLoading = true;
  renderSelectedPets();
  renderExistingPets();
  await syncSavedPets();
}

if (!isGroomingSinglePage()) {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initBookingPetStep, { once: true });
  } else {
    initBookingPetStep();
  }
}
