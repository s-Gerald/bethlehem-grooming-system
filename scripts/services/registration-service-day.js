const pending = new Map();
const dayKey = service => `registrationReviewedDay:${service}`;
const noticeKey = service => `registrationDayNotice:${service}`;

export function loadRegistrationAvailability(service) {
  if (!pending.has(service)) {
    const request = (async () => {
      const response = await fetch(`${API.getBaseUrl()}/pre-registration/queue-info?service=${encodeURIComponent(service)}`, {
        cache: "no-store", headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error("Unable to check availability. Try again.");
      return response.json();
    })().finally(() => pending.delete(service));
    pending.set(service, request);
  }
  return pending.get(service);
}

export function rememberReviewedServiceDay(service, day) {
  if (day) sessionStorage.setItem(dayKey(service), day);
  else sessionStorage.removeItem(dayKey(service));
}

export function readReviewedServiceDay(service) {
  return sessionStorage.getItem(dayKey(service)) || null;
}

export function registrationDayNotice(error, service) {
  if (!["service_day_changed", "service_unavailable"].includes(error.code)) return false;
  rememberReviewedServiceDay(service, null);
  sessionStorage.setItem(noticeKey(service), error.message);
  return true;
}

export function takeRegistrationDayNotice(service) {
  const notice = sessionStorage.getItem(noticeKey(service));
  sessionStorage.removeItem(noticeKey(service));
  return notice;
}
