import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { formatBookingDate } from "../services/booking-format-service.js";
import { getGroomingPricePresentation } from "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import {
  confirmationDocument, confirmationFields, confirmationSection,
  formatConfirmationLabel, formatConfirmationTimestamp, ownerConfirmationFields, printConfirmation,
} from "./confirmation-presentation.js?v=20261017&registration=fcfs-20261027-v1&registration-ui=20261027-v3";

// The detail endpoint supplies saved selections, price bounds, and signed consent.
export function groomingVisitPresentation(booking, statusLabel) {
  const waiting = ["waiting_to_arrive", "waiting"].includes(booking.status);
  const pets = booking.booking_pets || [];
  const services = booking.booking_services || [];
  const serviceNames = (items) => items.map((item) => item.service?.service_name).filter(Boolean).join(", ");
  const walkIn = booking.booking_type === "walk_in";
  const title = walkIn ? "Grooming Walk-in Summary" : "Grooming Online Registration Summary";
  const registrationFields = [
    ["Registration reference", booking.booking_reference || "Not recorded"],
    ["Entry source", walkIn ? "Walk-in" : "Online Registration"],
    ["Visit day", formatBookingDate(booking.booking_date) || "Not recorded"],
    ...(!walkIn && booking.created_at ? [["Submitted online", formatConfirmationTimestamp(booking.created_at)]] : []),
    ...(booking.dropped_off_at ? [["Checked in", formatConfirmationTimestamp(booking.dropped_off_at)]] : []),
    ["Queue number", booking.queue_number ?? "Not assigned"],
  ];
  const header = confirmationSection("Registration Details",
    `<p class="grooming-summary-status">${escapeHtml(statusLabel)}</p>${confirmationFields(registrationFields)}`);
  const details = pets.map((item) => {
    const pet = item.pet || {};
    const selected = services.filter((service) => String(service.booking_pet_id) === String(item.booking_pet_id));
    const petPrice = getGroomingPricePresentation(savedServicePricing(selected));
    const size = item.confirmed_size || item.registered_size || pet.size;
    const duration = formatActualGroomingDuration(item.grooming_start_time, item.grooming_end_time);
    const petStatus = item.grooming_state === "cancelled" ? "Cancelled"
      : ["for_payment", "released"].includes(booking.status) ? statusLabel
      : item.grooming_end_time ? "Grooming Finished" : item.grooming_start_time ? "Grooming in Progress"
      : waiting ? "Waiting to Arrive" : "Checked In";
    return `<section class="grooming-summary-pet confirmation-pet">
      <h3>${escapeHtml(pet.pet_name || "Your pet")}</h3>
      <p class="grooming-summary-pet-status">${escapeHtml(petStatus)} · ${item.pet_queue_number != null ? `Queue #${escapeHtml(item.pet_queue_number)}` : "Queue not assigned"}</p>
      ${confirmationFields([
        ["Grooming package", serviceNames(selected.filter((service) => service.service?.kind === "package")) || "None selected"],
        ["A la Carte", serviceNames(selected.filter((service) => service.service?.kind !== "package")) || "None selected"],
        ["Pet groomer", item.groomer?.first_name || "Any available groomer"],
        ...(pets.length > 1 && selected.length ? [["Saved service subtotal", petPrice.amount]] : []),
        ...(item.haircut_requested ? [["Haircut", "Requested"]] : []),
        ...(item.grooming_preference_label || item.grooming_preference ? [["Grooming preference", item.grooming_preference_label || formatConfirmationLabel(item.grooming_preference)]] : []),
        ...(pet.breed ? [["Breed", pet.breed]] : []),
        ...(pet.species ? [["Species", formatConfirmationLabel(pet.species)]] : []),
        ...(size ? [["Size", formatConfirmationLabel(size)]] : []),
        ...(pet.weight != null && pet.weight !== "" ? [["Weight", `${pet.weight} kg`]] : []),
        ...(item.grooming_start_time ? [["Started", formatConfirmationTimestamp(item.grooming_start_time)]] : []),
        ...(item.grooming_end_time ? [["Finished", formatConfirmationTimestamp(item.grooming_end_time)]] : []),
        ...(duration ? [["Actual Grooming Duration", duration]] : []),
        ...(item.special_instructions?.trim() ? [["Special instructions", item.special_instructions.trim(), null, true]] : []),
      ])}
    </section>`;
  }).join("");
  const consent = booking.consent;
  const consentText = (value, yes, no) => typeof value === "boolean" ? (value ? yes : no) : "Not recorded";
  const consentSection = confirmationSection("Consent", confirmationFields([
    ["Grooming consent", consentText(consent?.grooming_consent, "Agreed", "Not agreed")],
    ["Sedation consent", consentText(consent?.sedation_consent, "Authorized", "Not authorized")],
    ["Signed by", consent?.signer_name || "Not recorded"],
    ["Signed at", formatConfirmationTimestamp(consent?.signed_at) || "Not recorded"],
  ]));
  // Use saved booking bounds, never today's catalogue prices. Paid visits use the saved total.
  const pricing = booking.paid && booking.total_amount != null
    ? { minAmount: Number(booking.total_amount), maxAmount: Number(booking.total_amount) }
    : savedServicePricing(services);
  const price = getGroomingPricePresentation(pricing);
  const priceSection = `<section class="confirmation-section grooming-summary-total">
    ${confirmationFields([[price.label, price.amount]])}
    ${price.isEstimate ? `<p class="confirmation-estimate-note">${escapeHtml(price.disclaimer)}</p>` : ""}
  </section>`;
  const petSections = confirmationSection(`Pets (${pets.length})`, details);
  const owner = booking.user || booking.owner;
  const ownerSection = owner ? confirmationSection("Owner Information", ownerConfirmationFields(owner)) : "";
  const sections = header + petSections + consentSection + priceSection;
  return {
    title,
    html: sections,
    print: confirmationDocument({
      printTitle: title, reference: booking.booking_reference,
      status: statusLabel, message: "", showOperational: false,
      sections: header + ownerSection + petSections + consentSection + priceSection,
    }),
  };
}

let requestId = 0;
let returnTrigger;
let initialized = false;

export async function openGroomingVisitSummary(bookingId, trigger, statusFor) {
  const dialog = document.getElementById("groomingVisitSummary");
  const content = document.getElementById("groomingVisitSummaryContent");
  const printButton = document.getElementById("printVisitSummary");
  const printRoot = document.getElementById("groomingVisitPrint");
  const closeButton = document.getElementById("closeVisitSummary");
  if (!initialized) {
    initialized = true;
    closeButton.addEventListener("click", () => dialog.close());
    document.getElementById("dismissVisitSummary").addEventListener("click", () => dialog.close());
    dialog.addEventListener("keydown", (event) => {
      if (event.key !== "Tab") return;
      const controls = [...dialog.querySelectorAll("button:not(:disabled)")];
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    dialog.addEventListener("close", () => {
      requestId++;
      document.body.classList.remove("grooming-summary-open");
      // Dashboard polling may have replaced the original trigger while open.
      const target = returnTrigger?.isConnected ? returnTrigger : document.getElementById(returnTrigger?.id);
      (target || document.getElementById("trackerHeading"))?.focus({ preventScroll: true });
    });
    printButton.addEventListener("click", () => {
      document.body.classList.add("grooming-summary-printing");
      try { printConfirmation(); }
      finally { document.body.classList.remove("grooming-summary-printing"); }
    });
  }
  const currentRequest = ++requestId;
  returnTrigger = trigger;
  printButton.disabled = true;
  printRoot.innerHTML = "";
  document.getElementById("groomingVisitSummaryHeading").textContent = "Registration Summary";
  content.innerHTML = '<p role="status">Loading visit summary…</p>';
  content.setAttribute("aria-busy", "true");
  if (!dialog.open) dialog.showModal();
  document.body.classList.add("grooming-summary-open");
  closeButton.focus({ preventScroll: true });
  try {
    const { booking } = await API.getBooking(bookingId);
    if (currentRequest !== requestId || !dialog.open) return;
    const statusBooking = { ...booking, pets: (booking.booking_pets || []).map((pet) => ({
      grooming_status: pet.grooming_state === "cancelled" ? "cancelled"
        : pet.grooming_end_time ? "grooming_finished" : pet.grooming_start_time ? "in_progress" : booking.status,
      grooming_started_at: pet.grooming_start_time || null, grooming_started_timestamp: pet.grooming_start_time || null,
    })) };
    const summary = groomingVisitPresentation(booking, statusFor(statusBooking));
    document.getElementById("groomingVisitSummaryHeading").textContent = summary.title;
    content.innerHTML = summary.html;
    printRoot.innerHTML = summary.print;
    printButton.disabled = false;
  } catch {
    if (currentRequest === requestId && dialog.open) content.innerHTML = '<p role="alert">Unable to load visit summary. Close and try again.</p>';
  } finally {
    if (currentRequest === requestId) content.setAttribute("aria-busy", "false");
  }
}

function savedServicePricing(services) {
  if (!services.length) return null;
  return {
    minAmount: services.reduce((sum, item) => sum + Number(item.price_min_at_booking ?? item.price_at_booking ?? 0), 0),
    maxAmount: services.some((item) => item.price_min_at_booking != null && item.price_max_at_booking == null)
      ? null : services.reduce((sum, item) => sum + Number(item.price_max_at_booking ?? item.price_at_booking ?? 0), 0),
  };
}

function formatActualGroomingDuration(startedAt, finishedAt) {
  if (!startedAt || !finishedAt) return "";
  const milliseconds = new Date(finishedAt).getTime() - new Date(startedAt).getTime();
  if (!Number.isFinite(milliseconds) || milliseconds < 0) return "";
  const minutes = Math.floor(milliseconds / 60000);
  const hours = Math.floor(minutes / 60);
  return hours ? `${hours} hr${minutes % 60 ? ` ${minutes % 60} min` : ""}` : `${minutes} min`;
}
