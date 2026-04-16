-- =============================================================
-- Intake: clean out test tenants from debugging session
-- =============================================================
--
-- Run on the server:
--   mysql -u intake -pdeorext1 intake < cleanup.sql
--
-- Deletes the two test tenants created during debugging, along
-- with everything that hangs off them (thanks to the cascading
-- foreign keys on tenant_id). Master admin user is untouched.
--
-- Safe to run more than once — just no-ops if the rows are gone.

SET FOREIGN_KEY_CHECKS = 1;

-- Show what we're about to nuke, for the operator
SELECT subdomain, name, id, is_active, created_at
FROM tenants
WHERE subdomain IN ('the-bike-hub', 'velonw');

-- Delete. Everything else (tenant_users, tenant_service_*, pages, etc.)
-- cascades via foreign keys.
DELETE FROM tenants WHERE subdomain IN ('the-bike-hub', 'velonw');

-- Verify
SELECT COUNT(*) AS remaining_tenants FROM tenants;

-- Done
