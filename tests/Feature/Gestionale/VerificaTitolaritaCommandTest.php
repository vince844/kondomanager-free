<?php

/**
 * beta.50 — Il comando che dice a chi si è fidato delle date di competenza.
 *
 * `anagrafica_immobile` ha `data_inizio` e `data_fine` dalla creazione della tabella, e
 * **nessun calcolo le legge**: gli otto punti che decidono chi paga filtrano su `attivo` e
 * `tipologia`, e il motore non ha una dimensione temporale. Sette testi dell'interfaccia
 * promettevano il contrario, e la beta.50 li ha riscritti.
 *
 * Correggere la scritta però non dice a un amministratore **se è successo a lui**. Chi ha
 * compilato la data di uscita credendo che interrompesse l'addebito sta addebitando il
 * venditore da mesi. Questo comando è quel modo: *un avviso senza lo strumento per misurare
 * il danno è metà lavoro.*
 *
 * ## Il segnale che conta è il terzo
 *
 * I primi due — data di fine compilata, associazioni spente — dicono «guarda qui». Il terzo
 * misura un danno in euro: due titolari al 100 % sulla stessa unità si normalizzano a 200 e
 * prendono **metà spesa ciascuno**, identico per un rogito di gennaio e uno di dicembre. È
 * il subentro rappresentato come comproprietà, ed è il modo in cui la maggior parte degli
 * amministratori lo registra oggi, perché è l'unico che l'interfaccia consente.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function unitaConTitolari(Condominio $condominio, string $interno, array $titolari): Immobile
{
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => $interno,
        'nome' => "Int. $interno", 'codice_immobile' => "VT-$interno", 'descrizione' => 'Test',
    ]);

    foreach ($titolari as $t) {
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => Anagrafica::factory()->create(['nome' => $t['nome']])->id,
            'immobile_id'   => $immobile->id,
            'tipologia'     => $t['tipologia'] ?? 'proprietario',
            'quota'         => $t['quota'],
            'attivo'        => $t['attivo'] ?? true,
            'data_inizio'   => $t['data_inizio'] ?? now()->subYear(),
            'data_fine'     => $t['data_fine'] ?? null,
        ]);
    }

    return $immobile;
}

it('tace quando non c\'è niente da segnalare', function () {
    $c = Condominio::factory()->create(['nome' => 'Tutto in ordine']);
    unitaConTitolari($c, '1', [['nome' => 'Rossi Mario', 'quota' => 100]]);

    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])
        ->expectsOutputToContain('Nessun segnale')
        ->assertSuccessful();
});

it('elenca chi ha compilato la data di fine credendo che interrompesse l\'addebito', function () {
    $c = Condominio::factory()->create(['nome' => 'Con data fine']);
    unitaConTitolari($c, '2', [
        ['nome' => 'Venditore Anna', 'quota' => 100, 'data_fine' => '2026-06-30'],
    ]);

    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])
        ->expectsOutputToContain('Data fine compilata')
        ->expectsOutputToContain('Venditore Anna')
        ->assertSuccessful();
});

it('segnala il subentro registrato come comproprietà, che è quello che costa denaro', function () {
    // ⚠️ È il caso vero: il rogito di giugno registrato aggiungendo il compratore senza poter
    // togliere il venditore — perché la validazione della pivot rifiuta il secondo titolare al
    // 100 e l'unica strada che l'interfaccia lascia è metterli entrambi. Il motore normalizza
    // su 200 e addebita metà spesa a chi ha venduto.
    $c = Condominio::factory()->create(['nome' => 'Subentro come comproprietà']);
    unitaConTitolari($c, '3', [
        ['nome' => 'Venditore Anna',  'quota' => 100],
        ['nome' => 'Compratore Luca', 'quota' => 100],
    ]);

    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])
        ->expectsOutputToContain('Quote che non fanno 100')
        ->expectsOutputToContain('200')
        ->assertSuccessful();
});

it('non scambia per anomalia una comproprietà vera al 50 e 50', function () {
    // Controprova: due comproprietari che sommano 100 sono la normalità, non un subentro.
    $c = Condominio::factory()->create(['nome' => 'Comproprietà legittima']);
    unitaConTitolari($c, '4', [
        ['nome' => 'Bianchi Luca', 'quota' => 50],
        ['nome' => 'Bianchi Anna', 'quota' => 50],
    ]);

    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])
        ->expectsOutputToContain('Nessun segnale')
        ->assertSuccessful();
});

it('una riga spenta non entra nel conto delle quote, perché il motore non la somma', function () {
    // ⚠️ La distinzione che rende il segnale utile invece che rumoroso. Un venditore
    // **disattivato** non partecipa al riparto: la sua quota non finisce nel denominatore, e
    // la comproprietà residua è corretta. Contarlo darebbe un falso allarme proprio a chi ha
    // fatto la cosa giusta.
    $c = Condominio::factory()->create(['nome' => 'Venditore disattivato']);
    unitaConTitolari($c, '5', [
        ['nome' => 'Venditore Anna',  'quota' => 100, 'attivo' => false],
        ['nome' => 'Compratore Luca', 'quota' => 100],
    ]);

    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])
        ->doesntExpectOutputToContain('Quote che non fanno 100')
        ->expectsOutputToContain('Associazioni disattivate')
        ->assertSuccessful();
});

it('non modifica niente', function () {
    $c = Condominio::factory()->create(['nome' => 'Sola lettura']);
    unitaConTitolari($c, '6', [
        ['nome' => 'Venditore Anna',  'quota' => 100, 'data_fine' => '2026-06-30'],
        ['nome' => 'Compratore Luca', 'quota' => 100],
    ]);

    $prima = DB::table('anagrafica_immobile')->orderBy('id')->get()->toJson();
    $this->artisan('kondomanager:verifica-titolarita', ['--condominio' => $c->id])->assertSuccessful();

    expect(DB::table('anagrafica_immobile')->orderBy('id')->get()->toJson())->toBe($prima);
});
