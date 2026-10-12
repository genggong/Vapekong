<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->match(['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'], '/', 'LegacyController::root');
$routes->match(['GET','POST','PUT','PATCH','DELETE','OPTIONS','HEAD'], '(:any)', 'LegacyController::handle/$1');
