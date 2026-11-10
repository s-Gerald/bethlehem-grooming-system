document.addEventListener("alpine:init", () => {
  let resolveDialog = null;
  let toastTimer = null;
  let dialogTrigger = null;

  Alpine.store("clinicAccess", {
    permissions: {},
    user: null,
    ready: null,
    init() {
      return this.ready ??= this.load();
    },
    async load() {
      try {
        const response = await API.getStaffIdentity();
        this.user = response.user || null;
        this.permissions = response.user?.clinic_permissions || {};
      } catch {
        this.permissions = {};
      }
    },
  });

  Alpine.store("clinicConfinementClock", {
    now: Date.now(),
    init() { this.timer = setInterval(() => { this.now = Date.now(); }, 60000); },
  });

  Alpine.store("clinicFeedback", {
    dialog: { open: false, title: "", message: "", confirmLabel: "Confirm", cancelLabel: "Cancel", danger: false },
    toast: { show: false, message: "", variant: "success" },
    toastDuration: 3500,

    confirm({ title, message, confirmLabel = "Confirm", cancelLabel = "Cancel", danger = false, dismissOnEscape = true, requireReason = false }) {
      resolveDialog?.(false);
      dialogTrigger = requireReason ? document.activeElement : null;
      this.dialog = { open: true, title, message, confirmLabel, cancelLabel, danger, dismissOnEscape, requireReason, reason: '', reasonError: '' };
      return new Promise((resolve) => { resolveDialog = resolve; });
    },

    validateCorrectionReason(interaction = 'save') {
      const reason = String(this.dialog.reason ?? '');
      const message = !reason.trim() ? 'Enter a reason for correction.'
        : Array.from(reason).length > 200 ? 'Use up to 200 characters.' : '';
      if (interaction !== 'input' || (this.dialog.reasonError && !message)) this.dialog.reasonError = message;
      return !message;
    },

    trapCorrectionFocus(event) {
      if (!this.dialog.requireReason) return;
      const controls = [...event.currentTarget.querySelectorAll('button, textarea')].filter(el => !el.disabled && el.getClientRects().length);
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    },

    answer(confirmed) {
      if (confirmed && this.dialog.requireReason && !this.validateCorrectionReason()) {
        document.getElementById('clinic-confirm-reason')?.focus(); return;
      }
      this.dialog.open = false;
      resolveDialog?.(confirmed && this.dialog.requireReason ? {reason: this.dialog.reason.trim()} : confirmed);
      resolveDialog = null;
      const trigger = dialogTrigger;
      dialogTrigger = null;
      if (trigger) setTimeout(() => trigger.focus(), 0);
    },

    notify(message, variant = "success") {
      clearTimeout(toastTimer);
      // Keep legacy boolean callers, including cached components, compatible.
      if (typeof variant === "boolean") variant = variant ? "success" : "error";
      this.toast = { show: true, message, variant };
      toastTimer = setTimeout(() => { this.toast.show = false; }, this.toastDuration);
    },
  });
});

