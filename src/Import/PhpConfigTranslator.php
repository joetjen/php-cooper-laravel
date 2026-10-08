<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Import;

use JOetjen\CooperConfig\Casc\Commented;
use JOetjen\CooperConfig\Casc\MapValue;
use JOetjen\CooperConfig\Casc\Raw;
use JOetjen\CooperLaravel\Tags;
use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Translates a Laravel configuration file into the tree php-cooper-
 * config's `Writer` writes as CASC -- by **parsing** the PHP, never
 * running it, so translating a file cannot do what running it would.
 *
 * | PHP | CASC |
 * |---|---|
 * | strings, numbers, `true`/`false`/`null`, arrays | the same, exactly; an associative array is a block |
 * | `env('X')` | `${X:nil}` -- `null` while `X` is unset, as `env()` gave; `${X}`, which fails the load while `X` is unset (reported), with `$required` |
 * | `env('X', true)`, `env('X', 3)`, `env('X', 0.5)` | `!bool(${X:true})`, `!int(${X:3})`, `!float(${X:0.5})` |
 * | `env('X', null)`, `env('X', 'd')` | `${X:nil}`, `${X:"d"}` |
 * | `env('X', storage_path('x'))`, `env('X', Foo::BAR)`, `env('X', Foo::class)` | `${X:!storage_path("x")}`, `${X:!php_const("Foo::BAR")}`, `${X:"Foo"}` |
 * | `(bool) env('X', false)`, `(int)`, `(float)`, `(string)` | as the default's type says, when the cast agrees with it |
 * | `(int) env('X')`, `(bool)`, `(float)`, `(string)` | `!int(${X:0})`, ... -- the cast's value of `null`; `!int(${X})`, ... with `$required` |
 * | `'redis://' . env('HOST') . ':6379'` | `"redis://${HOST:''}:6379"` -- `null` concatenated is `''`; `"redis://${HOST}:6379"` with `$required` |
 * | `explode(',', env('X', 'a,b'))` | `${X[]:["a", "b"]}` |
 * | `explode(',', env('X'))` | `${X[]:[]}`; `${X[]}` with `$required` |
 * | `Foo\Bar::class` | `!module("Foo.Bar")`, or the string when a segment is not PascalCase |
 * | `storage_path('x')` and the other path helpers | `!storage_path("x")`; `!base_path("")` without an argument |
 * | `PDO::MYSQL_ATTR_SSL_CA`, `PHP_EOL` | `!php_const("PDO::MYSQL_ATTR_SSL_CA")` |
 * | anything else | `!php("""<the code>""")`, its class and function names spelled out in full, since the file's `use` lines do not come along |
 * | a closure, or code using a variable of the file | left out: CASC cannot hold either |
 *
 * An array is `!php` as a whole when a key is not a literal or an item
 * is spread (`...$x`). A comment above a key comes along, its banner
 * decoration (`|---`) dropped.
 *
 * @internal
 */
final class PhpConfigTranslator
{
    /** A variable name `${...}` can read (CASC.md §7.2). */
    private const ENV_NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /** Where a double-quoted CASC string would start interpolating (CASC.md §7). */
    private const OPENER = '/[@$%!]\{|![A-Za-z][A-Za-z0-9_+\-]*[?!]?\(/';

    /** A namespace segment `!module` takes (CASC.md §7.5). */
    private const PASCAL_SEGMENT = '/\A[A-Z][A-Za-z0-9]*\z/';

    private const CLOSURE = 'a closure cannot be written as configuration';

    /**
     * What a value that is left out, or has no exact form, translates to:
     * an object no PHP literal can be, compared by identity.
     */
    private static ?\stdClass $skip = null;

    private int $exact = 0;

    /** @var list<string> */
    private array $viaPhp = [];

    /** @var list<string> */
    private array $skipped = [];

    /** @var array<string, true> the variables `${X}` now requires, under `$required` */
    private array $nowRequired = [];

    private readonly Standard $printer;

    /**
     * @param bool $required whether `env('X')` without a default requires `X` -- `${X}`, failing the load while it is unset -- rather than reading an unset `X` as `env()` does: `null`, or what a cast or a concatenation makes of it. Off by default, since that is the faithful translation; on, it is the stricter one, and each such variable is reported
     */
    public function __construct(private readonly bool $required = false)
    {
        $this->printer = new Standard(['shortArraySyntax' => true]);
    }

    /**
     * @param string $source the file's PHP source
     * @param string $file its path, for errors
     * @param string $prefix the dotted path its keys are listed under (`database` for `config/database.php`)
     * @return Translation the tree and its account
     * @throws \InvalidArgumentException when the source does not parse, or returns no array literal
     */
    public function translate(string $source, string $file, string $prefix = ''): Translation
    {
        $this->exact = 0;
        $this->viaPhp = $this->skipped = [];
        $this->nowRequired = [];

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($source) ?? [];
        } catch (ParseError $e) {
            throw new \InvalidArgumentException("{$file} does not parse: {$e->getMessage()}", 0, $e);
        }
        // The file's `use` lines do not travel with a `!php` value, so
        // every name is spelled out in full first.
        $statements = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => true])))->traverse($statements);
        // Except a global function's or constant's: `\env(...)` and
        // `env(...)` are one call, and the second is how a reader writes it.
        $statements = (new NodeTraverser(new GlobalNamesUnqualified()))->traverse($statements);

        $returned = null;
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Return_) {
                $returned = $statement->expr;
                break;
            }
        }
        if (!$returned instanceof Expr\Array_) {
            throw new \InvalidArgumentException("{$file} does not return an array literal, so there is nothing to translate");
        }

        $tree = $this->array($returned, $prefix);
        if ($tree instanceof MapValue) {
            $tree = $tree->entries;
        }
        if (!is_array($tree)) {
            // A top-level array that cannot be read key by key: the whole
            // file is one `!php`, which has no key to stand under.
            throw new \InvalidArgumentException("{$file} returns an array whose keys are not literals; it cannot be translated key by key");
        }

        return new Translation($tree, $this->exact, $this->viaPhp, $this->skipped, array_keys($this->nowRequired));
    }

    private static function skip(): \stdClass
    {
        return self::$skip ??= new \stdClass();
    }

    // ---- arrays ---------------------------------------------------------------------

    /**
     * An array literal: a block, a list, or -- when its keys cannot be
     * read -- `!php` as a whole, or left out when that cannot run.
     *
     * @return array<array-key, mixed>|MapValue|Raw|\stdClass
     */
    private function array(Expr\Array_ $array, string $where): array|MapValue|Raw|\stdClass
    {
        $entries = [];
        $comments = [];
        $next = 0;
        foreach ($array->items as $item) {
            $key = $item->key === null ? $next : self::literalKey($item->key);
            if ($key === null || $item->unpack || $item->byRef) {
                return $this->php($array, $where);
            }
            if (is_int($key)) {
                $next = max($next, $key + 1);
            }
            $entries[$key] = $item;
            $comments[$key] = self::comment($item);
        }

        if ($entries === []) {
            $this->exact++;

            return [];
        }
        if (array_is_list($entries)) {
            return $this->list($entries, $where);
        }

        $out = [];
        foreach ($entries as $key => $item) {
            $value = $this->value($item->value, $where === '' ? (string) $key : "{$where}.{$key}");
            if ($value === self::skip()) {
                continue;
            }
            $out[$key] = $comments[$key] === '' ? $value : new Commented($value, $comments[$key]);
        }

        // Every key left out: still a map, as the PHP said, and `{}`
        // leaves whatever is underneath it as it is.
        return $out === [] || array_is_list($out) ? new MapValue($out) : $out;
    }

    /**
     * A list. Plain values make one key between them; a block in it is
     * counted by its own keys.
     *
     * @param list<ArrayItem> $items
     * @return list<mixed>
     */
    private function list(array $items, string $where): array
    {
        $exactBefore = $this->exact;
        $phpBefore = count($this->viaPhp);
        $plain = true;
        $out = [];
        foreach ($items as $index => $item) {
            $plain = $plain && !$item->value instanceof Expr\Array_;
            $value = $this->value($item->value, "{$where}[{$index}]");
            if ($value !== self::skip()) {
                $out[] = $value;
            }
        }
        if ($plain) {
            // One key for the list: `!php` when any element is, else exact.
            $php = count($this->viaPhp) > $phpBefore;
            $this->viaPhp = array_slice($this->viaPhp, 0, $phpBefore);
            $this->exact = $exactBefore;
            if ($php) {
                $this->viaPhp[] = $where;
            } elseif ($out !== [] || $items === []) {
                $this->exact++;
            }
        }

        return $out;
    }

    /**
     * An array key written as a literal, or `null`.
     */
    private static function literalKey(Expr $key): string|int|null
    {
        return match (true) {
            $key instanceof Scalar\String_ => $key->value,
            $key instanceof Scalar\Int_ => $key->value,
            default => null,
        };
    }

    // ---- values ---------------------------------------------------------------------

    /**
     * One value, counted as exact, `!php` or skipped.
     */
    private function value(Expr $expr, string $where): mixed
    {
        if ($expr instanceof Expr\Array_) {
            return $this->array($expr, $where);
        }
        if (self::isClosure($expr)) {
            $this->skipped[] = "{$where}: " . self::CLOSURE;

            return self::skip();
        }
        $exact = $this->exact($expr);
        if ($exact !== self::skip()) {
            $this->exact++;

            return $exact;
        }

        return $this->php($expr, $where);
    }

    /**
     * `$expr` as CASC says it exactly, or `SKIP` when CASC cannot.
     */
    private function exact(Expr $expr): mixed
    {
        $literal = self::literal($expr);
        if ($literal !== self::skip()) {
            return $literal;
        }

        $raw = match (true) {
            $expr instanceof Expr\FuncCall => $this->call($expr),
            $expr instanceof Expr\Cast => $this->cast($expr),
            $expr instanceof Expr\BinaryOp\Concat => $this->concatenation($expr),
            $expr instanceof Expr\ClassConstFetch, $expr instanceof Expr\ConstFetch => self::constant($expr),
            default => null,
        };

        return $raw ?? self::skip();
    }

    /**
     * A scalar literal's value, or `SKIP`.
     */
    private static function literal(Expr $expr): mixed
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\UnaryMinus && ($expr->expr instanceof Scalar\Int_ || $expr->expr instanceof Scalar\Float_)) {
            return -$expr->expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            return match ($expr->name->toLowerString()) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => self::skip(),
            };
        }

        return self::skip();
    }

    /**
     * `env(...)`, `explode(',', env(...))` or a path helper.
     */
    private function call(Expr\FuncCall $call): ?Raw
    {
        $name = self::functionName($call);

        return match (true) {
            $name === 'env' => $this->env($call, null),
            $name === 'explode' => $this->explode($call),
            in_array($name, Tags::pathHelpers(), true) => self::pathHelper($call, $name),
            default => null,
        };
    }

    /**
     * `env('X', default)` as a reference typed by its default, `$cast`
     * agreeing with it -- or `null` for one CASC cannot say.
     *
     * @param 'bool'|'int'|'float'|'string'|null $cast a cast written around the call
     */
    private function env(Expr\FuncCall $call, ?string $cast): ?Raw
    {
        [$name, $default] = self::envArguments($call);
        if ($name === null) {
            return null;
        }
        if ($default === null) {
            if (!$this->required) {
                // What PHP makes of `env()`'s null: the cast of it, or null.
                return new Raw(match ($cast) {
                    null => '${' . $name . ':nil}',
                    'string' => '${' . $name . ':""}',
                    'bool' => '!bool(${' . $name . ':false})',
                    'int' => '!int(${' . $name . ':0})',
                    'float' => '!float(${' . $name . ':0.0})',
                });
            }
            $this->nowRequired[$name] = true;
            $reference = '${' . $name . '}';

            return new Raw($cast === null || $cast === 'string' ? $reference : "!{$cast}({$reference})");
        }

        $value = self::literal($default);
        [$type, $written] = match (true) {
            is_bool($value) => ['bool', $value ? 'true' : 'false'],
            is_int($value) => ['int', (string) $value],
            is_float($value) => ['float', self::float($value)],
            $value === null => ['null', 'nil'],
            is_string($value) => ['string', self::quoted($value)],
            default => [null, self::defaultTag($default)],
        };
        if ($written === null || ($cast !== null && !self::castAgrees($cast, $type))) {
            return null;
        }
        $reference = '${' . $name . ':' . $written . '}';

        return new Raw(match ($type) {
            'bool', 'int', 'float' => "!{$type}({$reference})",
            default => $reference,
        });
    }

    /**
     * Whether a cast around `env()` says what its default's type does:
     * `(float)` also takes an integer default, `(string)` a string, and
     * every cast a call with a tag or class as its default only when it
     * is `(string)`.
     */
    private static function castAgrees(string $cast, ?string $type): bool
    {
        return $cast === $type
            || ($cast === 'float' && $type === 'int')
            || ($cast === 'string' && $type === null);
    }

    /**
     * An `env()` call's variable name and default expression.
     *
     * @return array{0: string|null, 1: Expr|null}
     */
    private static function envArguments(Expr\FuncCall $call): array
    {
        if ($call->isFirstClassCallable() || count($call->args) < 1 || count($call->args) > 2) {
            return [null, null];
        }
        $args = $call->getArgs();
        $name = $args[0]->value;
        if (!$name instanceof Scalar\String_ || preg_match(self::ENV_NAME, $name->value) !== 1 || $args[0]->unpack || $args[0]->name !== null) {
            return [null, null];
        }

        return [$name->value, $args[1]->value ?? null];
    }

    /**
     * A default CASC writes as a tag or a string: a path helper, a
     * constant, a class name.
     */
    private static function defaultTag(Expr $default): ?string
    {
        if ($default instanceof Expr\FuncCall && in_array(self::functionName($default), Tags::pathHelpers(), true)) {
            return self::pathHelper($default, (string) self::functionName($default))?->source;
        }
        if ($default instanceof Expr\ClassConstFetch && $default->class instanceof Name && $default->name instanceof Identifier && $default->name->toLowerString() === 'class') {
            return self::quoted($default->class->toString());
        }
        if ($default instanceof Expr\ClassConstFetch || $default instanceof Expr\ConstFetch) {
            return self::constant($default)?->source;
        }

        return null;
    }

    /**
     * `(bool) env(...)` and the other scalar casts.
     */
    private function cast(Expr\Cast $cast): ?Raw
    {
        $type = match (true) {
            $cast instanceof Expr\Cast\Bool_ => 'bool',
            $cast instanceof Expr\Cast\Int_ => 'int',
            $cast instanceof Expr\Cast\Double => 'float',
            $cast instanceof Expr\Cast\String_ => 'string',
            default => null,
        };
        if ($type === null || !$cast->expr instanceof Expr\FuncCall || self::functionName($cast->expr) !== 'env') {
            return null;
        }

        return $this->env($cast->expr, $type);
    }

    /**
     * `explode(',', env('X', 'a,b'))`: CASC's list reference.
     */
    private function explode(Expr\FuncCall $call): ?Raw
    {
        if ($call->isFirstClassCallable() || count($call->args) !== 2) {
            return null;
        }
        [$separator, $subject] = $call->getArgs();
        if (!$separator->value instanceof Scalar\String_ || $separator->value->value !== ','
            || !$subject->value instanceof Expr\FuncCall || self::functionName($subject->value) !== 'env') {
            return null;
        }
        [$name, $default] = self::envArguments($subject->value);
        if ($name === null) {
            return null;
        }
        if ($default === null) {
            if (!$this->required) {
                return new Raw('${' . $name . '[]:[]}');
            }
            $this->nowRequired[$name] = true;

            return new Raw('${' . $name . '[]}');
        }
        if (!$default instanceof Scalar\String_) {
            return null;
        }
        $items = $default->value === '' ? [] : explode(',', $default->value);
        $written = [];
        foreach ($items as $item) {
            $quoted = self::quoted($item);
            if ($quoted === null) {
                return null;
            }
            $written[] = $quoted;
        }

        return new Raw('${' . $name . '[]:[' . implode(', ', $written) . ']}');
    }

    /**
     * `storage_path('x')` as `!storage_path("x")`.
     */
    private static function pathHelper(Expr\FuncCall $call, string $name): ?Raw
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        $args = $call->getArgs();
        if ($args === []) {
            return new Raw("!{$name}(\"\")");
        }
        if (count($args) !== 1 || !$args[0]->value instanceof Scalar\String_) {
            return null;
        }
        $quoted = self::quoted($args[0]->value->value);

        return $quoted === null ? null : new Raw("!{$name}({$quoted})");
    }

    /**
     * A string concatenated with `env()` calls, as one string
     * interpolating them. Literal parts are text; a call is `${X}`, or
     * `${X:'d'}` with a string default -- inside a string, a default is
     * single-quoted. Anything else in the chain, or text that would read
     * as interpolation, leaves it to `!php`.
     */
    private function concatenation(Expr\BinaryOp\Concat $concat): ?Raw
    {
        $text = '';
        $required = [];
        foreach (self::parts($concat) as $part) {
            $literal = self::literal($part);
            if (is_string($literal) || is_int($literal)) {
                $literal = (string) $literal;
                if (preg_match(self::OPENER, $literal) === 1) {
                    return null;
                }
                $text .= self::escape($literal);
                continue;
            }
            if (!$part instanceof Expr\FuncCall || self::functionName($part) !== 'env') {
                return null;
            }
            [$name, $default] = self::envArguments($part);
            if ($name === null) {
                return null;
            }
            if ($default === null) {
                // Concatenated, `env()`'s null is the empty string.
                if (!$this->required) {
                    $text .= '${' . $name . ":''}";
                    continue;
                }
                $required[] = $name;
                $text .= '${' . $name . '}';
                continue;
            }
            if (!$default instanceof Scalar\String_ || preg_match("/['}]/", $default->value) === 1) {
                return null;
            }
            $text .= '${' . $name . ":'" . $default->value . "'}";
        }
        foreach ($required as $name) {
            $this->nowRequired[$name] = true;
        }

        return new Raw('"' . $text . '"');
    }

    /**
     * A concatenation chain's operands, left to right.
     *
     * @return list<Expr>
     */
    private static function parts(Expr $expr): array
    {
        return $expr instanceof Expr\BinaryOp\Concat ? [...self::parts($expr->left), ...self::parts($expr->right)] : [$expr];
    }

    /**
     * `Foo\Bar::class`, `Foo::BAR` or `BAR`.
     */
    private static function constant(Expr\ClassConstFetch|Expr\ConstFetch $fetch): ?Raw
    {
        if ($fetch instanceof Expr\ConstFetch) {
            return new Raw('!php_const(' . self::quoted($fetch->name->toString()) . ')');
        }
        // `static::`, `self::` and `$object::` mean nothing outside a class.
        if (!$fetch->class instanceof Name || in_array($fetch->class->toLowerString(), ['self', 'static', 'parent'], true) || !$fetch->name instanceof Identifier) {
            return null;
        }
        $class = $fetch->class->toString();
        if ($fetch->name->toLowerString() !== 'class') {
            return new Raw('!php_const(' . self::quoted("{$class}::{$fetch->name->toString()}") . ')');
        }
        $segments = explode('\\', $class);
        foreach ($segments as $segment) {
            if (preg_match(self::PASCAL_SEGMENT, $segment) !== 1) {
                return new Raw((string) self::quoted($class));
            }
        }

        return new Raw('!module("' . implode('.', $segments) . '")');
    }

    // ---- !php ------------------------------------------------------------------------

    /**
     * `$expr` as `!php("""...""")`, or left out when it cannot run at
     * load: it reads a variable only the file defined, or its code
     * cannot be quoted.
     */
    private function php(Expr $expr, string $where): Raw|\stdClass
    {
        $variables = array_filter(
            (new NodeFinder())->findInstanceOf([$expr], Expr\Variable::class),
            static fn (Expr\Variable $variable): bool => !self::insideClosure($variable, $expr),
        );
        if ($variables !== []) {
            $first = array_values($variables)[0];
            $name = is_string($first->name) ? '$' . $first->name : 'a variable';
            $this->skipped[] = "{$where}: it reads {$name}, which only the PHP file defines";

            return self::skip();
        }

        $code = $this->printer->prettyPrintExpr($expr);
        // A triple-quoted string is the one CASC string that holds any
        // PHP unchanged -- no escapes, no interpolation -- except `"""`,
        // which the printer only ever writes inside a '...' string, so a
        // single-quoted CASC string could not hold that code either.
        if (str_contains($code, '"""')) {
            $this->skipped[] = "{$where}: its code holds both \"\"\" and ', which no CASC string can quote";

            return self::skip();
        }
        $this->viaPhp[] = $where;

        // A closing `"` would run into the closing `"""`; a space after it
        // changes nothing in PHP.
        return new Raw('!php("""' . (str_ends_with($code, '"') ? "{$code} " : $code) . '""")');
    }

    /**
     * Whether `$variable` is a closure's own -- a parameter or a local --
     * inside `$root`, rather than one of the file's.
     */
    private static function insideClosure(Expr\Variable $variable, Expr $root): bool
    {
        foreach ((new NodeFinder())->find([$root], static fn (Node $node): bool => self::isClosure($node)) as $closure) {
            if ((new NodeFinder())->findFirst([$closure], static fn (Node $node): bool => $node === $variable) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function isClosure(Node $node): bool
    {
        return $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
            || ($node instanceof Expr\CallLike && $node->isFirstClassCallable());
    }

    // ---- spelling -------------------------------------------------------------------

    /**
     * A function call's name, lower-case, when it is a plain name.
     */
    private static function functionName(Expr\FuncCall $call): ?string
    {
        return $call->name instanceof Name ? $call->name->toLowerString() : null;
    }

    /**
     * `$text` as a CASC string: double-quoted and escaped, or
     * single-quoted when it holds an interpolation opener; `null` when it
     * holds an opener and a `'` too, which neither quoting can carry.
     */
    private static function quoted(string $text): ?string
    {
        if (preg_match(self::OPENER, $text) !== 1) {
            return '"' . self::escape($text) . '"';
        }

        return str_contains($text, "'") || preg_match('/[\x00-\x1f]/', $text) === 1 ? null : "'{$text}'";
    }

    /**
     * `$text` escaped for a double-quoted CASC string (CASC.md §6.5).
     */
    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);
    }

    /**
     * A float as CASC reads one back: always with a fraction or an
     * exponent, so it stays a float.
     */
    private static function float(float $value): string
    {
        $text = var_export($value, true);

        return preg_match('/[.eEN]/', $text) === 1 ? $text : "{$text}.0";
    }

    /**
     * The comment written above an array item, without its comment
     * markers or Laravel's banner decoration: `/* | ---- | Name | ---- *\/`
     * becomes `Name`.
     */
    private static function comment(ArrayItem $item): string
    {
        $lines = [];
        foreach ($item->getComments() as $comment) {
            foreach (preg_split('/\R/', $comment->getText()) ?: [] as $line) {
                $line = trim($line);
                $line = (string) preg_replace('~\A(?:/\*+|\*/|//+|#)~', '', $line);
                $line = (string) preg_replace('~\*+/\z~', '', $line);
                $line = trim((string) preg_replace('~\A[*|]~', '', trim($line)));
                if (preg_match('/\A-{3,}\z/', $line) === 1) {
                    continue;
                }
                $lines[] = $line;
            }
        }
        // Blank lines collapse to one, and none at either end.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim($text, "\n ");
    }
}
