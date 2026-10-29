import { escapeHtml } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import '../utils/philippine-mobile.js?v=mobile-format-20261029';

export function confirmationFields(fields) {
  return `<dl class="confirmation-fields">${fields.map(([label, value, id, wide]) => `
    <div${wide ? ' class="confirmation-field--wide"' : ""}><dt>${escapeHtml(label)}</dt><dd${id ? ` id="${escapeHtml(id)}"` : ""}>${escapeHtml(value ?? "Not provided")}</dd></div>
  `).join("")}</dl>`;
}

export function clinicReasonConfirmation(appointment) {
  const concerns = Array.isArray(appointment.common_concerns) ? appointment.common_concerns : [];
  let details = String(appointment.chief_complaint ?? "").trim();
  // Current API summaries prefix the free text with the selected concerns.
  // Only remove that exact prefix; preserve separately supplied free text.
  const summary = concerns.join(", ");
  if (summary && details === summary) details = "";
  else if (summary && details.startsWith(summary + "\n")) details = details.slice(summary.length).trim();
  return confirmationFields([
    ["Selected concerns", concerns.join(" · ") || "None provided"],
    ["Additional details", details || "None provided"],
  ]).replace('class="confirmation-fields"', 'class="confirmation-fields confirmation-fields--reason"');
}


export function confirmationSection(title, content) {
  return `<section class="confirmation-section"><h2>${escapeHtml(title)}</h2>${content}</section>`;
}

export function formatConfirmationLabel(value) {
  return String(value ?? "").replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function formatConfirmationTimestamp(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return new Intl.DateTimeFormat("en-PH", {
    timeZone: "Asia/Manila", year: "numeric", month: "long", day: "numeric",
    hour: "numeric", minute: "2-digit", hour12: true,
  }).format(date);
}

export function formatConfirmationPhone(value) {
  return globalThis.PhilippineMobile.format(value);
}

export function ownerConfirmationFields(owner = {}) {
  const name = owner.fullName || owner.name || [
    owner.firstName || owner.first_name,
    owner.middleInitial ? `${owner.middleInitial}.` : "",
    owner.lastName || owner.last_name,
  ].filter(Boolean).join(" ") || owner.username;
  return confirmationFields([
    ["Full name", name || "Not provided", "ownerName"],
    ["Contact number", formatConfirmationPhone(owner.phone) || "Not provided", "ownerPhone"],
    ["Email", owner.email || "Not provided", "ownerEmail"],
  ]);
}

export function readConfirmationData(key) {
  try { return JSON.parse(sessionStorage.getItem(key) || "null"); }
  catch { return null; }
}

export function confirmationDocument({
  message, printTitle, reference, status, walkIn = false, queueNumber, sections, warning = "", showOperational = true,
}) {
  return `<article class="confirmation-document" aria-label="Confirmation details">
      <header class="confirmation-brand-header">
        <div class="brand-lockup" role="img" aria-label="Bethlehem Animal Clinic">
          <img src="../../assets/images/clinic/Bethlehem_Logo-256.png" alt="" class="brand-lockup__mark" />
          <span class="brand-lockup__divider" aria-hidden="true"></span>
          <span class="brand-lockup__text" aria-hidden="true">
            <span class="brand-lockup__name">BETHLEHEM</span>
            <span class="brand-lockup__service">ANIMAL CLINIC</span>
          </span>
        </div>
        <p class="confirmation-print-heading">${escapeHtml(printTitle)}</p>
      </header>
      ${showOperational ? `<div class="confirmation-operational">
        ${walkIn ? `<dl class="confirmation-queue"><dt>Queue number</dt><dd>${escapeHtml(queueNumber ?? "Not assigned")}</dd></dl>` : ""}
        <dl class="confirmation-reference"><dt>Registration reference</dt><dd>${escapeHtml(reference || "Not available")}</dd></dl>
        <span id="confirmationOutcomeStatus" class="confirmation-badge${walkIn ? " confirmation-badge--checked-in" : ""}" role="status">${escapeHtml(status)}</span>
      </div>` : ""}
      ${message ? `<p id="confirmationOutcomeMessage" class="confirmation-message">${escapeHtml(message)}</p>` : ""}
      ${warning ? `<p class="confirmation-warning" role="status">${escapeHtml(warning)}</p>` : ""}
      ${sections}
    </article>`;
}

export function renderConfirmation(root, options) {
  const { title, primaryHref, primaryLabel } = options;
  root.innerHTML = `
    <header class="confirmation-success">
      <span class="confirmation-success-mark" aria-hidden="true">✓</span>
      <h1 id="confirmationHeading" tabindex="-1" aria-describedby="confirmationOutcomeStatus confirmationOutcomeMessage">${escapeHtml(title)}</h1>
    </header>
    ${confirmationDocument(options)}
    <div class="confirmation-actions">
      <a class="confirmation-button confirmation-button--primary" href="${escapeHtml(primaryHref)}">${escapeHtml(primaryLabel)}</a>
      <button class="confirmation-button" id="printConfirmationButton" type="button">Print Confirmation</button>
    </div>`;

  document.body.classList.add("confirmation-ready");
  document.title = title;
  root.querySelector("#confirmationHeading").focus({ preventScroll: true });
  root.querySelector("#printConfirmationButton").addEventListener("click", () => printConfirmation());
}

export function printConfirmation() {
  const originalTitle = document.title;
  const restoreTitle = () => {
    document.title = originalTitle;
    window.removeEventListener("afterprint", restoreTitle);
  };
  // Retain the existing Grooming behavior for browser-generated print headers.
  document.title = "\u200B";
  window.addEventListener("afterprint", restoreTitle);
  window.print();
  window.setTimeout(restoreTitle, 1000);
}
