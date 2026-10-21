export const GROOMING_MINIMUM_AGE_MESSAGE = "Pet must be at least 6 months old for grooming.";

function todayKey() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila", year: "numeric", month: "2-digit", day: "2-digit",
  }).format(new Date());
}

function calendarDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/.exec(value || "");
  if (!match) return null;
  const [, year, month, day] = match.map(Number);
  const date = new Date(year, month - 1, day);
  return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
    ? date : null;
}

function ageInMonths(pet, today) {
  const birthdate = calendarDate(pet?.birthdate);
  if (!birthdate) return null;
  const current = calendarDate(today);
  const months = (current.getFullYear() - birthdate.getFullYear()) * 12
    + current.getMonth() - birthdate.getMonth();
  // Clamp anniversaries to the last day of shorter months (e.g. Aug 31 → Feb 28).
  const anniversaryDay = Math.min(birthdate.getDate(),
    new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate());
  return months - (current.getDate() < anniversaryDay ? 1 : 0);
}

export function isEligibleForGrooming(pet, today = todayKey()) {
  const months = ageInMonths(pet, today);
  return months === null || months >= 6;
}

export function formatPetAge(pet, today = todayKey()) {
  const months = ageInMonths(pet, today);
  if (months === null) return "";
  const count = months >= 12 ? Math.floor(months / 12) : Math.max(0, months);
  const unit = months >= 12 ? "year" : "month";
  return `${count} ${unit}${count === 1 ? "" : "s"}`;
}
