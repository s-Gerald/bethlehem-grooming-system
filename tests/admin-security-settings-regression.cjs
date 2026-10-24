const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const componentSource = fs.readFileSync(
  path.join(root, "scripts/components/admin-settings.js"),
  "utf8",
);

const calls = [];
const toasts = [];
let settings;
let statusRequest;
let cancelRequest;
let roleRequest;
const focusedFields = [];
const cooldownTicks = new Map();
const deliveryChecks = new Map();
global.window = {
  setInterval(callback) { cooldownTicks.set(1, callback); return 1; },
  clearInterval(id) { cooldownTicks.delete(id); },
  setTimeout(callback) { deliveryChecks.set(1, callback); return 1; },
  clearTimeout(id) { deliveryChecks.delete(id); },
  lucide: null,
  showSuccessToast(message) {
    assert.equal(settings.staffStatusModal.open, false);
    assert.equal(settings.staffSetupModal.open, false);
    toasts.push(message);
  },
};
global.document = {
  getElementById(id) {
    return { focus() { focusedFields.push(id); } };
  },
};
global.API = {
  async emailDeliveryStatus() { return { status: 'failed' }; },
  async getAdminSecurityAccounts() {
    return {
      admin: {
        user_id: 1,
        first_name: "Admin",
        last_name: "Bethlehem",
        username: "Admin",
        email: "bethlehem.admin.test@gmail.com",
        is_active: true,
        is_archived: false,
      },
      staff: [
        {
          user_id: 2,
          first_name: "Grooming",
          last_name: "Staff",
          username: "groomingstaff",
          email: "bethlehem.staff.test@gmail.com",
          staff_type: "grooming",
          staff_subrole: "grooming_receptionist",
          is_active: true,
          is_archived: false,
          password_setup_required: false,
        },
        {
          user_id: 3,
          first_name: "Clinic",
          last_name: "Staff",
          username: null,
          email: "clinic.staff.test@gmail.com",
          staff_type: "clinic",
          staff_subrole: "veterinarian",
          is_active: true,
          is_archived: false,
          password_setup_required: true,
        },
        {
          user_id: 4,
          first_name: "Former",
          last_name: "Staff",
          username: "formerstaff",
          email: "former.staff.test@gmail.com",
          staff_type: "clinic",
          staff_subrole: "clinic_receptionist",
          is_active: false,
          is_archived: false,
          password_setup_required: false,
        },
      ],
    };
  },
  async requestAdminCredentialChange(payload) {
    calls.push(["admin", payload]);
    return {
      change_id: 11,
      purpose: "Admin username change",
      target_name: "Admin Bethlehem",
      confirmation_email: "b**************@gmail.com",
    };
  },
  async confirmSecurityCredentialChange(changeId, code) {
    calls.push(["confirm", changeId, code]);
    return {
      message: "Admin username change confirmed.",
      requires_reauthentication: false,
    };
  },
  async resendSecurityCredentialChangeCode(changeId) {
    calls.push(["resend", changeId]);
    return {
      message: "A new security code was sent.",
      confirmation_email: "b**************@gmail.com",
    };
  },
  async requestStaffAccount(payload) {
    calls.push(["add-staff", payload]);
    return {
      message: "Account setup email sent",
      staff: { username: null },
    };
  },
  async updateStaffAccountStatus(staffId, active, password) {
    calls.push(["staff-status", staffId, active, password]);
    if (statusRequest) return statusRequest;
    if (password !== "CurrentAdmin!234") {
      throw { errors: { current_password: ["The admin password is incorrect."] } };
    }
    return {
      message: active ? "Staff account reactivated." : "Staff account deactivated.",
    };
  },
  async updateStaffAccountRole(staffId, staffRole, password) {
    calls.push(["staff-role", staffId, staffRole, password]);
    if (roleRequest) return roleRequest;
    if (password !== "CurrentAdmin!234") {
      throw { errors: { current_password: ["Incorrect password. Try again."] } };
    }
    return {
      staff: {
        staff_type: staffRole === "grooming_receptionist" ? "grooming" : "clinic",
        staff_subrole: staffRole,
      },
    };
  },
  async resendStaffSetupEmail(staffId) {
    calls.push(["staff-resend", staffId]);
    return { message: "A new setup email was sent." };
  },
  async cancelStaffSetup(staffId, password) {
    calls.push(["staff-cancel", staffId, password]);
    if (cancelRequest) return cancelRequest;
    if (password !== "CurrentAdmin!234") {
      throw { errors: { current_password: ["The admin password is incorrect."] } };
    }
    return { message: "Account setup cancelled." };
  },
  clearAuthState() {},
  redirectToSignIn() {},
};

vm.runInThisContext(fs.readFileSync(path.join(root, 'scripts/auth/email-delivery.js'), 'utf8'));
vm.runInThisContext(componentSource, {
  filename: "scripts/components/admin-settings.js",
});

(async () => {
  settings = adminSettings();
  settings.$nextTick = (callback) => callback();

  await settings.loadSecurityAccounts();
  assert.equal(settings.adminAccount.username, "Admin");
  assert.equal(settings.staffAccounts.length, 3);
  assert.equal(settings.staffAccounts[0].username, "groomingstaff");
  assert.equal(settings.staffAccounts[0].email, "bethlehem.staff.test@gmail.com");
  assert.equal(settings.staffAccounts[0].roleLabel, "Grooming Receptionist");
  assert.equal(settings.staffAccounts[0].statusLabel, "Active");
  assert.equal(settings.staffAccounts[1].statusLabel, "Pending setup");
  assert.equal(settings.staffAccounts[1].username, "");
  assert.equal(settings.activeStaffCount, 1);
  assert.equal(settings.pendingStaffCount, 1);
  assert.equal(settings.deactivatedStaffCount, 1);
  assert.equal(settings.staffAccounts[2].statusLabel, "Deactivated");

  settings.adminPassword.current = "CurrentAdmin!234";
  settings.adminPassword.username = "ClinicAdmin";
  await settings.submitAdminPassword();
  assert.equal(settings.adminPassword.error, "");
  assert.equal(settings.securityVerification.open, true);
  assert.equal(settings.securityVerification.changeId, 11);
  assert.equal(settings.securityVerification.purpose, "Admin username change");
  assert.equal(calls[0][0], "admin");
  assert.equal(calls[0][1].username, "ClinicAdmin");

  settings.closeSecurityVerification();

  const staff = settings.staffAccounts[0];
  settings.openStaffDetails(staff);
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.staffDetailsModal.staff.fullName, "Grooming Staff");
  assert.equal(settings.staffDetailsModal.staff.statusLabel, "Active");
  settings.closeStaffDetails();
  assert.equal(settings.staffDetailsModal.open, false);

  settings.openSecurityVerification({
    change_id: 11,
    purpose: "Admin username change",
    target_name: "Admin Bethlehem",
    confirmation_email: "b**************@gmail.com",
  });
  settings.securityVerification.digits = ["1", "2", "3", "4", "5", "6"];
  await settings.confirmSecurityCredentialChange();
  assert.deepEqual(calls[1], ["confirm", 11, "123456"]);
  assert.equal(settings.securityVerification.open, false);
  assert.equal(settings.staffNotice, "Admin username change confirmed.");

  for (const digits of [['1', '2', '3', '4', '5', '6'], ['1', '2', '', '', '', '']]) {
    settings.openSecurityVerification({ change_id: 12, resend_after: 0 });
    settings.securityVerification.digits = digits;
    settings.securityVerification.error = 'Invalid code';
    let reject;
    let requests = 0;
    const original = API.resendSecurityCredentialChangeCode;
    API.resendSecurityCredentialChangeCode = () => { requests++; return new Promise((_resolve, fail) => { reject = fail; }); };
    const sending = settings.resendSecurityCredentialChangeCode();
    assert.deepEqual(settings.securityVerification.digits, ['', '', '', '', '', '']);
    assert.equal(settings.securityCodeComplete, false);
    assert.equal(settings.securityVerification.error, '');
    await settings.resendSecurityCredentialChangeCode();
    assert.equal(requests, 1);
    reject({ status: 429, retryAfter: 128, message: 'Please wait.' });
    await sending;
    assert.equal(settings.securityVerification.resendRemaining, 128);
    API.resendSecurityCredentialChangeCode = original;
    settings.closeSecurityVerification();
    assert.equal(cooldownTicks.size, 0);
  }
  settings.openSecurityVerification({ change_id: 13, resend_after: 0, email_delivery_token: 'private-admin-receipt' });
  await new Promise(resolve => setImmediate(resolve));
  assert.match(settings.securityVerification.error, /Couldn't send the code/);
  settings.closeSecurityVerification();
  assert.equal(deliveryChecks.size, 0);

  settings.openSecurityVerification({ change_id: 14, resend_after: 0 });
  let finishResend;
  const originalResend = API.resendSecurityCredentialChangeCode;
  API.resendSecurityCredentialChangeCode = () => new Promise(resolve => { finishResend = resolve; });
  const oldResend = settings.resendSecurityCredentialChangeCode();
  settings.closeSecurityVerification();
  settings.openSecurityVerification({ change_id: 15, resend_after: 0, confirmation_email: 'n***@example.test' });
  finishResend({ confirmation_email: 'o***@example.test', resend_after: 90 });
  await oldResend;
  assert.equal(settings.securityVerification.email, 'n***@example.test', 'A stale resend must not update another challenge');
  assert.equal(settings.securityVerification.resendRemaining, 0);
  API.resendSecurityCredentialChangeCode = originalResend;
  settings.closeSecurityVerification();

  settings.openAddStaffAccount();
  settings.chooseStaffType("clinic");
  assert.equal(settings.addStaffModal.step, "role");
  assert.equal(settings.addStaffModal.roleStage, "clinic");
  settings.handleAddStaffClose();
  assert.equal(settings.addStaffModal.open, true);
  assert.equal(settings.addStaffModal.roleStage, "main");
  assert.equal(settings.addStaffModal.staffType, "");
  settings.handleAddStaffClose();
  assert.equal(settings.addStaffModal.open, false);

  settings.openAddStaffAccount();
  settings.chooseStaffType("clinic");
  settings.chooseClinicSubrole("veterinarian");
  assert.equal(settings.addStaffModal.step, "details");
  assert.equal(settings.addStaffModal.staffSubrole, "veterinarian");
  settings.addStaffModal.firstName = "John";
  settings.addStaffModal.lastName = "Smith";
  settings.addStaffModal.email = "invalid@-example..com";
  await settings.requestNewStaffAccount();
  assert.match(settings.addStaffModal.error, /valid email address/);
  assert.equal(calls.some((call) => call[0] === "add-staff"), false);
  settings.addStaffModal.email = "clinic.staff@example.test";
  await settings.requestNewStaffAccount();
  assert.equal(settings.addStaffModal.step, "success");
  assert.equal("createdUsername" in settings.addStaffModal, false);
  assert.equal("username" in settings.addStaffModal, false);
  assert.deepEqual(calls.find((call) => call[0] === "add-staff"), [
    "add-staff",
    {
      staff_type: "clinic",
      staff_subrole: "veterinarian",
      first_name: "John",
      last_name: "Smith",
      email: "clinic.staff@example.test",
    },
  ]);

  settings.openStaffStatusModal(settings.staffAccounts[0]);
  assert.equal(focusedFields.at(-1), "staffStatusAdminPassword");
  await settings.updateStaffStatus();
  assert.equal(calls.some((call) => call[0] === "staff-status"), false);
  assert.equal(settings.staffStatusModal.open, true);
  settings.staffStatusModal.password = "CurrentStaff!234";
  await settings.updateStaffStatus();
  assert.equal(settings.staffStatusModal.open, true);
  assert.equal(settings.staffStatusModal.error, "The admin password is incorrect.");
  settings.staffStatusModal.password = "CurrentAdmin!234";
  let finishStatus;
  statusRequest = new Promise((resolve) => { finishStatus = resolve; });
  const updating = settings.updateStaffStatus();
  const statusCalls = calls.filter((call) => call[0] === "staff-status").length;
  await settings.updateStaffStatus();
  assert.equal(calls.filter((call) => call[0] === "staff-status").length, statusCalls);
  assert.equal(settings.staffStatusModal.busy, true);
  finishStatus({ message: "Staff account deactivated." });
  await updating;
  statusRequest = null;
  assert.deepEqual(
    calls.filter((call) => call[0] === "staff-status").at(-1),
    ["staff-status", 2, false, "CurrentAdmin!234"],
  );
  assert.equal(settings.staffStatusModal.open, false);
  assert.equal(settings.staffStatusModal.password, "");
  assert.equal(settings.staffStatusModal.error, "");
  assert.equal(toasts.length, 0);
  await new Promise((resolve) => setTimeout(resolve, 250));
  assert.equal(toasts.at(-1), "Grooming Staff was deactivated and signed out.");

  settings.openStaffStatusModal(settings.staffAccounts[2]);
  assert.equal(focusedFields.at(-1), "staffStatusAdminPassword");
  settings.staffStatusModal.password = "CurrentAdmin!234";
  await settings.updateStaffStatus();
  await new Promise((resolve) => setTimeout(resolve, 250));
  assert.equal(toasts.at(-1), "Former Staff was reactivated and can sign in again.");

  settings.openStaffStatusModal(settings.staffAccounts[0]);
  settings.staffStatusModal.password = "discard";
  settings.staffStatusModal.showPassword = true;
  settings.staffStatusModal.error = "The admin password is incorrect.";
  settings.closeStaffStatusModal();
  assert.equal(settings.staffStatusModal.password, "");
  assert.equal(settings.staffStatusModal.showPassword, false);
  assert.equal(settings.staffStatusModal.error, "");

  settings.openStaffStatusModal({
    ...settings.staffAccounts[2],
    fullName: "Staff account",
  });
  settings.staffStatusModal.password = "CurrentAdmin!234";
  await settings.updateStaffStatus();
  await new Promise((resolve) => setTimeout(resolve, 250));
  assert.equal(toasts.at(-1), "Clinic Receptionist was reactivated and can sign in again.");

  const pending = settings.staffAccounts[1];
  await settings.resendStaffSetup(pending);
  assert.deepEqual(calls.find((call) => call[0] === "staff-resend"), ["staff-resend", 3]);
  settings.openStaffSetupModal(pending);
  assert.equal(settings.staffSetupModal.open, true);
  await settings.cancelStaffSetup();
  assert.equal(calls.some((call) => call[0] === "staff-cancel"), false);
  settings.staffSetupModal.password = "wrong";
  await settings.cancelStaffSetup();
  assert.equal(settings.staffSetupModal.open, true);
  assert.equal(settings.staffSetupModal.error, "The admin password is incorrect.");
  settings.staffSetupModal.password = "CurrentAdmin!234";
  let finishCancel;
  cancelRequest = new Promise((resolve) => { finishCancel = resolve; });
  const cancelling = settings.cancelStaffSetup();
  const cancelCalls = calls.filter((call) => call[0] === "staff-cancel").length;
  await settings.cancelStaffSetup();
  assert.equal(calls.filter((call) => call[0] === "staff-cancel").length, cancelCalls);
  assert.equal(settings.staffSetupModal.busy, true);
  finishCancel({ message: "Account setup cancelled." });
  await cancelling;
  assert.deepEqual(calls.filter((call) => call[0] === "staff-cancel").at(-1), ["staff-cancel", 3, "CurrentAdmin!234"]);
  assert.equal(settings.staffSetupModal.open, false);
  assert.equal(settings.staffSetupModal.password, "");
  await new Promise((resolve) => setTimeout(resolve, 250));
  assert.equal(toasts.at(-1), "Account setup cancelled.");
  settings.openStaffSetupModal(pending);
  settings.staffSetupModal.password = "discard";
  settings.staffSetupModal.showPassword = true;
  settings.closeStaffSetupModal();
  assert.equal(settings.staffSetupModal.password, "");
  assert.equal(settings.staffSetupModal.showPassword, false);

  for (const [index, savedRole] of [[0, "grooming_receptionist"], [1, "veterinarian"], [2, "clinic_receptionist"]]) {
    settings.openStaffDetails(settings.staffAccounts[index]);
    assert.equal(settings.staffDetailsModal.selectedRole, savedRole);
    assert.equal(settings.staffRoleChangePending, false);
  }
  settings.openStaffDetails(settings.staffAccounts[0]);
  settings.staffDetailsModal.selectedRole = "veterinarian";
  settings.changeStaffRole();
  assert.equal(settings.staffRoleChangePending, true);
  assert.equal(calls.some((call) => call[0] === "staff-role"), false);
  await settings.saveStaffRoleChange();
  assert.equal(calls.some((call) => call[0] === "staff-role"), false);
  settings.staffDetailsModal.password = "CurrentStaff!234";
  await settings.saveStaffRoleChange();
  assert.equal(settings.staffDetailsModal.error, "Incorrect password. Try again.");
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.staffDetailsModal.selectedRole, "veterinarian");
  assert.equal(settings.savedStaffRole, "grooming_receptionist");
  assert.equal(settings.staffAccounts[0].roleLabel, "Grooming Receptionist");

  settings.staffDetailsModal.showPassword = true;
  settings.cancelStaffRoleChange();
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.staffDetailsModal.selectedRole, "grooming_receptionist");
  assert.equal(settings.staffDetailsModal.password, "");
  assert.equal(settings.staffDetailsModal.error, "");
  assert.equal(settings.staffDetailsModal.showPassword, false);
  assert.equal(settings.staffRoleChangePending, false);

  settings.staffDetailsModal.selectedRole = "clinic_receptionist";
  settings.changeStaffRole();
  settings.staffDetailsModal.password = "discard";
  settings.staffDetailsModal.showPassword = true;
  settings.staffDetailsModal.error = "Discard error";
  settings.staffDetailsModal.selectedRole = "grooming_receptionist";
  settings.changeStaffRole();
  assert.equal(settings.staffRoleChangePending, false);
  assert.equal(settings.staffDetailsModal.password, "");
  assert.equal(settings.staffDetailsModal.error, "");
  assert.equal(settings.staffDetailsModal.showPassword, false);

  settings.staffDetailsModal.selectedRole = "veterinarian";
  settings.changeStaffRole();
  settings.staffDetailsModal.password = "CurrentAdmin!234";
  let finishRole;
  roleRequest = new Promise((resolve) => { finishRole = resolve; });
  const savingRole = settings.saveStaffRoleChange();
  const roleCalls = calls.filter((call) => call[0] === "staff-role").length;
  await settings.saveStaffRoleChange();
  settings.closeStaffDetails();
  settings.cancelStaffRoleChange();
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.staffDetailsModal.selectedRole, "veterinarian");
  assert.equal(settings.staffDetailsModal.busy, true);
  assert.equal(calls.filter((call) => call[0] === "staff-role").length, roleCalls);
  finishRole({ staff: { staff_type: "clinic", staff_subrole: "veterinarian" } });
  await savingRole;
  roleRequest = null;
  assert.equal(settings.staffDetailsModal.open, true);
  assert.equal(settings.savedStaffRole, "veterinarian");
  assert.equal(settings.staffDetailsModal.selectedRole, "veterinarian");
  assert.equal(settings.staffDetailsModal.password, "");
  assert.equal(settings.staffRoleChangePending, false);
  assert.equal(settings.staffDetailsModal.busy, false);
  assert.equal(settings.staffAccounts[0].staffType, "clinic");
  assert.equal(settings.staffAccounts[0].subroleLabel, "Veterinarian");
  assert.equal(toasts.at(-1), "Role updated successfully");
  assert.equal(settings.staffAccounts[0].active, true);
  settings.staffDetailsModal.selectedRole = "grooming_receptionist";
  settings.changeStaffRole();
  settings.staffDetailsModal.password = "CurrentAdmin!234";
  await settings.saveStaffRoleChange();
  assert.equal(settings.savedStaffRole, "grooming_receptionist");
  assert.equal(settings.staffAccounts[0].staffSubrole, "grooming_receptionist");
  assert.equal(settings.staffAccounts[0].subroleLabel, "");
  assert.equal(settings.staffAccounts[0].roleLabel, "Grooming Receptionist");

  settings.staffDetailsModal.selectedRole = "veterinarian";
  settings.changeStaffRole();
  settings.staffDetailsModal.password = "discard";
  settings.closeStaffDetails();
  assert.equal(settings.staffDetailsModal.password, "");
  settings.openStaffDetails(settings.staffAccounts[2]);
  assert.equal(settings.staffDetailsModal.selectedRole, "clinic_receptionist");
  assert.equal(settings.staffDetailsModal.error, "");
  settings.openStaffDetails({ staffType: "clinic", staffSubrole: "" });
  assert.equal(settings.staffDetailsModal.selectedRole, "");
  assert.equal(settings.staffRoleChangePending, false);
  settings.staffDetailsModal.selectedRole = "veterinarian";
  settings.changeStaffRole();
  assert.equal(settings.staffRoleChangePending, true);

  settings.openAddStaffAccount();
  settings.chooseStaffType("grooming");
  assert.equal(settings.addStaffModal.staffSubrole, "grooming_receptionist");
  settings.addStaffModal.firstName = "New";
  settings.addStaffModal.lastName = "Receptionist";
  settings.addStaffModal.email = "new.grooming@example.test";
  await settings.requestNewStaffAccount();
  assert.equal(calls.filter((call) => call[0] === "add-staff").at(-1)[1].staff_subrole, "grooming_receptionist");
  assert.equal(settings.accountRoleLabel({ staff_type: "grooming", staff_subrole: "grooming_receptionist" }), "Grooming Receptionist");
  assert.equal(settings.accountRoleLabel({ staff_type: "grooming", staff_subrole: null }), "Staff");
  settings.openStaffDetails({ staffType: "grooming", staffSubrole: "" });
  assert.equal(settings.savedStaffRole, "");

  API.getUserRole = () => 'staff';
  const savedAccount = { user_id: 7, first_name: 'Grooming', last_name: 'Staff', username: 'groomingstaff',
    role: 'staff', staff_type: 'grooming', staff_subrole: 'grooming_receptionist', email: 'staff@example.test', is_active: true, is_archived: false };
  API.getSettingsSecurityAccount = async () => ({ account: savedAccount });
  const profileCalls = [];
  API.validateStaffProfile = async payload => { profileCalls.push(['validate', payload]); return { success: true }; };
  API.updateStaffProfile = async payload => { profileCalls.push(['save', payload]); return { account: { ...savedAccount, ...payload } }; };
  settings = adminSettings();
  settings.$nextTick = callback => callback();
  await settings.loadOwnAccount();
  settings.editStaffProfile();
  assert.equal(settings.staffProfile.firstName, 'Grooming');
  assert.equal(settings.staffProfileChanged, false);
  await settings.submitStaffProfile();
  assert.equal(profileCalls.length, 0, 'No-op submissions do not call the API');
  settings.staffProfile.firstName = '';
  assert.equal(settings.staffProfileValid, false);
  await settings.submitStaffProfile();
  assert.equal(profileCalls.length, 0, 'Invalid submissions do not call the API');
  assert.match(settings.staffProfile.errors.first_name, /first name/i);
  settings.staffProfile.firstName = 'Updated';
  settings.cancelStaffProfile();
  assert.equal(settings.staffProfile.editing, false);
  assert.equal(settings.staffProfile.firstName, 'Grooming');
  settings.editStaffProfile();
  settings.staffProfile.firstName = 'Updated';
  await settings.submitStaffProfile();
  assert.equal(profileCalls.length, 1);
  assert.equal(profileCalls[0][0], 'save');
  assert.equal('current_password' in profileCalls[0][1], false);
  assert.equal(settings.staffProfile.editing, false);
  assert.equal(settings.staffAccount.fullName, 'Updated Staff');
  assert.equal(toasts.at(-1), 'Profile updated successfully.');

  settings.editStaffProfile();
  settings.staffProfile.username = 'GroomingStaff';
  settings.staffProfile.lastName = 'Person';
  await settings.submitStaffProfile();
  assert.equal(profileCalls.at(-1)[0], 'validate', 'Case-only change requires availability preflight');
  assert.equal(settings.staffProfile.confirming, true);
  assert.equal(settings.staffAccount.username, 'groomingstaff');
  settings.staffProfile.currentPassword = 'discard';
  settings.cancelStaffProfileConfirmation();
  assert.equal(settings.staffProfile.currentPassword, '');
  assert.equal(settings.staffProfile.confirming, false);
  assert.equal(settings.staffProfile.lastName, 'Person');
  assert.equal(settings.staffProfile.editing, true);
  await settings.submitStaffProfile();
  API.updateStaffProfile = async () => { throw { errors: { current_password: ['The current password is incorrect.'] } }; };
  settings.staffProfile.currentPassword = 'wrong';
  await settings.confirmStaffProfile();
  assert.equal(settings.staffProfile.currentPassword, '');
  assert.equal(settings.staffProfile.confirming, true);
  assert.match(settings.staffProfile.errors.current_password, /incorrect/);
  assert.equal(settings.staffAccount.fullName, 'Updated Staff');
  assert.equal(settings.staffAccount.username, 'groomingstaff');
  let finishProfile;
  API.updateStaffProfile = payload => { profileCalls.push(['save', payload]); return new Promise(resolve => { finishProfile = resolve; }); };
  settings.staffProfile.currentPassword = 'CurrentStaff!234';
  const savingProfile = settings.confirmStaffProfile();
  const requestCount = profileCalls.length;
  await settings.confirmStaffProfile();
  settings.cancelStaffProfile();
  settings.cancelStaffProfileConfirmation();
  assert.equal(profileCalls.length, requestCount, 'Duplicate confirms are prevented');
  assert.equal(settings.staffProfile.confirming, true, 'Cannot cancel a save in flight');
  finishProfile({ account: { ...savedAccount, first_name: 'Updated', last_name: 'Person', username: 'GroomingStaff' } });
  await savingProfile;
  assert.equal(settings.staffProfile.editing, false);
  assert.equal(settings.staffProfile.currentPassword, '');
  assert.equal(settings.staffAccount.fullName, 'Updated Person');
  assert.equal(settings.staffAccount.username, 'GroomingStaff');

  settings.editStaffProfile();
  settings.staffProfile.username = 'Conflict';
  API.validateStaffProfile = async () => { throw { errors: { username: ['That username is already in use.'] } }; };
  await settings.submitStaffProfile();
  assert.equal(settings.staffProfile.confirming, false);
  assert.match(settings.staffProfile.errors.username, /already in use/);
  assert.equal(settings.staffProfile.username, 'Conflict', 'Validation failures preserve entered information');
  API.validateStaffProfile = async () => ({ success: true });
  settings.staffProfileInput('username');
  await settings.submitStaffProfile();
  settings.staffProfile.currentPassword = 'CurrentStaff!234';
  API.updateStaffProfile = async () => { throw { errors: { username: ['That username is already in use.'] } }; };
  await settings.confirmStaffProfile();
  assert.equal(settings.staffProfile.confirming, false, 'A conflict during confirmation returns to editing');
  assert.equal(settings.staffProfile.editing, true);
  assert.equal(settings.staffProfile.currentPassword, '');
  assert.equal(settings.staffProfile.username, 'Conflict');
  assert.equal(settings.staffAccount.username, 'GroomingStaff');
  assert.equal(settings.securityVerification.open, false, 'Profile editing does not open OTP verification');

  console.log("Admin security settings regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
