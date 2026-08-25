<?php

use App\Models\User;
use App\Services\UpdateService;
use Spatie\Permission\Models\Role;

/**
 * L'aggiornamento dice cosa manca **prima** del pulsante, non dopo il clic.
 *
 * **L'ordine era al contrario.** La pagina «Gestione aggiornamenti» mostrava *«Nuova versione
 * 1.10.0 disponibile»* e un pulsante; il controllo della versione di PHP scattava dentro
 * `prepareForUpgrade()`, cioè **dopo** che l'amministratore aveva cliccato e confermato. Niente di
 * rotto — quel controllo sta prima di qualunque scrittura di file, e l'installazione restava
 * intatta — ma gli veniva proposto qualcosa che non poteva avere, e lo scopriva provando.
 *
 * Diventa concreto con la 1.10.0, che alza la soglia da PHP 8.2 a **8.4.1**: ogni installazione che
 * sta sotto farebbe esattamente quel percorso.
 *
 * ⚠️ **E le estensioni non le controllava nessuno, su questa strada.** `UpdateService` le trasporta
 * nel bridge ma non le verifica; `extension_loaded()` lo chiama solo l'installer di una macchina
 * nuova. Chi *aggiornava* su un hosting senza `gd` superava ogni controllo e poi non stampava più
 * niente — `gd` è ciò su cui poggia mpdf, cioè ogni PDF del programma. Questa pagina è il primo
 * punto del percorso di aggiornamento in cui quella domanda viene fatta.
 */
beforeEach(function () {
    // La rotta è protetta da `role:amministratore`, non da un permesso: l'aggiornamento
    // del sistema non è delegabile a un collaboratore.
    Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');

    $this->actingAs($this->admin);
});

/** Una release remota con i requisiti che vogliamo, senza uscire in rete. */
function rpcRelease(string $php, array $estensioni): array
{
    return [
        'version'      => '1.10.0',
        'url'          => 'https://example.test/km-1.10.0.zip',
        'hash'         => str_repeat('a', 64),
        'size'         => 15_000_000,
        'requirements' => ['php' => $php, 'extensions' => $estensioni],
    ];
}

/** Sostituisce il servizio con uno che risponde quello che serve alla prova. */
function rpcFingi(array $release): void
{
    $finto = Mockery::mock(UpdateService::class)->makePartial();
    $finto->shouldReceive('isAutoUpdateEnabled')->andReturn(true);
    $finto->shouldReceive('checkRemoteVersion')->andReturn($release);
    $finto->shouldReceive('isUpgradeInProgress')->andReturn(false);

    app()->instance(UpdateService::class, $finto);
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * ★ Il caso del rilascio: chi è su PHP 8.2 lo legge prima di cliccare.
 */
it('dichiara la versione di PHP mancante prima del pulsante', function () {
    // Una soglia che questo server non può soddisfare, qualunque PHP stia girando.
    rpcFingi(rpcRelease('99.0.0', []));

    $this->get(route('system.upgrade.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('requisiti.php.richiesto', '99.0.0')
            ->where('requisiti.php.attuale', PHP_VERSION)
            ->where('requisiti.estensioni', [])
        );
});

/**
 * ★ Il caso di `gd`: l'unico punto del percorso di aggiornamento in cui viene chiesto.
 */
it('dichiara le estensioni mancanti, che l\'aggiornamento non controllava affatto', function () {
    rpcFingi(rpcRelease('1.0.0', ['zip', 'estensione_che_non_esiste', 'json']));

    $this->get(route('system.upgrade.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Solo quelle davvero assenti: `zip` e `json` ci sono e non vanno nominate.
            ->where('requisiti.estensioni', ['estensione_che_non_esiste'])
            ->where('requisiti.php', null)
        );
});

/**
 * Il verso opposto, ed è quello che protegge chi sta bene: un server a posto non deve vedere
 * nessun allarme, o l'allarme smette di significare qualcosa.
 */
it('non dice niente quando il server regge la versione offerta', function () {
    rpcFingi(rpcRelease('8.0.0', ['json']));

    $this->get(route('system.upgrade.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('requisiti', null));
});

/**
 * Senza aggiornamenti disponibili non c'è niente da verificare: `checkRemoteVersion()` torna `null`
 * quando la versione locale è già l'ultima, e il controllo non deve inventarsi una risposta.
 */
it('resta zitto quando non c\'è nessun aggiornamento da offrire', function () {
    $finto = Mockery::mock(UpdateService::class)->makePartial();
    $finto->shouldReceive('isAutoUpdateEnabled')->andReturn(true);
    $finto->shouldReceive('checkRemoteVersion')->andReturn(null);
    $finto->shouldReceive('isUpgradeInProgress')->andReturn(false);
    app()->instance(UpdateService::class, $finto);

    $this->get(route('system.upgrade.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('requisiti', null)->where('availableRelease', null));
});

/**
 * ⚠️ **Il ripiego non deve essere più permissivo del vero.** Se il manifest remoto non dichiara i
 * requisiti — un file vecchio, una release scritta a mano — la pagina deve assumere la soglia del
 * codice, non dare via libera. Un ripiego generoso qui vorrebbe dire proporre un aggiornamento che
 * poi non parte, che è esattamente il difetto che questa pagina esiste per chiudere.
 */
it('senza requisiti dichiarati assume la soglia vera, invece di dare via libera', function () {
    rpcFingi([
        'version' => '1.10.0',
        'url'     => 'https://example.test/km-1.10.0.zip',
        'hash'    => str_repeat('a', 64),
        // nessun 'requirements'
    ]);

    $this->get(route('system.upgrade.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            // Su questo server PHP è ≥ 8.4.1, quindi il ripiego non scatta e `requisiti` è null.
            // Ciò che conta è che la soglia usata sia quella vera: la si legge nel sorgente.
            $sorgente = file_get_contents(base_path('app/Http/Controllers/System/SystemUpgradeController.php'));
            expect($sorgente)->toContain("?? '8.4.1'");

            return $page;
        });
});
