<?php
/** Allow at most $limit requests per 15 minutes per IP address for a named bucket. */
function rate_limit(string $bucket, int $limit): void
{
    $pdo = db();
    $ip = client_ip();
    if (random_int(1, 50) === 1) {   // occasional tidy-up of old rows
        $pdo->exec('DELETE FROM rate_limit_hits WHERE hit_at < (NOW() - INTERVAL 1 DAY)');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND ip = ? AND hit_at > (NOW() - INTERVAL 900 SECOND)');
    $stmt->execute([$bucket, $ip]);
    if ((int) $stmt->fetchColumn() >= $limit) {
        header('Retry-After: 900');
        fail(429, 'Too many requests. Please wait a few minutes and try again.');
    }
    $pdo->prepare('INSERT INTO rate_limit_hits (bucket, ip) VALUES (?, ?)')->execute([$bucket, $ip]);
}
