<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SchoolContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->school_id) {
            return response()->json([
                'message' => 'School context not found'
            ], 403);
        }

        // Share school_id globally
        app()->instance('school_id', $user->school_id);

        return $next($request);
    }
}

