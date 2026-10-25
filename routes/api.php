<?php

use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminClinicController;
use App\Http\Controllers\AdminPetProfileController;
use App\Http\Controllers\AdminSecurityController;
use App\Http\Controllers\AdminVaccinationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ClinicClosureController;
use App\Http\Controllers\ClinicSettingController;
use App\Http\Controllers\ClinicServiceController;
use App\Http\Controllers\ClinicCaseServiceController;
use App\Http\Controllers\ClinicBillingController;
use App\Http\Controllers\ClinicConfinementController;
use App\Http\Controllers\ClinicWalkinController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerNotificationController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoginEmailChallengeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PetController;
use App\Http\Controllers\PetMedicalRecordController;
use App\Http\Controllers\PetVaccinationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WalkinController;
use App\Http\Controllers\GroomingPricingController;
use App\Http\Middleware\ThrottleAuthenticationResends;
use Illuminate\Support\Facades\Route;


Route::post('/paymongo/webhook', \App\Http\Controllers\PayMongoWebhookController::class);

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::put('/register/pending', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/sign-in', [AuthController::class, 'signIn']);
Route::post('/password/forgot', [PasswordResetController::class, 'requestCode'])
    ->middleware(ThrottleAuthenticationResends::class.':password-resend');
Route::post('/password/code/verify', [PasswordResetController::class, 'verifyCode'])
    ->middleware('throttle:10,1');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:10,1');
Route::post('/staff/password-setup/verify', [PasswordResetController::class, 'verifyStaffSetupLink'])
    ->middleware('throttle:10,1');
Route::post('/staff/password-setup/complete', [PasswordResetController::class, 'completeStaffSetup'])
    ->middleware('throttle:10,1');
Route::post('/staff/password-setup/request-new-link', [PasswordResetController::class, 'requestNewStaffSetupLink'])
    ->middleware('throttle:3,10');
Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:registration-verify');
Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware(ThrottleAuthenticationResends::class.':registration-resend');
Route::post('/email/delivery-status', [EmailVerificationController::class, 'deliveryStatus'])
    ->middleware('throttle:email-delivery-status');
Route::post('/email/login/confirm', [LoginEmailChallengeController::class, 'confirm'])
    ->middleware('throttle:10,1');
Route::post('/email/login/resend', [LoginEmailChallengeController::class, 'resend'])
    ->middleware(ThrottleAuthenticationResends::class.':3,5');

// ── PUBLIC ROUTES ─────────────────────────────────────
Route::get('/timeslots', [BookingController::class,   'getTimeslots'])
    ->middleware(\App\Http\Middleware\EnsureBookingForecastSession::class);
Route::get('/clinic/status', [ClinicClosureController::class, 'status']);
Route::get('/clinic/timeslots', [ClinicWalkinController::class, 'timeslots']);

// ── PROTECTED ROUTES (token required) ────────────────
Route::middleware(['auth:sanctum', 'account.usable', 'session.active'])->group(function () {
    Route::get('/session', [\App\Http\Controllers\SessionController::class, 'status'])->name('session.status');
    Route::post('/session/activity', [\App\Http\Controllers\SessionController::class, 'status'])->name('session.activity');
    Route::post('/session/unlock', [\App\Http\Controllers\SessionController::class, 'unlock'])
        ->middleware(['role:admin,staff', 'throttle:10,1'])->name('session.unlock');
    Route::get('/grooming/services', [GroomingPricingController::class, 'index']);
    Route::get('/admin/grooming/services', [GroomingPricingController::class, 'managedIndex'])->middleware('role:admin');
    Route::get('/admin/grooming/services/catalogue', [GroomingPricingController::class, 'staffIndex'])->middleware(['role:admin,staff', 'staff:grooming_services_view']);
    Route::post('/admin/grooming/services', [GroomingPricingController::class, 'store'])->middleware('role:admin');
    Route::put('/admin/grooming/services/{service}', [GroomingPricingController::class, 'save'])->middleware('role:admin');
    Route::patch('/admin/grooming/services/{service}/status', [GroomingPricingController::class, 'status'])->middleware('role:admin');
    Route::get('/grooming/pet-groomers', [\App\Http\Controllers\PetGroomerController::class, 'index']);
    Route::patch('/admin/grooming/services/{service}/pricing', [GroomingPricingController::class, 'update'])->middleware('role:admin');

    // Auth
    Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('/email/login/complete', [LoginEmailChallengeController::class, 'complete']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::middleware('role:customer')->prefix('phone-verification')->group(function () {
        Route::post('/send', [\App\Http\Controllers\PhoneVerificationController::class, 'send'])->middleware('throttle:20,1');
        Route::post('/resend', [\App\Http\Controllers\PhoneVerificationController::class, 'resend'])->middleware('throttle:20,1');
        Route::post('/verify', [\App\Http\Controllers\PhoneVerificationController::class, 'verify'])->middleware('throttle:20,1');
    });

    // Pets
    Route::get('/pets', [PetController::class, 'index']);
    Route::get('/pets/{id}', [PetController::class, 'show']);
    Route::get('/pets/{petId}/medical-records', [PetMedicalRecordController::class, 'index']);
    Route::get('/pets/{petId}/vaccinations', [PetVaccinationController::class, 'index']);
    Route::get('/clinic/history', [PetMedicalRecordController::class, 'history']);
    Route::post('/pets', [PetController::class, 'store']);
    Route::put('/pets/{id}', [PetController::class, 'update']);
    Route::post('/pets/{id}/archive', [PetController::class, 'archive']);
    Route::post('/pets/{id}/unarchive', [PetController::class, 'unarchive']);

    // Admin — Pet management (update any customer's pet)
    Route::put('/admin/pets/{id}', [PetController::class, 'adminUpdate']);
    Route::get('/admin/pets/{petId}/profile', [AdminPetProfileController::class, 'show']);

    // Booking
    Route::post('/booking/store', [BookingController::class, 'store'])->middleware(\App\Http\Middleware\EnsureCustomerPhoneVerified::class);
    Route::post('/booking/workload-forecast', [BookingController::class, 'forecastTimeslots']);
    Route::get('/pre-registration/access', [BookingController::class, 'preRegistrationAccess']);
    Route::get('/booking/history', [BookingController::class, 'history']);
    Route::get('/booking/grooming-capacity', [BookingController::class, 'groomingCapacity']);
    Route::get('/booking/{id}', [BookingController::class, 'show']);
    Route::post('/booking/cancel', [BookingController::class, 'cancel']);
    Route::post('/booking/reschedule', [BookingController::class, 'reschedule']);

    // Customer — Clinic visit pre-registration
    Route::post('/clinic/pre-register', [ClinicWalkinController::class, 'preRegister'])->middleware(\App\Http\Middleware\EnsureCustomerPhoneVerified::class);

    // Admin — Customer management (staff can view, admin can manage)
    Route::get('/admin/dashboard/search', [CustomerController::class, 'dashboardSearch']);
    Route::get('/admin/customers', [CustomerController::class, 'index']);
    Route::post('/admin/customers/unregistered', [CustomerController::class, 'storeUnregistered']);
    Route::get('/admin/customers/unregistered/{id}', [CustomerController::class, 'showUnregistered']);
    Route::post('/admin/customers/unregistered/{id}/archive', [CustomerController::class, 'archiveUnregistered']);
    Route::get('/admin/walk-in/customers', [CustomerController::class, 'walkInSearch']);
    Route::post('/admin/walk-in/customers/validate-new-owner', [CustomerController::class, 'validateWalkInOwner']);
    Route::post('/admin/customer-pets/{ownerType}/{ownerId}', [PetController::class, 'adminStore']);
    Route::get('/admin/customers/{id}', [CustomerController::class, 'show']);
    Route::post('/admin/customers/{id}/deactivate', [CustomerController::class, 'deactivate']);
    Route::post('/admin/customers/{id}/reactivate', [CustomerController::class, 'reactivate']);
    Route::post('/admin/customers/{id}/archive', [CustomerController::class, 'archive']);
    Route::post('/admin/customers/{id}/unarchive', [CustomerController::class, 'unarchive']);

    // Customer — Notifications
    Route::get('/customer/notifications', [CustomerNotificationController::class, 'index']);
    Route::patch('/customer/notifications/read-all', [CustomerNotificationController::class, 'markAllRead']);
    Route::patch('/customer/notifications/{id}/read', [CustomerNotificationController::class, 'markRead']);

    // Shared staff functionality, with grooming operations restricted separately.
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/admin/qr-payments/{id}/proof', [\App\Http\Controllers\QrPaymentProofController::class, 'show'])->whereNumber('id')->middleware('grooming');
        Route::get('/admin/qr-payments/config', [\App\Http\Controllers\QrPaymentSettingController::class, 'configuration'])->middleware('grooming');
        Route::get('/admin/qr-payments/image', [\App\Http\Controllers\QrPaymentSettingController::class, 'image'])->middleware('grooming');
        Route::get('/admin/notifications', [NotificationController::class, 'index']);
        Route::patch('/admin/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/admin/notifications/{id}/read', [NotificationController::class, 'markRead']);

        Route::get('/admin/clinic/settings/availability', [ClinicSettingController::class, 'availability']);
        Route::get('/admin/clinic/blocked-dates', [ClinicClosureController::class, 'blockedDates']);
        Route::patch('/admin/clinic/settings/groomers-on-duty', [ClinicSettingController::class, 'updateGroomersOnDuty'])->middleware('grooming');
        Route::post('/admin/pet-groomers', [\App\Http\Controllers\PetGroomerController::class, 'store'])->middleware('grooming');
        Route::patch('/admin/pet-groomers/{id}', [\App\Http\Controllers\PetGroomerController::class, 'update'])->middleware('grooming');
        Route::get('/settings/security/account', [AdminSecurityController::class, 'ownAccount']);
        Route::post('/settings/security/email-change', [\App\Http\Controllers\StaffEmailChangeController::class, 'start'])
            ->middleware(['role:staff', 'throttle:3,10,staff-email-start'])->name('staff.email-change.start');
        Route::post('/settings/security/email-change/{change}/confirm', [\App\Http\Controllers\StaffEmailChangeController::class, 'confirm'])
            ->middleware(['role:staff', 'throttle:10,1,staff-email-confirm'])->name('staff.email-change.confirm');
        Route::post('/settings/security/email-change/{change}/resend', [\App\Http\Controllers\StaffEmailChangeController::class, 'resend'])
            ->middleware(['role:staff', 'throttle:3,10,staff-email-resend'])->name('staff.email-change.resend');
        Route::delete('/settings/security/email-change/{change}', [\App\Http\Controllers\StaffEmailChangeController::class, 'cancel'])
            ->middleware(['role:staff', 'throttle:10,1,staff-email-cancel'])->name('staff.email-change.cancel');
        Route::post('/settings/security/profile/validate', [AdminSecurityController::class, 'validateStaffProfile'])
            ->middleware(['role:staff', 'throttle:10,1']);
        Route::patch('/settings/security/profile', [AdminSecurityController::class, 'updateStaffProfile'])
            ->middleware(['role:staff', 'throttle:10,1']);
        Route::post('/settings/security/password-change', [AdminSecurityController::class, 'requestStaffPasswordChange'])
            ->middleware(['role:staff', 'throttle:3,10']);
        Route::post('/settings/security/credential-changes/{change}/confirm', [AdminSecurityController::class, 'confirm'])
            ->middleware(['role:staff', 'throttle:10,1']);
        Route::post('/settings/security/credential-changes/{change}/resend', [AdminSecurityController::class, 'resend'])
            ->middleware(['role:staff', ThrottleAuthenticationResends::class.':3,10']);
        Route::get('/admin/transactions', [PaymentController::class, 'index']);
        Route::get('/admin/dashboard/summary', [AdminBookingController::class, 'dashboardSummary'])->middleware('staff:dashboard');
        Route::get('/admin/dashboard/activity', [AdminBookingController::class, 'dashboardActivity'])->middleware('staff:dashboard');
        Route::middleware('grooming')->group(function () {
            Route::get('/admin/grooming/activity', [AdminBookingController::class, 'activityIndicator']);
            Route::get('/admin/bookings', [AdminBookingController::class, 'index']);
            Route::get('/admin/bookings/archived', [AdminBookingController::class, 'archivedIndex']);
            Route::get('/admin/bookings/{id}/signed-consent', [AdminBookingController::class, 'signedConsent']);
            Route::post('/admin/bookings/{id}/check-in', [AdminBookingController::class, 'checkIn']);
            Route::patch('/admin/bookings/{id}/internal-staff-note', [AdminBookingController::class, 'updateInternalStaffNote']);
            Route::patch('/admin/bookings/{id}/pets/{bookingPetId}/grooming-visit-notes', [AdminBookingController::class, 'updateGroomingVisitNotes']);
            Route::post('/admin/bookings/{id}/sedation-consent', [AdminBookingController::class, 'recordSedationConsent']);
            Route::post('/admin/bookings/{id}/revert-check-in', [AdminBookingController::class, 'revertCheckIn']);
            Route::post('/admin/bookings/{id}/start-grooming', [AdminBookingController::class, 'startGrooming']);
            Route::post('/admin/bookings/{id}/revert-start-grooming', [AdminBookingController::class, 'revertStartGrooming']);
            Route::post('/admin/bookings/{id}/pets/{bookingPetId}/start-grooming', [AdminBookingController::class, 'startPetGrooming']);
            Route::post('/admin/bookings/{id}/pets/{bookingPetId}/undo-start', [AdminBookingController::class, 'undoPetStart']);
            Route::post('/admin/bookings/{id}/pets/{bookingPetId}/cancel', [AdminBookingController::class, 'cancelPet']);
            Route::post('/admin/bookings/{id}/pets/{bookingPetId}/mark-done', [AdminBookingController::class, 'markPetDone']);
            Route::post('/admin/bookings/{id}/mark-done', [AdminBookingController::class, 'markDone']);
            Route::post('/admin/bookings/{id}/cancel', [AdminBookingController::class, 'cancel']);
            Route::post('/admin/bookings/{id}/archive', [AdminBookingController::class, 'archive']);
            Route::post('/admin/bookings/{id}/picked-up', [AdminBookingController::class, 'markPickedUp']);

            Route::post('/admin/bookings/{id}/pay', [PaymentController::class, 'store']);
            Route::post('/admin/bookings/{id}/pay-now', [PaymentController::class, 'payNow']);
            Route::post('/admin/bookings/{id}/online-payment', [PaymentController::class, 'createOnlineCheckout']);
            Route::get('/admin/bookings/{id}/online-payment', [PaymentController::class, 'onlinePaymentStatus']);
            Route::delete('/admin/bookings/{id}/online-payment', [PaymentController::class, 'cancelOnlineCheckout']);
            Route::post('/admin/bookings/{id}/release', [PaymentController::class, 'release']);

            Route::post('/admin/walk-in/capacity-preview', [WalkinController::class, 'previewCapacity']);
            Route::post('/admin/walk-in', [WalkinController::class, 'store']);
        });
    });

    Route::get('/admin/reports/grooming', [ReportController::class, 'grooming']);
    Route::get('/admin/reports/clinic', [ReportController::class, 'clinic']);

    // Clinic administration — available only to the project's staff/admin roles.
    Route::middleware(['role:admin,staff', 'clinic:access'])->group(function () {
        Route::get('/admin/clinic-services/options', [ClinicServiceController::class, 'options'])->middleware('clinic:services_view');
        Route::get('/admin/clinic-services', [ClinicServiceController::class, 'index'])->middleware('clinic:services_view');
        Route::post('/admin/clinic-services', [ClinicServiceController::class, 'store'])->middleware('clinic:services_manage');
        Route::put('/admin/clinic-services/{clinicService}', [ClinicServiceController::class, 'update'])->middleware('clinic:services_manage');
        Route::patch('/admin/clinic-services/{clinicService}/status', [ClinicServiceController::class, 'status'])->middleware('clinic:services_manage');

        Route::get('/admin/pets/{petId}/cases/{caseId}/details', [AdminPetProfileController::class, 'caseDetails']);
        Route::get('/admin/pets/{petId}/clinic-case', [AdminClinicController::class, 'currentPetCase']);
        Route::post('/admin/clinic-walk-in', [ClinicWalkinController::class, 'store'])->middleware('clinic:intake');

        Route::get('/admin/clinic-records', [AdminClinicController::class, 'records']);
        Route::get('/admin/clinic-cases', [AdminClinicController::class, 'activeCases']);
        Route::get('/admin/clinic-confinements', [AdminClinicController::class, 'confinedCases']);
        Route::post('/admin/clinic-cases', [AdminClinicController::class, 'createCase'])->middleware('clinic:intake');
        Route::get('/admin/clinic-cases/{id}', [AdminClinicController::class, 'showCase']);
        Route::post('/admin/clinic-cases/{id}/start', [AdminClinicController::class, 'startCase'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-cases/{id}/finish', [AdminClinicController::class, 'finishCase'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/services', [ClinicCaseServiceController::class, 'index']);
        Route::get('/admin/clinic-confinement-service-options', [ClinicConfinementController::class, 'options'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/confinement', [ClinicConfinementController::class, 'show']);
        Route::post('/admin/clinic-cases/{id}/confinement', [ClinicConfinementController::class, 'admit'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/confinement/admission-preview', [ClinicConfinementController::class, 'admissionPreview'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-cases/{id}/confinement/charges', [ClinicConfinementController::class, 'addCharge'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-cases/{id}/confinement/charges/{chargeId}/stop', [ClinicConfinementController::class, 'stopCharge'])->middleware('clinic:clinical');
        Route::delete('/admin/clinic-cases/{id}/confinement/charges/{chargeId}', [ClinicConfinementController::class, 'removeCharge'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/confinement/observations', [ClinicConfinementController::class, 'observations']);
        Route::post('/admin/clinic-cases/{id}/confinement/observations', [ClinicConfinementController::class, 'recordObservation'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/confinement/discharge-preview', [ClinicConfinementController::class, 'preview'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-cases/{id}/confinement/discharge', [ClinicConfinementController::class, 'discharge'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-service-options', [ClinicServiceController::class, 'caseOptions'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/service-pricing/{clinicServiceId}', [ClinicCaseServiceController::class, 'pricing'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-cases/{id}/services', [ClinicCaseServiceController::class, 'store'])->middleware('clinic:clinical');
        Route::delete('/admin/clinic-cases/{id}/services/{caseServiceId}', [ClinicCaseServiceController::class, 'destroy'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-cases/{id}/charges', [ClinicBillingController::class, 'index'])->middleware('clinic:billing_view');
        Route::patch('/admin/clinic-cases/{id}/charges/{chargeId}', [ClinicBillingController::class, 'update'])->middleware('clinic:billing_edit');

        Route::get('/admin/clinic-appointments', [AdminClinicController::class, 'index']);
        Route::get('/admin/clinic-appointments/archived', [AdminClinicController::class, 'archivedIndex']);
        Route::post('/admin/clinic-appointments/{id}/check-in', [AdminClinicController::class, 'checkIn'])->middleware('clinic:intake');
        Route::post('/admin/clinic-appointments/{id}/start-consultation', [AdminClinicController::class, 'startConsultation'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-appointments/{id}/finish-consultation', [AdminClinicController::class, 'finishConsultation'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-appointments/{id}/online-payment', [\App\Http\Controllers\OnlinePaymentController::class, 'createClinic'])->middleware('clinic:payment');
        Route::get('/admin/clinic-appointments/{id}/online-payment', [\App\Http\Controllers\OnlinePaymentController::class, 'clinicStatus'])->middleware('clinic:payment');
        Route::delete('/admin/clinic-appointments/{id}/online-payment', [\App\Http\Controllers\OnlinePaymentController::class, 'cancelClinic'])->middleware('clinic:payment');
        Route::post('/admin/clinic-appointments/{id}/pay', [AdminClinicController::class, 'markPaid'])->middleware('clinic:payment');
        Route::post('/admin/clinic-appointments/{id}/cancel', [AdminClinicController::class, 'cancel'])->middleware('clinic:queue');
        Route::post('/admin/clinic-appointments/{id}/record', [AdminClinicController::class, 'saveRecord'])->middleware('clinic:clinical');
        Route::post('/admin/clinic-appointments/{id}/attachments', [AdminClinicController::class, 'uploadAttachment'])->middleware('clinic:clinical');
        Route::get('/admin/clinic-appointments/{id}/attachments/{attachmentId}/download', [AdminClinicController::class, 'downloadAttachment']);
        Route::delete('/admin/clinic-appointments/{id}/attachments/{attachmentId}', [AdminClinicController::class, 'deleteAttachment'])->middleware('clinic:clinical');

        Route::get('/admin/clinic-medicine-options', [AdminClinicController::class, 'medicineOptions']);

        Route::get('/admin/vaccination-options', [AdminVaccinationController::class, 'options']);
        Route::get('/admin/pets/{petId}/vaccinations', [AdminVaccinationController::class, 'index']);
        Route::post('/admin/pets/{petId}/vaccinations', [AdminVaccinationController::class, 'store'])->middleware('clinic:clinical');
        Route::get('/admin/pets/{petId}/vaccinations/{vaccinationId}', [AdminVaccinationController::class, 'show']);
        Route::patch('/admin/pets/{petId}/vaccinations/{vaccinationId}', [AdminVaccinationController::class, 'update'])->middleware('clinic:clinical');
        Route::delete('/admin/pets/{petId}/vaccinations/{vaccinationId}', [AdminVaccinationController::class, 'destroy'])->middleware('clinic:clinical');
        Route::post('/admin/pets/{petId}/vaccinations/{vaccinationId}/publish', [AdminVaccinationController::class, 'publish'])->middleware('clinic:clinical');
        Route::post('/admin/pets/{petId}/vaccinations/{vaccinationId}/void', [AdminVaccinationController::class, 'void'])->middleware('clinic:clinical');
    });

    // Inventory and POS expose stock and financial data only to staff/admin roles.
    Route::middleware('role:admin,staff')->group(function () {
        // Admin — Inventory: Products
        Route::get('/inventory/items', [InventoryController::class, 'index'])->middleware('staff:inventory_view');
        Route::post('/inventory/items', [InventoryController::class, 'store'])->middleware('staff:inventory_manage');
        Route::get('/inventory/items/{id}', [InventoryController::class, 'show'])->middleware('staff:inventory_view');
        Route::put('/inventory/items/{id}', [InventoryController::class, 'update'])->middleware('staff:inventory_manage');
        Route::post('/inventory/items/{id}/deactivate', [InventoryController::class, 'deactivate'])->middleware('staff:inventory_manage');
        Route::post('/inventory/items/{id}/reactivate', [InventoryController::class, 'reactivate'])->middleware('staff:inventory_manage');

        // Admin — Inventory: Barcode & search
        Route::get('/inventory/search', [InventoryController::class, 'search'])->middleware('staff:inventory_view');
        Route::get('/inventory/barcode/{barcode}', [InventoryController::class, 'findByBarcode'])->middleware('staff:inventory_view');

        // Admin — Inventory: Stock movements
        Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn'])->middleware('staff:inventory_manage');
        Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut'])->middleware('staff:inventory_manage');

        // Admin — Inventory: History & alerts
        Route::get('/inventory/transactions', [InventoryController::class, 'transactions'])->middleware('staff:inventory_view');
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock'])->middleware('staff:inventory_view');
        Route::get('/inventory/alerts/expiry', [InventoryController::class, 'expiryAlerts'])->middleware('staff:inventory_view');
        Route::get('/inventory/alerts/badge', [InventoryController::class, 'alertBadge'])->middleware('staff:inventory_view');
        Route::get('/inventory/summary', [InventoryController::class, 'summary'])->middleware('staff:inventory_view');

        // Admin — POS
        Route::post('/pos/online-payment', [\App\Http\Controllers\OnlinePaymentController::class, 'createPos'])->middleware('staff:pos');
        Route::get('/pos/online-payment/{id?}', [\App\Http\Controllers\OnlinePaymentController::class, 'posStatus'])->middleware('staff:pos')->whereNumber('id');
        Route::delete('/pos/online-payment/{id}', [\App\Http\Controllers\OnlinePaymentController::class, 'cancelPos'])->middleware('staff:pos')->whereNumber('id');
        Route::post('/pos/validate-cart', [PosController::class, 'validateCart'])->middleware('staff:pos');
        Route::post('/pos/transactions', [PosController::class, 'processSale'])->middleware('staff:pos');
        Route::get('/pos/transactions', [PosController::class, 'getTransactions'])->middleware('staff:pos');
        Route::get('/pos/transactions/{posId}', [PosController::class, 'getReceipt'])->middleware('staff:pos');
    });

    // Administrator-only clinic availability and closure actions.
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/qr-payments/settings', [\App\Http\Controllers\QrPaymentSettingController::class, 'show']);
        Route::post('/admin/qr-payments/settings', [\App\Http\Controllers\QrPaymentSettingController::class, 'save'])->middleware('throttle:10,1');
        Route::get('/admin/security/accounts', [AdminSecurityController::class, 'accounts']);
        Route::post('/admin/security/account/credential-change', [AdminSecurityController::class, 'requestOwnChange'])
            ->middleware('throttle:3,10');
        Route::post('/admin/security/staff-accounts', [AdminSecurityController::class, 'requestStaffAccount'])
            ->middleware('throttle:3,10');
        Route::patch('/admin/security/staff/{staff}/status', [AdminSecurityController::class, 'updateStaffStatus'])
            ->middleware('throttle:10,1');
        Route::patch('/admin/security/staff/{staff}/role', [AdminSecurityController::class, 'updateStaffRole'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/staff/{staff}/setup-email', [AdminSecurityController::class, 'resendStaffSetupEmail'])
            ->middleware('throttle:3,10');
        Route::patch('/admin/security/staff/{staff}/setup-email', [AdminSecurityController::class, 'correctStaffSetupEmail'])
            ->middleware('throttle:10,1');
        Route::delete('/admin/security/staff/{staff}/setup', [AdminSecurityController::class, 'cancelStaffSetup'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/credential-changes/{change}/confirm', [AdminSecurityController::class, 'confirm'])
            ->middleware('throttle:10,1');
        Route::post('/admin/security/credential-changes/{change}/resend', [AdminSecurityController::class, 'resend'])
            ->middleware(ThrottleAuthenticationResends::class.':3,10');

        Route::post('/admin/clinic/stop-today', [ClinicClosureController::class, 'stopToday']);
        Route::post('/admin/clinic/reopen-today', [ClinicClosureController::class, 'reopenToday']);
        Route::post('/admin/clinic/blocked-dates', [ClinicClosureController::class, 'addBlockedDate']);
        Route::delete('/admin/clinic/blocked-dates/{id}', [ClinicClosureController::class, 'removeBlockedDate']);
        Route::patch('/admin/clinic/settings/availability', [ClinicSettingController::class, 'updateAvailability']);
    });
});
