<?php
/**
 * Lazy PDO singleton. Reads creds from /etc/sg-noc-branch.env via Env.
 * Uses persistent connections so PHP-FPM workers don't pay the connect
 * cost on every request.
 */

declare(strict_types=1);

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $name = Env::get('DB_NAME', 'sg_noc_branch');
        $user = Env::get('DB_USER', 'sg_noc');
        $pass = Env::get('DB_PASSWORD', '');

        $dsn = "mysql:host=127.0.0.1;dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
            PDO::ATTR_PERSISTENT          => true,
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET time_zone='+00:00'",
        ]);
        return self::$pdo;
    }
}
