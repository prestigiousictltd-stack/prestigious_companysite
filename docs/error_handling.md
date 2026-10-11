Validation rules
A row is valid only if:

total_nin_generated is a non-negative integer

Partner resolves to an existing, ACTIVE partner

Station (if provided) belongs to that partner

A commission rate applies to the partner on the period's effective date
(the first day of the period month)

No enrolment record already exists for the same
(partner, station, year, month)

Any failure produces a row-level error with a specific reason.

Duplicate handling
Duplicate detection is period-based. If you import for March 2026 and a
record already exists for the same partner + station in March 2026, the
row is flagged as duplicate.

You cannot double-import a period. To correct a mistake, delete the
import_batch (which cascades to partner_enrolment_records), then
re-import. Ledger entries are not automatically reversed — a Super Admin
must record a manual REVERSAL adjustment.

Step 4 — Confirm
Two options, depending on error count:

All valid (error_rows = 0)
Click Confirm Import. The entire batch is committed inside one
transaction:

Insert all partner_enrolment_records with frozen commission snapshots

Insert COMMISSION_EARNED ledger entries per partner

Update import_batches.status = 'COMPLETED'

Notify each affected partner (twice: new records + commission credited)

Write an audit log entry

If any step fails, the entire transaction rolls back. Status becomes
FAILED.

Some errors (error_rows > 0)
Two choices:

Reject batch (all-or-nothing) — recommended for financial accuracy.
Batch status becomes FAILED. No data committed.

Import valid rows only (partial) — the batch is marked
partial_import = 1. Only the valid rows are committed. The error CSV is
available for correction. This action is audited.

The all-or-nothing default exists because partial imports silently drop
partner data. Only use partial import when the errors are known
non-financial (e.g. an information-only column) or when the corrected rows
will be imported in a follow-up batch.

Error CSV
At any time during the wizard, click Download Error CSV. The file
contains one row per error:

csv
Row #,Reason
42,Unknown or inactive partner
87,Duplicate: already imported for period March 2026
113,No applicable commission rate for partner on 2026-03-01
Use this file to correct the source and re-import.

Commission resolution
The commission percentage applied to each row is the rate whose
effective_from is on or before the first day of the period month, and
whose effective_to is either null or on or after that date.

If a partner has no applicable rate, the row fails. Set up a rate at
Super Admin → Commission Rates first.

What happens after import
For each partner receiving records:

partner_enrolment_records gains rows with frozen values.

partner_financial_transactions gains one COMMISSION_EARNED entry per
enrolment record.

Partner's available balance increases by the sum of commission.

Partner receives two notifications in the portal.

The partner cannot modify any of these records from their portal.

Re-importing after a mistake
If an import was committed with incorrect data:

Note the batch ID from CSV Import list.

Confirm with the business that a reversal is desired.

Record a manual REVERSAL ledger entry per affected partner for the
exact commission amount (Super Admin → Financial Ledger → Record
Adjustment with a negative amount).

Delete the enrolment records:

sql
DELETE FROM partner_enrolment_records WHERE import_batch_id = <id>;
(Cascade will not run — partner_enrolment_records is referenced by the
ledger, so use caution; the related_enrolment_id column is
ON DELETE SET NULL, so the ledger row survives with a null reference.)

Re-import the corrected file.

Warning: Step 4 does not reverse the ledger. Always do Step 3 first.
This is intentional — financial history must not vanish silently.