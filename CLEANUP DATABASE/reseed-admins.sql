-- =====================================================================
-- Re-seed the Super Admin account after cleanup.
-- Replace the two placeholder values below BEFORE running.
-- =====================================================================

INSERT INTO `super_admins`
  (full_name, email, phone, password_hash, must_change_pwd,
   two_factor_enabled, status, created_at)
VALUES
  ('Super Administrator',
   'REPLACE-WITH-REAL-EMAIL@yourdomain.com',
   NULL,
   'REPLACE-WITH-REAL-BCRYPT-HASH',
   0,
   0,
   'ACTIVE',
   NOW());

-- Optional: seed one Admin account as well
INSERT INTO `admins`
  (full_name, email, phone, password_hash, must_change_pwd,
   status, created_by_super_admin_id, created_at)
VALUES
  ('Operations Admin',
   'REPLACE-WITH-ADMIN-EMAIL@yourdomain.com',
   NULL,
   'REPLACE-WITH-THE-SAME-OR-A-DIFFERENT-BCRYPT-HASH',
   0,
   'ACTIVE',
   (SELECT id FROM (SELECT id FROM super_admins ORDER BY id DESC LIMIT 1) AS t),
   NOW());