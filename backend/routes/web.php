<?php

use Illuminate\Support\Facades\Route;

// The user interface is the Vue SPA in /frontend; this server only hosts the API.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'status' => 'ok']));
