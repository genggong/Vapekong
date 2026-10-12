<?php

class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        if (self::$instance !== null) return self::$instance;

        $config = require __DIR__ . '/../Config/database.php';
        $host = trim((string)($config['host'] ?? 'localhost')) ?: 'localhost';
        $port = (int)($config['port'] ?? 3306);
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $hosts = [$host];
        // Beberapa shared-hosting tidak menyediakan mysql.sock untuk PHP-FPM.
        // Paksa fallback TCP agar halaman tertentu tidak gagal sementara halaman lain normal.
        if (strtolower($host) === 'localhost') $hosts[] = '127.0.0.1';
        if ($host === '127.0.0.1') $hosts[] = 'localhost';
        $lastError = null;

        foreach (array_values(array_unique($hosts)) as $tryHost) {
            try {
                $dsn = "mysql:host={$tryHost};port={$port};dbname={$config['database']};charset={$charset}";
                self::$instance = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 8,
                ]);
                return self::$instance;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }

        $debug = function_exists('app_config') ? (bool)app_config('debug', false) : false;
        http_response_code(500);
        if ($debug && $lastError) {
            die('Koneksi database gagal: ' . htmlspecialchars($lastError->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        die('Koneksi database gagal. Sistem sudah mencoba koneksi socket dan TCP. Silakan buka Database & System Doctor atau periksa host database di konfigurasi.');
    }
}
