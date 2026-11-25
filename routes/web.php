<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'docs' => 'See README.md. All endpoints are under /api and need the X-API-Key header.',
]));
