<?php
// scripts/ensure_mysql_database.php
// Create the MySQL database from values in server/.env if it doesn't exist.

function parseEnv(string $path): array {
    if (!is_file($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*#/', $line)) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        // Strip surrounding quotes
        if ($val !== '' && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        $env[$key] = $val;
    }
    return $env;
}

$root = __DIR__ . DIRECTORY_SEPARATOR . '..';
$envPath = realpath($root . DIRECTORY_SEPARATOR . '.env') ?: ($root . DIRECTORY_SEPARATOR . '.env');
$env = parseEnv($envPath);

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';
$db   = $env['DB_DATABASE'] ?? 'aidpoint';

if (!$db) {
    fwrite(STDERR, "DB_DATABASE is empty\n");
    exit(1);
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database '{$db}' ensured.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to ensure database: " . $e->getMessage() . "\n");
    exit(1);
}
