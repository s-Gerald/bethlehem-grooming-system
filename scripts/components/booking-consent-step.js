import { initGroomingConsent } from "./grooming-consent.js?v=20261017";
import { getPetWeightSubmission, getPetWeightSubmissionValidationMessage } from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";
import { loadGroomingCatalogue, selectedPricingSignature, normalizeStepThreeDraft } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026";
import { getBookingDraft } from "../services/booking-draft-service.js?flow=grooming-order-20261016";
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
    const schedule = getStoredData("bookingSchedule");
    const bookingPets = getStoredData("bookingPets") || [];
    const reviewData = getStoredData("bookingStep4Review") || getStoredData("bookingReview") || {};

    if (bookingPets.some((pet) => !isEligibleForGrooming(pet))) {
      goToGroomingStep("pets");
      return;
    }

    if (!schedule?.window_id) {
      sessionStorage.setItem("bookingScheduleArrivalError", "Please choose an available arrival window.");
      goToGroomingStep("schedule");
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
    const submittedAt = new Date().toISOString();

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
        booking_date: schedule.date,
        window_id: schedule.window_id,
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
        booking_time: response.booking.window,
        submitted_at:
          response.booking.created_at || response.booking.submitted_at || submittedAt,
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

      if (["arrival_window_ended", "arrival_window_unavailable"].includes(error.code) || error.errors?.window_id) {
        // Keep the date visible while invalidating only the selected arrival.
        sessionStorage.setItem("bookingSchedule", JSON.stringify({ date: schedule.date }));
        sessionStorage.setItem("bookingScheduleArrivalError", error.message);
        goToGroomingStep("schedule");
        return;
      }

      if (Object.keys(error.errors || {}).some((key) => key.endsWith('.groomer_id'))) {
        goToGroomingStep("services");
        return;
      }
      if (Object.keys(error.errors || {}).some((key) => key.endsWith('.birthdate'))) {
        goToGroomingStep("pets");
        return;
      }
      const existingBookingId = error.errors?.existing_booking_id;
      if (existingBookingId) {
        openDuplicateModal(existingBookingId, error.errors.existing_booking_ref, schedule);
      } else {
        showSubmitError(error.message || "Pre-registration submission failed. Please try again.");
      }
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

  // ── Duplicate booking modal ───────────────────────────────────────────────
  function openDuplicateModal(existingBookingId, existingBookingRef, schedule) {
    const modal       = document.getElementById("duplicateBookingModal");
    const refSpan     = document.getElementById("duplicateBookingRef");
    const yesBtn      = document.getElementById("duplicateYesBtnModal");
    const noBtn       = document.getElementById("duplicateNoBtnModal");
    const errorMsg    = document.getElementById("duplicateModalError");
    const modalBody   = document.getElementById("duplicateModalBody");
    const successDiv  = document.getElementById("duplicateModalSuccess");
    const successText = document.getElementById("duplicateSuccessText");

    refSpan.textContent = existingBookingRef || "existing visit";
    errorMsg.textContent = "";
    errorMsg.classList.add("hidden");
    modalBody.classList.remove("hidden");
    successDiv.classList.add("hidden");

    modal.classList.remove("hidden");
    modal.classList.add("flex");

    noBtn.onclick = () => {
      window.location.href = "./dashboard.html";
    };

    yesBtn.onclick = async () => {
      yesBtn.disabled = true;
      yesBtn.textContent = "Rescheduling...";
      errorMsg.classList.add("hidden");

      try {
        const data = await API.rescheduleBooking(existingBookingId, schedule.date, schedule.window_id);

        // Show success state
        modalBody.classList.add("hidden");
        successDiv.classList.remove("hidden");
        successText.textContent =
          `Visit ${data.booking.booking_reference} has been moved to ${formatScheduleLabel(data.booking.booking_date, data.booking.window)}.`;

        setTimeout(() => {
          window.location.href = "./dashboard.html";
        }, 2000);
      } catch (rescheduleError) {
        errorMsg.textContent = rescheduleError.message || "Reschedule failed. Please try again.";
        errorMsg.classList.remove("hidden");
        yesBtn.disabled = false;
        yesBtn.textContent = "Yes, Reschedule";
      }
    };
  }

  function formatScheduleLabel(date, windowLabel) {
    if (!date) return windowLabel || "the new schedule";
    try {
      const formatted = new Intl.DateTimeFormat("en-PH", {
        year: "numeric", month: "long", day: "numeric",
      }).format(new Date(date + "T00:00:00"));
      return windowLabel ? `${formatted} at ${windowLabel}` : formatted;
    } catch {
      return windowLabel || date;
    }
  }

}
