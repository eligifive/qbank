<?php
/**
 * download_sql.php
 * Serves a previously generated qbank SQL file as a forced download.
 * Only files inside /generated with a safe, exact .sql filename are allowed.
 */
require "auth.php";
require_login();

$file = basename($_GET['file'] ?? '');

if ($file === '' || !preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/', $file)) {
    http_response_code(400);
    die('Invalid file name.');
}

$path = __DIR__ . '/generated/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    die('File not found. It may have already been removed.');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, must-revalidate');
readfile($path);
exit;
