<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel;

use JOetjen\Cooper\Value\CooperAtom;
use JOetjen\Cooper\Value\CooperDate;
use JOetjen\Cooper\Value\CooperDateTime;
use JOetjen\Cooper\Value\CooperInteger;
use JOetjen\Cooper\Value\CooperLocalDateTime;
use JOetjen\Cooper\Value\CooperTime;
use JOetjen\CooperConfig\ConversionError;
use JOetjen\CooperConfig\Convert;

/**
 * What Cooper loaded, as values Laravel's configuration holds.
 *
 * php-cooper-config's `Convert` does the measurements and the secrets --
 * a byte size is its byte count, a duration its milliseconds, an address
 * its text, a tuple a list, a secret revealed unless the block's
 * `cooper-secrets` says otherwise -- so a document means the same here as
 * anywhere else Cooper's PHP configuration is read.
 *
 * What `Convert` leaves as Cooper's own value objects is made plain,
 * because Laravel and its packages read configuration as strings and
 * numbers, and because `config:cache` writes it with `var_export()`, which
 * Cooper's value objects do not survive: an atom (`level = debug`)
 * becomes its name, a date or time its CASC text, an integer beyond 64
 * bits its digits. A secret kept by `cooper-secrets = keep` or wrapped by
 * an application's class, and whatever `!php` returns, are the
 * application's choice and stay as they are.
 *
 * @internal
 */
final class ConfigValues
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $config what Cooper loaded
     * @param array<string, mixed> $modules the `modules` load option, for a `cooper-secrets` class name
     * @return array<array-key, mixed> the configuration, by top-level key
     * @throws ConversionError when a value cannot be converted
     */
    public static function fromDocument(array $config, array $modules = []): array
    {
        return self::walk(Convert::toAppConfig($config, $modules));
    }

    /**
     * @param array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private static function walk(array $items): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            $out[$key] = self::plain($item);
        }

        return $out;
    }

    private static function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => self::walk($value),
            $value instanceof CooperAtom => $value->name,
            $value instanceof CooperInteger => $value->digits,
            $value instanceof CooperDate,
            $value instanceof CooperTime,
            $value instanceof CooperLocalDateTime,
            $value instanceof CooperDateTime => (string) $value,
            default => $value,
        };
    }
}
