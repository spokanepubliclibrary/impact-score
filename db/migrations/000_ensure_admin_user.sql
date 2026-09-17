-- Ensure admin user exists (runs after init.sql and before other migrations)
-- This migration guarantees the admin account is always present, even if
-- the INSERT IGNORE in init.sql didn't execute during first container start.

-- Username: admin  |  Password: changeme
-- Hash: $2y$12$RkWvXALsT/0nyqcQtI.Tp.8.YY0eGMovCCKBSDwon8sUCsm83UCAC
-- Generate a new hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"

INSERT INTO admins (username, password)
VALUES ('admin', '$2y$12$RkWvXALsT/0nyqcQtI.Tp.8.YY0eGMovCCKBSDwon8sUCsm83UCAC')
ON DUPLICATE KEY UPDATE password=VALUES(password);
