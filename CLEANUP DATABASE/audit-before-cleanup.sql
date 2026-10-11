-- =====================================================================
-- Pre-cleanup audit
-- Run against prestigiousict_ng
-- =====================================================================

SELECT 'PARTNERS'   AS entity, COUNT(*) AS cnt FROM partners
UNION ALL SELECT '  PENDING',        COUNT(*) FROM partners WHERE status='PENDING'
UNION ALL SELECT '  ACTIVE',         COUNT(*) FROM partners WHERE status='ACTIVE'
UNION ALL SELECT '  INACTIVE',       COUNT(*) FROM partners WHERE status='INACTIVE'
UNION ALL SELECT '  SUSPENDED',      COUNT(*) FROM partners WHERE status='SUSPENDED'
UNION ALL SELECT '  REJECTED',       COUNT(*) FROM partners WHERE status='REJECTED'
UNION ALL SELECT 'SUB-ACCOUNTS',     COUNT(*) FROM partner_sub_accounts
UNION ALL SELECT 'AGGREGATORS',      COUNT(*) FROM aggregators
UNION ALL SELECT 'STATIONS',         COUNT(*) FROM stations
UNION ALL SELECT 'BANK ACCOUNTS',    COUNT(*) FROM partner_bank_accounts
UNION ALL SELECT 'COMMISSION RATES (partner)', COUNT(*) FROM partner_commission_rates
UNION ALL SELECT 'COMMISSION RATES (sub)',     COUNT(*) FROM sub_account_commission_rates
UNION ALL SELECT 'COMMISSION RATES (aggregator)', COUNT(*) FROM aggregator_commission_rates
UNION ALL SELECT 'AGGREGATOR ASSIGNMENTS', COUNT(*) FROM partner_aggregator_assignments
UNION ALL SELECT 'IMPORT BATCHES',   COUNT(*) FROM import_batches
UNION ALL SELECT 'IMPORT BATCH ROWS', COUNT(*) FROM import_batch_records
UNION ALL SELECT 'ENROLMENT RECORDS', COUNT(*) FROM partner_enrolment_records
UNION ALL SELECT 'AGGREGATOR SETTLEMENTS', COUNT(*) FROM aggregator_enrolment_records
UNION ALL SELECT 'PARTNER LEDGER ENTRIES', COUNT(*) FROM partner_financial_transactions
UNION ALL SELECT 'AGGREGATOR LEDGER ENTRIES', COUNT(*) FROM aggregator_financial_transactions
UNION ALL SELECT 'WITHDRAWALS',      COUNT(*) FROM withdrawals
UNION ALL SELECT 'WITHDRAWAL HISTORY', COUNT(*) FROM withdrawal_status_history
UNION ALL SELECT 'DAILY REPORTS',    COUNT(*) FROM daily_reports
UNION ALL SELECT 'NOTIFICATIONS',    COUNT(*) FROM notifications
UNION ALL SELECT 'LOGIN ATTEMPTS',   COUNT(*) FROM login_attempts
UNION ALL SELECT 'PASSWORD RESET TOKENS', COUNT(*) FROM password_reset_tokens
UNION ALL SELECT 'AUDIT LOGS',       COUNT(*) FROM audit_logs
UNION ALL SELECT 'ADMINS',           COUNT(*) FROM admins
UNION ALL SELECT 'SUPER ADMINS',     COUNT(*) FROM super_admins
UNION ALL SELECT '--- REFERENCE DATA (keep) ---', 0
UNION ALL SELECT '  STATES',         COUNT(*) FROM states
UNION ALL SELECT '  LGAS',           COUNT(*) FROM lgas
UNION ALL SELECT '  SERVICES',       COUNT(*) FROM services
UNION ALL SELECT '  EXPENSE CATEGORIES', COUNT(*) FROM expense_categories
UNION ALL SELECT '  STAFF',          COUNT(*) FROM staff
UNION ALL SELECT '  SYSTEM SETTINGS', COUNT(*) FROM system_settings;