// BEGIN GENERATED MOBILE POLICY
// Source: resources/data/philippine-mobile-policy.json; run npm run build:mobile.
const MOBILE_PREFIXES = ["0813","0817","0895","0896","0897","0898","0905","0906","0907","0908","0909","0910","0911","0912","0914","0915","0916","0917","0918","0919","0920","0921","0922","0923","0924","0925","0926","0927","0928","0929","0930","0931","0932","0933","0934","0935","0936","0937","0938","0939","0942","0943","0944","0945","0946","0947","0948","0949","0950","0951","0952","0953","0954","0955","0956","0957","0958","0959","0960","0961","0962","0963","0964","0965","0966","0967","0968","0969","0970","0971","0972","0973","0974","0975","0976","0977","0978","0979","0981","0983","0985","0986","0987","0988","0989","0991","0992","0993","0994","0995","0996","0997","0998","0999"];
// END GENERATED MOBILE POLICY

var PhilippineMobile = (() => {
  const bindings = new WeakMap();
  function normalize(value) {
    const phone = String(value ?? '').trim().replace(/[\s()-]+/g, '');
    if (/^\+?63[89][0-9]{9}$/.test(phone)) return `0${phone.replace(/^\+?63/, '')}`;
    if (/^[89][0-9]{9}$/.test(phone)) return `0${phone}`;
    return phone;
  }
  const unformat = value => String(value ?? '').replace(/ /g, '');
  const group = digits => [digits.slice(0, 4), digits.slice(4, 7), digits.slice(7, 11)].filter(Boolean).join(' ');
  function format(value, fallback = '') {
    const text = String(value ?? '').trim();
    if (!text) return fallback;
    const phone = normalize(text);
    return validate(phone) ? text : group(phone);
  }
  function searchQuery(value) {
    const text = String(value ?? '').trim(), phone = text.replace(/[\s()-]+/g, '');
    return /^(?:0[89]|\+?63[89])[0-9]*$/.test(phone) ? phone.replace(/^\+?63/, '0') : text;
  }
  function formatError(value, { required = true } = {}) {
    const phone = unformat(value);
    if (!phone) return required ? 'Enter a mobile number.' : '';
    if (phone.length !== 11 || /[^0-9]/.test(phone)) return 'Enter an 11-digit mobile number.';
    return /^0[89]/.test(phone) ? '' : 'Enter a valid Philippine mobile number.';
  }
  function validate(value, options = {}) {
    const error = formatError(value, options);
    if (error || !value) return error;
    return MOBILE_PREFIXES.includes(unformat(value).slice(0, 4)) ? '' : 'Enter a valid Philippine mobile number.';
  }
  function bind(input, options = {}) {
    if (bindings.has(input)) return bindings.get(input);
    const errorId = options.errorId || input.getAttribute('aria-describedby')?.split(' ')[0];
    const error = errorId && document.getElementById(errorId);
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '13');
    input.setAttribute('placeholder', '09XX XXX XXXX');
    if (errorId) input.setAttribute('aria-describedby', errorId);
    error?.setAttribute('aria-live', 'polite');
    error?.setAttribute('aria-atomic', 'true');
    const getError = () => (!input.value && options.required === false) || options.isUnchanged?.() ? ''
      : options.getError ? options.getError(unformat(input.value)) : validate(input.value, options);
    let composing = false, lastValue = input.value, deletion = null;
    function setError(message = '') {
      input.setAttribute('aria-invalid', String(Boolean(message)));
      if (!options.onError) input.classList.toggle('!border-red-400', Boolean(message));
      if (error && !options.onError) {
        error.textContent = message;
        error.hidden = !message;
        error.classList.toggle('hidden', !message);
      }
      options.onError?.(message);
    }
    const digitCount = text => text.replace(/\D/g, '').length;
    function position(text, count) {
      if (!count) return 0;
      let seen = 0;
      for (let i = 0; i < text.length; i++) if (/[0-9]/.test(text[i]) && ++seen === count) return i + 1;
      return text.length;
    }
    function write(digits, start = null, end = start) {
      const visible = group(digits);
      if (input.value !== visible) {
        input.value = visible;
      }
      if (start != null && end != null && (input.selectionStart !== position(visible, start) || input.selectionEnd !== position(visible, end))) {
        input.setSelectionRange(position(visible, start), position(visible, end));
      }
      lastValue = visible;
    }
    function changed() {
      setError(unformat(input.value).length === 11 ? getError() : '');
      options.onChange?.(input.value);
    }
    function edit(event = {}) {
      if (composing || event.isComposing) return;
      const raw = input.value, national = normalize(raw);
      const international = digitCount(raw) === 12 && /^[0-9]{11}$/.test(national);
      let digits = (international ? national : raw.replace(/\D/g, '')).slice(0, 11);
      let start = input.selectionStart == null ? null : Math.min(11, Math.max(0, digitCount(raw.slice(0, input.selectionStart)) - Number(international)));
      let end = input.selectionEnd == null ? null : Math.min(11, Math.max(0, digitCount(raw.slice(0, input.selectionEnd)) - Number(international)));
      // Some keyboards report a separator deletion only through the input event.
      if (deletion?.collapsed !== false && start != null && raw !== lastValue && digits === unformat(lastValue)
          && ['deleteContentBackward', 'deleteContentForward'].includes(event.inputType)) {
        const at = event.inputType === 'deleteContentBackward' ? start - 1 : start;
        if (at >= 0) { digits = digits.slice(0, at) + digits.slice(at + 1); start = end = at; }
      }
      deletion = null;
      write(digits, start, end); changed();
    }
    const field = {
      clear: () => setError(), setError,
      setValue(value) { const text = String(value ?? ''), local = normalize(text); write((digitCount(text) === 12 ? local : text).replace(/\D/g, '').slice(0, 11)); },
      validate() { const message = getError(); setError(message); return !message; },
    };
    bindings.set(input, field);
    field.setValue(input.value);
    input.addEventListener('compositionstart', () => { composing = true; });
    input.addEventListener('compositionend', () => { composing = false; edit(); });
    input.addEventListener('beforeinput', event => {
      const start = input.selectionStart, end = input.selectionEnd;
      deletion = { collapsed: start === end };
      if (composing || event.isComposing || event.cancelable === false || start == null || start !== end) return;
      const backward = event.inputType === 'deleteContentBackward' && input.value[start - 1] === ' ';
      const forward = event.inputType === 'deleteContentForward' && input.value[start] === ' ';
      if (!backward && !forward) return;
      event.preventDefault();
      const digits = input.value.replace(/\D/g, ''), at = digitCount(input.value.slice(0, start)) - Number(backward);
      write(digits.slice(0, at) + digits.slice(at + 1), at); deletion = null; changed();
    });
    input.addEventListener('input', edit);
    input.addEventListener('blur', () => field.validate());
    input.addEventListener('paste', event => {
      if (!event.clipboardData) return;
      event.preventDefault();
      const start = digitCount(input.value.slice(0, input.selectionStart ?? input.value.length));
      const end = digitCount(input.value.slice(0, input.selectionEnd ?? input.value.length));
      const previous = input.value.replace(/\D/g, '');
      const pasted = event.clipboardData.getData('text');
      const digits = (start === 0 && end === previous.length ? normalize(pasted) : pasted).replace(/\D/g, '');
      const inserted = digits.slice(0, Math.max(0, 11 - previous.length + end - start));
      write(previous.slice(0, start) + inserted + previous.slice(end), start + inserted.length);
      changed();
    });
    return field;
  }
  function showError(input, message) { bindings.get(input)?.setError(message); }
  return { normalize, unformat, format, searchQuery, formatError, validate, bind, showError };
})();
globalThis.PhilippineMobile = PhilippineMobile;
if (typeof window !== 'undefined') window.PhilippineMobile = PhilippineMobile;
