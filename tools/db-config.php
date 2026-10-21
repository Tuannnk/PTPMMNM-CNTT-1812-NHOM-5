<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Thieu PDO PostgreSQL. Cai goi php-pgsql.\n");
    exit(2);
}
if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Hay chay trong terminal tuong tac.\n");
    exit(2);
}

function ask(string $label, string $default): string
{
    fwrite(STDOUT, $label . ' [' . $default . ']: ');
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Khong doc duoc cau hinh.');
    }
    $value = trim($value);
    return $value === '' ? $default : $value;
}

try {
    $config = [
        'PGHOST' => ask('May chu PostgreSQL', '127.0.0.1'),
        'PGPORT' => ask('Cong', '5432'),
        'PGDATABASE' => ask('Ten database', 'ptpmmnm_cntt_1812_nhom_5'),
        'PGUSER' => ask('Tai khoan database', 'ptpmmnm_cntt_1812_nhom_5'),
    ];
    if (!ctype_digit($config['PGPORT']) || (int)$config['PGPORT'] < 1 || (int)$config['PGPORT'] > 65535) {
        throw new RuntimeException('Cong PostgreSQL khong hop le.');
    }

    fwrite(STDOUT, 'Mat khau database (khong hien thi): ');
    exec('stty -echo', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Khong the an mat khau trong terminal.');
    }
    try {
        $password = fgets(STDIN);
    } finally {
        exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    if ($password === false || rtrim($password, "\r\n") === '') {
        throw new RuntimeException('Mat khau khong duoc de trong.');
    }
    $config['PGPASSWORD'] = rtrim($password, "\r\n");

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['PGHOST'], $config['PGPORT'], $config['PGDATABASE']);
    $db = new PDO($dsn, $config['PGUSER'], $config['PGPASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $check = $db->query('SELECT current_database(), current_user')->fetch(PDO::FETCH_NUM);

    $directory = __DIR__ . '/../.local';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Khong tao duoc thu muc .local.');
    }
    chmod($directory, 0700);
    $path = $directory . '/db.json';
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Khong ghi duoc cau hinh database.');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        throw new RuntimeException('Khong luu duoc cau hinh database.');
    }
    fwrite(STDOUT, 'Ket noi thanh cong: ' . $check[0] . ' / ' . $check[1] . "\n");
    fwrite(STDOUT, "Cau hinh da luu trong .local/db.json (khong dua len Git).\n");
} catch (Throwable $error) {
    @unlink(__DIR__ . '/../.local/db.json.tmp');
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
