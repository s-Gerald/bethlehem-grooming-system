const peso = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });
const quantity = new Intl.NumberFormat("en-PH", { maximumFractionDigits: 2 });
const dateFormat = new Intl.DateTimeFormat("en-PH", {
  timeZone: "Asia/Manila", month: "short", day: "numeric", year: "numeric",
});
const timeFormat = new Intl.DateTimeFormat("en-PH", {
  timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", hour12: true,
});

export function historyDateTimeParts(timestamp) {
  if (!timestamp) return null;
  const date = new Date(timestamp);
  return Number.isNaN(date.getTime()) ? null : { date: dateFormat.format(date), time: timeFormat.format(date) };
}

export function historyTimestamp(timestamp) {
  const parts = historyDateTimeParts(timestamp);
  return parts ? `${parts.date} · ${parts.time}` : "";
}

export function groomingHistoryPets(booking, selectedPet) {
  return [selectedPet, ...(booking.pets || []).filter(pet => pet.booking_pet_id !== selectedPet?.booking_pet_id)]
    .filter(Boolean);
}

export function petGroomingTimes(booking, pet) {
  const useBookingTimes = !pet || Number(booking.number_of_pets) === 1;
  return {
    start: pet?.grooming_started_timestamp || (useBookingTimes ? booking.grooming_started_timestamp : null),
    finish: pet?.grooming_finished_timestamp || (useBookingTimes ? booking.grooming_finished_timestamp : null),
  };
}

export function clinicHistoryPayment(details) {
  return details.grouped ? details.payment : details.patients?.[0]?.payment;
}

function money(amount) {
  return (typeof amount === "number" || (typeof amount === "string" && amount.trim()))
    && Number.isFinite(Number(amount)) && Number(amount) >= 0 ? peso.format(Number(amount)) : "";
}

function line(name, count, unitPrice, amount) {
  return {
    name,
    detail: count != null && money(unitPrice) ? `${quantity.format(count)} × ${money(unitPrice)}` : "",
    amount: money(amount),
  };
}

export function clinicHistoryBilling(patient) {
  if (patient.status === "cancelled") return { lines: [], subtotal: "" };
  return {
    lines: (patient.billing_lines || []).filter(item => item.description?.trim())
      .map(item => line(item.description, item.quantity, item.unit_price, item.amount)),
    subtotal: money(patient.patient_subtotal),
  };
}

function settledGroomingPet(booking, pet) {
  const payment = booking.payment_summary;
  if (payment?.status !== "paid") return null;
  return payment.pets?.find(item => Number(item.booking_pet_id) === Number(pet?.booking_pet_id))
    || (Number(booking.number_of_pets) === 1 ? payment.pets?.[0] : null);
}

export function historyServiceLines(booking, pet) {
  const settled = settledGroomingPet(booking, pet);
  return Array.isArray(settled?.service_breakdown)
    ? settled.service_breakdown.filter(line => line.label)
    : (pet?.services || []).filter(service => service.service_name).map(service => ({
      label: service.service_name, service_kind: service.service_kind,
    }));
}

export function groomingPetBilling(booking, pet) {
  const settled = settledGroomingPet(booking, pet);
  const charged = settled && settled.payment_kind !== "cancelled" && pet?.grooming_status !== "cancelled";
  const lines = historyServiceLines(booking, pet);
  const main = lines.some(line => line.service_kind) ? lines.findIndex(line => line.service_kind === "package") : 0;
  return {
    lines: lines.map((line, index) => ({
      name: line.label,
      detail: line.service_kind ? (line.service_kind === "package" ? "Main service" : "À la carte") : "",
      preference: index === main ? pet?.grooming_preference_label : "",
      amount: charged && line.price_source !== "unpriced" ? money(line.price_at_booking) : "",
    })),
    subtotal: charged ? money(settled.final_pet_charge) : "",
  };
}

export function groomingHistoryProducts(booking) {
  const payment = booking.payment_summary;
  return payment?.status === "paid" ? (payment.products || []).filter(item => item.item_name?.trim())
    .map(item => line(item.item_name, item.quantity, item.price_at_sale, item.subtotal)) : [];
}

export function historyPaymentFields(payment) {
  const total = money(payment?.total_paid);
  if (payment?.status !== "paid" || !total) return [];
  return [
    ...(payment.products?.length ? [
      ["Grooming services", money(payment.grooming_services_total)],
      ["Products", money(payment.products_total)],
    ] : []),
    ["Total paid", total],
    ["Payment method", payment.payment_method_label],
    ["Paid on", historyTimestamp(payment.paid_at)],
  ].filter(([, value]) => value);
}
