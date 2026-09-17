-- ─────────────────────────────────────────────────────────────────────────────
-- db/seed_dev.sql — development convenience seed
--
-- DEV ONLY. `make seed-dev` refuses to run when APP_ENV=production.
-- Never bake this file into the production image (`.dockerignore` excludes
-- the entire db/ directory from the web image build context).
--
-- Cleartext credentials (for recovery):
--   admin  / admin
--
-- Hashes generated with Python bcrypt (rounds=12). PHP's password_verify()
-- accepts both $2b$ and $2y$ bcrypt variants, so these work natively.
--
-- All inserts are INSERT IGNORE — safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────

USE impact_score;

-- ── Admin account ───────────────────────────────────────────────────────────
-- Username: admin   Password: admin
INSERT IGNORE INTO admins (username, password) VALUES
    ('admin', '$2b$12$Ll8lBa8q2/TbCxwl1Nr7EO8kUlaRpqrJHArz.P/ThX2jXBQcNXRz6');

-- ── Teams ───────────────────────────────────────────────────────────────────
INSERT IGNORE INTO teams (id, name) VALUES
    (1, 'Youth Services'),
    (2, 'Adult Learning'),
    (3, 'Community Engagement'),
    (4, 'Digital Literacy'),
    (5, 'Teen Programs');

-- ── Locations ───────────────────────────────────────────────────────────────
INSERT IGNORE INTO locations (id, name) VALUES
    (1, 'Main Library - Downtown'),
    (2, 'North Branch'),
    (3, 'South Branch'),
    (4, 'East Branch'),
    (5, 'Community Center');

-- ── Test staff users ────────────────────────────────────────────────────────
-- Note: this app's `users` table doesn't carry a password column — staff
-- identity is keyed off the admin login. Test users below populate the staff
-- list so submitted scores have realistic user_id values.
INSERT IGNORE INTO users (id, name, default_team, email) VALUES
    (101, 'Sarah Martinez',       1, 'sarah@example.local'),
    (102, 'James Chen',           2, 'james@example.local'),
    (103, 'Priya Desai',          3, 'priya@example.local'),
    (104, 'Marcus Johnson',       4, 'marcus@example.local'),
    (105, 'Elena Rodriguez',      5, 'elena@example.local'),
    (106, 'David Thompson',       1, 'david@example.local'),
    (107, 'Amanda Lee',           2, 'amanda@example.local');

-- ── Scoring questions ───────────────────────────────────────────────────────
INSERT IGNORE INTO scoring_questions (id, question_text, points, type, question_type, sort_order) VALUES
    (101, 'Did this program meet its stated goal?',                    1, 'yesno',      'binary',     10),
    (102, 'How engaged was the audience?',                             5, 'scale_1_5',  'scale',      20),
    (103, 'Did the program provide new skills or knowledge?',          3, 'yesno',      'binary',     30),
    (104, 'How would you rate community impact?',                      5, 'scale_1_5',  'scale',      40),
    (105, 'Would you recommend this program again?',                   2, 'yesno',      'binary',     50),
    (106, 'Level of participant satisfaction',                         4, 'scale_1_5',  'scale',      60),
    (107, 'Program alignment with library strategic goals',             3, 'scale_1_5',  'scale',      70);

-- ── Form profiles ───────────────────────────────────────────────────────────
INSERT IGNORE INTO form_profiles (id, team_id, name, bulk_entry, program_type) VALUES
    (101, 1, 'Youth Program Assessment',     0, 'program'),
    (102, 2, 'Adult Learning Evaluation',    0, 'program'),
    (103, 3, 'Community Event Feedback',     0, 'program'),
    (104, 4, 'Digital Skills Workshop',      1, 'program'),
    (105, 5, 'Teen Activity Review',         0, 'program');

-- ── Form questions (link questions to forms) ────────────────────────────────
INSERT IGNORE INTO form_questions (id, form_id, question_id) VALUES
    -- Youth Program Assessment
    (1001, 101, 101),
    (1002, 101, 102),
    (1003, 101, 103),
    (1004, 101, 105),
    -- Adult Learning Evaluation
    (1005, 102, 101),
    (1006, 102, 104),
    (1007, 102, 106),
    (1008, 102, 107),
    -- Community Event Feedback
    (1009, 103, 101),
    (1010, 103, 102),
    (1011, 103, 104),
    (1012, 103, 105),
    -- Digital Skills Workshop
    (1013, 104, 101),
    (1014, 104, 103),
    (1015, 104, 106),
    -- Teen Activity Review
    (1016, 105, 102),
    (1017, 105, 103),
    (1018, 105, 105),
    (1019, 105, 107);

-- ── Sample programs (submitted scores) ───────────────────────────────────────
INSERT IGNORE INTO programs (id, name, team, program_name, total_score, date, attendance, program_type) VALUES
    (1001, 'Storytelling Circle',           'Youth Services',      'Storytelling Circle',           8,  '2024-01-15', 24, 'program'),
    (1002, 'Computer Basics for Seniors',   'Adult Learning',      'Computer Basics',               7,  '2024-01-16', 18, 'program'),
    (1003, 'Community Garden Workshop',     'Community Engagement','Garden Workshop',               9,  '2024-01-17', 32, 'program'),
    (1004, 'Excel for Small Business',      'Digital Literacy',    'Excel Workshop',               10, '2024-01-18', 15, 'program'),
    (1005, 'Teen Gaming Tournament',        'Teen Programs',       'Gaming Night',                  8,  '2024-01-19', 28, 'program'),
    (1006, 'STEM Building Challenge',       'Youth Services',      'STEM Challenge',                9,  '2024-01-22', 22, 'program'),
    (1007, 'Creative Writing Class',        'Adult Learning',      'Writing Workshop',              7,  '2024-01-23', 12, 'program'),
    (1008, 'Book Club Discussion',          'Community Engagement','Book Club',                     8,  '2024-01-24', 19, 'program'),
    (1009, 'Social Media Basics',           'Digital Literacy',    'Social Media 101',              6,  '2024-01-25', 20, 'program'),
    (1010, 'Movie Night for Teens',         'Teen Programs',       'Movie Night',                   7,  '2024-01-26', 35, 'program');

-- ── Scores (impact evaluations with calculated adjusted_impact_score) ──────
-- adjusted_impact_score = total_score + sqrt(attendance)
INSERT INTO scores (id, user_id, team, program, total_score, form_id, program_date, attendance, adjusted_impact_score, team_id, location_id) VALUES
    (1001, 101, 'Youth Services',      'Storytelling Circle',        8, 101, '2024-01-15', 24, 12.90, 1, 1),
    (1002, 102, 'Adult Learning',      'Computer Basics for Seniors',7, 102, '2024-01-16', 18, 11.24, 2, 2),
    (1003, 103, 'Community Engagement','Community Garden Workshop',  9, 103, '2024-01-17', 32, 14.66, 3, 3),
    (1004, 104, 'Digital Literacy',    'Excel for Small Business',   10, 104, '2024-01-18', 15, 13.87, 4, 4),
    (1005, 105, 'Teen Programs',       'Teen Gaming Tournament',      8, 105, '2024-01-19', 28, 13.29, 5, 1),
    (1006, 101, 'Youth Services',      'STEM Building Challenge',     9, 101, '2024-01-22', 22, 13.69, 1, 2),
    (1007, 102, 'Adult Learning',      'Creative Writing Class',      7, 102, '2024-01-23', 12, 10.46, 2, 3),
    (1008, 103, 'Community Engagement','Book Club Discussion',         8, 103, '2024-01-24', 19, 12.36, 3, 4),
    (1009, 104, 'Digital Literacy',    'Social Media Basics',         6, 104, '2024-01-25', 20, 10.47, 4, 5),
    (1010, 105, 'Teen Programs',       'Movie Night for Teens',       7, 105, '2024-01-26', 35, 12.92, 5, 1);

-- ── Score responses (individual question answers) ──────────────────────────
-- Responses for score 1001 (Storytelling Circle - Youth Services)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10001, 1001, 101, 'yes',   1),
    (10002, 1001, 102, '4',     4),
    (10003, 1001, 103, 'yes',   3),
    (10004, 1001, 105, 'yes',   2);

-- Responses for score 1002 (Computer Basics - Adult Learning)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10005, 1002, 101, 'yes',   1),
    (10006, 1002, 104, '4',     4),
    (10007, 1002, 106, '4',     4),
    (10008, 1002, 107, '3',     3);

-- Responses for score 1003 (Garden Workshop - Community Engagement)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10009, 1003, 101, 'yes',   1),
    (10010, 1003, 102, '5',     5),
    (10011, 1003, 104, '5',     5),
    (10012, 1003, 105, 'yes',   2);

-- Responses for score 1004 (Excel Workshop - Digital Literacy)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10013, 1004, 101, 'yes',   1),
    (10014, 1004, 103, 'yes',   3),
    (10015, 1004, 106, '5',     5),
    (10016, 1004, 107, '5',     5);

-- Responses for score 1005 (Gaming Tournament - Teen Programs)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10017, 1005, 102, '4',     4),
    (10018, 1005, 103, 'yes',   3),
    (10019, 1005, 105, 'yes',   2);

-- Responses for score 1006 (STEM Challenge - Youth Services)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10020, 1006, 101, 'yes',   1),
    (10021, 1006, 102, '5',     5),
    (10022, 1006, 103, 'yes',   3),
    (10023, 1006, 105, 'yes',   2);

-- Responses for score 1007 (Writing Class - Adult Learning)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10024, 1007, 101, 'yes',   1),
    (10025, 1007, 104, '3',     3),
    (10026, 1007, 106, '4',     4),
    (10027, 1007, 107, '2',     2);

-- Responses for score 1008 (Book Club - Community Engagement)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10028, 1008, 101, 'yes',   1),
    (10029, 1008, 102, '4',     4),
    (10030, 1008, 104, '4',     4),
    (10031, 1008, 105, 'yes',   2);

-- Responses for score 1009 (Social Media - Digital Literacy)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10032, 1009, 101, 'yes',   1),
    (10033, 1009, 103, 'yes',   3),
    (10034, 1009, 106, '3',     3),
    (10035, 1009, 107, '3',     3);

-- Responses for score 1010 (Movie Night - Teen Programs)
INSERT IGNORE INTO score_responses (id, score_id, question_id, response, points) VALUES
    (10036, 1010, 102, '4',     4),
    (10037, 1010, 103, 'yes',   3),
    (10038, 1010, 105, 'yes',   2);

-- ── Score-user relationships ────────────────────────────────────────────────
INSERT IGNORE INTO score_users (score_id, user_id, role) VALUES
    (1001, 101, 'support'),
    (1002, 102, 'support'),
    (1003, 103, 'support'),
    (1004, 104, 'support'),
    (1005, 105, 'support'),
    (1006, 101, 'support'),
    (1007, 102, 'support'),
    (1008, 103, 'support'),
    (1009, 104, 'support'),
    (1010, 105, 'support');

-- ── Tags (additional performer-related tags) ─────────────────────────────────
INSERT IGNORE INTO tags (tag_name) VALUES
    ('jazz'),
    ('dance'),
    ('theater'),
    ('comedy'),
    ('magic'),
    ('circus skills');

-- ── Sample Performers ───────────────────────────────────────────────────────
INSERT IGNORE INTO performers (performer_id, stage_name, legal_name, performer_type, short_description, full_bio, website_url, home_city, home_state, willing_to_travel, virtual_programs_available, insurance_on_file, w9_on_file, status, average_rating, total_reviews) VALUES
    (101, 'Luna Moon', 'Sarah Lee', 'Storyteller', 'Interactive storytelling for all ages', 'Professional storyteller with 15 years of experience engaging audiences of all ages with folklore and original stories.', 'https://lunamoon.example.local', 'Spokane', 'WA', 1, 0, 1, 1, 'active', 4.80, 12),
    (102, 'Jazz Cat Trio', 'Michael Torres', 'Musician', 'High-energy jazz ensemble', 'Award-winning jazz group specializing in educational performances and interactive workshops.', 'https://jazzcat.example.local', 'Seattle', 'WA', 1, 1, 1, 1, 'active', 4.60, 8),
    (103, 'Dr. STEM Lab', 'Dr. Jennifer Park', 'Educator', 'Interactive science demonstrations', 'Engaging STEM educator with hands-on experiments and interactive learning programs for youth.', NULL, 'Spokane', 'WA', 1, 0, 1, 1, 'active', 4.90, 15),
    (104, 'Magician Marcus', 'Marcus Williams', 'Magician', 'Comedy magic and illusions', 'Interactive magician specializing in family-friendly shows with audience participation.', 'https://magicmarcus.example.local', 'Spokane', 'WA', 0, 0, 0, 1, 'active', 4.70, 6),
    (105, 'Dance Dynamics', 'Priya Sharma', 'Dancer', 'Cultural dance workshops', 'Professional dancer offering Bollywood, contemporary, and cultural dance workshops.', NULL, 'Portland', 'OR', 1, 0, 1, 0, 'pending', 4.50, 3);

-- ── Performer Contacts ──────────────────────────────────────────────────────
INSERT IGNORE INTO performer_contacts (performer_id, contact_name, role, email, phone, is_primary) VALUES
    (101, 'Sarah Lee', 'Performer', 'sarah@lunamoon.example.local', '509-555-0101', 1),
    (102, 'Michael Torres', 'Performer', 'michael@jazzcat.example.local', '206-555-0102', 1),
    (103, 'Jennifer Park', 'Performer', 'jen@stemlab.example.local', '509-555-0103', 1),
    (104, 'Marcus Williams', 'Performer', 'marcus@magicmarcus.example.local', '509-555-0104', 1),
    (105, 'Priya Sharma', 'Performer', 'priya@dancedynamics.example.local', '503-555-0105', 1);

-- ── Performer Programs ──────────────────────────────────────────────────────
INSERT IGNORE INTO performer_programs (program_id, performer_id, program_title, program_description, audience, program_format, duration_minutes, setup_time_minutes, breakdown_time_minutes, capacity_min, capacity_max, base_fee) VALUES
    (201, 101, 'Classic Tales Storytelling', 'Interactive storytelling with audience participation', 'all_ages', 'performance', 45, 15, 10, 15, 100, 250.00),
    (202, 101, 'Creative Writing Workshop', 'Guided story creation workshop for kids', 'kids', 'workshop', 60, 15, 10, 10, 25, 300.00),
    (203, 102, 'Jazz for All Ages', 'High-energy jazz performance', 'all_ages', 'performance', 50, 30, 15, 20, 150, 400.00),
    (204, 102, 'Jazz Improvisation Workshop', 'Learn jazz improvisation basics', 'teens', 'workshop', 90, 20, 15, 8, 20, 350.00),
    (205, 103, 'Mad Scientist Lab', 'Interactive STEM experiments', 'kids', 'workshop', 75, 20, 15, 15, 40, 325.00),
    (206, 104, 'Magic Show Extravaganza', 'Comedy magic with tricks and illusions', 'all_ages', 'performance', 45, 20, 10, 20, 100, 275.00),
    (207, 105, 'Bollywood Dance Basics', 'Learn Bollywood dance moves', 'teens', 'workshop', 60, 15, 10, 12, 30, 280.00);

-- ── Program Tags ────────────────────────────────────────────────────────────
INSERT IGNORE INTO program_tag_map (program_id, tag_id) VALUES
    (201, (SELECT tag_id FROM tags WHERE tag_name = 'storytelling')),
    (202, (SELECT tag_id FROM tags WHERE tag_name = 'storytelling')),
    (203, (SELECT tag_id FROM tags WHERE tag_name = 'jazz')),
    (204, (SELECT tag_id FROM tags WHERE tag_name = 'jazz')),
    (205, (SELECT tag_id FROM tags WHERE tag_name = 'stem')),
    (206, (SELECT tag_id FROM tags WHERE tag_name = 'magic')),
    (207, (SELECT tag_id FROM tags WHERE tag_name = 'dance'));

-- ── Performer Program Requirements ──────────────────────────────────────────
INSERT IGNORE INTO program_requirements (program_id, needs_microphone, needs_sound_system, needs_projector, needs_stage, outdoor_possible, additional_requirements) VALUES
    (201, 1, 0, 0, 0, 1, 'Comfortable seating or rugs for audience'),
    (203, 1, 1, 0, 1, 0, 'Level floor, electrical outlet for equipment'),
    (205, 1, 0, 1, 0, 0, 'Long tables for experiments, access to sink'),
    (206, 1, 0, 0, 0, 1, 'Elevated stage or clear performance area');

-- ── Performer Bookings ──────────────────────────────────────────────────────
INSERT IGNORE INTO performer_bookings (booking_id, performer_id, program_id, event_title, branch_name, event_date, attendance_count, agreed_fee, booking_status, booked_by_staff_name) VALUES
    (301, 101, 201, 'Storytelling at Main Library', 'Main Branch', '2024-01-15', 24, 250.00, 'completed', 'Sarah Martinez'),
    (302, 103, 205, 'STEM Demo at Youth Event', 'North Branch', '2024-01-22', 22, 325.00, 'completed', 'David Thompson'),
    (303, 102, 203, 'Jazz Concert Series', 'Main Branch', '2024-01-26', 35, 400.00, 'completed', 'James Chen'),
    (304, 104, 206, 'Magic Show - Winter Celebration', 'South Branch', '2024-02-10', 45, 275.00, 'confirmed', 'Priya Desai'),
    (305, 105, 207, 'Bollywood Dance Workshop', 'Community Center', '2024-02-15', 18, 280.00, 'tentative', 'Elena Rodriguez');

-- ── Link Impact Scores to Performers ────────────────────────────────────────
INSERT IGNORE INTO impact_event_performers (id, score_id, performer_id, program_id, fee_agreed, notes) VALUES
    (1, 1001, 101, 201, 250.00, 'Storytelling Circle by Luna Moon'),
    (2, 1006, 103, 205, 325.00, 'STEM Challenge by Dr. STEM Lab'),
    (3, 1010, 102, 203, 400.00, 'Movie Night performance by Jazz Cat Trio');

-- ── Performer Reviews ───────────────────────────────────────────────────────
INSERT IGNORE INTO performer_reviews (review_id, performer_id, booking_id, reviewer_name, review_date, rating_overall, rating_professionalism, rating_engagement, rating_value, rating_audience_response, would_book_again, strengths, internal_notes) VALUES
    (401, 101, 301, 'Sarah Martinez', '2024-01-16', 5, 5, 5, 4, 5, 1, 'Excellent storyteller, very professional, great with kids', 'Highly recommended for future events'),
    (402, 103, 302, 'David Thompson', '2024-01-23', 5, 5, 5, 5, 5, 1, 'Amazing hands-on experiments, incredible engagement', 'Book for more STEM workshops'),
    (403, 102, 303, 'James Chen', '2024-01-27', 5, 4, 4, 5, 5, 1, 'High-quality jazz ensemble, professional', 'Audience loved the music');

-- ── Confirmation banner ─────────────────────────────────────────────────────
SELECT 'Dev seed applied: 5 teams, 5 locations, 7 staff, 7 scoring questions, 5 forms, 10 programs with calculated adjusted impact scores, 5 performers with programs, 5 bookings, and 3 reviews. Login: admin / admin (rotate immediately).' AS info;
