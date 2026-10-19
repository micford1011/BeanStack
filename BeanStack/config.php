<?php
declare(strict_types=1);
session_start();

$host = 'localhost';
$dbname = 'beanstack';
$dbuser = 'root';
$dbpass = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Start MySQL in XAMPP and run setup.php first.');
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function logged_in(): bool {
    return isset($_SESSION['user_id']);
}
function require_login(): void {
    if (!logged_in()) {
        header('Location: login.php');
        exit;
    }
}
function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied. This page is for administrators only.');
    }
}
function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function redirect(string $url): never {
    header("Location: {$url}");
    exit;
}
