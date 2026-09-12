<?php
require "auth.php";
require_login();
include "db.php";

$message = '';
$messageType = '';
$duplicateCount = 0;
$duplicateIds = [];

$subject_id = isset($_REQUEST['subject_id']) && $_REQUEST['subject_id'] !== '' ? (int)$_REQUEST['subject_id'] : '';

function getDuplicateIds($pdo, $subject_id) {
    $stmt = $pdo->prepare("SELECT question_id, question_text FROM qbank WHERE subject_id = :subj ORDER BY question_id ASC");
    $stmt->execute([':subj' => $subject_id]);
    
    $seenHashes = [];
    $dupes = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $textHash = md5(trim($row['question_text']));
        if (isset($seenHashes[$textHash])) {
            $dupes[] = (int)$row['question_id']; // Fixed: was $row['id']
        } else {
            $seenHashes[$textHash] = true;
        }
    }
    return $dupes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if ($subject_id !== '') {
        try {
            $idsToDelete = getDuplicateIds($pdo, $subject_id);
            if (count($idsToDelete) > 0) {
                $idList = implode(',', $idsToDelete);
                $markSql = "UPDATE qbank SET dupe = 1 WHERE question_id IN ($idList)";
                $pdo->exec($markSql);
                
                $deleteSql = "DELETE FROM qbank WHERE dupe = 1 AND subject_id = :subj";
                $stmtDel = $pdo->prepare($deleteSql);
                $stmtDel->execute([':subj' => $subject_id]);
                
                $deletedRows = $stmtDel->rowCount();
                $message = "Success! Marked and removed $deletedRows duplicate row(s).";
                $messageType = "success";
            } else {
                $message = "No duplicates found to delete.";
                $messageType = "warning";
            }
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "Error: Subject ID is required for deletion.";
        $messageType = "danger";
    }
}

if ($subject_id !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $duplicateIds = getDuplicateIds($pdo, $subject_id);
        $duplicateCount = count($duplicateIds);
        if ($duplicateCount === 0) {
            $message = "No duplicates found for this Subject.";
            $messageType = "info";
        }
    } catch (PDOException $e) {
        $message = "Error checking duplicates: " . $e->getMessage();
        $messageType = "danger";
    }
}

$pageTitle = 'Remove Duplicates';
$pageCrumb = 'Database Tools';
$activePage = 'duplicates';
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Form -->
        <div class="card shadow-sm mb-4 border-0">
            <div class="card-header bg-white">
                <h5 class="mb-0">Check for Duplicates by Subject</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Subject ID</label>
                        <input type="number" name="subject_id" class="form-control" required value="<?= htmlspecialchars($subject_id) ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Check Subject</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Deletion Confirmation Box -->
        <?php if ($duplicateCount > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
            <div class="card border-danger shadow-sm">
                <div class="card-body text-center py-4">
                    <h4 class="text-danger mb-3">⚠️ Duplicates Found!</h4>
                    <p class="fs-5">
                        Found <strong><?= $duplicateCount ?></strong> duplicate row(s) for<br>
                        Subject ID: <strong><?= htmlspecialchars($subject_id) ?></strong>.
                    </p>
                    <p class="text-muted small mb-4">
                        The script will first update these rows to set <code>dupe = 1</code>, and then delete all rows where <code>dupe = 1</code>.
                    </p>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="subject_id" value="<?= htmlspecialchars($subject_id) ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Update to dupe=1 and delete these <?= $duplicateCount ?> rows permanently?');">
                            Mark as Dupe & Delete Now
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
