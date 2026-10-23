<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps docs/openapi.yaml and the registered routes from drifting apart.
 *
 * @internal
 */
final class OpenApiContractTest extends CIUnitTestCase
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @var array<string, mixed>
     */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spec = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    }

    public function testEveryReferenceResolves(): void
    {
        $unresolved = [];

        array_walk_recursive($this->spec, function (mixed $value, int|string $key) use (&$unresolved): void {
            if ($key === '$ref' && is_string($value) && preg_match('~^#/components/(\w+)/(\w+)$~', $value, $m) === 1 && ! isset($this->spec['components'][$m[1]][$m[2]])) {
                $unresolved[] = $value;
            }
        });

        $this->assertSame([], $unresolved);
    }

    public function testEveryRegisteredRouteIsDocumentedAndViceVersa(): void
    {
        $documented = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (! in_array($method, self::VERBS, true)) {
                    continue;
                }

                $documented[] = strtoupper($method) . ' ' . preg_replace('/\{\w+\}/', '{}', $path);
            }
        }

        $registered = [];
        $routes     = Services::routes();
        $routes->loadRoutes();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (array_keys($routes->getRoutes($method)) as $route) {
                if (str_starts_with($route, 'contabil/')) {
                    $registered[] = $method . ' /' . preg_replace('/\(\[0-9\]\+\)/', '{}', substr($route, strlen('contabil/')));
                }
            }
        }

        sort($documented);
        sort($registered);

        $this->assertSame([], array_values(array_diff($registered, $documented)), 'routes missing from the OpenAPI document');
        $this->assertSame([], array_values(array_diff($documented, $registered)), 'documented operations without a route');
    }

    public function testEveryOperationDeclaresItsPermission(): void
    {
        $missing = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, self::VERBS, true) && ! str_contains((string) ($operation['description'] ?? ''), 'contabil.')) {
                    $missing[] = strtoupper($method) . ' ' . $path;
                }
            }
        }

        $this->assertSame([], $missing, 'operations that do not name a contabil.* permission');
    }
}
