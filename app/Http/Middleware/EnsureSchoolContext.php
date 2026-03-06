<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSchoolContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->school_id) {
            return response()->json([
                'success' => false,
                'message' => 'School context required',
            ], 403);
        }

        config(['app.current_school_id' => $user->school_id]);
        
        return $next($request);
    }
}