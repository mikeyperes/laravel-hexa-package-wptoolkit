<?php

use Illuminate\Support\Facades\Route;
use hexa_package_wptoolkit\Http\Controllers\WpToolkitDashboardController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('wp-toolkit', [WpToolkitDashboardController::class, 'index'])->name('wptoolkit.index');
    Route::post('wp-toolkit/get-installs', [WpToolkitDashboardController::class, 'getInstalls'])->name('wptoolkit.get-installs');
    Route::post('wp-toolkit/get-credentials', [WpToolkitDashboardController::class, 'getCredentials'])->name('wptoolkit.get-credentials');
});
