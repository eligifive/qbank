<?php
/**
 * dump_into_qbank.php
 *
 * Inserts the batch of validated questions staged by generate.php (held in
 * $_SESSION['qbank_pending_import'] -- no file is written to disk anywhere
 * in this flow) directly into the qbank table via parameterized queries.
 */
require "auth.php";
require_login();
require "db.php";

$rows = $_SESSION['qbank_pending_import'] ?? null;

if (!is_array($rows) || empty($rows)) {
    http_response_code(400);
    die('Nothing to dump -- generate some questions first, then click "Dump into Qbank".');
}

try {
    $stats = insertQuestionsIntoQbank($pdo, $rows);

    // Clear the staged batch now that it's been inserted, so a page refresh
    // or a second click can't insert the same batch twice.
    unset($_SESSION['qbank_pending_import']);

    header(
        'Location: generate.php?dumped=' . $stats['inserted']
        . '&dupes=' . $stats['duplicates']
        . '&failed=' . $stats['failed']
    );
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    die(
        'Qbank dump failed. No questions were inserted. '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
    );
}