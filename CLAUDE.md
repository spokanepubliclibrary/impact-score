# CLAUDE.md — Impact Score Dashboard

## Project Overview

PHP/MySQL web app for community engagement impact scoring. Staff submit scored evaluations; admins manage questions, users, and reports.

## Stack

- **PHP** with MySQLi (no ORM, no framework — plain procedural PHP)
- **MySQL** database
- **Bootstrap 5** + vanilla JS for the frontend
- Sessions for auth (separate admin session)

## Conventions

### Database
- All queries use **MySQLi prepared statements** with `bind_param` — never interpolate user input into SQL
- Connection is established by `require_once('secure/db_connection.php')` which defines `$servername`, `$username`, `$password`, `$database`; then `new mysqli(...)` is called inline
- `db_connect.php` in the root is an alternate helper that loads from `/var/www/secure/db_connection.php` (production path)
- In production, credentials live **outside** the web root at `/var/www/secure/db_connection.php`
- In development, credentials are at `secure/db_connection.php` (relative path)

### Auth
- Admin routes check `$_SESSION['admin_logged_in'] === true` at the top of every file; redirect to `admin_login.php` if not set
- No user-facing auth — the score submission form is open

### PHP Style
- Procedural, not OOP (except for the `mysqli` object itself)
- Error reporting enabled (`E_ALL`) with logging to `error_log.txt`
- Each file is self-contained — it connects to the DB, handles the request, and renders HTML

### Frontend
- Bootstrap 5.3 via CDN
- Chart.js for control charts
- html2canvas + jsPDF for PDF export of reports
- Font: Montserrat via Google Fonts
- AJAX calls use `fetch()` to `get_form_details.php` and `get_forms.php`

### Color palette (use these, don't introduce new ones)
- Deep Plum: `#480d3c` (primary buttons, headers)
- Fuchsia: `#bb1b51` (hover states)
- Parchment: `#f5f2ec` (page background)

## Key Files

| File | Role |
|---|---|
| `index.php` | Dashboard hub — entry point |
| `value_score_form.php` | Score submission (bulk + non-bulk); includes optional performer selection |
| `program.php` | Score submission handler; saves performer link to `impact_event_performers` |
| `view_scores.php` | Score list, search, export |
| `report.php` | Main programming report with SPC charts |
| `admin_portal.php` | Admin navigation hub |
| `admin_panel.php` | CRUD for scoring questions |
| `admin_forms.php` | CRUD for form profiles |
| `admin_users.php` | User/team management |
| `get_form_details.php` | AJAX endpoint — returns questions for a form |
| `get_performers.php` | AJAX endpoint — returns active performers as JSON |
| `api/scores.php` | REST API — score records |
| `db_connect.php` | DB connection helper (used by some files) |

## Performer Database Module

| File | Role |
|---|---|
| `performers/index.php` | Performer directory with search/filter |
| `performers/view.php` | Performer detail page with tabbed sections (Overview, Contacts, Programs, Bookings, Reviews, Notes, Files, Payment) |
| `performers/edit.php` | Add/edit performer form (admin only) |
| `performers/booking_list.php` | Booking list with date/status/branch filters |
| `performers/booking_edit.php` | Add/edit booking form (admin only) |
| `performers/booking_view.php` | Booking detail with milestones and review |
| `performers/review_edit.php` | Add/edit staff review form (admin only) |
| `performers/program_catalog.php` | Global searchable program catalog |
| `performers/ajax_programs.php` | AJAX: returns programs for a given performer |

### Performer Module Auth
- Directory, detail, catalog, booking_view: accessible to all (no login required)
- Edit, booking_edit, review_edit, and destructive actions: check `$_SESSION['admin_logged_in'] === true`
- Admin-only sections (Payment/Admin tab, delete buttons) check admin session inline

## Database Schema (key tables)

- `scores` — one row per submission: `user_id`, `team_id`, `program`, `program_date`, `total_score`, `attendance`, `scaled_attendance`, `adjusted_impact_score`
- `score_responses` — one row per question answer: `score_id`, `question_id`, `response_value`
- `scoring_questions` — `question_text`, `question_order`, `question_type`, `max_score`, `form_profile_id`
- `scoring_options` — multiple-choice options: `question_id`, `option_text`, `option_value`
- `form_profiles` — named form configurations linked to questions
- `users`, `teams`, `locations` — reference data

## Docker

| File | Purpose |
|---|---|
| `Dockerfile` | PHP 8.2 + Apache image; installs `mysqli`, copies app, injects `docker/db_connection.php` |
| `docker-compose.yml` | Two services: `app` (port 8080) and `db` (MySQL 8.0); named volumes for uploads and DB data |
| `init.sql` | Creates all tables and seeds a default admin (`admin` / `changeme`) |
| `docker/db_connection.php` | Reads DB credentials from env vars (`DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`) |

Run with: `docker compose up --build`

The real `secure/db_connection.php` has production credentials and should **never** be committed. The Dockerfile replaces it at build time with `docker/db_connection.php`.

## What to Watch Out For

- Several files have `copy`, `DRAFT`, or `copy 2` variants in the directory — these are old working copies. The canonical files have no suffix (e.g., `value_score_form.php`, not `value_score_form copy.php`)
- `error_log.txt` in the root is a live log — don't delete it
- The `uploads/` directory stores report images — must be web-server writable
- `backup/` contains DB dumps — don't commit sensitive data from there

## Notes
- Always update CLAUDE.md and README.md after every update

## Task instructions


# Performer Database Module

## Purpose

You are adding a new feature to this application: the Performer Database.

First, familiarize yourself with the application as it currently exists, paying special attention to the value_score_form.php form. Add a field to that form that allows for the optional selection of a performer from the Performer database.

system includes a **Performer Database module** used to manage individuals and organizations who deliver library programs (musicians, storytellers, authors, workshop leaders, educators, etc.).

This module supports:

* discovering performers
* managing performer profiles
* tracking program offerings
* booking performers for events
* recording staff reviews
* storing contracts and documents
* tracking payment/admin details
* linking performers to Impact Score events

The performer system should function as an **integrated module within the existing Impact Score application**, not as a separate application.

---

# Architecture Rules

## Same Database, Separate Module

The performer system **must use the existing Impact Score database**, but it should remain logically separate through its own table group.

Do NOT mix performer data into unrelated tables.

Use the performer-specific tables already defined in the schema:

* `performers`
* `performer_contacts`
* `performer_addresses`
* `performer_programs`
* `program_requirements`
* `tags`
* `performer_tag_map`
* `program_tag_map`
* `performer_bookings`
* `performer_reviews`
* `performer_notes`
* `performer_payment_profiles`
* `performer_files`
* `performer_blackout_dates`

These tables represent the full performer domain.

---

## Event Integration

Performers must be linkable to Impact Score events.

Do not modify core event structures unless necessary.

Instead, use a **junction table** if needed:

```
impact_event_performers
```

This allows events to have:

* one performer
* multiple performers
* performers plus partner organizations

---

# UI Design Philosophy

The UI should be built around **staff workflows**, not raw database tables.

Staff workflows include:

1. discovering performers
2. evaluating performers
3. booking performers
4. documenting contracts and payments
5. reviewing performance outcomes
6. reporting on program impact

Pages should combine related data instead of exposing individual tables directly.

Example:

Performer detail pages combine:

* performer profile
* contacts
* programs
* bookings
* reviews
* notes
* files
* payment summary

---

# Required Front-End Pages

## 1. Performer Directory

Primary search/browse page.

Features:

* search by performer name
* filters for performer type
* tag filtering
* audience filtering
* price range filtering
* status filtering

Display columns should include:

* name
* performer type
* location
* average rating
* number of bookings
* active status
* virtual availability

Provide quick actions:

* view performer
* add performer

---

## 2. Performer Detail Page

Central record for a performer.

Must include sections or tabs for:

* Overview
* Contacts
* Programs
* Booking History
* Reviews
* Notes
* Files
* Payment/Admin

The summary header should display:

* performer name
* organization
* location
* performer type
* tags
* average rating
* price range
* status indicators

Quick actions:

* edit performer
* add booking
* add review
* upload file
* add note

---

## 3. Add / Edit Performer

Form page for creating and editing performer records.

Fields include:

* name
* organization
* performer type
* bio
* website/social links
* travel details
* virtual availability
* tags
* documentation flags
* status

---

## 4. Contact Management

Each performer can have multiple contacts.

Support:

* booking contact
* performer
* manager
* agent

Allow marking one contact as primary.

This can exist as a tab within the performer detail page.

---

## 5. Program Management

Each performer may offer multiple programs.

Programs must include:

* title
* description
* audience
* format
* duration
* capacity
* fees
* setup requirements
* virtual availability

Programs appear both:

* inside the performer profile
* in a global program catalog page

---

## 6. Program Catalog

A global searchable list of programs across all performers.

Staff should be able to filter by:

* audience
* format
* tags
* performer
* price

This page helps programming staff identify potential programs quickly.

---

## 7. Booking Entry Page

Used to schedule performers for events.

Capture:

* performer
* program
* event title
* branch
* room
* date/time
* agreed fee
* travel/materials fee
* booking status
* staff contact
* notes

Bookings may optionally connect to Impact Score events.

---

## 8. Booking List / Calendar

Operational page for managing performer bookings.

Allow filtering by:

* date range
* branch
* performer
* booking status

Display upcoming events and recent bookings.

---

## 9. Booking Detail Page

Displays all information for a specific booking.

Include:

* performer
* program
* event details
* fees
* contract milestones
* notes
* files
* associated review

---

## 10. Review Entry Page

Staff evaluation form for completed events.

Capture:

* overall rating
* professionalism
* engagement
* value
* audience response
* would book again
* strengths
* concerns
* internal notes

Reviews must be stored separately from performer profiles.

---

## 11. Reviews Summary Page

Allows staff to compare performers.

Show:

* average rating
* review count
* most recent review
* booking frequency

---

## 12. Files / Document Management

Store files associated with performers and programs.

Supported file types include:

* contracts
* W-9 forms
* insurance certificates
* promo photos
* flyers
* invoices

Files should be accessible from performer and booking pages.

---

## 13. Notes System

Internal notes should be attachable to:

* performers
* bookings

Notes may include categories such as:

* general
* booking
* payment
* accessibility
* behavior

---

## 14. Payment / Administrative Information

Administrative tab within performer detail.

Contains:

* payee name
* payment method
* tax information indicators
* payment terms
* contract requirements

Access to this section should be restricted to authorized staff.

---

## 15. Availability / Blackout Dates

Performers may define blackout date ranges.

Used to prevent scheduling conflicts.

---

# Impact Score Integration

The Impact Score event page should include a **Performers section**.

This section must allow:

* linking performers to events
* selecting associated program
* recording fees
* linking reviews

This enables analysis of:

* performer impact
* cost per attendee
* program outcomes
* performer reuse across branches

---

# Development Guidance

Do not create a separate application.

This system should:

* reuse existing authentication
* reuse existing admin UI patterns
* live within the same navigation structure as Impact Score

However, the performer module should remain **logically isolated**, using its own tables and page group.

---

# Implementation Priority

Minimum viable implementation:

1. Performer Directory
2. Performer Detail
3. Add/Edit Performer
4. Program Management
5. Booking Entry
6. Booking List
7. Review Entry
8. File Upload
9. Event Performer Linking

Additional reporting and availability tools can be added later.

---

If you'd like, I can also give you a **second section for CLAUDE.md** that dramatically improves Claude's coding performance:

**"Performer Module Implementation Rules"**

This would tell Claude things like:

* which PHP patterns to follow
* how to structure queries
* how to handle file uploads
* how to enforce permission boundaries

Those instructions tend to **cut Claude coding errors by ~50%** in projects like yours.


## SQL

Here is the SQL script we'll be using to build the new tables:

-- =========================================================
-- Library Performer Database Schema
-- MySQL 8+
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- Drop tables in reverse dependency order
-- =========================================================

DROP TABLE IF EXISTS performer_blackout_dates;
DROP TABLE IF EXISTS performer_files;
DROP TABLE IF EXISTS performer_payment_profiles;
DROP TABLE IF EXISTS performer_notes;
DROP TABLE IF EXISTS performer_reviews;
DROP TABLE IF EXISTS performer_bookings;
DROP TABLE IF EXISTS program_requirements;
DROP TABLE IF EXISTS program_tag_map;
DROP TABLE IF EXISTS performer_tag_map;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS performer_programs;
DROP TABLE IF EXISTS performer_addresses;
DROP TABLE IF EXISTS performer_contacts;
DROP TABLE IF EXISTS performers;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- Core performer table
-- =========================================================

CREATE TABLE performers (
    performer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stage_name VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255) NULL,
    organization_name VARCHAR(255) NULL,
    performer_type VARCHAR(100) NULL COMMENT 'musician, storyteller, author, educator, etc.',
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

-- =========================================================
-- Contacts
-- =========================================================

CREATE TABLE performer_contacts (
    contact_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    contact_name VARCHAR(255) NOT NULL,
    role VARCHAR(100) NULL COMMENT 'performer, manager, booking contact, agent',
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    preferred_contact_method ENUM('email', 'phone', 'text', 'other') NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_contacts_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Addresses
-- =========================================================

CREATE TABLE performer_addresses (
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

    CONSTRAINT fk_addresses_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Programs
-- =========================================================

CREATE TABLE performer_programs (
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

    CONSTRAINT fk_programs_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_program_capacity
        CHECK (
            capacity_min IS NULL
            OR capacity_max IS NULL
            OR capacity_min <= capacity_max
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Tags
-- =========================================================

CREATE TABLE tags (
    tag_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE performer_tag_map (
    performer_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (performer_id, tag_id),

    CONSTRAINT fk_tagmap_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_tagmap_tag
        FOREIGN KEY (tag_id) REFERENCES tags(tag_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_tag_map (
    program_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (program_id, tag_id),

    CONSTRAINT fk_program_tag_program
        FOREIGN KEY (program_id) REFERENCES performer_programs(program_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_program_tag_tag
        FOREIGN KEY (tag_id) REFERENCES tags(tag_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Program requirements
-- =========================================================

CREATE TABLE program_requirements (
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

    CONSTRAINT fk_requirements_program
        FOREIGN KEY (program_id) REFERENCES performer_programs(program_id)
        ON DELETE CASCADE,

    CONSTRAINT uq_requirements_program UNIQUE (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Bookings / event history
-- =========================================================

CREATE TABLE performer_bookings (
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

    booking_status ENUM(
        'inquiry',
        'tentative',
        'confirmed',
        'completed',
        'cancelled',
        'no_show'
    ) NOT NULL DEFAULT 'inquiry',

    booked_by_staff_name VARCHAR(255) NULL,
    internal_notes TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_bookings_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_bookings_program
        FOREIGN KEY (program_id) REFERENCES performer_programs(program_id)
        ON DELETE SET NULL,

    CONSTRAINT chk_booking_times
        CHECK (
            start_time IS NULL
            OR end_time IS NULL
            OR start_time <= end_time
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Reviews
-- =========================================================

CREATE TABLE performer_reviews (
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

    CONSTRAINT fk_reviews_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_reviews_booking
        FOREIGN KEY (booking_id) REFERENCES performer_bookings(booking_id)
        ON DELETE SET NULL,

    CONSTRAINT chk_rating_overall
        CHECK (rating_overall BETWEEN 1 AND 5 OR rating_overall IS NULL),
    CONSTRAINT chk_rating_professionalism
        CHECK (rating_professionalism BETWEEN 1 AND 5 OR rating_professionalism IS NULL),
    CONSTRAINT chk_rating_engagement
        CHECK (rating_engagement BETWEEN 1 AND 5 OR rating_engagement IS NULL),
    CONSTRAINT chk_rating_value
        CHECK (rating_value BETWEEN 1 AND 5 OR rating_value IS NULL),
    CONSTRAINT chk_rating_audience_response
        CHECK (rating_audience_response BETWEEN 1 AND 5 OR rating_audience_response IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Internal notes
-- =========================================================

CREATE TABLE performer_notes (
    note_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,

    note_type ENUM('general', 'booking', 'payment', 'behavior', 'accessibility', 'other') NOT NULL DEFAULT 'general',
    visibility ENUM('internal', 'admin_only') NOT NULL DEFAULT 'internal',

    note_text TEXT NOT NULL,
    entered_by VARCHAR(255) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_notes_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_notes_booking
        FOREIGN KEY (booking_id) REFERENCES performer_bookings(booking_id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Payment profiles
-- =========================================================

CREATE TABLE performer_payment_profiles (
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

    CONSTRAINT fk_payment_profiles_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_tax_id_last4
        CHECK (tax_id_last4 IS NULL OR CHAR_LENGTH(tax_id_last4) = 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Files / uploads
-- =========================================================

CREATE TABLE performer_files (
    file_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NULL,

    file_type ENUM(
        'photo',
        'video',
        'audio',
        'contract',
        'w9',
        'insurance',
        'promo',
        'invoice',
        'other'
    ) NOT NULL DEFAULT 'other',

    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(1000) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size_bytes BIGINT UNSIGNED NULL,

    uploaded_by VARCHAR(255) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_files_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_files_program
        FOREIGN KEY (program_id) REFERENCES performer_programs(program_id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Blackout dates
-- =========================================================

CREATE TABLE performer_blackout_dates (
    blackout_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    performer_id INT UNSIGNED NOT NULL,
    blackout_start DATE NOT NULL,
    blackout_end DATE NOT NULL,
    reason VARCHAR(255) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_blackout_performer
        FOREIGN KEY (performer_id) REFERENCES performers(performer_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_blackout_dates
        CHECK (blackout_start <= blackout_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Indexes
-- =========================================================

CREATE INDEX idx_performers_stage_name ON performers(stage_name);
CREATE INDEX idx_performers_status ON performers(status);
CREATE INDEX idx_performers_performer_type ON performers(performer_type);

CREATE INDEX idx_contacts_performer_id ON performer_contacts(performer_id);
CREATE INDEX idx_contacts_email ON performer_contacts(email);
CREATE INDEX idx_contacts_is_primary ON performer_contacts(is_primary);

CREATE INDEX idx_addresses_performer_id ON performer_addresses(performer_id);
CREATE INDEX idx_addresses_is_primary ON performer_addresses(is_primary);

CREATE INDEX idx_programs_performer_id ON performer_programs(performer_id);
CREATE INDEX idx_programs_audience ON performer_programs(audience);
CREATE INDEX idx_programs_format ON performer_programs(program_format);
CREATE INDEX idx_programs_active ON performer_programs(active);
CREATE INDEX idx_programs_title ON performer_programs(program_title);

CREATE INDEX idx_tags_name ON tags(tag_name);

CREATE INDEX idx_bookings_performer_id ON performer_bookings(performer_id);
CREATE INDEX idx_bookings_program_id ON performer_bookings(program_id);
CREATE INDEX idx_bookings_event_date ON performer_bookings(event_date);
CREATE INDEX idx_bookings_status ON performer_bookings(booking_status);
CREATE INDEX idx_bookings_branch_name ON performer_bookings(branch_name);

CREATE INDEX idx_reviews_performer_id ON performer_reviews(performer_id);
CREATE INDEX idx_reviews_booking_id ON performer_reviews(booking_id);
CREATE INDEX idx_reviews_review_date ON performer_reviews(review_date);

CREATE INDEX idx_notes_performer_id ON performer_notes(performer_id);
CREATE INDEX idx_notes_booking_id ON performer_notes(booking_id);
CREATE INDEX idx_notes_type ON performer_notes(note_type);
CREATE INDEX idx_notes_visibility ON performer_notes(visibility);

CREATE INDEX idx_payment_profiles_performer_id ON performer_payment_profiles(performer_id);
CREATE INDEX idx_payment_profiles_active ON performer_payment_profiles(active);

CREATE INDEX idx_files_performer_id ON performer_files(performer_id);
CREATE INDEX idx_files_program_id ON performer_files(program_id);
CREATE INDEX idx_files_type ON performer_files(file_type);

CREATE INDEX idx_blackout_performer_id ON performer_blackout_dates(performer_id);
CREATE INDEX idx_blackout_start_end ON performer_blackout_dates(blackout_start, blackout_end);

-- =========================================================
-- Starter tags (optional seed data)
-- =========================================================

INSERT INTO tags (tag_name) VALUES
('all ages'),
('music'),
('storytelling'),
('author visit'),
('stem'),
('workshop'),
('bilingual'),
('local artist'),
('cultural program'),
('early learning'),
('teens'),
('adults'),
('sensory-friendly'),
('summer reading'),
('arts');

-- =========================================================
-- Helpful view: performer summary
-- =========================================================

CREATE OR REPLACE VIEW vw_performer_summary AS
SELECT
    p.performer_id,
    p.stage_name,
    p.legal_name,
    p.organization_name,
    p.performer_type,
    p.status,
    p.home_city,
    p.home_state,
    p.willing_to_travel,
    p.virtual_programs_available,
    p.average_rating,
    p.total_reviews,
    COUNT(DISTINCT pp.program_id) AS total_programs,
    COUNT(DISTINCT pb.booking_id) AS total_bookings
FROM performers p
LEFT JOIN performer_programs pp
    ON p.performer_id = pp.performer_id
LEFT JOIN performer_bookings pb
    ON p.performer_id = pb.performer_id
GROUP BY
    p.performer_id,
    p.stage_name,
    p.legal_name,
    p.organization_name,
    p.performer_type,
    p.status,
    p.home_city,
    p.home_state,
    p.willing_to_travel,
    p.virtual_programs_available,
    p.average_rating,
    p.total_reviews;

-- =========================================================
-- Helpful view: completed booking costs
-- =========================================================

CREATE OR REPLACE VIEW vw_completed_booking_costs AS
SELECT
    pb.booking_id,
    pb.performer_id,
    p.stage_name,
    pb.program_id,
    pp.program_title,
    pb.event_title,
    pb.branch_name,
    pb.event_date,
    pb.attendance_count,
    pb.agreed_fee,
    pb.travel_fee,
    pb.materials_fee,
    pb.total_cost,
    pb.booking_status
FROM performer_bookings pb
LEFT JOIN performers p
    ON pb.performer_id = p.performer_id
LEFT JOIN performer_programs pp
    ON pb.program_id = pp.program_id
WHERE pb.booking_status = 'completed';

-- =========================================================
-- Notes
-- =========================================================
-- Recommended next upgrades:
-- 1. Add a staff_users table and replace free-text names with foreign keys
-- 2. Add a branches table and reference branch_id instead of branch_name
-- 3. Add a payment_transactions table for real accounting workflow
-- 4. Add triggers to auto-update performers.average_rating and total_reviews

