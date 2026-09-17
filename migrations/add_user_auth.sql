-- =========================================================
-- Migration: Add user authentication columns
-- MySQL 8.0 compatible — run once on existing databases.
-- Fresh Docker builds already have these columns via init.sql.
-- =========================================================

ALTER TABLE users
    ADD COLUMN password_hash        VARCHAR(255) NULL          COMMENT 'bcrypt hash; NULL = no manual login set',
    ADD COLUMN microsoft_oid        VARCHAR(255) NULL          COMMENT 'Azure AD / Entra Object ID',
    ADD COLUMN microsoft_email      VARCHAR(255) NULL          COMMENT 'Email address returned by Microsoft token',
    ADD COLUMN must_change_password TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Force password change on next login',
    ADD COLUMN account_active       TINYINT(1)   NOT NULL DEFAULT 1,
    ADD COLUMN is_admin             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = can access admin portal',
    ADD COLUMN last_login           DATETIME     NULL;

CREATE INDEX idx_users_email           ON users (email);
CREATE INDEX idx_users_microsoft_oid   ON users (microsoft_oid);
CREATE INDEX idx_users_microsoft_email ON users (microsoft_email);
