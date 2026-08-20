<?php

/**
 * beta.61 — La pagina delle quote millesimali era scoperta **due volte**.
 *
 * Sotto `/gestionale/{condominio}/tabelle/{tabella}/quote` il binding implicito risolve i due
 * modelli per id, ciascuno per conto suo: niente lega la tabella al condominio dell'indirizzo. E
 * `UpdateQuoteRequest` validava l'unità con `exists:immobili,id`, **senza perimetro**.
 *
 * I due buchi non sono lo stesso buco, e nessuno dei due chiude l'altro:
 *
 * - la **tabella** arriva dall'indirizzo, e si chiude con una guardia sul modello legato;
 * - l'**unità** arriva dal corpo della richiesta, e non la chiuderebbe nemmeno
 *   `->scopeBindings()`, che sull'indirizzo non la vede passare.
 *
 * Il danno del primo era doppio, ed è la ragione per cui il caso non è teorico: la pagina mostrava
 * le quote **vere** della tabella e la tendina delle unità del condominio **dell'indirizzo**, e il
 * salvataggio comincia con `whereNotIn('id', $idsPresenti)->delete()`. Bastava aprire e salvare
 * per cancellare le quote di un condominio e scriverci sopra unità di un altro.
 */

use App\Models\Condominio;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function utenteConAccessoAlleQuote(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $utente = \App\Models\User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore');

    return $utente;
}

/** Un condominio con l'esercizio e il piano dei conti che i middleware della rotta pretendono. */
function condominioNavigabile(): Condominio
{
    $condominio = Condominio::factory()->create();

    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2026',
        'stato' => 'aperto',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Gestione Ordinaria',
        'tipo' => 'ordinaria',
        'attiva' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('piani_conti')->insert([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestioneId,
        'nome' => 'Piano dei conti 2026',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $condominio;
}

function tabellaDi(Condominio $condominio): Tabella
{
    return Tabella::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Proprietà generale',
        'tipo' => 'standard',
        'quota' => 'millesimi',
        'numero_decimali' => 2,
        'attiva' => true,
        'data_inizio' => now(),
    ]);
}

function immobileDi(Condominio $condominio, string $nome): int
{
    return DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id,
        'codice_immobile' => 'U'.uniqid(),
        'nome' => $nome,
        'attivo' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('la tabella di un altro condominio non si apre', function () {
    $mio = condominioNavigabile();
    $altrui = condominioNavigabile();
    $tabella = tabellaDi($altrui);

    $this->actingAs(utenteConAccessoAlleQuote())
        ->get(route('admin.gestionale.tabelle.quote.index', [$mio->id, $tabella->id]))
        ->assertNotFound();
});

test('la tabella di un altro condominio non si salva, e le sue quote restano', function () {
    $mio = condominioNavigabile();
    $altrui = condominioNavigabile();
    $tabella = tabellaDi($altrui);

    $suaUnita = immobileDi($altrui, 'Interno 1');
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id,
        'immobile_id' => $suaUnita,
        'valore' => 500,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $miaUnita = immobileDi($mio, 'Interno mio');

    $this->actingAs(utenteConAccessoAlleQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$mio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $miaUnita], 'valore' => '1000'],
            ],
        ])
        ->assertNotFound();

    // ⚠️ Il salvataggio comincia cancellando le righe non presenti nel modulo: se la guardia
    // arrivasse dopo la transazione, questa riga non ci sarebbe più.
    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});

test("l'unità di un altro condominio non si associa, anche sulla tabella giusta", function () {
    $mio = condominioNavigabile();
    $altrui = condominioNavigabile();
    $tabella = tabellaDi($mio);
    $unitaAltrui = immobileDi($altrui, 'Interno estraneo');

    $this->actingAs(utenteConAccessoAlleQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$mio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $unitaAltrui], 'valore' => '1000'],
            ],
        ])
        ->assertSessionHasErrors('quote.0.immobile.id');

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(0);
});

test('il caso legittimo continua a funzionare', function () {
    // Il controllo che conta: una guardia che blocca anche l'uso normale è un guasto, non una
    // difesa. È la lezione della beta.60 sul `replacer` — il test da scrivere è quello sul ramo
    // innocente.
    $mio = condominioNavigabile();
    $tabella = tabellaDi($mio);
    $unita = immobileDi($mio, 'Interno 1');

    $this->actingAs(utenteConAccessoAlleQuote())
        ->get(route('admin.gestionale.tabelle.quote.index', [$mio->id, $tabella->id]))
        ->assertOk();

    $this->actingAs(utenteConAccessoAlleQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$mio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $unita], 'valore' => '1000'],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});
