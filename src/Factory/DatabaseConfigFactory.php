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
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;

class DatabaseConfigFactory
{
    public function __invoke(ContainerValue $container): DatabaseConfig
    {
        $env = $container->config->environment;
        $driver = $env->string('DB_DRIVER');

        return new DatabaseConfig([
            'default' => 'default',
            'databases' => [
                'default' => [
                    'connection' => $driver,
                ],
            ],
            'connections' => [
                $driver => $this->createDriverConfig($driver, $env),
            ],
        ]);
    }

    private function createDriverConfig(string $driver, Environment $env): SQLiteDriverConfig|MySQLDriverConfig|PostgresDriverConfig|SQLServerDriverConfig
    {
        return match ($driver) {
            'sqlite' => $this->createSQLiteDriverConfig($env),
            'mysql' => $this->createMySQLDriverConfig($env),
            'postgres', 'pgsql' => $this->createPostgresDriverConfig($env),
            'sqlserver', 'mssql' => $this->createSQLServerDriverConfig($env),
            default => throw DatabaseConfigException::unsupportedDriver($driver),
        };
    }

    private function createSQLiteDriverConfig(Environment $env): SQLiteDriverConfig
    {
        $database = $env->string('DB_NAME');

        return new SQLiteDriverConfig(
            connection: $database === ':memory:'
                ? new MemoryConnectionConfig()
                : new FileConnectionConfig(database: $database),
            queryCache: true,
        );
    }

    private function createMySQLDriverConfig(Environment $env): MySQLDriverConfig
    {
        return new MySQLDriverConfig(
            connection: new MySQLTcpConnectionConfig(
                database: $env->string('DB_NAME'),
                host: $env->string('DB_HOST', '127.0.0.1'),
                port: $env->int('DB_PORT', 3306),
                user: $env->string('DB_USER'),
                password: $env->string('DB_PASSWORD', ''),
            ),
            queryCache: true,
        );
    }

    private function createPostgresDriverConfig(Environment $env): PostgresDriverConfig
    {
        return new PostgresDriverConfig(
            connection: new PostgresTcpConnectionConfig(
                database: $env->string('DB_NAME'),
                host: $env->string('DB_HOST', '127.0.0.1'),
                port: $env->int('DB_PORT', 5432),
                user: $env->string('DB_USER'),
                password: $env->string('DB_PASSWORD', ''),
            ),
            schema: $env->string('DB_SCHEMA', 'public'),
            queryCache: true,
        );
    }

    private function createSQLServerDriverConfig(Environment $env): SQLServerDriverConfig
    {
        return new SQLServerDriverConfig(
            connection: new SQLServerTcpConnectionConfig(
                database: $env->string('DB_NAME'),
                host: $env->string('DB_HOST', '127.0.0.1'),
                port: $env->int('DB_PORT', 1433),
                user: $env->string('DB_USER'),
                password: $env->string('DB_PASSWORD', ''),
            ),
            queryCache: true,
        );
    }
}
