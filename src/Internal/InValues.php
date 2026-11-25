<?php

declare(strict_types=1);

namespace Componenta\Cycle\Internal;

use Cycle\Database\Injection\Fragment;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Injection\ParameterInterface;

/**
 * Preserves the type and position of operands in DBAL IN conditions.
 *
 * @internal
 */
final class InValues
{
    public static function parameter(array $values): Parameter
    {
        $hasFragments = false;

        foreach ($values as $key => $value) {
            if ($value instanceof FragmentInterface) {
                $hasFragments = true;
            } elseif (!$value instanceof ParameterInterface) {
                $values[$key] = new Parameter($value);
            }
        }

        // The DBAL compiler appends simple list bindings after all fragment
        // bindings. In a mixed list, make every operand a fragment so bindings
        // are emitted in SQL placeholder order. Never interpolate input values.
        if ($hasFragments) {
            foreach ($values as $key => $value) {
                if (!$value instanceof FragmentInterface) {
                    $values[$key] = new Fragment('?', $value);
                }
            }
        }

        return new Parameter($values);
    }
}
