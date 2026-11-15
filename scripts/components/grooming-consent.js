import { createSignaturePad } from './signature-pad.js';

const termsUrl = new URL('../data/grooming-consent.json?v=20261017', import.meta.url);
let agreementRequest;

export function initGroomingConsent({ storageKey, signerName = '', staff = false, onChange = () => {} }) {
  const host = document.getElementById('groomingConsentFields');
  host.innerHTML = `
    <section class="rounded-2xl border border-[#91aeca] bg-white p-4 sm:p-6">
      <h3 class="mb-4 text-lg font-semibold text-[#2f4b66]">Grooming Agreement</h3>
      <div class="mx-auto max-w-[65ch]">
        <p data-consent-intro class="text-base leading-7 text-slate-700"></p>
        <ul data-consent-conditions class="mt-5 space-y-5 text-base leading-7 text-slate-700"></ul>
      </div>
      <label class="mt-4 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
        <input id="mainConsentCheckbox" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" aria-describedby="groomingConsentError" />
        <span class="text-sm text-slate-700"><span data-consent-grooming></span> <span class="text-red-500">*</span></span>
      </label>
      <p id="groomingConsentError" role="alert" class="mt-2 text-sm text-red-600" hidden>Agree to the grooming agreement.</p>
    </section>
    <section class="rounded-2xl border border-[#91aeca] bg-white p-4 sm:p-6">
      <fieldset aria-describedby="sedationConsentError" class="min-w-0">
        <legend class="mb-4 text-lg font-semibold text-[#2f4b66]">Sedation Authorization <span class="text-red-500">*</span></legend>
        <p data-consent-sedation class="mx-auto max-w-[65ch] text-base leading-7 text-slate-700"></p>
        <div class="mt-5 grid gap-3">
          <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 hover:bg-slate-50 has-[:checked]:border-[#315b7e] has-[:checked]:bg-slate-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#315b7e]">
            <input id="sedationConsentYes" name="sedationAuthorization" type="radio" value="yes" required aria-describedby="sedationConsentError" class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" />
            <span class="text-sm leading-6 text-slate-700">Yes, I authorize sedation if deemed necessary for grooming.</span>
          </label>
          <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 px-4 py-3 hover:bg-slate-50 has-[:checked]:border-[#315b7e] has-[:checked]:bg-slate-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[#315b7e]">
            <input id="sedationConsentNo" name="sedationAuthorization" type="radio" value="no" required aria-describedby="sedationConsentError" class="mt-1 h-4 w-4 shrink-0 accent-[#315b7e]" />
            <span class="text-sm leading-6 text-slate-700">No, I do not authorize sedation.</span>
          </label>
        </div>
        <p id="sedationConsentError" role="alert" class="mt-2 text-sm text-red-600" hidden>Choose Yes or No.</p>
      </fieldset>
    </section>
    <section aria-labelledby="consentChoicesLabel" class="rounded-2xl border border-slate-200 bg-white px-4 py-4 sm:px-6">
      <h3 id="consentChoicesLabel" class="text-sm font-semibold text-[#2f4b66]">Consent choices</h3>
      <dl class="mt-3 grid gap-2 text-sm text-slate-700">
        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1"><dt>Grooming Agreement</dt><dd data-grooming-summary class="font-semibold">Not yet agreed</dd></div>
        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1"><dt>Sedation Authorization</dt><dd data-sedation-summary class="font-semibold">Choose Yes or No</dd></div>
      </dl>
    </section>
    <section class="rounded-2xl border border-[#91aeca] bg-white p-4 sm:p-6">
      <h3 id="customerSignatureLabel" class="mb-3 text-lg font-semibold text-[#2f4b66]">Customer Signature <span class="text-red-500">*</span></h3>
      <canvas id="customerSignaturePad" role="img" aria-labelledby="customerSignatureLabel" aria-describedby="customerSignatureError" class="w-full rounded-xl border border-slate-300 bg-slate-50" style="aspect-ratio:5/2; max-width:560px; height:auto; touch-action:none"></canvas>
      <button id="clearCustomerSignature" type="button" class="mt-2 text-sm font-medium text-[#315b7e] hover:underline focus-visible:underline">Clear signature</button>
      <p id="customerSignatureError" role="alert" class="mt-2 text-sm text-red-600" hidden>Please draw your signature.</p>
      <label for="consentSignerName" class="mb-2 mt-4 block text-sm font-medium text-slate-700">Signer full name <span class="text-red-500">*</span></label>
      <input id="consentSignerName" type="text" autocomplete="name" maxlength="200" required aria-describedby="consentSignerError" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-sm outline-none transition focus:border-[#315b7e] focus:ring-2 focus:ring-[#315b7e]/20" />
      <p id="consentSignerError" role="alert" class="mt-2 text-sm text-red-600" hidden>Enter the signer’s full name.</p>
    </section>
    <dialog id="sedationDeclineDialog" aria-labelledby="sedationDeclineTitle" aria-describedby="sedationDeclineMessage" style="margin:auto" class="w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-amber-300 bg-white p-5 text-slate-800 shadow-xl backdrop:bg-slate-900/40 sm:p-6">
      <h3 id="sedationDeclineTitle" class="text-lg font-semibold text-amber-900">Continue without sedation authorization?</h3>
      <p id="sedationDeclineMessage" class="mt-3 text-sm leading-6 text-slate-700"></p>
      <div class="mt-5 flex flex-col gap-3">
        <button id="reviewSedationChoice" type="button" autofocus class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#315b7e]">Review choice</button>
        <button id="confirmSedationDecline" type="button" class="rounded-xl bg-amber-100 px-4 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-700">Continue without sedation authorization</button>
      </div>
    </dialog>`;
  const grooming = host.querySelector('#mainConsentCheckbox');
  const sedationYes = host.querySelector('#sedationConsentYes');
  const sedationNo = host.querySelector('#sedationConsentNo');
  const name = host.querySelector('#consentSignerName');
  const dialog = host.querySelector('#sedationDeclineDialog');
  let agreement = null;
  let saved = null;
  let busy = false;
  let declineConfirmed = false;
  let confirming = false;
  let attempted = false;
  const touched = new Set();
  try { saved = JSON.parse(sessionStorage.getItem(storageKey)); } catch { /* Ignore an invalid draft. */ }
  name.value = saved?.signer_name ?? signerName;
  const pad = createSignaturePad(host.querySelector('canvas'), () => changed('signature'));
  const sedationChoice = () => sedationYes.checked ? 'yes' : sedationNo.checked ? 'no' : null;
  const payload = () => ({
    terms_agreed: grooming.checked,
    sedation_consent: sedationChoice() === null ? null : sedationYes.checked,
    signer_name: name.value.trim(),
    signature_strokes: pad.strokes,
    consent_version: agreement?.version,
  });
  function validate(showErrors = false) {
    if (showErrors) attempted = true;
    const errors = [
      ['groomingConsentError', 'grooming', !grooming.checked, [grooming]],
      ['sedationConsentError', 'sedation', sedationChoice() === null, [sedationYes, sedationNo]],
      ['customerSignatureError', 'signature', !pad.drawn, [host.querySelector('canvas')]],
      ['consentSignerError', 'name', !name.value.trim(), [name]],
    ];
    for (const [id, key, invalid, fields] of errors) {
      const visible = invalid && (attempted || touched.has(key));
      host.querySelector('#' + id).hidden = !visible;
      fields.forEach(field => field.setAttribute('aria-invalid', String(visible)));
    }
    return Boolean(agreement && grooming.checked && sedationChoice() !== null && pad.drawn && !pad.drawing && name.value.trim());
  }
  function changed(field) {
    if (field) touched.add(field);
    if (agreement) sessionStorage.setItem(storageKey, JSON.stringify({
      ...payload(), sedation_choice: sedationChoice(), sedation_decline_confirmed: declineConfirmed,
    }));
    host.querySelector('[data-grooming-summary]').textContent = grooming.checked ? 'Agreed' : 'Not yet agreed';
    host.querySelector('[data-sedation-summary]').textContent = sedationChoice() === null ? 'Choose Yes or No' : sedationYes.checked ? 'Authorized' : 'Not authorized';
    onChange(validate() && !busy && !confirming);
  }
  grooming.addEventListener('change', () => changed('grooming'));
  [sedationYes, sedationNo].forEach(radio => radio.addEventListener('change', () => {
    declineConfirmed = false;
    changed('sedation');
  }));
  name.addEventListener('input', () => changed('name'));
  name.addEventListener('blur', () => changed('name'));
  host.querySelector('#clearCustomerSignature').addEventListener('click', () => pad.clear());
  host.querySelector('#sedationDeclineMessage').textContent = staff
    ? 'Confirm that the customer has chosen not to authorize sedation for this grooming visit.'
    : 'You have chosen not to authorize sedation for this grooming visit.';
  host.querySelector('#reviewSedationChoice').addEventListener('click', () => dialog.close('review'));
  host.querySelector('#confirmSedationDecline').addEventListener('click', () => dialog.close('continue'));
  agreementRequest ??= fetch(termsUrl).then(response => {
    if (!response.ok) throw new Error('Unable to load the agreement. Please reload.');
    return response.json();
  }).catch(error => { agreementRequest = null; throw error; });
  const ready = agreementRequest.then(terms => {
    agreement = terms;
    host.querySelector('[data-consent-intro]').textContent = terms.grooming_intro;
    const headings = ['Pet eligibility', 'Grooming services', 'Skin & coat risks', 'Behavior & safety', 'Parasites & health concerns', 'Owner representative', 'Payment responsibility'];
    terms.grooming_conditions.forEach((condition, index) => {
      const item = document.createElement('li');
      const heading = document.createElement('h4');
      heading.className = 'mb-1 text-sm font-bold text-[#2f4b66]';
      heading.textContent = headings[index];
      const paragraph = document.createElement('p');
      paragraph.textContent = condition;
      item.append(heading, paragraph);
      host.querySelector('[data-consent-conditions]').append(item);
    });
    host.querySelector('[data-consent-grooming]').textContent = terms.grooming_action;
    host.querySelector('[data-consent-sedation]').textContent = terms.sedation_paragraph;
    if (saved?.consent_version === terms.version) {
      grooming.checked = saved.terms_agreed === true;
      // Old checkbox drafts do not establish an intentional Yes/No decision.
      sedationYes.checked = saved.sedation_choice === 'yes';
      sedationNo.checked = saved.sedation_choice === 'no';
      declineConfirmed = sedationNo.checked && saved.sedation_decline_confirmed === true;
      pad.restore(saved.signature_strokes);
    }
    changed();
  }).catch(error => {
    const status = document.getElementById('consentStatusMessage');
    status.hidden = false;
    status.textContent = error.message;
    onChange(false);
  });
  return {
    ready, payload, validate,
    get valid() { return validate() && !busy && !confirming; },
    async confirmSubmission() {
      if (!validate(true) || busy || confirming) return false;
      if (!sedationNo.checked || declineConfirmed) return true;
      confirming = true;
      changed();
      dialog.returnValue = '';
      const confirmed = await new Promise(resolve => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'continue'), { once: true });
        dialog.showModal();
      });
      confirming = false;
      declineConfirmed = confirmed;
      changed();
      if (!confirmed) sedationNo.focus();
      return confirmed && validate() && !busy;
    },
    setBusy(value) {
      busy = value;
      grooming.disabled = sedationYes.disabled = sedationNo.disabled = name.disabled = value;
      host.querySelector('#clearCustomerSignature').disabled = value;
      host.querySelector('canvas').style.pointerEvents = value ? 'none' : '';
      onChange(validate() && !busy && !confirming);
    },
    prefill(fullName) {
      if (!name.value) { name.value = fullName; changed(); }
    },
  };
}
