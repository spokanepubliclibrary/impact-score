-- Impact Score Dashboard — Database Initialization
-- Matches production schema from INFORMATION_SCHEMA inspection
-- Run automatically by MySQL on first container start via docker-entrypoint-initdb.d

CREATE DATABASE IF NOT EXISTS impact_score CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE impact_score;

-- -------------------------------------------------------
-- teams
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS teams (
    id   INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                   INT          NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(255) NOT NULL,
    default_team         INT          DEFAULT NULL,
    email                VARCHAR(255) DEFAULT NULL,
    password_hash        VARCHAR(255) NULL          COMMENT 'bcrypt hash; NULL = no manual login set',
    microsoft_oid        VARCHAR(255) NULL          COMMENT 'Azure AD Object ID',
    microsoft_email      VARCHAR(255) NULL          COMMENT 'Email from Microsoft token',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    account_active       TINYINT(1)   NOT NULL DEFAULT 1,
    is_admin             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = can access admin portal',
    last_login           DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_users_email           (email),
    KEY idx_users_microsoft_oid   (microsoft_oid),
    KEY idx_users_microsoft_email (microsoft_email),
    CONSTRAINT fk_users_team FOREIGN KEY (default_team) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- locations
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id   INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- form_profiles
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_profiles (
    id           INT       NOT NULL AUTO_INCREMENT,
    team_id      INT       NOT NULL,
    name         VARCHAR(255) NOT NULL,
    bulk_entry   TINYINT(1)   NOT NULL DEFAULT 0,
    program_type ENUM('program','one_on_one','recurring') DEFAULT 'program',
    PRIMARY KEY (id),
    KEY idx_team (team_id),
    CONSTRAINT fk_form_profiles_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- scoring_questions
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS scoring_questions (
    id             INT          NOT NULL AUTO_INCREMENT,
    question_text  TEXT         NOT NULL,
    points         INT          NOT NULL DEFAULT 0,
    type           VARCHAR(50)  NOT NULL DEFAULT 'yesno',
    options        TEXT         DEFAULT NULL,
    sort_order     INT          DEFAULT 0,
    question_order INT          DEFAULT 0,
    question_type  VARCHAR(50)  DEFAULT 'binary',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- scoring_options
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS scoring_options (
    id           INT NOT NULL AUTO_INCREMENT,
    question_id  INT NOT NULL,
    option_text  VARCHAR(255) NOT NULL,
    option_points INT         NOT NULL DEFAULT 0,
    points       INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_question (question_id),
    CONSTRAINT fk_scoring_options_question FOREIGN KEY (question_id) REFERENCES scoring_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- form_questions  (junction: which questions belong to a form)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_questions (
    id          INT NOT NULL AUTO_INCREMENT,
    form_id     INT NOT NULL,
    question_id INT NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_form_question (form_id, question_id),
    KEY idx_form (form_id),
    KEY idx_question (question_id),
    CONSTRAINT fk_fq_form     FOREIGN KEY (form_id)     REFERENCES form_profiles     (id) ON DELETE CASCADE,
    CONSTRAINT fk_fq_question FOREIGN KEY (question_id) REFERENCES scoring_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- form_prefill_values  (default answers per form/question)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS form_prefill_values (
    id            INT NOT NULL AUTO_INCREMENT,
    form_id       INT NOT NULL,
    question_id   INT NOT NULL,
    prefill_value TEXT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_form_question (form_id, question_id),
    KEY idx_form (form_id),
    KEY idx_question (question_id),
    CONSTRAINT fk_fpv_form     FOREIGN KEY (form_id)     REFERENCES form_profiles     (id) ON DELETE CASCADE,
    CONSTRAINT fk_fpv_question FOREIGN KEY (question_id) REFERENCES scoring_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- scores  (one row per submission event)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS scores (
    id                    INT          NOT NULL AUTO_INCREMENT,
    user_id               INT          DEFAULT NULL,
    team                  VARCHAR(255) DEFAULT NULL,
    program               VARCHAR(255) NOT NULL,
    total_score           INT          NOT NULL DEFAULT 0,
    q4                    TINYINT      DEFAULT 0,
    q5                    TINYINT      DEFAULT 0,
    q6                    TINYINT      DEFAULT 0,
    q7                    TINYINT      DEFAULT 0,
    q8                    TINYINT      DEFAULT 0,
    q9                    TINYINT      DEFAULT 0,
    q10                   TINYINT      DEFAULT 0,
    q11                   TINYINT      DEFAULT 0,
    q12                   TINYINT      DEFAULT 0,
    q13                   TINYINT      DEFAULT 0,
    q14                   TINYINT      DEFAULT 0,
    q15                   TINYINT      DEFAULT 0,
    q16                   TINYINT      DEFAULT 0,
    q17                   TINYINT      DEFAULT 0,
    q18                   TINYINT      DEFAULT 0,
    q19                   TINYINT      DEFAULT 0,
    q20                   TINYINT      DEFAULT 0,
    q21                   TINYINT      DEFAULT 0,
    q22                   TINYINT      DEFAULT 0,
    q23                   TINYINT      DEFAULT 0,
    q24                   TINYINT      DEFAULT 0,
    q25                   TINYINT      DEFAULT 0,
    q26                   TINYINT      DEFAULT 0,
    q27                   TINYINT      DEFAULT 0,
    q28                   TINYINT      DEFAULT 0,
    q29                   TINYINT      DEFAULT 0,
    q30                   TINYINT      DEFAULT 0,
    submission_date       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    form_id               INT          DEFAULT NULL,
    program_date          DATE         DEFAULT NULL,
    attendance            INT          DEFAULT NULL,
    adjusted_impact_score FLOAT        DEFAULT 0,
    scaled_attendance     FLOAT        GENERATED ALWAYS AS (SQRT(attendance)) STORED,
    team_id               INT          DEFAULT NULL,
    location_id           INT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_team_id (team_id),
    CONSTRAINT fk_scores_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- score_responses  (one row per question answered per score)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS score_responses (
    id          INT NOT NULL AUTO_INCREMENT,
    score_id    INT DEFAULT NULL,
    question_id INT NOT NULL,
    response    VARCHAR(255) DEFAULT NULL,
    points      INT DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_score    (score_id),
    KEY idx_question (question_id),
    CONSTRAINT fk_score_responses_score    FOREIGN KEY (score_id)    REFERENCES scores            (id) ON DELETE CASCADE,
    CONSTRAINT fk_score_responses_question FOREIGN KEY (question_id) REFERENCES scoring_questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- score_users  (additional staff linked to a score)
--   Composite PK (no auto-increment), matches production
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS score_users (
    score_id INT NOT NULL,
    user_id  INT NOT NULL,
    role     ENUM('primary','support','staff','lead','facilitator','assistant') DEFAULT 'support',
    PRIMARY KEY (score_id, user_id),
    CONSTRAINT fk_score_users_score FOREIGN KEY (score_id) REFERENCES scores (id) ON DELETE CASCADE,
    CONSTRAINT fk_score_users_user  FOREIGN KEY (user_id)  REFERENCES users  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- admins  (admin login accounts)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id       INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- programming_reports  (saved report configs/layouts)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS programming_reports (
    id          INT  NOT NULL AUTO_INCREMENT,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    config_json LONGTEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dates (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- programs  (aggregated/denormalized program records)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS programs (
    id                    BIGINT       NOT NULL AUTO_INCREMENT,
    name                  VARCHAR(255) DEFAULT NULL,
    team                  VARCHAR(255) DEFAULT NULL,
    program_name          VARCHAR(255) DEFAULT NULL,
    total_score           INT          DEFAULT NULL,
    date                  DATE         DEFAULT NULL,
    attendance            INT          DEFAULT NULL,
    scaled_attendance     DECIMAL(10,4) DEFAULT NULL,
    adjusted_impact_score DECIMAL(10,4) DEFAULT NULL,
    created_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    program_type          ENUM('program','one_on_one','recurring') NOT NULL DEFAULT 'program',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- calendar_upload  (imported calendar events for comparison)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_upload (
    id               INT          NOT NULL AUTO_INCREMENT,
    date_relative    VARCHAR(50)  DEFAULT NULL,
    date             DATE         DEFAULT NULL,
    title            VARCHAR(255) DEFAULT NULL,
    creator          VARCHAR(255) DEFAULT NULL,
    total_attendance INT          DEFAULT NULL,
    attendance_notes TEXT         DEFAULT NULL,
    private_event    TINYINT(1)   DEFAULT 0,
    changed          VARCHAR(50)  DEFAULT NULL,
    status           VARCHAR(50)  DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- columns  (metadata for dynamically added score columns)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `columns` (
    id      INT          NOT NULL AUTO_INCREMENT,
    name    VARCHAR(255) NOT NULL,
    formula TEXT         DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Temporary working tables (used by admin merge/import tools)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS temp_merged_scores (
    program       VARCHAR(255) NOT NULL,
    attendance    INT          NOT NULL,
    impact_score  INT          NOT NULL,
    type          VARCHAR(100) NOT NULL,
    month         VARCHAR(50)  NOT NULL,
    creator       VARCHAR(255) NOT NULL,
    correct_date  VARCHAR(50)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS temp_program_dates (
    program      VARCHAR(255) DEFAULT NULL,
    creator      VARCHAR(255) DEFAULT NULL,
    correct_date VARCHAR(50)  DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS temp_program_scores (
    program       VARCHAR(255) NOT NULL,
    team          VARCHAR(255) NOT NULL,
    attendance    INT          NOT NULL,
    impact_score  INT          NOT NULL,
    user          VARCHAR(255) NOT NULL,
    date          DATE         NOT NULL,
    user_id       INT          DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Seed data
-- -------------------------------------------------------

-- Default teams (Youth Services + Adult Services match typical library setup)
INSERT IGNORE INTO teams (id, name) VALUES
    (1, 'Youth Services'),
    (2, 'Adult Services');

-- Default locations
INSERT IGNORE INTO locations (id, name) VALUES
    (1, 'Main Branch'),
    (2, 'North Branch'),
    (3, 'South Branch');


-- Default staff admin user (users table — for user_login.php)
-- Email: admin@example.com  |  Password: changeme  |  is_admin: 1
-- Change email and password immediately after first login.
INSERT IGNORE INTO users (name, email, password_hash, must_change_password, account_active, is_admin)
VALUES ('Admin', 'admin@example.com', '$2b$10$GH00nAJT7wuOOYnnrHAvN.T.81qHNlS4BW4yKH7J5uDYZL2RnqwVK', 1, 1, 1);

-- =========================================================
-- Performer Database Module
-- =========================================================

CREATE TABLE IF NOT EXISTS performers (
    performer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stage_name VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255) NULL,
    organization_name VARCHAR(255) NULL,
    performer_type VARCHAR(100) NULL,
    short_description VARCHAR(500) NULL,
    full_bio TEXT NULL,
    website_url VARCHAR(500) NULL,
    social_facebook VARCHAR(500) NULL,
    social_instagram VARCHAR(500) NULL,
    social_youtube VARCHAR(500) NULL,
    social_other VARCHAR(500) NULL,
    home_city VARCHAR(150) NULL,
    home_state VARCHAR(150) NULL,
    home_country VARCHAR(150) NULL DEFAULT 'USA',
    travel_radius_miles INT NULL,
    willing_to_travel TINYINT(1) NOT NULL DEFAULT 1,
    virtual_programs_available TINYINT(1) NOT NULL DEFAULT 0,
    insurance_on_file TINYINT(1) NOT NULL DEFAULT 0,
    w9_on_file TINYINT(1) NOT NULL DEFAULT 0,
    background_check_on_file TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive', 'pending', 'do_not_book') NOT NULL DEFAULT 'active',
    average_rating DECIMAL(3,2) NULL,
    total_reviews INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_contacts (
    contact_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    contact_name VARCHAR(255) NOT NULL,
    role VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    preferred_contact_method ENUM('email', 'phone', 'text', 'other') NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contacts_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_addresses (
    address_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    address_type ENUM('mailing', 'payment', 'business', 'other') NOT NULL DEFAULT 'mailing',
    address_line_1 VARCHAR(255) NOT NULL,
    address_line_2 VARCHAR(255) NULL,
    city VARCHAR(150) NOT NULL,
    state VARCHAR(150) NULL,
    postal_code VARCHAR(50) NULL,
    country VARCHAR(150) NOT NULL DEFAULT 'USA',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_addresses_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_programs (
    program_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    program_title VARCHAR(255) NOT NULL,
    program_description TEXT NULL,
    audience ENUM('early_learning', 'kids', 'teens', 'adults', 'all_ages') NOT NULL DEFAULT 'all_ages',
    program_format ENUM('performance', 'workshop', 'lecture', 'interactive', 'other') NOT NULL DEFAULT 'performance',
    duration_minutes INT UNSIGNED NULL,
    setup_time_minutes INT UNSIGNED NULL,
    breakdown_time_minutes INT UNSIGNED NULL,
    capacity_min INT UNSIGNED NULL,
    capacity_max INT UNSIGNED NULL,
    virtual_available TINYINT(1) NOT NULL DEFAULT 0,
    repeatable_same_day TINYINT(1) NOT NULL DEFAULT 0,
    base_fee DECIMAL(10,2) NULL,
    travel_fee DECIMAL(10,2) NULL,
    materials_fee DECIMAL(10,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_programs_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    tag_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_tag_map (
    performer_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (performer_id, tag_id),
    CONSTRAINT fk_tagmap_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_tagmap_tag FOREIGN KEY (tag_id) REFERENCES tags(tag_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS program_tag_map (
    program_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (program_id, tag_id),
    CONSTRAINT fk_program_tag_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE CASCADE,
    CONSTRAINT fk_program_tag_tag FOREIGN KEY (tag_id) REFERENCES tags(tag_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS program_requirements (
    requirement_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    needs_microphone TINYINT(1) NOT NULL DEFAULT 0,
    needs_sound_system TINYINT(1) NOT NULL DEFAULT 0,
    needs_projector TINYINT(1) NOT NULL DEFAULT 0,
    needs_screen TINYINT(1) NOT NULL DEFAULT 0,
    needs_tables TINYINT(1) NOT NULL DEFAULT 0,
    needs_chairs TINYINT(1) NOT NULL DEFAULT 0,
    needs_stage TINYINT(1) NOT NULL DEFAULT 0,
    outdoor_possible TINYINT(1) NOT NULL DEFAULT 0,
    weather_sensitive TINYINT(1) NOT NULL DEFAULT 0,
    power_requirements VARCHAR(255) NULL,
    library_must_provide TEXT NULL,
    performer_will_provide TEXT NULL,
    additional_requirements TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_requirements_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE CASCADE,
    CONSTRAINT uq_requirements_program UNIQUE (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_bookings (
    booking_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,
    event_title VARCHAR(255) NOT NULL,
    branch_name VARCHAR(255) NULL,
    room_name VARCHAR(255) NULL,
    event_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    attendance_count INT UNSIGNED NULL,
    target_audience VARCHAR(100) NULL,
    agreed_fee DECIMAL(10,2) NULL,
    travel_fee DECIMAL(10,2) NULL,
    materials_fee DECIMAL(10,2) NULL,
    total_cost DECIMAL(10,2) NULL,
    contract_sent_date DATE NULL,
    contract_signed_date DATE NULL,
    invoice_received_date DATE NULL,
    payment_sent_date DATE NULL,
    payment_cleared_date DATE NULL,
    booking_status ENUM('inquiry','tentative','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'inquiry',
    booked_by_staff_name VARCHAR(255) NULL,
    internal_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_reviews (
    review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,
    reviewer_name VARCHAR(255) NOT NULL,
    review_date DATE NOT NULL,
    rating_overall TINYINT UNSIGNED NULL,
    rating_professionalism TINYINT UNSIGNED NULL,
    rating_engagement TINYINT UNSIGNED NULL,
    rating_value TINYINT UNSIGNED NULL,
    rating_audience_response TINYINT UNSIGNED NULL,
    would_book_again TINYINT(1) NOT NULL DEFAULT 1,
    strengths TEXT NULL,
    concerns TEXT NULL,
    public_notes TEXT NULL,
    internal_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES performer_bookings(booking_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_notes (
    note_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,
    note_type ENUM('general', 'booking', 'payment', 'behavior', 'accessibility', 'other') NOT NULL DEFAULT 'general',
    visibility ENUM('internal', 'admin_only') NOT NULL DEFAULT 'internal',
    note_text TEXT NOT NULL,
    entered_by VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notes_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_booking FOREIGN KEY (booking_id) REFERENCES performer_bookings(booking_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_payment_profiles (
    payment_profile_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    payee_name VARCHAR(255) NOT NULL,
    payment_method ENUM('check', 'ach', 'direct_deposit', 'invoice', 'other') NOT NULL DEFAULT 'check',
    tax_id_last4 VARCHAR(4) NULL,
    remit_email VARCHAR(255) NULL,
    remit_phone VARCHAR(50) NULL,
    payment_terms VARCHAR(255) NULL,
    requires_po TINYINT(1) NOT NULL DEFAULT 0,
    requires_contract TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_profiles_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_files (
    file_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,
    file_type ENUM('photo','video','audio','contract','w9','insurance','promo','invoice','other') NOT NULL DEFAULT 'other',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    uploaded_by VARCHAR(255) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_files_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performer_blackout_dates (
    blackout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    blackout_start DATE NOT NULL,
    blackout_end DATE NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_blackout_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table: links Impact Score submissions to performers
CREATE TABLE IF NOT EXISTS impact_event_performers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    score_id INT NOT NULL,
    performer_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,
    fee_agreed DECIMAL(10,2) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_iep_score FOREIGN KEY (score_id) REFERENCES scores(id) ON DELETE CASCADE,
    CONSTRAINT fk_iep_performer FOREIGN KEY (performer_id) REFERENCES performers(performer_id) ON DELETE CASCADE,
    CONSTRAINT fk_iep_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performer program default scores (sticky question answers per program)
CREATE TABLE IF NOT EXISTS performer_program_default_scores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    form_profile_id INT UNSIGNED NOT NULL DEFAULT 0,
    question_id INT UNSIGNED NOT NULL,
    response_value INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_prog_form_question (program_id, form_profile_id, question_id),
    CONSTRAINT fk_ppds_program FOREIGN KEY (program_id) REFERENCES performer_programs(program_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Performer indexes
CREATE INDEX idx_performers_stage_name ON performers(stage_name);
CREATE INDEX idx_performers_status ON performers(status);
CREATE INDEX idx_performers_performer_type ON performers(performer_type);
CREATE INDEX idx_contacts_performer_id ON performer_contacts(performer_id);
CREATE INDEX idx_programs_performer_id ON performer_programs(performer_id);
CREATE INDEX idx_bookings_performer_id ON performer_bookings(performer_id);
CREATE INDEX idx_bookings_event_date ON performer_bookings(event_date);
CREATE INDEX idx_bookings_status ON performer_bookings(booking_status);
CREATE INDEX idx_reviews_performer_id ON performer_reviews(performer_id);
CREATE INDEX idx_files_performer_id ON performer_files(performer_id);
CREATE INDEX idx_iep_score_id ON impact_event_performers(score_id);
CREATE INDEX idx_iep_performer_id ON impact_event_performers(performer_id);

-- Starter tags
INSERT IGNORE INTO tags (tag_name) VALUES
('all ages'),('music'),('storytelling'),('author visit'),('stem'),
('workshop'),('bilingual'),('local artist'),('cultural program'),
('early learning'),('teens'),('adults'),('sensory-friendly'),
('summer reading'),('arts');
