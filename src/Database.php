<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = self::environmentConfig() ?? self::localConfig();

        $required = ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'];
        foreach ($required as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new RuntimeException('Thiếu cấu hình ' . $key . '. Chạy lại: php tools/db-config.php');
            }
        }
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('Thiếu PHP PDO PostgreSQL. Cài gói php-pgsql.');
        }

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['PGHOST'], $config['PGPORT'], $config['PGDATABASE']);
        if (($config['PGSSLMODE'] ?? '') !== '') {
            $dsn .= ';sslmode=' . $config['PGSSLMODE'];
        }
        $emulatePrepares = filter_var(getenv('PG_EMULATE_PREPARES') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        self::$connection = new PDO($dsn, $config['PGUSER'], $config['PGPASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $emulatePrepares,
        ]);
        return self::$connection;
    }

    private static function environmentConfig(): ?array
    {
        $url = getenv('DATABASE_URL');
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host'], $parts['path'], $parts['user'], $parts['pass'])) {
                throw new RuntimeException('DATABASE_URL không đúng định dạng PostgreSQL.');
            }
            return [
                'PGHOST' => $parts['host'],
                'PGPORT' => (string)($parts['port'] ?? 5432),
                'PGDATABASE' => ltrim($parts['path'], '/'),
                'PGUSER' => rawurldecode($parts['user']),
                'PGPASSWORD' => rawurldecode($parts['pass']),
                'PGSSLMODE' => 'require',
            ];
        }

        $host = getenv('PGHOST');
        if (!is_string($host) || $host === '') {
            return null;
        }
        return [
            'PGHOST' => $host,
            'PGPORT' => (string)(getenv('PGPORT') ?: '5432'),
            'PGDATABASE' => (string)(getenv('PGDATABASE') ?: ''),
            'PGUSER' => (string)(getenv('PGUSER') ?: ''),
            'PGPASSWORD' => (string)(getenv('PGPASSWORD') ?: ''),
            'PGSSLMODE' => (string)(getenv('PGSSLMODE') ?: 'require'),
        ];
    }

    private static function localConfig(): array
    {
        $configPath = __DIR__ . '/../.local/db.json';
        if (!is_file($configPath)) {
            throw new RuntimeException('Chưa cấu hình PostgreSQL. Đặt DATABASE_URL hoặc chạy: php tools/db-config.php');
        }
        $config = json_decode((string)file_get_contents($configPath), true);
        if (!is_array($config)) {
            throw new RuntimeException('Cấu hình PostgreSQL không đọc được. Chạy lại: php tools/db-config.php');
        }
        return $config;
    }
}
