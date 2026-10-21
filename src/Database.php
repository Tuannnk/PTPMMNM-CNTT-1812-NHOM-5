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

        $configPath = __DIR__ . '/../.local/db.json';
        if (!is_file($configPath)) {
            throw new RuntimeException('Chưa cấu hình PostgreSQL. Chạy: php tools/db-config.php');
        }
        $config = json_decode((string)file_get_contents($configPath), true);
        if (!is_array($config)) {
            throw new RuntimeException('Cấu hình PostgreSQL không đọc được. Chạy lại: php tools/db-config.php');
        }

        $required = ['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'];
        foreach ($required as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new RuntimeException('Thiếu cấu hình ' . $key . '. Chạy lại: php tools/db-config.php');
            }
        }
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('Thiếu PHP PDO PostgreSQL. Cài gói php-pgsql.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['PGHOST'],
            $config['PGPORT'],
            $config['PGDATABASE']
        );
        self::$connection = new PDO($dsn, $config['PGUSER'], $config['PGPASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$connection;
    }
}
