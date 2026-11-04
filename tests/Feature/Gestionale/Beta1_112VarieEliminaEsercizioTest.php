<?php

/**
 * L'eliminazione di un esercizio chiuso e senza scritture non porta via in silenzio i dati che
 * hanno l'esercizio come riferimento (Coda 275).
 *
 * Nessuna pagina offre più il comando «Elimina» (tolto dall'interfaccia, resta la rotta `DELETE`):
 * le prove partono dalla rotta, come una richiesta a mano. Il vincolo del database porta via in
 * cascata i saldi, i crediti residui e i legami con le gestioni dell'esercizio, azzera
 * l'esercizio dei piani rate e del già versato, e rifiuta da solo le fatture e le deleghe F24 con un
 * errore generico: la guardia rifiuta prima, con un solo messaggio che dice quanti sono.
 *
 * Le cifre sono quelle delle righe che ogni prova costruisce; le frasi sono quelle del messaggio.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Un condominio con l'esercizio 2026 aperto (la sua gestione, il suo piano dei conti, un'unità, un
 * fornitore) e l'esercizio 2025 chiuso e vuoto: quello che si prova a eliminare.
 *
 * @return array{condominio: \App\Models\Condominio, chiuso: Esercizio, aperto: Esercizio, gestione: Gestione, immobile: int, fornitore: \App\Models\Fornitore, voce: \App\Models\Gestionale\Conto}
 */
function esercizioChiusoDaEliminare(): array
{
    [$condominio, $aperto, $gestione, $fornitore, $voce, , $immobile] = setupContabile();

    $chiuso = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2025',
        'data_inizio' => '2025-01-01',
        'data_fine' => '2025-12-31',
        'stato' => 'chiuso',
    ]);

    return compact('condominio', 'chiuso', 'aperto', 'gestione', 'immobile', 'fornitore', 'voce');
}

/** La richiesta `DELETE` come la manderebbe un client: la risposta e il messaggio che l'amministratore legge. */
function eliminaEsercizio(object $prova, array $ctx, ?Esercizio $esercizio = null): array
{
    $risposta = $prova->actingAs($prova->user)
        ->delete(route('admin.gestionale.esercizi.destroy', [$ctx['condominio'], $esercizio ?? $ctx['chiuso']]));

    return [$risposta, (string) session('message.message')];
}

/** Un saldo iniziale di un condòmino sull'esercizio, come lo scrive la sezione «Saldi». */
function saldoDelCondomino(array $ctx, Esercizio $esercizio, int $centesimi, ?Anagrafica $anagrafica = null): Saldo
{
    return Saldo::create([
        'esercizio_id' => $esercizio->id,
        'condominio_id' => $ctx['condominio']->id,
        'anagrafica_id' => ($anagrafica ?? Anagrafica::factory()->create())->id,
        'immobile_id' => $ctx['immobile'],
        'gestione_id' => $ctx['gestione']->id,
        'saldo_iniziale' => $centesimi,
        'origine' => 'manuale',
    ]);
}

// ─── I saldi iniziali ────────────────────────────────────────────────────────

test('Coda 275 — un esercizio chiuso con il saldo iniziale di un condòmino non si elimina, e il saldo resta', function () {
    $ctx = esercizioChiusoDaEliminare();
    $saldo = saldoDelCondomino($ctx, $ctx['chiuso'], 35552);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('Esercizio 2025')
        ->and($messaggio)->toContain('1 saldo iniziale di un condòmino')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(Saldo::find($saldo->id)?->saldo_iniziale)->toBe(35552);
});

test('Coda 275 — il messaggio conta i saldi dei condòmini e i debiti verso i fornitori, e dice dove si vedono', function () {
    $ctx = esercizioChiusoDaEliminare();
    saldoDelCondomino($ctx, $ctx['chiuso'], 10000);
    saldoDelCondomino($ctx, $ctx['chiuso'], -2500);

    // Un debito verso un fornitore: nessuna anagrafica e nessuna unità, nella sezione «Saldi» non compare.
    Saldo::create([
        'esercizio_id' => $ctx['chiuso']->id,
        'condominio_id' => $ctx['condominio']->id,
        'fornitore_id' => $ctx['fornitore']->id,
        'gestione_id' => $ctx['gestione']->id,
        'saldo_iniziale' => 48000,
        'origine' => 'manuale',
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('2 saldi iniziali dei condòmini')
        ->and($messaggio)->toContain('1 debito iniziale verso un fornitore')
        ->and($messaggio)->toContain('situazione debitoria del fornitore')
        ->and(DB::table('saldi')->where('esercizio_id', $ctx['chiuso']->id)->count())->toBe(3);
});

test('Coda 275 — il debito verso un fornitore da solo basta a rifiutare, senza nominare saldi di condòmini che non ci sono', function () {
    $ctx = esercizioChiusoDaEliminare();

    Saldo::create([
        'esercizio_id' => $ctx['chiuso']->id,
        'condominio_id' => $ctx['condominio']->id,
        'fornitore_id' => $ctx['fornitore']->id,
        'gestione_id' => $ctx['gestione']->id,
        'saldo_iniziale' => 48000,
        'origine' => 'manuale',
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 debito iniziale verso un fornitore')
        ->and($messaggio)->not->toContain('dei condòmini')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(DB::table('saldi')->where('esercizio_id', $ctx['chiuso']->id)->value('saldo_iniziale'))->toBe(48000);
});

test('Coda 275 — il rifiuto non manda a cercare nella sezione «Saldi Iniziali» i saldi di un esercizio chiuso, che lì non compaiono', function () {
    $ctx = esercizioChiusoDaEliminare();
    $saldo = saldoDelCondomino($ctx, $ctx['chiuso'], 35552);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);
    $risposta->assertSessionHas('message.type', 'error');

    // Il fatto su cui poggia la frase: la sezione mostra i saldi del solo esercizio aperto.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', $ctx['condominio']));
    $pagina->assertOk();
    $saldiVisti = collect($pagina->viewData('page')['props']['immobili'])->flatMap(fn ($i) => collect($i['saldi'])->pluck('id'))->all();

    expect($pagina->viewData('page')['props']['esercizio']['id'])->toBe($ctx['aperto']->id)
        ->and($saldiVisti)->not->toContain($saldo->id)
        ->and($messaggio)->not->toContain('(sezione «Saldi»)')
        ->and($messaggio)->toContain("1 saldo iniziale di un condòmino (la sezione «Saldi Iniziali» mostra solo quelli dell'esercizio aperto)");
});

// ─── I piani rate, i crediti residui, le gestioni ───────────────────────────

test('Coda 275 — un esercizio con un piano rate non si elimina, e il piano tiene il suo esercizio', function () {
    $ctx = esercizioChiusoDaEliminare();

    $piano = PianoRate::factory()->create([
        'condominio_id' => $ctx['condominio']->id,
        'gestione_id' => $ctx['gestione']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'nome' => 'Preventivo 2025',
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 piano rate («Preventivo 2025»)')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and($piano->fresh()->esercizio_id)->toBe($ctx['chiuso']->id);
});

test('Coda 275 — il messaggio nomina tutti i piani rate dell esercizio', function () {
    $ctx = esercizioChiusoDaEliminare();

    foreach (['Integrativo 2025', 'Preventivo 2025'] as $nome) {
        PianoRate::factory()->create([
            'condominio_id' => $ctx['condominio']->id,
            'gestione_id' => $ctx['gestione']->id,
            'esercizio_id' => $ctx['chiuso']->id,
            'nome' => $nome,
        ]);
    }

    [, $messaggio] = eliminaEsercizio($this, $ctx);

    expect($messaggio)->toContain('2 piani rate («Integrativo 2025», «Preventivo 2025»)');
});

test('Coda 275 — un esercizio con un credito residuo non si elimina, e il credito resta', function () {
    $ctx = esercizioChiusoDaEliminare();

    // Il piano che ha generato il credito è di un altro esercizio: a trattenere l'eliminazione è il solo credito.
    $piano = PianoRate::factory()->create([
        'condominio_id' => $ctx['condominio']->id,
        'gestione_id' => $ctx['gestione']->id,
        'esercizio_id' => $ctx['aperto']->id,
        'nome' => 'Preventivo 2026',
    ]);

    DB::table('crediti_residui')->insert([
        'anagrafica_id' => Anagrafica::factory()->create()->id,
        'condominio_id' => $ctx['condominio']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'gestione_id' => $ctx['gestione']->id,
        'piano_rate_id' => $piano->id,
        'importo' => 1250,
        'data_generazione' => '2025-12-31',
        'stato' => 'da_compensare',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 credito residuo')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(DB::table('crediti_residui')->where('esercizio_id', $ctx['chiuso']->id)->value('importo'))->toBe(1250);
});

test('Coda 275 — una gestione agganciata solo a questo esercizio lo trattiene, con il suo nome nel messaggio', function () {
    $ctx = esercizioChiusoDaEliminare();

    $solaSua = Gestione::factory()->create(['condominio_id' => $ctx['condominio']->id, 'nome' => 'Straordinaria 2025']);
    legaAEsercizio($ctx['chiuso'], $solaSua->id);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 gestione che resterebbe senza esercizio («Straordinaria 2025»)')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(DB::table('esercizio_gestione')->where('esercizio_id', $ctx['chiuso']->id)->where('gestione_id', $solaSua->id)->count())->toBe(1)
        ->and(Gestione::find($solaSua->id))->not->toBeNull();
});

test('Coda 275 — una gestione agganciata anche a un altro esercizio non lo trattiene: si elimina e la gestione resta dove si apre', function () {
    $ctx = esercizioChiusoDaEliminare();

    // Come un esercizio importato: la stessa gestione sta su ogni esercizio.
    legaAEsercizio($ctx['chiuso'], $ctx['gestione']->id);

    [$risposta] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'success');
    expect(Esercizio::find($ctx['chiuso']->id))->toBeNull()
        ->and(Gestione::find($ctx['gestione']->id))->not->toBeNull()
        ->and(DB::table('esercizio_gestione')->where('gestione_id', $ctx['gestione']->id)->pluck('esercizio_id')->all())->toBe([$ctx['aperto']->id]);
});

// ─── Il già versato ─────────────────────────────────────────────────────────

/** Un importo già versato su una voce di spesa, con l'esercizio indicato (o per tutti, se nullo). */
function giaVersatoDellaVoce(array $ctx, ?int $esercizioId, int $centesimi): int
{
    return DB::table('contributi_versati')->insertGetId([
        'condominio_id' => $ctx['condominio']->id,
        'target_type' => \App\Models\Gestionale\Conto::class,
        'target_id' => $ctx['voce']->id,
        'immobile_id' => $ctx['immobile'],
        'esercizio_id' => $esercizioId,
        'importo_cents' => $centesimi,
        'natura' => 'avanzo',
        'origine' => 'migrazione',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('Coda 275 — un esercizio con un importo già versato suo non si elimina: la chiave azzererebbe l esercizio e il già versato passerebbe a valere in tutti i piani', function () {
    $ctx = esercizioChiusoDaEliminare();
    $riga = giaVersatoDellaVoce($ctx, $ctx['chiuso']->id, 30000);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 importo già versato per questo esercizio')
        ->and($messaggio)->toContain('pagina «Già versato»')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(DB::table('contributi_versati')->where('id', $riga)->value('esercizio_id'))->toBe($ctx['chiuso']->id);
});

test('Coda 275 — il già versato di tutti gli esercizi o di un altro esercizio non trattiene quello chiuso e vuoto', function () {
    $ctx = esercizioChiusoDaEliminare();
    $perTutti = giaVersatoDellaVoce($ctx, null, 30000);
    $dellAltro = giaVersatoDellaVoce($ctx, $ctx['aperto']->id, 12000);

    [$risposta] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'success');
    expect(Esercizio::find($ctx['chiuso']->id))->toBeNull()
        ->and(DB::table('contributi_versati')->where('id', $perTutti)->value('esercizio_id'))->toBeNull()
        ->and(DB::table('contributi_versati')->where('id', $dellAltro)->value('esercizio_id'))->toBe($ctx['aperto']->id);
});

// ─── Le fatture e le deleghe F24 ────────────────────────────────────────────

test('Coda 275 — un esercizio con una fattura passiva non si elimina, e il messaggio dice perché', function () {
    $ctx = esercizioChiusoDaEliminare();

    $fattura = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $ctx['condominio']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'fornitore_id' => $ctx['fornitore']->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => '25/001',
        'data_documento' => '2025-03-10',
        'data_scadenza' => '2025-04-10',
        'importo_imponibile' => 100000,
        'importo_iva' => 22000,
        'totale_documento' => 122000,
        'netto_a_pagare' => 122000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    // Prima della guardia a rifiutare era il vincolo del database, con la frase generica «si è verificato un errore».
    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 fattura passiva')
        // La frase di testa non dice che l'eliminazione cancellerebbe o staccherebbe le fatture: il database le rifiuta.
        ->and($messaggio)->toContain('l\'eliminazione cancellerebbe dati che non sono scritture contabili, li staccherebbe dall\'esercizio o il database la rifiuterebbe')
        ->and($messaggio)->not->toContain('lascerebbe senza esercizio')
        ->and($messaggio)->not->toContain('Si è verificato un errore')
        ->and(DB::table('fatture_passive')->where('id', $fattura)->count())->toBe(1);
});

test('Coda 275 — un esercizio con una delega F24 non si elimina, e il messaggio dice perché', function () {
    $ctx = esercizioChiusoDaEliminare();

    DB::table('deleghe_f24')->insert([
        'uuid' => '6f1c1c4e-6a52-4f64-9d57-1a43c4c7a001',
        'condominio_id' => $ctx['condominio']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'stato' => 'bozza',
        'plafond' => 'ritenute',
        'data_scadenza' => '2025-12-16',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 delega F24')
        ->and($messaggio)->toContain('l\'eliminazione cancellerebbe dati che non sono scritture contabili, li staccherebbe dall\'esercizio o il database la rifiuterebbe')
        ->and($messaggio)->not->toContain('Si è verificato un errore')
        ->and(DB::table('deleghe_f24')->where('esercizio_id', $ctx['chiuso']->id)->count())->toBe(1);
});

// ─── Le scritture eliminate con il vecchio metodo ───────────────────────────

test('Coda 275 — una scrittura eliminata con il vecchio metodo (deleted_at) trattiene l esercizio, perché la cascata la porterebbe via lo stesso', function () {
    $ctx = esercizioChiusoDaEliminare();

    $scrittura = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $ctx['condominio']->id,
        'gestione_id' => $ctx['gestione']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'data_registrazione' => '2025-06-30',
        'data_competenza' => '2025-06-30',
        'numero_protocollo' => 'PN-2025-0001',
        'causale' => 'Prova',
        'tipo_movimento' => 'pagamento_fattura',
        'stato' => 'registrata',
        'deleted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('1 scrittura contabile (compresa quella eliminata in passato, che l\'elenco non mostra più ma il database conserva)')
        ->and($messaggio)->not->toContain('comprese quelle')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull()
        ->and(DB::table('scritture_contabili')->where('id', $scrittura)->count())->toBe(1);
});

/** Una scrittura registrata nell'esercizio chiuso, viva o eliminata con il vecchio metodo (`deleted_at`). */
function scritturaDelChiuso(array $ctx, string $protocollo, bool $eliminata): int
{
    return DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $ctx['condominio']->id,
        'gestione_id' => $ctx['gestione']->id,
        'esercizio_id' => $ctx['chiuso']->id,
        'data_registrazione' => '2025-06-30',
        'data_competenza' => '2025-06-30',
        'numero_protocollo' => $protocollo,
        'causale' => 'Prova',
        'tipo_movimento' => 'pagamento_fattura',
        'stato' => 'registrata',
        'deleted_at' => $eliminata ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('Coda 275 — una scrittura viva trattiene l esercizio, e senza scritture eliminate il messaggio non nomina le eliminate in passato', function () {
    $ctx = esercizioChiusoDaEliminare();
    scritturaDelChiuso($ctx, 'PN-2025-0001', false);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toContain('l\'esercizio contiene 1 scrittura contabile.')
        ->and($messaggio)->not->toContain('eliminat')
        ->and($messaggio)->not->toContain('compres')
        ->and(Esercizio::find($ctx['chiuso']->id))->not->toBeNull();
});

test('Coda 275 — due scritture vive trattengono l esercizio, al plurale e senza la parentesi delle eliminate', function () {
    $ctx = esercizioChiusoDaEliminare();
    scritturaDelChiuso($ctx, 'PN-2025-0001', false);
    scritturaDelChiuso($ctx, 'PN-2025-0002', false);

    [, $messaggio] = eliminaEsercizio($this, $ctx);

    expect($messaggio)->toContain('l\'esercizio contiene 2 scritture contabili.')
        ->and($messaggio)->not->toContain('eliminat');
});

test('Coda 275 — una scrittura viva e una eliminata in passato: il messaggio le conta tutte e due, al plurale', function () {
    $ctx = esercizioChiusoDaEliminare();
    scritturaDelChiuso($ctx, 'PN-2025-0001', false);
    scritturaDelChiuso($ctx, 'PN-2025-0002', true);

    [, $messaggio] = eliminaEsercizio($this, $ctx);

    expect($messaggio)->toContain('2 scritture contabili (comprese quelle eliminate in passato, che l\'elenco non mostra più ma il database conserva)')
        ->and($messaggio)->not->toContain('compresa quella');
});

// ─── Quello che non trattiene ───────────────────────────────────────────────

test('Coda 275 — i saldi e i piani rate di un altro esercizio non trattengono quello chiuso e vuoto', function () {
    $ctx = esercizioChiusoDaEliminare();

    saldoDelCondomino($ctx, $ctx['aperto'], 35552);
    PianoRate::factory()->create([
        'condominio_id' => $ctx['condominio']->id,
        'gestione_id' => $ctx['gestione']->id,
        'esercizio_id' => $ctx['aperto']->id,
        'nome' => 'Preventivo 2026',
    ]);

    [$risposta] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'success');
    expect(Esercizio::find($ctx['chiuso']->id))->toBeNull()
        ->and(DB::table('saldi')->where('esercizio_id', $ctx['aperto']->id)->value('saldo_iniziale'))->toBe(35552)
        ->and(PianoRate::where('esercizio_id', $ctx['aperto']->id)->count())->toBe(1);
});

test('Coda 275 — un esercizio chiuso senza niente dentro si elimina ancora', function () {
    $ctx = esercizioChiusoDaEliminare();

    [$risposta] = eliminaEsercizio($this, $ctx);

    $risposta->assertSessionHas('message.type', 'success');
    expect(Esercizio::find($ctx['chiuso']->id))->toBeNull();
});

test('Coda 275 — l esercizio aperto resta protetto con il suo messaggio, anche se ha dei saldi', function () {
    $ctx = esercizioChiusoDaEliminare();
    saldoDelCondomino($ctx, $ctx['aperto'], 35552);

    [$risposta, $messaggio] = eliminaEsercizio($this, $ctx, $ctx['aperto']);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toBe(__('gestionale.error_delete_opened_esercizio'))
        ->and(Esercizio::find($ctx['aperto']->id))->not->toBeNull();
});

test('Coda 275 — l esercizio di un altro condominio non si raggiunge dalla rotta: 404, e l esercizio resta', function () {
    $ctx = esercizioChiusoDaEliminare();

    $altro = \App\Models\Condominio::factory()->create();
    $esercizioAltrui = Esercizio::factory()->create(['condominio_id' => $altro->id, 'stato' => 'chiuso']);
    Esercizio::factory()->create(['condominio_id' => $altro->id, 'stato' => 'aperto']);

    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.esercizi.destroy', [$ctx['condominio'], $esercizioAltrui]))
        ->assertNotFound();

    expect(Esercizio::find($esercizioAltrui->id))->not->toBeNull();
});
