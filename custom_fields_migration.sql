-- =========================================================
-- Custom Dropdown Fields Migration
-- Run once against your existing database to add support
-- for admin-configurable dropdown fields on value_score_form.php
-- =========================================================

-- Defines each custom dropdown field (e.g. "Age Group")
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

-- Options for each custom field (e.g. "Adult", "Teen", "Baby")
CREATE TABLE IF NOT EXISTS custom_field_options (
    option_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_id      INT UNSIGNED NOT NULL,
    option_label  VARCHAR(255) NOT NULL,
    display_order INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_cfo_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Team-based visibility filter (empty = field shows for ALL teams)
CREATE TABLE IF NOT EXISTS custom_field_team_filter (
    field_id INT UNSIGNED NOT NULL,
    team_id  INT          NOT NULL,
    PRIMARY KEY (field_id, team_id),
    CONSTRAINT fk_cftf_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id) ON DELETE CASCADE,
    CONSTRAINT fk_cftf_team  FOREIGN KEY (team_id)  REFERENCES teams(id)              ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Form-based visibility filter (empty = field shows for ALL forms)
CREATE TABLE IF NOT EXISTS custom_field_form_filter (
    field_id INT UNSIGNED NOT NULL,
    form_id  INT          NOT NULL,
    PRIMARY KEY (field_id, form_id),
    CONSTRAINT fk_cfff_field FOREIGN KEY (field_id) REFERENCES custom_fields(field_id)   ON DELETE CASCADE,
    CONSTRAINT fk_cfff_form  FOREIGN KEY (form_id)  REFERENCES form_profiles(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores the value selected by staff per score submission
CREATE TABLE IF NOT EXISTS score_custom_field_values (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    score_id  INT          NOT NULL,
    field_id  INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NULL,
    UNIQUE KEY uq_score_field (score_id, field_id),
    CONSTRAINT fk_scfv_score  FOREIGN KEY (score_id)  REFERENCES scores(id)                ON DELETE CASCADE,
    CONSTRAINT fk_scfv_field  FOREIGN KEY (field_id)  REFERENCES custom_fields(field_id)   ON DELETE CASCADE,
    CONSTRAINT fk_scfv_option FOREIGN KEY (option_id) REFERENCES custom_field_options(option_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexes
CREATE INDEX idx_cfo_field_id    ON custom_field_options(field_id);
CREATE INDEX idx_scfv_score_id   ON score_custom_field_values(score_id);
CREATE INDEX idx_scfv_field_id   ON score_custom_field_values(field_id);
CREATE INDEX idx_scfv_option_id  ON score_custom_field_values(option_id);

-- Seed: Age Group field (first use case)
INSERT INTO custom_fields (field_label, field_key, description, is_required, is_active, display_order)
VALUES ('Age Group', 'age_group', 'The primary age group for this program', 0, 1, 10);

SET @age_field_id = LAST_INSERT_ID();

INSERT INTO custom_field_options (field_id, option_label, display_order) VALUES
(@age_field_id, 'Baby (0–18 months)',       10),
(@age_field_id, 'Pre-School (18 mo – 5yr)', 20),
(@age_field_id, 'Middle School (11–14)',     30),
(@age_field_id, 'Teen (12–18)',              40),
(@age_field_id, 'Adult (18+)',               50),
(@age_field_id, 'All Ages / Mixed',          60);
