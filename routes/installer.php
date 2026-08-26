<?php

use App\Http\Controllers\Installer\InstallController;
use App\Http\Middleware\CheckInstaller;
use App\Livewire\Installer\InstallerWizard;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotte dell'installer
|--------------------------------------------------------------------------
|
| ⚠️ **Il passo si registra con `Route::get()`, non con `Route::livewire()`.**
|
| `Route::livewire()` non è un metodo di Laravel: è una macro, e la registra
| soltanto Livewire 4. Questo file viene letto al boot di **ogni** processo, web
| e console, quindi finché usava la macro bastava un `vendor/` non allineato al
| codice per rendere il programma inavviabile: morivano `migrate`,
| `optimize:clear`, `route:list`, `about` — cioè proprio gli strumenti con cui si
| finisce l'aggiornamento e si capisce cosa è successo. E il messaggio,
| «Attribute [livewire] does not exist», non nomina né Livewire né composer.
|
| Non è un caso di laboratorio: è la finestra che attraversa chiunque salga dalla
| 1.9.x. Là Livewire era la 3.7.10, arrivata di rimbalzo da `eii/laravel-installer`,
| e fra il `git pull` e il `composer install` il codice è già nuovo e le dipendenze
| ancora no. Misurato il 26/08/2026 su una 1.9.1 vera: con la macro `migrate`
| muore, con `Route::get()` le migrazioni passano tutte.
|
| La forma diretta non è un ripiego. Livewire la documenta nel trait
| `HandlesPageComponents` — «users can pass Livewire components into Routes as if
| they were simple invokable controllers» — e `SupportPageComponents` la tratta
| come «Case 1», mentre la macro è il «Case 2». Con Livewire 4 le due producono la
| stessa pagina byte per byte, stesso snapshot e stesso `{step}`; con Livewire 3
| questa sopravvive e quella no.
|
*/

Route::middleware(['web', CheckInstaller::class])->group(function () {
    Route::get('/install', [InstallController::class, 'start'])->name('install.start');
    Route::get('/install/{step}', InstallerWizard::class)->name('install.step');
});
