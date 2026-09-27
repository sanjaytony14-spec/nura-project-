<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function respond(int $status, array $data): never {
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        $status = 503;
        $data = ['error' => 'Session storage is unavailable. Please try again.'];
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(function (Throwable $error): void {
    // Do not log credentials, request bodies, connection strings or personal data.
    error_log('API failure: ' . get_class($error));
    respond(503, ['error' => 'Service temporarily unavailable. Please try again shortly.']);
});

function env(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function method(string ...$allowed): string {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(405, ['error' => 'Method not allowed.']);
    }
    return $method;
}

function input(): array {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        respond(415, ['error' => 'Send an application/json request.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) respond(413, ['error' => 'Request is too large.']);
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { respond(400, ['error' => 'Invalid JSON request.']); }
    if (!is_array($data) || array_is_list($data)) respond(400, ['error' => 'Expected a JSON object.']);
    return $data;
}

function field(array $data, string $key): string {
    if (!isset($data[$key]) || !is_string($data[$key])) respond(422, ['error' => 'Please check the ' . $key . ' field.']);
    return trim($data[$key]);
}

function mysql(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $db = new PDO('mysql:host=' . env('MYSQL_HOST', 'mysql') . ';dbname=' . env('MYSQL_DATABASE', 'nura') . ';charset=utf8mb4', env('MYSQL_USER'), env('MYSQL_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_username (username),
        UNIQUE KEY uq_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return $db;
}

function redis(): Redis {
    static $db;
    if (!$db) {
        $db = new Redis();
        $db->connect(env('REDIS_HOST', 'redis'), (int) env('REDIS_PORT', '6379'), 3);
        if (env('REDIS_PASSWORD') !== '') $db->auth(env('REDIS_PASSWORD'));
    }
    return $db;
}

function mongo(): MongoDB\Driver\Manager {
    static $db;
    return $db ??= new MongoDB\Driver\Manager(env('MONGO_URI'), ['serverSelectionTimeoutMS' => 3000]);
}

function profileNamespace(): string { return env('MONGO_DATABASE', 'nura') . '.profiles'; }

function rateLimit(string $scope, int $limit): void {
    // REMOTE_ADDR cannot be forged with an arbitrary X-Forwarded-For header.
    $key = 'rate:' . $scope . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $count = redis()->eval("local n = redis.call('INCR', KEYS[1]); if n == 1 then redis.call('EXPIRE', KEYS[1], 900) end; return n", [$key], 1);
    if ($count > $limit) {
        header('Retry-After: ' . max(1, redis()->ttl($key)));
        respond(429, ['error' => 'Too many attempts. Please try again in 15 minutes.']);
    }
}

function sessionStart(): void {
    if (env('APP_ENV') === 'production' && (env('COOKIE_SECURE') !== '1' || !str_starts_with(env('APP_ORIGIN'), 'https://'))) {
        throw new RuntimeException('Production requires HTTPS and secure cookies');
    }
    $host = env('REDIS_HOST', 'redis');
    $port = env('REDIS_PORT', '6379');
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', 'tcp://' . $host . ':' . $port . '?auth=' . rawurlencode(env('REDIS_PASSWORD')) . '&prefix=nura_session:');
    ini_set('session.gc_maxlifetime', '1800');
    ini_set('redis.session.locking_enabled', '1');
    ini_set('redis.session.lock_retries', '100');
    ini_set('redis.session.lock_wait_time', '20000');
    session_name('nura_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => env('COOKIE_SECURE') === '1', 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new RuntimeException('Session storage unavailable');
    if ((isset($_SESSION['created']) && time() - $_SESSION['created'] >= 43200)
        || (isset($_SESSION['last_active']) && time() - $_SESSION['last_active'] >= 1800)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $_SESSION['last_active'] = time();
}

function csrf(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== env('APP_ORIGIN', 'http://localhost:8080')) respond(403, ['error' => 'Request origin is not allowed.']);
    if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) respond(403, ['error' => 'Your session changed. Refresh the page and try again.']);
}

function user(): array {
    if (!isset($_SESSION['user_id'])) respond(401, ['error' => 'Please sign in to continue.']);
    $stmt = mysql()->prepare('SELECT id, username, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['created']);
        respond(401, ['error' => 'Please sign in to continue.']);
    }
    return $user;
}
