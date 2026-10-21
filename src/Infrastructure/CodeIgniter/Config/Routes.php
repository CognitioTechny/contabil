<?php

declare(strict_types=1);

/**
 * API routes of the package, discovered by CodeIgniter. Switch them off with
 * `$routesEnabled = false` in `app/Config/Contabil.php`.
 *
 * @var CodeIgniter\Router\RouteCollection $routes
 */

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
        },
    );
}
