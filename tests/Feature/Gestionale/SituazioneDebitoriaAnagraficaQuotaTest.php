<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Il payload della situazione debitoria deve portare, su ogni voce di `dettaglio_quote`,
 * l'`anagrafica_id` della quota.
 *
 * Non è un dato decorativo: la pagina di incasso lo usa per decidere **di chi è** il credito
 * di una riga a saldo misto. Cercando per unità immobiliare il gruppo raccoglie le quote di
 * tutti i comproprietari, quindi una riga può portare il credito di una persona e il debito
 * di un'altra; senza questo campo il pulsante «Usa credito» offrirebbe il credito altrui a
 * chi sta incassando, e il salvataggio verrebbe rifiutato — oppure pagherebbe il debito di uno
 * con il credito di un altro, a seconda dell'ordine di inserimento delle quote.
 *
 * (Quel rifiuto era una `RuntimeException` non catturata, cioè una pagina 500, fino alla
 * beta.49: ora è `CreditoDiAltroSoggettoException` e torna al modulo compilato. Il campo qui
 * resta necessario lo stesso — serve a non arrivarci.)
 *
 * Perché questo test esiste accanto a quello del componente: il test JS lavora su una
 * fixture che il campo ce l'ha. Se il server smettesse di mandarlo, quel test resterebbe
 * verde e il pulsante sparirebbe in silenzio — il ripiego è sicuro (non si offre niente), ma
 * la funzione morirebbe senza che nessuno se ne accorga. Questo lato fissa la fonte.
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Un'unità con due intestatari: uno a credito sulla rata, l'altro a debito. */
function scenarioComproprietari(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Comproprietari',
        'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano',
        'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2025',
        'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria',
        'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 5', 'descrizione' => 'Appartamento',
        'interno' => '5', 'foglio' => '1', 'particella' => '1', 'subalterno' => '5',
    ]);

    $crea = function (string $nome, string $email, string $cf) use ($condominio, $immobile) {
        $a = Anagrafica::create([
            'condominio_id' => $condominio->id, 'nome' => $nome, 'email' => $email,
            'indirizzo' => 'Via Verdi 10', 'cap' => '00100',
            'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => $cf,
        ]);
        $immobile->anagrafiche()->attach($a->id, [
            'tipologia' => 'proprietario', 'quota' => 50,
            'attivo' => true, 'data_inizio' => now()->subYear(),
        ]);

        return $a;
    };

    $rossi   = $crea('Rossi Mario', 'rossi@test.it', 'RSSMRA80A01H501U');
    $bianchi = $crea('Bianchi Anna', 'bianchi@test.it', 'BNCNNA85B41H501X');

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => 'Piano', 'numero_rate' => 1,
    ]);
    $rata = Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => 1,
        'data_scadenza' => '2025-03-31', 'importo_totale' => 0, 'stato' => 'emessa',
    ]);

    // Rossi deve 250, Bianchi ha 250 di credito: il netto della rata è zero.
    RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $rossi->id, 'immobile_id' => $immobile->id,
        'importo' => 25000, 'importo_pagato' => 0,
        'stato' => 'da_pagare', 'data_scadenza' => '2025-03-31',
    ]);
    RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $bianchi->id, 'immobile_id' => $immobile->id,
        'importo' => 10000, 'importo_pagato' => 35000,
        'stato' => 'pagata', 'data_scadenza' => '2025-03-31',
    ]);

    return (object) compact('condominio', 'immobile', 'rossi', 'bianchi');
}

test('ogni quota del dettaglio dichiara a chi appartiene', function () {
    $s = scenarioComproprietari();

    $risposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [
        'condominio'  => $s->condominio->id,
        'immobile_id' => $s->immobile->id,
    ]));

    $risposta->assertOk();

    $quote = $risposta->json('rate.0.dettaglio_quote');
    expect($quote)->toHaveCount(2);

    foreach ($quote as $q) {
        expect($q)->toHaveKey('anagrafica_id');
        expect($q['anagrafica_id'])->toBeInt();
    }
});

test('il credito e il debito della riga mista sono attribuiti a due persone diverse', function () {
    // È la forma dei dati su cui poggia la guardia della pagina di incasso: se un giorno il
    // gruppo smettesse di mescolare le anagrafiche, questa asserzione si accende e ricorda
    // che quella guardia ha smesso di servire a qualcosa.
    $s = scenarioComproprietari();

    $risposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', [
        'condominio'  => $s->condominio->id,
        'immobile_id' => $s->immobile->id,
    ]));

    $quote = collect($risposta->json('rate.0.dettaglio_quote'));

    $credito = $quote->firstWhere('is_credito', true);
    $debito  = $quote->firstWhere('is_credito', false);

    expect($credito['anagrafica_id'])->toBe($s->bianchi->id);
    expect($debito['anagrafica_id'])->toBe($s->rossi->id);
    expect($credito['anagrafica_id'])->not->toBe($debito['anagrafica_id']);
});
