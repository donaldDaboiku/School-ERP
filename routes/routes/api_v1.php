<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
| Versioned API endpoints for School ERP
|--------------------------------------------------------------------------
*/

Route::prefix('v1')
    ->middleware(['api'])
    ->group(function () {

        Route::get('/health', function () {
            return response()->json([
                'status' => 'ok',
                'version' => 'v1',
                'app' => config('app.name'),
            ]);
        });
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::post('/users', [\App\Http\Controllers\Api\V1\Admin\UserController::class, 'store']);
});

        // Auth (later)
        // Route::post('/login', ...);

        // Students
        // Route::apiResource('students', StudentController::class);

        // Teachers
        // Route::apiResource('teachers', TeacherController::class);

    });
