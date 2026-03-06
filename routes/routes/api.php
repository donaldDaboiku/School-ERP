<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\StudentController;

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Auth
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);

    /*
    |--------------------------------------------------------------------------
    | Protected API
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'school.context'])->group(function () {

        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', function (Request $request) {
            return response()->json($request->user());
        });

        // Students (Admin & Teacher)
        Route::middleware('role:admin,teacher')->group(function () {
            Route::apiResource('students', StudentController::class);
        });

    });
});

/*
|--------------------------------------------------------------------------
| System / Health
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the School Management System API',
    ]);
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'OK',
        'app' => config('app.name'),
    ]);
});
