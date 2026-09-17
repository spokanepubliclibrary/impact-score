<?php
/**
 * Secure Image File Viewer
 *
 * This script serves image files from the `uploads/` directory based on a
 * `file` parameter passed via GET. It ensures security by sanitizing the
 * filename and preventing directory traversal.
 *
 * Features:
 *  - Sanitizes file input using `basename()` to block traversal attacks
 *  - Checks for file existence before attempting to serve
 *  - Outputs the image directly with appropriate headers
 *
 * Notes:
 *  - Assumes images are PNGs. Modify `Content-Type` header as needed.
 *  - Files must reside in the `uploads/` folder.
 *
 * @package FileServe
 * @version 1.0
 */

// --- Check for a 'file' parameter in the GET request ---
if (isset($_GET['file'])) {
    // Sanitize the filename to prevent directory traversal
    $file = basename($_GET['file']);
    $path = "uploads/" . $file;

    // --- Check if the file exists in the uploads directory ---
    if (file_exists($path)) {
        // Output the image with proper content type (assumes PNG)
        header("Content-Type: image/png");
        readfile($path);
        exit;
    } else {
        // File was not found
        echo "❌ Image not found.";
    }
}
?>
