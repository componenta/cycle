<?php

namespace Componenta\Cycle;

final class ConfigKey extends \Componenta\Config\ConfigKey
{
    public final const string BINDINGS = 'Componenta\Cycle::bindings';

    public const string ROOT = 'Componenta\Cycle::cycle';

    // Database
    public const string DATABASE = 'Componenta\Cycle::database';

    // Entities
    public const string ENTITIES = 'Componenta\Cycle::entities';

    // Schema
    public const string SCHEMA = 'Componenta\Cycle::schema';

    // Migrations
    public const string MIGRATIONS = 'Componenta\Cycle::migrations';
}