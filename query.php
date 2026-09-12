<?php
require "auth.php";
require_login();
include "db.php";

$message = '';
$messageType = '';
$query_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql_query'])) {
    $query_input = trim($_POST['sql_query']);
    
    if (empty($query_input)) {
        $message = "Please enter a query to execute.";
        $messageType = "warning";
    } else {
        if (!preg_match('/^\s*INSERT\s+INTO\s+[`]?qbank[`]?[\s(]/i', $query_input)) {
            $message = "<strong>Action Denied:</strong> You are only allowed to execute <code>INSERT</code> statements on the <code>qbank</code> table.";
            $messageType = "danger";
        } 
        elseif (preg_match('/;\s*(DROP|DELETE|UPDATE|ALTER|TRUNCATE|REPLACE)\s+/i', $query_input)) {
            $message = "<strong>Action Denied:</strong> Stacked dangerous commands (DROP, DELETE, UPDATE, ALTER, etc.) were detected in your query string.";
            $messageType = "danger";
        } 
        else {
            try {
                $affectedRows = $pdo->exec($query_input);
                if ($affectedRows !== false) {
                    $message = "<strong>Success!</strong> Query executed perfectly. <strong>($affectedRows row(s) inserted)</strong>";
                    $messageType = "success";
                    $query_input = ''; 
                } else {
                    $errorInfo = $pdo->errorInfo();
                    $message = "<strong>SQL Error:</strong> " . htmlspecialchars($errorInfo[2]);
                    $messageType = "danger";
                }
            } catch (PDOException $e) {
                $message = "<strong>Database Error:</strong> " . htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
    }
}

$pageTitle = 'SQL Insert Query';
$pageCrumb = 'Database Tools';
$activePage = 'query';
$extraHead = '<style>
    .query-editor { font-family: "Courier New", Courier, monospace; background-color: #1e1e1e; color: #d4d4d4; font-size: 14px; border-radius: 5px; padding: 15px; border: 1px solid #ced4da; resize: vertical; }
    .query-editor:focus { background-color: #1e1e1e; color: #d4d4d4; box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); }
    .rules-box { background-color: #e9ecef; border-left: 4px solid #6c757d; }
</style>';
include 'includes/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <div class="p-3 mb-4 rounded rules-box small">
            <strong>Security Constraints Active:</strong>
            <ul class="mb-0 mt-1">
                <li>Only <code>INSERT INTO qbank</code> statements are permitted.</li>
                <li><code>UPDATE</code>, <code>DELETE</code>, <code>DROP</code>, <code>TRUNCATE</code>, and <code>ALTER</code> commands are strictly blocked.</li>
                <li>Multiple inserts can be passed simultaneously.</li>
            </ul>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <span>SQL Editor</span>
                <span class="badge bg-secondary">Target: qbank</span>
            </div>
            <div class="card-body p-0">
                <form method="POST" action="">
                    <textarea 
                        name="sql_query" 
                        class="form-control query-editor border-0 rounded-0" 
                        rows="12" 
                        placeholder="Type or paste your INSERT query here...&#10;&#10;Example:&#10;INSERT INTO qbank (subject_id, question_text, correct_answer) VALUES (1, 'What is PHP?', 1);"
                        required spellcheck="false"><?= htmlspecialchars($query_input) ?></textarea>
                    
                    <div class="p-3 bg-light border-top d-flex justify-content-end">
                        <button type="button" class="btn btn-secondary me-2" onclick="document.querySelector('textarea').value='';">Clear</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold">Execute Query</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
