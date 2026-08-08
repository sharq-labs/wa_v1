<?php

use App\Http\Controllers\Api\ContactImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WhatsAppHealthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('/workspaces/{workspace}')
    ->middleware('workspace')
    ->group(function (): void {
        Route::get('/whatsapp-health', [WhatsAppHealthController::class, 'index']);

        Route::get('/contact-imports', [ContactImportController::class, 'index']);
        Route::post('/contact-imports/upload', [ContactImportController::class, 'upload']);
        Route::get('/contact-imports/{contactImport}', [ContactImportController::class, 'show']);
        Route::post('/contact-imports/{contactImport}/start', [ContactImportController::class, 'start']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    });
