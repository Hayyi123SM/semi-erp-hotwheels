<?php

namespace Tests\Mysql;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The base for tests that only mean something on MySQL.
 *
 * The rest of the suite runs on SQLite, which is fast and needs nothing set up.
 * That is also why a bug can pass it: SQLite and MySQL disagree about how a
 * `LIKE` escape character is read, and the disagreement is invisible until a
 * query is run on the engine production actually uses. These tests exist to be
 * that second opinion.
 *
 * Every test here drops and rebuilds the schema. So the connection is checked
 * before anything is destroyed, and a database whose name does not announce
 * itself as a test database is refused outright rather than run against.
 */
abstract class MySqlTestCase extends TestCase
{
    use RefreshDatabase;

    /** Only a database set aside for this may be wiped. */
    private const NAME_MARKER = 'test';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('MYSQL_TEST_READY') !== '1') {
            $this->markTestSkipped(
                'No MySQL test connection. Copy .env.mysql-testing.example to '.
                '.env.mysql-testing and point DB_DATABASE at a database meant to '.
                'be thrown away.'
            );
        }

        $this->refuseAnythingButATestDatabase();
    }

    /**
     * The last thing between a mistyped variable and an afternoon of restoring
     * the application's own data.
     */
    private function refuseAnythingButATestDatabase(): void
    {
        $database = (string) config('database.connections.mysql.database');

        if (! str_contains(mb_strtolower($database), self::NAME_MARKER)) {
            throw new RuntimeException(sprintf(
                'Refusing to run: DB_DATABASE is "%s", and this suite drops every '.
                'table in it. Its name has to contain "%s".',
                $database,
                self::NAME_MARKER,
            ));
        }
    }

    /** The engine, as the connection reports it, for a failure message. */
    protected function engine(): string
    {
        return (string) DB::selectOne('select version() as version')->version;
    }
}
