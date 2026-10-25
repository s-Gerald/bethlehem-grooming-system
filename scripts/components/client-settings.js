// Connected to pages/client/settings.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", () => {
  if (!API.hasAuthenticatedSession("customer")) {
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
  const newPasswordInput = document.getElementById("newPassword");
  const confirmPasswordInput = document.getElementById("confirmNewPassword");
  const passwordStrength = document.getElementById("passwordStrength");
  const confirmPasswordFeedback = document.getElementById("confirmPasswordFeedback");

  const firstNameInput = document.getElementById("settingsFirstName");
  const lastNameInput = document.getElementById("settingsLastName");
  const phoneInput = document.getElementById("settingsPhone");
  const phoneAction = document.getElementById("settingsPhoneAction");
  const phoneCancel = document.getElementById("settingsPhoneCancel");
  const emailInput = document.getElementById("settingsEmail");
  const accountInitials = document.getElementById("accountInitials");
  const accountDisplayName = document.getElementById("accountDisplayName");
  const accountMeta = document.getElementById("accountMeta");

  let serverUser = null;
  let profileLoadPromise = null;
  window.addEventListener("customer-phone:verified", (event) => {
    serverUser = event.detail.user;
    applyUserToAccount(serverUser);
    showStatus(event.detail.message || "Phone number verified.", "success");
  });
  window.addEventListener("customer-phone:pending", () => {
    if (serverUser) { serverUser = { ...serverUser, phone_change_pending: true }; applyUserToAccount(serverUser); }
  });
  phoneAction?.addEventListener("click", async () => {
    if (!serverUser || phoneAction.disabled) return;
    phoneAction.disabled = true; hideStatus();
    try {
      const { openPhoneVerification } = await import("./customer-phone-verification.js?v=20261026-otp");
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
        "[data-settings-tab], [data-settings-panel] h3, [data-settings-panel] label",
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

        const shouldShow = input.type === "password";
        input.type = shouldShow ? "text" : "password";
        button.setAttribute("aria-label", shouldShow ? "Hide password" : "Show password");
        button.setAttribute("title", shouldShow ? "Hide password" : "Show password");

        const closedIcon = button.querySelector(".password-icon-closed");
        const openIcon = button.querySelector(".password-icon-open");
        closedIcon?.classList.toggle("hidden", shouldShow);
        openIcon?.classList.toggle("hidden", !shouldShow);
      });
    });
  }

  function setupForms() {
    logoutBtn?.addEventListener("click", handleLogout);
    newPasswordInput?.addEventListener("input", () => {
      updatePasswordStrength();
      updateConfirmationFeedback();
    });
    confirmPasswordInput?.addEventListener("input", updateConfirmationFeedback);

    passwordForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      validatePasswordForm();
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
        await API.loadCustomerData("/me", () => API.getMe("customer"), ({ user }) => {
          if (!user) throw new Error("Account profile is unavailable.");
          applyUserToAccount(user);
          serverUser = user;
          settingsContent?.removeAttribute("inert");
          settingsContent?.removeAttribute("aria-hidden");
          settingsContent?.classList.remove("hidden");
          loadingScreen?.classList.add("hidden");
        });
        return serverUser;
      } catch (error) {
        if (API.isAuthenticationError(error) || !API.hasAuthenticatedSession("customer")) {
          API.redirectToSignIn();
          return null;
        }

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

  function applyUserToAccount(user) {
    firstNameInput.value = user.first_name || "";
    lastNameInput.value = user.last_name || "";
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

    const digits = text.replace(/\D/g, "");
    const localDigits = digits.startsWith("639") && digits.length === 12
      ? `0${digits.slice(2)}`
      : digits;

    if (localDigits.length === 11) {
      return `${localDigits.slice(0, 4)}-${localDigits.slice(4, 7)}-${localDigits.slice(7)}`;
    }

    return text;
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

  function validatePasswordForm() {
    const currentPassword = document.getElementById("currentPassword").value;
    const newPassword = document.getElementById("newPassword").value;
    const confirmNewPassword = document.getElementById("confirmNewPassword").value;

    if (!currentPassword || !newPassword || !confirmNewPassword) {
      showStatus("Please complete all password fields.", "error");
      return;
    }

    if (!Object.values(getPasswordRules(newPassword)).every(Boolean)) {
      showStatus("Please meet every new password requirement.", "error");
      updatePasswordStrength();
      return;
    }

    if (newPassword === currentPassword) {
      showStatus("New password must be different from the current password.", "error");
      return;
    }

    if (newPassword !== confirmNewPassword) {
      showStatus("New password and confirmation do not match.", "error");
      return;
    }

    // BACKEND: Send current_password, password, and password_confirmation here.
    showStatus("Your password meets the requirements. No password change was made.", "info");
  }

  function getPasswordRules(value) {
    return {
      length: value.length >= 12,
      uppercase: /[A-Z]/.test(value),
      lowercase: /[a-z]/.test(value),
      special: /[^A-Za-z0-9\s]/.test(value),
      number: /\d/.test(value),
    };
  }

  function updatePasswordStrength() {
    if (!passwordStrength || !newPasswordInput) return;

    const value = newPasswordInput.value;
    const rules = getPasswordRules(value);
    const complete = Object.values(rules).every(Boolean);

    document.querySelectorAll("[data-password-rule]").forEach((item) => {
      const satisfied = Boolean(value && rules[item.dataset.passwordRule]);
      item.classList.toggle("text-green-700", satisfied);
      item.classList.toggle("text-portal-muted", !satisfied);
    });

    newPasswordInput.classList.toggle("border-green-500", Boolean(value && complete));
    newPasswordInput.classList.toggle("border-red-400", Boolean(value && !complete));
    newPasswordInput.classList.toggle("border-portal-border", !value);
    newPasswordInput.setAttribute("aria-invalid", String(Boolean(value && !complete)));

    passwordStrength.textContent = !value
      ? "Please add all necessary characters to create a safe password."
      : complete
        ? "Your new password meets all requirements."
        : "Please add all necessary characters to create a safe password.";
    passwordStrength.classList.toggle("text-green-700", Boolean(value && complete));
    passwordStrength.classList.toggle("text-red-700", Boolean(value && !complete));
    passwordStrength.classList.toggle("text-portal-muted", !value);
  }

  function updateConfirmationFeedback() {
    if (!confirmPasswordInput || !confirmPasswordFeedback) return;

    const value = confirmPasswordInput.value;
    const matches = value === newPasswordInput.value;
    confirmPasswordFeedback.textContent = !value
      ? ""
      : matches
        ? "Passwords match."
        : "Passwords do not match.";
    confirmPasswordFeedback.classList.toggle("text-green-700", Boolean(value && matches));
    confirmPasswordFeedback.classList.toggle("text-red-700", Boolean(value && !matches));
    confirmPasswordFeedback.classList.toggle("text-portal-muted", !value);
    confirmPasswordInput.classList.toggle("border-green-500", Boolean(value && matches));
    confirmPasswordInput.classList.toggle("border-red-400", Boolean(value && !matches));
    confirmPasswordInput.classList.toggle("border-portal-border", !value);
    confirmPasswordInput.setAttribute("aria-invalid", String(Boolean(value && !matches)));
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
