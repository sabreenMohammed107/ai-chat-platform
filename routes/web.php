<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Portal;
use Illuminate\Support\Facades\Route;

// Clients land on their portal; the admin panel is at /admin
Route::redirect('/', '/portal');
Route::redirect('login', '/portal/login');

// The client's dashboard (its own session: SeparateAreaSessions)
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showPortal'])->name('login');
        Route::post('login', [AuthController::class, 'loginPortal'])->middleware('throttle:10,1');
    });
    Route::post('logout', [AuthController::class, 'logoutPortal'])->name('logout');
    // The admin's one-time link from "Open portal"
    Route::get('enter/{token}', [Portal\DashboardController::class, 'enter'])->name('enter');

    Route::middleware('tenant')->group(function () {
        Route::get('/', [Portal\DashboardController::class, 'index'])->name('dashboard');
        Route::get('conversations', [Portal\ConversationController::class, 'index'])->name('conversations');
        Route::get('conversations/{uuid}', [Portal\ConversationController::class, 'show'])->name('conversations.show');

        Route::get('knowledge', [Portal\KnowledgeController::class, 'index'])->name('knowledge');
        Route::post('knowledge/website', [Portal\KnowledgeController::class, 'storeWebsite'])->name('knowledge.website');
        Route::post('knowledge/faq', [Portal\KnowledgeController::class, 'storeFaq'])->name('knowledge.faq');
        Route::post('knowledge/file', [Portal\KnowledgeController::class, 'storeFile'])->name('knowledge.file');
        Route::post('knowledge/{source}/sync', [Portal\KnowledgeController::class, 'sync'])->name('knowledge.sync');
        Route::delete('knowledge/reset', [Portal\KnowledgeController::class, 'reset'])->name('knowledge.reset');
        Route::delete('knowledge/{source}', [Portal\KnowledgeController::class, 'destroy'])->name('knowledge.destroy');

        Route::get('keys', [Portal\KeyController::class, 'index'])->name('keys');
        Route::post('keys/secret', [Portal\KeyController::class, 'storeSecret'])->name('keys.secret');
        Route::post('keys/public', [Portal\KeyController::class, 'rotatePublic'])->name('keys.public');
        Route::put('keys/domains', [Portal\KeyController::class, 'domains'])->name('keys.domains');
        Route::delete('keys/{key}', [Portal\KeyController::class, 'revoke'])->name('keys.revoke');

        Route::get('integration', [Portal\DashboardController::class, 'integration'])->name('integration');
        Route::get('settings', [Portal\DashboardController::class, 'settings'])->name('settings');
        Route::put('settings', [Portal\DashboardController::class, 'updateSettings'])->name('settings.update');
        Route::post('try', [Portal\DashboardController::class, 'try'])->middleware('throttle:20,1')->name('try');
    });
});

// The platform owner's panel (its own session: SeparateAreaSessions)
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showAdmin'])->name('login');
        Route::post('login', [AuthController::class, 'loginAdmin'])->middleware('throttle:10,1');
    });
    Route::post('logout', [AuthController::class, 'logoutAdmin'])->name('logout');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('/', [Admin\TenantController::class, 'index'])->name('dashboard');
        Route::get('tenants/create', [Admin\TenantController::class, 'create'])->name('tenants.create');
        Route::post('tenants', [Admin\TenantController::class, 'store'])->name('tenants.store');
        Route::get('tenants/{tenant}', [Admin\TenantController::class, 'edit'])->name('tenants.edit');
        Route::put('tenants/{tenant}', [Admin\TenantController::class, 'update'])->name('tenants.update');
        Route::post('tenants/{tenant}/subscriptions', [Admin\TenantController::class, 'subscribe'])->name('tenants.subscribe');
        Route::post('tenants/{tenant}/users', [Admin\TenantController::class, 'addUser'])->name('tenants.users');
        Route::post('tenants/{tenant}/open', [Admin\TenantController::class, 'open'])->name('tenants.open');

        Route::get('plans', [Admin\PlanController::class, 'index'])->name('plans');
        Route::post('plans', [Admin\PlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [Admin\PlanController::class, 'update'])->name('plans.update');
    });
});
