<?php

declare(strict_types=1);

namespace Componenta\Cycle\Exception;

use InvalidArgumentException;

class DatabaseConfigException extends InvalidArgumentException
{
    public static function missingDriver(): self
    {
        return new self('Cycle driver is not configured. Set the DB_DRIVER environment variable.');
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self(sprintf(
            'Unsupported database driver "%s". Supported drivers: sqlite, mysql, postgres, pgsql, sqlserver, mssql.',
            $driver,
        ));
    }

    public static function missingOption(string $name): self
    {
        return new self(sprintf(
            'Required database configuration option "%s" is not set.',
            $name,
        ));
    }
}