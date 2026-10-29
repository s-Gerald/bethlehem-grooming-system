import { initBookingPetStep, refreshBookingPetStep } from "./booking-pet-step.js?v=pet-weight-size-20261011-v3&copy=grooming-terminology-20261016&flow=grooming-order-20261016&age=20261016&pet-fields=20261026-v1&registration=fcfs-20261027-v1&registration-rollover=20261027-v3&pet-care=20261027-v1&remember=20261029";
import { initBookingServicesStep, refreshBookingServicesStep } from "./booking-services-step.js?v=grooming-pricing-20261006&flow=grooming-order-20261016&groomers=named-20261016&catalog=services-20261017-2&services=20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import { initBookingReviewStep, refreshBookingReviewStep } from "./booking-review-step.js?v=grooming-actual-20261026&pet-weight=pet-weight-size-20261011-v3&copy=grooming-terminology-20261016&flow=grooming-order-20261016&groomers=named-20261016&catalog=services-20261017-2&services=20261026&grooming=actual-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3";
import { initBookingConsentStep } from "./booking-consent-step.js?v=grooming-actual-20261026&pet-weight=pet-weight-size-20261011-v3&copy=grooming-terminology-20261016&flow=grooming-order-20261016&groomers=named-20261016&consent=20261017&age=20261016&catalog=services-20261017-2&services=20261026&grooming=actual-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&registration-rollover=20261027-v3";
import { initBookingConfirmedStep } from "./booking-confirmed-step.js?copy=grooming-terminology-20261016&groomers=named-20261016&consent=20261016&catalog=services-20261017-2&services=20261026&grooming=actual-20261026&registration=fcfs-20261027-v1&registration-ui=20261027-v3&mobile-format=20261029";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=phone-20261022&registration=fcfs-20261027-v1&registration-rollover=20261027-v3";
import { hasCompletedRequiredServiceSelection, loadGroomingCatalogue } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import { loadPetGroomers, hasPetGroomerSelection } from "./pet-groomer-selection.js?services=20261026&registration=fcfs-20261027-v1";
import { isEligibleForGrooming } from "../services/grooming-age.js";

const steps = ["pets", "services", "review", "consent", "confirmed"];
const initialized = new Set();
let currentStep = null;

function stored(key) {
  try {
    return JSON.parse(sessionStorage.getItem(key) || "null");
  } catch {
    return null;
  }
}

function availableStep(requested) {
  const pets = stored("bookingPets");
  const services = stored("bookingStep3");
  const review = stored("bookingStep4Review");
  if (requested === "confirmed" && stored("bookingConfirmation")) return requested;
  if (requested === "pets" || !Array.isArray(pets) || !pets.length || pets.some((pet) => !isEligibleForGrooming(pet))) return "pets";
  if (requested === "services" || pets.some((pet) =>
    !hasCompletedRequiredServiceSelection(pet, services?.petSelections?.find((selection) => String(selection.petId) === String(pet.id)))
    || !hasPetGroomerSelection(services?.petSelections?.find((selection) => String(selection.petId) === String(pet.id)))
  )) return "services";
  if (requested === "review" || !review?.pets?.length) return "review";
  return requested === "consent" ? "consent" : "pets";
}

function stepUrl(step) {
  const url = new URL(window.location.href);
  if (step === "pets") url.searchParams.delete("step");
  else url.searchParams.set("step", step);
  return url;
}

async function enterStep(step) {
  if (step === "pets") {
    if (initialized.has(step)) await refreshBookingPetStep();
    else await initBookingPetStep();
  } else if (step === "services") {
    if (initialized.has(step)) refreshBookingServicesStep();
    else initBookingServicesStep();
  } else if (step === "review") {
    if (initialized.has(step)) refreshBookingReviewStep();
    else initBookingReviewStep();
  } else if (step === "consent") {
    if (!initialized.has(step)) initBookingConsentStep();
  } else if (step === "confirmed") {
    if (!initialized.has(step)) initBookingConfirmedStep();
  }
  initialized.add(step);
}

async function navigate(requested, historyAction = "push") {
  if (requested === "schedule") requested = "review";
  if (!steps.includes(requested)) requested = "pets";
  if (currentStep === "confirmed" && requested !== "confirmed") requested = "confirmed";
  const step = availableStep(requested);
  if (step === currentStep) {
    if (historyAction === "replace") window.history.replaceState({ step }, "", stepUrl(step));
    return;
  }

  for (const section of document.querySelectorAll("[data-grooming-step]")) {
    const visible = section.dataset.groomingStep === step;
    section.hidden = !visible;
    section.classList.toggle("hidden", !visible);
  }
  currentStep = step;
  document.body.classList.toggle("bg-[#f6fafd]", step === "pets");
  document.body.classList.toggle("bg-slate-50", step !== "pets");

  if (historyAction === "push") window.history.pushState({ step }, "", stepUrl(step));
  if (historyAction === "replace") window.history.replaceState({ step }, "", stepUrl(step));

  window.scrollTo(0, 0);
  await enterStep(step);
}

document.addEventListener("DOMContentLoaded", async () => {
  sessionStorage.removeItem("bookingSchedule");
  sessionStorage.removeItem("bookingScheduleArrivalError");
  let requested = new URLSearchParams(window.location.search).get("step") || "pets";
  if (requested !== "confirmed" || !stored("bookingConfirmation")) {
    const blocked = await initBookingFormAccessGuard({ dashboardPath: "./dashboard.html" });
    if (blocked) return;
    if (["schedule", "review", "consent"].includes(requested)) {
      try { await Promise.all([loadGroomingCatalogue(), loadPetGroomers()]); }
      catch { requested = "services"; }
    }
  }

  document.addEventListener("grooming:step", (event) => navigate(event.detail.step));
  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[data-grooming-target]");
    if (!link) return;
    event.preventDefault();
    navigate(link.dataset.groomingTarget);
  });
  window.addEventListener("popstate", () => {
    navigate(new URLSearchParams(window.location.search).get("step") || "pets", "replace");
  });
  await navigate(requested, "replace");
});
