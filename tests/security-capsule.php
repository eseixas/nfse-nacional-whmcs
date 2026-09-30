<?php

namespace WHMCS\Database;

// Standalone tests only: no database connection is possible.
final class Capsule
{
    public static ?string $deleteError = null;
    public static array $exportRows = [];
    public static int $writes = 0;
    public static function table(string $name): SecurityQuery { return new SecurityQuery(); }
}

final class SecurityQuery
{
    public function __call(string $name, array $args): mixed
    {
        if (in_array($name, ['delete', 'insert', 'update'], true)) { ++Capsule::$writes; }
        if ($name === 'delete' && Capsule::$deleteError !== null) {
            throw new \RuntimeException(Capsule::$deleteError);
        }
        return match ($name) {
            'get' => Capsule::$exportRows, 'first' => null, 'count' => 0, 'exists' => false,
            'delete', 'insert', 'update' => 1,
            default => $this,
        };
    }
}
