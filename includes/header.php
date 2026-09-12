<?php
$pageTitle  = $pageTitle  ?? 'QBank Admin';
$activePage = $activePage ?? '';

function navClass($key, $active) {
    return 'nav-link-item' . ($key === $active ? ' active' : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> · QBank Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
<?= $extraHead ?? '' ?>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="mark">QB</div>
            <div>
                <div class="name">QBank Admin</div>
                <div class="tag">Content Control Center</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Overview</div>
            <a href="dashboard.php" class="<?= navClass('dashboard', $activePage) ?>">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <div class="sidebar-section-label">Question Bank</div>
            <a href="allq.php" class="<?= navClass('allq', $activePage) ?>">
                <i class="bi bi-card-checklist"></i> All Questions
            </a>
            <a href="generate.php" class="<?= navClass('generate', $activePage) ?>">
                <i class="bi bi-stars"></i> Generate Questions
            </a>
            <a href="remove_duplicates.php" class="<?= navClass('duplicates', $activePage) ?>">
                <i class="bi bi-shield-exclamation"></i> Remove Duplicates
            </a>
            <a href="query.php" class="<?= navClass('query', $activePage) ?>">
                <i class="bi bi-terminal"></i> SQL Insert Query
            </a>
        </nav>
        <div class="sidebar-foot">
            <div class="admin-chip">
                <div class="avatar"><?= htmlspecialchars(current_admin_initial()) ?></div>
                <div class="who">
                    <div class="n"><?= htmlspecialchars(current_admin_name()) ?></div>
                    <div class="r"><?= htmlspecialchars(current_admin_role()) ?></div>
                </div>
            </div>
            <a href="logout.php" class="logout-link">
                <i class="bi bi-box-arrow-right"></i> Log out
            </a>
        </div>
    </aside>
    <div class="app-main">
        <header class="topbar">
            <div>
                <?php if (!empty($pageCrumb)): ?><div class="crumb"><?= htmlspecialchars($pageCrumb) ?></div><?php endif; ?>
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
            </div>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-secondary btn-sm d-md-none" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="bi bi-list"></i>
                </button>
                <span class="session-stamp"><i class="bi bi-clock-history me-1"></i><?= date('D, d M Y') ?></span>
            </div>
        </header>
        <main class="content-area">
