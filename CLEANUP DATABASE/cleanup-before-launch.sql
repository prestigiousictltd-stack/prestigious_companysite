-- =====================================================================
-- Pre-launch database cleanup — v2
-- =====================================================================
-- Uses DELETE FROM instead of TRUNCATE so the script works in every
-- MySQL/MariaDB tool (phpMyAdmin, command line, GUI clients) without
-- depending on the FOREIGN_KEY_CHECKS session flag persisting.
--
-- After deleting, auto-increment counters are reset explicitly.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Financial ledger (append-only tables)
-- ---------------------------------------------------------------------
DELETE FROM `partner_financial_transactions`;
DELETE FROM `aggregator_financial_transactions`;

-- ---------------------------------------------------------------------
-- 2. Withdrawals and their history
-- ---------------------------------------------------------------------
DELETE FROM `withdrawal_status_history`;
DELETE FROM `withdrawals`;

-- ---------------------------------------------------------------------
-- 3. Enrolment and settlement records
-- ---------------------------------------------------------------------
DELETE FROM `aggregator_enrolment_records`;
DELETE FROM `partner_enrolment_records`;

-- ---------------------------------------------------------------------
-- 4. Import batches
-- ---------------------------------------------------------------------
DELETE FROM `import_batch_records`;
DELETE FROM `import_batches`;

-- ---------------------------------------------------------------------
-- 5. Rate histories and assignments
-- ---------------------------------------------------------------------
DELETE FROM `partner_aggregator_assignments`;
DELETE FROM `sub_account_commission_rates`;
DELETE FROM `partner_commission_rates`;
DELETE FROM `aggregator_commission_rates`;

-- ---------------------------------------------------------------------
-- 6. Bank accounts and sub-accounts
-- ---------------------------------------------------------------------
DELETE FROM `partner_bank_accounts`;
DELETE FROM `partner_sub_accounts`;

-- ---------------------------------------------------------------------
-- 7. Stations, partners, aggregators
-- ---------------------------------------------------------------------
DELETE FROM `stations`;
DELETE FROM `partners`;
DELETE FROM `aggregators`;

-- ---------------------------------------------------------------------
-- 8. Daily Report children then parent
-- ---------------------------------------------------------------------
DELETE FROM `daily_report_receivables`;
DELETE FROM `daily_report_user_performance`;
DELETE FROM `daily_report_services`;
DELETE FROM `daily_report_expenses`;
DELETE FROM `daily_report_revenue`;
DELETE FROM `daily_report_notes`;
DELETE FROM `daily_reports`;

-- ---------------------------------------------------------------------
-- 9. Communication and auth artefacts
-- ---------------------------------------------------------------------
DELETE FROM `notifications`;
DELETE FROM `login_attempts`;
DELETE FROM `password_reset_tokens`;
DELETE FROM `audit_logs`;

-- ---------------------------------------------------------------------
-- 10. Admin and Super Admin accounts
-- ---------------------------------------------------------------------
DELETE FROM `admins`;
DELETE FROM `super_admins`;

-- =====================================================================
-- Reset auto-increment counters so IDs start at 1 in production.
-- ALTER TABLE is a metadata change; it works on parent tables.
-- =====================================================================
ALTER TABLE `partner_financial_transactions` AUTO_INCREMENT = 1;
ALTER TABLE `aggregator_financial_transactions` AUTO_INCREMENT = 1;
ALTER TABLE `withdrawal_status_history` AUTO_INCREMENT = 1;
ALTER TABLE `withdrawals` AUTO_INCREMENT = 1;
ALTER TABLE `aggregator_enrolment_records` AUTO_INCREMENT = 1;
ALTER TABLE `partner_enrolment_records` AUTO_INCREMENT = 1;
ALTER TABLE `import_batch_records` AUTO_INCREMENT = 1;
ALTER TABLE `import_batches` AUTO_INCREMENT = 1;
ALTER TABLE `partner_aggregator_assignments` AUTO_INCREMENT = 1;
ALTER TABLE `sub_account_commission_rates` AUTO_INCREMENT = 1;
ALTER TABLE `partner_commission_rates` AUTO_INCREMENT = 1;
ALTER TABLE `aggregator_commission_rates` AUTO_INCREMENT = 1;
ALTER TABLE `partner_bank_accounts` AUTO_INCREMENT = 1;
ALTER TABLE `partner_sub_accounts` AUTO_INCREMENT = 1;
ALTER TABLE `stations` AUTO_INCREMENT = 1;
ALTER TABLE `partners` AUTO_INCREMENT = 1;
ALTER TABLE `aggregators` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_receivables` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_user_performance` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_services` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_expenses` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_revenue` AUTO_INCREMENT = 1;
ALTER TABLE `daily_report_notes` AUTO_INCREMENT = 1;
ALTER TABLE `daily_reports` AUTO_INCREMENT = 1;
ALTER TABLE `notifications` AUTO_INCREMENT = 1;
ALTER TABLE `login_attempts` AUTO_INCREMENT = 1;
ALTER TABLE `password_reset_tokens` AUTO_INCREMENT = 1;
ALTER TABLE `audit_logs` AUTO_INCREMENT = 1;
ALTER TABLE `admins` AUTO_INCREMENT = 1;
ALTER TABLE `super_admins` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Verification — every row must be 0
-- =====================================================================
SELECT 'partners' AS tbl, COUNT(*) AS rows_after FROM partners
UNION ALL SELECT 'aggregators',           COUNT(*) FROM aggregators
UNION ALL SELECT 'stations',              COUNT(*) FROM stations
UNION ALL SELECT 'import_batches',        COUNT(*) FROM import_batches
UNION ALL SELECT 'import_batch_records',  COUNT(*) FROM import_batch_records
UNION ALL SELECT 'enrolment_records',     COUNT(*) FROM partner_enrolment_records
UNION ALL SELECT 'aggregator_settlements', COUNT(*) FROM aggregator_enrolment_records
UNION ALL SELECT 'partner_ledger',        COUNT(*) FROM partner_financial_transactions
UNION ALL SELECT 'aggregator_ledger',     COUNT(*) FROM aggregator_financial_transactions
UNION ALL SELECT 'withdrawals',           COUNT(*) FROM withdrawals
UNION ALL SELECT 'withdrawal_history',    COUNT(*) FROM withdrawal_status_history
UNION ALL SELECT 'daily_reports',         COUNT(*) FROM daily_reports
UNION ALL SELECT 'notifications',         COUNT(*) FROM notifications
UNION ALL SELECT 'audit_logs',            COUNT(*) FROM audit_logs
UNION ALL SELECT 'admins',                COUNT(*) FROM admins
UNION ALL SELECT 'super_admins',          COUNT(*) FROM super_admins
UNION ALL SELECT '--- reference data (must stay populated) ---', 0
UNION ALL SELECT 'states',                COUNT(*) FROM states
UNION ALL SELECT 'lgas',                  COUNT(*) FROM lgas
UNION ALL SELECT 'services',              COUNT(*) FROM services
UNION ALL SELECT 'expense_categories',    COUNT(*) FROM expense_categories
UNION ALL SELECT 'staff',                 COUNT(*) FROM staff
UNION ALL SELECT 'system_settings',       COUNT(*) FROM system_settings;