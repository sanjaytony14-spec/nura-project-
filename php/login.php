<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$method = method('GET', 'POST', 'DELETE');
sessionStart();
if ($method === 'GET') respond(200, ['csrf' => $_SESSION['csrf'], 'authenticated' => isset($_SESSION['user_id'])]);
csrf();
if ($method === 'DELETE') {
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => env('COOKIE_SECURE') === '1', 'httponly' => true, 'samesite' => 'Lax']);
    respond(200, ['message' => 'Signed out.']);
}
rateLimit('login', 30);
$data = input();
$identifier = field($data, 'identifier');
$password = $data['password'] ?? null;
if (strlen($identifier) > 254 || !is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) respond(422, ['error' => 'Check your sign-in details.']);
$stmt = mysql()->prepare('SELECT id, password_hash FROM users WHERE email = ? OR username = ? LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$account = $stmt->fetch();
// A valid fixed-cost hash keeps unknown-account and wrong-password checks comparable.
$hash = $account['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
$valid = password_verify($password, $hash);
if (!$account || !$valid) respond(401, ['error' => 'Incorrect username/email or password.']);
if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
    mysql()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $account['id']]);
}
session_regenerate_id(true);
$_SESSION = ['user_id' => (string) $account['id'], 'created' => time(), 'last_active' => time(), 'csrf' => bin2hex(random_bytes(32))];
respond(200, ['message' => 'Signed in.', 'csrf' => $_SESSION['csrf']]);
