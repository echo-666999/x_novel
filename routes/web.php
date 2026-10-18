<?php

use App\Http\Controllers\BookExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 导出文件不放公开目录，登录和资源权限两层校验后才提供下载。
Route::get('/book-exports/{artifact}', BookExportController::class)->middleware('auth')->name('book-exports.download');
