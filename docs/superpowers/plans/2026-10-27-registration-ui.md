# Online Registration UI implementation plan

Goal: simplify the existing registration surfaces while preserving FCFS, saved prices, consent, permissions and cached/lazy data loading.

Design: retain Bethlehem portal/confirmation tokens, Phosphor icons and current components. No reference image was supplied; use the attached written specifications. Hallmark and planning guidance are advisory under AGENTS.md; no new design system, worktree or approval gate is needed.

1. Update existing regression expectations for online/walk-in wording, compact tracker states, queue tiles and saved multi-pet summaries. Verify the new expectations fail before presentation changes.
2. Modify registration-queue-information.js and existing confirmation/summary components and their CSS. Keep shared metadata once, explicit negative consent and per-pet saved selections, states, queue numbers, duration and price ranges. Verify existing confirmation and summary regressions.
3. Modify client-dashboard.js/dashboard.html, relevant customer entry labels and admin Incoming/details in appointments.html/admin-dashboard.js, with Clinic terminology in clinic.html/admin-clinic.js. Preserve handlers, restrictions and ordering. Verify dashboard, Incoming and workflow regressions.
4. Version affected assets/imports, build CSS and production assets; run frontend regressions and relevant isolated PHPUnit suites. Inspect only the Customer dashboard in the ChatGPT browser. Document actual results and deployment/manual checks.

Review focus: mixed pet progress; online versus walk-in source; failed aggregate counts; pet/service association and saved ranges; modal focus and stale requests. Use existing tests for each; do not add source-string tests for visual CSS.

Expected edits: existing customer/staff registration pages and components, confirmation and portal CSS, affected existing tests, notification/access presentation copy if necessary, and redeployment notes. No production deployment, schema changes, file deletions or manual submission flows.
