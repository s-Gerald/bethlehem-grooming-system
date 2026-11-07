document.addEventListener("DOMContentLoaded", async () => {
  const form = document.getElementById("setupPasswordForm");
  const expiredLink = document.getElementById("setupExpiredLink");
  const invalidLink = document.getElementById("setupInvalidLink");
  const expiredMessage = document.getElementById("setupExpiredLinkMessage");
  const requestNewLink = document.getElementById("requestNewSetupLink");
  const username = document.getElementById("setupUsername");
  const usernameMessage = document.getElementById("setupUsernameMessage");
  const password = document.getElementById("setupNewPassword");
  const confirmation = document.getElementById("setupConfirmPassword");
  const message = document.getElementById("setupPasswordMessage");
  const submit = document.getElementById("setupPasswordSubmit");
  const submitLabel = document.getElementById("setupPasswordSubmitLabel");
  const accountEmail = document.getElementById("setupAccountEmail");
  const accountDescription = document.getElementById("setupAccountDescription");

  if (!form || !expiredLink || !invalidLink || !username || !usernameMessage || !password || !confirmation || !message || !submit || !requestNewLink) return;

  installVisibilityToggle(
    "toggleSetupPassword",
    password,
    ".setup-password-icon-closed",
    ".setup-password-icon-open",
    "new password",
  );
  installVisibilityToggle(
    "toggleSetupConfirmation",
    confirmation,
    ".setup-confirmation-icon-closed",
    ".setup-confirmation-icon-open",
    "password confirmation",
  );

  const fragmentParams = new URLSearchParams(window.location.hash.slice(1));
  const setupToken = fragmentParams.get("token") || "";
  const cleanUrl = new URL(window.location.href);
  cleanUrl.hash = "";
  history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}`);

  if (!/^[A-Za-z0-9]{64}$/.test(setupToken)) {
    showInvalidLink();
    return;
  }

  try {
    const response = await API.verifyStaffPasswordSetupToken(setupToken);
    if (accountEmail && accountDescription && response?.email) {
      accountEmail.textContent = response.email;
      accountDescription.classList.remove("hidden");
    }
    form.classList.remove("hidden");
    username.focus();
  } catch (error) {
    if (error?.expired) {
      showExpiredLink();
    } else {
      showInvalidLink();
    }
    return;
  }

  const credentialBindings = {
    username: CredentialPolicy.bind(username, { feedback: usernameMessage, validate: () => CredentialPolicy.username(username.value.trim(), false) }),
    password: CredentialPolicy.bind(password, { validate: () => CredentialPolicy.password(password.value, 15) }),
    password_confirmation: CredentialPolicy.bind(confirmation, { validate: () => CredentialPolicy.confirmation(confirmation.value, password.value) }),
  };
  form.noValidate = true;
  password.addEventListener('input', () => { setMessage(); credentialBindings.password_confirmation.refresh(); });
  confirmation.addEventListener('input', () => setMessage());
  submit.disabled = false;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (submit.dataset.busy === 'true') return;
    let first;
    const fields = { username, password, password_confirmation: confirmation };
    for (const [key, binding] of Object.entries(credentialBindings)) if (!binding.validate()) first ||= fields[key];
    if (first) { first.focus(); return; }

    setBusy(true);
    setMessage();
    try {
      const response = await API.completeStaffPasswordSetup(
        setupToken,
        password.value,
        confirmation.value,
        username.value.trim() || null,
      );
      if (!response.completed_setup || response.user?.role !== "staff") {
        throw new Error("This setup link has expired or is no longer valid.");
      }
      window.location.replace("/admin/dashboard");
    } catch (error) {
      let first;
      for (const [key, messages] of Object.entries(error?.errors || {})) {
        if (!credentialBindings[key]) continue;
        credentialBindings[key].setError(messages[0]); first ||= fields[key];
      }
      if (first) { setBusy(false); first.focus(); return; }
      if (error?.expired) {
        showExpiredLink();
        return;
      }
      if (/link|token/i.test(error?.message || "")) {
        showInvalidLink();
        return;
      }
      setMessage("error", firstApiError(error));
      setBusy(false);
    }
  });

  function setBusy(busy) {
    submit.dataset.busy = busy ? "true" : "false";
    submit.disabled = busy;
    if (submitLabel) submitLabel.textContent = busy ? "Creating Account..." : "Create Account";
  }

  requestNewLink.addEventListener("click", async () => {
    requestNewLink.disabled = true;
    requestNewLink.textContent = "Sending...";
    try {
      await API.requestNewStaffPasswordSetupLink(setupToken);
      expiredMessage.textContent = "A new setup link has been sent to your email.";
      expiredMessage.className = "rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700";
      requestNewLink.textContent = "Link Sent";
    } catch (error) {
      expiredMessage.textContent = firstApiError(error);
      expiredMessage.className = "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
      requestNewLink.disabled = false;
      requestNewLink.textContent = "Request New Setup Link";
    }
  });

  function showExpiredLink() {
    accountDescription?.classList.add("hidden");
    form.classList.add("hidden");
    username.disabled = true;
    password.disabled = true;
    confirmation.disabled = true;
    submit.disabled = true;
    invalidLink.classList.add("hidden");
    expiredLink.classList.remove("hidden");
  }

  function showInvalidLink() {
    accountDescription?.classList.add("hidden");
    form.classList.add("hidden");
    username.disabled = true;
    password.disabled = true;
    confirmation.disabled = true;
    submit.disabled = true;
    expiredLink.classList.add("hidden");
    invalidLink.classList.remove("hidden");
  }

  function firstApiError(error) {
    const firstMessages = Object.values(error?.errors || {})[0];
    return Array.isArray(firstMessages) && firstMessages[0]
      ? firstMessages[0]
      : error?.message || "The password could not be created.";
  }

  function setMessage(type = null, text = "") {
    if (!type) {
      message.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      message.textContent = "";
      return;
    }
    message.className = "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
    message.textContent = text;
  }

  function installVisibilityToggle(buttonId, input, closedSelector, openSelector, label) {
    const button = document.getElementById(buttonId);
    const closedIcon = document.querySelector(closedSelector);
    const openIcon = document.querySelector(openSelector);
    if (!button) return;

    button.addEventListener("click", () => {
      const show = input.type === "password";
      const nextLabel = `${show ? "Hide" : "Show"} ${label}`;
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", nextLabel);
      button.setAttribute("title", nextLabel);
      closedIcon?.classList.toggle("hidden", show);
      openIcon?.classList.toggle("hidden", !show);
    });
  }
});
