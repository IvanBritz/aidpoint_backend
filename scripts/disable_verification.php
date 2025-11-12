<?php
// disable_verification.php - Clear verification requirements for a specific user (by email)
// Usage (PowerShell):
//   php disable_verification.php --email "user@example.com"

ini_set('display_errors', '1');
error_reporting(E_ALL);

function argv_option(string $name, $default = null) {
    $key = "--$name";
    $args = $_SERVER['argv'] ?? [];
    foreach ($args as $i => $arg) {
        if ($arg === $key && isset($args[$i+1])) return $args[$i+1];
        if (str_starts_with($arg, $key.'=')) return substr($arg, strlen($key) + 1);
    }
    return $default;
}

$email = argv_option('email');
if (!$email) {
    fwrite(STDERR, "Missing required arg --email\n");
    exit(1);
}

$dbPath = __DIR__ . '/../database/database.sqlite';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Database file not found: $dbPath\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('PRAGMA foreign_keys = ON');
$now = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('UPDATE users SET 
        email_verified_at = COALESCE(email_verified_at, :now),
        email_verification_code = NULL,
        email_verification_code_expires_at = NULL,
        requires_login_verification = 0,
        login_verification_code = NULL,
        login_verification_code_expires_at = NULL,
        is_first_login = 1,
        updated_at = :now
      WHERE email = :email');

    $stmt->execute([
        ':now' => $now,
        ':email' => $email,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('User not found');
    }

    $pdo->commit();
    echo "Verification disabled for {$email}.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
