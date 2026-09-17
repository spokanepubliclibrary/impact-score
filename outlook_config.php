<?php
/**
 * Outlook SMTP Configuration
 *
 * This configuration file defines the SMTP settings used to send emails
 * via Microsoft Outlook (Office 365). These settings are required when
 * using PHPMailer or similar libraries for authenticated email delivery.
 *
 * Features:
 *  - Configures host, port, username, and password for Outlook SMTP.
 *  - Sets default "from" address and name for outbound emails.
 *  - Optional support for secure TLS connections (port 587).
 *
 * Usage:
 *  - Include this file wherever email functionality is required.
 *  - Ensure credentials are kept secure and out of version control.
 *
 * @package EmailConfig
 * @version 1.0
 */

// --- SMTP Server Settings for Microsoft Outlook (Office 365) ---
$outlook_host     = 'smtp.office365.com'; // Outlook SMTP server
$outlook_port     = 587;                  // Port for TLS encryption (587)
$outlook_username = 'your_email@example.com'; // Your Outlook email address
$outlook_password = 'your_password';          // Your Outlook email password

// --- Default Sender Information ---
$from_address = 'your_email@example.com'; // Email shown in "From" field
$from_name    = 'Your Name';              // Name shown in "From" field

// --- Optional: Secure connection setting (used by PHPMailer, etc.) ---
// $smtp_secure = 'tls'; // Uncomment if needed
?>
