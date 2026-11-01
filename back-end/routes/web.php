<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:20,1');
