<?php
// scripts/mysql_drop_tables.php
// Drop selected tables if they exist in the configured MySQL database.

function parseEnv(string $path): array {
    if (!is_file($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*#/', $line)) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
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
$db   = $env['DB_DATABASE'] ?? '';

if (!$db) { fwrite(STDERR, "DB_DATABASE is empty\n"); exit(1);} 

$tables = $argv; array_shift($tables); // remove script name
if (empty($tables)) { $tables = ['users','password_reset_tokens','sessions']; }

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `".$t."`");
        echo "Dropped table if existed: {$t}\n";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed: ".$e->getMessage()."\n");
    exit(1);
}
