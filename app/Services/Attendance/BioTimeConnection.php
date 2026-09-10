<?php

namespace App\Services\Attendance;

use App\Models\Attendance\BiotimeSource;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a read-only connection to one BioTime SQL Server database.
 *
 * Connections are defined at runtime (`biotime_{id}`) from the BiotimeSource
 * row, because there are several BioTime databases and their credentials live
 * in that table — not in .env and not in config/database.php.
 *
 * The NOC only ever reads this one query, the one HR asked for:
 *   SELECT id, emp_code, punch_time, punch_state, terminal_sn, terminal_alias, area_alias
 *   FROM iclock_transaction
 * so the SQL login needs SELECT on that table and nothing else.
 */
class BioTimeConnection
{
    public const TABLE = 'iclock_transaction';

    public const COLUMNS = ['id', 'emp_code', 'punch_time', 'punch_state', 'terminal_sn', 'terminal_alias', 'area_alias'];

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
            // ODBC Driver 18 encrypts by default. BioTime's SQL Server usually
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

    /** The owner's query, unfiltered. */
    public function transactions(BiotimeSource $source): Builder
    {
        return $this->connection($source)->table(self::TABLE)->select(self::COLUMNS);
    }

    /**
     * Runs the ingest query with TOP 5 and reports what the Sources page shows.
     *
     * @return array{latency_ms: int, max_id: int, areas: list<string>, sample: list<array<string, mixed>>}
     */
    public function test(BiotimeSource $source): array
    {
        $started = microtime(true);
        $connection = $this->connection($source);

        $sample = $connection->table(self::TABLE)
            ->select(self::COLUMNS)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $latency = (int) round((microtime(true) - $started) * 1000);
        $maxId = (int) $connection->table(self::TABLE)->max('id');

        // Only recent rows: a DISTINCT over years of punches is a full scan.
        $areas = $connection->table(self::TABLE)
            ->where('id', '>', max(0, $maxId - 200000))
            ->whereNotNull('area_alias')
            ->distinct()
            ->orderBy('area_alias')
            ->limit(100)
            ->pluck('area_alias')
            ->map(fn ($a) => (string) $a)
            ->all();

        return [
            'latency_ms' => $latency,
            'max_id' => $maxId,
            'areas' => $areas,
            'sample' => $sample->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /**
     * A datetime literal SQL Server reads the same way whatever the login's
     * language: 'YYYYMMDD hh:mm:ss'. 'YYYY-MM-DD' is read as YYYY-DD-MM for a
     * DATETIME column under British/French DATEFORMAT.
     */
    public static function sqlDateTime(string $ymdHis): string
    {
        return str_replace('-', '', substr($ymdHis, 0, 10)).substr($ymdHis, 10);
    }

    /** The driver's own sentence, without the SQL Laravel appends to it. */
    public static function cleanError(\Throwable $e): string
    {
        $message = preg_replace('/\s*\(Connection: .*$/s', '', $e->getMessage()) ?? $e->getMessage();

        return mb_substr(trim($message), 0, 1000);
    }
}
