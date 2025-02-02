<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api', function ($routes) {
    $routes->post('coasters/', 'Api\CoasterController::create');
    $routes->get('coasters/list', 'Api\CoasterController::list');
    $routes->put('coasters/store/(:segment)', 'Api\CoasterController::store/$1');
    $routes->delete('coasters/delete/(:segment)', 'Api\CoasterController::delete/$1');

    $routes->post('coasters/(:any)/wagons', 'Api\WagonController::create/$1');
    $routes->get('coasters/(:any)/wagons', 'Api\WagonController::list/$1');
    $routes->put('coasters/(:any)/wagons/(:any)', 'Api\WagonController::store/$1/$2');
    $routes->delete('coasters/(:any)/wagons/(:any)', 'Api\WagonController::delete/$1/$2');
});