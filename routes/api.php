<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\PdfProxyController;
use App\Http\Controllers\PdfAdminController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('pdf')->name('pdf.')->group(function () {
    Route::get('list', [\App\Http\Controllers\PdfController::class, 'index'])->name('list')->middleware('throttle:60,1');
    Route::get('view', [\App\Http\Controllers\PdfProxyController::class, 'show'])->name('view')->middleware('throttle:60,1');

    Route::get('last-sync', [PdfAdminController::class, 'lastSync'])->name('lastSync')->middleware('throttle:30,1');
    Route::post('import', [PdfAdminController::class, 'import'])->name('import')->middleware('throttle:pdf-import');
});
