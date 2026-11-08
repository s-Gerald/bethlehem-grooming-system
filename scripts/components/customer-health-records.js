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

function clinicPrescriptions(record) {
  return (Array.isArray(record.prescriptions) ? record.prescriptions : [])
    .filter((item) => item && hasHealthValue(item.medicine))
    .map((item) => ({
      name: [item.medicine, item.strength_concentration].filter(hasHealthValue).join(" · "),
      details: [
        ["Dose", item.dose], ["Route", item.route], ["Frequency", item.frequency],
        ["Duration", item.duration], ["Special instructions", item.instructions, { wide: true }],
      ],
    }));
}

// Both owner views use the same sections and conditional fields.
export function renderClinicVisitSections(record, escapeHtml, { compact = false } = {}) {
  const weight = record.vitals?.weight_kg;
  const hasWeight = hasHealthValue(weight) && /^[+]?\d+(?:\.\d*)?$/.test(String(weight).trim())
    && Number.isFinite(Number(weight)) && Number(weight) > 0;
  const recordedDate = (value) => hasHealthValue(value) ? healthDate(value) : "";
  const vaccines = (Array.isArray(record.vaccinations) ? record.vaccinations : [])
    .filter((item) => hasHealthValue(item?.vaccine_name));
  const medicines = (Array.isArray(record.administered_medicines) ? record.administered_medicines : [])
    .filter((item) => hasHealthValue(item?.medicine_name))
    .map((item) => {
      const dose = hasHealthValue(item.dose_amount) && Number.isFinite(Number(item.dose_amount))
        ? [Number(item.dose_amount), item.dose_unit].filter(hasHealthValue).join(" ") : "";
      const route = hasHealthValue(item.route)
        ? String(item.route).replace(/^./, (letter) => letter.toUpperCase()) : "";
      return { name: item.medicine_name, details: [["Dose", dose], ["Route", route]] };
    });
  const sectionHeading = compact ? "h5" : "h4";
  const groupHeading = compact ? "h6" : "h5";
  const valueClasses = compact
    ? "whitespace-pre-line break-words text-xs leading-5 text-portal-text"
    : "whitespace-pre-line break-words text-sm leading-6 text-portal-text";
  const renderFields = (fields) => {
    const visible = fields.filter(([, value]) => hasHealthValue(value));
    return visible.length ? `
      <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
        ${visible.map(([label, value, { wide, emphasize } = {}]) => `
          <div class="min-w-0 ${wide ? "sm:col-span-2" : ""}">
            <dt class="break-words text-xs font-medium text-portal-muted [overflow-wrap:anywhere]">${escapeHtml(label)}</dt>
            <dd class="${valueClasses} [overflow-wrap:anywhere]${emphasize ? " font-semibold" : ""}">${escapeHtml(value)}</dd>
          </div>`).join("")}
      </dl>` : "";
  };
  const renderGroup = (title, entries) => entries.length ? `
    <${groupHeading} class="mt-4 text-xs font-semibold text-portal-muted">${title}</${groupHeading}>
    <ul class="mt-2 space-y-3">
      ${entries.map(({ name, details }) => `
        <li class="min-w-0 border-l-2 border-portal-border pl-3 [overflow-wrap:anywhere]">
          <p class="${compact ? "text-xs" : "text-sm"} font-semibold text-portal-text">${escapeHtml(name)}</p>
          ${renderFields(details)}
        </li>`).join("")}
    </ul>` : "";

  return [
    ["Next steps", renderFields([
      ["Follow-up date", recordedDate(record.follow_up_date), { emphasize: true }],
      ...vaccines.map((item) => [`Next vaccination date · ${item.vaccine_name}`, recordedDate(item.next_due_date), { emphasize: true }]),
      ["Follow-up instructions", record.follow_up_notes, { wide: true }],
    ])],
    ["Visit overview", renderFields([
      ["Reason for visit", record.chief_complaint, { wide: true }],
      ["Attending veterinarian", record.primary_veterinarian_name],
      [record.diagnosis_source === "working_diagnosis" ? "Working diagnosis" : "Diagnosis", record.diagnosis],
      ["Visit weight", hasWeight ? `${formatSavedWeight(weight)} kg` : ""],
    ])],
    ["Care provided", renderFields([["Recorded treatment", record.treatment_given, { wide: true }]])
      + renderGroup("Vaccinations administered", vaccines.map((item) => ({
        name: item.vaccine_name,
        details: [
          ["Administration date", recordedDate(item.administered_date)],
          ["Next vaccination date", recordedDate(item.next_due_date)],
        ],
      })))
      + renderGroup("Medicines administered", medicines)
      + renderGroup("Prescribed medications", clinicPrescriptions(record))],
  ].map(([title, content]) => content ? `
    <section class="mt-4 border-t border-portal-border pt-4">
      <${sectionHeading} class="text-sm font-semibold text-portal-text">${title}</${sectionHeading}>
      ${content}
    </section>` : "").join("");
}
