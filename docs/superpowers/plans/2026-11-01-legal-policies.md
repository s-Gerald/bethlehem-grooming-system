# Legal policies implementation plan

**Goal:** Publish Bethlehem's Terms and Privacy Policy and enforce minimal registration acceptance evidence.

**Architecture:** Keep legal documents as public static HTML with one shared stylesheet. Validate `terms_accepted` at new registration and email-proven completion; keep the server-controlled version and timestamp on pending and completed records. Old pending records use the existing review grant instead of completing without evidence.

**Stack:** Laravel, nullable MySQL/SQLite columns, existing vanilla JavaScript and Node regression harness.

## Constraints

- Work on the latest `gerald-frontend`; verified remote HEAD `3c8b7798c6cf258f5b08e300db49043a615b636f` before editing.
- Preserve existing queue, email proof, browser binding, recovery, and authentication behavior.
- No IP address or fingerprint collection for agreement evidence; existing accounts remain nullable.
- Use original, verified clinic-specific copy and Philippine legal references. No invented commercial policies or contact details.
- Preserve the signup layout and required checkbox. Legal links open separate tabs.
- Browser inspection is visual only; the operator handles functional navigation and form submissions.

## Steps and files

1. Add failing behavior tests in `tests/Feature/EmailVerificationTest.php` and `tests/customer-signup-verification-regression.cjs`: omitted/rejected acceptance, server-owned evidence, legacy pending review, recovery, frontend blocking and API errors.
2. Add `database/migrations/2026_11_01_000001_add_customer_terms_acceptance.php`; add fillable/casts in `app/Models/User.php` and `app/Models/PendingCustomerRegistration.php`. Add the version and completion invariant to `app/Services/CustomerRegistrationService.php`; validate in `app/Http/Controllers/AuthController.php`; route pre-policy pending records through review in `app/Http/Controllers/EmailVerificationController.php`.
3. Add `pages/legal/terms-and-conditions.html`, `pages/legal/privacy-policy.html`, and `css/legal.css`. Update `pages/client/signup.html`, `scripts/auth/client/signup.js`, `index.html`, and the footer styles in `css/landing.css`. Reuse the current logo, typography, colors, static routes, and relative paths.
4. Run the affected PHP email/registration tests, Node signup regression, public-route checks, and asset build where required. Inspect desktop/tablet/mobile documents in the in-app browser, without entering or submitting data.

## Review focus

- Old queued registrations can have no acceptance: preserve their email proof and require acceptance on review.
- A caller cannot choose a version or backdate acceptance.
- Ordinary verification continuation does not ask for acceptance again.
- Foreign-browser and legacy recovery preserve email-ownership checks and existing records.
- Payment providers, configurable SMS, retention, contact channels, and legal rights must match verified facts.

## References and operator review

- Data Privacy Act: https://privacy.gov.ph/data-privacy-act/
- IRR: https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/
- Right to be informed: https://privacy.gov.ph/the-right-to-be-informed/
- Consent guidance: https://privacy.gov.ph/wp-content/uploads/2023/11/NPC-Circular-No.-2023-04_Guidelines-on-Consent_07Nov2023.pdf
- Veterinary document structure only: https://vetster.com/en-us/legal/terms

The landing page verifies the clinic's postal address, 7007-3122, 0917 113 1941, and Facebook page; it publishes no privacy email or named representative. The Privacy Policy uses phone and in-person requests. The operator should confirm that staff can receive and route those requests, and supply a dedicated privacy contact if appropriate.

Repository deployment documentation identifies Hostinger and Brevo SMTP; application code integrates iProgSMS behind feature switches and PayMongo hosted checkout. The current PayMongo service explicitly permits test credentials only; real-payment availability requires separate operator review. Confirm the actual production processors, SMS switches, payment methods, processing locations, and retention arrangements before publication. Account deletion is described as a staff-assisted request, not a self-service feature. Confirm the staff workflow for requests and the retention schedule. No fixed retention, cancellation fee, refund deadline, warranty, or legal certification is asserted.

## Deployment

Deploy the nullable migration before the updated registration code, then restart existing queue workers so encrypted pending jobs use the current service. Run `php artisan migrate --force` using the host's supported PHP CLI. Deploy both HTML files, `css/legal.css`, updated signup/landing assets and generated CSS together. Keep the documents' effective date aligned with publication and `CustomerRegistrationService::TERMS_VERSION`; preserve prior published versions outside the public page when revising terms.

No new route, queue, session, scheduled task, or dependency is needed. Do not backfill acceptance for existing customers. The single new migration was applied successfully to the verified local MySQL instance (`APP_ENV=local`, host `127.0.0.1`). No Hostinger migration or publication was performed.

## Completed verification

- PHP 8.4: `EmailVerificationTest.php` — 189 tests, 2,209 assertions passed, including the new acceptance, continuation, and recovery cases.
- `LegalPagesTest.php` and `CustomerTermsAcceptanceMigrationTest.php` — 2 tests, 19 assertions passed; public assets require no session/database queries, and nullable migration replay/rollback preserves records.
- `node tests/customer-signup-verification-regression.cjs` — passed, including unchecked acceptance, payload evidence, accessible API rejection, and existing continuation/review cases.
- `node tests/public-landing-regression.mjs` — passed.
- `npm run build:css` — passed; existing non-blocking Browserslist database age warning. Generated stylesheet has no tracked diff because the required utility classes were already generated.
- In-app browser visual inspection: Terms at desktop/mobile and Privacy at desktop/tablet/mobile, with no horizontal overflow or broken logo. Signup wording, separate-tab attributes, accessible checkbox name/error association, and public footer styling inspected without form entry or submission. No browser warnings/errors on the signup preview.
- Original signup tab and its form values were left intact. Functional navigation, OTP submissions, and password-reset testing remain with the operator as requested.
- `git diff --check` — passed.
