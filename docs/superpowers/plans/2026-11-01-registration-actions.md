# Fixed Registration Actions Implementation Plan

**Goal:** Keep each active registration step's existing actions visible at the viewport bottom without changing form or navigation behavior.

**Architecture:** Apply one shared stylesheet to existing action rows, preserving their DOM ownership and handlers. Each registration main reserves bottom clearance; its existing maximum width determines button alignment. Hidden grooming steps and replaced walk-in mains retain their existing lifecycle.

**Tech Stack:** Static HTML, Tailwind CSS, native JavaScript modules, Node regression harnesses.

**Spec:** User attachment `Pasted text.txt`, Fixed Bottom Navigation Across All Registration Steps (2026-11-01).

## Constraints and review focus

- Preserve all button IDs, labels, form associations, handlers, disabled/loading states, draft persistence and business rules.
- Active routes: online grooming's four sections; clinic pet/reason/review; walk-in owner/pet/services/review/consent; dynamic clinic reason/consent.
- Confirmation screens and header home links retain their current markup.
- Verify long labels at 320px, last-field and error clearance, warning controls in short viewports, hidden-step visibility and dynamic replacement.
- No new event listeners, requests, dependencies, commits or worktrees. Manual navigation/data entry/submission remains with the user.

## Implementation and checks

1. Add `css/components/registration-actions.css`; tag the existing rows and mains in the nine active registration HTML pages and five walk-in renderers. Use the existing 72rem/64rem widths, navy buttons and responsive gutters. Verify fixed positioning and clearance through rendered layout measurements.
2. Include the versioned stylesheet in those HTML pages and version changed walk-in imports/entry modules. Move the owner warning above the action bar and bound its height. Support safe-area padding, focus scrolling, keyboard viewport resizing and narrow labels. Verify the existing owner page in the single ChatGPT browser tab at desktop/tablet/mobile sizes without entering data or navigating steps.
3. Run focused grooming flow/consent, walk-in owner/pet/review and clinic concern regressions. Run the production build and diff checks. Review asset inclusions and confirmation exclusion. Document results and the manual verification boundary.

This plan uses the repository's advisory planning policy; implementation proceeds in this chat under the user's existing authorization.
