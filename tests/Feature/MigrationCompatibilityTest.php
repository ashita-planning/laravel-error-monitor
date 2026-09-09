<?php

declare(strict_types=1);

namespace Apkk\LaravelErrorMonitor\Tests\Feature;

use Apkk\LaravelErrorMonitor\Tests\TestCase;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

final class MigrationCompatibilityTest extends TestCase
{
    private const CREATE_MIGRATION = __DIR__.'/../../database/migrations/2026_08_03_000000_create_error_monitor_events_table.php';

    private const FORWARD_MIGRATION = __DIR__.'/../../database/migrations/2026_09_09_000000_make_error_monitor_event_timestamps_nullable.php';

    #[Test]
    public function fresh_install_uses_explicitly_nullable_timestamp_columns(): void
    {
        $callback = $this->migrationCallback('create', self::CREATE_MIGRATION);
        $columns = $this->columnsDefinedBy($callback);

        $this->assertSame('timestamp', $columns['first_occurred_at']->get('type'));
        $this->assertTrue($columns['first_occurred_at']->get('nullable'));
        $this->assertSame('timestamp', $columns['last_occurred_at']->get('type'));
        $this->assertTrue($columns['last_occurred_at']->get('nullable'));

        $sql = $this->compileCreateSqlForMySql($callback);

        $this->assertStringContainsString('`first_occurred_at` timestamp null', $sql);
        $this->assertStringContainsString('`last_occurred_at` timestamp null', $sql);
        $this->assertStringNotContainsString('0000-00-00 00:00:00', $sql);
    }

    #[Test]
    public function existing_mysql_install_changes_both_timestamps_to_nullable(): void
    {
        $callback = $this->migrationCallback('table', self::FORWARD_MIGRATION, 'mysql');
        $columns = $this->columnsDefinedBy($callback);

        $this->assertSame('timestamp', $columns['first_occurred_at']->get('type'));
        $this->assertTrue($columns['first_occurred_at']->get('nullable'));
        $this->assertTrue($columns['first_occurred_at']->get('change'));
        $this->assertSame('timestamp', $columns['last_occurred_at']->get('type'));
        $this->assertTrue($columns['last_occurred_at']->get('nullable'));
        $this->assertTrue($columns['last_occurred_at']->get('change'));
    }

    #[Test]
    public function forward_migration_skips_non_mysql_databases(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');

        Schema::shouldReceive('getConnection')->once()->andReturn($connection);
        Schema::shouldReceive('table')->never();

        $migration = require self::FORWARD_MIGRATION;
        $migration->up();
    }

    private function migrationCallback(string $schemaMethod, string $migrationPath, ?string $driver = null): Closure
    {
        if ($driver !== null) {
            $connection = \Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
            Schema::shouldReceive('getConnection')->once()->andReturn($connection);
        }

        $callback = null;

        Schema::shouldReceive($schemaMethod)
            ->once()
            ->with('error_monitor_events', \Mockery::on(function (Closure $candidate) use (&$callback): bool {
                $callback = $candidate;

                return true;
            }));

        $migration = require $migrationPath;
        $migration->up();

        $this->assertInstanceOf(Closure::class, $callback);

        return $callback;
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    private function columnsDefinedBy(Closure $callback): array
    {
        $columns = [];
        $blueprint = $this->blueprint($this->app['db']->connection(), $callback);

        foreach ($blueprint->getColumns() as $column) {
            $columns[$column->name] = $column;
        }

        return $columns;
    }

    private function compileCreateSqlForMySql(Closure $callback): string
    {
        $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'testing');
        $connection->useDefaultSchemaGrammar();

        $blueprint = $this->blueprint($connection);
        $blueprint->create();
        $callback($blueprint);

        $toSql = new ReflectionMethod(Blueprint::class, 'toSql');
        $arguments = $toSql->getNumberOfRequiredParameters() === 0
            ? []
            : [$connection, $connection->getSchemaGrammar()];

        /** @var array<int, string> $sql */
        $sql = $toSql->invokeArgs($blueprint, $arguments);

        return implode("\n", $sql);
    }

    private function blueprint(Connection $connection, ?Closure $callback = null): Blueprint
    {
        $constructor = new ReflectionMethod(Blueprint::class, '__construct');
        $arguments = $constructor->getParameters()[0]->getName() === 'connection'
            ? [$connection, 'error_monitor_events', $callback]
            : ['error_monitor_events', $callback];

        /** @var Blueprint $blueprint */
        $blueprint = (new ReflectionClass(Blueprint::class))->newInstanceArgs($arguments);

        return $blueprint;
    }
}
