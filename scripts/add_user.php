<?php
// add_user.php - Upsert a user into the Laravel SQLite database with a bcrypt-hashed password.
// Usage (PowerShell):
//   $env:PASSWORD = "{{USER_PASSWORD}}"
//   php add_user.php --firstname "aidpoint" --lastname "admin" --email "aidpoint4@gmail.com" --systemrole 1

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

$firstname = argv_option('firstname');
$lastname = argv_option('lastname');
$email = argv_option('email');
$systemrole = (int) argv_option('systemrole', 1);
$password = getenv('PASSWORD');

if (!$firstname || !$lastname || !$email) {
    fwrite(STDERR, "Missing required args --firstname, --lastname, --email\n");
    exit(1);
}
if ($password === false || $password === '') {
    fwrite(STDERR, "PASSWORD environment variable is required\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
if ($hash === false) {
    fwrite(STDERR, "Failed to hash password\n");
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
    $stmt = $pdo->prepare('SELECT id, systemrole_id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    $emailVerifiedAt = ($systemrole === 1) ? $now : null;

    if ($row) {
        $stmt = $pdo->prepare('UPDATE users SET firstname = :firstname, lastname = :lastname, password = :password, systemrole_id = :role, updated_at = :updated_at'
            . ($systemrole === 1 ? ', email_verified_at = :email_verified_at, email_verification_code = NULL, email_verification_code_expires_at = NULL' : '')
            . ' WHERE id = :id');
        $params = [
            ':firstname' => $firstname,
            ':lastname' => $lastname,
            ':password' => $hash,
            ':role' => $systemrole,
            ':updated_at' => $now,
            ':id' => $row['id'],
        ];
        if ($systemrole === 1) {
            $params[':email_verified_at'] = $emailVerifiedAt;
        }
        $stmt->execute($params);
        $userId = (int) $row['id'];
        $action = 'updated';
    } else {
        if ($systemrole === 1) {
            $stmt = $pdo->prepare('INSERT INTO users (firstname, lastname, email, password, systemrole_id, status, email_verified_at, email_verification_code, email_verification_code_expires_at, created_at, updated_at) VALUES (:firstname, :lastname, :email, :password, :role, :status, :email_verified_at, NULL, NULL, :created_at, :updated_at)');
            $stmt->execute([
                ':firstname' => $firstname,
                ':lastname' => $lastname,
                ':email' => $email,
                ':password' => $hash,
                ':role' => $systemrole,
                ':status' => 'active',
                ':email_verified_at' => $emailVerifiedAt,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (firstname, lastname, email, password, systemrole_id, status, created_at, updated_at) VALUES (:firstname, :lastname, :email, :password, :role, :status, :created_at, :updated_at)');
            $stmt->execute([
                ':firstname' => $firstname,
                ':lastname' => $lastname,
                ':email' => $email,
                ':password' => $hash,
                ':role' => $systemrole,
                ':status' => 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $userId = (int) $pdo->lastInsertId();
        $action = 'inserted';
    }

    $pdo->commit();
    echo "User $action: id={$userId}, email={$email}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
