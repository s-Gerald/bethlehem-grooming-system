// Shared read-only Clinic presentation. Static templates inherit their Alpine controller.
function formatClinicFileSize(bytes) {
  const size = Number(bytes);
  if (!Number.isFinite(size) || size < 0) return "Size unavailable";
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

const VACCINATION_ADMINISTRATION_ROUTES = [
  { value: "subcutaneous", label: "Subcutaneous" },
  { value: "intramuscular", label: "Intramuscular" },
  { value: "intranasal", label: "Intranasal" },
  { value: "oral", label: "Oral" },
  { value: "other", label: "Other" },
];

function clinicTreatmentSummary(record) {
  const dose = record?.dose_amount != null && record.dose_amount !== ''
    ? `${Number(record.dose_amount)}${record.dose_unit ? ' ' + record.dose_unit : ''}` : '';
  const route = ({intramuscular: 'IM', subcutaneous: 'SC', intranasal: 'Intranasal', oral: 'Oral', other: 'Other'})[record?.route] || record?.route;
  return [dose, route].filter(Boolean).join(' · ') || '—';
}

function clinicTreatmentDetails(record) {
  if (!record) return [];
  const date = value => value ? new Date(String(value).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-PH', {dateStyle: 'medium'}) : '—';
  return [
    {label: 'Treatment type', value: record.treatment_type === 'medicine' ? 'Medicine' : 'Vaccine'},
    {label: 'Treatment', value: record.treatment_name || record.vaccine_name},
    {label: 'Product', value: record.product_name || record.inventory_item_name},
    {label: 'Source', value: record.inventory_item_id ? 'Bethlehem Inventory' : 'Historical / external'},
    {label: 'Status', value: ({draft: 'Draft', published: 'Published', voided: 'Voided'})[record.state] || record.state},
    {label: 'Manufacturer', value: record.manufacturer},
    {label: 'Batch / Lot', value: record.batch_number},
    {label: 'Expiry', value: date(record.product_expiry_date)},
    {label: 'Quantity', value: record.inventory_quantity == null ? '—' : `${Number(record.inventory_quantity)} ${record.inventory_unit || ''}`.trim()},
    {label: 'Strength / concentration', value: record.strength_concentration},
    {label: 'Dose', value: record.dose_amount == null ? '—' : `${Number(record.dose_amount)} ${record.dose_unit || ''}`.trim()},
    {label: 'Route', value: VACCINATION_ADMINISTRATION_ROUTES.find(route => route.value === record.route)?.label || record.route},
    {label: 'Administration Site', value: record.administration_site},
    {label: 'Administration Date', value: date(record.administered_date)},
    {label: 'Next Due Date', value: date(record.next_due_date)},
    {label: 'Notes', value: record.notes},
    {label: 'Case', value: record.clinic_appointment_reference},
    {label: 'Recorded By', value: record.recorded_by_user_name},
    ...(record.published_at ? [{label: 'Published', value: date(record.published_at)}, {label: 'Published By', value: record.published_by_user_name}] : []),
    ...(record.voided_at ? [{label: 'Voided', value: date(record.voided_at)}, {label: 'Voided By', value: record.voided_by_user_name}, {label: 'Void Reason', value: record.void_reason}] : []),
  ];
}

function clinicConfinementDuration(admittedAt, end = Date.now()) {
  if (!admittedAt) return '—';
  const minutes = Math.max(0, Math.floor((new Date(end).getTime() - new Date(admittedAt).getTime()) / 60000));
  const days = Math.floor(minutes / 1440), hours = Math.floor(minutes % 1440 / 60);
  return `${days ? days + 'd ' : ''}${hours}h ${minutes % 60}m`;
}


function clinicHistoryService(record) { return record?.service_snapshot || record?.clinic_service || {}; }
function clinicHistoryCaseId(record) { return record?.appointment_id || record?.clinic_appointment_id || record?.appointment?.id || null; }
function clinicHistoryPrice(value) { return value == null ? '—' : Number(value).toLocaleString('en-PH', {style: 'currency', currency: 'PHP'}); }
function clinicHistoryTime(value) { return value ? new Date(value).toLocaleString('en-PH', {dateStyle: 'medium', timeStyle: 'short'}) : '—'; }
function clinicHistoryDate(value) {
  if (!value) return '—';
  try { return new Date(value).toLocaleDateString('en-PH', {year: 'numeric', month: 'short', day: 'numeric'}); }
  catch { return value; }
}

function clinicConfinementFields(record, medical, now = Date.now()) {
  if (!record) return [];
  return [
    {label: 'Confinement', value: clinicHistoryService(record.case_service).name},
    {label: 'Status', value: record.discharged_at ? 'Discharged' : 'Confined'},
    {label: 'Admitted', value: clinicHistoryTime(record.admitted_at)},
    ...(record.discharged_at ? [{label: 'Discharged', value: clinicHistoryTime(record.discharged_at)}] : []),
    {label: 'Duration', value: clinicConfinementDuration(record.admitted_at, record.discharged_at || now)},
    ...(record.attending_veterinarian_name && record.attending_veterinarian_name !== medical?.primary_veterinarian_name
      ? [{label: 'Attending Veterinarian', value: record.attending_veterinarian_name}] : []),
    {label: 'Reason', value: record.reason}, {label: 'Notes', value: record.notes},
    {label: 'Discharge Note', value: record.discharge_note},
  ].filter(field => field.value != null && field.value !== '');
}

if (typeof customElements !== 'undefined' && !customElements.get('clinic-record-sections')) {
  customElements.define('clinic-record-sections', class extends HTMLElement {
    connectedCallback() {
      if (this.childElementCount) return;
      this.classList.add('block', 'case-detail-sections');
      this.setAttribute('x-data', '{compactClinicalHistory: ' + this.hasAttribute('compact') + '}');
      this.innerHTML = `
        <template x-if="detail.medical"><section data-case-section="medical" tabindex="-1" aria-labelledby="case-medical-heading" class="case-detail-section space-y-6">
          <h3 id="case-medical-heading" tabindex="-1" class="text-sm font-bold text-[#2f4b66]">Medical Record</h3>
          <div x-show="compactClinicalHistory && detail.medical?.primary_veterinarian_name"><p class="text-xs font-medium text-slate-500">Primary Attending Veterinarian</p><p class="mt-1 text-sm text-slate-700" x-text="detail.medical?.primary_veterinarian_name"></p></div>
          <div x-show="detail.medical?.additional_veterinarians?.length">
            <p class="text-xs font-medium text-slate-500">Additional Veterinarians</p>
            <p class="mt-1 text-sm text-slate-700" x-text="(detail.medical?.additional_veterinarians || []).map(vet => vet.name).join(', ')"></p>
          </div>
          <template x-if="detail.medical?.vitals && (!compactClinicalHistory || Object.values(detail.medical.vitals).some(value => value != null && value !== ''))">
            <div>
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Vitals</p>
              <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-center">
                  <p class="text-xs text-slate-400">Temp</p>
                  <p class="mt-0.5 font-semibold text-slate-700" x-text="detail.medical.vitals?.temperature_c != null ? detail.medical.vitals.temperature_c + ' °C' : '—'"></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-center">
                  <p class="text-xs text-slate-400">HR</p>
                  <p class="mt-0.5 font-semibold text-slate-700" x-text="detail.medical.vitals?.heart_rate_bpm != null ? detail.medical.vitals.heart_rate_bpm + ' bpm' : '—'"></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-center">
                  <p class="text-xs text-slate-400">RR</p>
                  <p class="mt-0.5 font-semibold text-slate-700" x-text="detail.medical.vitals?.respiratory_rate_bpm != null ? detail.medical.vitals.respiratory_rate_bpm + ' breaths/min' : '—'"></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-center">
                  <p class="text-xs text-slate-400">Weight</p>
                  <p class="mt-0.5 font-semibold text-slate-700" x-text="detail.medical.vitals?.weight_kg ? detail.medical.vitals.weight_kg + ' kg' : '—'"></p>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-center">
                  <p class="text-xs text-slate-400">BCS</p>
                  <p class="mt-0.5 font-semibold text-slate-700" x-text="detail.medical.vitals?.body_condition_score ? detail.medical.vitals.body_condition_score + ' / 9' : '—'"></p>
                </div>
              </div>
            </div>
          </template>
          <section x-show="!compactClinicalHistory || (detail.medical?.chief_complaint || detail.medical?.relevant_history || detail.medical?.reported_signs)" class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">History</h3>
            <div x-show="!compactClinicalHistory || detail.medical?.chief_complaint">
              <p class="text-xs font-medium text-slate-500">Chief Complaint</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.chief_complaint) || '—'"></p>
            </div>
            <div x-show="!compactClinicalHistory || detail.medical?.relevant_history">
              <p class="text-xs font-medium text-slate-500">Relevant Patient History</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.relevant_history) || '—'"></p>
            </div>
            <div x-show="!compactClinicalHistory || detail.medical?.reported_signs">
              <p class="text-xs font-medium text-slate-500">Reported Signs</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.reported_signs) || '—'"></p>
            </div>
          </section>
          <section x-show="!compactClinicalHistory || (detail.medical?.physical_exam_findings ?? detail.medical?.findings)" class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Objective</h3>
            <div x-show="!compactClinicalHistory || (detail.medical?.physical_exam_findings ?? detail.medical?.findings)">
              <p class="text-xs font-medium text-slate-500">Physical Exam Findings</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.physical_exam_findings ?? detail.medical?.findings) || '—'"></p>
            </div>
          </section>
          <section x-show="!compactClinicalHistory || (detail.medical?.differential_diagnoses || detail.medical?.working_diagnosis || detail.medical?.diagnosis)" class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Assessment</h3>
            <div x-show="!compactClinicalHistory || detail.medical?.differential_diagnoses">
              <p class="text-xs font-medium text-slate-500">Differential Diagnoses</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.differential_diagnoses) || '—'"></p>
            </div>
            <div x-show="!compactClinicalHistory || (detail.medical?.working_diagnosis ?? detail.medical?.diagnosis)">
              <p class="text-xs font-medium text-slate-500">Working Diagnosis</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.working_diagnosis ?? detail.medical?.diagnosis) || '—'"></p>
            </div>
          </section>
          <section x-show="!compactClinicalHistory || (detail.medical?.diagnostics?.length || detail.medical?.treatment_given || detail.medical?.prescriptions?.length || detail.medical?.follow_up_date || detail.medical?.follow_up_notes || detail.medical?.vet_notes || detail.medical?.attachments?.length)" class="space-y-4">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Plan</h3>
            <div x-show="detail.medical?.diagnostics?.length">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">
                Diagnostics
                &amp;
                Tests
              </h4>
              <template x-for="row in (detail.medical?.diagnostics || [])" :key="row.key">
                <div class="mb-2 space-y-1 rounded-xl border border-slate-200 p-3 text-sm text-slate-700">
                  <p class="font-semibold" x-text="(row.diagnostic === 'Other' ? row.custom_name : row.diagnostic) + ' · ' + row.status"></p>
                  <p x-show="row.notes" class="whitespace-pre-wrap" x-text="row.notes"></p>
                  <p x-show="row.result_reference" class="break-words" x-text="row.result_reference"></p>
                  <p x-show="!row.result_attachment_id &amp;&amp; !row.result_reference &amp;&amp; !row.notes" class="text-xs text-slate-500">Result pending</p>
                  <template x-if="(detail.medical?.attachments || []).find(file => Number(file.id) === Number(row.result_attachment_id))">
                    <button type="button" @click="downloadDetailAttachment(detail.medical.attachments.find(file => Number(file.id) === Number(row.result_attachment_id)))" :disabled="detailAttachmentBusyId === Number(row.result_attachment_id)" class="rounded-lg bg-[#eef5fb] px-3 py-2 text-xs font-semibold text-[#315b7e]">
                      View Result
                    </button>
                  </template>
                </div>
              </template>
            </div>
            <div x-show="!compactClinicalHistory || detail.medical?.treatment_given">
              <p class="text-xs font-medium text-slate-500">Treatment Given</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.treatment_given) || '—'"></p>
            </div>
            <div x-show="detail.medical?.prescriptions?.length">
              <h4 class="mb-2 text-xs font-semibold text-slate-500">Prescriptions</h4>
              <template x-for="(row, index) in (detail.medical?.prescriptions || [])" :key="index">
                <dl class="mb-2 grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-2">
                  <div>
                    <dt class="text-xs text-slate-500">Medicine</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.medicine || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Strength / Concentration</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.strength_concentration || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Dose</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.dose || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Route</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.route ? row.route[0].toUpperCase() + row.route.slice(1) : '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Frequency</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.frequency || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Duration</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.duration || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Quantity</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.quantity || '—'"> </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-slate-500">Instructions</dt>
                    <dd class="break-words whitespace-pre-wrap text-sm text-slate-700" x-text="row.instructions || '—'"> </dd>
                  </div>
                </dl>
              </template>
            </div>
            <!-- Follow-up -->
            <template x-if="(detail.medical?.follow_up_date || detail.medical?.follow_up_notes)">
              <div class="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3">
                <p class="text-xs font-semibold text-blue-700">Follow-up</p>
                <p x-show="!compactClinicalHistory || detail.medical.follow_up_date" class="mt-0.5 text-sm font-medium text-blue-900" x-text="fmtDate(detail.medical.follow_up_date)"></p>
                <p x-show="detail.medical.follow_up_notes" class="mt-0.5 text-xs text-blue-700" x-text="detail.medical.follow_up_notes"></p>
              </div>
            </template>
            <div x-show="!compactClinicalHistory || detail.medical?.vet_notes">
              <p class="text-xs font-medium text-slate-500">Additional Vet Notes</p>
              <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700" x-text="(detail.medical?.vet_notes) || '—'"></p>
            </div>
            <!-- Attachments -->
            <div x-show="!compactClinicalHistory || (detail.medical?.attachments || []).some(file => !(detail.medical?.diagnostics || []).some(row => Number(row.result_attachment_id) === Number(file.id)))">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Attachments</p>
              <div class="space-y-2">
                <template x-if="!(detail.medical?.attachments || []).filter(file => !(detail.medical?.diagnostics || []).some(row => Number(row.result_attachment_id) === Number(file.id))).length">
                  <p class="text-sm text-slate-400">—</p>
                </template>
                <template x-for="attachment in (detail.medical?.attachments || []).filter(file => !(detail.medical?.diagnostics || []).some(row => Number(row.result_attachment_id) === Number(file.id)))" :key="attachment.id">
                  <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                      <p class="truncate text-sm font-semibold text-[#2f4b66]" x-text="attachment.label || attachment.file_name"></p>
                      <p class="mt-0.5 truncate text-xs text-slate-400">
                        <span x-text="attachment.file_name">
                        </span>
                        ·
                        <span x-text="formatFileSize(attachment.file_size_bytes)">
                        </span>
                      </p>
                    </div>
                    <button type="button" @click="downloadDetailAttachment(attachment)" :disabled="detailAttachmentBusyId === attachment.id"
                        class="shrink-0 rounded-xl bg-[#eef5fb] px-3 py-2 text-xs font-semibold text-[#315b7e] transition hover:bg-[#dceaf5] disabled:opacity-50">
                      Download
                    </button>
                  </div>
                </template>
              </div>
              <p x-show="detailAttachmentError" class="mt-2 text-sm text-red-600" x-text="detailAttachmentError"></p>
            </div>
          </section>
        </section></template>
              <template x-if="detail.treatments?.length"><section data-case-section="treatment" tabindex="-1" aria-labelledby="case-treatment-heading" class="case-detail-section">
                <h3 id="case-treatment-heading" tabindex="-1" class="mb-4 text-sm font-bold text-[#2f4b66]">Treatment</h3>
                <div class="divide-y divide-slate-100">
                  <template x-for="rec in detail.treatments" :key="rec.id"><article :data-case-entry="'treatment-' + rec.id" tabindex="-1" :aria-label="rec.treatment_name || rec.vaccine_name || 'Treatment'" class="case-detail-entry py-4 first:pt-0 last:pb-0">
                    <h4 tabindex="-1" class="mb-3 text-sm font-semibold text-slate-700" x-text="rec.treatment_name || rec.vaccine_name || 'Treatment'"></h4>
                    <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3"><template x-for="field in caseTreatmentFields(rec)" :key="field.label"><div class="min-w-0"><dt class="text-xs text-slate-500" x-text="field.label"></dt><dd class="mt-1 whitespace-pre-wrap break-words font-medium text-slate-700" x-text="field.value ?? '—'"></dd></div></template></dl>
                  </article></template>
                </div>
              </section></template>
              <template x-if="detail.services?.length"><section data-case-section="services" tabindex="-1" aria-labelledby="case-services-heading" class="case-detail-section">
                <h3 id="case-services-heading" tabindex="-1" class="mb-4 text-sm font-bold text-[#2f4b66]">Services</h3>
                <div class="overflow-x-auto rounded-2xl border border-slate-200" role="region" aria-label="Case services" tabindex="0"><table class="w-full min-w-[480px] text-left text-sm">
                  <caption class="sr-only">Services recorded for this case</caption>
                  <thead class="border-b border-slate-100 bg-slate-50 text-xs font-semibold text-slate-500"><tr><th scope="col" class="px-4 py-3">Service</th><th scope="col" class="px-4 py-3">Notes</th><th scope="col" class="px-4 py-3 text-right">Price</th></tr></thead>
                  <tbody class="divide-y divide-slate-100"><template x-for="rec in detail.services" :key="rec.id"><tr :data-case-entry="'services-' + rec.id" tabindex="-1" :aria-label="historyService(rec).name || 'Service'"><td class="px-4 py-3 text-slate-700" x-text="historyService(rec).name || '—'"></td><td class="whitespace-pre-wrap break-words px-4 py-3 text-slate-600" x-text="rec.notes?.trim() ? rec.notes : '—'"></td><td class="whitespace-nowrap px-4 py-3 text-right text-slate-700" x-text="historyPrice(rec.applied_price)"></td></tr></template></tbody>
                </table></div>
              </section></template>
              <template x-if="detail.confinement"><section data-case-section="confinement" :data-case-entry="'confinement-' + detail.confinement.id" tabindex="-1" aria-labelledby="case-confinement-heading" class="case-detail-section">
                <h3 x-show="!compactClinicalHistory" id="case-confinement-heading" tabindex="-1" class="mb-4 text-sm font-bold text-[#2f4b66]">Confinement</h3>
                <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3"><template x-for="field in confinementDetailFields(detail.confinement)" :key="field.label"><div class="min-w-0"><dt class="text-xs text-slate-500" x-text="field.label"></dt><dd class="mt-1 whitespace-pre-wrap break-words font-medium text-slate-700" x-text="field.value"></dd></div></template></dl>
                <div class="mt-5">
                  <admin-loader x-cloak x-show="detail.observationsLoading"></admin-loader>
                  <div x-show="detail.observationsError" role="alert" class="mb-3 text-sm text-red-600"><span x-text="detail.observationsError"></span><button type="button" @click="loadHistoryObservations(detail.page + 1)" class="rounded-lg px-2 py-1 font-semibold">Retry</button></div>
                  <div x-show="detail.observations?.length">
                    <h4 class="mb-3 text-sm font-semibold text-slate-700">Monitoring</h4>
            <div class="overflow-x-auto rounded-2xl border border-slate-200" role="region" aria-label="Historical confinement monitoring" tabindex="0"><table class="w-full min-w-[660px] text-left text-sm">
              <caption class="sr-only">Saved confinement observations</caption><thead class="border-b border-slate-100 bg-slate-50 text-xs font-semibold text-slate-500"><tr><th scope="col" class="px-4 py-3">Recorded</th><th scope="col" class="px-4 py-3">Temp °C</th><th scope="col" class="px-4 py-3">Heart bpm</th><th scope="col" class="px-4 py-3">Resp. bpm</th><th scope="col" class="px-4 py-3">Observation</th><th scope="col" class="px-4 py-3">Recorded by</th></tr></thead>
              <tbody class="divide-y divide-slate-100"><template x-for="entry in (detail.observations || [])" :key="entry.id"><tr><td class="px-4 py-3" x-text="historyTime(entry.recorded_at)"></td><td class="px-4 py-3" x-text="entry.temperature_c ?? '—'"></td><td class="px-4 py-3" x-text="entry.heart_rate_bpm ?? '—'"></td><td class="px-4 py-3" x-text="entry.respiratory_rate_bpm ?? '—'"></td><td class="max-w-xs whitespace-pre-wrap break-words px-4 py-3" x-text="entry.notes || '—'"></td><td class="px-4 py-3" x-text="'Staff #' + entry.recorded_by_user_id"></td></tr></template></tbody>
            </table></div>
            <button x-show="detail.page < detail.lastPage" type="button" @click="loadHistoryObservations(detail.page + 1)" :disabled="detail.observationsLoading" class="mt-3 rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 disabled:opacity-50">Load more observations</button>
                  </div>
                </div>
              </section></template>`;
    }
  });
}
