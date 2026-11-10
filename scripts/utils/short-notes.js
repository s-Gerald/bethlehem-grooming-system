export const maxLength = 500;
export const limitMessage = `Maximum ${maxLength} characters allowed.`;

// Laravel's string max counts Unicode code points, not UTF-16 code units.
const length = value => Array.from(value).length;
export const tooLong = value => typeof value === 'string' && value.length > maxLength && length(value) > maxLength;

(() => {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  const fieldSelector = '[data-short-note]';
  const shortenMessage = `This note exceeds the ${maxLength}-character limit. Shorten it before saving.`;
  const states = new WeakMap();
  let nextErrorId = 0;

  const unchangedLegacy = (value, original) => tooLong(original)
    && String(value).replace(/\r\n?/g, '\n') === String(original).replace(/\r\n?/g, '\n');
  const editable = field => !field.readOnly && !field.matches(':disabled');
  const snapshot = field => ({
    value: field.value,
    start: field.selectionStart,
    end: field.selectionEnd,
    direction: field.selectionDirection,
  });

  function stateFor(field) {
    if (!states.has(field)) states.set(field, { previous: snapshot(field) });
    return states.get(field);
  }

  function showError(field, message) {
    const state = stateFor(field);
    if (!state.error) {
      state.error = document.createElement('p');
      state.error.id = `short-note-error-${++nextErrorId}`;
      state.error.className = 'mt-1 text-xs text-red-600';
      state.error.setAttribute('role', 'alert');
      (field.closest('label') || field).after(state.error);
      state.wasInvalid = field.getAttribute('aria-invalid');
    }
    const descriptions = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    descriptions.add(state.error.id);
    field.setAttribute('aria-describedby', [...descriptions].join(' '));
    field.setAttribute('aria-invalid', 'true');
    field.dataset.noteInvalid = '';
    if (state.error.textContent !== message) state.error.textContent = message;
  }

  function clearError(field) {
    const state = states.get(field);
    if (!state?.error) return;
    const descriptions = (field.getAttribute('aria-describedby') || '').split(/\s+/)
      .filter(id => id && id !== state.error.id);
    if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
    else field.removeAttribute('aria-describedby');
    if (state.wasInvalid === null) field.removeAttribute('aria-invalid');
    else field.setAttribute('aria-invalid', state.wasInvalid);
    delete field.dataset.noteInvalid;
    state.error.remove();
    state.error = null;
  }

  function allowsEdit(previous, next) {
    return !tooLong(next) || length(next) < length(previous);
  }

  function rejectInsertion(event, field, text, message) {
    if (!event.cancelable || text == null) return;
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? start;
    const next = field.value.slice(0, start) + text + field.value.slice(end);
    if (allowsEdit(field.value, next)) return;
    event.preventDefault();
    showError(field, message);
  }

  function onField(event, handle) {
    const field = event.target;
    if (field.matches?.(fieldSelector) && editable(field)) handle(field);
  }

  document.addEventListener('focusin', event => onField(event, field => {
    const state = stateFor(field);
    if (tooLong(field.value)) {
      if (field.getAttribute('aria-invalid') !== 'true') showError(field, shortenMessage);
    } else if (state.previous.value !== field.value) clearError(field);
    state.previous = snapshot(field);
  }), true);

  document.addEventListener('beforeinput', event => onField(event, field => {
    stateFor(field).previous = snapshot(field);
    // Replacement and drop can target a range other than selectionStart/End.
    // The input fallback checks the actual result before form listeners run.
    if (event.inputType === 'insertText' || event.inputType === 'insertLineBreak') {
      rejectInsertion(event, field, event.inputType === 'insertLineBreak' ? '\n' : event.data, limitMessage);
    }
  }), true);

  document.addEventListener('paste', event => onField(event, field => {
    stateFor(field).previous = snapshot(field);
    const text = event.clipboardData?.getData('text/plain');
    const normalized = field.tagName === 'INPUT' ? text?.replace(/[\r\n]/g, '') : text?.replace(/\r\n?/g, '\n');
    rejectInsertion(event, field, normalized, `${limitMessage} Shorten the text and try again.`);
  }), true);

  document.addEventListener('drop', event => onField(event, field => {
    stateFor(field).previous = snapshot(field);
  }), true);

  document.addEventListener('input', event => onField(event, field => {
    const state = stateFor(field);
    const previous = state.previous;
    if (!allowsEdit(previous.value, field.value)) {
      field.value = previous.value;
      field.setSelectionRange(previous.start, previous.end, previous.direction);
      event.stopImmediatePropagation();
      showError(field, event.inputType === 'insertFromPaste'
        ? `${limitMessage} Shorten the text and try again.` : limitMessage);
      return;
    }
    state.previous = snapshot(field);
    if (tooLong(field.value)) showError(field, shortenMessage);
    else clearError(field);
  }), true);

  function validate(field, original) {
    if (!field || !editable(field) || !tooLong(field.value) || unchangedLegacy(field.value, original)) return true;
    showError(field, shortenMessage);
    field.focus();
    return false;
  }

  function validateAll(scope) {
    return !scope || [...scope.querySelectorAll(fieldSelector)].every(field => validate(field));
  }

  function reset(scope) {
    if (!scope) return;
    if (scope.matches(fieldSelector)) clearError(scope);
    else scope.querySelectorAll(fieldSelector).forEach(clearError);
  }

  document.addEventListener('reset', event => reset(event.target), true);

  document.addEventListener('submit', event => {
    if (event.target.matches?.('[data-check-notes]') && !validateAll(event.target)) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  document.addEventListener('click', event => {
    const action = event.target.closest?.('[data-check-note]');
    if (!action) return;
    const valid = action.dataset.checkNote.split(' ').every(id => {
      const field = document.getElementById(id);
      return !field?.getClientRects().length || validate(field);
    });
    if (!valid) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, true);

  globalThis.ShortNotes = { maxLength, limitMessage, tooLong, unchangedLegacy, shortenMessage, validate, reset };
})();
