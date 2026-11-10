<?php

/**
 * # Allegati: i permessi e i ruoli di serie (Coda 460, decisione 241.1)
 *
 * Un permesso per azione, per le segnalazioni e per le comunicazioni, come i commenti. I quattro ruoli di
 * serie non si modificano dall'interfaccia e il seeder li riallinea a ogni aggiornamento: ciò che dicono qui
 * vale per tutti. Utente e fornitore caricano sulle segnalazioni, non sulle comunicazioni; il collaboratore
 * carica e pubblica, ma l'approvazione e i file degli altri sono dell'amministratore, che li concede a mano.
 *
 * Cosa non copre: chi concede i permessi a chi (resta la regola di oggi, `ValidaConcessioneRuoli`; la Coda 543
 * riserva il pannello all'amministratore, e senza pannello l'approvazione non apre niente, vedi
 * `AllegatiModerazioneTest`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function algPermessiDelRuolo(string $ruolo): array
{
    return RuoloSpatie::findByName($ruolo, 'web')->permissions->pluck('name')->sort()->values()->all();
}

test('i sedici permessi nuovi esistono, con la loro descrizione', function () {
    $nomi = collect(ALG_PERMESSI)->flatten()->all();
    expect($nomi)->toHaveCount(16);

    foreach ($nomi as $nome) {
        $caso = Permission::tryFrom($nome);
        expect($caso)->not->toBeNull("Manca il permesso «{$nome}» in App\\Enums\\Permission")
            ->and($caso->description())->toBeString()->not->toBe('');
    }
});

test('l\'amministratore ha tutti i permessi nuovi', function () {
    expect(algPermessiDelRuolo(Role::AMMINISTRATORE->value))->toContain(...collect(ALG_PERMESSI)->flatten()->all());
});

test('utente e fornitore caricano, pubblicano, modificano ed eliminano i propri allegati delle segnalazioni, e vedono quelli delle comunicazioni', function () {
    $s = ALG_PERMESSI['segnalazioni'];
    $c = ALG_PERMESSI['comunicazioni'];
    $attesi = [$s['carica'], $s['pubblica'], $s['modifica_propri'], $s['elimina_propri'], $s['visualizza'], $c['visualizza']];
    $esclusi = [$s['approva'], $s['modifica'], $s['elimina'], $c['carica'], $c['pubblica'], $c['approva'], $c['modifica'], $c['modifica_propri'], $c['elimina'], $c['elimina_propri']];

    foreach ([Role::UTENTE->value, Role::FORNITORE->value] as $ruolo) {
        $permessi = algPermessiDelRuolo($ruolo);
        expect($permessi)->toContain(...$attesi);
        foreach ($esclusi as $escluso) {
            expect($permessi)->not->toContain($escluso, "Il ruolo {$ruolo} non deve avere «{$escluso}»");
        }
    }
});

test('il collaboratore carica, pubblica, modifica ed elimina i propri, in tutte e due; non approva e non tocca i file degli altri', function () {
    $permessi = algPermessiDelRuolo(Role::COLLABORATORE->value);

    foreach (ALG_PERMESSI as $tipo => $p) {
        expect($permessi)->toContain($p['carica'], $p['pubblica'], $p['modifica_propri'], $p['elimina_propri'], $p['visualizza']);
        foreach (['approva', 'modifica', 'elimina'] as $azione) {
            expect($permessi)->not->toContain($p[$azione], "Il collaboratore non deve avere «{$p[$azione]}» di serie");
        }
    }
});

test('l\'aggiornamento porta i permessi nuovi nei ruoli di serie, come per ogni permesso (SystemFinalizer)', function () {
    // Un'installazione aggiornata da prima degli allegati: i ruoli di serie senza i permessi nuovi.
    RuoloSpatie::findByName(Role::UTENTE->value, 'web')->revokePermissionTo(ALG_PERMESSI['segnalazioni']['carica']);

    app(\App\Services\System\SystemFinalizer::class)->sincronizzaRuoliEPermessi();

    expect(algPermessiDelRuolo(Role::UTENTE->value))->toContain(ALG_PERMESSI['segnalazioni']['carica']);
});
