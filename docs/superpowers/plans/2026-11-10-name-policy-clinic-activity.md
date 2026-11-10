# Human names and Clinic activity implementation plan

**Goal:** Enforce shared first/last name limits and correct inline validation, and extend sidebar activity to Clinic.

**Architecture:** Keep JSON as the PHP/generated-JavaScript policy source. Extend existing name bindings and flow value comparisons. Count Clinic appointments through existing model scopes and share the sidebar refresh lifecycle.

**Constraints:** First names 40, last names 80; preserve Unicode/normalization, historical identities, permissions, drafts, existing Grooming activity and Clinic event payloads. No migrations or truncation.

1. [ ] Add failing boundary/lifecycle regressions in `tests/human-name-regression.cjs` and `tests/Feature/HumanNameTest.php`. Extend `HumanName`, `ValidHumanName`, canonical JSON and generated policy; migrate request/controller and frontend consumers with explicit field context. Verify shared policy and creation/profile tests.
2. [ ] Bind customer-name correction to shared lifecycle; preserve unchanged legacy values. Validate walk-in drafts before submission and route field errors to owner step with stored value comparisons and preserved continuation data. Verify signup, owner, customer and settings regressions.
3. [ ] Add Clinic endpoint regressions for status/date/confinement/access. Implement count in `AdminClinicController`, route in Clinic middleware, central API helper and independent sidebar state using one timer. Install matching dot through shared navigation setup; signal refresh after Clinic loads without changing its event shape. Verify sidebar/Clinic regressions and production build.

**Review focus:** IME and numeric sanitization; unresolved errors during editing; stale async responses; unchanged historical names; owner recovery retaining pet/consent data; distinct confinement counts; permission/session invalidation while requests are in flight.

Execution follows the user's authorized implementation request and repository workflow; no worktree or commit required.
