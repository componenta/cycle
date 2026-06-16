<?php

declare(strict_types=1);

namespace Componenta\Cycle\Exception;

use RuntimeException;

/**
 * Exception thrown when entity is not found.
 */
class EntityNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $role,
        public readonly mixed $id,
    ) {
        parent::__construct(
            sprintf('Entity [%s] with id [%s] not found', $role, (string) $id)
        );
    }
}
