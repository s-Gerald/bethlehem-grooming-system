import { initBookingCalendar } from "./booking-calendar.js?pet-weight=pet-weight-size-20261011-v3&grooming=actual-20261026";
import {
  CLINIC_VISIT_DRAFT_KEY,
  requireCustomerSession,
  updateClinicVisitDraft,
  clearClinicVisitArrival,
} from "../services/clinic-visit-service.js?pet-weight=pet-weight-size-20261011-v3";
import { initBookingFormAccessGuard } from "../services/booking-form-access-guard.js?v=phone-20261022";

document.addEventListener("DOMContentLoaded", async () => {
  if (!requireCustomerSession("./sign-in.html")) {
    return;
  }

  const blocked = await initBookingFormAccessGuard({
    dashboardPath: "./dashboard.html",
  });
  if (blocked) return;

  await initBookingCalendar({
    service: "clinic",
    dateOnly: false,
    storageKey: CLINIC_VISIT_DRAFT_KEY,
    dateField: "appointmentDate",
    nextPath: "./booking-pet-details.html?flow=clinic",
    fetchTimeslots: (date) => API.getClinicTimeslots(date),
    clearSelection: () => clearClinicVisitArrival(),
    onDateSelected: (appointmentDate) => clearClinicVisitArrival(appointmentDate),
    saveSelection: (selection) => {
      updateClinicVisitDraft({
        appointmentDate: selection.date,
        windowId: Number(selection.window_id),
        windowLabel: selection.time,
        startTime: selection.start_time,
        endTime: selection.end_time,
        scheduleText: selection.scheduleText,
      });
    },
  });
});
