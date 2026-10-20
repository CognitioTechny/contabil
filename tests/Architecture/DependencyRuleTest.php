<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Architecture;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Domain and Application must stay framework-free and host-free: the only
 * namespaces they may reference are the package's own and the PSR interfaces.
 *
 * @internal
 */
final class DependencyRuleTest extends CIUnitTestCase
{
    /**
     * Namespace prefixes Domain and Application code may use.
     */
    private const ALLOWED = ['CognitioTechny\\Contabil\\Domain\\', 'CognitioTechny\\Contabil\\Application\\', 'Psr\\'];

    public function testDomainAndApplicationDependOnlyOnThemselvesAndPsr(): void
    {
        $violations = [];

        foreach (['Domain', 'Application'] as $layer) {
            foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src/' . $layer) as $file) {
                foreach (self::violations((string) file_get_contents($file)) as $name) {
                    $violations[] = substr($file, strlen(dirname(__DIR__, 2)) + 1) . ' references ' . $name;
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function testCheckerRejectsCodeIgniterImports(): void
    {
        $source = "<?php\nnamespace CognitioTechny\\Contabil\\Domain;\nuse CodeIgniter\\Model;\nfinal class X {}\n";

        $this->assertSame(['CodeIgniter\\Model'], self::violations($source));
    }

    public function testCheckerRejectsHostNamespacesAndInlineReferences(): void
    {
        $source = "<?php\nnamespace CognitioTechny\\Contabil\\Application;\nuse App\\Models\\User;\nfinal class X { public function f() { return new \\Config\\Database(); } }\n";

        $this->assertSame(['App\\Models\\User', 'Config\\Database'], self::violations($source));
    }

    public function testCheckerAcceptsPackageAndPsrNamespaces(): void
    {
        $source = "<?php\nnamespace CognitioTechny\\Contabil\\Application;\nuse CognitioTechny\\Contabil\\Domain\\Exception\\ValidationException;\nuse Psr\\EventDispatcher\\EventDispatcherInterface;\nuse DateTimeImmutable;\nfinal class X {}\n";

        $this->assertSame([], self::violations($source));
    }

    /**
     * @return list<string> Qualified names in the source outside the allowed namespaces.
     */
    public static function violations(string $source): array
    {
        $found  = [];
        $tokens = token_get_all($source);

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // A relative qualified name resolves inside the file's own namespace; only
            // `use` imports and fully qualified names can reach another namespace.
            $previous = self::previousSignificant($tokens, $i);
            if ($token[0] === T_NAME_QUALIFIED && ($previous === null || $previous[0] !== T_USE)) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            foreach (self::ALLOWED as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }

            $found[] = $name;
        }

        return $found;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return array{int, string, int}|null
     */
    private static function previousSignificant(array $tokens, int $index): ?array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] !== T_WHITESPACE) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
