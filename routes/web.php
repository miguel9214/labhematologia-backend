<?php

use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});



Route::get('/debug-php', function () {
    return [
        'php_version' => phpversion(),
        'loaded_ini' => php_ini_loaded_file(),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'temp_dir' => sys_get_temp_dir(),
        'is_temp_writable' => is_writable(sys_get_temp_dir()),
        'max_execution_time' => ini_get('max_execution_time'),
    ];
});
