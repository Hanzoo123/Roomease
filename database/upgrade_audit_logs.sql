-- =========================================================
-- Upgrade: admin_actions becomes audit_logs
-- =========================================================
-- For a database created from an older boardinghouse.sql, which has the
-- admin-only admin_actions table. Run it once; every entry already logged is
-- kept, and marked as an administrator's. A fresh import of boardinghouse.sql
-- already has audit_logs and does not need this.
--
--   mysql -u root -p roomease < database/upgrade_audit_logs.sql
--
-- The log now also records what landlords change and every sign-in, so the
-- table and its "admin" column are renamed to say so.
-- =========================================================

ALTER TABLE admin_actions
    DROP FOREIGN KEY fk_actions_admin;

ALTER TABLE admin_actions
    RENAME TO audit_logs;

ALTER TABLE audit_logs
    RENAME COLUMN action_id TO log_id,
    RENAME COLUMN admin_id TO actor_id,
    ADD COLUMN actor_role VARCHAR(20) NULL AFTER actor_id,
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER detail,
    ADD COLUMN user_agent VARCHAR(120) NULL AFTER ip_address,
    DROP INDEX idx_actions_created,
    DROP INDEX idx_actions_target,
    DROP INDEX idx_actions_admin,
    ADD KEY idx_audit_created (created_at),
    ADD KEY idx_audit_target (target_type, target_id, created_at),
    ADD KEY idx_audit_actor (actor_id, created_at),
    ADD KEY idx_audit_action (action, created_at),
    ADD CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id)
        REFERENCES users(user_id) ON DELETE SET NULL;

-- Everything logged before this upgrade was an administrator's doing.
UPDATE audit_logs SET actor_role = 'administrator' WHERE actor_role IS NULL;
