# Admin User Guide

For Admin users who file the Daily Report.

## Sign in

Navigate to `/admin/login.php`. If your account still has an initial
password, you will be redirected to change it before proceeding.

## Dashboard

The dashboard shows:

- **Drafts** — reports saved but not submitted
- **Submitted** — awaiting Super Admin review
- **Returned** — sent back to you for correction
- **Approved** — locked

Below are your recent reports and any reports that were returned with a
Super Admin comment. Returned reports require you to make corrections and
resubmit.

## Filing a Daily Report

Navigate to **Daily Report → New Daily Report**. Complete every section in
order.

### 1. Report Information

- **Date** — default is today. Adjust if filing for a past date (up to 30
  days back).
- **Created By** — automatically set to you.

### 2. Executive Summary

A narrative overview of the day. Cover receivables, user performance,
services rendered, expenses, revenue, and any problems. This is the first
thing the reader sees, so make it substantive.

### 3. Accounts Receivable

Add a row for each outstanding receivable:

| Field | Notes |
|---|---|
| Staff Name | Start typing to select from the catalogue |
| Service Rendered | Same — catalogue autocomplete |
| Unit | Free-text description |
| Amount | In the currency shown in the column header (per source document) |
| Due Date | Optional |

Click **+ Add Row** to add more. Click the × to remove a row.

### 4. User Performance

A weekly grid of staff performance.

- Add one row per staff member.
- Enter a value for each day of the week.
- **Weekly Total** is calculated automatically.
- A bar chart appears below the table.

The unit of measure (enrolments, transactions, etc.) is configured by the
Super Admin and shown in the section header.

### 5. Services Rendered Out

Add a row per service:

- **Service** — catalogue autocomplete
- **Units Sold** — whole number
- **Revenue** — in Naira
- **Due Date** — optional

Click **+ Add Service** to add more.

### 6. Expenses Breakdown

Add a row per expense:

- **Category** — catalogue autocomplete
- **Description** — free text
- **Amount** — in Naira

Click **+ Add Expense** to add more.

### 7. Financial Summary

Calculated, not editable:

- **Revenue** — pulled from Services Rendered Out (or Weekly Revenue,
  depending on configuration)
- **Expenses** — total from the Expenses section
- **Net Income** — Revenue minus Expenses

The Net Income box turns red if it is negative.

### 8. Revenue / Sales

Seven fields — Monday through Sunday.

- Enter the revenue for each day.
- **Weekly Total** is calculated automatically.
- A bar chart appears below.

### 9. Problem / Issues

Four narrative fields:

- **Problems / Issues Identified** — what went wrong
- **Actions Taken** — what was done
- **Recommended Action** — what should happen next
- **Additional Notes** — anything else

### 10. Submit

Two buttons at the bottom of the form:

- **Save as Draft** — saves without full validation; can be edited later
- **Submit Daily Report** — full validation; moves the report to SUBMITTED

On submit, the server recalculates every total. Any total shown on your
screen is a convenience for you, not an authoritative value.

## After submission

You are redirected to a **Report Preview** matching the printed layout.
Two options:

- **Print / Save as PDF** — opens a clean A4 page
- **Back to History** — returns to the reports list

The report is now locked from editing. It cannot be reopened unless the
Super Admin returns it.

## Report History

Navigate to **Report History** to see all your reports.

Filters:

- Status
- Date range

Each row shows reference, date, status, net income, submitted timestamp,
last-updated timestamp.

Actions:

- **View** — see the report
- **Edit** — only for DRAFT or RETURNED reports

## Returned reports

When a Super Admin returns a report, you receive a notification. Open it to
see the review comment. Click **Edit** to reopen the form, make corrections,
and submit again.

Resubmitting keeps the same report reference. The revision is recorded but
the reference does not change.

## Printing and PDF

From the report preview or view page:

1. Click **Print / Save as PDF**.
2. The browser opens a new tab with the report rendered in A4 layout.
3. Use the browser's print dialog:
   - Select **Save as PDF** as the destination, or
   - Print to a physical printer

The layout includes the company header, report metadata, all sections, and
charts. The URL bar, buttons, and navigation are excluded from the printed
page.

## Notifications

Navigate to **Notifications** to see:

- Report returned for correction
- Report approved
- System announcements

## Change Password

Navigate to **Change Password**, provide current, new, and confirmation.

## Support

If you cannot log in, use the password reset link on the login page or
contact the Super Admin.