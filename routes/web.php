<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard');

Route::prefix('api')->group(function () {
    Route::get('runs', [ReportController::class, 'runs']);
    Route::get('runs/{name}/report', [ReportController::class, 'report']);
    Route::get('runs/{name}/probes', [ReportController::class, 'probes']);
    Route::get('runs/{name}/people', [ReportController::class, 'people']);
});
