<?php

use Illuminate\Support\Facades\Route;
use hexa_package_wptoolkit\Http\Controllers\WpToolkitDashboardController;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('wp-toolkit', [WpToolkitDashboardController::class, 'index'])->name('wptoolkit.index');
    Route::post('wp-toolkit/get-installs', [WpToolkitDashboardController::class, 'getInstalls'])->name('wptoolkit.get-installs');
    Route::post('wp-toolkit/get-credentials', [WpToolkitDashboardController::class, 'getCredentials'])->name('wptoolkit.get-credentials');
    Route::post('wp-toolkit/wp-login', [WpToolkitDashboardController::class, 'wpLogin'])->name('wptoolkit.wp-login');
    Route::post('wp-toolkit/reset-password', [WpToolkitDashboardController::class, 'resetPassword'])->name('wptoolkit.reset-password');
    Route::post('wp-toolkit/cpanel-login', [WpToolkitDashboardController::class, 'cpanelLogin'])->name('wptoolkit.cpanel-login');
    Route::post('wp-toolkit/whm-reseller-login', [WpToolkitDashboardController::class, 'whmResellerLogin'])->name('wptoolkit.whm-reseller-login');
});
