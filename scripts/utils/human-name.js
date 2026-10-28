// BEGIN GENERATED NAME POLICY
// Source: resources/data/human-name-policy.json; run npm run build:names.
const HUMAN_NAME_POLICY = {"max_length":100,"mc_names":["mcdonald","mcdonnell","mcdowell","mcgregor","mcguire","mckay","mckenzie","mclaughlin","mclean","mcleod","mcmillan","mcmahon","mcnamara"]};
// END GENERATED NAME POLICY

var HumanName = (() => {
  const states = new WeakMap();
  const whitespace = value => String(value ?? '').replace(/\p{Zs}+/gu, ' ').replace(/^ +| +$/g, '');
  function normalize(value) {
    const name = whitespace(value);
    if (name !== name.toLowerCase() && name !== name.toUpperCase()) return name;
    return name.replace(/(?:(?!ʼ)[\p{L}\p{M}])+/gu, word => {
      word = word.toLowerCase();
      return HUMAN_NAME_POLICY.mc_names.includes(word)
        ? `Mc${word[2].toUpperCase()}${word.slice(3)}`
        : [...word][0].toUpperCase() + word.slice([...word][0].length);
    });
  }
  function validate(value, { required = true } = {}) {
    const raw = String(value ?? '');
    if (!whitespace(raw)) return required ? 'Enter a name.' : '';
    if (/\p{N}/u.test(raw)) return "Numbers aren't allowed.";
    if (/[^\p{L}\p{M}\p{Zs}'’ʼ\-‐‑]/u.test(raw) || !/\p{L}/u.test(raw.replace(/ʼ/g, ''))) return 'Enter a valid name.';
    return [...normalize(raw)].length > HUMAN_NAME_POLICY.max_length ? 'Use 100 characters or fewer.' : '';
  }
  function state(input) {
    if (!states.has(input)) states.set(input, { original: input.defaultValue || '', dirty: false, composing: false, numeric: false });
    return states.get(input);
  }
  function showError(input, message) {
    if (!input) return;
    const current = state(input);
    input.classList.toggle('!border-red-400', Boolean(message));
    if (current.onError) {
      input.setAttribute('aria-invalid', String(Boolean(message)));
      current.onError(message);
      return;
    }
    let error = document.getElementById(`${input.id}NameError`);
    if (!error && message) {
      error = document.createElement('p'); error.id = `${input.id}NameError`;
      error.className = 'mt-2 text-sm text-red-600'; error.setAttribute('aria-live', 'polite');
      input.insertAdjacentElement('afterend', error);
      const ids = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
      ids.add(error.id); input.setAttribute('aria-describedby', [...ids].join(' '));
    }
    if (error) { error.textContent = message; error.hidden = !message; }
    input.setAttribute('aria-invalid', String(Boolean(message)));
    input.classList.toggle('ring-1', Boolean(message));
    input.classList.toggle('ring-red-400', Boolean(message));
  }
  function cleanInput(input) {
    const current = state(input);
    const raw = input.value;
    const numeric = /\p{N}/u.test(raw);
    if (numeric) {
      const start = input.selectionStart, end = input.selectionEnd;
      input.value = raw.replace(/\p{N}/gu, '');
      if (start !== null && end !== null) {
        input.setSelectionRange(raw.slice(0, start).replace(/\p{N}/gu, '').length, raw.slice(0, end).replace(/\p{N}/gu, '').length);
      }
    }
    current.dirty = true; current.numeric = numeric;
    showError(input, numeric ? "Numbers aren't allowed." : '');
  }
  function finish(input) {
    const current = state(input);
    if (current.composing) return false;
    // Autofill can change a field without an input event.
    if (/\p{N}/u.test(input.value)) cleanInput(input);
    if ((current.dirty || input.value !== current.original) && input.value !== current.original) {
      const formatted = normalize(input.value);
      if (formatted !== input.value) {
        input.value = formatted;
        current.formatting = true;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        current.formatting = false;
      }
    }
    const message = validate(input.value);
    showError(input, message || (current.numeric ? "Numbers aren't allowed." : ''));
    return !message;
  }
  function reset(input) {
    const onError = states.get(input)?.onError;
    states.delete(input); Object.assign(state(input), { original: input.value, onError }); showError(input, '');
  }
  function bind(input, onError) {
    if (!input) return;
    state(input).onError = onError; reset(input);
    for (const id of (input.getAttribute('aria-describedby') || '').split(' ')) document.getElementById(id)?.setAttribute('aria-live', 'polite');
  }
  function target(event) {
    const input = event.target;
    return input?.matches?.('input[data-human-name]') && !input.readOnly && !input.disabled ? input : null;
  }
  document.addEventListener('focusin', event => { const input = target(event); if (input && !states.has(input)) state(input).original = input.value; }, true);
  document.addEventListener('invalid', event => {
    const input = target(event); if (input) { event.preventDefault(); showError(input, validate(input.value)); }
  }, true);
  document.addEventListener('compositionstart', event => { const input = target(event); if (input) state(input).composing = true; }, true);
  document.addEventListener('compositionend', event => {
    const input = target(event); if (!input) return;
    state(input).composing = false; cleanInput(input);
    state(input).formatting = true;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    state(input).formatting = false;
  }, true);
  document.addEventListener('input', event => {
    const input = target(event); if (!input) return;
    const current = state(input);
    if (!event.isComposing && !current.composing && !current.formatting) cleanInput(input);
  }, true);
  document.addEventListener('blur', event => { const input = target(event); if (input) finish(input); }, true);
  document.addEventListener('submit', event => {
    let firstInvalid;
    event.target.querySelectorAll('input[data-human-name]').forEach(input => {
      if (!input.readOnly && !input.disabled && !finish(input)) firstInvalid ||= input;
    });
    if (firstInvalid) { event.preventDefault(); event.stopImmediatePropagation(); firstInvalid.focus(); }
  }, true);
  return { normalize, validate, showError, finish, reset, bind };
})();
window.HumanName = HumanName;
