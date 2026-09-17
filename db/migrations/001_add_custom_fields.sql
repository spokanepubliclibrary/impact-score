-- =========================================================
-- 001_add_custom_fields.sql
--
-- Adds support for admin-configurable dropdown fields used by
-- value_score_form.php (e.g., "Age Group"). Originated as the standalone
-- custom_fields_migration.sql at the repo root.
--
-- Idempotent: re-running against a DB that already has these tables is a no-op.
-- Both fresh installs (via db/init.sql) and existing deployments converge on
-- the same schema.
-- =========================================================

CREATE TABLE IF NOT EXISTS custom_fields (
    field_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_label   VARCHAR(255) NOT NULL,
    field_key     VARCHAR(100) NOT NULL,
    description   VARCHAR(500) NULL,
    is_required   TINYINT(1)   NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    display_order INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_field_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_options (
    option_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_id      INT UNSIGNED NOT NULL,
    option_label  VARCHAR(255) NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_cfo_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_team_filter (
    field_id INT UNSIGNED NOT NULL,
    team_id  INT          NOT NULL,
    PRIMARY KEY (field_id, team_id),
    CONSTRAINT fk_cftf_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id) ON DELETE CASCADE,
    CONSTRAINT fk_cftf_team  FOREIGN KEY (team_id)  REFERENCES teams(id)               ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_form_filter (
    field_id INT UNSIGNED NOT NULL,
    form_id  INT          NOT NULL,
    PRIMARY KEY (field_id, form_id),
    CONSTRAINT fk_cfff_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id) ON DELETE CASCADE,
    CONSTRAINT fk_cfff_form  FOREIGN KEY (form_id)  REFERENCES form_profiles(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS score_custom_field_values (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    score_id  INT          NOT NULL,
    field_id  INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NULL,
    UNIQUE KEY uq_score_field (score_id, field_id),
    CONSTRAINT fk_scfv_score  FOREIGN KEY (score_id)  REFERENCES scores(id)                       ON DELETE CASCADE,
    CONSTRAINT fk_scfv_field  FOREIGN KEY (field_id)  REFERENCES custom_fields(field_id)          ON DELETE CASCADE,
    CONSTRAINT fk_scfv_option FOREIGN KEY (option_id) REFERENCES custom_field_options(option_id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_cfo_field_id   ON custom_field_options(field_id);
CREATE INDEX idx_scfv_score_id  ON score_custom_field_values(score_id);
CREATE INDEX idx_scfv_field_id  ON score_custom_field_values(field_id);
CREATE INDEX idx_scfv_option_id ON score_custom_field_values(option_id);

-- Seed: Age Group field (first use case). Idempotent via INSERT IGNORE +
-- unique key on field_key.
INSERT IGNORE INTO custom_fields (field_label, field_key, description, is_required, is_active, display_order)
VALUES ('Age Group', 'age_group', 'The primary age group for this program', 0, 1, 10);

-- Seed Age Group options (only if the field exists and options aren't already there).
INSERT IGNORE INTO custom_field_options (field_id, option_label, display_order)
SELECT cf.field_id, x.option_label, x.display_order
FROM custom_fields cf
JOIN (
    SELECT 'Baby (0–18 months)'       AS option_label, 10 AS display_order
    UNION ALL SELECT 'Pre-School (18 mo – 5yr)',          20
    UNION ALL SELECT 'Middle School (11–14)',             30
    UNION ALL SELECT 'Teen (12–18)',                      40
    UNION ALL SELECT 'Adult (18+)',                       50
    UNION ALL SELECT 'All Ages / Mixed',                  60
) AS x
WHERE cf.field_key = 'age_group'
AND NOT EXISTS (
    SELECT 1 FROM custom_field_options cfo
    WHERE cfo.field_id = cf.field_id AND cfo.option_label = x.option_label
);
