import { createColorCombobox } from "./color-combobox.js?pet-care=20261027-v1";
import { normalizePetSize } from "../services/booking-draft-service.js?registration=fcfs-20261027-v1";
import { createBreedCombobox } from "./breed-combobox.js?pet-fields=20261030-v1";
import { breedCoatCatalogueReady } from "./breed-coat-catalogue.js?pet-fields=20261030-v1";
import { createBreedCoatCombobox } from "./breed-coat-combobox.js?pet-fields=20261030-v1";
import { createFixedOptionCombobox } from "./fixed-option-combobox.js";
import {
  formatSavedWeight,
  getEnteredWeight,
  getSizeForWeight,
  getSizeOptions,
  getWeightFieldValidationMessage,
  getWeightValidationMessage,
  initializeWeightField,
  syncPetWeightAndSize as syncWeightSizeFields,
  showWeightValidationInField,
} from "./pet-weight-size.js?v=pet-weight-size-20261011-v3";

// Connected to pages/client/my-pets.html
// Depends on: api.js and the shared pre-registration pet field components

document.addEventListener("DOMContentLoaded", () => {
  // ── Auth guard ───────────────────────────────────────
  if (!API.hasAuthenticatedSession("customer")) {
    API.redirectToSignIn();
    return;
  }

  // ── State ────────────────────────────────────────────
  let allPets = [];
  let petsLoadPending = false;
  let allKnownPets = [];
  let showingArchived = false;
  let activePetsCache = null;
  let archivedPetsCache = null;
  let activePetsRequest = null;
  let archivedPetsRequest = null;
  let petCollectionGeneration = 0;
  let petCollectionPrefetchStarted = false;
  let notificationsLoading = false;
  let notificationsLoaded = false;
  let editingPet = null;
  let petModalReturnFocus = null;
  let confirmationResolver = null;
  let confirmationReturnFocus = null;

  // ── DOM refs ─────────────────────────────────────────
  const petsGrid         = document.getElementById("petsGrid");
  const petsLoadingScreen = document.getElementById("petsLoadingScreen");
  const petsLoadingMessage = document.getElementById("petsLoadingMessage");
  const petsContent = document.getElementById("petsContent");
  const petSearch        = document.getElementById("petSearch");
  const filterActiveBtn  = document.getElementById("filterActiveBtn");
  const filterArchivedBtn= document.getElementById("filterArchivedBtn");
  const addPetBtn        = document.getElementById("addPetBtn");
  const petModal         = document.getElementById("petModal");
  const petsPageShell    = document.getElementById("petsPageShell");
  const closePetModal    = document.getElementById("closePetModal");
  const petForm          = document.getElementById("petForm");
  const petModalTitle    = document.getElementById("petModalTitle");
  const petFormError     = document.getElementById("petFormError");
  const petFormSubmit    = document.getElementById("petFormSubmit");
  const petSpecies       = document.getElementById("petSpecies");
  const petBreed         = document.getElementById("petBreed");
  const petGender        = document.getElementById("petGender");
  const petBirthdate     = document.getElementById("petBirthdate");
  const petSize          = document.getElementById("petSize");
  const petFurType       = document.getElementById("petFurType");
  const petWeight        = document.getElementById("petWeight");
  const logoutBtn        = document.getElementById("clientLogoutBtn");
  const petConfirmationModal = document.getElementById("petConfirmationModal");
  const petConfirmationCard = document.getElementById("petConfirmationCard");
  const petConfirmationTitle = document.getElementById("petConfirmationTitle");
  const petConfirmationMessage = document.getElementById("petConfirmationMessage");
  const petConfirmationDetails = document.getElementById("petConfirmationDetails");
  const cancelPetConfirmation = document.getElementById("cancelPetConfirmation");
  const confirmPetAction = document.getElementById("confirmPetAction");
  const editVerifiedIndicators = {
    breed: document.getElementById("petBreedVerified"),
    fur_type: document.getElementById("petFurTypeVerified"),
    weight: document.getElementById("petWeightVerified"),
    size: document.getElementById("petSizeVerified"),
  };
  const notificationBell = document.getElementById("notifBellBtn");
  const notificationBadge = document.getElementById("notifBadge");
  const notificationDropdown = document.getElementById("notifDropdown");
  const notificationList = document.getElementById("notifList");
  const markAllNotificationsRead = document.getElementById("notifMarkAllRead");
  const currentNotificationTab = window.ClientNotificationUI.ensureDropdownControls(notificationDropdown, () => {
    void loadNotifications({ force: true });
  });

  const petTypeCombobox = createFixedOptionCombobox({
    root: document.getElementById("petSpeciesCombobox"),
    input: petSpecies,
    listbox: document.getElementById("petSpeciesOptions"),
    toggleButton: document.getElementById("petSpeciesDropdownButton"),
    placeholder: "Select species",
    options: [
      { value: "Dog", label: "Dog" },
      { value: "Cat", label: "Cat" },
    ],
  });

  const genderCombobox = createFixedOptionCombobox({
    root: document.getElementById("petGenderCombobox"),
    input: petGender,
    listbox: document.getElementById("petGenderOptions"),
    toggleButton: document.getElementById("petGenderDropdownButton"),
    placeholder: "Select gender",
    options: [
      { value: "male", label: "Male" },
      { value: "female", label: "Female" },
    ],
    displaySelectedLabel: true,
  });

  const breedCombobox = createBreedCombobox({
    root: document.getElementById("petBreedCombobox"),
    input: petBreed,
    listbox: document.getElementById("petBreedOptions"),
    toggleButton: document.getElementById("petBreedDropdownButton"),
    petTypeInput: petSpecies,
    getPetType: () => petSpecies.value,
    errorElement: document.getElementById("petBreedError"),
  });

  const colorCombobox = createColorCombobox({
    input: document.getElementById("petColor"),
    listbox: document.getElementById("petColorOptions"),
    errorElement: document.getElementById("petColorError"),
  });

  const breedCoatCombobox = createBreedCoatCombobox({
    root: document.getElementById("petFurTypeCombobox"),
    breedInput: petBreed,
    petTypeInput: petSpecies,
    input: petFurType,
    listbox: document.getElementById("petFurTypeOptions"),
    toggleButton: document.getElementById("petFurTypeDropdownButton"),
    errorElement: document.getElementById("petFurTypeError"),
  });

  const sizeCombobox = createFixedOptionCombobox({
    root: document.getElementById("petSizeCombobox"),
    input: petSize,
    listbox: document.getElementById("petSizeOptions"),
    toggleButton: document.getElementById("petSizeDropdownButton"),
    placeholder: "Select size",
    options: [],
    displaySelectedLabel: false,
  });

  const profileInputs = {
    pet_name: document.getElementById("petName"), species: petSpecies, breed: petBreed,
    fur_type: petFurType, gender: petGender, birthdate: petBirthdate, weight: petWeight,
    size: petSize, color: document.getElementById("petColor"), medical_conditions: document.getElementById("petMedical"),
  };
  function fieldLocked(field) {
    if (!editingPet) return false;
    const saved = editingPet[field];
    return ["pet_name", "species"].includes(field)
      || (saved !== null && saved !== undefined && String(saved).trim() !== "")
      || editingPet.customer_locked_fields?.includes(field)
      || isClinicVerified(editingPet, field)
      || (["weight", "size"].includes(field)
        && ["weight", "size"].some(key => isClinicVerified(editingPet, key)));
  }
  function syncEditFieldLocks() {
    Object.entries(profileInputs).forEach(([field, input]) => {
      const locked = fieldLocked(field);
      input.disabled = locked;
      input.setAttribute("data-profile-locked", String(locked));
      document.getElementById(`${input.id}Lock`)?.classList.toggle("hidden", !locked);
    });
    petTypeCombobox.setDisabled(fieldLocked("species"));
    genderCombobox.setDisabled(fieldLocked("gender"));
    breedCombobox.setDisabled(fieldLocked("breed") || !petSpecies.value);
    breedCoatCombobox.setDisabled(fieldLocked("fur_type"));
    sizeCombobox.setDisabled(fieldLocked("size") || !petSpecies.value
      || (!fieldLocked("weight") && Boolean(getSizeForWeight(petSpecies.value, petWeight.value))));
    document.getElementById("petSavedDetailsHelp")?.classList.toggle("hidden", !editingPet);
    syncConfirmState();
  }
  function permittedChanges(payload) {
    return Object.fromEntries(Object.entries(payload).filter(([field, value]) =>
      !fieldLocked(field) && !petFieldValuesMatch(field, editingPet?.[field] ?? null, value)));
  }
  function syncConfirmState() {
    if (!editingPet) { petFormSubmit.disabled = false; return; }
    const changed = Object.entries(profileInputs).some(([field, input]) =>
      !fieldLocked(field) && input.value.trim() !== "");
    petFormSubmit.disabled = !changed || Boolean(validatePetForm(readPetPayload()));
  }
  function readPetPayload() {
    const payload = {
      pet_name: profileInputs.pet_name.value.trim(), species: petSpecies.value,
      breed: petBreed.value.trim() || null, gender: genderCombobox.getValue() || null,
      birthdate: petBirthdate.value || null, color: colorCombobox.getValue() || null,
      size: normalizePetSize(sizeCombobox.getValue()) || null, fur_type: petFurType.value || null,
      weight: getEnteredWeight(petWeight) || null, medical_conditions: profileInputs.medical_conditions.value.trim() || null,
    };
    if (editingPet) Object.keys(payload).forEach(field => {
      if (fieldLocked(field)) payload[field] = editingPet[field] ?? null;
    });
    return payload;
  }
  petForm.addEventListener("input", syncConfirmState);
  petForm.addEventListener("change", syncConfirmState);

  // ── Icons ─────────────────────────────────────────────
  // Phosphor sprite icons render directly without JavaScript hydration.

  // ── Profile ───────────────────────────────────────────
  const loadCustomerProfile = async () => {
    try {
      const { user } = await API.getMe("customer");
      const name = `${user.first_name} ${user.last_name}`;
      document.getElementById("clientProfileName").textContent = name;
      document.getElementById("clientProfileInitials").textContent =
        (user.first_name[0] + user.last_name[0]).toUpperCase();
    } catch {
      // silently fail — not critical
    }
  };

  // ── Logout ────────────────────────────────────────────
  logoutBtn?.addEventListener("click", async () => {
    try {
      await API.logout("customer");
    } finally {
      API.redirectToSignIn({ replace: true });
    }
  });

  // ── Sidebar toggle (mobile) ───────────────────────────
  const sidebarToggle   = document.getElementById("clientSidebarToggle");
  const sidebarBackdrop = document.getElementById("clientSidebarBackdrop");
  const sidebarClose    = document.getElementById("clientSidebarClose");
  const sidebar         = document.getElementById("clientSidebar");
  const mobileSidebarQuery = window.matchMedia("(max-width: 1180px)");
  const sidebarLinks = sidebar?.querySelectorAll("a") || [];

  function setSidebarState(isOpen) {
    document.body.classList.toggle("client-sidebar-open", isOpen);
    sidebarToggle?.setAttribute("aria-expanded", String(isOpen));
    sidebarToggle?.setAttribute(
      "aria-label",
      isOpen ? "Close navigation menu" : "Open navigation menu",
    );
  }

  function closeSidebar() {
    setSidebarState(false);
  }

  function toggleSidebar() {
    if (!mobileSidebarQuery.matches) return;
    const isOpen = document.body.classList.contains("client-sidebar-open");
    setSidebarState(!isOpen);
  }

  setSidebarState(false);
  sidebarToggle?.addEventListener("click", toggleSidebar);
  sidebarBackdrop?.addEventListener("click", closeSidebar);
  sidebarClose?.addEventListener("click", closeSidebar);
  sidebarLinks.forEach((link) => link.addEventListener("click", closeSidebar));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeSidebar();
  });
  mobileSidebarQuery.addEventListener("change", (event) => {
    if (!event.matches) closeSidebar();
  });

  // Notifications in the My Pets header.
  function closeNotificationDropdown() {
    if (!notificationBell || !notificationDropdown) return;

    notificationDropdown.style.display = "none";
    notificationBell.setAttribute("aria-expanded", "false");
  }

  function positionNotificationDropdown() {
    if (!notificationBell || !notificationDropdown) return;

    const bellBounds = notificationBell.getBoundingClientRect();
    const margin = 12;
    const width = Math.min(384, window.innerWidth - (margin * 2));

    notificationDropdown.style.top = `${bellBounds.bottom + 8}px`;
    notificationDropdown.style.right = `${Math.max(margin, window.innerWidth - bellBounds.right)}px`;
    notificationDropdown.style.left = "auto";
    notificationDropdown.style.width = `${width}px`;
  }

  async function loadNotifications({ force = false } = {}) {
    if (
      !notificationBadge
      || !notificationList
      || notificationsLoading
      || (!force && notificationsLoaded && API.readCustomerCache("/customer/notifications")?.fresh)
    ) return;
    notificationsLoading = true;

    try {
      await API.loadCustomerData("/customer/notifications", () => API.getCustomerNotifications({ force }), (data) => {
        const notifications = Array.isArray(data.notifications) ? data.notifications : [];
        const unreadCount = Number(data.unread_count || 0);
        window.ClientNotificationUI.setUnreadCount(notificationDropdown, unreadCount);
        notificationsLoaded = true;

        notificationBadge.textContent = unreadCount > 9 ? "9+" : String(unreadCount);
        notificationBadge.classList.toggle("hidden", unreadCount === 0);
        notificationBadge.classList.toggle("inline-flex", unreadCount > 0);

        window.ClientNotificationUI.render(notificationList, notifications, currentNotificationTab());

        notificationList.querySelectorAll("[data-client-notification-index]").forEach((button) => {
          button.addEventListener("click", async () => {
            const notification = notifications[Number(button.dataset.clientNotificationIndex)];

            try {
              await API.markCustomerNotificationRead(notification.id);
              if (notification.destination) {
                window.location.href = notification.destination;
                return;
              }
              await loadNotifications({ force: true });
            } catch {
              // Notifications are non-critical to pet profile management.
            }
          });
        });
      }, { force });
    } catch {
      notificationList.innerHTML = '<p class="px-4 py-6 text-center text-sm text-portal-muted">Notifications are unavailable.</p>';
    } finally {
      notificationsLoading = false;
    }
  }

  notificationBell?.addEventListener("click", () => {
    if (!notificationDropdown) return;

    if (notificationDropdown.style.display === "none") {
      void loadNotifications();
      positionNotificationDropdown();
      notificationDropdown.style.display = "flex";
      notificationBell.setAttribute("aria-expanded", "true");
      return;
    }

    closeNotificationDropdown();
  });

  markAllNotificationsRead?.addEventListener("click", async () => {
    try {
      await API.markAllCustomerNotificationsRead();
      await loadNotifications({ force: true });
    } catch {
      // Notifications are non-critical to pet profile management.
    }
  });

  document.addEventListener("click", (event) => {
    if (
      notificationDropdown?.style.display !== "none"
      && !notificationDropdown.contains(event.target)
      && !notificationBell?.contains(event.target)
    ) {
      closeNotificationDropdown();
    }
  });

  window.addEventListener("resize", () => {
    if (notificationDropdown?.style.display !== "none") {
      positionNotificationDropdown();
    }
  });

  // ── Load pets ─────────────────────────────────────────
  function syncAllKnownPets() {
    allKnownPets = [
      ...(activePetsCache || []),
      ...(archivedPetsCache || []),
    ];
  }

  function getPetCollectionCache(archived) {
    return archived ? archivedPetsCache : activePetsCache;
  }

  async function loadPetCollection(archived, { force = false } = {}) {
    const cachedPets = getPetCollectionCache(archived);
    const endpoint = `/pets?archived=${archived ? 1 : 0}`;
    const shared = API.readCustomerCache(endpoint);
    if (!force && cachedPets !== null && shared?.fresh) return cachedPets;

    const inFlightRequest = archived ? archivedPetsRequest : activePetsRequest;
    if (!force && inFlightRequest) return inFlightRequest;

    const requestGeneration = petCollectionGeneration;
    let request;
    request = API.loadCustomerData(endpoint,
      () => API.getUserPets({ archived: archived ? 1 : 0, force }),
      (data) => {
        const pets = Array.isArray(data.pets) ? data.pets : [];
        if (requestGeneration !== petCollectionGeneration) return;
        if (archived) archivedPetsCache = pets;
        else activePetsCache = pets;
        syncAllKnownPets();
        if (showingArchived === archived) {
          allPets = pets;
          petsLoadPending = false;
          applyFilter();
        }
      }, { force })
      .then((data) => Array.isArray(data.pets) ? data.pets : [])
      .finally(() => {
        if (archived && archivedPetsRequest === request) archivedPetsRequest = null;
        if (!archived && activePetsRequest === request) activePetsRequest = null;
      });

    if (archived) archivedPetsRequest = request;
    else activePetsRequest = request;

    return request;
  }

  function scheduleIdleTask(task) {
    if (typeof window.requestIdleCallback === "function") {
      window.requestIdleCallback(task, { timeout: 1200 });
      return;
    }

    window.setTimeout(task, 200);
  }

  function schedulePetCollectionPrefetch() {
    if (petCollectionPrefetchStarted) return;
    petCollectionPrefetchStarted = true;
    scheduleIdleTask(() => {
      loadPetCollection(!showingArchived)
        .catch(() => {
          // The visible collection remains usable if background prefetch fails.
        })
        .finally(() => scheduleIdleTask(async () => {
          await loadNotifications();
          scheduleIdleTask(loadCustomerProfile);
        }));
    });
  }

  function invalidatePetCollections() {
    petCollectionGeneration += 1;
    activePetsCache = null;
    archivedPetsCache = null;
    activePetsRequest = null;
    archivedPetsRequest = null;
    petCollectionPrefetchStarted = false;
    syncAllKnownPets();
  }

  async function loadPets({ force = false } = {}) {
    const archived = showingArchived;
    const generation = petCollectionGeneration;
    petsLoadPending = true;
    if (getPetCollectionCache(archived) === null && !API.readCustomerCache(`/pets?archived=${archived ? 1 : 0}`)) renderGrid(null);

    try {
      const pets = await loadPetCollection(archived, { force });
      if (showingArchived !== archived) return;
      if (generation !== petCollectionGeneration) return;
      if (petsLoadPending || allPets !== pets) {
        allPets = pets;
        petsLoadPending = false;
        applyFilter();
      }
    } catch (error) {
      if (showingArchived !== archived) return;
      if (generation !== petCollectionGeneration) return;
      allPets = getPetCollectionCache(archived) !== null && (error.status === 0 || error.status >= 500)
        ? getPetCollectionCache(archived) : [];
      petsLoadPending = false;
      applyFilter();
    }
    schedulePetCollectionPrefetch();
  }

  // ── Filter + search ───────────────────────────────────
  function applyFilter() {
    if (petsLoadPending) return;
    const q = petSearch.value.trim().toLowerCase();
    const filtered = q
      ? allPets.filter((p) => p.pet_name.toLowerCase().includes(q))
      : allPets;
    renderGrid(filtered);
  }

  petSearch.addEventListener("input", applyFilter);

  function setPetStatusFilter(archived) {
    showingArchived = archived;
    for (const [button, selected] of [
      [filterActiveBtn, !archived],
      [filterArchivedBtn, archived],
    ]) {
      button.setAttribute("aria-pressed", String(selected));
      button.className = selected
        ? "h-10 rounded-xl bg-portal-primary px-4 py-2 text-sm font-semibold text-white shadow-sm"
        : "h-10 rounded-xl px-4 py-2 text-sm font-semibold text-portal-text hover:bg-portal-surface-soft";
    }
    loadPets();
  }

  filterActiveBtn.addEventListener("click", () => setPetStatusFilter(false));
  filterArchivedBtn.addEventListener("click", () => setPetStatusFilter(true));

  // ── Render grid ───────────────────────────────────────
  function renderGrid(pets) {
    if (pets === null) {
      if (petsLoadingMessage) petsLoadingMessage.textContent = showingArchived ? "Loading archived pets..." : "Loading my pets...";
      petsLoadingScreen?.classList.remove("hidden");
      petsContent?.classList.add("hidden");
      petsContent?.setAttribute("inert", "");
      petsContent?.setAttribute("aria-hidden", "true");
      return;
    }

    petsLoadingScreen?.classList.add("hidden");
    petsContent?.classList.remove("hidden");
    petsContent?.removeAttribute("inert");
    petsContent?.removeAttribute("aria-hidden");

    if (pets.length === 0) {
      const msg = showingArchived
        ? "No archived pets."
        : "No pets yet. Add your first pet using the button above.";
      petsGrid.innerHTML = `
        <div class="col-span-full text-center py-16">
          <svg class="ph-icon w-10 h-10 mx-auto text-portal-muted-icon" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#paw-print"></use></svg>
          <p class="mt-3 text-portal-muted text-sm">${msg}</p>
        </div>`;

      return;
    }

    petsGrid.innerHTML = pets.map((pet) => buildCard(pet)).join("");


    petsGrid.querySelectorAll("[data-edit]").forEach((btn) => {
      btn.addEventListener("click", () => openEditModal(Number(btn.dataset.edit)));
    });
    petsGrid.querySelectorAll("[data-archive]").forEach((btn) => {
      btn.addEventListener("click", () => handleArchive(Number(btn.dataset.archive)));
    });
    petsGrid.querySelectorAll("[data-unarchive]").forEach((btn) => {
      btn.addEventListener("click", () => handleUnarchive(Number(btn.dataset.unarchive)));
    });
  }

  function buildCard(pet) {
    const sizeLabel = { small: "Small", medium: "Medium", large: "Large", extra_large: "Extra Large" };
    const furLabel  = { short: "Short", medium: "Medium", long: "Long", wire: "Wire", curl: "Curl" };
    const resolvedFurType = furLabel[pet.fur_type] || pet.fur_type;
    const speciesIcon = getPetSpeciesIcon(pet.species);

    const rows = [
      ["Breed", pet.breed],
      ["Size", sizeLabel[pet.size] ? `${sizeLabel[pet.size]}${isClinicVerified(pet, "size") ? " ✓" : ""}` : null],
      ["Fur Type", resolvedFurType],
      ["Weight", pet.weight ? `${formatSavedWeight(pet.weight)} kg` : null],
      ["Color", pet.color],
      ["Allergies & Special Care Notes", pet.medical_conditions],
    ].filter(([, v]) => v);

    const detailsHtml = rows.length
      ? rows.map(([label, val]) => `
          <div class="flex items-start justify-between gap-4 text-sm">
            <span class="shrink-0 text-portal-muted">${label}</span>
            <span class="min-w-0 break-words text-right font-semibold text-portal-text">${escHtml(String(val))}</span>
          </div>`).join("")
      : `<p class="text-sm text-portal-muted">No additional details.</p>`;

    const archiveBtn = pet.is_archived
      ? `<button type="button" data-unarchive="${pet.pet_id}"
            class="portal-button-secondary col-span-2 min-h-8 w-full gap-1.5 rounded-xl px-3 py-1.5 text-xs">
            <svg class="ph-icon w-4 h-4" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#arrow-counter-clockwise"></use></svg> Restore
          </button>`
      : `<button type="button" data-archive="${pet.pet_id}"
            class="portal-button-secondary min-h-8 w-full gap-1.5 rounded-xl px-3 py-1.5 text-xs">
            <svg class="ph-icon w-4 h-4" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#archive"></use></svg> Archive
          </button>`;

    const editBtn = pet.is_archived ? "" : `
      <button type="button" data-edit="${pet.pet_id}"
        class="portal-button-secondary min-h-8 w-full gap-1.5 rounded-xl px-3 py-1.5 text-xs">
        <svg class="ph-icon w-4 h-4" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#pencil-simple"></use></svg> Edit
      </button>`;

    const viewProfileLink = `
      <a href="./pet-details.html?pet_id=${encodeURIComponent(pet.pet_id)}"
        class="portal-button-primary col-span-2 min-h-8 w-full rounded-xl px-3 py-1.5 text-xs font-semibold">
        View profile
      </a>`;

    return `
      <div class="portal-card flex w-full max-w-[400px] flex-col gap-3 p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="flex min-w-0 items-center gap-3">
            <div class="portal-icon-tile h-10 w-10 rounded-xl">
              <svg class="ph-icon w-5 h-5 text-portal-primary" viewBox="0 0 256 256" aria-hidden="true" focusable="false"><use href="../../assets/icons/phosphor.svg#${speciesIcon}"></use></svg>
            </div>
            <div class="min-w-0 [overflow-wrap:anywhere]">
              <p class="font-bold text-portal-text text-base leading-tight">${escHtml(pet.pet_name)}</p>
              <p class="text-xs text-portal-muted mt-0.5">${escHtml(pet.species || "Pet")}</p>
            </div>
          </div>
          ${pet.is_archived ? `<span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-portal-muted">Archived</span>` : ""}
        </div>

        <div class="flex-1 space-y-1">
          ${detailsHtml}
        </div>

        <div class="grid grid-cols-2 gap-2 border-t border-portal-border pt-2">
          ${viewProfileLink}
          ${editBtn}
          ${archiveBtn}
        </div>
      </div>`;
  }

  // ── Modal ─────────────────────────────────────────────
  function getPetSpeciesIcon(species) {
    const normalizedSpecies = String(species || "").trim().toLowerCase();

    if (normalizedSpecies === "dog") return "dog";
    if (normalizedSpecies === "cat") return "cat";

    return "paw-print";
  }

  function openAddModal() {
    editingPet = null;
    syncEditVerifiedIndicators(null);
    petModalTitle.textContent = "Add Pet";
    syncEditFieldLocks();
    resetPetForm();
    syncEditFieldLocks();
    hideFormError();
    showModal();
  }

  async function openEditModal(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;

    editingPet = null;
    syncEditFieldLocks();
    editingPet = pet;
    syncEditVerifiedIndicators(pet);
    petModalTitle.textContent = "Edit Pet";
    document.getElementById("petId").value = pet.pet_id;
    document.getElementById("petName").value = pet.pet_name || "";
    petTypeCombobox.setValue(normalizeSpecies(pet.species));
    genderCombobox.setValue(String(pet.gender || "").toLowerCase());
    petBirthdate.value = pet.birthdate || "";
    breedCombobox.setValue(pet.breed || "");
    petFurType.value = pet.fur_type || "";
    breedCoatCombobox.update({ preserveValue: true, autoSelect: false });
    colorCombobox.setValue(pet.color, { preserveLegacy: true });
    document.getElementById("petMedical").value = pet.medical_conditions || "";

    initializeWeightField(petWeight);
    petWeight.value = formatSavedWeight(pet.weight);
    syncWeightAndSize({ clearManualSize: true });
    setStoredSize(pet.size);

    await breedCoatCatalogueReady;
    breedCoatCombobox.update({ preserveValue: true, autoSelect: false });

    syncEditFieldLocks();
    hideFormError();
    showModal();
  }

  function showModal() {
    petModalReturnFocus = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    petsPageShell.inert = true;
    petModal.classList.remove("hidden");
    petModal.classList.add("flex");
    closePetModal.focus({ preventScroll: true });
  }

  function closeModal() {
    colorCombobox.close();
    petModal.classList.add("hidden");
    petModal.classList.remove("flex");
    editingPet = null;
    petsPageShell.inert = !petConfirmationModal.classList.contains("hidden");
    const returnFocus = petModalReturnFocus;
    petModalReturnFocus = null;
    if (returnFocus?.isConnected && !returnFocus.disabled) {
      returnFocus.focus({ preventScroll: true });
    } else {
      addPetBtn.focus({ preventScroll: true });
    }
  }

  function trapPetModalFocus(event) {
    if (event.key !== "Tab" || event.defaultPrevented) return;
    const controls = [...event.currentTarget.querySelectorAll(
      "button, input, select, textarea, a[href], [tabindex]",
    )].filter((element) => element.tabIndex >= 0 && !element.matches(":disabled")
      && !element.closest("[inert]") && element.getClientRects().length);
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) {
      event.preventDefault();
      first?.focus();
    }
  }

  addPetBtn.addEventListener("click", openAddModal);
  closePetModal.addEventListener("click", closeModal);
  cancelPetConfirmation.addEventListener("click", () => resolvePetConfirmation(false));
  confirmPetAction.addEventListener("click", () => resolvePetConfirmation(true));
  for (const modal of [petModal, petConfirmationModal]) {
    modal.addEventListener("pointerdown", (event) => {
      if (event.target === modal) event.preventDefault();
    });
    modal.addEventListener("keydown", trapPetModalFocus);
  }
  petSpecies.addEventListener("change", () => {
    syncWeightAndSize({ clearManualSize: true });
    validateWeightOnCommit();
  });
  petWeight.addEventListener("input", () => syncWeightAndSize());
  petWeight.addEventListener("change", validateWeightOnCommit);
  petSize.addEventListener("change", handleManualSizeChange);

  // ── Form submit ───────────────────────────────────────
  petForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    hideFormError();
    const id = document.getElementById("petId").value;
    const weight = getEnteredWeight(petWeight);
    const payload = readPetPayload();

    const validationMessage = validatePetForm(payload);
    if (validationMessage) {
      colorCombobox.showValidation();
      breedCombobox.showValidation();
      breedCoatCombobox.showValidation();
      const weightMessage = getWeightFieldValidationMessage(petWeight)
        || getWeightValidationMessage(payload.species, weight);
      if (weightMessage) {
        showWeightValidationInField(petWeight, weightMessage);
      }
      if (validationMessage === colorCombobox.getValidationMessage()) {
        document.getElementById("petColor").focus();
      } else if (validationMessage === breedCoatCombobox.getValidationMessage()) {
        petFurType.focus();
      } else if (validationMessage === breedCombobox.getValidationMessage()) {
        petBreed.focus();
      } else {
        showFormError(validationMessage);
      }
      return;
    }

    const changes = id ? permittedChanges(payload) : payload;
    if (id && Object.keys(changes).length === 0) { syncConfirmState(); return; }

    petFormSubmit.disabled = true;
    petFormSubmit.textContent = "Confirming...";
    if (document.activeElement === petFormSubmit) {
      closePetModal.focus({ preventScroll: true });
    }

    try {
      if (id) {
        await API.updatePet(id, changes);
      } else {
        await API.addPet(payload);
      }
      closeModal();
      invalidatePetCollections();
      await loadPets();
    } catch (err) {
      if (err.errors?.color?.[0]) {
        colorCombobox.showValidation(err.errors.color[0]);
        document.getElementById("petColor").focus();
        return;
      }
      if (err.errors?.fur_type?.[0]) {
        breedCoatCombobox.showValidation(err.errors.fur_type[0]);
        petFurType.focus();
        return;
      }
      if (err.errors?.weight?.[0]) showWeightValidationInField(petWeight, err.errors.weight[0]);
      showFormError(err.errors
        ? Object.values(err.errors).flat()[0]
        : (err.message || "Something went wrong."));
    } finally {
      petFormSubmit.disabled = false;
      petFormSubmit.textContent = "Confirm";
      syncConfirmState();
    }
  });

  // ── Archive / Unarchive ───────────────────────────────
  async function handleArchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    const confirmed = await openPetConfirmation({
      title: "Archive pet?",
      message: `Archive ${pet.pet_name}? This pet will be hidden from the booking form.`,
      confirmLabel: "Archive pet",
    });
    if (!confirmed) return;
    try {
      await API.archivePet(id);
      invalidatePetCollections();
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not archive pet.");
    }
  }

  async function handleUnarchive(id) {
    const pet = allPets.find((p) => p.pet_id === id);
    if (!pet) return;
    const confirmed = await openPetConfirmation({
      title: "Restore pet?",
      message: `Restore ${pet.pet_name} to your active pets?`,
      confirmLabel: "Restore pet",
    });
    if (!confirmed) return;
    try {
      await API.unarchivePet(id);
      invalidatePetCollections();
      await loadPets();
    } catch (err) {
      alert(err.message || "Could not restore pet.");
    }
  }

  // ── Helpers ───────────────────────────────────────────
  function showFormError(msg) {
    petFormError.textContent = msg;
    petFormError.classList.remove("hidden");
  }

  function hideFormError() {
    petFormError.textContent = "";
    petFormError.classList.add("hidden");
  }

  function openPetConfirmation({ title, message, details = "", confirmLabel }) {
    confirmationReturnFocus = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    petConfirmationTitle.textContent = title;
    petConfirmationMessage.textContent = message;
    petConfirmationDetails.textContent = details;
    petConfirmationDetails.classList.toggle("hidden", !details);
    confirmPetAction.textContent = confirmLabel;
    petsPageShell.inert = true;
    petModal.inert = !petModal.classList.contains("hidden");
    petConfirmationModal.classList.remove("hidden");
    petConfirmationModal.classList.add("flex");

    petConfirmationCard.focus({ preventScroll: true });

    return new Promise((resolve) => {
      confirmationResolver = resolve;
    });
  }

  function resolvePetConfirmation(confirmed) {
    if (!confirmationResolver) return;

    const resolve = confirmationResolver;
    const returnFocus = confirmationReturnFocus;
    confirmationResolver = null;
    confirmationReturnFocus = null;
    petConfirmationModal.classList.add("hidden");
    petConfirmationModal.classList.remove("flex");
    petModal.inert = false;
    const editing = !petModal.classList.contains("hidden");
    petsPageShell.inert = editing;
    const fallbackFocus = editing ? petFormSubmit : addPetBtn;
    (returnFocus?.isConnected && !returnFocus.disabled ? returnFocus : fallbackFocus)
      .focus({ preventScroll: true });
    resolve(confirmed);
  }

  function isClinicVerified(pet, field) {
    return Boolean(field)
      && Array.isArray(pet?.clinic_verified_fields)
      && pet.clinic_verified_fields.includes(field);
  }

  function syncEditVerifiedIndicators(pet) {
    Object.entries(editVerifiedIndicators).forEach(([field, indicator]) => {
      indicator?.classList.toggle("hidden", !isClinicVerified(pet, field));
    });
  }

  function petFieldValuesMatch(field, original, updated) {
    if (field === "weight") {
      if ((original === null || original === "")
        && (updated === null || updated === "")) {
        return true;
      }

      const originalWeight = Number(original);
      const updatedWeight = Number(updated);

      return Number.isFinite(originalWeight)
        && Number.isFinite(updatedWeight)
        && Math.abs(originalWeight - updatedWeight) < 0.001;
    }

    const normalize = (value) => value === null || String(value).trim() === ""
      ? null
      : String(value).trim();

    return normalize(original) === normalize(updated);
  }

  function normalizeSpecies(value) {
    return ({ dog: "Dog", cat: "Cat" })[String(value || "").trim().toLowerCase()] || String(value || "");
  }

  function resetPetForm() {
    document.getElementById("petId").value = "";
    document.getElementById("petName").value = "";
    colorCombobox.reset();
    document.getElementById("petMedical").value = "";
    petBirthdate.value = "";

    petTypeCombobox.setValue("");
    genderCombobox.reset();
    breedCombobox.reset();
    breedCoatCombobox.reset();
    initializeWeightField(petWeight);
    syncWeightAndSize({ clearManualSize: true });
  }

  function setStoredSize(storedSize) {
    const normalizedStoredSize = normalizePetSize(storedSize);
    const matchingOption = getSizeOptions(petSpecies.value).find(
      ({ value }) => normalizePetSize(value) === normalizedStoredSize,
    );

    sizeCombobox.setValue(matchingOption?.value || "");
  }

  function syncWeightAndSize({ clearManualSize = false } = {}) {
    const previousMessage = getWeightFieldValidationMessage(petWeight);
    if (editingPet && (fieldLocked("weight") || fieldLocked("size"))) {
      sizeCombobox.setOptions(getSizeOptions(petSpecies.value));
      if (fieldLocked("size")) setStoredSize(editingPet.size);
      if (!fieldLocked("weight")) showWeightValidationInField(petWeight, getWeightValidationMessage(petSpecies.value, getEnteredWeight(petWeight)));
      syncEditFieldLocks();
    } else {
      syncWeightSizeFields(petWeight, petSpecies.value, sizeCombobox, { clearManualSize });
    }
    if (previousMessage && petFormError.textContent === previousMessage) hideFormError();
  }

  function validateWeightOnCommit() {
    syncWeightAndSize();
    return !getWeightFieldValidationMessage(petWeight);
  }

  function handleManualSizeChange() {
    syncWeightAndSize();
  }

  function validatePetForm(payload) {
    if (!editingPet && !payload.pet_name) return "Pet name is required.";
    if (!editingPet && !payload.species) return "Species is required.";
    const normalizedPetName = normalizePetNameForComparison(payload.pet_name);
    const duplicatePetName = allKnownPets.some((pet) => (
      String(pet.pet_id) !== String(editingPet?.pet_id ?? "")
      && normalizePetNameForComparison(pet.pet_name) === normalizedPetName
    ));

    if (duplicatePetName) {
      return "You already have a pet with this name.";
    }

    const colorMessage = colorCombobox.getValidationMessage();
    if (colorMessage) return colorMessage;
    if ((payload.medical_conditions || "").length > 1000) return "Care notes must be 1,000 characters or fewer.";

    const breedValidationMessage = fieldLocked("breed") ? "" : breedCombobox.getValidationMessage();
    if (breedValidationMessage) {
      return breedValidationMessage;
    }

    const furTypeValidationMessage = fieldLocked("fur_type") ? "" : breedCoatCombobox.getValidationMessage();
    if (furTypeValidationMessage) {
      return furTypeValidationMessage;
    }

    const weightValidationMessage = fieldLocked("weight") ? "" : (getWeightFieldValidationMessage(petWeight)
      || getWeightValidationMessage(payload.species, payload.weight));
    if (weightValidationMessage) {
      return weightValidationMessage;
    }

    const allowedSizes = getSizeOptions(payload.species).map(
      ({ value }) => normalizePetSize(value),
    );
    if (!fieldLocked("size") && payload.size && !allowedSizes.includes(payload.size)) {
      return "Select a size available for this pet type.";
    }

    return "";
  }

  function normalizePetNameForComparison(value) {
    return String(value ?? "")
      .trim()
      .replace(/\s+/g, " ")
      .toLocaleLowerCase();
  }

  function escHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  // ── Init ──────────────────────────────────────────────
  loadPets();
  window.setInterval(() => {
    if (document.visibilityState === "visible") void loadNotifications({ force: true });
  }, 30000);

  if (new URLSearchParams(window.location.search).get("add") === "1") {
    openAddModal();
  }
});
