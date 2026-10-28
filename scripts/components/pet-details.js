// Connected to pages/client/pet-details.html
// Depends on: api.js

document.addEventListener("DOMContentLoaded", async () => {
  const { formatSavedWeight } = await import("./pet-weight-size.js?v=pet-weight-size-20261011-v3");
  const { renderClinicVisitSections, healthDate } = await import("./customer-health-records.js?v=20261029");
  if (!API.hasAuthenticatedSession("customer")) {
    API.redirectToSignIn();
    return;
  }

  const loadingState = document.getElementById("petLoadingState");
  const errorState = document.getElementById("petErrorState");
  const errorTitle = document.getElementById("petErrorTitle");
  const errorMessage = document.getElementById("petErrorMessage");
  const profileContent = document.getElementById("petProfileContent");
  const overviewGrid = document.getElementById("petOverviewGrid");
  const groomingRecords = document.getElementById("petGroomingRecords");
  const medicalRecords = document.getElementById("petMedicalRecords");
  const vaccinationRecords = document.getElementById("petVaccinationRecords");
  let groomingLoadState = "idle";
  let medicalLoadState = "idle";
  let vaccinationLoadState = "idle";
  let activePetTab = "overview";
  let petProfileReady = false;
  let tabPrefetchStarted = false;

  const profileParams = new URLSearchParams(window.location.search);
  const petIdParam = profileParams.get("pet_id");
  const petId = Number(petIdParam);
  const tabParam = profileParams.get("tab");
  const requestedTab = ["medical", "vaccinations"].includes(tabParam) ? "health" : tabParam;

  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const isMissing = (value) => value === null || value === undefined || String(value).trim() === "";
  const displayValue = (value) => isMissing(value) ? "Not provided." : String(value);
  const isTrue = (value) => value === true || value === 1 || value === "1";
  const isClinicVerified = (pet, field) => Boolean(field)
    && Array.isArray(pet?.clinic_verified_fields)
    && pet.clinic_verified_fields.includes(field);
  const verifiedIndicator = (verified) => verified
    ? '<span class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-[#315b7e]"><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>Verified</span>'
    : "";

  const titleCase = (value) => {
    if (isMissing(value)) return "Not provided.";
    return String(value)
      .replaceAll("_", " ")
      .replace(/\b\w/g, (character) => character.toUpperCase());
  };

  const formatDate = (value) => {
    if (isMissing(value)) return "Not provided.";
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString("en-PH", {
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  };

  const formatWeight = (value) => isMissing(value) ? "Not provided." : `${formatSavedWeight(value)} kg`;

  const formatBoolean = (value) => {
    if (value === null || value === undefined || value === "") return "Not provided.";
    return isTrue(value) ? "Yes" : "No";
  };

  const setError = (title, message) => {
    loadingState?.classList.add("hidden");
    profileContent?.classList.add("hidden");
    errorTitle.textContent = title;
    errorMessage.textContent = message;
    errorState?.classList.remove("hidden");
  };

  const setupSidebar = () => {
    const sidebar = document.getElementById("clientSidebar");
    const toggle = document.getElementById("clientSidebarToggle");
    const close = document.getElementById("clientSidebarClose");
    const backdrop = document.getElementById("clientSidebarBackdrop");
    const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
    const sidebarLinks = sidebar?.querySelectorAll("a") || [];

    const setOpen = (open) => {
      document.body.classList.toggle("client-sidebar-open", open);
      toggle?.setAttribute("aria-expanded", String(open));
      toggle?.setAttribute("aria-label", open ? "Close navigation menu" : "Open navigation menu");
    };

    const closeSidebar = () => setOpen(false);

    toggle?.addEventListener("click", () => {
      if (!mobileSidebarQuery.matches) return;
      setOpen(!document.body.classList.contains("client-sidebar-open"));
    });
    close?.addEventListener("click", () => setOpen(false));
    backdrop?.addEventListener("click", () => setOpen(false));
    sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeSidebar();
    });
    mobileSidebarQuery.addEventListener("change", (event) => {
      if (!event.matches) closeSidebar();
    });
    setOpen(false);
  };

  const setupTabs = () => {
    const tabs = document.querySelectorAll("[data-pet-tab]");
    const panels = document.querySelectorAll("[data-pet-panel]");
    const validTabs = new Set(
      Array.from(tabs).map((tab) => tab.dataset.petTab),
    );

    const activateTab = (selected) => {
      if (!validTabs.has(selected)) return;
      activePetTab = selected;

      tabs.forEach((candidate) => {
        const active = candidate.dataset.petTab === selected;
        candidate.setAttribute("aria-selected", String(active));
        candidate.classList.toggle("bg-portal-primary", active);
        candidate.classList.toggle("text-white", active);
        candidate.classList.toggle("shadow-sm", active);
        candidate.classList.toggle("text-portal-text", !active);
        candidate.classList.toggle("hover:bg-portal-surface-soft", !active);
      });

      panels.forEach((panel) => {
        panel.classList.toggle("hidden", panel.dataset.petPanel !== selected);
      });

      if (petProfileReady) void loadPetTabData(selected);
    };

    tabs.forEach((tab) => {
      tab.addEventListener("click", () => activateTab(tab.dataset.petTab));
    });

    activateTab(validTabs.has(requestedTab) ? requestedTab : "overview");
  };

  const loadProfile = async () => {
    try {
      const { user } = await API.getMe("customer");
      const firstName = user?.first_name || "";
      const lastName = user?.last_name || "";
      const fullName = `${firstName} ${lastName}`.trim() || "Pet Owner";
      const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase() || "--";

      document.getElementById("clientProfileName").textContent = fullName;
      document.getElementById("clientProfileInitials").textContent = initials;
    } catch {
      // The central API layer handles expired customer sessions.
    }
  };

  const renderOverview = (pet) => {
    const archived = isTrue(pet.is_archived);
    const details = [
      ["Pet Name", displayValue(pet.pet_name), null],
      ["Species", titleCase(pet.species), null],
      ["Breed", displayValue(pet.breed), "breed"],
      ["Gender", titleCase(pet.gender), null],
      ["Birthdate", formatDate(pet.birthdate), null],
      ["Size", `${titleCase(pet.size)} · ${isClinicVerified(pet, "size") ? "✓ Verified by Bethlehem Animal Clinic" : "Estimated from weight"}`, null],
      ["Weight", formatWeight(pet.weight), "weight"],
      ["Fur Type", titleCase(pet.fur_type), "fur_type"],
      ["Color", displayValue(pet.color), null],
      ["Neutered / Spayed", formatBoolean(pet.is_neutered), null],
    ];

    overviewGrid.innerHTML = details.map(([label, value, field]) => `
      <div class="min-w-0">
        <div class="flex items-center justify-between gap-3">
          <p class="text-xs font-medium text-portal-muted">${escapeHtml(label)}</p>
          ${verifiedIndicator(isClinicVerified(pet, field))}
        </div>
        <p class="pet-overview-value ${label === "Pet Name" ? "pet-overview-name-value" : ""} mt-1 break-words text-xs font-semibold text-portal-text">${escapeHtml(value)}</p>
      </div>
    `).join("") + `
      <div class="col-span-full grid grid-cols-1 gap-1 border-t border-portal-border pt-4 sm:grid-cols-2 sm:gap-x-8">
        <p class="text-xs font-medium text-portal-muted">Profile Status</p>
        <p class="inline-flex items-center gap-1.5 text-xs font-semibold ${archived ? "text-portal-muted" : "text-emerald-700"}">
          <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>${archived ? "Archived" : "Active"}
        </p>
      </div>
    `;

    document.getElementById("petMedicalConditions").textContent = displayValue(pet.medical_conditions);
  };

  const statusDetails = (status) => {
    const statuses = {
      waiting_to_arrive: ["Waiting to Arrive", "bg-blue-50 text-blue-700"],
      checked_in: ["Checked In", "bg-amber-50 text-amber-700"],
      in_progress: ["Being Groomed", "bg-violet-50 text-violet-700"],
      grooming_finished: ["Grooming Finished", "bg-emerald-50 text-emerald-700"],
      for_payment: ["For Payment", "bg-orange-50 text-orange-700"],
      for_pickup: ["Ready for Pickup", "bg-cyan-50 text-cyan-700"],
      released: ["Released", "bg-emerald-50 text-emerald-700"],
      archived: ["Completed", "bg-slate-100 text-slate-700"],
      cancelled: ["Cancelled", "bg-red-50 text-red-700"],
      no_show: ["No Show", "bg-slate-100 text-slate-600"],
      expired: ["Expired", "bg-slate-100 text-slate-600"],
    };
    return statuses[status] || [titleCase(status), "bg-slate-100 text-slate-700"];
  };

  const renderGroomingRecord = ({ booking, pet }) => {
    const [statusLabel, statusClasses] = statusDetails(pet.grooming_status || booking.status);
    const services = Array.isArray(pet.services)
      ? pet.services.map((service) => service.service_name).filter((name) => !isMissing(name))
      : [];

    const items = [
      ["Grooming Date", formatDate(booking.booking_date)],
      ["Arrival Window", displayValue(booking.time_window?.window_label)],
      ["Grooming Started", displayValue(pet.grooming_started_at)],
      ["Grooming Finished", displayValue(pet.grooming_finished_at)],
      ["Selected Services", services.length ? services.join(", ") : "Not provided."],
      ["Payment Status", booking.paid ? "Paid" : "Unpaid"],
    ];
    return `
      <article class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="text-xs font-medium text-slate-500">Registration reference</p>
            <h4 class="mt-1 text-sm font-bold text-[#2f4b66]">${escapeHtml(displayValue(booking.booking_reference))}</h4>
          </div>
          <span class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1 text-xs font-bold ${statusClasses}"><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>${escapeHtml(statusLabel)}</span>
        </div>
        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          ${items.map(([label, value]) => `
            <div>
              <dt class="text-xs font-medium text-slate-500">${escapeHtml(label)}</dt>
              <dd class="mt-1 break-words text-xs font-medium leading-5 text-slate-700">${escapeHtml(value)}</dd>
            </div>
          `).join("")}
        </dl>
      </article>
    `;
  };

  const loadGrooming = async () => {
    if (!groomingRecords || groomingLoadState === "loading" || groomingLoadState === "loaded") return;

    groomingLoadState = "loading";
    groomingRecords.innerHTML = `
      <div class="py-12 text-center" aria-live="polite">
        <p class="text-xs text-portal-muted">Loading grooming records...</p>
      </div>
    `;
    try {
      const data = await API.getBookingHistory({ petId });
      const bookings = [...(data.bookings || []), ...(data.history || [])];
      const records = bookings.map((booking) => {
        const pet = (booking.pets || []).find((item) => Number(item.pet_id) === petId);
        return pet ? { booking, pet } : null;
      }).filter(Boolean);

      records.sort((left, right) => {
        const byDate = String(right.booking.booking_date || "").localeCompare(String(left.booking.booking_date || ""));
        return byDate || Number(right.booking.booking_id || 0) - Number(left.booking.booking_id || 0);
      });

      if (!records.length) {
        groomingRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <h4 class="text-sm font-bold text-slate-700">No grooming records found</h4>
            <p class="mt-1 text-xs text-slate-500">This pet does not have grooming activity yet.</p>
          </div>
        `;
      } else {
        groomingRecords.innerHTML = `<div class="space-y-4">${records.map(renderGroomingRecord).join("")}</div>`;
      }
      groomingLoadState = "loaded";
    } catch (error) {
      groomingLoadState = "error";
      groomingRecords.innerHTML = `
        <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center">
          <h4 class="text-sm font-bold text-red-800">Grooming records could not be loaded</h4>
          <p class="mt-1 text-xs text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
        </div>
      `;
    }

  };

  const renderMedicalRecord = (record) => {
    return `
      <article class="border-t border-portal-border py-5 first:border-t-0 first:pt-0 last:pb-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h4 class="text-sm font-bold text-portal-text">${escapeHtml(healthDate(record.appointment_date))}</h4>
            ${isMissing(record.appointment_reference) ? "" : `<p class="mt-1 break-words text-xs text-portal-muted">${escapeHtml(record.appointment_reference)}</p>`}
          </div>
          <span class="w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">${escapeHtml(titleCase(record.status))}</span>
        </div>
        ${renderClinicVisitSections(record, escapeHtml, { compact: true })}
      </article>`;
  };

  const loadMedicalRecords = async () => {
    if (!medicalRecords || medicalLoadState === "loading" || medicalLoadState === "loaded") return;

    medicalLoadState = "loading";
    medicalRecords.innerHTML = `
      <div class="py-12 text-center">
        <p class="text-xs text-slate-500">Loading clinic visits...</p>
      </div>
    `;
    try {
      const data = await API.getPetMedicalRecords(petId);
      const records = Array.isArray(data.medical_records) ? data.medical_records : [];

      if (!records.length) {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <h4 class="text-sm font-bold text-slate-700">No clinic visits recorded</h4>
          </div>
        `;
      } else {
        medicalRecords.innerHTML = `<div>${records.map(renderMedicalRecord).join("")}</div>`;
      }

      medicalLoadState = "loaded";
    } catch (error) {
      medicalLoadState = "error";

      if (error?.status === 404) {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-amber-100 bg-amber-50 px-6 py-10 text-center" role="alert">
            <h4 class="text-sm font-bold text-amber-900">Pet profile not found</h4>
            <p class="mt-1 text-xs text-amber-700">This pet does not exist or is not available for your account.</p>
          </div>
        `;
      } else {
        medicalRecords.innerHTML = `
          <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center" role="alert">
            <h4 class="text-sm font-bold text-red-800">Clinic visits could not be loaded</h4>
            <p class="mt-1 text-xs text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
          </div>
        `;
      }
    }

  };

  const vaccinationStatusDetails = (status) => {
    const statuses = {
      current: {
        label: "Current",
        classes: "border-emerald-200 bg-emerald-50 text-emerald-700",
      },
      due_soon: {
        label: "Due soon",
        classes: "border-amber-200 bg-amber-50 text-amber-800",
      },
      overdue: {
        label: "Overdue",
        classes: "border-red-200 bg-red-50 text-red-700",
      },
      unknown: {
        label: "Unknown",
        classes: "border-slate-200 bg-slate-50 text-slate-600",
      },
    };

    return statuses[status] || statuses.unknown;
  };

  const renderVaccinationRecord = (record) => {
    const status = vaccinationStatusDetails(record.due_status);
    return `
      <article class="rounded-2xl border border-portal-border bg-white p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <h4 class="min-w-0 break-words text-sm font-bold text-portal-text">${escapeHtml(displayValue(record.vaccine_name))}</h4>
          <span class="w-fit rounded-full border px-3 py-1 text-xs font-semibold ${status.classes}"
            aria-label="Vaccination due status: ${escapeHtml(status.label)}">${escapeHtml(status.label)}</span>
        </div>
        <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium text-portal-muted">Administered</dt>
            <dd class="mt-1 text-xs text-portal-text">${escapeHtml(healthDate(record.administered_date))}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-portal-muted">Next due</dt>
            <dd class="mt-1 text-xs text-portal-text">${escapeHtml(healthDate(record.next_due_date))}</dd>
          </div>
        </dl>
      </article>`;
  };

  const loadVaccinations = async ({ retry = false } = {}) => {
    if (
      !vaccinationRecords
      || vaccinationLoadState === "loading"
      || (!retry && vaccinationLoadState === "loaded")
    ) {
      return;
    }

    vaccinationLoadState = "loading";
    vaccinationRecords.innerHTML = `
      <div class="py-12 text-center" aria-live="polite">
        <p class="text-xs text-slate-500">Loading vaccination history...</p>
      </div>
    `;
    try {
      const data = await API.getPetVaccinations(petId);
      const records = Array.isArray(data.vaccinations) ? data.vaccinations : [];

      if (!records.length) {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center">
            <h4 class="text-sm font-bold text-slate-700">No vaccinations recorded</h4>
          </div>
        `;
      } else {
        vaccinationRecords.innerHTML = `<div class="space-y-4">${records.map(renderVaccinationRecord).join("")}</div>`;
      }

      vaccinationLoadState = "loaded";
    } catch (error) {
      vaccinationLoadState = "error";

      if (error?.status === 404) {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-amber-100 bg-amber-50 px-6 py-10 text-center" role="alert">
            <h4 class="text-sm font-bold text-amber-900">Pet profile not found</h4>
            <p class="mt-1 text-xs text-amber-700">This pet does not exist or is not available for your account.</p>
          </div>
        `;
      } else {
        vaccinationRecords.innerHTML = `
          <div class="rounded-2xl border border-red-100 bg-red-50 px-6 py-10 text-center" role="alert">
            <h4 class="text-sm font-bold text-red-800">Vaccination history could not be loaded</h4>
            <p class="mt-1 text-xs text-red-600">${escapeHtml(error?.message || "Please try again later.")}</p>
            <button
              type="button"
              data-retry-vaccinations
              class="mt-4 inline-flex items-center gap-2 rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-50"
            >
              Retry
            </button>
          </div>
        `;
        vaccinationRecords
          .querySelector("[data-retry-vaccinations]")
          ?.addEventListener("click", () => loadVaccinations({ retry: true }));
      }
    }

  };

  const petTabLoaders = {
    grooming: loadGrooming,
    health: () => Promise.allSettled([loadMedicalRecords(), loadVaccinations()]),
  };

  const loadPetTabData = (tabName) => {
    const loader = petTabLoaders[tabName];
    return loader ? loader() : Promise.resolve();
  };

  const scheduleIdleTask = (task) => {
    if (typeof window.requestIdleCallback === "function") {
      window.requestIdleCallback(task, { timeout: 1200 });
      return;
    }

    window.setTimeout(task, 200);
  };

  const scheduleCustomerTabPrefetch = () => {
    if (tabPrefetchStarted) return;
    tabPrefetchStarted = true;

    const remainingTabs = [
      "grooming",
      "health",
    ].filter((tabName) => tabName !== activePetTab);

    const prefetchNextTab = () => {
      const nextTab = remainingTabs.shift();
      if (!nextTab) return;

      Promise.resolve(loadPetTabData(nextTab)).finally(() => {
        if (remainingTabs.length) scheduleIdleTask(prefetchNextTab);
      });
    };

    scheduleIdleTask(prefetchNextTab);
  };

  const loadPet = async () => {
    if (!petIdParam || !Number.isInteger(petId) || petId < 1) {
      setError("Invalid pet profile link", "Choose a pet from My Pets to open its profile.");
      return;
    }

    try {
      const { pet } = await API.getPet(petId);

      renderOverview(pet);
      loadingState?.classList.add("hidden");
      errorState?.classList.add("hidden");
      profileContent?.classList.remove("hidden");
      petProfileReady = true;
      void loadPetTabData(activePetTab);
      window.requestAnimationFrame(scheduleCustomerTabPrefetch);
    } catch (error) {
      if (error?.status === 404) {
        setError(
          "Pet profile not found",
          "This pet does not exist or is not available for your account."
        );
      } else {
        setError(
          "Pet profile could not be loaded",
          error?.message || "Please try again later."
        );
      }
    }
  };

  document.getElementById("clientLogoutBtn")?.addEventListener("click", async () => {
    try {
      await API.logout("customer");
    } finally {
      API.redirectToSignIn({ replace: true });
    }
  });

  setupSidebar();
  setupTabs();
  void loadPet();
  window.requestAnimationFrame(() => scheduleIdleTask(loadProfile));
});
