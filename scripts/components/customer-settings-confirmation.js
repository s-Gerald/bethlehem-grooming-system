// The three Settings confirmations share one dialog and one verification input.
window.createCustomerSettingsConfirmation = function ({ onChallenge, resetPassword }) {
  const get = (id) => document.getElementById(id);
  const dialog = get('settingsConfirmation');
  const form = get('settingsConfirmationForm');
  const title = get('settingsConfirmationTitle');
  const close = get('settingsConfirmationClose');
  const cancel = get('settingsConfirmationCancel');
  const submit = get('settingsConfirmationSubmit');
  const newEmail = get('settingsNewEmail');
  const password = get('settingsConfirmationPassword');
  const code = get('settingsVerificationCode');
  const instruction = get('settingsVerificationInstruction');
  const delivery = get('settingsVerificationDelivery');
  const resend = get('settingsVerificationResend');
  let view, busy = false, countdown, stopDelivery, resendAt = 0;

  function feedback(message = '', field) {
    const error = get(field ? field.id + 'Error' : 'settingsConfirmationError');
    error.textContent = message;
    error.hidden = !message;
    field?.setAttribute('aria-invalid', String(Boolean(message)));
    field?.classList.toggle('!border-red-400', Boolean(message));
  }

  const digits = window.VerificationCode.bind(code, dialog.querySelectorAll('.verification-code-cell'), () => {
    feedback('', code);
    updateActions();
  });
  for (const field of [newEmail, password]) field.addEventListener('input', () => {
    feedback('', field);
    feedback();
  });

  function updateActions() {
    if (!view) return;
    const challenge = view.challenge;
    const expired = challenge && Date.parse(challenge.expires_at) <= Date.now();
    const locked = challenge?.attempts_remaining === 0;
    const seconds = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
    const limited = challenge?.resends_remaining === 0;
    if (expired && !view.ended) {
      view.ended = true;
      feedback('This request has expired. Start a new request.', code);
      onChallenge(view.kind, null);
    }
    const label = challenge
      ? (view.kind === 'email' ? 'Verify email' : 'Confirm change')
      : 'Continue';
    submit.textContent = busy ? 'Please wait…' : label;
    submit.disabled = busy || view.ended || Boolean(challenge && (expired || locked || code.value.length !== 6));
    close.disabled = cancel.disabled = busy;
    newEmail.disabled = password.disabled = busy;
    code.disabled = busy || view.ended || locked;
    resend.disabled = busy || view.ended || Boolean(expired || locked || limited || seconds);
    const resendLabel = limited ? 'Resend limit reached'
      : seconds ? 'Resend code in ' + window.VerificationCode.formatCountdown(seconds) : 'Resend code';
    if (resend.textContent !== resendLabel) resend.textContent = resendLabel;
  }

  function setBusy(value) {
    busy = value;
    form.setAttribute('aria-busy', String(value));
    updateActions();
  }

  function stopWatching() {
    window.clearInterval(countdown);
    stopDelivery?.();
    stopDelivery = null;
  }

  function render() {
    stopWatching();
    const { kind, challenge, currentEmail } = view;
    title.textContent = kind === 'password' ? 'Verify password change'
      : challenge ? 'Verify new email' : 'Change email address';
    get('settingsEmailFields').hidden = kind !== 'email' || Boolean(challenge);
    get('settingsConfirmationPasswordArea').hidden = Boolean(challenge);
    get('settingsVerificationFields').hidden = !challenge;
    get('settingsCurrentEmail').textContent = currentEmail || '';
    resetPassword(password);
    feedback();
    feedback('', newEmail);
    feedback('', password);
    delivery.hidden = true;
    delivery.textContent = '';
    resendAt = Date.now() + (challenge?.resend_after || 0) * 1000;
    digits.clear();
    if (challenge) {
      instruction.textContent = 'Enter the 6-digit code for ' + challenge.email + '.';
      if (challenge.attempts_remaining === 0) feedback('Too many incorrect codes. Try again later.', code);
      if (challenge.email_delivery_token) {
        stopDelivery = window.AuthenticationEmailDelivery.watch(challenge.email_delivery_token, (status) => {
          if (status === 'sent') instruction.textContent = 'Enter the 6-digit code sent to ' + challenge.email + '.';
          const message = window.AuthenticationEmailDelivery.failureMessage(status);
          delivery.textContent = message;
          delivery.hidden = !message;
        });
      }
      countdown = window.setInterval(updateActions, 1000);
    }
    updateActions();
    if (!dialog.open) dialog.showModal();
    (challenge ? code : kind === 'email' ? newEmail : password).focus();
  }

  function dismiss() {
    if (busy || !view) return;
    const trigger = view.trigger;
    stopWatching();
    view = null;
    resetPassword(password);
    newEmail.value = '';
    digits.clear();
    dialog.close();
    trigger.focus();
  }

  function challengeError(error, field) {
    if (error.status === 401 || error.status === 403) {
      API.redirectToSignIn();
      return;
    }
    if (error.retryAfter && view.challenge) resendAt = Date.now() + error.retryAfter * 1000;
    if (error.code === 'locked' && view.challenge) view.challenge.attempts_remaining = 0;
    if (error.code === 'invalid' && view.challenge) {
      view.ended = true;
      onChallenge(view.kind, null);
    }
    feedback(error.errors?.code?.[0] || error.errors?.email?.[0] || error.message || 'Please try again.', field);
  }

  function setChallenge(challenge) {
    if (!challenge?.id) throw new Error('Unable to load the verification request. Please try again.');
    view.challenge = challenge;
    onChallenge(view.kind, challenge);
    newEmail.value = '';
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!view || busy || view.ended) return;
    const { kind, challenge, currentEmail } = view;
    const submittedView = view;
    if (challenge && (code.value.length !== 6 || challenge.attempts_remaining === 0)) return;
    const email = kind === 'email' && !challenge ? AccountEmail.normalize(newEmail.value) : '';
    if (kind === 'email' && !challenge) {
      const message = AccountEmail.validate(email) ||
        (email.toLowerCase() === currentEmail.toLowerCase() ? 'Enter a different email address.' : '');
      if (message) { feedback(message, newEmail); newEmail.focus(); return; }
    }
    if (!challenge && !password.value) {
      feedback('Enter your current password.', password);
      password.focus();
      return;
    }

    feedback();
    setBusy(true);
    let after, focus;
    try {
      if (challenge) {
        await API.confirmCustomerSecurityChange(kind, challenge.id, code.value);
        if (view !== submittedView) return;
        resetPassword(password);
        digits.clear();
        stopWatching();
        API.redirectToSignIn({ replace: true, passwordChanged: kind === 'password' });
      } else {
        const result = await API.requestCustomerEmailChange(email, password.value);
        if (view !== submittedView) return;
        setChallenge(result.challenge);
        after = render;
      }
    } catch (error) {
      if (view !== submittedView) return;
      const errors = error.errors || {};
      if (errors.current_password) {
        feedback(errors.current_password[0], password);
        focus = password;
      } else if (!challenge && errors.email) {
        feedback(errors.email[0], newEmail);
        focus = newEmail;
      } else {
        challengeError(error, challenge ? code : undefined);
        if (challenge) focus = code;
      }
    } finally {
      if (view === submittedView) {
        setBusy(false);
        after?.();
        focus?.focus();
      }
    }
  });

  resend.addEventListener('click', async () => {
    if (resend.disabled || busy || !view?.challenge) return;
    const submittedView = view;
    setBusy(true);
    let issued = false;
    try {
      const { challenge } = await API.resendCustomerSecurityChange(view.kind, view.challenge.id);
      if (view !== submittedView) return;
      setChallenge(challenge);
      issued = true;
    } catch (error) {
      if (view === submittedView) challengeError(error, code);
    } finally {
      if (view === submittedView) {
        setBusy(false);
        if (issued) render();
        else code.focus();
      }
    }
  });

  cancel.addEventListener('click', async () => {
    if (busy || !view) return;
    if (!view.challenge || view.ended) { dismiss(); return; }
    const submittedView = view;
    setBusy(true);
    try {
      await API.cancelCustomerSecurityChange(view.kind, view.challenge.id);
      if (view !== submittedView) return;
      onChallenge(view.kind, null);
      view.ended = true;
    } catch (error) {
      if (view === submittedView) challengeError(error);
    } finally {
      if (view === submittedView) {
        setBusy(false);
        if (view.ended) dismiss();
        else cancel.focus();
      }
    }
  });

  close.addEventListener('click', () => dismiss());
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); dismiss(); });
  window.addEventListener('pagehide', () => {
    stopWatching();
    resetPassword(password);
    newEmail.value = '';
    digits.clear();
    view = null;
    busy = false;
    if (dialog.open) dialog.close();
  });

  return {
    open(kind, trigger, options = {}) {
      if (dialog.open || busy) return;
      view = { kind, trigger, ...options };
      render();
    },
  };
};
