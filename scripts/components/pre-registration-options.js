import { requireCustomerSession } from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3&registration=fcfs-20261027-v1&remember=20261029";
import { getPreRegistrationAccess, setPreRegistrationLinkAccess } from "../services/booking-form-access-guard.js?v=phone-20261022&registration=rolling-20261027-v1&registration-rollover=20261027-v3&service-access=20261031-v1";
import { loadRegistrationAvailability } from "../services/registration-service-day.js?v=rolling-20261027-v1&registration-rollover=20261027-v3";
import { clearGroomingDraft } from "../services/booking-draft-service.js?flow=grooming-order-20261016&registration=fcfs-20261027-v1";
import { bindPreRegistrationButton, setPhoneVerificationAccess } from "./customer-pre-registration-button.js?v=mobile-format-20261029&registration-ui=20261027-v3&auth-feedback=20261028-v3";

const services = {
  grooming: {
    title: "Grooming", icon: "scissors", href: "./grooming-pre-registration.html",
    description: "Register your pet and choose grooming services.",
    note: "Queue numbers are assigned at check-in. Online registration does not reserve a queue position or grooming cage.",
  },
  clinic: {
    title: "Clinic Visit", icon: "stethoscope", href: "./booking-pet-details.html?flow=clinic",
    description: "Register your pet for a veterinary consultation.",
    note: "Queue numbers are assigned at check-in.",
  },
};

export async function initPreRegistrationOptions() {
  const get = id => document.getElementById(id);
  const tabs = { grooming: get("groomingTab"), clinic: get("clinicTab") };
  const panel = get("serviceInformation");
  const button = get("serviceContinue");
  const retry = get("retryAvailability");
  const content = get("serviceContent");
  const skeleton = get("serviceSkeleton");
  const announcement = get("serviceLoadingAnnouncement");
  const availability = {};
  const serviceLoading = {};
  let selected = "grooming", access = null, accessFailed = false, accessLoading = false;
  let skeletonTimer = null, skeletonVisible = false, active = true;
  button.addEventListener("click", () => {
    if (button.getAttribute("aria-disabled") !== "true" && selected === "grooming" && sessionStorage.getItem("bookingConfirmation")) {
      clearGroomingDraft();
    }
  });
  bindPreRegistrationButton(button);

  function cancelSkeletonDelay() {
    if (skeletonTimer !== null) window.clearTimeout(skeletonTimer);
    skeletonTimer = null;
  }

  function animateCard() {
    panel.getAnimations?.().forEach(animation => animation.cancel());
    if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      panel.animate?.([{ opacity: 0.7 }, { opacity: 1 }], { duration: 180, easing: "ease-out" });
    }
  }

  function render() {
    if (!active) return;
    const service = services[selected], data = availability[selected];
    const loading = accessLoading || !!serviceLoading[selected];
    const showSkeleton = loading && !(data && access);
    const wasHidden = content.hidden;
    for (const [name, tab] of Object.entries(tabs)) {
      tab.setAttribute("aria-selected", String(name === selected));
      tab.setAttribute("tabindex", name === selected ? "0" : "-1");
    }
    panel.setAttribute("aria-labelledby", tabs[selected].id);
    panel.setAttribute("aria-busy", String(loading));
    if (showSkeleton && content.contains?.(document.activeElement)) tabs[selected].focus();
    content.hidden = showSkeleton;
    skeleton.hidden = !showSkeleton;
    if (showSkeleton) {
      if (skeletonTimer === null && !skeletonVisible) {
        skeletonTimer = window.setTimeout(() => {
          skeletonTimer = null;
          if (!active || skeleton.hidden) return;
          skeletonVisible = true;
          skeleton.classList.remove("opacity-0");
          skeleton.classList.add("motion-safe:animate-pulse");
        }, 180);
      }
    } else {
      cancelSkeletonDelay();
      skeletonVisible = false;
    }
    skeleton.classList.toggle("opacity-0", !skeletonVisible);
    skeleton.classList.toggle("motion-safe:animate-pulse", skeletonVisible);
    const loadingMessage = loading ? "Loading service information." : "";
    if (announcement.textContent !== loadingMessage) announcement.textContent = loadingMessage;
    get("serviceTitle").textContent = service.title;
    get("serviceDescription").textContent = service.description;
    get("serviceIcon").setAttribute("href", `../../assets/icons/phosphor.svg#${service.icon}`);
    get("serviceNote").textContent = service.note;
    get("serviceVisitDay").textContent = data?.visit_day_label || "Unavailable";
    get("serviceOpening").textContent = data?.check_in_starts || "Unavailable";
    get("serviceStatus").textContent = data?.message || "Unable to check availability. Try again.";
    const serviceAccess = access?.services?.[selected];
    const phoneRequired = access?.sms_verification?.enforced && access?.phone_verification_required;
    const message = accessFailed ? "Unable to check availability. Try again."
      : serviceAccess && !serviceAccess.allowed ? serviceAccess.message
      : phoneRequired ? "Phone verification required to continue." : "";
    get("serviceAccessText").textContent = message;
    get("serviceAccessMessage").hidden = !message;
    button.href = service.href;
    button.textContent = `Continue to ${service.title}`;
    setPreRegistrationLinkAccess(button, !loading && !!serviceAccess?.allowed && !!data?.registration_available, message || data?.message);
    retry.hidden = loading || (!accessFailed && !!data);
    if (wasHidden && !showSkeleton && data && access) animateCard();
  }

  function select(service, focus = false) {
    if (selected !== service) {
      selected = service;
      const hadContent = !content.hidden;
      render();
      if (hadContent && !content.hidden) animateCard();
    }
    if (focus) tabs[service].focus();
  }

  for (const [service, tab] of Object.entries(tabs)) {
    tab.addEventListener("click", () => select(service));
    tab.addEventListener("keydown", event => {
      const other = service === "grooming" ? "clinic" : "grooming";
      const next = { ArrowRight: other, ArrowLeft: other, Home: "grooming", End: "clinic" }[event.key];
      if (next) { event.preventDefault(); select(next, true); }
    });
  }

  async function load() {
    const requests = [];
    if (!accessLoading && (!access || accessFailed)) {
      accessLoading = true;
      accessFailed = false;
      requests.push(getPreRegistrationAccess().then(result => {
        access = result;
      }).catch(() => {
        access = null;
        accessFailed = true;
      }).finally(() => {
        accessLoading = false;
        setPhoneVerificationAccess(access);
        render();
      }));
    }
    for (const service of Object.keys(services)) {
      if (serviceLoading[service] || availability[service]) continue;
      serviceLoading[service] = true;
      requests.push(loadRegistrationAvailability(service).then(result => {
        availability[service] = result;
      }).catch(() => {
        availability[service] = null;
      }).finally(() => {
        serviceLoading[service] = false;
        render();
      }));
    }
    render();
    await Promise.all(requests);
  }
  retry.addEventListener("click", load);
  window.addEventListener("pagehide", () => { active = false; cancelSkeletonDelay(); });
  window.addEventListener("pageshow", () => { active = true; render(); });
  await load();
}

if (await requireCustomerSession("./sign-in.html")) await initPreRegistrationOptions();
