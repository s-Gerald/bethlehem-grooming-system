import { limitMessage, tooLong } from "../utils/short-notes.js?v=500-20261110";

export const CLINIC_VISIT_DRAFT_KEY = "clinicVisitDraft";
export const CLINIC_VISIT_PETS_KEY = "clinicVisitPets";
export const CLINIC_VISIT_CONFIRMATION_KEY = "clinicVisitConfirmation";
export const CLINIC_MAX_PATIENTS = 13;
export const CLINIC_PATIENT_LIMIT_MESSAGE = `You can register up to ${CLINIC_MAX_PATIENTS} pets for one clinic visit.`;

export function readClinicVisitDraft() {
  try {
    const value = sessionStorage.getItem(CLINIC_VISIT_DRAFT_KEY);
    const draft = value ? JSON.parse(value) ?? {} : {};
    if (!Array.isArray(draft.patients)) {
      draft.patients = draft.pet?.id ? [{
        pet: draft.pet, commonConcerns: draft.commonConcerns, chiefComplaint: draft.chiefComplaint,
      }] : [];
    }
    draft.patients = draft.patients.slice(0, CLINIC_MAX_PATIENTS).filter((row) => row?.pet?.id);
    for (const key of ["pet", "commonConcerns", "chiefComplaint", "appointmentDate", "windowId", "windowLabel", "startTime", "endTime", "scheduleText"]) delete draft[key];
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
  if (tooLong(String(details))) return limitMessage;
  return "";
}

export function formatClinicVisitReason(concerns, details = "") {
  const description = String(details ?? "").trim();
  return `${concerns.join(", ")}${description ? `\n${description}` : ""}`;
}

export function clinicRegistrationError(error, patients) {
  const field = Object.keys(error.errors ?? {}).find((key) => /^patients\.\d+\./.test(key));
  if (!field) return error.message;
  const patient = patients[Number(field.split(".")[1])];
  return [patient?.pet?.petName, error.errors[field]?.[0]].filter(Boolean).join(" — ") || error.message;
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

export async function requireCustomerSession(signInPath = "/sign-in") {
  if (window.API?.waitForCustomerSession) return window.API.waitForCustomerSession();
  if (window.API?.getCustomerToken?.()) {
    return true;
  }

  window.location.replace(signInPath);
  return false;
}
