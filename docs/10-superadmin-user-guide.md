# Super Admin User Guide

The Super Admin has full access to every aspect of the platform. Every
action is audit-logged.

## Dashboard

The dashboard summarises:

**Partners:** total, pending, active, inactive, suspended, rejected
**NIN:** total enrolments, NIN generated, ERNEW, CRNEW, female, male
**Financials:** total revenue, commission, withdrawn, pending withdrawals,
outstanding balance
**Reports:** submitted, approved, returned
**Charts:** monthly NIN generation, daily report status
**Queues:** pending partner applications, pending withdrawals

Every number is a live query against the current data — nothing is cached.

## Partner Management

Navigate to **Partner Management**.

### Filters

- Search by name, email, or code
- Filter by status
- Filter by state

### Viewing a partner

Click **View** to open the partner's 360° page:

- Profile
- Commission rates (current and historical)
- Stations
- Bank accounts (masked by default; click **Reveal** to see the full
  number — the reveal is audit-logged)
- Enrolment by period (last 12)
- Recent ledger entries
- Recent withdrawals
- Financial summary (available balance, total earned, total withdrawn)

### Actions

From the partner view page, select an action:

| Action | Effect |
|---|---|
| Approve | Status → ACTIVE. Approved timestamp recorded. Initial rate created if a default is configured. |
| Reject | Status → REJECTED (only from PENDING) |
| Suspend | Status → SUSPENDED (only from ACTIVE) |
| Deactivate | Status → INACTIVE (only from ACTIVE) |
| Reactivate | Status → ACTIVE (from SUSPENDED or INACTIVE) |
| Reset Password | Generates a temporary password, emails the partner |

Every action supports an optional comment and is audit-logged.

### Editing a partner

Click **Edit** to update name, email, phone, address, state, and LGA.

**You cannot edit:** NIN, partner code, status (use actions), password
(use reset).

## Commission Rates

Navigate to **Commission Rates**.

### Adding a rate

- Partner
- Commission percentage (0–100)
- Effective From
- Effective To (optional; leave blank for open-ended)
- Notes

If the new rate's `effective_from` is later than an existing open-ended
rate, the existing rate is automatically closed the day before.

### Superseding a rate

Click **Supersede** on an active rate to close it immediately without
creating a replacement. Use with caution — partners without an active rate
cannot be credited.

### How rates affect imports

When you import a batch, each row resolves to the rate effective on the
first day of the batch's period. Historical snapshots are never recalculated.

## Admin Accounts

Navigate to **Admin Accounts**.

### Creating an Admin

- Full Name
- Phone (optional)
- Email
- Initial Password

The new Admin is forced to change the password on first login.

### Existing Admins

Actions per row:

- **Reset Password** — generates a temporary password; the Admin must
  change it at next login
- **Disable / Enable** — toggles status

Disabled Admins cannot log in but their historical reports remain intact.

## Stations

Navigate to **Stations**.

### Creating a station

- Station Code (unique; e.g. `ST-KN-001`)
- Station Name
- Partner (must be ACTIVE)
- State and LGA
- Address

### Managing existing stations

Toggle **Active / Inactive**. Inactive stations cannot be used in new
imports but retain their historical data.

## Catalogues

Three catalogues drive parts of the Daily Report:

### Services

Green Card, Uploading, Results, Admission Letter, Post UTME, and others.
Used in both the Services Rendered Out section and the Accounts Receivable
"Service Rendered" autocomplete.

### Expense Categories

Food, Water, Office Supplies, and others. Used in the Expenses Breakdown
section.

### Staff

Used in the User Performance grid and the Accounts Receivable staff
autocomplete. Curate the real roster here.

All three use the same pattern: add, toggle active/inactive. Deactivated
items no longer appear in new reports but remain visible in historical
reports.

## CSV Import

See `docs/07-csv-import-guide.md` for the full workflow. Summary:

1. **Upload** — period, revenue per NIN, CSV file
2. **Map columns** — associate CSV headers to system fields
3. **Preview** — validate, review row-level outcomes and financial impact
4. **Confirm** — atomic commit

After confirming, the batch list shows the batch as `COMPLETED`. Partner
ledgers are credited, notifications are sent.

## NIN Records

Navigate to **NIN Records** to browse system-wide enrolment data.

Filters:

- Partner
- State
- Station
- Year
- Month

Summary cards show totals for the filtered set. The table lists every
record with its frozen commission snapshot.

## Financial Ledger

Navigate to **Financial Ledger**.

This is the append-only source of truth for all partner balances.

### Recording an adjustment

Occasionally you may need to correct a partner's balance:

- **Positive amount** — credit (e.g. goodwill)
- **Negative amount** — debit (e.g. correction)

Provide a clear description. Adjustment is audit-logged.

### Filtering

Filter by partner and/or transaction type. Use for reconciliation audits.

### What you cannot do

You cannot edit or delete a ledger entry. To reverse one, record an
opposite adjustment.

## Withdrawals

Navigate to **Withdrawals**.

### Filtering

By status (PENDING, APPROVED, PROCESSING, PAID, REJECTED, REVERSED).

### Processing a request

Click **Open** on any request. The detail page shows partner, amount, bank
details, current status, and full history.

Actions depend on status:

- **PENDING** → Approve, Reject
- **APPROVED** → Mark Processing, Reject
- **PROCESSING** → Mark Paid

### Approving

Sets status to APPROVED. The reservation remains in place. The partner is
notified.

### Marking Paid

Enter an optional payment reference. This transaction:

1. Records `WITHDRAWAL_PAID` (negative effect on balance)
2. Records `WITHDRAWAL_REVERSED` (releases the reservation)
3. Updates the withdrawal to PAID
4. Notifies the partner

The sequence runs inside one database transaction.

### Rejecting

Returns the reserved funds to the partner's balance immediately and
notifies them.

## Daily Reports

Navigate to **Daily Reports**.

### Reviewing

Filter by status and date range. Click **Review** on any row to open the
report.

The review page shows:

- A review comment field
- Action buttons appropriate to the current status
- The full rendered report

Actions:

- **Approve** — locks the report; the Admin cannot edit
- **Return** — sends it back to the Admin with your comment; the Admin can
  then edit and resubmit
- **Archive** — keeps it read-only

Every decision is notified to the Admin who filed the report.

## Report Centre

Navigate to **Report Centre**. Seven report families:

- Partner Report
- NIN Report
- Station Report
- Financial Report
- Transaction Report
- Withdrawal Report
- Daily Operational Reports

Each supports filtering by partner, state, station, and period. Click
**Download CSV** to export the filtered set. Exports are audit-logged.

## Analytics

Navigate to **Analytics** for charts:

- Monthly NIN generation
- NIN by state (top 10)
- Female vs Male
- ERNEW vs CRNEW
- Commission by partner (top 10)
- Withdrawals by month
- Partner growth

Charts are computed on demand from the underlying data — no snapshots.

## Settings

See `docs/03-configuration-guide.md` for every configurable value.

## Audit Log

Navigate to **Audit Log** to see every logged action.

Filters:

- Action code (partial match)
- Actor role
- Date range
- Free-text search

Each entry shows actor, action, entity, description, IP address, and
timestamp. NIN values, bank account numbers, and passwords are never
recorded.

## Notifications

View system-wide notifications — new partner registrations, withdrawal
requests, daily report submissions, contact enquiries.

## Profile and Password

Update your name and phone. Email changes require direct database
intervention for security. Change your password from the sidebar.

## Best practices

1. **Verify before you approve.** Partner applications are your first
   defence against fraud.
2. **Do not import a CSV without previewing.** The financial impact panel
   is your last chance to catch a mistake.
3. **Do not edit partner bank details directly.** Use the reveal action
   with audit logging.
4. **Review returned reports within 24 hours.** Admins are blocked from
   editing until you decide.
5. **Never share your Super Admin session.** Every action is attributed
   to you in the audit log.
6. **Keep cron running.** Without it, stale sessions and old tokens
   accumulate.