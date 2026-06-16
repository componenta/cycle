<?php

declare(strict_types=1);

namespace Componenta\Cycle\Factory;

use Componenta\Cycle\Exception\DatabaseConfigException;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\TcpConnectionConfig as MySQLTcpConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig as PostgresTcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Config\SQLServer\TcpConnectionConfig as SQLServerTcpConnectionConfig;
use Cycle\Database\Config\SQLServerDriverConfig;
use function Componenta\Config\env;

class DatabaseConfigFactory
{
    public function __invoke(): DatabaseConfig
    {
        $driver = env('DB_DRIVER');

        return new DatabaseConfig([
            'default' => 'default',
            'databases' => [
                'default' => [
                    'connection' => $driver,
                ],
            ],
            'connections' => [
                $driver => $this->createDriverConfig($driver),
            ],
        ]);
    }

    private function createDriverConfig(string $driver): SQLiteDriverConfig|MySQLDriverConfig|PostgresDriverConfig|SQLServerDriverConfig
    {
        return match ($driver) {
            'sqlite' => $this->createSQLiteDriverConfig(),
            'mysql' => $this->createMySQLDriverConfig(),
            'postgres', 'pgsql' => $this->createPostgresDriverConfig(),
            'sqlserver', 'mssql' => $this->createSQLServerDriverConfig(),
            default => throw DatabaseConfigException::unsupportedDriver($driver),
        };
    }

    private function createSQLiteDriverConfig(): SQLiteDriverConfig
    {
        $database = env('DB_NAME');

        return new SQLiteDriverConfig(
            connection: $database === ':memory:'
                ? new MemoryConnectionConfig()
                : new FileConnectionConfig(database: $database),
            queryCache: true,
        );
    }

    private function createMySQLDriverConfig(): MySQLDriverConfig
    {
        return new MySQLDriverConfig(
            connection: new MySQLTcpConnectionConfig(
                database: env('DB_NAME'),
                host: env('DB_HOST', '127.0.0.1'),
                port: (int) env('DB_PORT', 3306),
                user: env('DB_USER'),
                password: env('DB_PASSWORD', ''),
            ),
            queryCache: true,
        );
    }

    private function createPostgresDriverConfig(): PostgresDriverConfig
    {
        return new PostgresDriverConfig(
            connection: new PostgresTcpConnectionConfig(
                database: env('DB_NAME'),
                host: env('DB_HOST', '127.0.0.1'),
                port: (int) env('DB_PORT', 5432),
                user: env('DB_USER'),
                password: env('DB_PASSWORD', ''),
            ),
            schema: env('DB_SCHEMA', 'public'),
            queryCache: true,
        );
    }

    private function createSQLServerDriverConfig(): SQLServerDriverConfig
    {
        return new SQLServerDriverConfig(
            connection: new SQLServerTcpConnectionConfig(
                database: env('DB_NAME'),
                host: env('DB_HOST', '127.0.0.1'),
                port: (int) env('DB_PORT', 1433),
                user: env('DB_USER'),
                password: env('DB_PASSWORD', ''),
            ),
            queryCache: true,
        );
    }
}