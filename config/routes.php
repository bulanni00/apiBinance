<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *

 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');
Router::addRoute(['GET', 'POST', 'HEAD'], '/yue', 'App\Controller\ApiBinance@yue');
Router::addRoute(['GET', 'POST', 'HEAD'], '/authBinance', 'App\Controller\ApiBinance@authBinance');

Router::addRoute(['GET', 'POST', 'HEAD'], '/ceshi/index', 'App\Controller\CeshiController@index');
Router::addRoute(['GET', 'POST', 'HEAD'], '/yue/index', 'App\Controller\YueController@index');
Router::get('/favicon.ico', function () {
    return '';
});
