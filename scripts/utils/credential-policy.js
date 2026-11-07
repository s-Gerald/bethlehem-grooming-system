// Format checks only. Password safety and account authorization are enforced by the server.
var CredentialPolicy = (() => {
  function password(value, minimum = 8, required = true) {
    if (!value) return required ? 'Enter a new password.' : '';
    if (/[^\x20-\x7e]/.test(value)) return 'Use English letters, numbers, or standard symbols only.';
    if (value.startsWith(' ') || value.endsWith(' ')) return 'Remove spaces at the beginning or end.';
    if (value.length < minimum) return `Use at least ${minimum} characters.`;
    if (value.length > 64) return 'Use no more than 64 characters.';
    return '';
  }
  function username(value, required = true, unchanged = null) {
    if (unchanged !== null && value === unchanged) return '';
    if (!value) return required ? 'Enter a username.' : '';
    if (value.length < 3 || value.length > 30) return 'Use 3–30 characters.';
    if (/[^A-Za-z0-9._-]/.test(value)) return 'Use letters, numbers, periods, underscores, or hyphens only.';
    if (!/^[A-Za-z]/.test(value)) return 'Begin with a letter.';
    if (!/[A-Za-z0-9]$/.test(value)) return 'End with a letter or number.';
    return '';
  }
  function confirmation(value, original, required = true) {
    if (!value) return required ? 'Confirm your new password.' : '';
    return value === original ? '' : 'Passwords do not match.';
  }
  function bind(input, { validate, feedback, className = 'mt-2 text-sm text-red-700' }) {
    if (!feedback) {
      feedback = document.createElement('p');
      feedback.id = `${input.id}CredentialError`;
      feedback.className = className;
      feedback.hidden = true;
      input.parentElement.insertAdjacentElement('afterend', feedback);
    }
    feedback.id ||= `${input.id}CredentialError`;
    feedback.setAttribute?.('aria-live', 'polite');
    input.setAttribute('aria-describedby', [...new Set(`${input.getAttribute('aria-describedby') || ''} ${feedback.id}`.trim().split(/\s+/))].join(' '));
    let error = '', touched = false;
    function setError(message = '') {
      error = message;
      feedback.textContent = message;
      feedback.hidden = !message;
      feedback.classList.toggle('hidden', !message);
      input.setAttribute('aria-invalid', String(Boolean(message)));
      input.classList.toggle('!border-red-400', Boolean(message));
    }
    input.addEventListener('input', () => setError(input.value && (error || touched) ? validate() : ''));
    input.addEventListener('blur', () => { touched = true; setError(validate()); });
    return {
      setError,
      clear() { touched = false; setError(); },
      refresh() { if (input.value || error) setError(input.value ? validate() : ''); },
      validate() { touched = true; const message = validate(); setError(message); return !message; },
    };
  }
  return { password, username, confirmation, bind };
})();
