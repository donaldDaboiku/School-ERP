<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Teacher Routes
|--------------------------------------------------------------------------
| Routes accessible by authenticated teachers
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth'])
    ->prefix('teacher')
    ->name('teacher.')
    ->group(function () {

        Route::get('/dashboard', function () {
            return response()->json([
                'message' => 'Teacher dashboard working',
            ]);
        });

        Route::get('/classes', function () {
            return response()->json([
                'message' => 'Teacher classes endpoint',
            ]);
        });

    });
