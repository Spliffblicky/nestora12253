<?php
declare(strict_types=1);

/**
 * DATABASE CONNECTOR (the only file that knows how to reach MySQL/MariaDB).
 * Every other file calls db(), q(), row(), rows(), val() or tx() from here.
 * Credentials come from backend/.env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        cfg('DB_HOST', '127.0.0.1'),
        cfg('DB_PORT', '3306'),
        cfg('DB_NAME', 'realestate_app')
    );
    $pdo = new PDO($dsn, (string)cfg('DB_USER', 'root'), (string)cfg('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Run a prepared statement with positional (?) parameters. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

/** First row or null. */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** All rows. */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** First column of the first row, or null. */
function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

function insert_id(): int
{
    return (int)db()->lastInsertId();
}

/** "?, ?, ?" placeholders for IN (...) lists. */
function marks(array $items): string
{
    return implode(',', array_fill(0, max(1, count($items)), '?'));
}

/** Run $fn inside a transaction; rolls back and rethrows on error. */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $out = $fn();
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
