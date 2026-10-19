const WEIGHT_PROFILES = Object.freeze({
  Dog: [
    { size: "Small", max: 11, label: "under 11 kg" },
    { size: "Medium", max: 26, label: "11 kg to under 26 kg" },
    { size: "Large", max: 51, label: "26 kg to under 51 kg" },
    { size: "Extra Large", max: null, label: "51 kg and above" },
  ],
  Cat: [
    { size: "Small", max: 5, label: "under 5 kg" },
    { size: "Medium", max: null, label: "5 kg and above" },
  ],
});
const MAX_WEIGHT_KG = 155.6;

function getProfile(petType) {
  const type = Object.keys(WEIGHT_PROFILES).find(
    value => value.toLowerCase() === String(petType || "").trim().toLowerCase(),
  );
  return WEIGHT_PROFILES[type] || [];
}

export function getSizeOptions(petType) {
  return getProfile(petType).map(({ size, label }) => ({ value: size, label: `${size}: ${label}` }));
}

export function getWeightValidationMessage(petType, rawWeight) {
  const message = getWeightValueValidationMessage(rawWeight);
  if (message || String(rawWeight ?? "").trim() === "") return message;
  if (!getProfile(petType).length) return "Select Dog or Cat before entering a weight.";
  return "";
}

export function getWeightValueValidationMessage(rawWeight) {
  if (String(rawWeight ?? "").trim() === "") return "";
  const text = String(rawWeight).trim();
  const weight = Number(rawWeight);
  if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(text) || !Number.isFinite(weight)) {
    return "Enter a valid weight in kilograms.";
  }
  if ((text.split(".")[1] || "").length > 2) return "Enter weight using no more than 2 decimal places.";
  if (weight <= 0) return "Enter a weight greater than 0 kg.";
  if (weight > MAX_WEIGHT_KG) return "Weight cannot be more than 155.6 kg.";
  return "";
}

export function getSizeForWeight(petType, rawWeight) {
  if (String(rawWeight ?? "").trim() === "" || getWeightValidationMessage(petType, rawWeight)) return "";
  return getSizeForStoredWeight(petType, rawWeight);
}

// Historical records retain their exact weight, even if it has more than two decimals.
export function getSizeForStoredWeight(petType, rawWeight) {
  const weight = Number(rawWeight);
  if (!Number.isFinite(weight) || weight <= 0 || weight > MAX_WEIGHT_KG) return "";
  return getProfile(petType).find(({ max }) => max === null || weight < max)?.size || "";
}

export { formatSavedWeight } from "../services/booking-format-service.js?v=pet-weight-format-20261019";

// Saved selections are references, not weight edits. New pet drafts keep their input text.
export function getPetWeightSubmission(pet) {
  const savedId = pet.petId ?? pet.id;
  if (/^[1-9]\d*$/.test(String(savedId ?? ""))) return {};
  return { weight: String(pet.weight ?? "").trim() || null };
}

export function getPetWeightSubmissionValidationMessage(pet) {
  const fields = getPetWeightSubmission(pet);
  return "weight" in fields ? getWeightValidationMessage(pet.petType ?? pet.species, fields.weight) : "";
}

export function getEnteredWeight(input) {
  return input?.value.trim() || "";
}

export function showWeightValidationInField(input, message = "") {
  if (!input) return;
  input.readOnly = false;
  input.setAttribute("aria-invalid", String(Boolean(message)));
  input.classList.toggle("border-red-400", Boolean(message));
  input.dataset.weightValidationMessage = message;
  const error = input.ownerDocument.getElementById(`${input.id}Error`);
  if (error) {
    error.textContent = message;
    error.classList.toggle("hidden", !message);
  }
}

export function initializeWeightField(input) {
  if (!input) return;
  input.value = "";
  showWeightValidationInField(input);
}

export function getWeightFieldValidationMessage(input) {
  return input?.dataset.weightValidationMessage || "";
}

// A disabled size still remains available through the combobox's getValue().
export function syncPetWeightAndSize(input, petType, sizeControl, { clearManualSize = false } = {}) {
  const options = getSizeOptions(petType);
  sizeControl.setOptions(options, { preserveValue: !clearManualSize });
  const size = getSizeForWeight(petType, getEnteredWeight(input));
  if (size) sizeControl.setValue(size);
  sizeControl.setDisabled(Boolean(size) || options.length === 0);
  showWeightValidationInField(input, getWeightValidationMessage(petType, getEnteredWeight(input)));
}
