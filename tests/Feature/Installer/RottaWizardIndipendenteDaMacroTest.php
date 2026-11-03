<?php

use App\Livewire\Installer\InstallerWizard;
use Illuminate\Support\Facades\Route;

/**
 * La rotta del wizard non deve dipendere da una macro del vendor.
 *
 * ⚠️ **`Route::livewire()` non è un metodo di Laravel: è una macro, e la registra soltanto
 * Livewire 4.** `routes/installer.php` viene letto dal `boot()` di AppServiceProvider, cioè a
 * ogni avvio di ogni processo — web e console. Finché quel file usava la macro, bastava un
 * `vendor/` non allineato al codice per rendere il programma **inavviabile**: morivano
 * `migrate`, `optimize:clear`, `route:list` e `about`, cioè proprio gli strumenti con cui si
 * porta a termine un aggiornamento e si capisce cosa è andato storto. Il messaggio che si
 * riceveva — «Attribute [livewire] does not exist» — non nomina né Livewire né composer.
 *
 * **Il caso reale.** Il 26/08/2026 l'aggiornamento della demo su DigitalOcean si è fermato lì.
 * Chi sale dalla 1.9.x attraversa per forza quella finestra: in 1.9.1 Livewire era la **3.7.10**,
 * arrivata di rimbalzo come dipendenza di `eii/laravel-installer`, e fra il `git pull` e il
 * `composer install` il codice è già nuovo mentre le dipendenze sono ancora vecchie. Riprodotto
 * su una 1.9.1 vera: con la macro `migrate` muore, con `Route::get()` le migrazioni passano tutte.
 *
 * **Perché una guardia e non solo la correzione.** La forma con la macro è quella che si trova
 * scritta ovunque, ed è l'unica documentata nei materiali di Livewire 3: chi tornasse su questo
 * file per aggiungere un passo la riscriverebbe senza pensarci, e nessuno se ne accorgerebbe fino
 * al prossimo aggiornamento di qualcuno. I test dell'installer esistenti usano `Livewire::test()`,
 * che **salta il routing** e quindi non vedrebbe niente.
 *
 * La forma diretta non è un ripiego: Livewire la documenta nel trait `HandlesPageComponents`
 * («users can pass Livewire components into Routes as if they were simple invokable
 * controllers») e `SupportPageComponents` la tratta come «Case 1», con la macro come «Case 2».
 */
it('registra il passo del wizard puntando al componente, non alla macro di Livewire', function () {
    $rotta = Route::getRoutes()->getByName('install.step');

    expect($rotta)->not->toBeNull('la rotta install.step non è registrata');

    $azione = $rotta->getAction('uses');

    // Con Route::get($uri, Componente::class) Laravel memorizza «Classe@__invoke».
    // Con Route::livewire() memorizzerebbe il LivewirePageController del vendor.
    expect($azione)->toBe(InstallerWizard::class.'@__invoke');
    expect($azione)->not->toContain('LivewirePageController');
    expect($rotta->getAction())->not->toHaveKey('livewire_component');
});

it('serve il wizard su /install/{step} quando l\'installer è acceso', function () {
    // Il middleware CheckInstaller legge la configurazione a ogni richiesta, quindi
    // impostarla qui basta. Non rianima UpgradePatchServiceProvider, che decide nel
    // proprio boot() — già avvenuto, e con l'installer spento.
    config()->set('installer.run_installer', true);

    $this->get('/install/welcome')
        ->assertOk()
        ->assertSee('wire:snapshot', false);
});
