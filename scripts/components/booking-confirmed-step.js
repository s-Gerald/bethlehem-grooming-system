import { formatBookingDate } from "../services/booking-format-service.js";
import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { getGroomingPricePresentation } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import {
  confirmationFields, confirmationSection, formatConfirmationLabel,
  formatConfirmationTimestamp, formatConfirmationPhone, ownerConfirmationFields,
  readConfirmationData, renderConfirmation,
} from "./confirmation-presentation.js?registration=fcfs-20261027-v1&registration-ui=20261027-v3";

export function groomingPetConfirmation(pet, review = {}, showPrice = true) {
  const price = getGroomingPricePresentation(review.pricing);
  const preference = review.groomingPreferenceLabel || pet.grooming_preference_label || formatConfirmationLabel(
    review.groomingPreference || review.grooming_preference || pet.grooming_preference,
  );
  const size = formatConfirmationLabel(pet.size || review.size) || "Not specified";
  const verified = pet.sizeVerified ?? review.sizeVerified;
  const weight = pet.weight ?? review.weight;
  const sizeSource = verified === true ? "Clinic verified" : verified === false ? "Estimated" : "";
  const services = Array.isArray(review.selectedServiceNames)
    ? review.selectedServiceNames.map(formatConfirmationLabel).join(", ")
    : formatConfirmationLabel(review.servicePackage);
  const alaCarte = Array.isArray(review.alaCarteServiceNames || review.alaCarteServices)
    ? (review.alaCarteServiceNames || review.alaCarteServices).map(formatConfirmationLabel).join(", ") : "";
  return `<section class="confirmation-pet">
    <h3>${escapeHtml(pet.petName || review.petName || "Unnamed Pet")}</h3>
    ${pet.pet_queue_number != null ? `<p>Queue #${escapeHtml(pet.pet_queue_number)}</p>` : ""}
    ${confirmationFields([
      ["Pet type", formatConfirmationLabel(pet.petType || review.petType) || "Not specified"],
      ["Breed", pet.breed || review.breed || "Not specified"],
      ["Size", [size, sizeSource].filter(Boolean).join(" · ")],
      ...(weight !== undefined && weight !== null && weight !== "" ? [["Weight", `${weight} kg`]] : []),
    ])}
    <div class="confirmation-pet-services">${confirmationFields([
      ["Pet Groomer", review.petGroomerName || pet.pet_groomer?.first_name || "Any available groomer"],
      ["Grooming package", services || "None selected"],
      ...(preference ? [["Grooming preference", preference]] : []),
      ...(review.haircutRequested || pet.haircut_requested ? [["Haircut", "Requested"]] : []),
      ["A la Carte", alaCarte || "None selected"],
      ...(showPrice ? [[price.isEstimate ? "Pet price estimate" : "Pet subtotal", price.amount]] : []),
      ...(review.specialInstructions?.trim() ? [["Special instructions", review.specialInstructions.trim(), null, true]] : []),
    ])}</div>
  </section>`;
}

export async function initBookingConfirmedStep() {
  const isWalkInConfirmation = document.body.dataset.confirmationContext === "walk-in";
  if (isWalkInConfirmation && !await API.waitForSessionVerification()) return;
  const confirmation = readConfirmationData(isWalkInConfirmation ? "walkInBookingConfirmation" : "bookingConfirmation");
  const bookingConsentStep = confirmation?.consent || readConfirmationData(isWalkInConfirmation ? "walkInConsentStep" : "bookingConsentStep");
  const root = document.getElementById("confirmationRoot");
  if (!root) return;
  if (!confirmation) {
    root.innerHTML = '<h1 tabindex="-1">Confirmation unavailable</h1><p role="status">Registration data not found. Please start a new registration.</p>';
    root.querySelector("h1").focus();
    return;
  }

  const pets = Array.isArray(confirmation.pets) ? confirmation.pets : [];
  const reviewPets = Array.isArray(confirmation.review?.pets) ? confirmation.review.pets : [];
  const petDetails = (pets.length ? pets : reviewPets).map((pet, index) => {
    const review = reviewPets.find((item) => item.petId != null && String(item.petId) === String(pet.id ?? pet.petId)) || reviewPets[index] || {};
    return groomingPetConfirmation(pet, review, (pets.length || reviewPets.length) > 1);
  }).join("");
  const groomingConsent = bookingConsentStep?.grooming_consent ?? bookingConsentStep?.groomingAgreementAccepted;
  const sedationConsent = bookingConsentStep?.sedation_consent ?? bookingConsentStep?.sedationConsentAccepted;
  const consentFields = bookingConsentStep ? [
    ...(typeof groomingConsent === "boolean" ? [["Grooming consent", groomingConsent ? "Agreed" : "Not agreed"]] : []),
    ...(typeof sedationConsent === "boolean" ? [["Sedation consent", sedationConsent ? "Authorized" : "Not authorized"]] : []),
    ...(bookingConsentStep.signer_name ? [["Signed by", bookingConsentStep.signer_name]] : []),
    ...(bookingConsentStep.signed_at ? [["Signed at", new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(new Date(bookingConsentStep.signed_at))]] : []),
  ] : [];
  const currentStatus = confirmation.status === "waiting_to_arrive" ? "Waiting to Arrive" : formatConfirmationLabel(confirmation.status) || "Not available";
  const totalPrice = getGroomingPricePresentation(confirmation.review?.totalPricing);
  const sections = [
    confirmationSection(isWalkInConfirmation ? "Check-in Details" : "Registration Details", confirmationFields(
      isWalkInConfirmation ? [
        ["Entry source", "Walk-in"],
        ["Queue number", confirmation.queue_number ?? "Not assigned"],
        ["Checked in at", formatConfirmationTimestamp(confirmation.checked_in_at || confirmation.submitted_at || confirmation.created_at) || "Not available", "submittedAt"],
        ["Current status", currentStatus],
      ] : [
        ["Entry source", "Online Registration"],
        ["Visit day", formatBookingDate(confirmation.booking_date) || "Not available"],
        ["Queue number", confirmation.queue_number ?? "Not assigned"],
        ["Status", currentStatus],
        ["Submitted online", formatConfirmationTimestamp(confirmation.submitted_at || confirmation.created_at) || "Not available", "submittedAt"],
      ],
    )),
    confirmationSection("Owner Information", ownerConfirmationFields(confirmation.owner)),
    confirmationSection(`Pets (${pets.length || reviewPets.length})`, petDetails || "<p>No pet information available.</p>"),
    consentFields.length ? confirmationSection("Grooming Consent", confirmationFields(consentFields)) : "",
    confirmationSection("Price Summary", `      <div class="confirmation-estimate">
        ${confirmationFields([[totalPrice.label, totalPrice.amount]])}
        ${totalPrice.isEstimate ? `<span class="confirmation-badge">Estimate Only</span>
        <p class="confirmation-estimate-note">${escapeHtml(totalPrice.disclaimer)}</p>` : ""}
      </div>`),
    !isWalkInConfirmation ? confirmationSection("What Happens Next", "<p>Show your registration reference when you arrive. Your queue number is assigned after check-in.</p>") : "",
  ].join("");

  renderConfirmation(root, {
    title: isWalkInConfirmation ? "Grooming Walk-in Checked In" : "Online Registration Received",
    message: isWalkInConfirmation ? "The pet has been added to today’s grooming queue." : "",
    printTitle: isWalkInConfirmation ? "GROOMING WALK-IN CHECK-IN CONFIRMATION" : "Grooming Online Registration Confirmation",
    reference: confirmation.booking_reference,
    status: isWalkInConfirmation ? currentStatus : "Waiting to Arrive",
    walkIn: isWalkInConfirmation, queueNumber: confirmation.queue_number, sections,
    primaryHref: isWalkInConfirmation ? "./appointments.html" : "./dashboard.html",
    primaryLabel: isWalkInConfirmation ? "Go to Grooming" : "Return to Dashboard",
    warning: !confirmation.booking_reference ? "Some confirmation details are incomplete. Please contact the clinic." : "",
  });
  const ownerName = document.getElementById("ownerName");
  const ownerPhone = document.getElementById("ownerPhone");
  const ownerEmail = document.getElementById("ownerEmail");
  const submittedAt = document.getElementById("submittedAt");
  populateOwnerInfo();
  populateSubmittedAt();

  function setText(element, value) { if (element) element.textContent = value; }

  async function populateSubmittedAt() {
    const savedTimestamp = confirmation?.submitted_at || confirmation?.created_at;

    if (savedTimestamp || isWalkInConfirmation || !confirmation?.booking_reference) {
      return;
    }

    try {
      const data = await API.getBookingHistory();
      const bookings = [
        ...(Array.isArray(data?.bookings) ? data.bookings : []),
        ...(Array.isArray(data?.history) ? data.history : []),
      ];
      const savedBooking = bookings.find(
        (booking) =>
          booking.booking_reference === confirmation.booking_reference,
      );
      const createdAt = savedBooking?.created_at;

      if (!createdAt) return;

      confirmation.submitted_at = createdAt;
      sessionStorage.setItem("bookingConfirmation", JSON.stringify(confirmation));
      setText(submittedAt, formatConfirmationTimestamp(createdAt) || "Not available.");
    } catch {
      // Keep the existing fallback when history cannot be loaded.
    }
  }

  async function populateOwnerInfo() {
    const owner = confirmation?.owner;

    if (owner) {
      return;
    }

    if (isWalkInConfirmation) {
      setText(ownerName, "Not provided");
      setText(ownerPhone, "Not provided");
      setText(ownerEmail, "Not provided");
      return;
    }

    try {
      const data = await API.getMe("customer");
      const user = data?.user;
      const fullName = [user?.first_name, user?.last_name].filter(Boolean).join(" ")
        || user?.username
        || "Not provided";
      setText(ownerName, fullName);
      setText(ownerPhone, formatConfirmationPhone(user?.phone) || "Not provided");
      setText(ownerEmail, user?.email || "Not provided");
    } catch {
      setText(ownerName, "Not provided");
      setText(ownerPhone, "Not provided");
      setText(ownerEmail, "Not provided");
    }
  }
}

if (typeof document !== "undefined" && document.body?.dataset.confirmationContext === "walk-in") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initBookingConfirmedStep, { once: true });
  } else {
    initBookingConfirmedStep();
  }
}
