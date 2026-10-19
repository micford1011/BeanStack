<?php
require_once 'config.php';
if (logged_in()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        redirect('index.php');
    }
    $error = 'Incorrect username or password.';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login | BeanStack</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="login-page">
<div class="login-art"><div class="login-logo">☕</div><h1>BeanStack</h1><p>Inventory Management System</p><div class="coffee-art">☕</div><small>Every ingredient, accounted for.</small></div>
<main class="login-panel"><div class="login-box"><span class="eyebrow">CAFÉ MARIETA · INVENTORY</span><h2>Welcome back</h2><p class="muted">Sign in to manage your café inventory.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post"><label>Username</label><input name="username" autocomplete="username" placeholder="Enter your username" required autofocus><label>Password</label><input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required><button class="btn btn-full" type="submit">Sign in <span>→</span></button></form><p class="login-foot">A calmer way to keep your café stocked.</p></div></main></body></html>
