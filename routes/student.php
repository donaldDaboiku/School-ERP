<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Student Routes
|--------------------------------------------------------------------------
| Routes accessible by authenticated students
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth'])
    ->prefix('student')
    ->name('student.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return response()->json([
                'message' => 'Student dashboard working',
            ]);
        });

        Route::get('/results', function () {
            return response()->json([
                'message' => 'Student results endpoint',
            ]);
        });

    });
