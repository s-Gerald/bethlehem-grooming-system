// BEGIN GENERATED EMAIL POLICY
// Source: resources/data/account-email-policy.json; run npm run build:email.
const EMAIL_ENTRY_POLICY = {"domains":["gmail.com","outlook.com","hotmail.com","live.com","msn.com","yahoo.com","yahoo.com.ph","ymail.com","icloud.com","me.com","mac.com"],"exception_domains":[],"typos":{"gmial.com":"gmail.com","gamil.com":"gmail.com","outlok.com":"outlook.com","hotmial.com":"hotmail.com","yaho.com":"yahoo.com"},"min_local":3,"max_local":64,"max_length":150};
// END GENERATED EMAIL POLICY

// Shared by existing entry forms. Sign-in/reset/resend intentionally use their legacy rules.
var AccountEmail = (() => {
  const bindings = new WeakMap();
  const normalize = value => {
    const email = String(value ?? '').trim(), at = email.lastIndexOf('@');
    return at < 0 ? email : email.slice(0, at + 1) + email.slice(at + 1).toLowerCase();
  };
  const approved = domain => EMAIL_ENTRY_POLICY.domains.includes(domain) || EMAIL_ENTRY_POLICY.exception_domains.includes(domain);
  function validate(value, { required = true, maxLength = EMAIL_ENTRY_POLICY.max_length } = {}) {
    const email = normalize(value);
    if (!email) return required ? 'Enter your email address.' : '';
    if (email.split('@').length !== 2 || /\s/.test(email)) return 'Enter a valid email address.';
    const [local, domain] = email.split('@');
    if (!local || !domain) return 'Enter a valid email address.';
    if (/[^\x21-\x7e]/.test(email)) return 'Remove unsupported characters.';
    if (local.length < EMAIL_ENTRY_POLICY.min_local) return 'Use at least 3 characters before @.';
    if (local.length > EMAIL_ENTRY_POLICY.max_local) return 'Use no more than 64 characters before @.';
    if (email.length > maxLength) return `Use an email address of ${maxLength} characters or fewer.`;
    if (!/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~\-]+(?:\.[A-Za-z0-9!#$%&'*+\/=?^_`{|}~\-]+)*$/.test(local)
      || !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/.test(domain)) return 'Enter a valid email address.';
    return approved(domain) ? '' : 'Use Gmail, Outlook, Hotmail, Live, Yahoo, or iCloud.';
  }
  function suggestion(value) {
    const email = normalize(value);
    if (email.split('@').length !== 2) return '';
    const domain = email.split('@')[1];
    return approved(domain) ? '' : EMAIL_ENTRY_POLICY.typos[domain] || '';
  }
  function applySuggestion(value) {
    const email = normalize(value), domain = suggestion(email);
    return domain ? email.slice(0, email.indexOf('@') + 1) + domain : email;
  }
  function bind(input, options = {}) {
    if (bindings.has(input)) return bindings.get(input);
    const errorId = options.errorId || input.getAttribute('aria-describedby')?.split(' ')[0] || `${input.id}Error`;
    const error = document.getElementById(errorId);
    input.setAttribute('data-email-entry', '');
    input.setAttribute('aria-describedby', errorId);
    error?.setAttribute('aria-live', 'polite');
    const hint = document.createElement('p'), label = document.createElement('span'), action = document.createElement('button');
    hint.hidden = true;
    hint.className = 'mt-2 text-sm text-slate-600';
    hint.setAttribute('aria-live', 'polite');
    action.type = 'button'; action.textContent = 'Use suggestion';
    action.className = 'ml-2 rounded font-semibold text-[#315b7e] underline underline-offset-4 hover:text-[#284a67] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#315b7e]';
    hint.append(label, action); input.insertAdjacentElement('afterend', hint);
    function clear() {
      input.setAttribute('aria-invalid', 'false');
      if (error && !options.onError) { error.textContent = ''; error.classList?.add('hidden'); }
      hint.hidden = true; options.onError?.('');
    }
    function setError(message, submittedValue) {
      if (submittedValue !== undefined && normalize(input.value) !== normalize(submittedValue)) return false;
      input.setAttribute('aria-invalid', String(Boolean(message)));
      if (error && !options.onError) { error.textContent = message; error.classList?.toggle('hidden', !message); }
      options.onError?.(message);
      const domain = message && !input.readOnly ? suggestion(input.value) : '';
      label.textContent = domain ? `Did you mean @${domain}?` : '';
      hint.hidden = !domain;
      return true;
    }
    const field = {
      clear, setError,
      validate() {
        const message = options.isUnchanged?.() ? '' : options.getError ? options.getError(input.value) : validate(input.value, options);
        setError(message); return !message;
      },
    };
    bindings.set(input, field);
    input.addEventListener('input', clear);
    input.addEventListener('blur', () => field.validate());
    action.addEventListener('click', () => {
      input.value = applySuggestion(input.value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      field.validate(); input.focus();
    });
    return field;
  }
  function showError(input, message) {
    bindings.get(input)?.setError(message);
  }
  return { normalize, validate, suggestion, applySuggestion, bind, showError };
})();
window.AccountEmail = AccountEmail;
