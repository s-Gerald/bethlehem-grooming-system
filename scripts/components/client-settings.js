// Connected to pages/client/settings.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", async () => {
  if (!(API.waitForCustomerSession ? await API.waitForCustomerSession() : API.hasAuthenticatedSession("customer"))) {
    API.redirectToSignIn();
    return;
  }

  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const settingsContent = document.getElementById("settingsContent");
  const loadingScreen = document.getElementById("settingsLoadingScreen");
  const loadingSpinner = document.getElementById("settingsLoadingSpinner");
  const loadingMessage = document.getElementById("settingsLoadingMessage");
  const loadingRetry = document.getElementById("settingsLoadingRetry");
  const sidebarToggle = document.getElementById("clientSidebarToggle");
  const sidebarClose = document.getElementById("clientSidebarClose");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebar = document.getElementById("clientSidebar");
  const profileName = document.getElementById("clientProfileName");
  const profileInitials = document.getElementById("clientProfileInitials");
  const logoutBtn = document.getElementById("clientLogoutBtn");

  const statusBox = document.getElementById("settingsStatus");
  const passwordForm = document.getElementById("passwordForm");
  const currentPasswordInput = document.getElementById('currentPassword');
  const passwordSubmit = document.getElementById('changePasswordSubmit');
  const passwordLabel = document.getElementById('changePasswordLabel');
  const passwordSpinner = document.getElementById('changePasswordSpinner');
  let changingPassword = false;
  const passwordBindings = {};
  const newPasswordInput = document.getElementById("newPassword");
  const confirmPasswordInput = document.getElementById("confirmNewPassword");

  const firstNameValue = document.getElementById("settingsFirstName");
  const lastNameValue = document.getElementById("settingsLastName");
  const phoneInput = document.getElementById("settingsPhone");
  const phoneAction = document.getElementById("settingsPhoneAction");
  const phoneCancel = document.getElementById("settingsPhoneCancel");
  const emailInput = document.getElementById("settingsEmail");
  const emailAction = document.getElementById('settingsEmailAction');
  const emailCancel = document.getElementById('settingsEmailCancel');
  const emailPending = document.getElementById('settingsEmailPending');
  const accountInitials = document.getElementById("accountInitials");
  const accountDisplayName = document.getElementById("accountDisplayName");
  const accountMeta = document.getElementById("accountMeta");

  let serverUser = null;
  let profileLoadPromise = null;
  let emailChallenge, passwordChallenge, emailStateLoaded = false, emailStateRequest;
  const confirmation = window.createCustomerSettingsConfirmation({
    onChallenge(kind, challenge) {
      if (kind === 'email') setEmailChallenge(challenge);
      else passwordChallenge = challenge;
    },
    resetPassword: resetPasswordInput,
  });

  function setEmailChallenge(challenge) {
    emailChallenge = challenge;
    emailPending.hidden = !challenge;
    emailAction.textContent = challenge ? 'Continue' : 'Change';
  }

  function loadEmailChallenge() {
    if (!emailStateRequest) {
      emailStateRequest = API.getCustomerSecurityChange('email').then(({ challenge }) => {
        emailStateLoaded = true;
        setEmailChallenge(challenge);
      }).finally(() => { emailStateRequest = null; });
    }
    return emailStateRequest;
  }

  emailAction.addEventListener('click', async () => {
    if (!serverUser || emailAction.disabled) return;
    hideStatus();
    if (!emailStateLoaded || (emailChallenge && Date.parse(emailChallenge.expires_at) <= Date.now())) {
      emailAction.disabled = true;
      try { await loadEmailChallenge(); }
      catch (error) { showStatus(error.message || 'Unable to load email verification.', 'error'); return; }
      finally { emailAction.disabled = false; }
    }
    confirmation.open('email', emailAction, { currentEmail: serverUser.email || '', challenge: emailChallenge });
  });

  emailCancel.addEventListener('click', async () => {
    if (!emailChallenge || emailCancel.disabled) return;
    emailCancel.disabled = emailAction.disabled = true;
    hideStatus();
    try {
      await API.cancelCustomerSecurityChange('email', emailChallenge.id);
      setEmailChallenge(null);
    } catch (error) {
      if (error.code === 'invalid') setEmailChallenge(null);
      else showStatus(error.message || 'Unable to cancel email verification.', 'error');
    } finally {
      emailCancel.disabled = emailAction.disabled = false;
      emailAction.focus();
    }
  });

  window.addEventListener("customer-phone:verified", (event) => {
    serverUser = { ...serverUser, ...event.detail.user };
    applyUserToAccount(serverUser);
    showStatus(event.detail.message || "Phone number verified.", "success");
  });
  window.addEventListener('customer-profile:updated', event => {
    renderAccountProfile({ ...serverUser, ...event.detail.user });
    if (!emailStateLoaded) void loadEmailChallenge().catch(() => { /* Change retries the pending-state lookup. */ });
  });
  window.addEventListener("customer-phone:pending", () => {
    if (serverUser) { serverUser = { ...serverUser, phone_change_pending: true }; applyUserToAccount(serverUser); }
  });
  phoneAction?.addEventListener("click", async () => {
    if (!serverUser || phoneAction.disabled) return;
    phoneAction.disabled = true; hideStatus();
    try {
      const { openPhoneVerification } = await import("./customer-phone-verification.js?v=mobile-policy-20261110&urls=20261103");
      await openPhoneVerification(phoneAction, serverUser, { navigate: false });
    } catch (error) { showStatus(error.message || "Unable to open phone verification.", "error"); }
    finally { phoneAction.disabled = false; }
  });
  phoneCancel?.addEventListener("click", async () => {
    if (phoneCancel.disabled) return;
    phoneCancel.disabled = true; phoneAction.disabled = true; hideStatus();
    try {
      const { challenge } = await API.getPhoneVerificationState();
      if (challenge) await API.cancelPhoneVerification(challenge.challenge_id);
      serverUser = { ...serverUser, phone_change_pending: false }; applyUserToAccount(serverUser);
    } catch (error) { showStatus(error.message || "Unable to cancel phone change.", "error"); }
    finally { phoneCancel.disabled = false; phoneAction.disabled = false; }
  });

  function scheduleSettingsIdleTask(task) {
    if (typeof window.requestIdleCallback === "function") {
      window.requestIdleCallback(task, { timeout: 800 });
      return;
    }

    window.setTimeout(task, 100);
  }

  setupSidebar();
  setupTabs();
  setupHeaderSearch();
  setupPasswordToggles();
  setupForms();
  window.addEventListener('pagehide', () => {
    for (const input of Object.values(passwordFields())) resetPasswordInput(input);
  });
  loadingRetry?.addEventListener("click", loadProfile);
  if (API.readCustomerCache("/me")) void loadProfile();
  else window.requestAnimationFrame(() => scheduleSettingsIdleTask(loadProfile));

  function setupSidebar() {
    if (!sidebarToggle || !sidebarClose || !sidebarBackdrop || !sidebar) return;

    const sidebarLinks = sidebar.querySelectorAll("a");

    function setSidebarState(isOpen) {
      document.body.classList.toggle("client-sidebar-open", isOpen);
      sidebarToggle.setAttribute("aria-expanded", String(isOpen));
      sidebarToggle.setAttribute(
        "aria-label",
        isOpen ? "Close navigation menu" : "Open navigation menu",
      );
    }

    function closeSidebar() {
      setSidebarState(false);
    }

    function toggleSidebar() {
      if (!mobileSidebarQuery.matches) return;
      const isOpen = document.body.classList.contains("client-sidebar-open");
      setSidebarState(!isOpen);
    }

    setSidebarState(false);
    sidebarToggle.addEventListener("click", toggleSidebar);
    sidebarClose.addEventListener("click", closeSidebar);
    sidebarBackdrop.addEventListener("click", closeSidebar);
    sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeSidebar();
    });
    mobileSidebarQuery.addEventListener("change", (event) => {
      if (!event.matches) closeSidebar();
    });
  }

  function setupTabs() {
    const tabs = document.querySelectorAll("[data-settings-tab]");
    const panels = document.querySelectorAll("[data-settings-panel]");

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => {
        const target = tab.dataset.settingsTab;
        hideStatus();

        tabs.forEach((item) => {
          const isActive = item.dataset.settingsTab === target;
          item.setAttribute("aria-selected", String(isActive));
          item.classList.toggle("bg-portal-primary", isActive);
          item.classList.toggle("text-white", isActive);
          item.classList.toggle("shadow-sm", isActive);
          item.classList.toggle("text-portal-text", !isActive);
          item.classList.toggle("hover:bg-portal-active", !isActive);
        });

        panels.forEach((panel) => {
          panel.classList.toggle("hidden", panel.dataset.settingsPanel !== target);
        });
      });
    });
  }

  function setupHeaderSearch() {
    const search = document.getElementById("settingsSearch");
    if (!search) return;

    search.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;
      event.preventDefault();

      const query = search.value.trim().toLowerCase();
      if (!query) return;
      const matches = document.querySelectorAll(
        "[data-settings-tab], [data-settings-panel] h3, [data-settings-panel] label, [data-settings-panel] dt",
      );
      const match = Array.from(matches).find((item) =>
        item.textContent.trim().toLowerCase().includes(query),
      );

      if (!match) {
        search.setCustomValidity("No matching account or security setting was found.");
        search.reportValidity();
        search.setCustomValidity("");
        return;
      }

      const section = match.closest("[data-settings-panel]")?.dataset.settingsPanel
        || match.dataset.settingsTab;
      document.querySelector(`[data-settings-tab="${section}"]`)?.click();
      match.scrollIntoView({ behavior: "smooth", block: "center" });
      if (match instanceof HTMLLabelElement && match.htmlFor) {
        document.getElementById(match.htmlFor)?.focus({ preventScroll: true });
      }
    });
  }

  function setupPasswordToggles() {
    document.querySelectorAll("[data-password-toggle]").forEach((button) => {
      button.addEventListener("click", () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;

        setPasswordVisibility(input, button, input.type === 'password');
      });
    });
  }

  function setPasswordVisibility(input, button, visible) {
    input.type = visible ? 'text' : 'password';
    const label = visible ? 'Hide password' : 'Show password';
    button?.setAttribute('aria-label', label);
    button?.setAttribute('title', label);
    button?.querySelector('.password-icon-closed')?.classList.toggle('hidden', visible);
    button?.querySelector('.password-icon-open')?.classList.toggle('hidden', !visible);
  }

  function resetPasswordInput(input) {
    input.value = '';
    setPasswordVisibility(input, document.querySelector('[data-password-toggle="' + input.id + '"]'), false);
  }

  function setupForms() {
    logoutBtn?.addEventListener("click", handleLogout);
    for (const [field, input] of Object.entries(passwordFields())) {
      passwordBindings[field] = CredentialPolicy.bind(input, {
        feedback: document.getElementById(`${input.id}Error`),
        validate: () => passwordFieldMessage(field),
      });
    }
    newPasswordInput.addEventListener('input', () => passwordBindings.password_confirmation.refresh());
    currentPasswordInput.addEventListener('input', () => passwordBindings.password.refresh());

    passwordForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      await validatePasswordForm();
    });

  }

  function loadProfile() {
    if (serverUser) return Promise.resolve(serverUser);
    if (profileLoadPromise) return profileLoadPromise;

    loadingScreen?.setAttribute("role", "status");
    if (loadingMessage) loadingMessage.textContent = "Loading settings...";
    loadingSpinner?.classList.remove("hidden");
    loadingRetry?.classList.add("hidden");

    profileLoadPromise = (async () => {
      try {
        await API.loadCustomerData("/me", () => API.getMe("customer", { force: true }), ({ user }) => {
          if (!user) throw new Error("Account profile is unavailable.");
          renderAccountProfile(user);
        }, { force: true });
        void loadEmailChallenge().catch(() => { /* Change retries the pending-state lookup. */ });
        return serverUser;
      } catch (error) {
        if (API.isAuthenticationError(error) || !API.hasAuthenticatedSession("customer")) {
          API.redirectToSignIn();
          return null;
        }
        if (serverUser) return serverUser;

        loadingScreen?.setAttribute("role", "alert");
        if (loadingMessage) loadingMessage.textContent = "Unable to load settings right now. Please try again.";
        loadingSpinner?.classList.add("hidden");
        loadingRetry?.classList.remove("hidden");
        return null;
      } finally {
        profileLoadPromise = null;
      }
    })();

    return profileLoadPromise;
  }

  function renderAccountProfile(user) {
    if (!serverUser) emailAction.disabled = false;
    serverUser = user;
    applyUserToAccount(user);
    settingsContent?.removeAttribute("inert");
    settingsContent?.removeAttribute("aria-hidden");
    settingsContent?.classList.remove("hidden");
    loadingScreen?.classList.add("hidden");
  }

  function applyUserToAccount(user) {
    firstNameValue.textContent = user.first_name || "Not provided";
    lastNameValue.textContent = user.last_name || "Not provided";
    phoneInput.value = formatMobileNumber(user.phone) || "Not provided";
    const phoneStatus = document.getElementById("settingsPhoneStatus");
    if (phoneStatus) {
      const hasPhone = Boolean(String(user.phone ?? "").trim());
      const verified = hasPhone && user.phone_verification_status === "verified";
      const pending = verified && user.phone_change_pending === true;
      phoneStatus.textContent = pending ? "Pending" : !hasPhone ? "Not added" : verified ? "Verified" : "Not verified";
      phoneStatus.classList.toggle("bg-green-50", verified && !pending);
      phoneStatus.classList.toggle("text-green-700", verified && !pending);
      phoneStatus.classList.toggle("bg-portal-surface-soft", !verified || pending);
      phoneStatus.classList.toggle("text-portal-muted", !verified || pending);
      if (phoneAction) phoneAction.textContent = pending ? "Continue verification" : !hasPhone ? "Add" : verified ? "Change" : "Verify";
      if (phoneCancel) phoneCancel.hidden = !pending;
    }
    emailInput.value = user.email || "Not provided";

    updateVisibleProfile(user);
  }

  function formatMobileNumber(value) {
    const text = String(value ?? "").trim();
    if (!text || text === "—" || text === "Not provided") return "";
    return PhilippineMobile.format(text);
  }

  function updateVisibleProfile(user) {
    const firstName = user.first_name || "";
    const lastName = user.last_name || "";
    const fullName = `${firstName} ${lastName}`.trim() || user.username || "Customer";
    const initials = getInitials(firstName, lastName, user.username);
    const joinedLabel = formatJoinedDate(user.created_at || user.joined_at);

    if (profileName) profileName.textContent = fullName;
    if (profileInitials) profileInitials.textContent = initials;
    if (accountInitials) accountInitials.textContent = initials;
    if (accountDisplayName) accountDisplayName.textContent = fullName;
    if (accountMeta) {
      accountMeta.textContent = joinedLabel ? `Pet Owner - Joined ${joinedLabel}` : "Pet Owner";
    }
  }

  function passwordFields() {
    return { current_password: currentPasswordInput, password: newPasswordInput, password_confirmation: confirmPasswordInput };
  }

  function passwordFieldMessage(field) {
    if (field === 'current_password') return currentPasswordInput.value ? '' : 'Enter your current password.';
    if (field === 'password_confirmation') return CredentialPolicy.confirmation(confirmPasswordInput.value, newPasswordInput.value);
    return CredentialPolicy.password(newPasswordInput.value)
      || (newPasswordInput.value === currentPasswordInput.value ? 'Choose a different password.' : '');
  }
  function setPasswordFieldError(field, message = '') {
    passwordBindings[field].setError(message);
  }

  async function validatePasswordForm() {
    if (changingPassword) return;
    hideStatus();
    if (passwordChallenge && Date.parse(passwordChallenge.expires_at) > Date.now()) {
      confirmation.open('password', passwordSubmit, { challenge: passwordChallenge });
      return;
    }
    passwordChallenge = null;
    const fields = passwordFields();
    for (const field of Object.keys(fields)) setPasswordFieldError(field);
    let first;
    for (const [field, binding] of Object.entries(passwordBindings)) {
      if (!binding.validate()) first ||= fields[field];
    }
    if (first) { first.focus(); return; }

    changingPassword = true;
    passwordForm.setAttribute('aria-busy', 'true');
    passwordSubmit.disabled = true;
    passwordSubmit.setAttribute('aria-busy', 'true');
    passwordLabel.textContent = 'Requesting code…';
    passwordSpinner.classList.remove('hidden');
    for (const input of Object.values(fields)) input.readOnly = true;
    try {
      const result = await API.requestCustomerPasswordChange({ current_password: currentPasswordInput.value,
        password: newPasswordInput.value, password_confirmation: confirmPasswordInput.value });
      if (!result.challenge?.id) throw new Error('Unable to request a verification code.');
      passwordChallenge = result.challenge;
      for (const input of Object.values(fields)) resetPasswordInput(input);
      confirmation.open('password', passwordSubmit, { challenge: passwordChallenge });
    } catch (error) {
      let first;
      for (const [field, messages] of Object.entries(error.errors || {})) {
        if (!fields[field]) continue;
        setPasswordFieldError(field, [].concat(messages)[0]); first ||= fields[field];
      }
      if (first) first.focus();
      else if (error.status === 429) showStatus(`Too many attempts. Try again in ${error.retryAfter || 60} seconds.`, 'error');
      else if (error.code === 'password_screening_unavailable') showStatus('Password safety checking is temporarily unavailable. Try again later.', 'error');
      else if (error.status === 401 || error.status === 403) API.redirectToSignIn();
      else if (!error.status || error.status >= 500) showStatus('Unable to request a verification code. Try again.', 'error');
      else showStatus(error.message || 'Unable to change your password. Please try again.', 'error');
    } finally {
      changingPassword = false;
      passwordForm.setAttribute('aria-busy', 'false');
      for (const input of Object.values(fields)) input.readOnly = false;
      passwordSubmit.disabled = false;
      passwordSubmit.setAttribute('aria-busy', 'false');
      passwordLabel.textContent = 'Change Password';
      passwordSpinner.classList.add('hidden');
    }
  }

  async function handleLogout() {
    try {
      await API.logout("customer");
    } finally {
      API.redirectToSignIn({ replace: true });
    }
  }

  function getInitials(firstName, lastName, username) {
    const initials = `${firstName[0] || ""}${lastName[0] || ""}`.toUpperCase();
    return initials || (username || "CU").slice(0, 2).toUpperCase();
  }

  function formatJoinedDate(value) {
    if (!value) return "";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";

    return date.toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function showStatus(message, type) {
    if (!statusBox) return;

    statusBox.textContent = message;
    statusBox.className = "rounded-xl border px-4 py-3 text-sm font-medium";

    if (type === "error") {
      statusBox.classList.add("border-red-200", "bg-red-50", "text-red-700");
    } else if (type === "info") {
      statusBox.classList.add("border-portal-border", "bg-portal-surface-soft", "text-portal-text");
    } else {
      statusBox.classList.add("border-green-200", "bg-green-50", "text-green-700");
    }

    statusBox.classList.remove("hidden");
  }

  function hideStatus() {
    statusBox?.classList.add("hidden");
  }
});
