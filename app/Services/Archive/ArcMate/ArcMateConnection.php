<?php

namespace App\Services\Archive\ArcMate;

use App\Models\Archive\ArchiveSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A read-only connection to one ArcMate SQL Server database.
 *
 * Deliberately the same shape as Services\Attendance\BioTimeConnection, which
 * solves the identical problem for ZKTeco: connections are defined at runtime
 * from a row rather than living in config/database.php, because the credentials
 * belong in an encrypted column and there is more than one database.
 *
 * ArcMate gives every project its OWN database (AM7220130318_Invoices,
 * AM72_CESCopier, …), so a connection is per database rather than per server —
 * hence the name carries both.
 *
 * Nothing here ever writes. The login this uses is a dedicated read-only one
 * (db_datareader); ArcMate's own `sa` account, which sits in plain sight in
 * every project.config on the share, is never used and never read.
 */
class ArcMateConnection
{
    public static function driverAvailable(): bool
    {
        return extension_loaded('pdo_sqlsrv') && in_array('sqlsrv', \PDO::getAvailableDrivers(), true);
    }

    public function connection(ArchiveSource $source, string $database): ConnectionInterface
    {
        if (! self::driverAvailable()) {
            throw new RuntimeException(
                'The PHP SQL Server driver (pdo_sqlsrv) is not installed on this server. '
                .'It is the same driver the attendance sync uses — see deployment/biotime/install-sqlsrv.sh.'
            );
        }

        if (! $source->isConfigured()) {
            throw new RuntimeException('The ArcMate source has no host, username or password set yet.');
        }

        $database = trim($database);

        if ($database === '') {
            throw new RuntimeException('No ArcMate database was named for this archive.');
        }

        $name = $source->connectionName($database);

        config(["database.connections.{$name}" => [
            'driver' => 'sqlsrv',
            'host' => $source->host,
            'port' => $source->port ?: 1433,
            'database' => $database,
            'username' => $source->username,
            'password' => (string) $source->password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // ODBC Driver 18 encrypts by default, and this SQL Server has a
            // self-signed certificate like every other one on this network —
            // hence the per-source trust switch rather than a global one.
            'encrypt' => 'yes',
            'trust_server_certificate' => $source->trust_server_certificate ? 'true' : 'false',
            'login_timeout' => 10,
            'appname' => 'SG-NOC archive',
        ]]);

        // The credentials may have been edited since this process last connected.
        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * Whether this server can be reached at all, and what went wrong if not.
     *
     * @return array{ok:bool, error:?string, databases:array<int,string>}
     */
    public function test(ArchiveSource $source): array
    {
        try {
            // master is the one database that is always there, so the test says
            // "the login works" rather than "this project happens to exist".
            $connection = $this->connection($source, 'master');

            $databases = collect($connection->select(
                "SELECT name FROM sys.databases WHERE name LIKE 'AM72%' ORDER BY name"
            ))->map(fn ($row) => (string) ($row->name ?? ''))->filter()->values()->all();

            return ['ok' => true, 'error' => null, 'databases' => $databases];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::cleanError($e), 'databases' => []];
        }
    }

    /** The driver's own sentence, without the SQL Laravel appends to it. */
    public static function cleanError(\Throwable $e): string
    {
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim($message), 0, 1000);
    }
}
