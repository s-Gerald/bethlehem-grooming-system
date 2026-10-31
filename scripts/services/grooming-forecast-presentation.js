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
  time(value) {
    if (!value || !Number.isFinite(Date.parse(value))) return "";
    return new Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", hour12: true }).format(new Date(value));
  },
  text(forecast) {
    if (!forecast) return "";
    const prefix = forecast.provisional ? "Provisional · " : "";
    if (forecast.completion_at) return `${prefix}Est. ${this.time(forecast.start_at)}–${this.time(forecast.completion_at)}${forecast.closing_risk ? " · May extend past closing" : ""}`;
    if (["delayed", "uncertain"].includes(forecast.status)) return `${prefix}${forecast.status === "delayed" ? "Grooming delayed; completion uncertain" : "Queue times uncertain"}${forecast.closing_risk ? " · May extend past closing" : ""}`;
    return `${prefix}Estimated grooming time: ${this.duration(forecast.duration)}`;
  },
};
