const manilaCalendar = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
});

function daysInMonth(year, month) {
  return [31, year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0) ? 29 : 28,
    31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1] || 0;
}

export function getPetBirthdateBounds(now = new Date()) {
  const parts = Object.fromEntries(manilaCalendar.formatToParts(now).map(part => [part.type, part.value]));
  const year = Number(parts.year) - 40;
  const day = Math.min(Number(parts.day), daysInMonth(year, Number(parts.month)));
  return {
    min: `${String(year).padStart(4, '0')}-${parts.month}-${String(day).padStart(2, '0')}`,
    max: `${parts.year}-${parts.month}-${parts.day}`,
  };
}

export function getPetBirthdateValidationMessage(value, { now = new Date(), originalValue = null } = {}) {
  if (value === null || value === '') return '';
  if (originalValue && value === originalValue) return '';
  const parts = typeof value === 'string' && /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value);
  if (!parts || Number(parts[1]) < 1 || Number(parts[3]) < 1
    || Number(parts[3]) > daysInMonth(Number(parts[1]), Number(parts[2]))) return 'Enter a valid birthdate.';
  const { min, max } = getPetBirthdateBounds(now);
  if (value > max) return 'Birthdate cannot be in the future.';
  if (value < min) return 'Enter a birthdate within the last 40 years.';
  return '';
}

export function createPetBirthdateField({ input, errorElement, now = () => new Date(), onInvalid = () => {} }) {
  let originalValue = null;
  let rejectedValue = '';

  const getValue = () => input.value || rejectedValue;
  function refresh() {
    const bounds = getPetBirthdateBounds(now());
    input.min = bounds.min;
    input.max = bounds.max;
  }
  function getValidationMessage() {
    if (input.disabled) return '';
    if (input.validity?.badInput) return 'Enter a valid birthdate.';
    return getPetBirthdateValidationMessage(getValue(), { now: now(), originalValue });
  }
  function showValidation(message = getValidationMessage()) {
    input.setCustomValidity(message);
    input.setAttribute('aria-invalid', String(Boolean(message)));
    input.classList.toggle('border-red-400', Boolean(message));
    errorElement.textContent = message;
    errorElement.classList.toggle('hidden', !message);
  }
  function validate({ focus = false } = {}) {
    refresh();
    const message = getValidationMessage();
    showValidation(message);
    if (message) {
      onInvalid();
      if (focus) input.focus();
    }
    return !message;
  }
  function setValue(value, { originalValue: savedValue = null } = {}) {
    originalValue = savedValue;
    input.value = value || '';
    // Native date inputs sanitize invalid restored calendar strings to empty.
    // Keep the draft value until the user edits it so it cannot silently save as null.
    rejectedValue = value && !input.value ? value : '';
    refresh();
    showValidation();
  }
  function prepareSubmission(event) {
    if (!validate({ focus: true })) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    // An unchanged historical date is allowed for this submission only. Keep the
    // canonical picker range at all other times, including while the field has focus.
    if (originalValue && getValue() === originalValue
      && (originalValue < input.min || originalValue > input.max)) {
      input.removeAttribute('min');
      input.removeAttribute('max');
      setTimeout(refresh, 0);
    }
  }

  const descriptions = new Set((input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
  descriptions.add(errorElement.id);
  input.setAttribute('aria-describedby', [...descriptions].join(' '));
  input.addEventListener('focus', refresh);
  input.addEventListener('pointerdown', refresh);
  for (const type of ['input', 'change']) input.addEventListener(type, () => {
    rejectedValue = '';
    validate();
  });
  input.addEventListener('invalid', event => {
    if (getValidationMessage()) {
      event.preventDefault();
      validate({ focus: true });
    }
  });
  const form = input.form;
  form.addEventListener('click', event => {
    const control = event.target.closest('button, input');
    if (control?.type === 'submit' && control.form === form) prepareSubmission(event);
  }, true);
  form.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA'
      && event.target.getAttribute('role') !== 'combobox' && event.target.type !== 'button') prepareSubmission(event);
  }, true);
  form.addEventListener('submit', prepareSubmission, true);
  form.addEventListener('reset', () => { rejectedValue = ''; showValidation(''); refresh(); });
  refresh();
  return { setValue, getValue, refresh, getValidationMessage, showValidation, validate };
}
