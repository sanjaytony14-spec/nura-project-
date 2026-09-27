<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
method('POST');
sessionStart();
csrf();
rateLimit('register', 10);
$data = input();
$username = field($data, 'username');
$email = strtolower(field($data, 'email'));
$password = $data['password'] ?? null;
if (!preg_match('/^[A-Za-z0-9_]{3,32}$/D', $username)) respond(422, ['error' => 'Username must contain 3-32 letters, numbers or underscores.']);
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) respond(422, ['error' => 'Enter a valid email address.']);
if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) respond(422, ['error' => 'Password must be 12-72 bytes long and contain no null characters.']);
try {
    $stmt = mysql()->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') respond(409, ['error' => 'That username or email is already registered. Please sign in or choose another.']);
    throw $error;
}
// Profiles are created on first save, avoiding a non-atomic write across two databases.
respond(201, ['message' => 'Account created. You can now sign in.']);
