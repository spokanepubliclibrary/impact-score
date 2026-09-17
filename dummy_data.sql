-- =============================================================
-- Impact Score Dashboard — Sample / Dummy Data
-- Compatible with init.sql schema
-- Run AFTER init.sql:  mysql -u root -p impact_score < dummy_data.sql
-- =============================================================

USE impact_score;

-- =============================================================
-- 0. CLEANUP  (safe to re-run — deletes in reverse FK order)
-- =============================================================

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE impact_event_performers;
TRUNCATE TABLE performer_blackout_dates;
TRUNCATE TABLE performer_files;
TRUNCATE TABLE performer_payment_profiles;
TRUNCATE TABLE performer_notes;
TRUNCATE TABLE performer_reviews;
TRUNCATE TABLE performer_bookings;
TRUNCATE TABLE program_requirements;
TRUNCATE TABLE program_tag_map;
TRUNCATE TABLE performer_tag_map;
TRUNCATE TABLE tags;
TRUNCATE TABLE performer_programs;
TRUNCATE TABLE performer_addresses;
TRUNCATE TABLE performer_contacts;
TRUNCATE TABLE performers;
TRUNCATE TABLE score_users;
TRUNCATE TABLE score_responses;
TRUNCATE TABLE scores;
TRUNCATE TABLE programs;
TRUNCATE TABLE form_questions;
TRUNCATE TABLE form_prefill_values;
TRUNCATE TABLE form_profiles;
TRUNCATE TABLE scoring_options;
TRUNCATE TABLE scoring_questions;
DELETE FROM users;
DELETE FROM locations WHERE id > 3;
DELETE FROM teams WHERE id > 2;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- 1. SUPPLEMENTAL TEAMS & LOCATIONS
-- =============================================================

INSERT IGNORE INTO teams (id, name) VALUES
(3, 'Teen Services'),
(4, 'Community Engagement'),
(5, 'Early Literacy');

INSERT IGNORE INTO locations (id, name) VALUES
(4, 'East Branch'),
(5, 'West Branch'),
(6, 'Downtown Branch');

-- =============================================================
-- 2. USERS  (10 staff members across teams)
-- =============================================================

-- Admin user first (id=1 reserved) — password: changeme, must change on login
INSERT INTO users (id, name, email, password_hash, must_change_password, account_active, is_admin) VALUES
(1, 'Admin', 'admin@example.com', '$2b$10$GH00nAJT7wuOOYnnrHAvN.T.81qHNlS4BW4yKH7J5uDYZL2RnqwVK', 1, 1, 1);

-- Sample staff users (no passwords — set via Admin → Manage Users)
INSERT INTO users (id, name, default_team, email, account_active) VALUES
(2,  'Maria Santos',      1, 'msantos@library.org',      1),
(3,  'James Wilson',      1, 'jwilson@library.org',       1),
(4,  'Sarah Chen',        2, 'schen@library.org',         1),
(5,  'David Park',        2, 'dpark@library.org',         1),
(6,  'Lisa Thompson',     3, 'lthompson@library.org',     1),
(7,  'Michael Rodriguez', 4, 'mrodriguez@library.org',    1),
(8,  'Emma Johnson',      5, 'ejohnson@library.org',      1),
(9,  'Robert Kim',        2, 'rkim@library.org',          1),
(10, 'Priya Nair',        5, 'pnair@library.org',         1),
(11, 'Carmen Ortiz',      4, 'cortiz@library.org',        1);

-- =============================================================
-- 3. FORM PROFILES
--    bulk_entry=1 → bulk submission form
-- =============================================================

INSERT INTO form_profiles (id, team_id, name, bulk_entry, program_type) VALUES
(1, 1, 'Youth Standard Program Evaluation', 0, 'program'),
(2, 1, 'Youth Bulk Program Entry',           1, 'program'),
(3, 2, 'Adult Program Evaluation',           0, 'program'),
(4, 2, 'Adult Bulk Entry',                   1, 'program'),
(5, 2, 'One-on-One Reference Services',      0, 'one_on_one'),
(6, 3, 'Teen Program Evaluation',            0, 'program'),
(7, 5, 'Early Literacy Assessment',          0, 'recurring');

-- =============================================================
-- 4. SCORING QUESTIONS  (15 questions, varied types)
-- =============================================================

INSERT INTO scoring_questions (id, question_text, points, type, question_order, question_type) VALUES
(1,  'Was the program promoted at least two weeks in advance?',              5,  'yesno', 1,  'binary'),
(2,  'Did the program achieve its stated learning objectives?',              10, 'yesno', 2,  'binary'),
(3,  'Did participants actively engage throughout the program?',             10, 'yesno', 3,  'binary'),
(4,  'Was the program accessible to all who wanted to attend?',              5,  'yesno', 4,  'binary'),
(5,  'Did the program serve an underserved or targeted population?',         15, 'yesno', 5,  'binary'),
(6,  'Was there a community partner or collaborator involved?',              10, 'yesno', 6,  'binary'),
(7,  'Did the program result in new library card sign-ups or referrals?',    10, 'yesno', 7,  'binary'),
(8,  'Was a professional performer, author, or presenter featured?',         5,  'yesno', 8,  'binary'),
(9,  'Did participants take home a resource, handout, or reading list?',     5,  'yesno', 9,  'binary'),
(10, 'How would you rate the overall community impact? (1–5)',               15, 'scale', 10, 'scale'),
(11, 'Was bilingual or multilingual content included?',                     10, 'yesno', 11, 'binary'),
(12, 'Did the program address digital literacy or technology skills?',       10, 'yesno', 12, 'binary'),
(13, 'Was this part of a planned series or recurring initiative?',           5,  'yesno', 13, 'binary'),
(14, 'Did attendance meet or exceed the registration target?',               5,  'yesno', 14, 'binary'),
(15, 'Would you recommend this program be offered again?',                   5,  'yesno', 15, 'binary');

-- Scoring options for Q10 (impact scale 1–5)
INSERT INTO scoring_options (question_id, option_text, option_points, points) VALUES
(10, '1 — Minimal impact',                3,  3),
(10, '2 — Some impact',                   6,  6),
(10, '3 — Moderate impact',               9,  9),
(10, '4 — Strong impact',                 12, 12),
(10, '5 — Exceptional community impact',  15, 15);

-- =============================================================
-- 5. FORM–QUESTION ASSIGNMENTS
-- =============================================================

-- Form 1: Youth Standard (all 15 questions)
INSERT INTO form_questions (form_id, question_id) VALUES
(1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),(1,8),(1,9),(1,10),(1,11),(1,12),(1,13),(1,14),(1,15);

-- Form 2: Youth Bulk (streamlined)
INSERT INTO form_questions (form_id, question_id) VALUES
(2,1),(2,2),(2,3),(2,5),(2,8),(2,14),(2,15);

-- Form 3: Adult Standard (no Q8, Q11)
INSERT INTO form_questions (form_id, question_id) VALUES
(3,1),(3,2),(3,3),(3,4),(3,5),(3,6),(3,7),(3,9),(3,10),(3,12),(3,13),(3,14),(3,15);

-- Form 4: Adult Bulk
INSERT INTO form_questions (form_id, question_id) VALUES
(4,1),(4,2),(4,5),(4,8),(4,14),(4,15);

-- Form 5: One-on-One Reference
INSERT INTO form_questions (form_id, question_id) VALUES
(5,2),(5,3),(5,4),(5,7),(5,9);

-- Form 6: Teen
INSERT INTO form_questions (form_id, question_id) VALUES
(6,1),(6,2),(6,3),(6,4),(6,5),(6,8),(6,9),(6,11),(6,12),(6,14),(6,15);

-- Form 7: Early Literacy
INSERT INTO form_questions (form_id, question_id) VALUES
(7,1),(7,2),(7,3),(7,4),(7,5),(7,9),(7,13),(7,14),(7,15);

-- =============================================================
-- 6. SCORES  (25 submissions, Jan–Apr 2026)
--    adjusted_impact_score = total_score * SQRT(attendance)
-- =============================================================

INSERT INTO scores
    (id, user_id, team, program, total_score, submission_date, form_id,
     program_date, attendance, adjusted_impact_score, team_id, location_id)
VALUES
-- January  (user_ids shifted +1 to accommodate admin at id=1)
(1,  2,  'Youth Services',       'Storytime with Maya Moonbeam',           64,  '2026-01-10 10:30:00', 1, '2026-01-08', 42,  414.9,  1, 1),
(2,  3,  'Youth Services',       'STEM Discovery Workshop',                105, '2026-01-17 14:15:00', 1, '2026-01-15', 28,  555.9,  1, 2),
(3,  4,  'Adult Services',       'Winter Reading Kickoff',                  82,  '2026-01-20 09:00:00', 3, '2026-01-18', 65,  661.1,  2, 1),
(4,  8,  'Early Literacy',       'Baby Rhyme Time',                         60,  '2026-01-23 11:00:00', 7, '2026-01-22', 18,  254.6,  5, 3),
(5,  6,  'Teen Services',        'Teen Game Night',                         65,  '2026-01-28 15:00:00', 6, '2026-01-25', 24,  318.2,  3, 2),
-- February
(6,  2,  'Youth Services',       'Chinese New Year Celebration',           115, '2026-02-05 10:00:00', 1, '2026-02-01', 87,  1072.5, 1, 1),
(7,  7,  'Community Engagement', 'Tax Help for Seniors',                    89,  '2026-02-08 13:00:00', 3, '2026-02-06', 32,  503.7,  4, 1),
(8,  5,  'Adult Services',       'Local Author Talk — Dr. Walsh',           70,  '2026-02-13 18:30:00', 3, '2026-02-12', 55,  519.2,  2, 6),
(9,  3,  'Youth Services',       'Valentines Craft Workshop',               35,  '2026-02-16 10:00:00', 2, '2026-02-14', 35,  207.2,  1, 2),
(10, 4,  'Adult Services',       'Seed Library Launch Event',               82,  '2026-02-19 10:00:00', 3, '2026-02-18', 48,  568.1,  2, 1),
(11, 10, 'Early Literacy',       'Bilingual Storytime — English/Spanish',   65,  '2026-02-22 10:30:00', 7, '2026-02-20', 22,  304.9,  5, 4),
(12, 9,  'Adult Services',       'Career Workshop: Resume Skills',          89,  '2026-02-27 14:00:00', 3, '2026-02-26', 20,  398.0,  2, 5),
-- March
(13, 2,  'Youth Services',       'Spring Reading Kickoff Party',            72,  '2026-03-05 10:00:00', 1, '2026-03-03', 72,  611.3,  1, 1),
(14, 6,  'Teen Services',        'Teen Art Showcase',                       65,  '2026-03-10 15:30:00', 6, '2026-03-08', 45,  435.9,  3, 6),
(15, 7,  'Community Engagement', 'Citizenship Prep Workshop',              115, '2026-03-14 10:00:00', 3, '2026-03-12', 30,  630.1,  4, 1),
(16, 8,  'Early Literacy',       'Toddler Movement with Rainbow Rhythms',   50,  '2026-03-17 11:00:00', 7, '2026-03-15', 25,  250.0,  5, 3),
(17, 5,  'Adult Services',       'Financial Literacy Series — Week 1',      87,  '2026-03-20 18:00:00', 3, '2026-03-19', 28,  460.3,  2, 6),
(18, 3,  'Youth Services',       'Science Squad: Slime Lab',                82,  '2026-03-24 10:00:00', 1, '2026-03-22', 38,  505.1,  1, 2),
(19, 11, 'Community Engagement', 'Job Fair Prep Workshop',                  94,  '2026-03-28 09:00:00', 3, '2026-03-27', 42,  609.1,  4, 1),
-- April
(20, 2,  'Youth Services',       'Earth Day STEM Fair',                    115, '2026-04-04 10:00:00', 1, '2026-04-02', 95,  1121.0, 1, 1),
(21, 6,  'Teen Services',        'Teen Open Mic Night',                     60,  '2026-04-07 16:00:00', 6, '2026-04-05', 30,  328.6,  3, 6),
(22, 10, 'Early Literacy',       'Baby Rhyme Time — April',                 60,  '2026-04-08 10:30:00', 7, '2026-04-07', 16,  240.0,  5, 4),
-- Bulk entries
(23, 3,  'Youth Services',       'Preschool Story Drop-In',                 30,  '2026-03-31 11:00:00', 2, '2026-03-29', 12,  103.9,  1, 3),
(24, 4,  'Adult Services',       'Tuesday Tech Help',                       25,  '2026-04-02 15:00:00', 4, '2026-04-01', 8,   70.7,   2, 1),
(25, 9,  'Adult Services',       'English Conversation Group',              40,  '2026-04-03 10:00:00', 4, '2026-04-02', 15,  154.9,  2, 5);

-- =============================================================
-- 7. SCORE RESPONSES  (one row per question per score)
-- =============================================================

-- Score 1: Form 1 (Q1–15)  Storytime with Maya Moonbeam  total=64
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(1,1,'yes',5),(1,2,'yes',10),(1,3,'yes',10),(1,4,'yes',5),(1,5,'no',0),
(1,6,'no',0),(1,7,'no',0),(1,8,'yes',5),(1,9,'yes',5),(1,10,'3',9),
(1,11,'no',0),(1,12,'no',0),(1,13,'yes',5),(1,14,'yes',5),(1,15,'yes',5);

-- Score 2: Form 1  STEM Discovery Workshop  total=105
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(2,1,'yes',5),(2,2,'yes',10),(2,3,'yes',10),(2,4,'yes',5),(2,5,'yes',15),
(2,6,'yes',10),(2,7,'yes',10),(2,8,'no',0),(2,9,'yes',5),(2,10,'5',15),
(2,11,'no',0),(2,12,'yes',10),(2,13,'no',0),(2,14,'yes',5),(2,15,'yes',5);

-- Score 3: Form 3 (Q1–7,9,10,12,13,14,15)  Winter Reading  total=82
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(3,1,'yes',5),(3,2,'yes',10),(3,3,'yes',10),(3,4,'yes',5),(3,5,'no',0),
(3,6,'yes',10),(3,7,'yes',10),(3,9,'yes',5),(3,10,'4',12),(3,12,'no',0),
(3,13,'yes',5),(3,14,'yes',5),(3,15,'yes',5);

-- Score 4: Form 7 (Q1–5,9,13,14,15)  Baby Rhyme Time  total=60
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(4,1,'yes',5),(4,2,'yes',10),(4,3,'yes',10),(4,4,'yes',5),(4,5,'yes',15),
(4,9,'yes',5),(4,13,'yes',5),(4,14,'no',0),(4,15,'yes',5);

-- Score 5: Form 6 (Q1–5,8,9,11,12,14,15)  Teen Game Night  total=65
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(5,1,'yes',5),(5,2,'yes',10),(5,3,'yes',10),(5,4,'yes',5),(5,5,'yes',15),
(5,8,'no',0),(5,9,'no',0),(5,11,'no',0),(5,12,'yes',10),(5,14,'yes',5),(5,15,'yes',5);

-- Score 6: Form 1  Chinese New Year Celebration  total=115
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(6,1,'yes',5),(6,2,'yes',10),(6,3,'yes',10),(6,4,'yes',5),(6,5,'yes',15),
(6,6,'yes',10),(6,7,'yes',10),(6,8,'yes',5),(6,9,'yes',5),(6,10,'5',15),
(6,11,'yes',10),(6,12,'no',0),(6,13,'no',0),(6,14,'yes',5),(6,15,'yes',5);

-- Score 7: Form 3  Tax Help for Seniors  total=89
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(7,1,'yes',5),(7,2,'yes',10),(7,3,'yes',10),(7,4,'yes',5),(7,5,'yes',15),
(7,6,'yes',10),(7,7,'yes',10),(7,9,'yes',5),(7,10,'3',9),(7,12,'no',0),
(7,13,'yes',5),(7,14,'no',0),(7,15,'yes',5);

-- Score 8: Form 3  Author Talk — Dr. Walsh  total=70
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(8,1,'yes',5),(8,2,'yes',10),(8,3,'yes',10),(8,4,'yes',5),(8,5,'no',0),
(8,6,'no',0),(8,7,'yes',10),(8,9,'yes',5),(8,10,'5',15),(8,12,'no',0),
(8,13,'no',0),(8,14,'yes',5),(8,15,'yes',5);

-- Score 9: Form 2 (Q1,2,3,5,8,14,15)  Valentines Craft  total=35
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(9,1,'yes',5),(9,2,'yes',10),(9,3,'yes',10),(9,5,'no',0),(9,8,'no',0),
(9,14,'yes',5),(9,15,'yes',5);

-- Score 10: Form 3  Seed Library Launch  total=82
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(10,1,'yes',5),(10,2,'yes',10),(10,3,'yes',10),(10,4,'yes',5),(10,5,'yes',15),
(10,6,'yes',10),(10,7,'no',0),(10,9,'yes',5),(10,10,'4',12),(10,12,'no',0),
(10,13,'no',0),(10,14,'yes',5),(10,15,'yes',5);

-- Score 11: Form 7  Bilingual Storytime  total=65
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(11,1,'yes',5),(11,2,'yes',10),(11,3,'yes',10),(11,4,'yes',5),(11,5,'yes',15),
(11,9,'yes',5),(11,13,'yes',5),(11,14,'yes',5),(11,15,'yes',5);

-- Score 12: Form 3  Resume Skills  total=89
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(12,1,'yes',5),(12,2,'yes',10),(12,3,'yes',10),(12,4,'yes',5),(12,5,'yes',15),
(12,6,'no',0),(12,7,'yes',10),(12,9,'yes',5),(12,10,'3',9),(12,12,'yes',10),
(12,13,'no',0),(12,14,'yes',5),(12,15,'yes',5);

-- Score 13: Form 1  Spring Reading Kickoff  total=72
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(13,1,'yes',5),(13,2,'yes',10),(13,3,'yes',10),(13,4,'yes',5),(13,5,'no',0),
(13,6,'yes',10),(13,7,'no',0),(13,8,'no',0),(13,9,'yes',5),(13,10,'4',12),
(13,11,'no',0),(13,12,'no',0),(13,13,'yes',5),(13,14,'yes',5),(13,15,'yes',5);

-- Score 14: Form 6  Teen Art Showcase  total=65
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(14,1,'yes',5),(14,2,'yes',10),(14,3,'yes',10),(14,4,'yes',5),(14,5,'yes',15),
(14,8,'yes',5),(14,9,'yes',5),(14,11,'no',0),(14,12,'no',0),(14,14,'yes',5),(14,15,'yes',5);

-- Score 15: Form 3  Citizenship Prep  total=115
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(15,1,'yes',5),(15,2,'yes',10),(15,3,'yes',10),(15,4,'yes',5),(15,5,'yes',15),
(15,6,'yes',10),(15,7,'yes',10),(15,9,'yes',5),(15,10,'5',15),(15,12,'yes',10),
(15,13,'yes',5),(15,14,'yes',5),(15,15,'yes',5);

-- Score 16: Form 7  Toddler Movement / Rainbow Rhythms  total=50
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(16,1,'yes',5),(16,2,'yes',10),(16,3,'yes',10),(16,4,'yes',5),(16,5,'no',0),
(16,9,'yes',5),(16,13,'yes',5),(16,14,'yes',5),(16,15,'yes',5);

-- Score 17: Form 3  Financial Literacy Week 1  total=87
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(17,1,'yes',5),(17,2,'yes',10),(17,3,'yes',10),(17,4,'yes',5),(17,5,'yes',15),
(17,6,'no',0),(17,7,'no',0),(17,9,'yes',5),(17,10,'4',12),(17,12,'yes',10),
(17,13,'yes',5),(17,14,'yes',5),(17,15,'yes',5);

-- Score 18: Form 1  Science Squad: Slime Lab  total=82
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(18,1,'yes',5),(18,2,'yes',10),(18,3,'yes',10),(18,4,'yes',5),(18,5,'yes',15),
(18,6,'no',0),(18,7,'no',0),(18,8,'yes',5),(18,9,'no',0),(18,10,'4',12),
(18,11,'no',0),(18,12,'yes',10),(18,13,'no',0),(18,14,'yes',5),(18,15,'yes',5);

-- Score 19: Form 3  Job Fair Prep  total=94
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(19,1,'yes',5),(19,2,'yes',10),(19,3,'yes',10),(19,4,'yes',5),(19,5,'yes',15),
(19,6,'yes',10),(19,7,'yes',10),(19,9,'yes',5),(19,10,'3',9),(19,12,'yes',10),
(19,13,'no',0),(19,14,'no',0),(19,15,'yes',5);

-- Score 20: Form 1  Earth Day STEM Fair  total=115
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(20,1,'yes',5),(20,2,'yes',10),(20,3,'yes',10),(20,4,'yes',5),(20,5,'yes',15),
(20,6,'yes',10),(20,7,'yes',10),(20,8,'no',0),(20,9,'yes',5),(20,10,'5',15),
(20,11,'no',0),(20,12,'yes',10),(20,13,'yes',5),(20,14,'yes',5),(20,15,'yes',5);

-- Score 21: Form 6  Teen Open Mic  total=60
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(21,1,'yes',5),(21,2,'yes',10),(21,3,'yes',10),(21,4,'yes',5),(21,5,'yes',15),
(21,8,'yes',5),(21,9,'no',0),(21,11,'no',0),(21,12,'no',0),(21,14,'yes',5),(21,15,'yes',5);

-- Score 22: Form 7  Baby Rhyme Time April  total=60
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(22,1,'yes',5),(22,2,'yes',10),(22,3,'yes',10),(22,4,'yes',5),(22,5,'yes',15),
(22,9,'yes',5),(22,13,'yes',5),(22,14,'no',0),(22,15,'yes',5);

-- Score 23: Form 2 (bulk)  Preschool Story Drop-In  total=30
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(23,1,'yes',5),(23,2,'yes',10),(23,3,'yes',10),(23,5,'no',0),(23,8,'no',0),
(23,14,'no',0),(23,15,'yes',5);

-- Score 24: Form 4 (adult bulk)  Tuesday Tech Help  total=25
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(24,1,'yes',5),(24,2,'yes',10),(24,5,'no',0),(24,8,'no',0),(24,14,'yes',5),(24,15,'yes',5);

-- Score 25: Form 4 (adult bulk)  English Conversation Group  total=40
INSERT INTO score_responses (score_id, question_id, response, points) VALUES
(25,1,'yes',5),(25,2,'yes',10),(25,5,'yes',15),(25,8,'no',0),(25,14,'yes',5),(25,15,'yes',5);

-- =============================================================
-- 8. SCORE USERS  (additional staff co-facilitating events)
-- =============================================================

INSERT INTO score_users (score_id, user_id, role) VALUES
(6,  10, 'support'),   -- Carmen Ortiz co-facilitated Chinese New Year
(2,  5,  'support'),   -- Lisa Thompson supported STEM Workshop
(20, 7,  'support'),   -- Emma Johnson supported Earth Day Fair
(15, 10, 'support'),   -- Carmen supported Citizenship Prep
(13, 2,  'support'),   -- James supported Spring Reading
(3,  8,  'support');   -- Robert Kim supported Winter Reading

-- =============================================================
-- 9. PROGRAMS TABLE  (aggregated/denormalized records)
-- =============================================================

INSERT INTO programs (name, team, program_name, total_score, date, attendance, scaled_attendance, adjusted_impact_score, program_type) VALUES
('Maria Santos',      'Youth Services',       'Storytime with Maya Moonbeam',         64,  '2026-01-08', 42, 6.4807, 414.9,  'program'),
('James Wilson',      'Youth Services',       'STEM Discovery Workshop',             105,  '2026-01-15', 28, 5.2915, 555.9,  'program'),
('Sarah Chen',        'Adult Services',       'Winter Reading Kickoff',               82,  '2026-01-18', 65, 8.0623, 661.1,  'program'),
('Emma Johnson',      'Early Literacy',       'Baby Rhyme Time',                      60,  '2026-01-22', 18, 4.2426, 254.6,  'recurring'),
('Lisa Thompson',     'Teen Services',        'Teen Game Night',                      65,  '2026-01-25', 24, 4.8990, 318.2,  'program'),
('Maria Santos',      'Youth Services',       'Chinese New Year Celebration',        115,  '2026-02-01', 87, 9.3274, 1072.5, 'program'),
('Michael Rodriguez', 'Community Engagement', 'Tax Help for Seniors',                 89,  '2026-02-06', 32, 5.6569, 503.7,  'program'),
('David Park',        'Adult Services',       'Local Author Talk — Dr. Walsh',        70,  '2026-02-12', 55, 7.4162, 519.2,  'program'),
('Sarah Chen',        'Adult Services',       'Seed Library Launch Event',            82,  '2026-02-18', 48, 6.9282, 568.1,  'program'),
('Priya Nair',        'Early Literacy',       'Bilingual Storytime — English/Spanish',65,  '2026-02-20', 22, 4.6904, 304.9,  'recurring'),
('Maria Santos',      'Youth Services',       'Spring Reading Kickoff Party',         72,  '2026-03-03', 72, 8.4853, 611.3,  'program'),
('Michael Rodriguez', 'Community Engagement', 'Citizenship Prep Workshop',           115,  '2026-03-12', 30, 5.4772, 630.1,  'program'),
('Maria Santos',      'Youth Services',       'Earth Day STEM Fair',                 115,  '2026-04-02', 95, 9.7468, 1121.0, 'program');

-- =============================================================
-- 10. PERFORMERS  (6 performers, varied types and statuses)
-- =============================================================

INSERT INTO performers
    (performer_id, stage_name, legal_name, organization_name, performer_type,
     short_description, full_bio,
     website_url, social_instagram, social_facebook, social_youtube,
     home_city, home_state, home_country, travel_radius_miles,
     willing_to_travel, virtual_programs_available,
     insurance_on_file, w9_on_file, background_check_on_file,
     status, average_rating, total_reviews)
VALUES
(1, 'Maya Moonbeam', 'Sarah Bennett', NULL, 'storyteller',
 'Award-winning storyteller specializing in folk tales and early literacy programs.',
 'Sarah Bennett, performing as Maya Moonbeam, has been captivating library audiences for over 12 years. She holds a Masters in Library Science and a certificate in Youth Theater Arts. Specializing in folk tales, bilingual storytelling, and early literacy programs, Maya has worked with 30+ libraries across the Pacific Northwest. Her programs consistently earn top engagement ratings and she is beloved by children and adults alike.',
 'https://www.mayamoonbeamstories.com', '@mayamoonbeam', 'Maya Moonbeam Storyteller', 'MayaMoonbeamStories',
 'Portland', 'OR', 'USA', 100, 1, 1, 1, 1, 1,
 'active', 4.83, 3),

(2, 'The Science Squad', 'STEM Educators LLC', 'STEM Educators LLC', 'educator',
 'High-energy STEM workshop team delivering hands-on science for kids and teens.',
 'The Science Squad is a team of former classroom science teachers who left education to bring interactive STEM programming to libraries, museums, and community centers. Their workshops emphasize curiosity, experimentation, and critical thinking. Programs range from beginner-friendly slime labs for early childhood to advanced robotics and coding workshops for teens. They have presented at over 200 library programs nationwide.',
 'https://www.sciencesquadprograms.com', '@sciencesquadprograms', 'Science Squad Programs', 'ScienceSquadPrograms',
 'Seattle', 'WA', 'USA', 150, 1, 1, 1, 1, 1,
 'active', 4.50, 2),

(3, 'Carlos Vega', 'Carlos Eduardo Vega', NULL, 'musician',
 'Bilingual musician and educator bringing Latin American rhythms and cultural storytelling to all ages.',
 'Carlos Vega is a classically trained guitarist and percussionist who grew up between Mexico City and San Francisco. He has performed internationally and has been sharing his love of Latin American music and culture with library patrons for 8 years. His programs are celebrated for their bilingual content and audience participation. Carlos is fluent in English and Spanish and incorporates folk instruments from across Latin America.',
 'https://www.carlosvegamusic.com', '@carlosvegamusic', 'Carlos Vega Music', NULL,
 'San Francisco', 'CA', 'USA', 75, 1, 1, 1, 1, 0,
 'active', 4.17, 2),

(4, 'Dr. Jennifer Walsh', 'Jennifer Walsh', NULL, 'author',
 'Award-winning YA and adult fiction author offering dynamic author talks and writing workshops.',
 'Dr. Jennifer Walsh is the author of seven novels for teens and adults, including the award-winning "The Cartography of Belonging" series. She holds a PhD in Creative Writing from the University of Michigan and teaches writing workshops nationally. Her library programs blend storytelling craft with personal narrative and have inspired countless teens to find their own writing voices. Dr. Walsh is warm, approachable, and adapts her presentation style to the audience.',
 'https://www.drjenniferwalsh.com', '@drjenwalshwrites', 'Dr. Jennifer Walsh Author', 'DrJenniferWalshAuthor',
 'Denver', 'CO', 'USA', 500, 1, 1, 1, 1, 1,
 'active', 4.67, 3),

(5, 'Rainbow Rhythms Dance Co.', NULL, 'Rainbow Arts LLC', 'musician',
 'Upbeat family dance and movement company bringing participatory musical programming to all ages.',
 'Rainbow Rhythms Dance Co. is a Portland-based arts collective that specializes in family-friendly dance and music programming. Their Move & Groove performances invite audience members of all ages to participate in structured dance activities using colorful props and original music. They have performed at libraries, festivals, and school events throughout Oregon and Washington.',
 'https://www.rainbowrhythmsco.com', '@rainbowrhythmsco', 'Rainbow Rhythms Dance Co.', NULL,
 'Portland', 'OR', 'USA', 80, 1, 0, 1, 1, 0,
 'active', 3.75, 2),

(6, 'Tech Explorers Workshop', NULL, 'Digital Skills Inc.', 'educator',
 'Digital literacy workshops for adults and seniors, covering smartphones, internet safety, and basic computing.',
 'Tech Explorers Workshop, operated by Digital Skills Inc., provides accessible technology education to adults and seniors who may have limited digital experience. Their workshops are patient, jargon-free, and designed to build real-world skills. Programs cover topics including smartphone basics, internet safety, email, online banking, and video calling. They are a new vendor currently completing our onboarding process.',
 'https://www.techexplorersworkshop.com', NULL, NULL, NULL,
 'Beaverton', 'OR', 'USA', 50, 1, 0, 0, 0, 0,
 'pending', NULL, 0);

-- =============================================================
-- 11. PERFORMER CONTACTS
-- =============================================================

INSERT INTO performer_contacts
    (performer_id, contact_name, role, email, phone, preferred_contact_method, is_primary, notes)
VALUES
-- Maya Moonbeam
(1, 'Sarah Bennett',  'performer',        'sarah@mayamoonbeamstories.com',   '503-555-0112', 'email', 1, 'Best reached Mon–Thu before noon'),
(1, 'Tom Bennett',    'manager',          'tom@mayamoonbeamstories.com',      '503-555-0113', 'email', 0, 'Handles contracts and scheduling on her behalf'),
-- Science Squad
(2, 'Janet Torres',   'booking contact',  'janet@sciencesquadprograms.com',  '206-555-0247', 'email', 1, 'Primary contact for all booking inquiries'),
(2, 'Marcus Hill',    'performer',        'marcus@sciencesquadprograms.com', '206-555-0248', 'phone', 0, 'Day-of logistics contact'),
-- Carlos Vega
(3, 'Carlos Vega',    'performer',        'carlos@carlosvegamusic.com',      '415-555-0391', 'email', 1, NULL),
-- Dr. Jennifer Walsh
(4, 'Melissa Grant',  'agent',            'melissa@grantliteraryagency.com', '720-555-0562', 'email', 1, 'All booking requests must go through Melissa'),
(4, 'Jennifer Walsh', 'performer',        'jen@drjenniferwalsh.com',         '720-555-0563', 'email', 0, 'Direct contact for day-of coordination only'),
-- Rainbow Rhythms
(5, 'Kelly Martinez', 'booking contact',  'kelly@rainbowrhythmsco.com',      '503-555-0778', 'text',  1, 'Prefers text for quick confirmations'),
-- Tech Explorers
(6, 'Brian Chen',     'booking contact',  'brian@techexplorersworkshop.com', '503-555-0934', 'email', 1, 'New contact — onboarding in progress');

-- =============================================================
-- 12. PERFORMER ADDRESSES
-- =============================================================

INSERT INTO performer_addresses
    (performer_id, address_type, address_line_1, city, state, postal_code, country, is_primary)
VALUES
(1, 'mailing',  '4521 NE Alberta St',     'Portland',     'OR', '97218', 'USA', 1),
(2, 'business', '1820 Eastlake Ave E',    'Seattle',      'WA', '98102', 'USA', 1),
(3, 'mailing',  '1047 Valencia St',       'San Francisco','CA', '94110', 'USA', 1),
(4, 'payment',  '2240 S University Blvd', 'Denver',       'CO', '80210', 'USA', 1),
(5, 'business', '812 N Beech St',         'Portland',     'OR', '97227', 'USA', 1),
(6, 'business', '350 SW 5th Ave Ste 200', 'Beaverton',    'OR', '97006', 'USA', 1);

-- =============================================================
-- 13. PERFORMER PROGRAMS
-- =============================================================

INSERT INTO performer_programs
    (program_id, performer_id, program_title, program_description,
     audience, program_format, duration_minutes, setup_time_minutes, breakdown_time_minutes,
     capacity_min, capacity_max, virtual_available, repeatable_same_day,
     base_fee, travel_fee, materials_fee, active)
VALUES
-- Maya Moonbeam (performer_id=1)
(1,  1, 'Story Magic for Little Ones',
 'An enchanting interactive storytime designed for babies through age 5. Maya uses puppets, felt boards, and call-and-response to bring folk tales to life. Perfect for early literacy programming and parent-child engagement.',
 'early_learning', 'performance', 45, 15, 10, 10, 60, 0, 1, 250.00, NULL, NULL, 1),

(2,  1, 'Folktales Around the World',
 'A spellbinding performance weaving folk tales from five continents, told with physical storytelling, musical instruments, and audience participation. Suitable for all ages and available virtually with a custom Zoom setup.',
 'all_ages', 'performance', 60, 15, 15, 20, 150, 1, 0, 350.00, 75.00, NULL, 1),

(3,  1, 'Crafts & Stories Workshop',
 'A hands-on workshop combining storytelling with arts and crafts. Children hear a short original story and then create a related craft to take home. Ideal for ages 4–10.',
 'kids', 'workshop', 75, 20, 20, 10, 30, 0, 0, 300.00, NULL, 50.00, 1),

-- Science Squad (performer_id=2)
(4,  2, 'Rocket Science for Kids',
 'Participants build and launch mini bottle rockets, learning about aerodynamics, propulsion, and the science of flight. Includes a Q&A with the Science Squad team. Best held outdoors or in a large open room.',
 'kids', 'workshop', 60, 30, 20, 10, 40, 0, 0, 400.00, 50.00, NULL, 1),

(5,  2, 'Coding for Teens',
 'An introduction to block-based and text-based coding using Scratch and Python. Teens work in pairs to complete mini projects. Laptops or tablets required (library-provided acceptable).',
 'teens', 'interactive', 90, 15, 15, 8, 24, 1, 0, 450.00, NULL, 25.00, 1),

(6,  2, 'Science Explorers (Early Learning)',
 'A sensory-safe, gentle science program for children ages 2–5 and their caregivers. Features color mixing, bubble science, and magnet exploration. No mess — all materials contained.',
 'early_learning', 'interactive', 45, 10, 10, 5, 25, 0, 1, 350.00, NULL, NULL, 1),

-- Carlos Vega (performer_id=3)
(7,  3, 'Rhythms of Latin America',
 'Carlos performs original music and traditional songs from Mexico, Brazil, Cuba, and Argentina, inviting the audience to sing, clap, and play simple percussion instruments. Bilingual (English/Spanish) throughout. Ideal for cultural programming and summer reading.',
 'all_ages', 'performance', 60, 20, 20, NULL, 200, 1, 0, 500.00, 100.00, NULL, 1),

(8,  3, 'Guitar Workshop for Teens',
 'Teens learn the basics of guitar: tuning, chord shapes, strumming patterns, and their first song. Carlos provides instruction in English and Spanish. Students do not need to bring instruments — Carlos brings 6 loaner guitars.',
 'teens', 'workshop', 75, 15, 15, 4, 12, 0, 0, 350.00, NULL, NULL, 1),

-- Dr. Jennifer Walsh (performer_id=4)
(9,  4, 'Author Talk & Q&A',
 'Dr. Walsh discusses her writing journey, the themes behind her novels, and how readers can find their own stories. Includes a Q&A and optional book signing. Works best with a projector for slides. Books available for purchase separately.',
 'adults', 'lecture', 90, 15, 10, NULL, 150, 1, 0, 600.00, 150.00, NULL, 1),

(10, 4, 'Teen Writing Workshop',
 'A workshop that guides teen writers through character development, scene construction, and finding their voice. Participants leave with a completed short story opening. Materials provided.',
 'teens', 'workshop', 120, 10, 10, 8, 20, 1, 0, 500.00, NULL, 30.00, 1),

(11, 4, 'Picture Book Presentation',
 'Dr. Walsh shares her picture book titles and the process of writing for young children. Followed by a reading and storytime activity for children ages 3–8.',
 'early_learning', 'lecture', 30, 10, 5, NULL, 80, 0, 0, 300.00, 150.00, NULL, 1),

-- Rainbow Rhythms (performer_id=5)
(12, 5, 'Move & Groove Family Dance',
 'A high-energy family dance program using colorful scarves, rhythm sticks, and original songs. Everyone participates! Rainbow Rhythms brings all props and a sound system. Works best with open floor space.',
 'all_ages', 'performance', 45, 20, 20, NULL, 100, 0, 1, 400.00, NULL, NULL, 1),

-- Tech Explorers (performer_id=6)
(13, 6, 'Digital Literacy for Seniors',
 'A patient, step-by-step workshop for adults 60+ covering smartphone basics, internet safety, email setup, and video calling with family. Participants follow along on library devices or bring their own. Maximum 12 participants for individualized attention.',
 'adults', 'workshop', 90, 15, 10, 4, 12, 0, 0, 300.00, NULL, 25.00, 1);

-- =============================================================
-- 14. PROGRAM REQUIREMENTS
-- =============================================================

INSERT INTO program_requirements
    (program_id, needs_microphone, needs_sound_system, needs_projector, needs_screen,
     needs_tables, needs_chairs, needs_stage, outdoor_possible, weather_sensitive,
     power_requirements, library_must_provide, performer_will_provide, additional_requirements)
VALUES
-- Story Magic (program_id=1): minimal needs
(1, 0, 0, 0, 0, 0, 1, 0, 0, 0,
 NULL, 'Open floor space for children to sit; chairs for caregivers', 'All puppets, felt board, and props', NULL),

-- Folktales Around the World (program_id=2): mic + chairs
(2, 1, 1, 0, 0, 0, 1, 0, 0, 0,
 '2 standard 3-prong outlets', 'PA system if available; row seating or open floor', 'Instruments and props', NULL),

-- Crafts & Stories (program_id=3): tables needed
(3, 0, 0, 0, 0, 1, 1, 0, 0, 0,
 NULL, 'Tables with wipeable surfaces; trash bags for cleanup', 'All craft materials and story supplies', 'Allow 10 minutes post-program for cleanup'),

-- Rocket Science (program_id=4): outdoor preferred
(4, 1, 0, 0, 0, 1, 0, 0, 1, 1,
 '2 outlets for pump inflators', 'Outdoor space or large room with 20ft ceiling clearance; 4 tables', 'All materials and safety goggles', 'Outdoor launch requires permission from facilities'),

-- Coding for Teens (program_id=5): tech-heavy
(5, 0, 0, 1, 1, 1, 1, 0, 0, 0,
 '8 outlets or power strips', 'Laptops or tablets (1 per 2 teens); WiFi; projector and screen',
 'USB drives with starter code; handouts', 'WiFi must support simultaneous login for all participants'),

-- Rhythms of Latin America (program_id=7): sound + open floor
(7, 1, 1, 0, 0, 0, 1, 0, 1, 0,
 '4 outlets for amplifier', 'Seating along walls; open center floor space', 'Full PA system, instruments, loaner percussion', 'Carlos prefers to arrive 45 min early for soundcheck'),

-- Author Talk (program_id=9): projector for slides
(9, 1, 0, 1, 1, 0, 1, 0, 0, 0,
 '2 outlets', 'Projector, screen, and podium microphone; row seating', 'Laptop with presentation slides', 'Arrange with local bookstore for book sales table if possible'),

-- Teen Writing Workshop (program_id=10): tables + supplies
(10, 0, 0, 1, 1, 1, 1, 0, 0, 0,
 '2 outlets', 'Tables and chairs for 20; projector', 'Writing guides, notebooks, pens', NULL),

-- Move & Groove (program_id=12): open floor, sound
(12, 1, 1, 0, 0, 0, 0, 0, 0, 0,
 '4 outlets for PA and lights', 'Completely open floor space — no chairs; ceiling height min 10 ft',
 'Full PA system, colorful scarves, rhythm sticks, all props', 'Ensure floor is clear at least 15 min before start time'),

-- Digital Literacy (program_id=13): tech + small group
(13, 0, 0, 1, 1, 1, 1, 0, 0, 0,
 '12 outlets or power strips for devices', 'Library devices (tablets or laptops) for participants; projector and screen',
 'Printed step-by-step guides', 'WiFi required; recommend small meeting room for quiet environment');

-- =============================================================
-- 15. TAGS  (seeded here with explicit IDs so script is self-contained)
-- =============================================================

INSERT INTO tags (tag_id, tag_name) VALUES
(1,  'all ages'),
(2,  'music'),
(3,  'storytelling'),
(4,  'author visit'),
(5,  'stem'),
(6,  'workshop'),
(7,  'bilingual'),
(8,  'local artist'),
(9,  'cultural program'),
(10, 'early learning'),
(11, 'teens'),
(12, 'adults'),
(13, 'sensory-friendly'),
(14, 'summer reading'),
(15, 'arts');

-- Performer tags  (tag IDs: 1=all ages, 2=music, 3=storytelling,
-- 4=author visit, 5=stem, 6=workshop, 7=bilingual, 8=local artist, 9=cultural program,
-- 10=early learning, 11=teens, 12=adults, 13=sensory-friendly, 14=summer reading, 15=arts)

INSERT INTO performer_tag_map (performer_id, tag_id) VALUES
(1, 3),(1, 10),(1, 1),(1, 8),(1, 14),   -- Maya: storytelling, early learning, all ages, local artist, summer reading
(2, 5),(2, 6),(2, 11),(2, 10),           -- Science Squad: stem, workshop, teens, early learning
(3, 2),(3, 7),(3, 9),(3, 8),(3, 1),     -- Carlos: music, bilingual, cultural, local artist, all ages
(4, 4),(4, 11),(4, 12),                  -- Dr. Walsh: author visit, teens, adults
(5, 2),(5, 1),(5, 15),(5, 8),           -- Rainbow Rhythms: music, all ages, arts, local artist
(6, 5),(6, 6),(6, 12);                   -- Tech Explorers: stem, workshop, adults

-- Program tags
INSERT INTO program_tag_map (program_id, tag_id) VALUES
(1,  3),(1,  10),(1,  1),               -- Story Magic: storytelling, early learning, all ages
(2,  3),(2,  1),(2,  9),(2,  14),       -- Folktales: storytelling, all ages, cultural, summer reading
(3,  3),(3,  10),(3,  15),              -- Crafts & Stories: storytelling, early learning, arts
(4,  5),(4,  6),(4,  14),              -- Rocket Science: stem, workshop, summer reading
(5,  5),(5,  6),(5,  11),              -- Coding: stem, workshop, teens
(6,  5),(6,  10),(6,  13),             -- Science Explorers: stem, early learning, sensory-friendly
(7,  2),(7,  7),(7,  9),(7,  1),(7, 14),-- Rhythms: music, bilingual, cultural, all ages, summer reading
(8,  2),(8,  6),(8,  11),              -- Guitar Workshop: music, workshop, teens
(9,  4),(9,  12),                       -- Author Talk: author visit, adults
(10, 4),(10, 6),(10, 11),              -- Teen Writing: author visit, workshop, teens
(11, 3),(11, 10),                       -- Picture Book: storytelling, early learning
(12, 2),(12, 1),(12, 15),(12, 14),     -- Move & Groove: music, all ages, arts, summer reading
(13, 5),(13, 6),(13, 12);              -- Digital Literacy: stem, workshop, adults

-- =============================================================
-- 16. PERFORMER BOOKINGS
-- =============================================================

INSERT INTO performer_bookings
    (booking_id, performer_id, program_id, event_title, branch_name, room_name,
     event_date, start_time, end_time, attendance_count, target_audience,
     agreed_fee, travel_fee, materials_fee, total_cost,
     contract_sent_date, contract_signed_date, invoice_received_date, payment_sent_date, payment_cleared_date,
     booking_status, booked_by_staff_name, internal_notes)
VALUES
-- Maya Moonbeam bookings
(1, 1, 1, 'Storytime with Maya Moonbeam',          'Main Branch',     'Community Room A', '2026-01-08', '10:00:00', '11:00:00', 42,  'Families with children 0-5',  250.00, NULL,  NULL,  250.00, '2025-12-01', '2025-12-10', '2026-01-10', '2026-01-20', '2026-01-30', 'completed', 'Maria Santos',      'Fantastic event. Kids loved the puppets.'),
(2, 1, 2, 'Folktales Around the World — Winter',   'North Branch',    'Meeting Room B',   '2025-11-15', '14:00:00', '15:15:00', 58,  'All ages',                    350.00, 75.00, NULL,  425.00, '2025-10-01', '2025-10-08', '2025-11-17', '2025-11-28', '2025-12-05', 'completed', 'James Wilson',      'Exceeded target attendance. Will rebook for summer.'),
(3, 1, 3, 'Crafts & Stories — Fall Workshop',      'South Branch',    'Children''s Area', '2025-10-04', '10:00:00', '11:30:00', 22,  'Kids ages 4-10',              300.00, NULL,  50.00, 350.00, '2025-09-01', '2025-09-07', '2025-10-06', '2025-10-17', '2025-10-25', 'completed', 'Emma Johnson',      'Great energy. Clean craft — minimal mess.'),
(4, 1, 1, 'Spring Story Magic',                    'Downtown Branch', 'Program Room',     '2026-05-12', '10:00:00', '11:00:00', NULL,'Families with toddlers',      250.00, NULL,  NULL,  250.00, '2026-03-10', '2026-03-18', NULL,         NULL,         NULL,         'confirmed', 'Maria Santos',      'Contract signed. Reminder to print programs.'),

-- Science Squad bookings
(5, 2, 4, 'STEM Discovery — Rocket Science',       'Main Branch',     'Parking Lot',      '2026-01-15', '10:00:00', '11:30:00', 28,  'Kids ages 6-12',              400.00, 50.00, NULL,  450.00, '2025-12-10', '2025-12-15', '2026-01-17', '2026-01-28', '2026-02-05', 'completed', 'James Wilson',      'Held outdoors — weather cooperated perfectly.'),
(6, 2, 4, 'Science Squad: Slime Lab',              'North Branch',    'Community Room',   '2026-03-22', '10:00:00', '11:30:00', 38,  'Kids ages 5-12',              400.00, 50.00, NULL,  450.00, '2026-02-01', '2026-02-08', '2026-03-24', '2026-04-03', '2026-04-10', 'completed', 'James Wilson',      'Standing room only. Book earlier next time.'),
(7, 2, 5, 'Coding for Teens — Summer Session',     'East Branch',     'Tech Lab',         '2026-06-10', '14:00:00', '16:00:00', NULL,'Teens 12-18',                 450.00, NULL,  25.00, 475.00, '2026-03-15', '2026-03-22', NULL,         NULL,         NULL,         'confirmed', 'Lisa Thompson',     'Confirmed for Summer Reading. Check WiFi capacity.'),

-- Carlos Vega bookings
(8, 3, 7, 'Rhythms of Latin America — Lunar New Year', 'Main Branch', 'Auditorium',       '2026-02-01', '13:00:00', '14:15:00', 87,  'All ages',                    500.00, 100.00,NULL,  600.00, '2025-12-15', '2025-12-20', '2026-02-03', '2026-02-14', '2026-02-21', 'completed', 'Carmen Ortiz',      'One of our highest attendance programs this year.'),
(9, 3, 7, 'Rhythms of Latin America — Earth Day',  'South Branch',    'Community Room',   '2026-04-20', '11:00:00', '12:15:00', NULL,'All ages',                    500.00, 100.00,NULL,  600.00, '2026-03-01', NULL,         NULL,         NULL,         NULL,         'tentative', 'Carmen Ortiz',      'Awaiting signed contract. Follow up with Melissa.'),

-- Dr. Jennifer Walsh bookings
(10, 4, 9, 'Author Talk — Dr. Jennifer Walsh',      'Downtown Branch', 'Event Hall',       '2026-02-12', '18:30:00', '20:00:00', 55,  'Adults',                      600.00, 150.00,NULL,  750.00, '2025-12-01', '2025-12-09', '2026-02-14', '2026-02-25', '2026-03-05', 'completed', 'David Park',        'Bookstore partnership was a success — sold 34 copies.'),
(11, 4,10, 'Teen Writing Workshop',                 'North Branch',    'Meeting Room B',   '2025-10-18', '10:00:00', '12:30:00', 16,  'Teens 13-18',                 500.00, NULL,  30.00, 530.00, '2025-09-01', '2025-09-09', '2025-10-20', '2025-11-01', '2025-11-08', 'completed', 'Lisa Thompson',     'Teens stayed 20 min extra — very engaged.'),
(12, 4, 9, 'Author Talk — Spring Series',           'Main Branch',     'Community Room A', '2026-05-05', '19:00:00', '20:30:00', NULL,'Adults',                      600.00, 150.00,NULL,  750.00, '2026-03-01', '2026-03-10', NULL,         NULL,         NULL,         'confirmed', 'David Park',        'Coordinate with bookstore at least 3 weeks out.'),

-- Rainbow Rhythms bookings
(13, 5,12, 'Toddler Movement — Rainbow Rhythms',    'South Branch',    'Story Corner',     '2026-03-15', '10:30:00', '11:15:00', 25,  'Families 0-5',                400.00, NULL,  NULL,  400.00, '2026-01-15', '2026-01-22', '2026-03-17', '2026-03-27', '2026-04-04', 'completed', 'Emma Johnson',      'Fun program. Arrived 15 min late — flagged in notes.'),
(14, 5,12, 'Move & Groove — Fall Family Festival',  'Main Branch',     'Parking Lot',      '2025-09-14', '11:00:00', '11:50:00', 72,  'All ages',                    400.00, NULL,  NULL,  400.00, '2025-07-15', '2025-07-22', '2025-09-16', '2025-09-26', '2025-10-03', 'completed', 'Maria Santos',      'Sound equipment arrived late. Resolved on-site.'),

-- Tech Explorers booking
(15, 6,13, 'Digital Literacy for Seniors',          'Main Branch',     'Meeting Room A',   '2026-05-20', '14:00:00', '15:45:00', NULL,'Adults 60+',                  300.00, NULL,  25.00, 325.00, NULL,         NULL,         NULL,         NULL,         NULL,         'tentative', 'Sarah Chen',        'New vendor — W-9 and insurance must be on file before confirming.');

-- =============================================================
-- 17. PERFORMER REVIEWS  (with varied ratings, linked to bookings)
-- =============================================================

INSERT INTO performer_reviews
    (review_id, performer_id, booking_id, reviewer_name, review_date,
     rating_overall, rating_professionalism, rating_engagement, rating_value, rating_audience_response,
     would_book_again, strengths, concerns, public_notes, internal_notes)
VALUES
-- Maya Moonbeam — 3 reviews
(1, 1, 1, 'Maria Santos',  '2026-01-10',
 5, 5, 5, 5, 5, 1,
 'Maya had every child completely captivated. Her puppet work was extraordinary and she adapted the pace beautifully when younger babies got fussy.',
 NULL,
 'An exceptional storyteller who brings magic into the room. Highly recommended for early literacy programs.',
 'Arrived 20 minutes early and set up independently. No issues whatsoever.'),

(2, 1, 2, 'James Wilson',  '2025-11-16',
 5, 5, 5, 4, 5, 1,
 'Beautiful program. The stories she chose were perfectly balanced — funny and moving. Audience of all ages was engaged.',
 'Travel fee is on the higher side given our budget.',
 'A world-class storyteller. We were honored to have Maya at our branch.',
 'James recommends building the travel fee into the annual budget going forward.'),

(3, 1, 3, 'Emma Johnson',  '2025-10-05',
 5, 5, 4, 5, 5, 1,
 'The craft-and-story combo was creative and well-executed. Kids left proud of what they made.',
 'Craft took slightly longer than anticipated — ran 10 minutes over.',
 'A wonderful hands-on program that kept every child engaged from start to finish.',
 'Recommend budgeting 90 min instead of 75 for the workshop format.'),

-- Science Squad — 2 reviews
(4, 2, 5, 'James Wilson',  '2026-01-17',
 5, 5, 5, 5, 5, 1,
 'Incredible energy. The team was professional, safe, and deeply knowledgeable. Kids were asking science questions for days after.',
 NULL,
 'Hands-on STEM at its finest. The Science Squad delivered a flawless rocket launch event.',
 'Outdoor setup went smoothly. Recommend this time of year for the outdoor format.'),

(5, 2, 6, 'James Wilson',  '2026-03-24',
 4, 5, 4, 4, 4, 1,
 'Solid program. Slime lab was a massive hit with all age groups.',
 'Room was at capacity before start time — need to limit registration or book a larger space.',
 'High-energy, hands-on science fun. A perennial crowd-pleaser.',
 'Waitlist formed at the door. Cap registration at 30 next time.'),

-- Carlos Vega — 2 reviews
(6, 3, 8, 'Carmen Ortiz',  '2026-02-03',
 4, 4, 5, 4, 5, 1,
 'Carlos was magnetic on stage. The bilingual programming was exactly what our community needed. Audience participation was outstanding.',
 'Sound setup took longer than expected and started 10 minutes late.',
 'A vibrant, bilingual musical experience that connected our diverse community. Highly recommended.',
 'Soundcheck ran long — remind Carlos to arrive at least 45 min before start as specified in contract.'),

(7, 3,14, 'Maria Santos',  '2025-11-01',
 4, 4, 4, 4, 4, 1,
 'Good energy. Music selections were varied and appropriate for all ages.',
 NULL,
 'A fun musical performance that got everyone dancing.',
 'This was a different booking before we had Carlos — note this review is for a past engagement.'),

-- Dr. Jennifer Walsh — 3 reviews
(8, 4,10, 'Lisa Thompson', '2025-10-20',
 5, 5, 5, 5, 5, 1,
 'Dr. Walsh connected instantly with the teens. She was funny, honest, and inspiring. Several teens told me it was the best library program they had attended.',
 NULL,
 'A transformative experience for teen writers. Dr. Walsh''s generosity with her craft is unmatched.',
 'Lisa requests Dr. Walsh for the spring writing series as well.'),

(9, 4,11, 'David Park',    '2026-02-14',
 5, 5, 5, 5, 4, 1,
 'Dr. Walsh was wonderfully engaging with our adult audience. Her talk was intelligent and accessible, and the Q&A ran long because patrons had so much to ask.',
 'The book signing line ran longer than anticipated — plan extra time.',
 'A packed house, a lively Q&A, and enthusiastic book sales. A standout evening.',
 'Coordinate with Tattered Cover earlier — they ran low on stock.'),

(10, 4, 2, 'James Wilson', '2025-11-20',
 4, 5, 4, 4, 4, 1,
 'Solid author talk. Dr. Walsh is warm and personable. Audience enjoyed the reading excerpts.',
 'Projector resolution made some slides hard to read.',
 'A great author event with a thoughtful presentation.',
 'Check projector specs before next booking at this branch.'),

-- Rainbow Rhythms — 2 reviews (one with concerns)
(11, 5,13, 'Emma Johnson', '2026-03-17',
 4, 3, 4, 4, 5, 1,
 'The kids absolutely loved the dancing and props. High energy from start to finish.',
 'Performers arrived 15 minutes late with no advance notice. This caused patron frustration.',
 'A high-energy family dance program that delights young audiences.',
 'Tardiness noted. Remind Kelly that a 20-minute setup window is required. One more late arrival = escalate.'),

(12, 5,14, 'Maria Santos', '2025-09-15',
 3, 3, 4, 3, 4, 1,
 'Fun program with good patron engagement. Props were colorful and the music was upbeat.',
 'Sound equipment arrived 25 minutes late because the company forgot it at their previous venue. Staff had to improvise for the first few minutes.',
 'A spirited family dance program with lots of audience participation.',
 'The late equipment issue is the second incident. Flag for management review before next booking.');

-- Update performers.average_rating and total_reviews to match reviews above
UPDATE performers SET average_rating = 4.83, total_reviews = 3 WHERE performer_id = 1;
UPDATE performers SET average_rating = 4.50, total_reviews = 2 WHERE performer_id = 2;
UPDATE performers SET average_rating = 4.17, total_reviews = 2 WHERE performer_id = 3;
UPDATE performers SET average_rating = 4.67, total_reviews = 3 WHERE performer_id = 4;
UPDATE performers SET average_rating = 3.75, total_reviews = 2 WHERE performer_id = 5;

-- =============================================================
-- 18. PERFORMER NOTES
-- =============================================================

INSERT INTO performer_notes
    (performer_id, booking_id, note_type, visibility, note_text, entered_by)
VALUES
(1, NULL, 'general',      'internal',    'Prefers 2 printed copies of the program schedule emailed 1 week in advance. Brings her own table/display.', 'Maria Santos'),
(1, NULL, 'booking',      'internal',    'Tom Bennett handles all scheduling. Do not contact Sarah directly for booking requests — go through Tom or the website contact form.', 'James Wilson'),
(2, NULL, 'booking',      'internal',    'Needs confirmed table count at least 21 days before event date. Janet is very responsive but requires lead time.', 'James Wilson'),
(2, NULL, 'general',      'internal',    'Science Squad team size varies: 2 for indoor programs, 3 for outdoor programs. Confirm headcount for badge purposes.', 'Emma Johnson'),
(3, NULL, 'general',      'internal',    'Carlos prefers a separate warm-up room with a mirror and power outlet for 30 minutes before the program. First-floor rooms work best.', 'Carmen Ortiz'),
(3, NULL, 'booking',      'internal',    'Always specify outdoor vs indoor in the contract — Carlos has different equipment needs for each and will invoice differently.', 'Carmen Ortiz'),
(4, NULL, 'booking',      'internal',    'All booking requests must route through agent Melissa Grant. Reaching out to Dr. Walsh directly can cause delays and has frustrated her in the past.', 'David Park'),
(4, NULL, 'general',      'internal',    'Arrange with a local bookstore 3–4 weeks in advance for book sales. Dr. Walsh does not take a commission but expects books to be available for purchase.', 'David Park'),
(5, 13,  'behavior',      'admin_only',  'Late arrival incident on 2026-03-15: performers arrived 15 minutes after scheduled start time with no advance notice. Patron complaints received. Discussed with Kelly Martinez post-event. She apologized and said they had a scheduling overlap. Monitor closely.', 'Maria Santos'),
(5, 14,  'behavior',      'admin_only',  'Second incident (2025-09-14): sound equipment arrived 25 minutes late. This is a pattern. Before re-booking, management should speak with Rainbow Rhythms leadership directly.', 'Maria Santos'),
(6, NULL, 'payment',      'admin_only',  'New vendor — W-9 and certificate of insurance are required before booking can be confirmed. Brian Chen is aware and said documents will be submitted within 2 weeks (noted 2026-03-15).', 'Sarah Chen'),
(6, NULL, 'general',      'internal',    'Tech Explorers is a new vendor recommended by the Beaverton Public Library. Contact there: Donna Reyes, 503-555-0022. Strong reference given.', 'Sarah Chen');

-- =============================================================
-- 19. PERFORMER PAYMENT PROFILES
-- =============================================================

INSERT INTO performer_payment_profiles
    (performer_id, payee_name, payment_method, tax_id_last4,
     remit_email, remit_phone, payment_terms, requires_po, requires_contract, active)
VALUES
(1, 'Sarah Bennett',      'check',          '7214', 'payments@mayamoonbeamstories.com', '503-555-0112', 'Net 30',  0, 1, 1),
(2, 'STEM Educators LLC', 'invoice',        '8831', 'billing@sciencesquadprograms.com', '206-555-0247', 'Net 30',  1, 1, 1),
(3, 'Carlos Eduardo Vega','check',          '4409', 'carlos@carlosvegamusic.com',       '415-555-0391', 'Net 30',  0, 1, 1),
(4, 'Jennifer Walsh',     'invoice',        '6623', 'melissa@grantliteraryagency.com',  '720-555-0562', 'Net 45',  0, 1, 1),
(5, 'Rainbow Arts LLC',   'check',          '9917', 'kelly@rainbowrhythmsco.com',       '503-555-0778', 'Net 30',  0, 1, 1),
(6, 'Digital Skills Inc.','invoice',        NULL,   'brian@techexplorersworkshop.com',  '503-555-0934', 'Net 30',  1, 1, 1);

-- =============================================================
-- 20. PERFORMER FILES  (metadata only — no actual files)
-- =============================================================

INSERT INTO performer_files
    (performer_id, program_id, file_type, file_name, file_path, mime_type, file_size_bytes, uploaded_by)
VALUES
(1, NULL, 'contract', 'Maya_Moonbeam_Contract_Jan2026.pdf',       'uploads/performers/1/contracts/Maya_Moonbeam_Contract_Jan2026.pdf',       'application/pdf', 245120, 'Maria Santos'),
(1, NULL, 'w9',       'Maya_Moonbeam_W9_2025.pdf',                'uploads/performers/1/docs/Maya_Moonbeam_W9_2025.pdf',                      'application/pdf', 128512, 'Maria Santos'),
(1, NULL, 'promo',    'Maya_Moonbeam_Headshot_2024.jpg',          'uploads/performers/1/promo/Maya_Moonbeam_Headshot_2024.jpg',               'image/jpeg',      2097152,'Maria Santos'),
(2, NULL, 'contract', 'ScienceSquad_Contract_Jan2026.pdf',        'uploads/performers/2/contracts/ScienceSquad_Contract_Jan2026.pdf',         'application/pdf', 198656, 'James Wilson'),
(2, NULL, 'insurance','ScienceSquad_Insurance_Certificate.pdf',   'uploads/performers/2/docs/ScienceSquad_Insurance_Certificate.pdf',         'application/pdf', 312320, 'James Wilson'),
(2, NULL, 'w9',       'ScienceSquad_W9_2025.pdf',                 'uploads/performers/2/docs/ScienceSquad_W9_2025.pdf',                       'application/pdf', 131072, 'James Wilson'),
(3, NULL, 'w9',       'CarlosVega_W9_2025.pdf',                   'uploads/performers/3/docs/CarlosVega_W9_2025.pdf',                         'application/pdf', 125952, 'Carmen Ortiz'),
(3, NULL, 'promo',    'CarlosVega_Promo_Photo.jpg',               'uploads/performers/3/promo/CarlosVega_Promo_Photo.jpg',                    'image/jpeg',      3145728,'Carmen Ortiz'),
(4, NULL, 'contract', 'DrWalsh_Contract_Feb2026.pdf',             'uploads/performers/4/contracts/DrWalsh_Contract_Feb2026.pdf',              'application/pdf', 267264, 'David Park'),
(4, NULL, 'w9',       'DrWalsh_W9_2025.pdf',                      'uploads/performers/4/docs/DrWalsh_W9_2025.pdf',                            'application/pdf', 130048, 'David Park'),
(4, NULL, 'promo',    'DrWalsh_Author_Photo.jpg',                 'uploads/performers/4/promo/DrWalsh_Author_Photo.jpg',                      'image/jpeg',      1835008,'David Park'),
(5, NULL, 'w9',       'RainbowRhythms_W9_2025.pdf',               'uploads/performers/5/docs/RainbowRhythms_W9_2025.pdf',                     'application/pdf', 127488, 'Maria Santos'),
(5, NULL, 'insurance','RainbowRhythms_Insurance_2025.pdf',        'uploads/performers/5/docs/RainbowRhythms_Insurance_2025.pdf',              'application/pdf', 298496, 'Maria Santos');

-- =============================================================
-- 21. PERFORMER BLACKOUT DATES
-- =============================================================

INSERT INTO performer_blackout_dates (performer_id, blackout_start, blackout_end, reason) VALUES
(1, '2026-07-01', '2026-08-31', 'Summer touring schedule — unavailable for library programs'),
(1, '2026-12-20', '2027-01-05', 'Holiday break'),
(3, '2026-08-01', '2026-09-15', 'National concert tour'),
(4, '2026-12-01', '2026-12-20', 'End-of-year writing retreat'),
(5, '2026-06-15', '2026-07-15', 'Company-wide festival season — limited availability; confirm directly');

-- =============================================================
-- 22. IMPACT EVENT PERFORMERS  (linking scores to performers)
-- =============================================================

INSERT INTO impact_event_performers (score_id, performer_id, program_id, fee_agreed, notes) VALUES
(1,  1, 1, 250.00, 'Story Magic for Little Ones — January booking'),
(2,  2, 4, 400.00, 'STEM Discovery — Rocket Science'),
(6,  3, 7, 500.00, 'Rhythms of Latin America — Chinese New Year event'),
(8,  4, 9, 600.00, 'Author Talk & Q&A — Winter series'),
(16, 5,12, 400.00, 'Move & Groove — Toddler Movement'),
(18, 2, 4, 400.00, 'Slime Lab — Science Squad return booking');
