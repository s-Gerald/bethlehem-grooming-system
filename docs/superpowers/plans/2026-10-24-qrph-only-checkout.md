# QR Ph-only Checkout Implementation Plan

**Goal:** Refine the restored Hosted Checkout to use this application's QR Ph-only configuration and the exact display name Online Payment.

**Architecture:** Keep the shared checkout, settlement and reservation flows. Save allowed methods in the existing immutable payment snapshot, validate signed confirmations against that snapshot, and retain legacy method compatibility when a historical snapshot has no method list. Reuse the shared gateway label for Clinic/POS.

**Tech stack:** Laravel 12, PHP 8.4, existing JavaScript components and regression runner.

**Scope:** User's attached QR Ph-only brief; no commits, deployment, private environment changes, actual payment records, or application database migrations. Existing Bethlehem UI takes precedence over optional design conventions. Execute directly in gerald-frontend.

## Files and verification

- [x] Update existing GroomingPayMongoTest and SharedPayMongoTest: QR Ph creation for early/final Grooming, Clinic/POS; method mismatch rejection; historical pending settlement and labels; invalid configuration and recovery. Verified new regressions fail for the identified defects using PHP 8.4 and isolated SQLite, then pass after fixes.
- [x] Change config/services.php and .env.example to default to qrph. In PayMongoService centralize supported methods and validate checkout method configuration without blocking retrieval/expiry of historical attempts.
- [x] In PaymentController and OnlinePaymentController save payment_methods in existing snapshots; in OnlinePaymentService use those saved methods. In PayMongoWebhookController compare source.type to the saved list, keeping legacy aliases and snapshots without a list compatible. Preserve all other security and locking checks, without extra database/provider calls.
- [x] Make GatewayPayment::methodLabel() return exactly Online Payment. Update existing frontend flow fixtures and invoice/history assertions; preserve Cash and manual QR labels and inputs. Remove only the redundant provider-method row from transaction details; preserve all other layout/CSS.
- [x] Run payment/QR/schema/amount/report PHPUnit tests, existing frontend regressions, syntax checks and npm build. Keep the unrelated archive budget assertion unchanged. Update docs/paymongo-grooming.md with results and separate localhost versus Hostinger webhook delivery instructions.

## Review focus

- New QR Ph-only attempts reject signed non-QR Ph sources before any settlement or stock deduction.
- Historical pending attempts can settle with their original methods after configuration changes.
- Unsupported checkout methods fail before attempt creation/reservation, but cannot prevent historical cancellation/recovery.
- Backend receipt/history labels remain exactly Online Payment while provider_method remains accurate internally.
- Redirects and QR-code expiry never substitute for signed webhook settlement or confirmed session expiry.
