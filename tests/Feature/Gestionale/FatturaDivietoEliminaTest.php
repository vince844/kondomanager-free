<?php

/**
 * beta.34 — Il divieto sull'«Elimina» smette di essere muto.
 *
 * `FatturaPassivaController::destroy()` rifiutava l'eliminazione per SETTE
 * motivi distinti, ognuno col suo messaggio. Il menu della riga ne guardava
 * DUE (`stato_pagamento === 'aperta' && !is_stornata`), e quando la condizione
 * era falsa faceva sparire la voce senza dire perché.
 *
 * Due difetti opposti, entrambi reali:
 *   - chi non poteva eliminare non sapeva quale dei sette motivi lo riguardasse,
 *     né cosa fare per uscirne;
 *   - chi rientrava nei due controlli del menu poteva vedersi comparire la voce
 *     e ottenere comunque un rifiuto (fondo confermato, piano approvato, più
 *     scritture, esercizio chiuso).
 *
 * La correzione è una guardia sola: `FatturaPassiva::motivoBloccoEliminazione()`,
 * usata dalla destroy per decidere e dall'elenco per spiegare. Questi test la
 * inchiodano da entrambi i lati — se un domani qualcuno reintroducesse una
 * condizione solo nel controller, il payload smetterebbe di corrispondere.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

function utenteDivieto(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $user = \App\Models\User::factory()->create();
    $user->givePermissionTo('Accesso pannello amministratore');

    return $user;
}

function fatturaAperta(array $ctx): FatturaPassiva
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    return (new FatturaPassivaService())->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Manutenzione ascensore',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );
}

test('una fattura aperta non ha nessun motivo di blocco', function () {
    $ctx = setupContabile();
    $fattura = fatturaAperta($ctx);

    expect($fattura->motivoBloccoEliminazione())->toBeNull();
});

test('il motivo del divieto arriva nel payload dell\'elenco', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaAperta($ctx);
    $fattura->update(['stato_pagamento' => 'pagata']);

    $this->actingAs(utenteDivieto())
        ->get(route('admin.gestionale.fatture.index', $condominio->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/fatture/FatturaRegisterList')
            // Prima il frontend doveva dedurlo da sé, e ne conosceva due su sette.
            ->where('fatture.data.0.motivo_blocco_eliminazione', fn ($m) => is_string($m) && str_contains($m, 'pagata'))
        );
});

test('una fattura eliminabile viaggia col motivo a null', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    fatturaAperta($ctx);

    $this->actingAs(utenteDivieto())
        ->get(route('admin.gestionale.fatture.index', $condominio->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fatture.data.0.motivo_blocco_eliminazione', null)
        );
});

test('il messaggio dice sempre come uscirne', function () {
    $ctx = setupContabile();
    $fattura = fatturaAperta($ctx);
    $fattura->update(['stato_pagamento' => 'parziale']);

    $motivo = $fattura->fresh()->motivoBloccoEliminazione();

    // Il senso della beta: un divieto senza via d'uscita è ciò che la
    // segnalazione del forum descriveva come ansia, non come errore.
    expect($motivo)->toBeString()
        ->and($motivo)->toContain('Storno');
});

test('un esercizio chiuso blocca, e lo dice', function () {
    $ctx = setupContabile();
    [, $esercizio] = $ctx;
    $fattura = fatturaAperta($ctx);

    $esercizio->update(['stato' => 'chiuso']);

    expect($fattura->fresh()->motivoBloccoEliminazione())
        ->toBeString()
        ->toContain('esercizio chiuso');
});

test('una fattura gia stornata lo dichiara invece di sparire dal menu', function () {
    $ctx = setupContabile();
    $fattura = fatturaAperta($ctx);

    $extra = $fattura->dati_extra ?? [];
    $extra['is_stornata'] = true;
    $fattura->update(['dati_extra' => $extra]);

    expect($fattura->fresh()->motivoBloccoEliminazione())
        ->toBeString()
        ->toContain('storno');
});

test('la destroy rifiuta con lo stesso motivo che mostra l\'elenco', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaAperta($ctx);
    $fattura->update(['stato_pagamento' => 'pagata']);

    $motivoMostrato = $fattura->fresh()->motivoBloccoEliminazione();

    $this->actingAs(utenteDivieto())
        ->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]));

    // La fattura è ancora lì, e il messaggio flash è quello dell'elenco:
    // una guardia sola, non due che possono divergere.
    expect(FatturaPassiva::find($fattura->id))->not->toBeNull();
    expect(session('message.message') ?? '')->toContain($motivoMostrato);
});

test('la destroy continua a eliminare una fattura senza vincoli', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaAperta($ctx);

    $this->actingAs(utenteDivieto())
        ->delete(route('admin.gestionale.fatture.destroy', [$condominio->id, $fattura->id]))
        ->assertStatus(302);

    expect(FatturaPassiva::find($fattura->id))->toBeNull();
});
