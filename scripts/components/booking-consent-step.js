import { initGroomingConsent } from "./grooming-consent.js?v=20261017";
import { getPetWeightSubmission, getPetWeightSubmissionValidationMessage } from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";
import { loadGroomingCatalogue, selectedPricingSignature, normalizeStepThreeDraft } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import { getBookingDraft } from "../services/booking-draft-service.js?flow=grooming-order-20261016&registration=fcfs-20261027-v1";
import { goToGroomingStep } from "./grooming-flow-navigation.js";
import { isEligibleForGrooming } from "../services/grooming-age.js";

export function initBookingConsentStep() {
  const form = document.getElementById("bookingConsentForm");
  const submitBookingButton = document.getElementById("submitBookingButton");
  const consentStatusMessage = document.getElementById("consentStatusMessage");
  const consent = initGroomingConsent({ storageKey: "bookingConsentStep", onChange: validateConsentForm });
  API.getMe().then(({ user }) => consent.prefill([user?.first_name, user?.last_name].filter(Boolean).join(" "))).catch(() => {});

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!consent.valid) {
      consent.validate(true);
      validateConsentForm();
      return;
    }

    const consentPayload = consent.payload();

    // ── Build and submit the booking payload to the backend ──
    const bookingPets = getStoredData("bookingPets") || [];
    const reviewData = getStoredData("bookingStep4Review") || getStoredData("bookingReview") || {};

    if (bookingPets.some((pet) => !isEligibleForGrooming(pet))) {
      goToGroomingStep("pets");
      return;
    }

    // Map frontend pet format → backend field names
    const step3Selections = (getStoredData("bookingStep3")?.petSelections) || [];

    const weightMessage = bookingPets.map(getPetWeightSubmissionValidationMessage).find(Boolean);
    if (weightMessage) {
      alert(weightMessage);
      goToGroomingStep("pets");
      return;
    }

    const petsPayload = bookingPets.map((pet) => {
      // Find special instructions for this pet from the review data
      const reviewPet = Array.isArray(reviewData.pets)
        ? reviewData.pets.find((rp) => rp.petId === pet.id)
        : null;

      // Find service selections for this pet from step 3
      const serviceSelection = step3Selections.find((s) => s.petId === pet.id) || {};

      return {
        pet_id: isNaN(Number(pet.id)) ? undefined : Number(pet.id),  // only send if it's a real DB id
        pet_name: pet.petName,
        species: pet.petType,
        breed: pet.breed || null,
        birthdate: pet.birthdate || null,
        size: normalizeSizeForApi(pet.size),
        fur_type: pet.furType?.trim() || null,
        ...getPetWeightSubmission(pet),
        medical_conditions: pet.medicalNotes || null,
        special_instructions: reviewPet?.specialInstructions || null,
        grooming_preference: serviceSelection.groomingPreference || null,
        haircut_requested: serviceSelection.haircutRequested === true,
        groomer_id: serviceSelection.groomerId ?? null,
        services: {
          package:   serviceSelection.servicePackage || null,
          ala_carte: serviceSelection.alaCarteServices || [],
        },
      };
    });

    if (!await consent.confirmSubmission() || !consent.valid) return;
    consent.setBusy(true);
    submitBookingButton.disabled = true;
    submitBookingButton.textContent = "Submitting...";

    try {
      await loadGroomingCatalogue();
      const draft = getBookingDraft();
      const selections = normalizeStepThreeDraft(getStoredData("bookingStep3"), draft);
      if (sessionStorage.getItem("groomingReviewedPricing") !== selectedPricingSignature(draft, selections)) {
        consent.setBusy(false);
        submitBookingButton.textContent = "Submit Registration";
        goToGroomingStep("review");
        return;
      }
      const response = await API.storeBooking({
        number_of_pets: bookingPets.length,
        special_notes: null,
        ...consentPayload,
        pets: petsPayload,
      });

      // Save the backend response so the confirmed page can read it
      sessionStorage.setItem("bookingConfirmation", JSON.stringify({
        booking_id: response.booking.booking_id,
        booking_reference: response.booking.booking_reference,
        booking_date: response.booking.booking_date,
        submitted_at:
          response.booking.created_at,
        status: response.booking.status,
        number_of_pets: response.booking.number_of_pets,
        pets: bookingPets,
        review: { ...reviewData, pets: (reviewData.pets || []).map((pet, index) => ({ ...pet,
          petGroomerName: response.booking.pets?.[index]?.pet_groomer?.first_name || 'Any available groomer',
          groomingPreferenceLabel: response.booking.pets?.[index]?.grooming_preference_label })) },
        consent: response.consent,
      }));

      goToGroomingStep("confirmed");
    } catch (error) {
      consent.setBusy(false);
      submitBookingButton.textContent = "Submit Registration";

      if (Object.keys(error.errors || {}).some((key) => key.endsWith('.groomer_id'))) {
        goToGroomingStep("services");
        return;
      }
      if (Object.keys(error.errors || {}).some((key) => key.endsWith('.birthdate'))) {
        goToGroomingStep("pets");
        return;
      }
      showSubmitError(error.message || "Pre-registration submission failed. Please try again.");
    }
  });

  // "Small" → "small", "Extra Large" → "extra_large"
  function normalizeSizeForApi(size) {
    if (!size) return null;
    return size.toLowerCase().replace(/\s+/g, "_");
  }

  function validateConsentForm(valid = false) {
    submitBookingButton.disabled = !valid;
    submitBookingButton.className = valid
      ? "inline-flex items-center justify-center rounded-xl bg-[#315b7e] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[#274a67]"
      : "inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-slate-300 px-5 py-3 text-sm font-semibold text-white";
  }

  function getStoredData(key) {
    try {
      const rawValue = sessionStorage.getItem(key);
      return rawValue ? JSON.parse(rawValue) : null;
    } catch (error) {
      console.error(`Failed to parse sessionStorage key: ${key}`, error);
      return null;
    }
  }

  // ── Inline error banner (replaces alert for non-duplicate errors) ────────
  function showSubmitError(message) {
    consentStatusMessage.hidden = false;
    consentStatusMessage.textContent = message;
    consentStatusMessage.className =
      "mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
    consentStatusMessage.scrollIntoView({ behavior: "smooth", block: "center" });
  }

}
