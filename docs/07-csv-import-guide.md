# CSV Import Guide

The CSV import uploads monthly NIN enrolment statistics per partner and/or
station, computes commission, and credits partner ledgers.

## Overview

The import is a four-step wizard:

1. **Upload** — the CSV file and period details
2. **Map Columns** — associate each CSV column with a system field
3. **Preview** — see every row's validation outcome and the financial impact
4. **Confirm** — commit atomically

No ledger entry or enrolment record exists until Step 4.

## Step 1 — Upload

Navigate to **Super Admin → CSV Import → New Import**.

Provide:

| Field | Example | Notes |
|---|---|---|
| Period Year | `2026` | Reporting year |
| Period Month | `March` | Reporting month |
| Revenue per NIN | `100.00` | Naira value; snapshotted into every record |
| CSV File | `march-nin.csv` | Max size per `upload_max_size_mb` |

The batch is created with status `UPLOADED` immediately. The file is stored
under `uploads/imports/` with a randomised filename.

## CSV format

- **Delimiter:** comma, semicolon, tab, or pipe (auto-detected)
- **Encoding:** UTF-8 (with or without BOM)
- **Header row:** required
- **Row order:** any
- **Empty lines:** ignored

### Recognised columns

| System field | Required | Notes |
|---|---|---|
| Partner Code | One of these two required | e.g. `PICT-P-000042` |
| Partner Email | Alternative to Partner Code | e.g. `partner@example.com` |
| Station Code | Optional | If blank, the record is partner-level |
| Station Name | Optional | Informational |
| Total Enrolments | Optional | Default `0` |
| Total NIN Generated | **Required** | The primary metric |
| ERNEW NIN | Optional | Default `0` |
| CRNEW NIN | Optional | Default `0` |
| Female | Optional | Default `0` |
| Male | Optional | Default `0` |

Any CSV column not mapped is ignored. Column names can be anything —
mapping happens in Step 2.

### Example CSV

```csv
partner_code,station_code,total_enrolments,total_nin_generated,ernew_nin,crnew_nin,female_count,male_count
PICT-P-000042,ST-KN-001,120,118,90,28,60,58
PICT-P-000042,ST-KN-002,95,94,70,24,50,44
PICT-P-000043,ST-KN-003,60,60,55,5,30,30