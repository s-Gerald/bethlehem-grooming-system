const DATE_KEY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$/;

function parseDateKey(dateValue) {
  const rawDate = String(dateValue ?? "").trim();
  const match = rawDate.match(DATE_KEY_PATTERN);

  if (!match) {
    return null;
  }

  const year = Number(match[1]);
  const monthIndex = Number(match[2]) - 1;
  const day = Number(match[3]);
  const date = new Date(year, monthIndex, day);

  if (
    date.getFullYear() !== year ||
    date.getMonth() !== monthIndex ||
    date.getDate() !== day
  ) {
    return null;
  }

  return date;
}

export function formatBookingDate(dateValue) {
  const rawDate = String(dateValue ?? "").trim();

  if (!rawDate) {
    return "";
  }

  const date = parseDateKey(rawDate);

  if (!date) {
    return rawDate;
  }

  return new Intl.DateTimeFormat("en-PH", {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(date);
}

export function formatBookingTimeRange(timeValue) {
  const rawTime = String(timeValue ?? "").trim();

  if (!rawTime) {
    return "";
  }

  return rawTime.replace(/\s*(?:-|\u2013|\u2014)\s*/g, " \u2013 ");
}

export function formatBookingSchedule(dateValue, timeValue) {
  const bookingDate = formatBookingDate(dateValue);
  const bookingTime = formatBookingTimeRange(timeValue);

  if (bookingDate && bookingTime) {
    return `${bookingDate} \u00b7 ${bookingTime}`;
  }

  return bookingDate || bookingTime || "";
}


// Preserve saved decimal precision; this is presentation, independent of pet form controls.
export function formatSavedWeight(rawWeight) {
  const text = String(rawWeight ?? "").trim();
  if (!/^[+-]?\d+(?:\.\d*)?$/.test(text)) return text;
  const [whole, fraction] = text.split(".");
  const significant = (fraction || "").replace(/0+$/, "");
  return significant ? `${whole}.${significant}` : whole;
}
