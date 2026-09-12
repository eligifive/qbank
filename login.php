<?php
session_start();
require 'db.php';

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT * 
        FROM admin 
        WHERE email = ? 
        AND password = ?
    ");

    $stmt->execute([$email, $password]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['adminid'] = $admin['adminid'];
        $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
        $_SESSION['admin_role'] = $admin['role'];
        header("Location: dashboard.php");
        
        exit;

    } else {
        $error = "Invalid email or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · QBank Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
<style>
    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            radial-gradient(circle at 15% 20%, rgba(232,163,61,0.14), transparent 40%),
            radial-gradient(circle at 85% 80%, rgba(59,110,143,0.18), transparent 45%),
            var(--ink);
        padding: 24px;
    }
    .login-wrap {
        width: 100%;
        max-width: 900px;
        background: var(--paper);
        border-radius: 16px;
        overflow: hidden;
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        box-shadow: 0 30px 70px rgba(0,0,0,0.35);
    }
    .login-side {
        background: var(--ink);
        color: #C9D2E6;
        padding: 44px 40px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
    }
    .login-side .mark {
        width: 42px; height: 42px;
        border-radius: 9px;
        background: linear-gradient(145deg, var(--gold), var(--gold-deep));
        display: flex; align-items: center; justify-content: center;
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        color: var(--ink);
        margin-bottom: 22px;
    }
    .login-side h2 { color: #fff; font-size: 26px; line-height: 1.25; max-width: 24ch; }
    .login-side p { color: #8891AC; font-size: 14px; max-width: 32ch; }
    .login-side .stat-row { display: flex; gap: 26px; margin-top: 26px; }
    .login-side .stat-row .n { font-family: 'Space Grotesk', sans-serif; font-size: 22px; font-weight: 700; color: #fff; }
    .login-side .stat-row .l { font-size: 11.5px; color: #8891AC; }
    .login-form-side { padding: 48px 44px; }
    .login-form-side h1 { font-size: 24px; margin-bottom: 4px; }
    .login-form-side .sub { color: var(--muted); font-size: 14px; margin-bottom: 28px; }
    .form-label { font-size: 13px; font-weight: 600; color: var(--ink); }
    .form-control { border-color: var(--line); padding: 10px 12px; }
    .form-control:focus { border-color: var(--gold); box-shadow: 0 0 0 0.2rem rgba(232,163,61,0.18); }
    .btn-login { background: var(--ink); color: #fff; font-weight: 600; padding: 11px; border-radius: 8px; }
    .btn-login:hover { background: var(--ink-soft); color: #fff; }
    @media (max-width: 760px) {
        .login-wrap { grid-template-columns: 1fr; }
        .login-side { display: none; }
    }
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-side">
        <div>
            <div class="mark">QB</div>
            <h2>The control center for your question bank.</h2>
            <p>Review questions, clear duplicates, and insert new content — all from one place.</p>
        </div>
        <div class="stat-row">
            <div><div class="n">01</div><div class="l">Review questions</div></div>
            <div><div class="n">02</div><div class="l">Clear duplicates</div></div>
            <div><div class="n">03</div><div class="l">Insert via SQL</div></div>
        </div>
    </div>
    <div class="login-form-side">
        <h1>Sign in</h1>
        <div class="sub">Enter your admin credentials to continue.</div>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Admin ID or Email</label>
                <input type="text" name="email" class="form-control" placeholder="e.g. admin or admin@example.com"
                        required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Your password" required>
            </div>
            <button type="submit" class="btn btn-login w-100">Sign in <i class="bi bi-arrow-right ms-1"></i></button>
        </form>
        <div class="mt-4 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            Passwords in this system are currently stored as plain text — ask your database admin about migrating to hashed passwords when convenient.
        </div>
    </div>
</div>
</body>
</html>
