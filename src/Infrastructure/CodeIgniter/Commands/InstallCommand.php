<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands;

use CodeIgniter\CLI\CLI;
use Throwable;

class InstallCommand extends ContabilCommand
{
    protected $name        = 'contabil:install';
    protected $description = 'Publishes the Contabil configuration and runs its migrations.';
    protected $usage       = 'contabil:install [--force]';
    protected $options     = ['--force' => 'Overwrite app/Config/Contabil.php if it exists.'];

    public function run(array $params)
    {
        $source = dirname(__DIR__, 4) . '/config/contabil.php';
        $target = APPPATH . 'Config/Contabil.php';

        if (is_file($target) && CLI::getOption('force') === null) {
            CLI::write('Configuration already published: ' . $target, 'yellow');
        } elseif (! is_file($source) || ! is_dir(dirname($target)) || file_put_contents($target, (string) file_get_contents($source)) === false) {
            CLI::error('Could not publish the configuration to ' . $target);

            return EXIT_ERROR;
        } else {
            CLI::write('Published ' . $target, 'green');
        }

        try {
            $this->runner()->latest();
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('Contabil is installed.', 'green');

        return EXIT_SUCCESS;
    }
}
