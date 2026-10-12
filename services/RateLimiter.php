<?php
/**
 * Database-backed rate limits for the public, unauthenticated endpoints
 * (sign-in, registration, forgot password, public status lookup), so they
 * can't be used for password spraying, SMS bombing or enumerating
 * applications. Hits live in rate_limit_hits and are pruned after a day.
 */
class RateLimiter
{
    /** rule => [max hits, window in seconds] */
    public const RULES = [
        'login_ip' => [50, 900],        // failed sign-ins from one IP (any accounts), per 15 minutes
        'register_ip' => [10, 3600],    // new accounts from one IP, per hour
        'reset_ip' => [10, 3600],       // forgot-password requests from one IP, per hour
        'reset_account' => [3, 900],    // reset SMS to one account, per 15 minutes (a code lasts 15 minutes)
        'status_ip' => [10, 900],       // failed public status lookups from one IP, per 15 minutes
        'mobile_code' => [3, 900],      // verification-code SMS to one account, per 15 minutes
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    /** True if $rule's limit for $key is already reached. */
    public function tooMany(string $rule, string $key): bool
    {
        return $this->count($rule, $key) >= self::RULES[$rule][0];
    }

    /** Records one hit; returns the number of hits now in the window (so a caller can act once, when the limit is first reached). */
    public function hit(string $rule, string $key): int
    {
        $this->pdo->prepare('DELETE FROM rate_limit_hits WHERE hit_at < NOW() - INTERVAL 1 DAY')->execute();
        $this->pdo->prepare('INSERT INTO rate_limit_hits (bucket, hit_at) VALUES (?, NOW())')->execute([$this->bucket($rule, $key)]);
        return $this->count($rule, $key);
    }

    private function count(string $rule, string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND hit_at > NOW() - INTERVAL ? SECOND');
        $stmt->execute([$this->bucket($rule, $key), self::RULES[$rule][1]]);
        return (int) $stmt->fetchColumn();
    }

    private function bucket(string $rule, string $key): string
    {
        return $rule . ':' . substr(hash('sha256', $key), 0, 40);
    }
}
