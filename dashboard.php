<?php
require "auth.php";
require_login();
include "db.php";



function safeCount($pdo, $sql) {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
}

$totalQuestions = safeCount($pdo, "SELECT COUNT(*) FROM qbank");
$verifiedCount  = safeCount($pdo, "SELECT COUNT(*) FROM qbank WHERE is_verified = 1");
$activeCount    = safeCount($pdo, "SELECT COUNT(*) FROM qbank WHERE is_active = 1");
$aiCount        = safeCount($pdo, "SELECT COUNT(*) FROM qbank WHERE generated_by_ai = 1");
$subjectCount   = safeCount($pdo, "SELECT COUNT(*) FROM subjects");

$verifiedPct = ($totalQuestions !== null && $totalQuestions > 0 && $verifiedCount !== null)
    ? round(($verifiedCount / $totalQuestions) * 100)
    : null;

$recent = [];
try {
    $recent = $pdo->query("
        SELECT q.question_id, q.question_text, q.subject_id, q.is_verified, q.created_at,
               s.subject_name
        FROM qbank q
        LEFT JOIN subjects s ON s.subject_id = q.subject_id
        ORDER BY q.created_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent = [];
}

$pageTitle  = 'Dashboard';
$pageCrumb  = 'Overview';
$activePage = 'dashboard';
include 'includes/header.php';
?>
<div class="mb-4">
    <h2 class="mb-1" style="font-size:22px;">Welcome back, <?= htmlspecialchars(explode(' ', current_admin_name())[0]) ?>.</h2>
    <p class="text-muted mb-0">Here's the current state of the question bank.</p>
</div>

<!-- Scorecards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="scorecard s-blue">
            <div class="stripe"></div>
            <div class="figure"><?= $totalQuestions !== null ? number_format($totalQuestions) : '—' ?></div>
            <div class="label">Total questions</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="scorecard s-green">
            <div class="stripe"></div>
            <div class="figure"><?= $verifiedPct !== null ? $verifiedPct . '%' : '—' ?></div>
            <div class="label">Verified (<?= $verifiedCount !== null ? number_format($verifiedCount) : '—' ?>)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="scorecard">
            <div class="stripe"></div>
            <div class="figure"><?= $activeCount !== null ? number_format($activeCount) : '—' ?></div>
            <div class="label">Active questions</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="scorecard s-red">
            <div class="stripe"></div>
            <div class="figure"><?= $subjectCount !== null ? number_format($subjectCount) : '—' ?></div>
            <div class="label">Subjects covered</div>
        </div>
    </div>
</div>

<!-- Quick actions -->
<h5 class="mb-3" style="font-size:15px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted);">Tools</h5>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="allq.php" class="tool-card">
            <div class="icon-box" style="background:rgba(59,110,143,0.12); color:var(--blue);"><i class="bi bi-card-checklist"></i></div>
            <h5>All Questions</h5>
            <p>Browse, filter, and quick-edit the full question bank.</p>
        </a>
    </div>
    <div class="col-md-4">
        <a href="remove_duplicates.php" class="tool-card">
            <div class="icon-box" style="background:rgba(214,69,80,0.12); color:var(--red);"><i class="bi bi-shield-exclamation"></i></div>
            <h5>Remove Duplicates</h5>
            <p>Scan a subject for repeated questions and clean them up.</p>
        </a>
    </div>
    <div class="col-md-4">
        <a href="query.php" class="tool-card">
            <div class="icon-box" style="background:rgba(232,163,61,0.15); color:var(--gold-deep);"><i class="bi bi-terminal"></i></div>
            <h5>SQL Insert Query</h5>
            <p>Bulk-insert new questions with a guarded SQL editor.</p>
        </a>
    </div>
</div>

<!-- Recent questions -->
<h5 class="mb-3" style="font-size:15px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted);">Recently added</h5>
<div class="card shadow-sm border-0">
    <?php if (count($recent) > 0): ?>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr class="text-muted small">
                        <th style="width:70px;">ID</th>
                        <th>Question</th>
                        <th style="width:150px;">Subject</th>
                        <th style="width:110px;">Verified</th>
                        <th style="width:140px;">Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="text-muted">#<?= (int)$r['question_id'] ?></td>
                            <td><?= htmlspecialchars(mb_strimwidth($r['question_text'], 0, 90, '…')) ?></td>
                            <td><?= htmlspecialchars($r['subject_name'] ?? ('ID ' . $r['subject_id'])) ?></td>
                            <td>
                                <?php if ($r['is_verified']): ?>
                                    <span class="badge" style="background:rgba(47,158,68,0.12); color:var(--green);">Yes</span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(107,114,128,0.12); color:var(--muted);">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= $r['created_at'] ? date('d M Y', strtotime($r['created_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="p-4 text-center text-muted small">No questions to show yet.</div>
    <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
