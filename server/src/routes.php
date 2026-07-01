<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('ai.api.routing.prefix', 'ai'))->namespace('Fleetbase\Ai\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Ai API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('ai.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->group(
                    ['prefix' => 'v1', 'middleware' => ['fleetbase.protected']],
                    function ($router) {
                        $router->get('config', 'Internal\AiConfigController@show');
                        $router->post('config', 'Internal\AiConfigController@store');
                        $router->post('test-provider', 'Internal\AiConfigController@testProvider');
                        $router->get('sessions', 'Internal\AiSessionController@index');
                        $router->post('sessions', 'Internal\AiSessionController@store');
                        $router->get('sessions/{id}', 'Internal\AiSessionController@show');
                        $router->post('sessions/{id}/end', 'Internal\AiSessionController@end');
                        $router->delete('sessions/{id}', 'Internal\AiSessionController@destroy');
                        $router->get('tasks', 'Internal\AiTaskController@index');
                        $router->post('tasks', 'Internal\AiTaskController@store');
                        $router->get('tasks/{id}', 'Internal\AiTaskController@show');
                        $router->post('tasks/{id}/preview', 'Internal\AiTaskController@preview');
                        $router->post('tasks/{id}/apply', 'Internal\AiTaskController@apply');
                        $router->post('tasks/{id}/cancel', 'Internal\AiTaskController@cancel');
                        $router->get('tools', 'Internal\AiToolController@index');

                        $router->prefix('admin')->group(function ($router) {
                            $router->get('companies', 'Internal\AiAdminController@companies');
                            $router->get('users', 'Internal\AiAdminController@users');
                            $router->get('sessions', 'Internal\AiAdminController@sessions');
                            $router->get('sessions/{id}', 'Internal\AiAdminController@session');
                            $router->get('tasks/{id}', 'Internal\AiAdminController@task');
                            $router->post('tasks/{id}/reveal-content', 'Internal\AiAdminController@revealTaskContent');
                            $router->get('usage', 'Internal\AiAdminController@usage');
                        });
                    }
                );
            }
        );
    }
);
