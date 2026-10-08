<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Import;

/**
 * One PHP configuration file, translated: the tree php-cooper-config's
 * `Writer` writes, and an account of how each key got there.
 *
 * A key is counted where it holds a value rather than a map: `db.host`,
 * a whole list of plain values, each block of a list of blocks by its
 * own keys.
 *
 * @internal
 */
final class Translation
{
    /**
     * @param array<array-key, mixed> $tree the file's returned array, as the `Writer` takes it
     * @param int $exact how many keys CASC says exactly
     * @param list<string> $viaPhp the keys written as `!php("""...""")`, by dotted path
     * @param list<string> $skipped the keys left out, by dotted path, each with why
     * @param list<string> $required the variables `env('X')` read without a default, when translated as `${X}`, which requires them (`PhpConfigTranslator`'s `$required`); empty otherwise
     */
    public function __construct(
        public readonly array $tree,
        public readonly int $exact,
        public readonly array $viaPhp,
        public readonly array $skipped,
        public readonly array $required,
    ) {
    }
}
