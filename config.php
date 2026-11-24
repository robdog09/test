<?php
declare(strict_types=1);

/**
 * Database connection.
 *
 * Set DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS as environment variables,
 * or just edit the fallback values below.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = getenv('DB_DSN') ?: sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '192.168.2.13',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'baseball_team'
    );
    $user = getenv('DB_USER') ?: 'baseball';
    $pass = getenv('DB_PASS') ?: 'baseballftw!';

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Could not connect to the database. Check the DB_* settings in config.php.');
    }
    return $pdo;
}
