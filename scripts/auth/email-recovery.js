document.addEventListener('DOMContentLoaded', async () => {
  let token = window.location.hash.slice(1);
  history.replaceState(null, '', window.location.pathname);
  const form = document.getElementById('emailRecoveryForm');
  const email = document.getElementById('recoveryEmail');
  const password = document.getElementById('recoveryPassword');
  const reveal = document.getElementById('recoveryReveal');
  const submit = document.getElementById('recoverySubmit');
  const message = document.getElementById('recoveryMessage');
  const error = document.getElementById('recoveryError');
  let busy = false;
  const hidePassword = () => {
    password.type = 'password';
    reveal.setAttribute('aria-label', 'Show current account password');
    reveal.setAttribute('aria-pressed', 'false');
    document.getElementById('recoveryEye').setAttribute('href', '../../assets/icons/phosphor.svg#eye-slash');
  };
  const unavailable = () => {
    token = ''; password.value = ''; hidePassword();
    form.classList.add('hidden');
    message.textContent = 'This recovery link is expired or unavailable. Contact the clinic administrator.';
    message.classList.remove('hidden');
  };
  if (!/^[a-f0-9]{64}$/.test(token)) { unavailable(); return; }
  try {
    const result = await API.verifyStaffEmailRecovery(token);
    email.value = result.email;
    message.classList.add('hidden');
    form.classList.remove('hidden');
    password.focus();
  } catch { unavailable(); return; }
  password.addEventListener('input', () => {
    error.classList.add('hidden'); password.setAttribute('aria-invalid', 'false');
    submit.disabled = busy || !password.value;
  });
  reveal.addEventListener('click', () => {
    if (busy) return;
    const showing = password.type === 'password';
    password.type = showing ? 'text' : 'password';
    reveal.setAttribute('aria-label', (showing ? 'Hide' : 'Show') + ' current account password');
    reveal.setAttribute('aria-pressed', String(showing));
    document.getElementById('recoveryEye').setAttribute('href', '../../assets/icons/phosphor.svg#' + (showing ? 'eye' : 'eye-slash'));
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !password.value || !token) return;
    busy = true; submit.disabled = true; password.disabled = true; reveal.disabled = true;
    submit.textContent = 'Confirming...'; error.classList.add('hidden');
    try {
      await API.completeStaffEmailRecovery(token, password.value);
      token = ''; form.classList.add('hidden'); email.value = '';
      const title = document.getElementById('recoveryTitle');
      title.textContent = 'Email Updated'; title.focus();
      message.textContent = 'Your email has been changed. Sign in to continue.';
      message.classList.remove('hidden');
    } catch (failure) {
      if (['invalid', 'expired', 'locked'].includes(failure.data?.status)) unavailable();
      else {
        error.textContent = failure.errors?.current_password?.[0] || failure.message || 'Recovery could not be completed. Please try again.';
        error.classList.remove('hidden'); password.setAttribute('aria-invalid', 'true');
      }
    } finally {
      password.value = ''; hidePassword(); busy = false; password.disabled = false; reveal.disabled = false;
      submit.textContent = 'Confirm Email Change'; submit.disabled = true;
      if (token) password.focus();
    }
  });
  window.addEventListener('pagehide', () => { token = ''; password.value = ''; hidePassword(); });
  window.addEventListener('pageshow', event => { if (event.persisted) unavailable(); });
});
