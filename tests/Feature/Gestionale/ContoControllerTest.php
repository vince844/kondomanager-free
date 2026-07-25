<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo($permessoAdmin);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);
});

test('Bug 2: modifica di un sottoconto salva correttamente il campo codice', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    
    // Capitolo padre
    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'codice'         => null,
        'nome'           => 'Ascensore',
        'tipo'           => 'spesa',
    ]);

    // Sottoconto esistente
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => $capitolo->id,
        'codice'         => null,
        'nome'           => 'Manutenzione Ordinaria',
        'tipo'           => 'spesa',
    ]);

    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $sottoconto]), [
            'parent_id' => $capitolo->id,
            'codice'    => 'ASC-001',
            'nome'      => 'Manutenzione Ordinaria ASC',
            'tipo'      => 'spesa',
            'isCapitolo' => false,
            'isSottoConto' => true,
            'importo'   => '1000',
            'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 50,
            'percentuale_inquilino' => 50,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    // Verify DB state
    $sottoconto->refresh();
    expect($sottoconto->nome)->toBe('Manutenzione Ordinaria ASC');
    expect($sottoconto->codice)->toBe('ASC-001'); // Questo prima falliva perché 'codice' non era nel fillable!
});

/**
 * Regressione: un "capitolo padre" è un conto di PRIMO LIVELLO (parent_id NULL),
 * non un conto con importo 0. Prima la lista dei padri filtrava solo su importo == 0,
 * quindi un sotto-conto lasciato a zero veniva scambiato per un capitolo.
 */
test('Un sotto-conto lasciato a 0 non viene proposto come capitolo padre', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    // Capitolo di primo livello, vuoto: padre legittimo
    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => null,
        'is_capitolo'    => true,
        'importo'        => 0,
        'nome'           => 'SCALA 36',
        'tipo'           => 'spesa',
    ]);

    // Sotto-conto lasciato a 0: NON deve mai comparire tra i possibili padri
    $sottocontoAZero = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => $capitolo->id,
        'importo'        => 0,
        'nome'           => 'MANUTENZIONI ORDINARIE',
        'tipo'           => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.fetch-capitoli-conti', [
            'condominio'     => $condominio->id,
            'piano_conto_id' => $pianoConti->id,
        ]));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->toContain($capitolo->id);
    expect($ids)->not->toContain($sottocontoAZero->id); // prima del fix veniva incluso
});

/**
 * Regressione sul fix "is_capitolo": rimpiazzando il vecchio filtro
 * whereNull('parent_id') + importo=0 con il solo is_capitolo=true, la
 * lista dei capitoli padre aveva perso la garanzia strutturale che la
 * beta.16 (commit 073a6885) aveva introdotto — un conto con parent_id già
 * valorizzato (un vero sotto-conto) può avere is_capitolo=true (es. dal
 * passo 3 del backfill della migrazione, "chi ha sottoconti è capitolo",
 * che non guarda il PROPRIO parent_id del record) e ricomparire come
 * candidato "capitolo padre" — lo stesso pattern segnalato da un
 * amministratore e mostrato negli screenshot (un sotto-conto annidato
 * proposto come padre per una voce nuova). Le due condizioni vanno
 * sommate, non sostituite l'una con l'altra.
 */
test('Un sotto-conto con is_capitolo=true (es. da backfill legacy) non viene comunque proposto come capitolo padre', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => '36 SCALA 36', 'tipo' => 'spesa',
    ]);

    // Sotto-conto REALE (parent_id valorizzato) che however ha is_capitolo=true —
    // stato incoerente ma possibile (es. backfill legacy step 3, o dato importato).
    $sottocontoConIsCapitoloVero = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id, 'is_capitolo' => true,
        'importo' => 0, 'nome' => '36.MO MANUTENZIONI ORDINARIE', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.fetch-capitoli-conti', [
            'condominio'     => $condominio->id,
            'piano_conto_id' => $pianoConti->id,
        ]));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->toContain($capitolo->id);
    expect($ids)->not->toContain($sottocontoConIsCapitoloVero->id);
});

/**
 * Difesa in profondità, lato richiesta: is_capitolo=true e isSottoConto=true
 * (parent_id valorizzato) insieme non hanno senso nel modello a due livelli
 * di questa applicazione — le regole "primo livello" già in withValidator()
 * assumono che un capitolo non abbia mai un padre proprio. Il frontend
 * (ModalNuovoConto.vue/ModalModificaConto.vue) già impedisce la combinazione
 * via toggle mutuamente esclusivi, ma nulla lo impediva a livello di
 * richiesta HTTP diretta — è proprio così che il backfill della migrazione
 * (passo 3) può produrre dati storici incoerenti da un giorno all'altro.
 */
test('Store: isCapitolo e isSottoConto insieme vengono rifiutati', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$condominio, $esercizio, $pianoConti]), [
            'nome'         => 'Voce incoerente',
            'tipo'         => 'spesa',
            'isCapitolo'   => true,
            'isSottoConto' => true,
            'parent_id'    => $capitolo->id,
        ]);

    $response->assertSessionHasErrors('isCapitolo');
    expect(Conto::where('nome', 'Voce incoerente')->exists())->toBeFalse();
});

test('Update: isCapitolo e isSottoConto insieme vengono rifiutati', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $voce = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => false,
        'importo' => 0, 'nome' => 'Voce da rendere incoerente', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voce]), [
            'nome'         => 'Voce da rendere incoerente',
            'tipo'         => 'spesa',
            'isCapitolo'   => true,
            'isSottoConto' => true,
            'parent_id'    => $capitolo->id,
        ]);

    $response->assertSessionHasErrors('isCapitolo');
    expect($voce->fresh()->is_capitolo)->toBeFalse();
    expect($voce->fresh()->parent_id)->toBeNull();
});

test('Il conto in modifica è escluso dai capitoli padre (no figlio di sé stesso)', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);

    $capitoloA = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $capitoloB = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => true,
        'importo' => 0, 'nome' => 'SCALA 40', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('admin.gestionale.fetch-capitoli-conti', [
            'condominio'     => $condominio->id,
            'piano_conto_id' => $pianoConti->id,
            'conto_id'       => $capitoloA->id, // sto modificando A
        ]));

    $response->assertOk();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->not->toContain($capitoloA->id); // sé stesso mai proponibile
    expect($ids)->toContain($capitoloB->id);      // gli altri capitoli restano
});

test('Update: un sotto-conto non può essere scelto come capitolo padre', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $sottocontoAZero = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 0, 'nome' => 'MANUTENZIONI ORDINARIE', 'tipo' => 'spesa',
    ]);
    $altroSottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 10000, 'nome' => 'FORZA MOTRICE', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $altroSottoconto]), [
            'parent_id'    => $sottocontoAZero->id, // padre = un altro SOTTO-CONTO
            'nome'         => 'FORZA MOTRICE',
            'tipo'         => 'spesa',
            'isCapitolo'   => false,
            'isSottoConto' => true,
            'importo'      => '100',
            'tabella_millesimale_id'    => $tabellaId,
            'percentuale_proprietario'  => 100,
            'percentuale_inquilino'     => 0,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasErrors('parent_id');

    $altroSottoconto->refresh();
    expect($altroSottoconto->parent_id)->toBe($capitolo->id); // padre invariato
});

test('Update: un conto non può essere il padre di sé stesso', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $capitolo = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null,
        'importo' => 0, 'nome' => 'SCALA 36', 'tipo' => 'spesa',
    ]);
    $sottoconto = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => $capitolo->id,
        'importo' => 10000, 'nome' => 'FORZA MOTRICE', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $sottoconto]), [
            'parent_id'    => $sottoconto->id, // sé stesso
            'nome'         => 'FORZA MOTRICE',
            'tipo'         => 'spesa',
            'isCapitolo'   => false,
            'isSottoConto' => true,
            'importo'      => '100',
            'tabella_millesimale_id'    => $tabellaId,
            'percentuale_proprietario'  => 100,
            'percentuale_inquilino'     => 0,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasErrors('parent_id');

    $sottoconto->refresh();
    expect($sottoconto->parent_id)->toBe($capitolo->id); // padre invariato
});

/**
 * Riproduzione del bug "conto a zero perde la tabella millesimale": una voce
 * di spesa di PRIMO LIVELLO con importo lasciato a 0€ (es. capitolo non
 * ancora budgettizzato quest'anno) ha comunque una tabella millesimale e
 * delle ripartizioni reali già collegate. ModalModificaConto.vue la scambia
 * per un capitolo (euristica: parent_id nullo + importo=0) e al salvataggio
 * — anche senza cambiare nulla — il backend cancellava silenziosamente sia
 * conto_tabella_millesimale sia conto_tabella_ripartizioni.
 *
 * Introdotto in modo non intenzionale dal commit e25eefa2 ("garantir
 * integridade na conversão capitolo/spesa"), che ha aggiunto il ramo
 * distruttivo per ripulire le tabelle orfane quando un capitolo VERO viene
 * convertito, senza validare che isCapitolo fosse determinato correttamente
 * in partenza.
 *
 * Tre livelli di verifica:
 * 1. Difesa in profondità lato backend: anche se qualcuno (un frontend
 *    bacato, una chiamata diretta) dichiara isCapitolo=true per una voce con
 *    dati reali, senza conferma esplicita la richiesta viene RIFIUTATA — mai
 *    un'eliminazione silenziosa. È la rete di sicurezza che non dipende dal
 *    frontend per essere corretta.
 * 2. Con conferma esplicita, la conversione deliberata funziona ancora (non
 *    abbiamo bloccato un caso d'uso legittimo, solo reso obbligatoria la
 *    consapevolezza).
 * 3. Il flusso reale — la modale corretta rilegge is_capitolo persistito
 *    invece di indovinarlo, quindi un resave senza modifiche invia
 *    isCapitolo=false (lo stato vero) e i dati sopravvivono senza errori.
 */
test('BUG: senza conferma esplicita, la conversione in capitolo di una voce con tabella reale viene rifiutata', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    // Voce di spesa di primo livello, non ancora budgettizzata (importo=0),
    // ma con tabella millesimale e ripartizioni REALI già configurate — dopo
    // il backfill della migrazione, is_capitolo è correttamente false perché
    // ha già una tabella.
    $voce = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => null,
        'is_capitolo'    => false,
        'importo'        => 0,
        'nome'           => 'Manutenzione Giardino',
        'tipo'           => 'spesa',
    ]);

    $contoTabellaId = \Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $voce->id,
        'tabella_id' => $tabellaId,
        'coefficiente' => 100.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $contoTabellaId,
        'soggetto' => 'proprietario',
        'percentuale' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Simula esattamente quello che il frontend BACATO invia oggi (isCapitolo=true
    // dedotto da importo=0+parent_id nullo), senza alcuna conferma esplicita.
    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voce]), [
            'nome'         => 'Manutenzione Giardino',
            'tipo'         => 'spesa',
            'isCapitolo'   => true,
            'isSottoConto' => false,
            'importo'      => '0',
            'tabella_millesimale_id'    => null,
            'percentuale_proprietario'  => null,
            'percentuale_inquilino'     => null,
            'percentuale_usufruttuario' => null,
        ]);

    $response->assertSessionHasErrors('isCapitolo');

    expect(\Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->count())
        ->toBe(1, 'La tabella millesimale non deve mai sparire, nemmeno se la richiesta viene rifiutata a metà.');
    expect(\Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $contoTabellaId)->count())
        ->toBe(1, 'Le ripartizioni non devono mai sparire, nemmeno se la richiesta viene rifiutata a metà.');
});

test('Con conferma esplicita, la conversione deliberata in capitolo funziona ed elimina la tabella (caso d\'uso legittimo)', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $voce = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => null,
        'is_capitolo'    => false,
        'importo'        => 0,
        'nome'           => 'Voce da riconvertire',
        'tipo'           => 'spesa',
    ]);

    $contoTabellaId = \Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $voce->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $contoTabellaId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voce]), [
            'nome'         => 'Voce da riconvertire',
            'tipo'         => 'spesa',
            'isCapitolo'   => true,
            'isSottoConto' => false,
            'importo'      => '0',
            'confermaConversioneCapitolo' => true,
        ]);

    $response->assertSessionHasNoErrors();

    expect(\Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->count())->toBe(0);
    expect(\Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $contoTabellaId)->count())->toBe(0);
    expect($voce->fresh()->is_capitolo)->toBeTrue();
});

test('Flusso reale: resave senza modifiche invia isCapitolo=false (stato vero) e i dati sopravvivono senza errori', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $voce = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id,
        'parent_id'      => null,
        'is_capitolo'    => false,
        'importo'        => 0,
        'nome'           => 'Manutenzione Giardino',
        'tipo'           => 'spesa',
    ]);

    $contoTabellaId = \Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $voce->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100.00,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $contoTabellaId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // La modale CORRETTA rilegge is_capitolo persistito (false) invece di
    // indovinarlo da importo — un resave senza modifiche invia lo stato vero,
    // con la stessa tabella/percentuali già presenti.
    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voce]), [
            'nome'         => 'Manutenzione Giardino',
            'tipo'         => 'spesa',
            'isCapitolo'   => false,
            'isSottoConto' => false,
            'importo'      => '0',
            'tabella_millesimale_id'    => $tabellaId,
            'percentuale_proprietario'  => 100,
            'percentuale_inquilino'     => 0,
            'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasNoErrors();

    expect(\Illuminate\Support\Facades\DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->count())->toBe(1);
    $ripartizione = \Illuminate\Support\Facades\DB::table('conto_tabella_ripartizioni')
        ->where('conto_tabella_millesimale_id', $contoTabellaId)
        ->where('soggetto', 'proprietario')
        ->first();
    expect($ripartizione)->not->toBeNull()
        ->and((float) $ripartizione->percentuale)->toBe(100.0);
});

/**
 * beta.27: "richiede_gia_versato" segue lo stesso principio di is_capitolo —
 * fatto esplicito scelto in creazione, mai indovinato, che filtra l'elenco
 * "Già versato". Questi test coprono lo stesso schema store/update/capitolo
 * già validato per is_capitolo.
 */
test('Store: richiedeGiaVersato=true viene persistito su una voce di spesa', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$condominio, $esercizio, $pianoConti]), [
            'nome' => 'Ristrutturazione 2025', 'tipo' => 'spesa',
            'isCapitolo' => false, 'isSottoConto' => false,
            'richiedeGiaVersato' => true,
            'importo' => '1100', 'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasNoErrors();
    $conto = Conto::where('nome', 'Ristrutturazione 2025')->firstOrFail();
    expect($conto->richiede_gia_versato)->toBeTrue();
});

test('Store: un capitolo non può avere richiedeGiaVersato=true, a prescindere dal payload', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);

    $response = $this->actingAs($this->user)
        ->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$condominio, $esercizio, $pianoConti]), [
            'nome' => 'Capitolo con flag incoerente', 'tipo' => 'spesa',
            'isCapitolo' => true, 'isSottoConto' => false,
            'richiedeGiaVersato' => true, // un capitolo non versa nulla direttamente (beta.22)
        ]);

    $response->assertSessionHasNoErrors();
    $conto = Conto::where('nome', 'Capitolo con flag incoerente')->firstOrFail();
    expect($conto->richiede_gia_versato)->toBeFalse();
});

test('Update: richiedeGiaVersato può essere attivato in un secondo momento', function () {
    $condominio = Condominio::factory()->create();
    $pianoConti = PianoConto::factory()->create(['condominio_id' => $condominio->id]);
    $esercizio  = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $tabellaId  = \Illuminate\Support\Facades\DB::table('tabelle')->insertGetId(['condominio_id' => $condominio->id, 'nome' => 'A']);

    $voce = Conto::factory()->create([
        'piano_conto_id' => $pianoConti->id, 'parent_id' => null, 'is_capitolo' => false,
        'richiede_gia_versato' => false, 'importo' => 110_000, 'nome' => 'Lavori tetto', 'tipo' => 'spesa',
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$condominio, $esercizio, $pianoConti, $voce]), [
            'nome' => 'Lavori tetto', 'tipo' => 'spesa',
            'isCapitolo' => false, 'isSottoConto' => false,
            'richiedeGiaVersato' => true,
            'importo' => '1100', 'tabella_millesimale_id' => $tabellaId,
            'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]);

    $response->assertSessionHasNoErrors();
    expect($voce->fresh()->richiede_gia_versato)->toBeTrue();
});
