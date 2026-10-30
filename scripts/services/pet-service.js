/**
 * Pet Service
 *
 * Saved pets (clientPets) use localStorage so they persist across sessions.
 * Booking pets (current booking draft) stay in sessionStorage — they only
 * need to live for the duration of one booking flow.
 *
 * On the pet step, call loadPetsFromApi() first to sync the user's pets
 * from the database into localStorage before rendering.
 */
import { formatSavedWeight, getSizeForStoredWeight } from "../components/pet-weight-size.js?v=pet-weight-size-20261011-v3";

const CLIENT_PETS_KEY = "clientPets";     // localStorage — persists across tabs/sessions
const BOOKING_PETS_KEY = "bookingPets";   // sessionStorage — current booking draft only
const BOOKING_SCHEDULE_KEY = "bookingSchedule";

export const MAX_PETS_PER_BOOKING = 10;

// ── Saved pets (localStorage) ─────────────────────────────────────────────

export function getSavedPets() {
  try {
    const raw = localStorage.getItem(CLIENT_PETS_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    console.error("Failed to read saved pets:", error);
    return [];
  }
}

export function saveSavedPets(pets) {
  localStorage.setItem(CLIENT_PETS_KEY, JSON.stringify(pets));
}

/**
 * Fetch the user's pets from the backend and merge into localStorage.
 * Backend pets take precedence — they are the source of truth.
 * Any locally-added pets that don't exist in the DB yet are kept.
 */
export async function loadPetsFromApi() {
  try {
    const { pets: apiPets } = await API.getUserPets();

    // Convert backend field names to frontend format
    const mappedPets = apiPets.map((p) => {
      const sizeVerified = Array.isArray(p.clinic_verified_fields)
        && p.clinic_verified_fields.includes("size");
      return {
        id: String(p.pet_id),
        petName: p.pet_name || "",
        petType: p.species || "",
        gender: p.gender || "",
        birthdate: p.birthdate || "",
        breed: p.breed || "",
        color: p.color || "",
        weight: formatSavedWeight(p.weight),
        furType: p.fur_type || "",
        size: sizeVerified
          ? p.size || ""
          : (getSizeForStoredWeight(p.species, p.weight).toLowerCase().replaceAll(" ", "_") || p.size || ""),
        sizeVerified,
        medicalNotes: p.medical_conditions || "",
      };
    });

    // Replace entirely — the API is the source of truth for the logged-in user.
    // Never merge with existing localStorage data because it may contain
    // pets from a previously logged-in user on the same browser.
    saveSavedPets(mappedPets);
  } catch {
    // Network failure or not logged in — keep whatever is in localStorage
  }
}

// ── Booking pets (sessionStorage) ────────────────────────────────────────

export function getBookingPets(storageKey = BOOKING_PETS_KEY) {
  try {
    const raw = sessionStorage.getItem(storageKey);
    return raw ? JSON.parse(raw) : [];
  } catch (error) {
    console.error("Failed to read booking pets:", error);
    return [];
  }
}

export function saveBookingPets(pets, storageKey = BOOKING_PETS_KEY) {
  sessionStorage.setItem(storageKey, JSON.stringify(pets));
}

export function getBookingSchedule() {
  try {
    const raw = sessionStorage.getItem(BOOKING_SCHEDULE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.error("Failed to read booking schedule:", error);
    return null;
  }
}

// ── Pet object helpers ────────────────────────────────────────────────────

export function createPetObject(formData) {
  return {
    id: crypto.randomUUID(),
    petType: formData.petType,
    petName: formData.petName.trim(),
    gender: formData.gender || "",
    birthdate: formData.birthdate || "",
    breed: formData.breed.trim(),
    color: formData.color.trim(),
    weight: formData.weight.trim(),
    furType: formData.furType,
    size: formData.size,
    sizeVerified: false,
    medicalNotes: formData.medicalNotes.trim(),
  };
}

export function addPetToSavedPets(newPet) {
  const currentPets = getSavedPets();
  const updatedPets = [...currentPets, newPet];
  saveSavedPets(updatedPets);
  return updatedPets;
}

export function addPetToBooking(
  newPet,
  {
    storageKey = BOOKING_PETS_KEY,
    maxPets = MAX_PETS_PER_BOOKING,
  } = {},
) {
  const currentBookingPets = getBookingPets(storageKey);

  if (currentBookingPets.length >= maxPets) {
    throw new Error(`Only ${maxPets} ${maxPets === 1 ? "pet is" : "pets are"} allowed.`);
  }

  const alreadyExists = currentBookingPets.some((pet) => pet.id === newPet.id);
  if (alreadyExists) {
    return currentBookingPets;
  }

  const updatedBookingPets = [...currentBookingPets, newPet];
  saveBookingPets(updatedBookingPets, storageKey);
  return updatedBookingPets;
}

export function removePetFromBooking(petId, storageKey = BOOKING_PETS_KEY) {
  const updatedBookingPets = getBookingPets(storageKey).filter((pet) => pet.id !== petId);
  saveBookingPets(updatedBookingPets, storageKey);
  return updatedBookingPets;
}

export function isPetAlreadySelected(petId, storageKey = BOOKING_PETS_KEY) {
  return getBookingPets(storageKey).some((pet) => pet.id === petId);
}
