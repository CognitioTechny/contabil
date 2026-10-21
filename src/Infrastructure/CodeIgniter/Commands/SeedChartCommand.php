<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Application\Chart\ChartFormat;
use CognitioTechny\Contabil\Application\Chart\ChartImportException;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;

/**
 * Seeds an entity with one of the optional charts in `resources/charts/`.
 * Repeating it changes nothing: accounts whose code already exists are skipped.
 */
class SeedChartCommand extends ContabilCommand
{
    public const MODELS_DIRECTORY = __DIR__ . '/../../../../resources/charts';

    protected $name        = 'contabil:seed-chart';
    protected $description = 'Seeds an entity with an optional chart of accounts (safe to repeat).';
    protected $usage       = 'contabil:seed-chart --model <name> [--entity <id>]';
    protected $options     = [
        '--model'  => 'Chart to seed: "generic" (default) or "professional-services".',
        '--entity' => 'Entity id; defaults to the configured defaultEntityId.',
    ];

    public function run(array $params)
    {
        if (! $this->commandsEnabled()) {
            CLI::error('The Contabil commands are disabled in configuration.');

            return EXIT_ERROR;
        }

        $model = (string) ($this->option($params, 'model') ?? 'generic');
        $path  = self::MODELS_DIRECTORY . '/' . $model . '.json';

        if (preg_match('/^[a-z0-9-]+$/', $model) !== 1 || ! is_file($path)) {
            CLI::error('Unknown chart "' . $model . '". Available: ' . implode(', ', self::models()) . '.');

            return EXIT_ERROR;
        }

        $entity = $this->option($params, 'entity') ?? service('contabilCurrentEntity')->id();

        if ($entity === null || ! ctype_digit((string) $entity) || (int) $entity < 1) {
            CLI::error('Pass the entity with --entity <id> (a positive integer), or configure defaultEntityId.');

            return EXIT_ERROR;
        }

        try {
            $result = service('contabil')->importChart()->execute(
                (int) $entity,
                ChartFormat::parse((string) file_get_contents($path), ChartFormat::JSON),
                true,
            );
        } catch (ChartImportException $e) {
            CLI::error($e->getMessage());

            foreach ($e->errors() as $error) {
                CLI::write('  row ' . $error['row'] . ' (' . ($error['code'] ?? '-') . '): ' . $error['message']);
            }

            return EXIT_ERROR;
        } catch (ContabilException $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Chart "%s": %d account(s) created, %d already present.', $model, $result->created, $result->skipped), 'green');
        CLI::write('A seeded chart is a starting point; an accountant must review it before it is treated as official.', 'yellow');

        return EXIT_SUCCESS;
    }

    /**
     * @return list<string>
     */
    public static function models(): array
    {
        $names = array_map(static fn (string $file): string => basename($file, '.json'), glob(self::MODELS_DIRECTORY . '/*.json') ?: []);
        sort($names);

        return $names;
    }
}
