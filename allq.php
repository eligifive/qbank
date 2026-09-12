<?php
require "auth.php";
include "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_update') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }

    header('Content-Type: application/json');

    $question_id = $_POST['question_id'] ?? null;
    $field = $_POST['field'] ?? null;
    $value = $_POST['value'] ?? null;

    if ($value === '') {
        $value = null;
    }

    if (empty($question_id)) {
        echo json_encode(['success' => false, 'error' => "Question ID is missing or empty."]);
        exit;
    }

    if (!in_array($field, ['subtopic_id', 'is_verified'])) {
        echo json_encode(['success' => false, 'error' => "Field '$field' is not allowed."]);
        exit;
    }

    try {
        $updateSql = "UPDATE qbank SET $field = :val WHERE question_id = :qid";
        $updateStmt = $pdo->prepare($updateSql);
        $success = $updateStmt->execute([':val' => $value, ':qid' => $question_id]);
        echo json_encode(['success' => $success]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'move_question') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        exit;
    }

    header('Content-Type: application/json');

    $question_id = $_POST['question_id'] ?? null;
    $target = $_POST['target'] ?? null;

    if (empty($question_id) || !ctype_digit((string)$question_id)) {
        echo json_encode(['success' => false, 'error' => 'Question ID is missing or invalid.']);
        exit;
    }

    if (!in_array($target, ['stage', 'image'], true)) {
        echo json_encode(['success' => false, 'error' => "Target '$target' is not allowed."]);
        exit;
    }

    // "Yes, push to Stage" = verified; "No, push to Image" = not verified.
    $verifiedValue = ($target === 'stage') ? 1 : 0;

    $columns = [
        'subject_id', 'subtopic_id', 'applicable_exam_codes', 'difficulty', 'question_type',
        'question_text', 'option1', 'option2', 'option3', 'option4', 'correct_answer',
        'explanation', 'content_hash', 'generated_by_ai', 'is_verified', 'is_active',
    ];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM qbank WHERE question_id = :qid FOR UPDATE");
        $stmt->execute([':qid' => $question_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Question not found in qbank (it may already have been moved).']);
            exit;
        }

        $insertCols = implode(', ', array_map(fn($c) => "`$c`", $columns));
        $insertVals = implode(', ', array_map(fn($c) => ":$c", $columns));
        $updateCols = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($columns, ['content_hash'])));

        $insertStmt = $pdo->prepare(
            "INSERT INTO `$target` ($insertCols) VALUES ($insertVals)
             ON DUPLICATE KEY UPDATE $updateCols"
        );

        $params = [];
        foreach ($columns as $c) {
            $params[":$c"] = ($c === 'is_verified') ? $verifiedValue : ($row[$c] ?? null);
        }
        $insertStmt->execute($params);

        $deleteStmt = $pdo->prepare("DELETE FROM qbank WHERE question_id = :qid");
        $deleteStmt->execute([':qid' => $question_id]);

        $pdo->commit();

        echo json_encode(['success' => true, 'target' => $target, 'question_id' => (int)$question_id]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

require_login();

// Safely fetch tables with fallback
$subjectsList = [];
try {
    $stmt = $pdo->query("SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC");
    $subjectsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$subtopicsList = [];
try {
    $stmt = $pdo->query("SELECT subtopic_id, subtopic_name FROM subtopics ORDER BY subtopic_name ASC");
    $subtopicsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$filters = [
    'subject_id' => '', 'subtopic_id' => '', 'applicable_exam_codes' => '',
    'difficulty' => '', 'is_verified' => '', 'is_active' => '', 'generated_by_ai' => ''
];

$whereClauses = [];
$params = [];

foreach ($filters as $field => $val) {
    if (isset($_GET[$field]) && $_GET[$field] !== '') {
        if ($field === 'applicable_exam_codes') {
            $whereClauses[] = "$field LIKE :$field";
            $params[":$field"] = '%' . $_GET[$field] . '%';
        } else {
            $whereClauses[] = "$field = :$field";
            $params[":$field"] = $_GET[$field];
        }
        $filters[$field] = $_GET[$field];
    }
}

$whereSql = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$totalRecords = 0;
$questions = [];
try {
    $stmtCount = $pdo->prepare("SELECT COUNT(question_id) FROM qbank $whereSql");
    $stmtCount->execute($params);
    $totalRecords = $stmtCount->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM qbank $whereSql ORDER BY question_id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$totalPages = ceil($totalRecords / $limit);

function getPageUrl($pageNum) {
    $getParams = $_GET;
    $getParams['page'] = $pageNum;
    return '?' . http_build_query($getParams);
}

$pageTitle  = 'All Questions';
$pageCrumb  = 'Question Bank';
$activePage = 'allq';
$extraHead = <<<HTML
<script>
  window.MathJax = { tex: { inlineMath: [['$', '$'], ['\\(', '\\)']], displayMath: [['$$', '$$'], ['\\[', '\\]']], processEscapes: true } };
</script>
<script src="https://polyfill.io/v3/polyfill.min.js?features=es6"></script>
<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
<style>
    .option-box { border: 1px solid #dee2e6; border-radius: 5px; padding: 8px 12px; margin-bottom: 8px; background-color: #ffffff; }
    .correct-answer { background-color: rgba(25, 135, 84, 0.1); border-color: #198754; }
    .save-status { font-weight: 500; transition: opacity 0.5s ease-in-out; }
</style>
HTML;
include 'includes/header.php';
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white">
        <h5 class="mb-0">Filter Questions</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Subject</label>
                <select name="subject_id" class="form-select">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjectsList as $subject): ?>
                        <option value="<?= htmlspecialchars($subject['subject_id']) ?>" <?= ($filters['subject_id'] == $subject['subject_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($subject['subject_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Difficulty</label>
                <select name="difficulty" class="form-select">
                    <option value="">All</option>
                    <option value="1" <?= $filters['difficulty'] === '1' ? 'selected' : '' ?>>1 - Easy</option>
                    <option value="2" <?= $filters['difficulty'] === '2' ? 'selected' : '' ?>>2 - Medium</option>
                    <option value="3" <?= $filters['difficulty'] === '3' ? 'selected' : '' ?>>3 - Hard</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Is Verified?</label>
                <select name="is_verified" class="form-select">
                    <option value="">All</option>
                    <option value="1" <?= $filters['is_verified'] === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filters['is_verified'] === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2 w-100">Apply Filters</button>
                <a href="?" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0" style="font-size:17px;">Results <span class="badge bg-primary rounded-pill"><?= $totalRecords ?></span></h4>
    <span class="text-muted small">Page <?= $page ?> of <?= $totalPages ?: 1 ?></span>
</div>

<?php if (count($questions) > 0): ?>
    <?php foreach ($questions as $q): ?>
        <div class="card mb-4 shadow-sm border-0" id="qcard-<?= $q['question_id'] ?>">
            <div class="card-header bg-white d-flex justify-content-between">
                <strong>Q<?= $q['question_id'] ?>.</strong>
                <div class="text-muted small">Subj: <?= $q['subject_id'] ?? '-' ?> | Diff: <?= $q['difficulty'] ?? '-' ?></div>
            </div>
            <div class="card-body">
                <h5 class="card-title mb-4"><?= nl2br(htmlspecialchars($q['question_text'] ?? '')) ?></h5>
                <div class="row">
                    <div class="col-md-12">
                        <?php for ($i = 1; $i <= 4; $i++):
                            $isCorrect = (isset($q['correct_answer']) && $q['correct_answer'] == $i);
                            $highlightClass = $isCorrect ? 'correct-answer' : '';
                        ?>
                            <div class="option-box <?= $highlightClass ?>">
                                <strong>Option <?= $i ?>:</strong> <?= htmlspecialchars($q['option'.$i] ?? '') ?>
                                <?= $isCorrect ? '<span class="float-end text-success">✔️ Correct</span>' : '' ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-muted small me-1">Verified?</span>
                    <button type="button" class="btn btn-sm btn-success move-question-btn" data-question-id="<?= $q['question_id'] ?>" data-target="stage">
                        <i class="bi bi-check-circle"></i> Yes, push to Stage
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger move-question-btn" data-question-id="<?= $q['question_id'] ?>" data-target="image">
                        <i class="bi bi-x-circle"></i> No, push to Image
                    </button>
                    <span class="save-status small" id="status-<?= $q['question_id'] ?>" style="opacity: 0;">Saved!</span>
                </div>
                <div class="text-muted small">Added: <?= isset($q['created_at']) ? date('Y-m-d', strtotime($q['created_at'])) : '-' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="alert alert-warning">No questions found matching your criteria.</div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= getPageUrl($page - 1) ?>">Previous</a>
            </li>
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            if ($startPage > 1) { echo '<li class="page-item"><a class="page-link" href="'.getPageUrl(1).'">1</a></li>'; }
            for ($i = $startPage; $i <= $endPage; $i++) {
                $active = ($page == $i) ? 'active' : '';
                echo '<li class="page-item '.$active.'"><a class="page-link" href="'.getPageUrl($i).'">'.$i.'</a></li>';
            }
            if ($endPage < $totalPages) { echo '<li class="page-item"><a class="page-link" href="'.getPageUrl($totalPages).'">'.$totalPages.'</a></li>'; }
            ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= getPageUrl($page + 1) ?>">Next</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php
$extraScripts = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.move-question-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const questionId = this.getAttribute('data-question-id');
            const target = this.getAttribute('data-target');

            const card = document.getElementById('qcard-' + questionId);
            const statusSpan = document.getElementById('status-' + questionId);
            const buttons = card ? card.querySelectorAll('.move-question-btn') : [];

            buttons.forEach(function (b) { b.disabled = true; });
            if (statusSpan) {
                statusSpan.textContent = 'Moving...';
                statusSpan.className = 'save-status text-warning small ms-2';
                statusSpan.style.opacity = '1';
            }

            const formData = new FormData();
            formData.append('action', 'move_question');
            formData.append('question_id', questionId);
            formData.append('target', target);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success && card) {
                        card.style.transition = 'opacity 0.3s ease';
                        card.style.opacity = '0';
                        setTimeout(function () { card.remove(); }, 300);
                    } else {
                        buttons.forEach(function (b) { b.disabled = false; });
                        if (statusSpan) {
                            statusSpan.textContent = data.error || 'Error';
                            statusSpan.className = 'save-status text-danger small ms-2';
                        }
                        alert('Could not move question: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(function () {
                    buttons.forEach(function (b) { b.disabled = false; });
                    if (statusSpan) {
                        statusSpan.textContent = 'Network Error';
                        statusSpan.className = 'save-status text-danger small ms-2';
                    }
                    alert('Network error while moving the question.');
                });
        });
    });
});
</script>
HTML;
include 'includes/footer.php';
?>