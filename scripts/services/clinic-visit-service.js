import { formatSavedWeight } from "../components/pet-weight-size.js?v=pet-weight-size-20261011-v3";
export const CLINIC_VISIT_DRAFT_KEY = "clinicVisitDraft";
export const CLINIC_VISIT_PETS_KEY = "clinicVisitPets";
export const CLINIC_VISIT_CONFIRMATION_KEY = "clinicVisitConfirmation";

export function readClinicVisitDraft() {
  try {
    const value = sessionStorage.getItem(CLINIC_VISIT_DRAFT_KEY);
    const draft = value ? JSON.parse(value) : {};
    for (const key of ["appointmentDate", "windowId", "windowLabel", "startTime", "endTime", "scheduleText"]) delete draft[key];
    return draft;
  } catch (error) {
    console.error("Failed to read the clinic visit draft:", error);
    return {};
  }
}

export function updateClinicVisitDraft(values = {}) {
  const draft = {
    ...readClinicVisitDraft(),
    ...values,
  };

  sessionStorage.setItem(CLINIC_VISIT_DRAFT_KEY, JSON.stringify(draft));
  return draft;
}

export function clearClinicVisitDraft() {
  sessionStorage.removeItem(CLINIC_VISIT_DRAFT_KEY);
  sessionStorage.removeItem(CLINIC_VISIT_PETS_KEY);
  sessionStorage.removeItem(CLINIC_VISIT_CONFIRMATION_KEY);
}

export const CLINIC_CONCERNS = [
  "Routine check-up", "Vaccination", "Vomiting", "Diarrhea",
  "Not eating", "Skin / itching", "Ear problem", "Eye problem",
  "Coughing / sneezing", "Limping / injury", "Other",
];

export function validateClinicVisitReason(concerns, details = "") {
  if (!Array.isArray(concerns) || concerns.length === 0) {
    return "Select at least one common concern.";
  }
  if (concerns.some((concern) => !CLINIC_CONCERNS.includes(concern)) ||
      new Set(concerns).size !== concerns.length) {
    return "Select valid common concerns.";
  }
  if (String(details).trim().length > 1000) {
    return "Tell us more must not exceed 1,000 characters.";
  }
  return "";
}

export function formatClinicVisitReason(concerns, details = "") {
  const description = String(details ?? "").trim();
  return `${concerns.join(", ")}${description ? `\n${description}` : ""}`;
}

export function formatClinicVisitDate(dateKey) {
  if (!dateKey) return "Not selected";

  const [year, month, day] = String(dateKey).split("-").map(Number);
  const date = new Date(year, month - 1, day);

  if (Number.isNaN(date.getTime())) {
    return "Not selected";
  }

  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

export function normalizeApiPet(pet) {
  return {
    id: String(pet?.pet_id || pet?.id || ""),
    petName: pet?.pet_name || pet?.petName || "",
    petType: pet?.species || pet?.petType || "",
    gender: pet?.gender || "",
    birthdate: pet?.birthdate || "",
    breed: pet?.breed || "",
    color: pet?.color || "",
    weight: formatSavedWeight(pet?.weight),
    furType: pet?.fur_type || pet?.furType || "",
    size: pet?.size || "",
    sizeVerified: Array.isArray(pet?.clinic_verified_fields) && pet.clinic_verified_fields.includes("size"),
    medicalNotes: pet?.medical_conditions || pet?.medicalNotes || "",
  };
}

export async function requireCustomerSession(signInPath = "./sign-in.html") {
  if (window.API?.waitForCustomerSession) return window.API.waitForCustomerSession();
  if (window.API?.getCustomerToken?.()) {
    return true;
  }

  window.location.replace(signInPath);
  return false;
}
