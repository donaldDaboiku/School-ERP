<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Parent Routes
|--------------------------------------------------------------------------
| Routes accessible by authenticated parents
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth'])
    ->prefix('parent')
    ->name('parent.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return response()->json([
                'message' => 'Parent dashboard working',
            ]);
        });

        Route::get('/children', function () {
            return response()->json([
                'message' => 'Parent children endpoint',
            ]);
        });
        Route::get('/results', function ($id) {
            return response()->json([
                'message' => 'Parent  view results endpoint',
            ]);
        });

    }); 