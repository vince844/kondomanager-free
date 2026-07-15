<?php

use App\Http\Controllers\Impostazioni\BackupSettingsController;
use App\Http\Controllers\Impostazioni\CronSettingsController;
use App\Http\Controllers\Impostazioni\ImpostazioniController;
use App\Http\Controllers\Impostazioni\ImpostazioniGeneraliController;
use App\Http\Controllers\Impostazioni\ImpostazioniStampeController;
use App\Http\Controllers\Impostazioni\LogsController;
use App\Http\Controllers\Impostazioni\MailSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {

    Route::get('impostazioni', ImpostazioniController::class)
        ->name('impostazioni');

    Route::get('impostazioni/generali', [ImpostazioniGeneraliController::class, '__invoke'])
        ->name('impostazioni.generali');

    Route::post('impostazioni/generali', [ImpostazioniGeneraliController::class, 'store'])
        ->name('impostazioni.generali.store');

    Route::get('impostazioni/stampe', [ImpostazioniStampeController::class, 'index'])
        ->name('impostazioni.stampe');

    Route::post('impostazioni/stampe', [ImpostazioniStampeController::class, 'store'])
        ->name('impostazioni.stampe.store');

    Route::get('impostazioni/cron', [CronSettingsController::class, 'edit'])
        ->name('impostazioni.cron');

    Route::post('impostazioni/cron', [CronSettingsController::class, 'update'])
        ->name('impostazioni.cron.update');

    Route::post('impostazioni/cron/regenerate', [CronSettingsController::class, 'regenerateToken'])
        ->name('impostazioni.cron.regenerate');

    // MAIL SETTINGS
    Route::get('impostazioni/mail', [MailSettingsController::class, 'edit'])
        ->name('impostazioni.mail');

    Route::post('impostazioni/mail', [MailSettingsController::class, 'update'])
        ->name('admin.settings.mail.update');

    Route::post('impostazioni/mail/test', [MailSettingsController::class, 'testConnection'])
        ->name('admin.settings.mail.test');

    // BACKUPS
    Route::get('impostazioni/backups', [BackupSettingsController::class, 'index'])
        ->name('impostazioni.backups');

    Route::post('impostazioni/backups', [BackupSettingsController::class, 'store'])
        ->name('impostazioni.backups.store');

    Route::post('impostazioni/backups/settings', [BackupSettingsController::class, 'updateSettings'])
        ->name('impostazioni.backups.settings');

    Route::post('impostazioni/backups/password', [BackupSettingsController::class, 'removePassword'])
        ->name('impostazioni.backups.password');

    Route::post('impostazioni/backups/{backup:uuid}/step', [BackupSettingsController::class, 'step'])
        ->name('impostazioni.backups.step');

    Route::get('impostazioni/backups/{backup:uuid}/download', [BackupSettingsController::class, 'download'])
        ->name('impostazioni.backups.download');

    Route::delete('impostazioni/backups/{backup:uuid}', [BackupSettingsController::class, 'destroy'])
        ->name('impostazioni.backups.destroy');

    // LOGS & AUDIT
    Route::get('logs', [LogsController::class, 'index'])
        ->name('logs.index');

    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])
        ->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::get('settings/two-factor', [TwoFactorAuthController::class, 'show'])
        ->name('two-factor.show');

    Route::post('settings/two-factor', [TwoFactorAuthController::class, 'enable'])
        ->name('two-factor.enable');

    Route::post('settings/two-factor/confirm', [TwoFactorAuthController::class, 'confirm'])
        ->name('two-factor.confirm');

    Route::post('settings/two-factor/recovery-codes', [TwoFactorAuthController::class, 'regenerateRecoveryCodes'])
        ->name('two-factor.regenerate-recovery-codes');

    Route::delete('settings/two-factor', [TwoFactorAuthController::class, 'disable'])
        ->name('two-factor.disable');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');
});
