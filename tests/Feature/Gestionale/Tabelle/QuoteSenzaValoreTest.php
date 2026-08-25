<?php

/**
 * beta.61 — Il millesimo si può lasciare vuoto, e resta vuoto.
 *
 * Fino alla .60 `quote.*.valore` era `required`. Sembrava una difesa e non lo era: chi spuntava
 * «associa tutti gli immobili esistenti» otteneva una tabella **non più salvabile** finché non
 * l'aveva compilata tutta, e la via d'uscita rapida era scrivere `0` — che il motore di riparto
 * legge come «non partecipa», escludendo quel condòmino e facendo pagare la sua quota agli altri.
 *
 * Questo file presidia la parte di salvataggio delle tre che compongono il rimedio: che il vuoto
 * si possa salvare, e che arrivi a database **come vuoto**. Se venisse scritto `0`, la beta non
 * introdurrebbe «il valore mancante»: introdurrebbe «lo zero silenzioso», e l'avviso alla
 * generazione — che guarda il NULL — non avrebbe niente da leggere.
 */

use App\Models\Condominio;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function utenteQuote(): \App\Models\User
{
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $utente = \App\Models\User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore');

    return $utente;
}

function condominioConQuote(): Condominio
{
    $condominio = Condominio::factory()->create();

    DB::table('esercizi')->insert([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'stato' => 'aperto',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Gestione Ordinaria', 'tipo' => 'ordinaria',
        'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('piani_conti')->insert([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestioneId,
        'nome' => 'Piano dei conti 2026', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $condominio;
}

function tabellaConQuote(Condominio $condominio): Tabella
{
    return Tabella::create([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà generale',
        'tipo' => 'standard', 'quota' => 'millesimi', 'numero_decimali' => 2,
        'attiva' => true, 'data_inizio' => now(),
    ]);
}

function unitaConQuote(Condominio $condominio, string $nome): int
{
    return DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'codice_immobile' => 'U'.uniqid(),
        'nome' => $nome, 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('una riga senza millesimo si salva, e arriva vuota a database', function () {
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $piena = unitaConQuote($condominio, 'Interno 1');
    $vuota = unitaConQuote($condominio, 'Interno 2');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $piena], 'valore' => '500'],
                ['id' => null, 'immobile' => ['id' => $vuota], 'valore' => ''],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $righe = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->pluck('valore', 'immobile_id');

    expect($righe)->toHaveCount(2)
        ->and((float) $righe[$piena])->toBe(500.0)
        // ⚠️ Il cuore del test: **null**, non `0`. Se qui ci fosse uno zero, «non ancora
        // compilato» e «non partecipa» tornerebbero a essere la stessa cosa e l'avviso alla
        // generazione non avrebbe niente da leggere.
        ->and($righe[$vuota])->toBeNull();
});

test('lo zero scritto apposta resta zero, e non diventa vuoto', function () {
    // I due stati devono restare distinti in **entrambe** le direzioni: lo zero è una scelta
    // dichiarata («questa unità non partecipa»), e collassarlo su NULL farebbe comparire un
    // avviso su una tabella corretta.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno terra');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $unita], 'valore' => '0'],
            ],
        ])
        ->assertSessionHasNoErrors();

    $valore = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->value('valore');

    expect($valore)->not->toBeNull()
        ->and((float) $valore)->toBe(0.0);
});

test('un valore non numerico continua a essere rifiutato', function () {
    // `nullable` allarga il vuoto, non il resto: «abc» resta un errore, con il suo messaggio.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [
                ['id' => null, 'immobile' => ['id' => $unita], 'valore' => 'abc'],
            ],
        ])
        ->assertSessionHasErrors('quote.0.valore');

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(0);
});

test('la tabella creata con «associa tutti» si può salvare compilandone una parte', function () {
    // È il percorso che il `required` rendeva un vicolo cieco: sedici righe vuote create dalla
    // casella, e nessun modo di salvare finché non erano piene tutte e sedici.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);

    $unita = [];
    for ($i = 1; $i <= 4; $i++) {
        $id = unitaConQuote($condominio, 'Interno '.$i);
        $unita[] = $id;
        DB::table('quote_tabella')->insert([
            'tabella_id' => $tabella->id, 'immobile_id' => $id, 'valore' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $righe = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->orderBy('id')->get();

    $payload = [];
    foreach ($righe as $indice => $riga) {
        $payload[] = [
            'id' => $riga->id,
            'immobile' => ['id' => $riga->immobile_id],
            'valore' => $indice < 2 ? '250' : '',   // due compilate, due ancora no
        ];
    }

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), ['quote' => $payload])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $valori = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->orderBy('id')->pluck('valore');

    expect($valori)->toHaveCount(4)
        ->and((float) $valori[0])->toBe(250.0)
        ->and((float) $valori[1])->toBe(250.0)
        ->and($valori[2])->toBeNull()
        ->and($valori[3])->toBeNull();
});

test('salvare due volte non cancella e ricrea le righe', function () {
    // ⚠️ Il difetto trovato dalla revisione avversariale della beta.61, e il più grosso dei suoi:
    // `Validator::$excludeUnvalidatedArrayKeys` vale `true`, quindi `validated()` restituisce solo
    // le chiavi che una regola nomina. `quote.*.id` non ne aveva una: l'id veniva scartato in
    // silenzio, `$idsPresenti` risultava sempre vuoto, e `whereNotIn('id', [])` compila in
    // `where 1 = 1`. Ogni salvataggio cancellava **tutte** le righe e le ricreava — il ramo che
    // aggiorna era codice morto.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    $utente = utenteQuote();

    $this->actingAs($utente)
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [['id' => null, 'immobile' => ['id' => $unita], 'valore' => '500']],
        ])->assertSessionHasNoErrors();

    $prima = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->first();

    // Secondo salvataggio, con l'id della riga come lo manda la pagina.
    $this->actingAs($utente)
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [['id' => $prima->id, 'immobile' => ['id' => $unita], 'valore' => '600']],
        ])->assertSessionHasNoErrors();

    $dopo = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->first();

    expect($dopo->id)->toBe($prima->id, "la riga dev'essere aggiornata, non ricreata")
        ->and((float) $dopo->valore)->toBe(600.0)
        ->and($dopo->created_by)->toBe($prima->created_by, 'la firma di chi ha creato la riga non si riscrive')
        ->and(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});

test('una richiesta senza la chiave «quote» non svuota la tabella', function () {
    // Prima rispondeva con un messaggio di successo dopo aver cancellato tutto: `'quote' =>
    // ['array']` non pretendeva che la chiave esistesse, e si ricadeva nel `whereNotIn('id', [])`.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $unita, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [])
        ->assertSessionHasErrors('quote');

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(1);
});

test('togliere tutte le righe resta possibile: elenco vuoto, non chiave assente', function () {
    // `present` e non `required`: svuotare una tabella è un'operazione legittima.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $unita, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), ['quote' => []])
        ->assertSessionHasNoErrors();

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(0);
});

test('il millesimo negativo viene rifiutato', function () {
    // ⚠️ Il terzo stato che la dottrina della beta non copriva: non è NULL (quindi non avvisa) ed
    // è ≤ 0 (quindi il motore lo salta), ma **entra nel divisore** e sposta la spesa fra tabelle.
    // Misurato: € 193,78 spostati su € 1.000,00 con un solo `-900`.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [['id' => null, 'immobile' => ['id' => $unita], 'valore' => '-900']],
        ])
        ->assertSessionHasErrors('quote.0.valore');

    expect(DB::table('quote_tabella')->where('tabella_id', $tabella->id)->count())->toBe(0);
});

test('lo zero resta ammesso: non è un negativo', function () {
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno terra');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [['id' => null, 'immobile' => ['id' => $unita], 'valore' => '0']],
        ])
        ->assertSessionHasNoErrors();

    expect((float) DB::table('quote_tabella')->where('tabella_id', $tabella->id)->value('valore'))->toBe(0.0);
});

test('un valore più preciso dei decimali dichiarati arriva intero a database', function () {
    // ⚠️ Coda ⑪, chiusa nella beta.61: `numero_decimali` governa **come il valore si mostra**, mai
    // cosa si conserva. Fino alla .60 la pagina arrotondava al caricamento e all'uscita dal campo,
    // quindi aprire una tabella importata a quattro decimali e salvarla ne accorciava i valori —
    // cioè alterava un documento approvato dall'assemblea. La colonna è `decimal(12,5)` e nessun
    // calcolo ha bisogno dell'arrotondamento: il motore normalizza `valore / somma dei valori`.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);   // dichiara due decimali
    $unita = unitaConQuote($condominio, 'Interno 1');

    $this->actingAs(utenteQuote())
        ->put(route('admin.gestionale.tabelle.quote.update', [$condominio->id, $tabella->id]), [
            'quote' => [['id' => null, 'immobile' => ['id' => $unita], 'valore' => '228.5002']],
        ])
        ->assertSessionHasNoErrors();

    $valore = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->value('valore');

    expect((float) $valore)->toBe(228.5002);
});

test('abbassare i decimali dichiarati non tocca i valori già inseriti', function () {
    // Il caso che rendeva la vecchia regola pericolosa: `numero_decimali` si può abbassare
    // liberamente (`min:0|max:5`) senza nessun controllo sui valori presenti. Con l'arrotondamento
    // in salvataggio, portarla a zero su una tabella piena trasformava `14.550` in `15`.
    $condominio = condominioConQuote();
    $tabella = tabellaConQuote($condominio);
    $unita = unitaConQuote($condominio, 'Interno 1');

    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $unita, 'valore' => '14.55000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $tabella->update(['numero_decimali' => 0]);

    expect((float) DB::table('quote_tabella')->where('tabella_id', $tabella->id)->value('valore'))->toBe(14.55);
});
