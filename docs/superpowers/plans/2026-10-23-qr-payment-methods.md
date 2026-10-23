# QR Payment Methods Implementation Plan

**Goal:** Replace receiving-institution settings with accepted methods and preserve the staff-selected source throughout grooming settlement and history.

**Architecture:** Extend the existing singleton QR settings, Alpine components, and settlement verification service in place. Keep internal `cash`/`qrph` handling and use a nullable source snapshot on each QR payment, independent of OCR suggestions. Existing row locks, version checks, proof handling, and lazy assets remain in place.

**Tech Stack:** Laravel/PHP, Alpine/vanilla JavaScript, existing component CSS, PHPUnit and Node regression scripts.

**Spec:** User attachment `3ec5cab1-ac44-4a7f-86c8-e7b55e33dba7/Pasted text.txt`.

## Constraints

- Preserve existing Bethlehem colors, typography, navigation, settlement safeguards, and financial history.
- No native select/datalist, gateways, receiving-account management, or heavyweight dependencies.
- Defaults apply only to absent configuration; an explicit empty list stays empty.
- Manual localhost navigation and functional input testing belong to the user.
- Do not modify application data or environment settings; migrations run only in isolated tests.

## Review focus

- Empty, duplicate, reserved, and custom method names must be validated by the server.
- Disabled or stale methods must fail before settlement, with no partial financial writes.
- OCR may suggest an app but cannot replace the selected source or verify funds.
- Historical null sources must display QR Payment and retain original audit snapshots.
- Settings reload/save must preserve selections without extra configuration requests.

## Tasks

1. Update `tests/Feature/QrPaymentSettingsTest.php`, `GroomingPaymentPriceSafetyTest.php`, and `PaymentSchemaCompatibilityTest.php`; update existing settings, invoice, and OCR Node regressions. Run focused tests to establish failures for absent configuration/source behavior.
2. Add one forward migration for `accepted_methods` and `payment_source`. Remove the unused settings provider column, make historical QR provider snapshots nullable, and preserve existing snapshot values. Update `QrPaymentSettings`, `QrPayment`, `Payment`, `QrPaymentSettingController`, and `QrPaymentVerificationService` to validate configured methods and centralize labels. Replace the obsolete provider catalog with method defaults.
3. Update `scripts/components/admin-settings.js`, `pages/admin/settings.html`, and `css/components/qr-payment.css` with an accessible custom checkbox disclosure, removable chips, custom-name editing, and concise errors. Preserve upload/crop and existing lazy settings loading.
4. Update `scripts/components/qr-payment.js`, `scripts/components/admin-dashboard.js`, and `pages/admin/appointments.html` to select configured names while submitting `qrph` plus `payment_source`. Use the existing QR/proof/verification flow for every non-cash method. Update `PaymentController`, booking/history serializers, transaction details, and receipt labels through the shared payment label.
5. Run relevant PHPUnit, Node regressions, syntax checks, and the frontend build. Review migration preservation, eager loading, caching, obsolete references, and the final diff. Report manual testing and performance measurements as unperformed.
