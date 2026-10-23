<?php

declare(strict_types=1);

/**
 * API routes of the package, discovered by CodeIgniter. Switch them off with
 * `$routesEnabled = false` in `app/Config/Contabil.php`.
 *
 * @var RouteCollection $routes
 */

use CodeIgniter\Router\RouteCollection;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config\Contabil;

$contabil = config(Contabil::class);

if ($contabil->routesEnabled) {
    $routes->group(
        trim($contabil->apiRoute, '/'),
        ['namespace' => 'CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers'],
        static function ($routes): void {
            $routes->get('accounts', 'AccountsController::index');
            $routes->post('accounts', 'AccountsController::create');
            $routes->get('accounts/tree', 'AccountsController::tree');
            $routes->get('accounts/export', 'AccountsController::export');
            $routes->post('accounts/import', 'AccountsController::import');
            $routes->get('accounts/(:num)', 'AccountsController::show/$1');
            $routes->put('accounts/(:num)', 'AccountsController::update/$1');
            $routes->patch('accounts/(:num)', 'AccountsController::update/$1');
            $routes->delete('accounts/(:num)', 'AccountsController::delete/$1');
            $routes->post('accounts/(:num)/move', 'AccountsController::move/$1');
            $routes->post('accounts/(:num)/deactivate', 'AccountsController::deactivate/$1');
            $routes->post('accounts/(:num)/reactivate', 'AccountsController::reactivate/$1');

            $routes->get('fiscal-years', 'FiscalYearsController::index');
            $routes->post('fiscal-years', 'FiscalYearsController::create');
            $routes->get('fiscal-years/(:num)/periods', 'FiscalYearsController::periods/$1');
            $routes->post('fiscal-years/(:num)/close', 'FiscalYearsController::close/$1');
            $routes->post('fiscal-years/(:num)/reopen', 'FiscalYearsController::reopen/$1');

            $routes->post('periods/(:num)/close', 'PeriodsController::close/$1');
            $routes->post('periods/(:num)/reopen', 'PeriodsController::reopen/$1');

            $routes->get('entries', 'EntriesController::index');
            $routes->post('entries', 'EntriesController::create');
            $routes->post('entries/batch', 'EntriesController::batch');
            $routes->get('entries/(:num)', 'EntriesController::show/$1');
            $routes->put('entries/(:num)', 'EntriesController::update/$1');
            $routes->patch('entries/(:num)', 'EntriesController::update/$1');
            $routes->delete('entries/(:num)', 'EntriesController::delete/$1');
            $routes->post('entries/(:num)/post', 'EntriesController::post/$1');
            $routes->post('entries/(:num)/reverse', 'EntriesController::reverse/$1');
            $routes->post('entries/(:num)/settle', 'EntriesController::settle/$1');

            $routes->get('reports/ledger', 'ReportsController::ledger');
            $routes->get('reports/journal', 'ReportsController::journal');
            $routes->get('reports/trial-balance', 'ReportsController::trialBalance');
            $routes->get('reports/income-statement', 'ReportsController::incomeStatement');
            $routes->get('reports/balance-sheet', 'ReportsController::balanceSheet');
            $routes->get('reports/by-source', 'ReportsController::bySource');
        },
    );
}
