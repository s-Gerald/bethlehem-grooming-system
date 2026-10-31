// Shared presentation only. All durations and queue arithmetic come from PHP.
globalThis.GroomingForecastPresentation = {
  duration(value) {
    if (!value || !Number.isFinite(value.min_minutes) || !Number.isFinite(value.max_minutes)) return "Unavailable";
    const minutes = (number) => {
      const hours = Math.floor(number / 60), rest = number % 60;
      return [hours ? `${hours} ${hours === 1 ? "hr" : "hrs"}` : "", rest ? `${rest} min` : ""].filter(Boolean).join(" ");
    };
    return value.min_minutes === value.max_minutes ? minutes(value.min_minutes) : `${minutes(value.min_minutes)}–${minutes(value.max_minutes)}`;
  },
  time(value, reference = new Date()) {
    if (!value || !Number.isFinite(Date.parse(value))) return "";
    const date = new Date(value);
    const clock = new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", hour12: true }).format(date);
    const calendar = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit" });
    const day = calendar.format(date), today = calendar.format(new Date(reference));
    if (day === today) return clock;
    const tomorrow = new Date(`${today}T00:00:00Z`);
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
    if (day === tomorrow.toISOString().slice(0, 10)) return `${clock} tomorrow`;
    return `${clock} on ${new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", dateStyle: "medium" }).format(date)}`;
  },
  text(forecast, reference = forecast?.generated_at ?? new Date()) {
    if (!forecast) return "";
    const prefix = forecast.provisional ? "Provisional · " : "";
    const risk = forecast.closing_risk ? " · May extend past closing" : "";
    if (["delayed", "uncertain"].includes(forecast.status)) return `${prefix}${forecast.status === "delayed" ? "Grooming delayed; completion uncertain" : "Queue times uncertain"}${risk}`;
    if (forecast.status !== "unavailable" && forecast.completion_at) return `${prefix}Est. ready: ${this.time(forecast.completion_at, reference)}${risk}`;
    return `${prefix}Estimate unavailable${risk}`;
  },
};
