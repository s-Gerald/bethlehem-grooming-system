(function (global) {
  let dismissTimer;

  global.showToast = function showToast(message, variant = 'success', duration = 3500) {
    if (typeof variant === 'boolean') variant = variant ? 'success' : 'error';
    if (!['success', 'error', 'warning', 'info'].includes(variant)) variant = 'info';
    let toast = document.getElementById("success-toast");

    if (!toast) {
      toast = document.createElement("div");
      toast.id = "success-toast";
      toast.setAttribute('aria-atomic', 'true');
      toast.innerHTML = '<span class="app-toast__icon" aria-hidden="true"></span><span class="app-toast__message"></span><div class="app-toast__progress" aria-hidden="true"><div class="app-toast__progress-fill"></div></div>';
      document.body.appendChild(toast);
    }

    toast.className = `app-toast app-toast--${variant}`;
    toast.setAttribute('role', variant === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-live', variant === 'error' ? 'assertive' : 'polite');
    toast.querySelector('.app-toast__icon').textContent = { success: '✓', error: '!', warning: '!', info: 'i' }[variant];
    toast.querySelector(".app-toast__message").textContent = message;
    toast.classList.add("is-visible");
    const progress = toast.querySelector('.app-toast__progress-fill');
    progress.style.transitionDuration = '0ms';
    progress.style.width = '100%';
    void progress.offsetWidth;
    progress.style.transitionDuration = `${duration}ms`;
    progress.style.width = '0%';
    clearTimeout(dismissTimer);
    dismissTimer = setTimeout(() => toast.classList.remove("is-visible"), duration);
  };
  global.showSuccessToast = (message, duration = 3500) => global.showToast(message, 'success', duration);
})(window);
