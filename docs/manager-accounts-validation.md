# Manager accounts

Implemented on `gerald-frontend`, starting from remote commit `61c23ec` (Recent Security Activity).

## Behavior

- Owner remains `role=admin, admin_type=owner`; legacy Admin identities remain Owner during migration. Manager is `role=admin, admin_type=manager`.
- The repeatable migration adds a nullable string without changing the existing role enum. It preserves existing Manager rows on redeployment and clears admin types on non-admins.
- Owner authorization covers account collections, creation, role/status changes, setup administration, assisted recovery and the Owner credential interface. Manager direct requests return 403.
- `POST /api/admin/security/accounts` is the canonical creation path. Existing staff URLs remain compatible and use the same validation and services.
- Manager uses the existing setup token format, Admin password policy, Admin operational navigation, editable General settings and self-service Security. Confirmed Manager password changes retain self-service session behavior and consume `security_resolved_at`.
- Recent Security Activity services and history migrations remain intact. Manager coverage extends recognition, pagination, ownership, failed confirmations, resolution cutoffs and retained Recognized/Reviewed history.
- Identity responses, cache revision, sidebar identity, payment/activity attribution and asset versions include the role distinction. Reseeding preserves the single Owner and Manager promotions.

## Verification

- Final focused PHP run: **235 tests, 3,556 assertions passed**, using the repository's documented PHP 8.4.26 interpreter and isolated SQLite database.
- Frontend suite: **104/104 regression scripts passed**. The subsequently extended Manager Security history/resolution test also passes.
- Headless Edge: Owner creation through Manager details and setup-email success; Manager self-service Security with no Accounts request; correct Secure account focus; desktop (1440px) and mobile (390px), with no horizontal overflow.
- Cached Manager navigation: Clinic, Inventory, Reports and Settings, normal/hard reloads and mobile drawer behavior passed. Each document used one session check and no redundant identity request.
- JavaScript syntax and `git diff --check` passed. Existing styles are reused, so no CSS build was needed.

The full PHP audit ran 2,545 tests with 10 skips. Its obsolete dashboard sidebar assertion was updated and passes in the final focused run. Two unrelated failures remain; both reproduce with the original versions of the modified application classes:

1. `AdminVaccinationApiTest::test_mixed_confinement_care_reuses_treatment_stock_services_and_billing_on_the_same_case`: the fixture lacks `clinic_settings`.
2. `ClientPetProfileTest::test_pet_filtered_history_excludes_sibling_pets_and_their_services`: expects `Full Groom`, receives `Grooming service`.

Two older Node fixtures lacked the current General-settings API; both were confirmed to fail against the original Settings script and updated without changing production behavior. The sidebar race fixture now delays only `/me`, allowing its full existing coverage to execute.

## Deployment

Run `php artisan migrate --force` with the deployment's configured PHP runtime before enabling Manager creation. The migration retains role metadata on rollback. MariaDB execution was not available in the local SQLite test environment; it uses standard nullable VARCHAR and guarded schema/backfill operations.

No live deployment, real account creation, commit or push was performed.
