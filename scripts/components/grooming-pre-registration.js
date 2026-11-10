import { initBookingPetStep, refreshBookingPetStep } from "./booking-pet-step.js?v=pet-weight-size-20261107-v1&copy=grooming-terminology-20261016&flow=grooming-order-20261016&age=20261016&pet-fields=20261030-v1&registration=fcfs-20261027-v1&registration-rollover=20261027-v3&pet-care=20261027-v1&remember=20261029&customer-ux=20261031-v1&service-access=20261031-v1&pet-sessions=20261031-v1&pet-birthdate=20261103-v1&urls=20261103&queue-copy=20261103-v1&pet-profile=20261110-v1&body-condition=20261110-v2&short-notes=500-20261110&size-availability=20261110";
import { initBookingServicesStep, refreshBookingServicesStep } from "./booking-services-step.js?v=grooming-pricing-20261006&flow=grooming-order-20261016&groomers=named-20261016&catalog=services-20261017-2&services=20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&estimates=20261031&estimate-ui=20261031-v2&overdue=20261031-v1&urls=20261103&pet-profile=20261110-v1&body-condition=20261110-v2&short-notes=500-20261110&size-availability=20261110";
import { initBookingReviewStep, refreshBookingReviewStep } from "./booking-review-step.js?v=grooming-actual-20261026&pet-weight=pet-weight-size-20261107-v1&copy=grooming-terminology-20261016&flow=grooming-order-20261016&groomers=named-20261016&catalog=services-20261017-2&services=20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3&estimates=20261031&estimate-ui=20261031-v2&overdue=20261031-v1&urls=20261103&queue-copy=20261103-v1&pet-profile=20261110-v1&body-condition=20261110-v2&short-notes=500-20261110&size-availability=20261110";
import { initBookingConsentStep } from "./booking-consent-step.js?pet-fields=20261030-v1&v=grooming-actual-20261026&pet-weight=pet-weight-size-20261107-v1&copy=grooming-terminology-20261016&flow=grooming-order-20261016&groomers=named-20261016&consent=20261017&age=20261016&catalog=services-20261017-2&services=20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3&queue-copy=20261103-v1&pet-profile=20261110-v1&body-condition=20261110-v2&short-notes=500-20261110&size-availability=20261110";
import { initBookingConfirmedStep } from "./booking-confirmed-step.js?copy=grooming-terminology-20261016&groomers=named-20261016&consent=20261016&catalog=services-20261017-2&services=20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&registration-ui=20261027-v3&mobile-format=20261110&urls=20261103&queue-copy=20261103-v1&registration-reference=20261106-v1&owner-name=20261108&short-notes=500-20261110";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=phone-20261022&registration=fcfs-20261027-v1&registration-rollover=20261027-v3&service-access=20261031-v1&urls=20261103&queue-copy=20261103-v1";
import {
  GROOMING_AVAILABILITY_ERROR, hasCompletedRequiredServiceSelection, loadGroomingCatalogue,
  normalizeStepThreeDraft, petGroomingSizeError, selectedPricingSignature, specialInstructionsError,
} from "../services/grooming-service.js?v=managed-services-20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&short-notes=500-20261110&size-availability=20261110";
import { loadPetGroomers, hasPetGroomerSelection } from "./pet-groomer-selection.js?services=20261026&registration=fcfs-20261027-v1";
import { isEligibleForGrooming } from "../services/grooming-age.js";

const steps = ["pets", "services", "review", "consent", "confirmed"];
const initialized = new Set();
let currentStep = null;
let navigationRevision = 0;

function stored(key) {
  try {
    return JSON.parse(sessionStorage.getItem(key) || "null");
  } catch {
    return null;
  }
}

function availableStep(requested) {
  const pets = stored("bookingPets");
  if (requested === "confirmed" && stored("bookingConfirmation")) return requested;
  if (requested === "pets" || !Array.isArray(pets) || !pets.length
    || pets.some(pet => !isEligibleForGrooming(pet) || petGroomingSizeError(pet))) return "pets";
  if (requested === "services") return requested;
  const draft = { pets };
  const selections = normalizeStepThreeDraft(stored("bookingStep3"), draft, { keepUnavailablePackages: true });
  if (pets.some((pet, index) => {
    const selection = selections[index];
    return !hasCompletedRequiredServiceSelection(pet, selection)
      || !hasPetGroomerSelection(selection) || specialInstructionsError(pet, selection);
  })) return "services";
  if (requested === "review" || !stored("bookingStep4Review")?.pets?.length
    || sessionStorage.getItem("groomingReviewedPricing") !== selectedPricingSignature(draft, selections)) return "review";
  return requested === "consent" ? "consent" : "pets";
}

function stepUrl(step) {
  const url = new URL(window.location.href);
  if (step === "pets") url.searchParams.delete("step");
  else url.searchParams.set("step", step);
  return url;
}

async function enterStep(step, availability) {
  const firstEntry = !initialized.has(step);
  initialized.add(step);
  if (step === "pets") {
    if (firstEntry) await initBookingPetStep(availability);
    else await refreshBookingPetStep(availability);
  } else if (step === "services") {
    if (firstEntry) initBookingServicesStep(availability);
    else refreshBookingServicesStep(availability);
  } else if (step === "review") {
    if (firstEntry) await initBookingReviewStep(availability);
    else await refreshBookingReviewStep(availability);
  } else if (step === "consent") {
    if (firstEntry) initBookingConsentStep();
  } else if (step === "confirmed") {
    if (firstEntry) initBookingConfirmedStep();
  }
}

async function navigate(requested, historyAction = "push") {
  const revision = ++navigationRevision;
  if (requested === "schedule") requested = "review";
  if (!steps.includes(requested)) requested = "pets";
  if (currentStep === "confirmed" && requested !== "confirmed") requested = "confirmed";
  if (requested === "confirmed" && !stored("bookingConfirmation")) requested = "pets";
  let availability;
  let step = requested;
  if (["services", "review", "consent"].includes(requested)) {
    const pets = stored("bookingPets");
    if (!Array.isArray(pets) || !pets.length || pets.some(pet => !isEligibleForGrooming(pet))) {
      step = "pets";
    } else {
      const [catalogue, groomers] = await Promise.allSettled([loadGroomingCatalogue(), loadPetGroomers()]);
      if (revision !== navigationRevision) return;
      availability = {
        catalogueReady: catalogue.status === "fulfilled",
        error: catalogue.status === "rejected" ? GROOMING_AVAILABILITY_ERROR
          : groomers.status === "rejected" ? "Unable to load Pet Groomer availability. Please try again." : "",
      };
      if (!availability.catalogueReady) {
        step = ["pets", "services", "review"].includes(currentStep) ? currentStep : "services";
      } else {
        step = availableStep(requested);
        if (step !== "pets" && availability.error) step = "services";
      }
    }
  }

  const changed = step !== currentStep;
  for (const section of document.querySelectorAll("[data-grooming-step]")) {
    const visible = section.dataset.groomingStep === step;
    section.hidden = !visible;
    section.classList.toggle("hidden", !visible);
  }
  currentStep = step;
  document.body.classList.toggle("bg-[#f6fafd]", step === "pets");
  document.body.classList.toggle("bg-slate-50", step !== "pets");

  if (historyAction === "push" && changed) window.history.pushState({ step }, "", stepUrl(step));
  if (historyAction === "replace") window.history.replaceState({ step }, "", stepUrl(step));

  if (changed) window.scrollTo(0, 0);
  await enterStep(step, availability && { ...availability, refreshPets: changed });
}

document.addEventListener("DOMContentLoaded", async () => {
  sessionStorage.removeItem("bookingSchedule");
  sessionStorage.removeItem("bookingScheduleArrivalError");
  const requested = new URLSearchParams(window.location.search).get("step") || "pets";
  if (requested !== "confirmed" || !stored("bookingConfirmation")) {
    const blocked = await initBookingFormAccessGuard({ service: "grooming", dashboardPath: "/account" });
    if (blocked) return;
  }

  document.addEventListener("grooming:step", (event) => navigate(event.detail.step));
  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[data-grooming-target]");
    if (!link) return;
    event.preventDefault();
    navigate(link.dataset.groomingTarget);
  });
  window.addEventListener("popstate", () => {
    return navigate(new URLSearchParams(window.location.search).get("step") || "pets", "replace");
  });
  window.addEventListener("pageshow", event => {
    if (event.persisted) return navigate(new URLSearchParams(window.location.search).get("step") || "pets", "replace");
  });
  await navigate(requested, "replace");
});
