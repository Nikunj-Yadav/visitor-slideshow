<?php
/** MySQL connection (PDO) and a transaction helper. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', cfg('db_host'), cfg('db_port'), cfg('db_name'));
    $pdo = new PDO($dsn, (string) cfg('db_user'), (string) cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,   // real prepared statements: values are never pasted into SQL
    ]);
    $pdo->exec("SET time_zone = '+00:00'");     // store and compare all times in UTC
    return $pdo;
}

/**
 * Run $work inside a transaction. Each statement sees the latest committed data
 * (READ COMMITTED). If MySQL reports a deadlock, the work is simply tried again.
 */
function with_transaction(callable $work)
{
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $deadlock = $e instanceof PDOException && in_array((string) $e->getCode(), ['40001', '1213'], true);
            if ($deadlock && $attempt < 3) { usleep(random_int(20000, 80000)); continue; }
            throw $e;
        }
    }
}
