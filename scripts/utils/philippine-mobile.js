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
  function formatError(value, { required = true } = {}) {
    const phone = String(value ?? '');
    if (!phone) return required ? 'Enter a mobile number.' : '';
    if (phone.length !== 11 || /[^0-9]/.test(phone)) return 'Enter an 11-digit mobile number.';
    return /^0[89]/.test(phone) ? '' : 'Enter a valid Philippine mobile number.';
  }
  function validate(value, options = {}) {
    const error = formatError(value, options);
    if (error || !value) return error;
    return MOBILE_PREFIXES.includes(String(value).slice(0, 4)) ? '' : 'Enter a valid Philippine mobile number.';
  }
  function bind(input, options = {}) {
    if (bindings.has(input)) return bindings.get(input);
    const errorId = options.errorId || input.getAttribute('aria-describedby')?.split(' ')[0];
    const error = errorId && document.getElementById(errorId);
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('maxlength', '11');
    if (errorId) input.setAttribute('aria-describedby', errorId);
    error?.setAttribute('aria-live', 'polite');
    error?.setAttribute('aria-atomic', 'true');
    const getError = () => (!input.value && options.required === false) || options.isUnchanged?.() ? ''
      : options.getError ? options.getError(input.value) : validate(input.value, options);
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
    function edit() {
      const raw = input.value, digits = raw.replace(/\D/g, '').slice(0, 11);
      const start = input.selectionStart, end = input.selectionEnd;
      if (raw !== digits) {
        input.value = digits;
        if (start != null && end != null) input.setSelectionRange(
          Math.min(raw.slice(0, start).replace(/\D/g, '').length, 11),
          Math.min(raw.slice(0, end).replace(/\D/g, '').length, 11));
      }
      setError(digits.length === 11 ? getError() : '');
      options.onChange?.(input.value);
    }
    const field = { clear: () => setError(), setError, validate() { const message = getError(); setError(message); return !message; } };
    bindings.set(input, field);
    input.addEventListener('input', edit);
    input.addEventListener('blur', () => field.validate());
    input.addEventListener('paste', event => {
      if (!event.clipboardData) return;
      event.preventDefault();
      const start = input.selectionStart ?? input.value.length, end = input.selectionEnd ?? start;
      const pasted = event.clipboardData.getData('text');
      const digits = (start === 0 && end === input.value.length ? normalize(pasted) : pasted).replace(/\D/g, '');
      const inserted = digits.slice(0, Math.max(0, 11 - input.value.length + end - start));
      input.value = input.value.slice(0, start) + inserted + input.value.slice(end);
      input.setSelectionRange(start + inserted.length, start + inserted.length);
      edit();
    });
    return field;
  }
  function showError(input, message) { bindings.get(input)?.setError(message); }
  return { normalize, formatError, validate, bind, showError };
})();
window.PhilippineMobile = PhilippineMobile;
