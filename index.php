<?php
/* ===========================================================================
   Remove Password from PDF — Entry Point
*/

declare(strict_types=1);
session_start();

// Include configuration
require_once __DIR__ . '/config/app.php';

// Include helper functions
require_once __DIR__ . '/functions/helpers.php';
require_once __DIR__ . '/functions/security.php';
require_once __DIR__ . '/functions/file_handlers.php';

// Include blog posts data
require_once __DIR__ . '/data/blog_posts.php';

// Include routing logic
require_once __DIR__ . '/routes/web.php';

// If no action is specified, show the main view
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if (empty($action)) {
    require_once __DIR__ . '/views/main.php';
}
