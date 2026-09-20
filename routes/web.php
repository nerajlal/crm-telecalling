<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1');
});
Route::post('/webhooks/exotel/{call}/{token}', WebhookController::class)->middleware('throttle:120,1')->name('webhooks.exotel');
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/account', 'auth.account')->name('account');
    Route::put('/account/password', [AuthController::class, 'password'])->middleware('throttle:5,1')->name('account.password');
    Route::middleware('owner')->group(function () {
        Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::get('/leads/import', [LeadController::class, 'importForm'])->name('leads.import');
        Route::post('/leads/import', [LeadController::class, 'import'])->middleware('throttle:5,1')->name('leads.import.store');
        Route::get('/leads/sample', [LeadController::class, 'sample'])->name('leads.sample');
        Route::resource('employees', EmployeeController::class)->except(['show', 'destroy']);
        Route::get('/settings', SettingsController::class)->name('settings');
        Route::post('/calls/{call}/reconcile', [CallController::class, 'reconcile'])->middleware('throttle:10,1')->name('calls.reconcile');
        Route::post('/calls/{call}/resolve', [CallController::class, 'resolve'])->name('calls.resolve');
    });
    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('/leads/{lead}/notes', [LeadController::class, 'note'])->name('leads.notes');
    Route::post('/leads/{lead}/calls', [CallController::class, 'store'])->middleware('throttle:5,1')->name('calls.store');
    Route::get('/calls', [CallController::class, 'index'])->name('calls.index');
    Route::post('/calls/{call}/demo', [CallController::class, 'demo'])->name('calls.demo');
    Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
    Route::post('/leads/{lead}/follow-ups', [FollowUpController::class, 'store'])->name('follow-ups.store');
    Route::put('/follow-ups/{followUp}',[FollowUpController::class, 'update'])->name('follow-ups.update');
});

// API Route for React Native App to Sync Call Logs
// Assuming token or separate authentication will be configured later.
Route::post('/api/calls/sync', [\App\Http\Controllers\Api\CallController::class, 'sync'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
