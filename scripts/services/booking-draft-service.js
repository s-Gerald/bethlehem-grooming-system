/** Shared pet and service drafts; legacy storage keys remain compatible. */
export const BOOKING_STEP_TWO_KEY = "bookingStep2";
export const BOOKING_STEP_THREE_KEY = "bookingStep3";

export function clearGroomingDraft() {
  ["bookingSchedule", "bookingScheduleArrivalError", "bookingPets",
    BOOKING_STEP_TWO_KEY, BOOKING_STEP_THREE_KEY, "bookingStep4Review",
    "bookingReview", "bookingConsentStep", "bookingConfirmation",
    "groomingSelectedPricing", "groomingReviewedPricing",
  ].forEach((key) => sessionStorage.removeItem(key));
}

export function readSessionJson(key) {
  try {
    const rawValue = sessionStorage.getItem(key);
    return rawValue ? JSON.parse(rawValue) : null;
  } catch (error) {
    console.error(`Failed to parse ${key} sessionStorage:`, error);
    return null;
  }
}

export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

export function normalizePetType(value) {
  return String(value || "").trim().toLowerCase();
}

export function normalizePetSize(value) {
  const normalizedSize = String(value || "").trim().toLowerCase();

  const sizeMap = {
    small: "small",
    medium: "medium",
    large: "large",
    "extra large": "extra_large",
    "extra-large": "extra_large",
    extralarge: "extra_large",
    "extra_large": "extra_large",
    xl: "extra_large",
  };

  return sizeMap[normalizedSize] || "";
}

export function formatPetTypeLabel(value) {
  const normalizedType = normalizePetType(value);

  if (!normalizedType) {
    return "Unknown Type";
  }

  return normalizedType.charAt(0).toUpperCase() + normalizedType.slice(1);
}

export function formatPetSizeLabel(value) {
  const normalizedSize = normalizePetSize(value);

  const sizeLabels = {
    small: "Small",
    medium: "Medium",
    large: "Large",
    extra_large: "Extra Large",
  };

  return sizeLabels[normalizedSize] || "Size not specified";
}

function normalizePetRecord(pet, index) {
  return {
    ...pet,
    id: pet?.id || `booking-pet-${index + 1}`,
    petName: pet?.petName || "",
    petType: pet?.petType || "",
    breed: pet?.breed || "",
    size: pet?.size || "",
    weight: pet?.weight || "",
    furType: pet?.furType || "",
    medicalNotes: pet?.medicalNotes || "",
  };
}

export function normalizeBookingDraft(data) {
  const bookingDate = "";
  const bookingTime = "";
  const petsFromDraft = Array.isArray(data?.pets) ? data.pets.filter(Boolean) : [];
  const legacyPet =
    data?.petId || data?.petName || data?.petType || data?.petBreed
      ? [
          {
            id: data.petId || "",
            petName: data.petName || "",
            petType: data.petType || "",
            breed: data.petBreed || "",
            size: data.petSize || "",
          },
        ]
      : [];

  const pets = (petsFromDraft.length > 0 ? petsFromDraft : legacyPet).map(
    (pet, index) => normalizePetRecord(pet, index),
  );

  const firstPet = pets[0] || null;
  const petTypes = [
    ...new Set(
      pets.map((pet) => normalizePetType(pet.petType)).filter(Boolean),
    ),
  ];

  return {
    bookingDate,
    bookingTime,
    bookingScheduleText: "",
    pets,
    petIds: pets.map((pet) => pet.id).filter(Boolean),
    petTypes,
    petId: data?.petId || firstPet?.id || "",
    petType: data?.petType || firstPet?.petType || "",
    petName: data?.petName || firstPet?.petName || "",
    petBreed: data?.petBreed || firstPet?.breed || "",
  };
}

export function getBookingDraft() {
  const saved = readSessionJson(BOOKING_STEP_TWO_KEY);
  const pets = readSessionJson("bookingPets");
  if (!saved && !Array.isArray(pets)) return null;
  return normalizeBookingDraft({ ...saved, pets: Array.isArray(pets) ? pets : saved?.pets });
}
