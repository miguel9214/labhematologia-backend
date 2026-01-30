<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PdfProxyController;
use App\Http\Controllers\PdfAdminController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Públicas: listado, vista de PDF, login.
| Protegidas (auth:sanctum): subida, importación, last-sync.
|
*/

Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth:sanctum');

Route::prefix('pdf')->name('pdf.')->group(function () {
    // Públicas: búsqueda/listado, visualización y última sincronización (solo lectura)
    Route::get('list', [PdfController::class, 'index'])->name('list')->middleware('throttle:60,1');
    Route::get('view', [PdfProxyController::class, 'show'])->name('view')->middleware(['signed', 'throttle:60,1']);
    Route::get('last-sync', [PdfAdminController::class, 'lastSync'])->name('lastSync')->middleware('throttle:30,1');
    Route::post('import', [PdfAdminController::class, 'import'])->name('import')->middleware('throttle:pdf-import');
    // Protegidas (auth:sanctum): subida e importación
    Route::middleware('auth:sanctum')->group(function () {
        
        Route::post('upload', [\App\Http\Controllers\PdfUploadController::class, '__invoke'])->name('upload')->middleware('throttle:pdf-upload');
    });
});
