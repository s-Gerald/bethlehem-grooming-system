import { escapeHtml } from "../services/booking-draft-service.js";

export function renderEstimateReview(estimate) {
  if (!estimate) return "";
  return `<div class="grooming-estimate-selection">
    ${estimate.preferenceLabel ? `<p>Grooming preference: <strong>${escapeHtml(estimate.preferenceLabel)}</strong></p>` : ""}
    <p class="grooming-estimate-time">Estimated grooming time <strong>${escapeHtml(estimate.formatted)}</strong></p>
  </div>`;
}
