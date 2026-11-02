<?php
declare(strict_types=1);

/** File-backed, multi-process throttle keyed by remote address and username. */
final class LoginThrottle
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS = 8;
    private const MAX_KEYS = 4096;

    /** Atomically records an attempt, returning retry seconds when the key is blocked. */
    public static function begin(string $remoteAddress, string $username): int
    {
        $key = hash('sha256', $remoteAddress . "\0" . strtolower(trim($username)));
        $now = time();
        return self::updateStore(static function (array &$store) use ($key, $now): int {
            self::prune($store, $now);
            $attempts = $store[$key] ?? [];
            if (count($attempts) >= self::MAX_ATTEMPTS) {
                return max(1, self::WINDOW_SECONDS - ($now - (int)$attempts[0]));
            }
            $attempts[] = $now;
            $store[$key] = $attempts;
            if (count($store) > self::MAX_KEYS) {
                uasort($store, static fn(array $a, array $b): int => ($a[count($a) - 1] ?? 0) <=> ($b[count($b) - 1] ?? 0));
                $store = array_slice($store, -self::MAX_KEYS, null, true);
            }
            return 0;
        });
    }

    public static function clear(string $remoteAddress, string $username): void
    {
        $key = hash('sha256', $remoteAddress . "\0" . strtolower(trim($username)));
        self::updateStore(static function (array &$store) use ($key): void {
            unset($store[$key]);
        });
    }

    private static function prune(array &$store, int $now): void
    {
        foreach ($store as $key => $timestamps) {
            $timestamps = array_values(array_filter(
                is_array($timestamps) ? $timestamps : [],
                static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - self::WINDOW_SECONDS
            ));
            if (!$timestamps) unset($store[$key]);
            else $store[$key] = $timestamps;
        }
    }

    private static function updateStore(callable $callback): mixed
    {
        $directory = __DIR__ . '/../.local';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('LOGIN_THROTTLE_STORAGE_UNAVAILABLE');
        }
        @chmod($directory, 0700);
        $path = $directory . '/login-throttle.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('LOGIN_THROTTLE_STORAGE_UNAVAILABLE');
        @chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('LOGIN_THROTTLE_LOCK_UNAVAILABLE');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $store = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($store)) $store = [];
            $result = $callback($store);
            $encoded = json_encode($store, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('LOGIN_THROTTLE_WRITE_FAILED');
            }
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }
}
