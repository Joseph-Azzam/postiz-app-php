<?php

use App\Http\Controllers\Api\PublicPostController;
use App\Http\Controllers\TestDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Test dashboard: suite (full|gemini|db|social), no_ai=1, key=… (if POSTIZ_TEST_KEY set)
Route::get('test', TestDashboardController::class)->name('test.dashboard');

// Public (unauthenticated) post preview – same path as NestJS for frontend parity
Route::get('public/posts/{id}', [PublicPostController::class, 'preview']);
Route::get('public/posts/{id}/comments', [PublicPostController::class, 'comments']);
