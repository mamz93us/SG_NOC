<?php

namespace App\Services\Attendance;

use App\Models\Attendance\BiotimeSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a read-only connection to one ZKTeco SQL Server database.
 *
 * Connections are defined at runtime (`biotime_{id}`) from the BiotimeSource
 * row, because there are several databases and their credentials live in that
 * table — not in .env and not in config/database.php.
 *
 * What is read from it is the source type's business: see Readers\.
 */
class BioTimeConnection
{
    public static function driverAvailable(): bool
    {
        return extension_loaded('pdo_sqlsrv') && in_array('sqlsrv', \PDO::getAvailableDrivers(), true);
    }

    public function connection(BiotimeSource $source): ConnectionInterface
    {
        if (! self::driverAvailable()) {
            throw new RuntimeException(
                'The PHP SQL Server driver (pdo_sqlsrv) is not installed on this server. '
                .'Run deployment/biotime/install-sqlsrv.sh — see BIOTIME_ATTENDANCE_SETUP.md.'
            );
        }

        $name = $source->connectionName();

        config(["database.connections.{$name}" => [
            'driver' => 'sqlsrv',
            'host' => $source->host,
            'port' => $source->port ?: 1433,
            'database' => $source->database,
            'username' => $source->username,
            'password' => (string) $source->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // ODBC Driver 18 encrypts by default. ZKTeco's SQL Server usually
            // has a self-signed certificate, hence the per-source trust switch.
            'encrypt' => 'yes',
            'trust_server_certificate' => $source->trust_server_certificate ? 'true' : 'false',
            'login_timeout' => 10,
            'appname' => 'SG-NOC attendance',
        ]]);

        // The credentials may have been edited since this process last connected.
        DB::purge($name);

        return DB::connection($name);
    }

    /** The driver's own sentence, without the SQL Laravel appends to it. */
    public static function cleanError(\Throwable $e): string
    {
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim($message), 0, 1000);
    }
}
