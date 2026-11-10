# Immutable pet history

Deploy the changed Laravel source, migration, and raw `pages/` and `scripts/` files through the existing Hostinger release process. The approved Add/Edit Pet UI from `4f18a50` is unchanged.

Run the normal production migration step (`php artisan migrate --force`) with the site's configured PHP binary, under the existing maintenance/backup procedure. Migration `2026_11_10_000002_add_pet_profile_snapshots` automatically adds nullable JSON storage to `booking_pets` and `clinic_appointments` and freezes existing rows in deterministic chunks of 200. No separate backfill command, manual SQL, or seed is required. Both MySQL/MariaDB and the SQLite test database use Laravel's JSON support.

New visits capture registration metadata from saved Pets; physical check-in captures intake metadata after existing profile confirmations. Already-on-site walk-ins capture both stages. Repeated check-in requests do not replace intake. Full grooming check-in reversal clears intake and the session confirmation, retaining registration. Current Pet edits and finalized clinical BCS synchronization never refresh service snapshots.

History resolves intake, then registration, then legacy baseline. Missing snapshots produce absent historical profile values; they never fall back to a live Pet. Historical search uses the same stage precedence and case-insensitive name/species/breed matching where those fields were already searchable. Dedicated grooming registered/confirmed sizes and visit clinical records/vitals retain their own authority. Existing service names/kinds and prices use stored service snapshots where available.

Legacy baselines stop future drift; unavailable original metadata cannot be reconstructed. They are internally marked `source: legacy_baseline` and do not copy today's verification flags. Reliable confirmed session sizes remain intact. Clinical records, vitals, treatments, service lines, pricing, timestamps, and activity records are not rewritten by the backfill.

Backfill reads use a left join per chunk, handle missing linked Pets, and conditionally update only null snapshots. Reruns preserve populated snapshots and emit no activity events or notifications. Internal snapshot payloads are hidden from model serialization and excluded by the existing activity allowlist.

After deployment, check a grooming and clinic history record, edit the current pet profile, and confirm historical identity/search and visit facts stay fixed while current screens update. A rollback drops only the new JSON columns; it discards captured profile history, so retain the production backup if rolling back.

Local automated verification does not apply this migration or deploy anything to Hostinger.
