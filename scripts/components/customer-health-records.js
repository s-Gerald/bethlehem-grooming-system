import { formatSavedWeight } from "../services/booking-format-service.js?v=pet-weight-format-20261019";

// Owner-facing fields from the clinic record. Internal clinical data is never rendered.
export const hasHealthValue = (value) => (typeof value === "string" && value.trim() !== "")
  || (typeof value === "number" && Number.isFinite(value));

export function healthDate(value) {
  if (!hasHealthValue(value)) return "Not recorded";
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString("en-PH", {
    year: "numeric", month: "long", day: "numeric",
  });
}

function clinicMedications(record) {
  return (Array.isArray(record.prescriptions) ? record.prescriptions : [])
    .filter((item) => item && hasHealthValue(item.medicine))
    .map((item) => ({
      name: [item.medicine, item.strength_concentration].filter(hasHealthValue).join(" · "),
      details: [
        ["Dose", item.dose], ["Route", item.route], ["Frequency", item.frequency],
        ["Duration", item.duration], ["Special instructions", item.instructions],
      ].filter(([, value]) => hasHealthValue(value)),
    }));
}

export function clinicVisitFields(record) {
  const medications = clinicMedications(record).map(({ name, details }) =>
    [name, ...details.map(([label, value]) => `${label}: ${value}`)].join("\n")).join("\n\n");
  const weight = record.vitals?.weight_kg;
  const hasWeight = hasHealthValue(weight) && /^[+]?\d+(?:\.\d*)?$/.test(String(weight).trim())
    && Number.isFinite(Number(weight)) && Number(weight) > 0;

  return [
    ["Reason for visit", record.chief_complaint],
    ["Attending veterinarian", record.primary_veterinarian_name],
    ["Visit weight", hasWeight ? `${formatSavedWeight(weight)} kg` : ""],
    [record.diagnosis_source === "working_diagnosis" ? "Working diagnosis" : "Diagnosis", record.diagnosis],
    ["Care provided", record.treatment_given],
    ["Medications", medications],
    ["Follow-up date", hasHealthValue(record.follow_up_date) ? healthDate(record.follow_up_date) : ""],
    ["Follow-up instructions", record.follow_up_notes],
  ].filter(([, value]) => hasHealthValue(value));
}

// Both owner views use the same labels, conditional sections and medication formatting.
export function renderClinicVisitSections(record, escapeHtml, { compact = false } = {}) {
  const fields = clinicVisitFields(record);
  const medications = clinicMedications(record);
  const valueClasses = compact
    ? "whitespace-pre-line break-words text-xs leading-5 text-portal-text"
    : "whitespace-pre-line break-words text-sm leading-6 text-portal-text";
  const renderField = ([label, value]) => `
    <div class="min-w-0 ${["Reason for visit", "Working diagnosis", "Diagnosis", "Care provided", "Follow-up instructions", "Special instructions"].includes(label) ? "sm:col-span-2" : ""}">
      <dt class="text-xs font-medium text-portal-muted">${escapeHtml(label === "Care provided" ? "Recorded treatment" : label)}</dt>
      <dd class="mt-1 ${valueClasses} [overflow-wrap:anywhere]">${escapeHtml(value)}</dd>
    </div>`;
  return [
    ["Visit overview", ["Reason for visit", "Attending veterinarian", "Working diagnosis", "Diagnosis", "Visit weight"]],
    ["Care provided", ["Care provided"]],
    ["Next steps", ["Follow-up date", "Follow-up instructions"]],
  ].map(([title, labels]) => {
    const sectionFields = fields.filter(([label]) => labels.includes(label));
    const sectionMedications = title === "Care provided" ? medications : [];
    if (!sectionFields.length && !sectionMedications.length) return "";
    return `
      <section class="mt-5 border-t border-portal-border pt-5">
        <h4 class="text-sm font-semibold text-portal-text">${title}</h4>
        ${sectionFields.length ? `<dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">${sectionFields.map(renderField).join("")}</dl>` : ""}
        ${sectionMedications.length ? `
          <h5 class="mt-4 text-xs font-medium text-portal-muted">Prescribed medications</h5>
          <ul class="mt-2 space-y-4">
            ${sectionMedications.map(({ name, details }) => `
              <li class="min-w-0 border-l-2 border-portal-border pl-3 [overflow-wrap:anywhere]">
                <p class="whitespace-pre-line ${compact ? "text-xs" : "text-sm"} font-semibold text-portal-text">${escapeHtml(name)}</p>
                ${details.length ? `<dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">${details.map(renderField).join("")}</dl>` : ""}
              </li>`).join("")}
          </ul>` : ""}
      </section>`;
  }).join("");
}
