# Backup Guide

## What to back up

| Asset | Location | Frequency | Retention |
|---|---|---|---|
| Database | MySQL | Daily | 14 pairs (default) |
| Uploaded files | `uploads/` | Daily | 14 pairs (default) |
| Configuration | `config/` | On change | In version control |
| Application code | Whole project | On deploy | In version control |

The backup script handles the first two. The last two are handled by your
version control and deployment process.

## Automatic backups

The `cron/backup.php` script produces:

- `backups/db-YYYYMMDD-HHMMSS.sql.gz` — compressed MySQL dump
- `backups/uploads-YYYYMMDD-HHMMSS.tar.gz` — compressed uploads archive

### Invocation

**CLI (recommended):**