<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Import;

use JOetjen\CooperConfig\Casc\Writer;
use JOetjen\CooperLaravel\Import\PhpConfigTranslator;
use JOetjen\CooperLaravel\Import\Translation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Laravel configuration file read as PHP source -- never run -- and
 * written as CASC: each value exactly where CASC can say it, `!php`
 * where it cannot, left out (and reported) where nothing can.
 */
final class PhpConfigTranslatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function exactValues(): iterable
    {
        yield 'a string' => ["'text'", 'v = "text"'];
        yield 'a string CASC would interpolate' => ["'cost: \${amount}'", "v = 'cost: \${amount}'"];
        yield 'an integer' => ['5', 'v = 5'];
        yield 'a negative integer' => ['-5', 'v = -5'];
        yield 'a float' => ['1.5', 'v = 1.5'];
        yield 'true' => ['true', 'v = true'];
        yield 'false' => ['FALSE', 'v = false'];
        yield 'null' => ['null', 'v = nil'];
        yield 'a list' => ["['a', 'b']", 'v = ["a", "b"]'];
        yield 'an empty array' => ['[]', 'v = []'];
        yield 'env() without a default, null while X is unset as env() gave' => ["env('X')", 'v = ${X:nil}'];
        yield 'env() with a bool default' => ["env('X', true)", 'v = !bool(${X:true})'];
        yield 'env() with an int default' => ["env('X', 3306)", 'v = !int(${X:3306})'];
        yield 'env() with a negative int default' => ["env('X', -1)", 'v = !int(${X:-1})'];
        yield 'env() with a float default' => ["env('X', 0.5)", 'v = !float(${X:0.5})'];
        yield 'env() with a null default' => ["env('X', null)", 'v = ${X:nil}'];
        yield 'env() with a string default' => ["env('X', 'file')", 'v = ${X:"file"}'];
        yield 'env() with an empty string default' => ["env('X', '')", 'v = ${X:""}'];
        yield 'env() with a path helper default' => ["env('X', database_path('db.sqlite'))", 'v = ${X:!database_path("db.sqlite")}'];
        yield 'a bool cast around env()' => ["(bool) env('X', false)", 'v = !bool(${X:false})'];
        yield 'an int cast around env() without a default, the cast of null' => ["(int) env('X')", 'v = !int(${X:0})'];
        yield 'a string cast around env()' => ["(string) env('X', 'a')", 'v = ${X:"a"}'];
        yield 'a concatenation with env() without a default, null concatenated' => ["'redis://' . env('HOST') . ':6379'", "v = \"redis://\${HOST:''}:6379\""];
        yield 'a concatenation with a defaulted env()' => ["'https://' . env('HOST', 'localhost') . '/x'", "v = \"https://\${HOST:'localhost'}/x\""];
        yield 'explode() of env() with a default' => ["explode(',', env('X', 'a,b'))", 'v = ${X[]:["a", "b"]}'];
        yield 'explode() of env() without one' => ["explode(',', env('X'))", 'v = ${X[]:[]}'];
        yield 'a PascalCase class' => ['Illuminate\Foundation\Auth\User::class', 'v = !module("Illuminate.Foundation.Auth.User")'];
        yield 'a class that is not PascalCase' => ['Acme\lower_case::class', 'v = "Acme\\\\lower_case"'];
        yield 'a path helper' => ["storage_path('logs/laravel.log')", 'v = !storage_path("logs/laravel.log")'];
        yield 'a path helper without an argument' => ['base_path()', 'v = !base_path("")'];
        foreach (['app_path', 'config_path', 'database_path', 'lang_path', 'public_path', 'resource_path'] as $helper) {
            yield $helper => ["{$helper}('a')", "v = !{$helper}(\"a\")"];
        }
        yield 'a class constant' => ['PDO::ATTR_ERRMODE', 'v = !php_const("PDO::ATTR_ERRMODE")'];
        yield 'a global constant' => ['PHP_EOL', 'v = !php_const("PHP_EOL")'];
    }

    #[DataProvider('exactValues')]
    public function testAValueCascCanSayIsWrittenExactly(string $php, string $casc): void
    {
        $translation = self::translate("['v' => {$php}]");

        self::assertSame($casc, self::body($translation));
        self::assertSame(1, $translation->exact);
        self::assertSame([], $translation->viaPhp);
        self::assertSame([], $translation->skipped);
    }

    public function testANestedArrayIsABlock(): void
    {
        $translation = self::translate("['db' => ['host' => '127.0.0.1', 'port' => 5432, 'tags' => ['a']]]");

        self::assertSame("db {\n  host = \"127.0.0.1\"\n  port = 5432\n  tags = [\"a\"]\n}", self::body($translation));
        self::assertSame(3, $translation->exact);
    }

    public function testAListOfArraysIsAListOfBlocks(): void
    {
        self::assertSame('v = [{ a = 1 }, { a = 2 }]', self::body(self::translate("['v' => [['a' => 1], ['a' => 2]]]")));
    }

    public function testAnythingElseIsPhpWithItsNamesSpelledOut(): void
    {
        $translation = self::translate(
            "['prefix' => Str::slug(env('APP_NAME', 'laravel'), '_') . '_cache_']",
            "use Illuminate\\Support\\Str;\n",
        );

        self::assertSame(
            'prefix = !php("""\\Illuminate\\Support\\Str::slug(env(\'APP_NAME\', \'laravel\'), \'_\') . \'_cache_\'""")',
            self::body($translation),
        );
        self::assertSame(0, $translation->exact);
        self::assertSame(['prefix'], $translation->viaPhp);
    }

    public function testAnArrayWhoseKeysAreNotLiteralIsPhpAsAWhole(): void
    {
        $translation = self::translate("['options' => [PDO::ATTR_TIMEOUT => 5, 'x' => 1]]");

        self::assertSame('options = !php("""[\\PDO::ATTR_TIMEOUT => 5, \'x\' => 1]""")', self::body($translation));
        self::assertSame(['options'], $translation->viaPhp);
    }

    public function testASpreadIsPhpAsAWhole(): void
    {
        $translation = self::translate("['keys' => [...array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', '')))]]");

        self::assertSame(['keys'], $translation->viaPhp);
        self::assertStringStartsWith('keys = !php("""[...array_filter(explode(\',\', (string) env(', self::body($translation));
    }

    public function testPhpThatCannotBeQuotedIsSkipped(): void
    {
        $translation = self::translate("['v' => strtoupper('\"\"\"'), 'w' => 1]");

        self::assertSame('w = 1', self::body($translation));
        self::assertSame(['v: its code holds both """ and \', which no CASC string can quote'], $translation->skipped);
    }

    public function testAClosureCannotBeWrittenAndIsSkipped(): void
    {
        $translation = self::translate("['name' => 'x', 'resolver' => fn () => 1, 'nested' => ['cb' => function () { return 2; }]]");

        self::assertSame("name = \"x\"\n\nnested {}", self::body($translation));
        self::assertSame(1, $translation->exact);
        self::assertSame(['resolver: a closure cannot be written as configuration', 'nested.cb: a closure cannot be written as configuration'], $translation->skipped);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function unsetValues(): iterable
    {
        yield 'env() without a default' => ["env('X')", 'v = ${X:nil}'];
        yield 'a bool cast' => ["(bool) env('X')", 'v = !bool(${X:false})'];
        yield 'an int cast' => ["(int) env('X')", 'v = !int(${X:0})'];
        yield 'a float cast' => ["(float) env('X')", 'v = !float(${X:0.0})'];
        yield 'a string cast' => ["(string) env('X')", 'v = ${X:""}'];
        yield 'a concatenation' => ["env('X') . '/x'", "v = \"\${X:''}/x\""];
        yield 'explode()' => ["explode(',', env('X'))", 'v = ${X[]:[]}'];
    }

    #[DataProvider('unsetValues')]
    public function testEnvWithoutADefaultReadsAnUnsetVariableAsPhpWouldHave(string $php, string $casc): void
    {
        $translation = self::translate("['v' => {$php}]");

        self::assertSame($casc, self::body($translation));
        self::assertSame([], $translation->required);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function requiredValues(): iterable
    {
        yield 'env() without a default' => ["env('X')", 'v = ${X}'];
        yield 'env() with a default, unaffected' => ["env('X', 'd')", 'v = ${X:"d"}'];
        yield 'a bool cast' => ["(bool) env('X')", 'v = !bool(${X})'];
        yield 'an int cast' => ["(int) env('X')", 'v = !int(${X})'];
        yield 'a float cast' => ["(float) env('X')", 'v = !float(${X})'];
        yield 'a string cast' => ["(string) env('X')", 'v = ${X}'];
        yield 'a concatenation' => ["env('X') . '/x'", 'v = "${X}/x"'];
        yield 'explode()' => ["explode(',', env('X'))", 'v = ${X[]}'];
    }

    #[DataProvider('requiredValues')]
    public function testRequiredMakesEnvWithoutADefaultFailTheLoadWhileTheVariableIsUnset(string $php, string $casc): void
    {
        $translation = (new PhpConfigTranslator(required: true))->translate("<?php\n\nreturn ['v' => {$php}];\n", 'probe.php');

        self::assertSame($casc, self::body($translation));
    }

    public function testRequiredReportsEveryVariableItNowRequires(): void
    {
        $translation = (new PhpConfigTranslator(required: true))->translate(
            "<?php\n\nreturn ['a' => env('A'), 'b' => 'x' . env('B'), 'c' => env('C', null)];\n",
            'probe.php',
        );

        self::assertSame(['A', 'B'], $translation->required);
    }

    public function testAKeysCommentIsCarriedOver(): void
    {
        $translation = self::translate(<<<'PHP'
            [
                /*
                |--------------------------------------------------------------------------
                | Application Name
                |--------------------------------------------------------------------------
                |
                | This value is the name of your application.
                |
                */

                'name' => 'Laravel',

                // The locale.
                'locale' => 'en',
            ]
            PHP);

        self::assertSame("# Application Name\n#\n# This value is the name of your application.\nname = \"Laravel\"\n\n# The locale.\nlocale = \"en\"", self::body($translation));
    }

    public function testAFileThatReturnsNoArrayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return an array');

        (new PhpConfigTranslator())->translate("<?php\n\nreturn 'nope';\n", 'nope.php');
    }

    public function testAFileThatDoesNotParseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('broken.php');

        (new PhpConfigTranslator())->translate("<?php\n\nreturn [\n", 'broken.php');
    }

    public function testTheFileIsNeverRun(): void
    {
        $translation = self::translate("['v' => exit(9)]");

        self::assertSame(['v'], $translation->viaPhp);
    }

    private static function translate(string $array, string $uses = ''): Translation
    {
        return (new PhpConfigTranslator())->translate("<?php\n\n{$uses}\nreturn {$array};\n", 'probe.php');
    }

    /**
     * The document for `$translation`, without its version header.
     */
    private static function body(Translation $translation): string
    {
        $document = (new Writer())->write($translation->tree);

        return trim(substr($document, strlen("#@version = 1.0\n")));
    }
}
