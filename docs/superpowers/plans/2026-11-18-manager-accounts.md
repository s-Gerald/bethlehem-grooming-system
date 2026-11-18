# Manager accounts implementation plan

**Goal:** Extend the existing Admin family with Manager operational access and Owner-only system account administration.

**Architecture:** Keep `users.role` unchanged. Put Owner/Manager eligibility in User and manageable role mappings in AccountRole, preserving StaffRole. Extend existing invitation, credential, recovery and Security history services without parallel implementations.

**Tech stack:** Laravel 12, MariaDB-compatible migration, Alpine, vanilla JavaScript, PHPUnit and Node regressions.

**Spec:** User attachment `Pasted text.txt`, 2026-11-18.

## Constraints and review focus

- Existing nullable Admin identities remain Owner; migration backfills only existing Admins and is repeatable.
- Owner cannot be created or targeted through account management; Manager receives 403 across its APIs.
- Keep setup placeholder/token formats and existing pending staff links valid.
- Manager uses Admin password policy with self-service session semantics; permission changes invalidate sessions and pending credential requests.
- Preserve recognition, resolution cutoff, legacy history, pagination, focused incident navigation and retention unchanged.
- Preserve Bethlehem styles and General/QR operational Admin access; no Accounts request for Manager Security.

## Tasks

1. User, AccountRole and migration: add focused failing account authorization/lifecycle tests; implement nullable admin_type, identity helpers, role mappings, revision and attribution. Verify migration repeatability and operational permissions.
2. Routes and AdminSecurityController: use Owner middleware only for administrative Security routes; share creation and collection paths with legacy URLs; extend role/status/setup targets to manageable accounts. Verify direct Manager calls fail and Owner cannot be demoted.
3. PendingStaffAccountService, PasswordResetService/Controller, StaffEmailChangeService, StaffEmailRecoveryService and PrivilegedCredentialChangeService: extend eligibility through User helpers, preserve locks/tokens/OTP behavior and policies. Verify Manager setup/sign-in, transitions, deactivation, self-service and assisted recovery with existing tests extended.
4. AuthController, scripts/api.js, admin-settings.js, admin-sidebar.js and templates: carry admin_type in identity/cache; distinguish Owner Security from self-service; add Manager directly to details in Add Account; generic Accounts copy and Owner password labels. Verify Node behaviors for Manager identity, role selection and Security routing.
5. Extend AccountSecurityActivityTest for Manager without replacing current coverage. Run focused PHP lifecycle/security/general tests and Node regressions, review the diff and update touched asset versions.

Execute in this session under the repository's advisory skill rules. Do not add commits, worktrees, dependencies or deployment changes.

Completed and verified. See `docs/manager-accounts-validation.md` for final coverage and the two independently reproduced existing full-suite failures. Deployment seeding was also checked to preserve the single Owner and Manager promotions.
