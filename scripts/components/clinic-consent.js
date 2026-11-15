import { createSignaturePad } from "./signature-pad.js";

export function mountClinicConsent({ host, storageKey, scope = "", staff = false, signerName = "", onChange }) {
  host.innerHTML = `
    <div id="clinicAgreementLoadStatus" class="mb-4 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700" tabindex="-1" hidden>
      <p data-agreement-notice role="status"></p>
      <button data-agreement-retry type="button" class="mt-2 px-2 py-2 text-sm font-semibold text-[#315b7e] underline" hidden>Retry</button>
    </div>
    <div data-agreement-fields class="space-y-6" hidden>
      <section class="rounded-2xl border border-[#91aeca] bg-white p-4 sm:p-6" aria-labelledby="clinicAgreementTitle">
        <div class="mx-auto max-w-[65ch]">
          <h3 id="clinicAgreementTitle" tabindex="-1" class="mb-4 text-lg font-semibold text-[#2f4b66]"></h3>
          <div data-agreement-clauses class="space-y-4"></div>
          <label class="mt-6 flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
            <input id="clinicConsentCheckbox" type="checkbox" required aria-describedby="clinicAcknowledgmentError"
              class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" />
            <span class="text-sm leading-6 text-slate-700"><span data-agreement-acknowledgment></span> <span class="text-red-600" aria-hidden="true">*</span></span>
          </label>
          <p id="clinicAcknowledgmentError" role="alert" class="mt-2 text-sm text-red-600" hidden></p>
        </div>
      </section>
      <section class="rounded-2xl border border-[#91aeca] bg-white p-4 sm:p-6" aria-labelledby="clinicSignatureTitle">
        <div class="mx-auto max-w-[65ch]">
          <h3 id="clinicSignatureTitle" class="mb-3 text-lg font-semibold text-[#2f4b66]">Customer Signature <span class="text-red-600" aria-hidden="true">*</span></h3>
          <canvas id="clinicSignaturePad" tabindex="0" role="img" aria-labelledby="clinicSignatureTitle"
            aria-describedby="clinicSignatureHelp clinicSignatureError" class="block w-full rounded-xl border border-slate-300 bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#315b7e]"
            style="aspect-ratio:5/2; max-width:560px; height:auto"></canvas>
          <p id="clinicSignatureHelp" class="mt-2 text-sm text-slate-600">Draw your signature with your finger, mouse, or stylus.</p>
          <button data-clear-signature type="button" class="mt-1 py-2 text-sm font-medium text-[#315b7e] underline">Clear signature</button>
          <p id="clinicSignatureError" role="alert" class="mt-2 text-sm text-red-600" hidden></p>
          <label for="clinicSignerName" class="mb-2 mt-4 block text-sm font-medium text-slate-700">Signer full name <span class="text-red-600" aria-hidden="true">*</span></label>
          <input id="clinicSignerName" type="text" required maxlength="200" autocomplete="name" aria-describedby="clinicSignerError"
            class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20" />
          <p id="clinicSignerError" role="alert" class="mt-2 text-sm text-red-600" hidden></p>
        </div>
      </section>
    </div>`;

  const query = selector => host.querySelector(selector);
  const checkbox = query('#clinicConsentCheckbox');
  const name = query('#clinicSignerName');
  const canvas = query('#clinicSignaturePad');
  const status = query('#clinicAgreementLoadStatus');
  const notice = query('[data-agreement-notice]');
  const retry = query('[data-agreement-retry]');
  const fields = query('[data-agreement-fields]');
  const touched = new Set();
  const serverErrors = new Map();
  let review;
  let loading = false;
  let updatedMessage = '';
  const pad = createSignaturePad(canvas, () => {
    if (!pad.drawing) change('signature_strokes');
    else onChange();
  });

  const checks = [
    ['terms_agreed', checkbox, query('#clinicAcknowledgmentError'), () => checkbox.checked, 'Agree to the Clinic Care Agreement.'],
    ['signature_strokes', canvas, query('#clinicSignatureError'), () => pad.drawn, 'Please draw your signature.'],
    ['signer_name', name, query('#clinicSignerError'), () => !!name.value.trim() && name.value.trim().length <= 200, 'Enter the signer’s full name.'],
  ];

  function valid() {
    return !!review && !pad.drawing && !serverErrors.size && checks.every(([, , , check]) => check());
  }

  function validate(showAll = false) {
    let firstInvalid;
    for (const [field, input, error, check, message] of checks) {
      const invalid = !check() || serverErrors.has(field);
      if (showAll && invalid) touched.add(field);
      const visible = invalid && touched.has(field);
      const text = visible ? serverErrors.get(field) || message : '';
      if (error.textContent !== text) error.textContent = text;
      error.hidden = !visible;
      input.setAttribute('aria-invalid', String(visible));
      if (visible) firstInvalid ??= input;
    }
    if (showAll) firstInvalid?.focus();
    return valid();
  }

  function payload() {
    return {
      terms_agreed: checkbox.checked,
      signer_name: name.value.trim(),
      signature_strokes: pad.strokes,
      consent_version: review?.agreement.version,
      agreement_hash: review?.agreement_hash,
    };
  }

  function clearDraft() {
    try { sessionStorage.removeItem(storageKey); }
    catch { /* The form can still be completed without a saved draft. */ }
  }

  function change(field) {
    touched.add(field);
    serverErrors.delete(field);
    validate();
    if (review && !pad.drawing) {
      try { sessionStorage.setItem(storageKey, JSON.stringify({ ...payload(), scope })); }
      catch { /* The current page still holds the consent if browser storage is full. */ }
    }
    onChange();
  }

  async function load() {
    if (loading) return;
    loading = true;
    fields.hidden = true;
    status.hidden = false;
    retry.hidden = true;
    notice.textContent = 'Loading Clinic Care Agreement…';
    try {
      const result = await API.getClinicCareAgreement({ staff });
      if (!host.isConnected) return;
      const { agreement, agreement_hash: hash } = result;
      if (!agreement?.version || !agreement.clauses?.length || !hash) throw new Error('Agreement unavailable');
      query('#clinicAgreementTitle').textContent = agreement.title;
      query('[data-agreement-acknowledgment]').textContent = agreement.acknowledgment;
      query('[data-agreement-clauses]').replaceChildren(...agreement.clauses.map(({ heading, text }) => {
        const clause = document.createElement('div');
        const title = document.createElement('h4');
        title.className = 'text-sm font-semibold text-[#2f4b66]';
        title.textContent = heading;
        const body = document.createElement('p');
        body.className = 'mt-1 text-base leading-7 text-slate-700';
        body.textContent = text;
        clause.append(title, body);
        return clause;
      }));

      let saved;
      try { saved = JSON.parse(sessionStorage.getItem(storageKey)); }
      catch { clearDraft(); }
      if (saved?.consent_version === agreement.version && saved.agreement_hash === hash && saved.scope === scope) {
        checkbox.checked = saved.terms_agreed === true;
        name.value = saved.signer_name ?? signerName;
        pad.restore(saved.signature_strokes);
      } else if (saved) {
        clearDraft();
        if (saved.scope === scope) updatedMessage = 'The Clinic Care Agreement has changed. Review and sign the updated agreement.';
      }
      review = result;
      notice.textContent = updatedMessage;
      status.hidden = !updatedMessage;
      fields.hidden = false;
      validate();
    } catch {
      notice.textContent = 'The Clinic Care Agreement could not be loaded. Please try again.';
      retry.hidden = false;
    } finally {
      loading = false;
      if (host.isConnected) onChange();
    }
  }

  name.value = signerName;
  checkbox.addEventListener('change', () => change('terms_agreed'));
  name.addEventListener('input', () => change('signer_name'));
  name.addEventListener('blur', () => {
    touched.add('signer_name');
    validate();
  });
  query('[data-clear-signature]').addEventListener('click', () => pad.clear());
  retry.addEventListener('click', async () => {
    await load();
    if (host.isConnected) (review ? query('#clinicAgreementTitle') : retry).focus();
  });

  return {
    ready: load(),
    valid,
    validate: () => validate(true),
    payload,
    clearDraft,
    prefill(value) {
      signerName = value;
      if (!name.value && !touched.has('signer_name')) name.value = value;
      onChange();
    },
    async handleError(error) {
      if (error.code === 'clinic_agreement_changed') {
        updatedMessage = error.message || 'The Clinic Care Agreement has changed. Review and sign the updated agreement.';
        review = undefined;
        clearDraft();
        checkbox.checked = false;
        pad.clear();
        touched.clear();
        serverErrors.clear();
        onChange();
        const refreshed = load();
        status.focus();
        await refreshed;
        return true;
      }

      for (const [path, messages] of Object.entries(error.errors ?? {})) {
        const field = path.split('.')[0];
        if (checks.some(([key]) => key === field) && !serverErrors.has(field)) {
          serverErrors.set(field, [].concat(messages)[0]);
          touched.add(field);
        }
      }
      if (!serverErrors.size) return false;
      validate(true);
      onChange();
      return true;
    },
  };
}
