<?php

use App\Http\Controllers\ComplianceController;
use Illuminate\Support\Facades\Route;

// Public compliance pages (required for Meta App Review).
Route::get('/legal/privacy', [ComplianceController::class, 'privacy']);
Route::get('/legal/terms', [ComplianceController::class, 'terms']);
Route::get('/legal/data-deletion', [ComplianceController::class, 'dataDeletion']);
Route::get('/legal/support', [ComplianceController::class, 'support']);
Route::get('/legal/company', [ComplianceController::class, 'company']);

// SPA catch-all: the React application handles everything else.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|legal|storage|broadcasting|horizon).*$');
