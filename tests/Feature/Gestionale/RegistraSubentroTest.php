<?php

/**
 * B2, S5 — «Registra passaggio»: la scrittura (`RegistraSubentroAction`, `PassaggioController::store`).
 *
 * Invarianti del progetto `docs/subentro_e_competenza_temporale.md` provati qui: 19 (la coppia in saldi
 * somma zero, stesso subentro_id, non si tocca da sola), 21 (nessuna riga di rate_quote cambia), 25 (la
 * riga chiusa non si cancella), il cancello (1) sul server, «anteprima = scrittura» (i numeri del pannello
 * sono quelli in saldi), le decisioni A e B del 19/09, la rinuncia motivata, le pertinenze, l'usufrutto.
 * Le fixture sono quelle di `PassaggioTitolaritaTest` (stesso file di helper, stesso `beforeEach`).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestione;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);

    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $this->gestione = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria']);
    legaAEsercizio($this->esercizio, $this->gestione->id);
    $this->immobile = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 3', 'descrizione' => 'Appartamento', 'interno' => '3']);

    $this->rossi = rsPersona($this->condominio, 'Rossi Mario');
    $this->bianchi = rsPersona($this->condominio, 'Bianchi Anna');
    $this->rotta = route('admin.gestionale.immobili.passaggi.store', [$this->condominio, $this->immobile]);
});

function rsPersona(Condominio $c, string $nome): Anagrafica
{
    static $seq = 0;
    $seq++;
    $a = Anagrafica::forceCreate(['nome' => $nome, 'email' => "rs{$seq}@test.it", 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'RSTEST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]);
    $a->condomini()->syncWithoutDetaching([$c->id]);

    return $a;
}

function rsRiga(Immobile $i, Anagrafica $a, string $tipologia, string $dal, ?string $al = null, float $quota = 100.0): int
{
    return DB::table('anagrafica_immobile')->insertGetId([
        'immobile_id' => $i->id, 'anagrafica_id' => $a->id, 'tipologia' => $tipologia, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Un piano con una rata emessa e una quota a `$intestatario` sull'unità; quota pura = importo (senza saldi). */
function rsQuotaEmessa(Gestione $g, Condominio $c, Immobile $i, Anagrafica $intestatario, int $importo, string $scadenza = '2026-06-30', ?string $delibera = null, string $tipoPiano = 'ordinario'): int
{
    $pianoId = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'nome' => 'Piano ' . $g->nome . ' ' . substr($scadenza, 0, 4) . ($delibera ? ' del ' . $delibera : ''), 'numero_rate' => 1, 'giorno_scadenza' => 30,
        'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => $tipoPiano, 'contesto_creazione' => 'preventivo_iniziale',
        'data_delibera_assemblea' => $delibera, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rataId = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'numero_rata' => 1, 'data_scadenza' => $scadenza, 'data_emissione' => '2026-06-01', 'importo_totale' => $importo, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert([
        'rata_id' => $rataId, 'anagrafica_id' => $intestatario->id, 'immobile_id' => $i->id, 'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria',
        'data_scadenza' => $scadenza, 'regole_calcolo' => json_encode(['origine' => 'calcolo_automatico', 'importi' => ['quota_pura_gestione' => $importo, 'saldo_usato' => 0, 'totale_calcolato' => $importo]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $pianoId;
}

function rsVendita(int $rigaUscente, Anagrafica $entrante, array $extra = []): array
{
    return array_merge([
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaUscente, 'anagrafica_entrante_id' => $entrante->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => false, 'nota_cancello' => '',
    ], $extra);
}

/*
|--------------------------------------------------------------------------
| Il flusso: form → anteprima → registrazione → storico
|--------------------------------------------------------------------------
*/

it('anteprima = scrittura — una vendita con una rata emessa scrive la coppia in saldi con esattamente i numeri del pannello, chiude chi esce, apre chi entra, e lo storico li mostra (inv. 19, 21)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $quotePrima = DB::table('rate_quote')->orderBy('id')->get()->map(fn ($q) => (array) $q)->all();

    // 1. L'anteprima: il pannello propone 27655 (41200 × 245/365) e il cancello scatta (rata emessa).
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['coppie'][0]['importo'])->toBe(27655)->and($anteprima['cancello']['richiesto'])->toBeTrue();

    // 2. Senza la presa d'atto il server rifiuta (il cancello (1) non è solo nel browser).
    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($riga, $this->bianchi))->assertUnprocessable()->assertJsonValidationErrors('nota_cancello');
    expect(Subentro::count())->toBe(0)->and(Saldo::count())->toBe(0);

    // 3. Con spunta e nota: registrato.
    $risposta = $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']));
    $risposta->assertRedirect(route('admin.gestionale.immobili.anagrafiche.index', [$this->condominio, $this->immobile]));
    expect($risposta->getSession()->get('message')['type'])->toBe('success')
        ->and($risposta->getSession()->get('passaggio_registrato')['coppie'])->toBe(1)
        ->and($risposta->getSession()->get('passaggio_registrato')['conguaglio'])->toBe('€ 276,55');
    // Le due azioni della card verde aprono pagine, non JSON (verifica S5, R14).
    foreach ($risposta->getSession()->get('passaggio_registrato')['azioni'] as $url) {
        $this->actingAs($this->user)->get($url)->assertOk()->assertInertia();
    }

    // La pivot: Rossi chiuso al 30/04, Bianchi dal 01/05, `attivo` intatto (D10).
    $rossi = DB::table('anagrafica_immobile')->where('id', $riga)->first();
    $bianchi = DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->first();
    expect($rossi->data_fine)->toBe('2026-04-30')->and((int) $rossi->attivo)->toBe(1)
        ->and($bianchi->data_inizio)->toBe('2026-05-01')->and($bianchi->data_fine)->toBeNull()->and((float) $bianchi->quota)->toBe(100.0);

    // `subentri`: una riga, agganciata alle due righe della pivot, con la nota del cancello congelata.
    $subentro = Subentro::sole();
    expect($subentro)->toMatchArray(['tipo_passaggio' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => $riga, 'riga_entrante_id' => $bianchi->id, 'anagrafica_uscente_id' => $this->rossi->id, 'anagrafica_entrante_id' => $this->bianchi->id, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'utente_id' => $this->user->id])
        ->and($subentro->decorrenza->toDateString())->toBe('2026-05-01')->and($subentro->copia_autentica_il->toDateString())->toBe('2026-05-06');

    // Inv. 19: la coppia somma zero, stesso subentro_id, gestione e unità del piano, esercizio del piano, origine automatico.
    // E «anteprima = scrittura» letteralmente: il numero in saldi È quello che il pannello aveva proposto.
    $saldi = Saldo::orderBy('saldo_iniziale')->get();
    $proposto = (int) $anteprima['rate']['conguaglio']['coppie'][0]['importo'];
    expect($saldi)->toHaveCount(2)
        ->and($saldi->sum('saldo_iniziale'))->toBe(0)
        ->and((int) $saldi[1]->saldo_iniziale)->toBe($proposto)->and((int) $saldi[0]->saldo_iniziale)->toBe(-$proposto)
        ->and($saldi[0])->toMatchArray(['anagrafica_id' => $this->rossi->id, 'saldo_iniziale' => -27655, 'gestione_id' => $this->gestione->id, 'immobile_id' => $this->immobile->id, 'esercizio_id' => $this->esercizio->id, 'origine' => 'automatico', 'subentro_id' => $subentro->id])
        ->and($saldi[1])->toMatchArray(['anagrafica_id' => $this->bianchi->id, 'saldo_iniziale' => 27655, 'subentro_id' => $subentro->id])
        ->and((bool) $saldi[0]->is_applicato)->toBeFalse()
        ->and($saldi[0]->descrizione)->toBe('Conguaglio passaggio del 1 maggio 2026 (Rossi Mario → Bianchi Anna)');

    // Inv. 21: nessuna riga di rate_quote è cambiata.
    expect(DB::table('rate_quote')->orderBy('id')->get()->map(fn ($q) => (array) $q)->all())->toBe($quotePrima);

    // Lo storico: un passaggio, Rossi chiuso con il titolo, Bianchi in corso.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$this->condominio, $this->immobile]));
    $storico = $pagina->viewData('page')['props']['storico'];
    expect($storico['passaggi'])->toBe(1)
        ->and(collect($storico['righe'])->firstWhere('id', $riga)['subentro']['ruolo_nel_passaggio'])->toBe('uscente')
        ->and(collect($storico['righe'])->firstWhere('id', $bianchi->id)['subentro']['ruolo_nel_passaggio'])->toBe('entrante');

    // Inv. 25: la riga chiusa non si dissocia, e la vecchia riga di Rossi non si «modifica» cambiando persona.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $riga]))->assertUnprocessable();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $bianchi->id]))->assertUnprocessable();
    expect(DB::table('anagrafica_immobile')->count())->toBe(2);
});

it('inv. 19 — nessuna delle due righe della coppia si modifica o si cancella da sola dal Wallet, e il passaggio non si cancella finché ha la sua coppia', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();
    $saldo = Saldo::where('anagrafica_id', $this->bianchi->id)->firstOrFail();

    $this->actingAs($this->user)->patchJson(route('admin.gestionale.saldi.update', [$this->condominio, $saldo]), ['saldo_iniziale' => 100, 'gestione_id' => $this->gestione->id])->assertUnprocessable();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.saldi.destroy', [$this->condominio, $saldo]))->assertStatus(422);
    expect(Saldo::count())->toBe(2)->and(Saldo::sum('saldo_iniziale'))->toBe(0)->and((int) $saldo->fresh()->saldo_iniziale)->toBe(27655);

    expect(fn () => Subentro::sole()->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('la rinuncia motivata al conguaglio non scrive la coppia e congela la ragione nel passaggio; senza la ragione il server rifiuta', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $base = rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello', 'rinuncia_conguaglio' => true]);

    $this->actingAs($this->user)->postJson($this->rotta, $base + ['nota_conguaglio' => 'corta'])->assertUnprocessable()->assertJsonValidationErrors('nota_conguaglio');
    $this->actingAs($this->user)->post($this->rotta, $base + ['nota_conguaglio' => 'Regolato nel rogito del 30/04/2026, notaio Verdi'])->assertRedirect();

    expect(Saldo::count())->toBe(0)
        ->and(Subentro::sole()->nota_conguaglio)->toBe('Regolato nel rogito del 30/04/2026, notaio Verdi')
        ->and(Subentro::sole()->conguaglioRinunciato())->toBeTrue();
});

it('senza rate emesse il cancello non scatta, si registra senza nota, e in saldi non c\'è niente (niente coppia a zero)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi))->assertRedirect();

    expect(Subentro::count())->toBe(1)->and(Saldo::count())->toBe(0)->and(Subentro::sole()->nota_cancello)->toBeNull();
});

it('la stessa riga non si chiude due volte: il secondo invio identico risponde 422 e non scrive niente in più', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $corpo = rsVendita($riga, $this->bianchi);
    $this->actingAs($this->user)->post($this->rotta, $corpo)->assertRedirect();
    $this->actingAs($this->user)->postJson($this->rotta, $corpo)->assertUnprocessable()->assertJsonValidationErrors('riga_uscente_id');

    expect(Subentro::count())->toBe(1)->and(DB::table('anagrafica_immobile')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Decisione A: la vendita fra comproprietari; le quote per giorno
|--------------------------------------------------------------------------
*/

it('decisione A — il comproprietario che compra la quota dell\'altro: la sua riga al 50 % si chiude e se ne apre una alla quota somma (50 + 30 = 80, il terzo resta al 20), il passaggio aggancia la riga nuova', function () {
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03', null, 30);
    $rigaBianchi = rsRiga($this->immobile, $this->bianchi, 'proprietario', '2019-03-03', null, 50);
    rsRiga($this->immobile, rsPersona($this->condominio, 'Verdi Luca'), 'proprietario', '2019-03-03', null, 20);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['quota' => 30]))->assertRedirect();

    $righe = DB::table('anagrafica_immobile')->orderBy('id')->get();
    expect($righe)->toHaveCount(4)
        ->and($righe->firstWhere('id', $rigaRossi)->data_fine)->toBe('2026-04-30')
        ->and($righe->firstWhere('id', $rigaBianchi)->data_fine)->toBe('2026-04-30')
        ->and((float) $righe->last()->quota)->toBe(80.0)->and($righe->last()->data_inizio)->toBe('2026-05-01')->and((int) $righe->last()->anagrafica_id)->toBe($this->bianchi->id)
        ->and(Subentro::sole()->riga_entrante_id)->toBe($righe->last()->id);
});

it('la quota che sfora in un giorno ferma la registrazione con il giorno nel messaggio (inv. 11 dal passaggio), e niente viene scritto', function () {
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03', null, 60);
    rsRiga($this->immobile, rsPersona($this->condominio, 'Verdi Luca'), 'proprietario', '2019-03-03', null, 40);

    // Bianchi compra il 60 % di Rossi ma il modulo dice 100: il 1° maggio la somma farebbe 140.
    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['quota' => 100]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.quota.0', 'Interno 3 — la somma delle quote per proprietario non può superare 100: il 1 maggio 2026 farebbe 140.');

    expect(Subentro::count())->toBe(0)->and(DB::table('anagrafica_immobile')->where('id', $rigaRossi)->value('data_fine'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Pertinenze, usufrutto, locazione
|--------------------------------------------------------------------------
*/

it('le pertinenze spuntate seguono il passaggio: una riga subentri ciascuna legata alla principale, con la quota che chi esce aveva lì; una pertinenza di cui chi esce non è titolare ferma tutto', function () {
    $box = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $this->immobile->id]);
    $cantina = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Cantina 7', 'descrizione' => 'Cantina', 'interno' => 'C7', 'pertinenza_di_immobile_id' => $this->immobile->id]);
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $rigaBox = rsRiga($box, $this->rossi, 'proprietario', '2019-03-03', null, 50);
    rsRiga($cantina, rsPersona($this->condominio, 'Neri Paolo'), 'proprietario', '2019-03-03'); // la cantina è di un altro

    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($riga, $this->bianchi, ['pertinenze' => [$box->id, $cantina->id]]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.pertinenze.0', 'Cantina 7: Rossi Mario non risulta proprietario alla data del passaggio. Togli la spunta, o registra il passaggio dalla pertinenza.');
    expect(Subentro::count())->toBe(0);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['pertinenze' => [$box->id]]))->assertRedirect();
    $principale = Subentro::whereNull('subentro_padre_id')->sole();
    $figlio = Subentro::whereNotNull('subentro_padre_id')->sole();
    expect($figlio->subentro_padre_id)->toBe($principale->id)->and($figlio->immobile_id)->toBe($box->id)->and($figlio->riga_uscente_id)->toBe($rigaBox)
        ->and(DB::table('anagrafica_immobile')->where('id', $rigaBox)->value('data_fine'))->toBe('2026-04-30')
        ->and((float) DB::table('anagrafica_immobile')->where('immobile_id', $box->id)->where('anagrafica_id', $this->bianchi->id)->value('quota'))->toBe(50.0);
});

it('usufrutto — la costituzione chiude il proprietario, lo riapre come nudo proprietario alla stessa quota e apre l\'usufruttuario; la riga di continuazione non si dissocia (haStoria)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $this->bianchi->id,
        'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [],
    ])->assertRedirect();

    $righe = DB::table('anagrafica_immobile')->orderBy('id')->get();
    expect($righe)->toHaveCount(3)
        ->and($righe[0]->data_fine)->toBe('2026-04-30')
        ->and($righe[1])->toMatchArray(['anagrafica_id' => $this->rossi->id, 'tipologia' => 'nuda_proprietario', 'data_inizio' => '2026-05-01'])
        ->and($righe[2])->toMatchArray(['anagrafica_id' => $this->bianchi->id, 'tipologia' => 'usufruttuario', 'data_inizio' => '2026-05-01'])
        ->and(Subentro::sole()->riga_entrante_id)->toBe($righe[2]->id);

    // La riga «nuda_proprietario» di Rossi non è agganciata a subentri, ma continua la sua riga chiusa il giorno prima: ha storia.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.anagrafiche.destroy', [$this->condominio, $this->immobile, $righe[1]->id]))->assertUnprocessable();
});

it('usufrutto — l\'estinzione chiude l\'usufruttuario e riapre TUTTI i nudi proprietari come proprietari pieni, ciascuno alla sua quota', function () {
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $rigaUsu = rsRiga($this->immobile, $this->bianchi, 'usufruttuario', '2020-01-01');
    rsRiga($this->immobile, $this->rossi, 'nuda_proprietario', '2020-01-01', null, 60);
    rsRiga($this->immobile, $neri, 'nuda_proprietario', '2020-01-01', null, 40);

    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [],
    ])->assertRedirect();

    $inCorso = DB::table('anagrafica_immobile')->whereNull('data_fine')->get();
    expect(DB::table('anagrafica_immobile')->whereNotNull('data_fine')->count())->toBe(3)
        ->and($inCorso->pluck('tipologia')->unique()->all())->toBe(['proprietario'])
        ->and((float) $inCorso->firstWhere('anagrafica_id', $this->rossi->id)->quota)->toBe(60.0)
        ->and((float) $inCorso->firstWhere('anagrafica_id', $neri->id)->quota)->toBe(40.0);
});

it('inizio locazione — la scadenza del contratto NON chiude la riga (resta nel passaggio), e il promemoria opt-in finisce in agenda alla data giusta, agganciato al passaggio', function () {
    rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $verdi = rsPersona($this->condominio, 'Verdi Luca');

    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-05-31', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'promemoria_scadenza' => true, 'promemoria_giorni' => 90,
    ])->assertRedirect();

    $inquilino = DB::table('anagrafica_immobile')->where('anagrafica_id', $verdi->id)->first();
    expect($inquilino->data_inizio)->toBe('2026-06-01')->and($inquilino->data_fine)->toBeNull();
    $subentro = Subentro::sole();
    expect($subentro->data_fine_locazione->toDateString())->toBe('2030-05-31')->and($subentro->regime_contratto)->toBe('abitativo')->and($subentro->anagrafica_uscente_id)->toBeNull();

    $evento = Evento::where('tipo', 'scadenza')->sole();
    expect($evento->start_time->toDateString())->toBe('2030-03-02') // 31/05/2030 − 90 giorni
        ->and($evento->title)->toBe('Scade la locazione di Verdi Luca — Interno 3')
        ->and($evento->eventable_type)->toBe(Subentro::class)->and((int) $evento->eventable_id)->toBe($subentro->id)
        ->and((bool) $evento->is_completed)->toBeFalse();
});

it('senza la spunta del promemoria nessun evento nasce (opt-in, decisione del Checkpoint 1)', function () {
    rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-05-31', 'pertinenze' => [],
    ])->assertRedirect();

    expect(Evento::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Decisione B: il PDF del titolo
|--------------------------------------------------------------------------
*/

it('decisione B — il PDF del titolo diventa un documento dell\'unità, non pubblicato e senza aggancio al condominio, legato al passaggio; il file sparisce dal disco se la registrazione fallisce', function () {
    Storage::fake('local');
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');

    // Registrazione che fallisce (cancello senza nota, con rata emessa): niente documento, niente file.
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['allegato_titolo' => UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf'), 'promemoria_scadenza' => '0', 'copia_autentica' => '1']))->assertSessionHasErrors('nota_cancello');
    expect(Documento::count())->toBe(0)->and(count(Storage::disk('local')->allFiles('documenti')))->toBe(0);

    // Registrazione che passa (multipart, con il file).
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, [
        'allegato_titolo' => UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf'),
        'ho_letto' => '1', 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello', 'copia_autentica' => '1', 'promemoria_scadenza' => '0',
    ]))->assertRedirect();

    $documento = Documento::sole();
    expect($documento->name)->toBe('Titolo di provenienza — passaggio del 1 maggio 2026 (Rossi Mario → Bianchi Anna)')
        ->and($documento->documentable_type)->toBe(Immobile::class)->and((int) $documento->documentable_id)->toBe($this->immobile->id)
        ->and((bool) $documento->is_published)->toBeFalse()
        ->and($documento->condomini()->count())->toBe(0)->and($documento->anagrafiche()->count())->toBe(0)
        ->and(Subentro::sole()->documento_id)->toBe($documento->id);
    Storage::disk('local')->assertExists($documento->path);

    // Cancellare il documento non cancella il passaggio: il riferimento si azzera.
    $documento->delete();
    expect(Subentro::sole()->fresh()->documento_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Verifica indipendente S5 (19/09/2026): i rilievi sopravvissuti alla confutazione
|--------------------------------------------------------------------------
*/

it('R1 — un passaggio retroattivo (decorrenza prima della generazione del piano) che ha conguagliato le quote emesse blocca l\'annullamento dell\'emissione: la rata resta emessa e la coppia intatta', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $pianoId = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    // La rata è stata generata il 15/01/2026; il rogito è del 20/12/2025, registrato oggi: tutto all'entrante.
    DB::table('rate')->update(['data_emissione' => '2026-01-15']);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['decorrenza' => '2025-12-20', 'copia_autentica_il' => '2025-12-28', 'ho_letto' => true, 'nota_cancello' => 'Rogito di dicembre registrato in ritardo']))->assertRedirect();
    expect(Saldo::where('anagrafica_id', $this->bianchi->id)->value('saldo_iniziale'))->toBe(41200);

    $rataId = DB::table('rate')->value('id');
    $risposta = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $this->condominio->id, 'pianoRate' => $pianoId, 'rata' => $rataId]));
    expect($risposta->getSession()->get('message')['type'])->toBe('error')
        ->and($risposta->getSession()->get('message')['message'])->toContain('conguagliato')
        ->and(DB::table('rate')->where('id', $rataId)->value('stato'))->toBe('emessa')
        ->and(Saldo::count())->toBe(2);
});

it('R2 — due comproprietari che vendono insieme allo stesso acquirente, due passaggi con la stessa decorrenza: la seconda quota si somma sulla riga nata lo stesso giorno, senza righe rovesciate', function () {
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03', null, 50);
    $rigaVerdi = rsRiga($this->immobile, $verdi, 'proprietario', '2019-03-03', null, 50);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['quota' => 50]))->assertRedirect();
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaVerdi, $this->bianchi, ['quota' => 50]))->assertRedirect();

    $bianchi = DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->get();
    expect($bianchi)->toHaveCount(1)
        ->and((float) $bianchi[0]->quota)->toBe(100.0)->and($bianchi[0]->data_inizio)->toBe('2026-05-01')->and($bianchi[0]->data_fine)->toBeNull()
        ->and(Subentro::count())->toBe(2)
        ->and(Subentro::pluck('riga_entrante_id')->unique()->all())->toBe([$bianchi[0]->id])
        ->and(DB::table('anagrafica_immobile')->whereColumn('data_fine', '<', 'data_inizio')->count())->toBe(0);

    // E oltre 100 la guardia per giorno ferma anche la somma sulla riga dello stesso giorno.
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $rigaNeri = rsRiga($this->immobile, $neri, 'nuda_proprietario', '2019-03-03', null, 100);
    DB::table('anagrafica_immobile')->where('id', $rigaNeri)->update(['tipologia' => 'proprietario', 'quota' => 10, 'data_inizio' => '2026-05-01']);
    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($rigaNeri, $this->bianchi, ['quota' => 10, 'decorrenza' => '2026-05-01']))
        ->assertUnprocessable();
});

it('R3 — l\'anagrafica di chi compare in un passaggio non si elimina (la FK di saldi è in cascata: sparirebbe una gamba sola della coppia)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();
    // Rossi non ha più condomini (li stacchiamo, come farebbe «Modifica anagrafica» svuotando l'elenco).
    $this->rossi->condomini()->detach();

    $risposta = $this->actingAs($this->user)->delete(route('admin.anagrafiche.destroy', ['anagrafica' => $this->rossi->id]));
    expect($risposta->getSession()->get('message')['type'])->toBe('error')
        ->and(Anagrafica::find($this->rossi->id))->not->toBeNull()
        ->and(Saldo::count())->toBe(2)->and(Saldo::sum('saldo_iniziale'))->toBe(0);
});

it('R5 — se la scrittura del documento fallisce dopo che il file è su disco, il file viene tolto; e due nomi lunghissimi non fanno fallire niente (il nome si tronca a 255)', function () {
    Storage::fake('local');
    $lungo1 = rsPersona($this->condominio, str_repeat('A', 120));
    $lungo2 = rsPersona($this->condominio, str_repeat('B', 120));
    $riga = rsRiga($this->immobile, $lungo1, 'proprietario', '2019-03-03');

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $lungo2, [
        'allegato_titolo' => UploadedFile::fake()->create('atto.pdf', 120, 'application/pdf'), 'copia_autentica' => '1', 'promemoria_scadenza' => '0',
    ]))->assertRedirect();
    $documento = Documento::sole();
    expect(mb_strlen($documento->name))->toBeLessThanOrEqual(255)->and($documento->name)->toEndWith('…)');

    // Il ramo «file scritto, poi rollback» è garantito per costruzione: `$path` è assegnato nella transazione
    // prima di `create()`, e il catch lo cancella. Qui si verifica l'altra promessa: nessun file orfano nemmeno
    // quando la registrazione fallisce prima del passo del documento (pertinenza di cui chi esce non è titolare).
    $rigaB = rsRiga($this->immobile, $this->rossi, 'nuda_proprietario', '2019-03-03');
    $cantina = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Cantina 7', 'descrizione' => 'Cantina', 'interno' => 'C7', 'pertinenza_di_immobile_id' => $this->immobile->id]);
    $prima = count(Storage::disk('local')->allFiles('documenti'));
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaB, $this->bianchi, [
        'tipologia' => 'nuda_proprietario', 'pertinenze' => [$cantina->id],
        'allegato_titolo' => UploadedFile::fake()->create('atto2.pdf', 120, 'application/pdf'), 'copia_autentica' => '1', 'promemoria_scadenza' => '0',
    ]))->assertSessionHasErrors('pertinenze');
    expect(count(Storage::disk('local')->allFiles('documenti')))->toBe($prima)->and(Documento::count())->toBe(1);
});

it('R6 — estinzione dell\'usufrutto: un nudo proprietario chiuso il giorno prima da un\'altra vendita NON torna proprietario; il nudo nato lo stesso giorno diventa proprietario sulla sua riga', function () {
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $rigaUsu = rsRiga($this->immobile, $this->bianchi, 'usufruttuario', '2020-01-01');
    // Rossi era nudo proprietario e ha venduto la nuda a Neri con decorrenza 01/05 (registrato a mano: chiuso 30/04, Neri dal 01/05).
    rsRiga($this->immobile, $this->rossi, 'nuda_proprietario', '2020-01-01', '2026-04-30');
    $rigaNeri = rsRiga($this->immobile, $neri, 'nuda_proprietario', '2026-05-01');

    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [],
    ])->assertRedirect();

    $righe = DB::table('anagrafica_immobile')->get();
    expect($righe->where('anagrafica_id', $this->rossi->id)->count())->toBe(1) // Rossi resta solo con la sua riga chiusa
        ->and($righe->where('anagrafica_id', $this->rossi->id)->first()->data_fine)->toBe('2026-04-30')
        ->and($righe->firstWhere('id', $rigaNeri)->tipologia)->toBe('proprietario') // la riga di Neri si riqualifica, non si rovescia
        ->and($righe->firstWhere('id', $rigaNeri)->data_inizio)->toBe('2026-05-01')->and($righe->firstWhere('id', $rigaNeri)->data_fine)->toBeNull()
        ->and($righe->firstWhere('id', $rigaUsu)->data_fine)->toBe('2026-04-30')
        ->and(Subentro::sole()->riga_entrante_id)->toBe($rigaNeri);
});

it('R8/R9/R10 — gestione riusata su due esercizi, due piani con quote emesse a chi esce: una sola coppia sull\'esercizio 2026 con i giorni del piano 2026; il piano creato fuori dal suo esercizio usa l\'esercizio della generazione, non la data di creazione', function () {
    $e2025 = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2025, $this->gestione->id);
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $piano2025 = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500, '2025-06-30');
    $piano2026 = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200, '2026-06-30');
    // Il piano 2026 è stato creato a febbraio 2027 (consuntivo) ma generato sotto l'esercizio 2026: lo ricorda.
    DB::table('piani_rate')->where('id', $piano2025)->update(['esercizio_id' => $e2025->id, 'created_at' => '2025-01-10']);
    DB::table('piani_rate')->where('id', $piano2026)->update(['esercizio_id' => $this->esercizio->id, 'created_at' => '2027-02-10']);

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    $c = $json['rate']['conguaglio'];

    // Due gruppi (uno per esercizio): il 2025 a zero (decorrenza dopo la fine), il 2026 con 120/245 → 27655.
    expect($c['per_gestione'])->toHaveCount(2)
        ->and(collect($c['per_gestione'])->firstWhere('esercizio_id', $e2025->id)['importo'])->toBe(0)
        ->and(collect($c['per_gestione'])->firstWhere('esercizio_id', $this->esercizio->id))->toMatchArray(['importo' => 27655, 'giorni_uscente' => 120, 'giorni_entrante' => 245])
        ->and($c['coppie'])->toHaveCount(1)
        ->and($c['coppie'][0])->toMatchArray(['esercizio_id' => $this->esercizio->id, 'importo' => 27655])
        ->and($c['esercizi_dedotti'])->toBe([]);
    $frasi = implode(' ', $json['rate']['frasi']);
    expect($frasi)->toContain('120 a Rossi Mario, 245 a Bianchi Anna')->not->toContain('365 a Rossi Mario, 0 a Bianchi Anna');

    // Senza `esercizio_id` (piano di una versione precedente) l'esercizio si deduce e il pannello lo dice.
    DB::table('piani_rate')->where('id', $piano2026)->update(['esercizio_id' => null, 'created_at' => '2026-01-10']);
    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    expect($json['rate']['conguaglio']['esercizi_dedotti'])->toBe(['Piano Ordinaria 2026 2026'])
        ->and(implode(' ', $json['rate']['frasi']))->toContain('non ricorda in quale esercizio è stato generato');

});

it('R10 — straordinario con due delibere sulla stessa gestione: la frase cita la delibera del piano che produce l\'importo, non la prima', function () {
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria']);
    legaAEsercizio($this->esercizio, $straordinaria->id);
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($straordinaria, $this->condominio, $this->immobile, $this->rossi, 120000, '2026-04-30', '2026-02-12', 'straordinario');
    rsQuotaEmessa($straordinaria, $this->condominio, $this->immobile, $this->rossi, 50000, '2026-07-31', '2026-06-01', 'straordinario');

    $json = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    $frasi = implode(' ', $json['rate']['frasi']);
    expect($json['rate']['conguaglio']['coppie'][0]['importo'])->toBe(50000)
        ->and($frasi)->toContain('€ 1.200,00 restano interamente a Rossi Mario, perché l\'assemblea ha deliberato il 12 febbraio 2026')
        ->toContain('credito € 500,00 a Rossi Mario, debito € 500,00 a Bianchi Anna — la delibera del 1 giugno 2026');
});

/*
|--------------------------------------------------------------------------
| S6, voce 7 — il vademecum «chi resta obbligato» dopo la registrazione, e la copia autentica che arriva dopo
|--------------------------------------------------------------------------
*/

it('S6 — lo storico elenca il passaggio registrato con il vademecum ricalcolato dai fatti, e la copia autentica registrata dopo spegne il «finché non la ricevi»', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['copia_autentica' => false, 'copia_autentica_il' => null]))->assertRedirect();
    $subentro = Subentro::sole();

    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh());
    expect($storico['subentri'])->toHaveCount(1);
    $p = $storico['subentri'][0];
    expect($p['id'])->toBe($subentro->id)
        ->and($p['tipo_passaggio'])->toBe('vendita')->and($p['uscente'])->toBe('Rossi Mario')->and($p['entrante'])->toBe('Bianchi Anna')
        ->and($p['decorrenza'])->toBe('2026-05-01')->and($p['copia_autentica_attesa'])->toBeTrue()
        ->and($p['conguaglio']['stato'])->toBe('nessuno');
    $frasi = implode("\n", $p['obbligati']);
    expect($frasi)->toContain('Bianchi Anna risponde in solido con Rossi Mario')
        ->toContain('Finché il condominio non riceve copia autentica del titolo, l\'obbligo verso il condominio resta a Rossi Mario')
        // Il vademecum descrive uno stato: niente imperativi né futuro dell'anteprima.
        ->not->toContain('Registra pure')->not->toContain('verranno');

    // Chi la registra su un'altra unità non trova il passaggio; una data futura non passa.
    $altra = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 9', 'descrizione' => 'Altro', 'interno' => '9']);
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $altra, $subentro]), ['copia_autentica_il' => '2026-05-20'])->assertNotFound();
    $this->actingAs($this->user)->patchJson(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $this->immobile, $subentro]), ['copia_autentica_il' => now()->addDays(3)->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors('copia_autentica_il');

    // La copia arriva: la frase cambia da sola, e la data è scritta sul passaggio.
    $r = $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $this->immobile, $subentro]), ['copia_autentica_il' => '2026-05-20']);
    $r->assertRedirect();
    expect($r->getSession()->get('message')['type'])->toBe('success')
        ->and($subentro->fresh()->copia_autentica_il->toDateString())->toBe('2026-05-20');

    $p = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh())['subentri'][0];
    expect($p['copia_autentica_attesa'])->toBeFalse()->and($p['copia_autentica_il'])->toBe('2026-05-20');
    $frasi = implode("\n", $p['obbligati']);
    expect($frasi)->toContain('Copia autentica del titolo ricevuta il 20 maggio 2026: da quel giorno Rossi Mario è liberato')
        ->not->toContain('Finché il condominio non riceve');
});

it('S6 — la copia autentica riguarda una vendita: su una locazione il server la rifiuta e lo dice', function () {
    $proprietario = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'inizio_locazione', 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $this->bianchi->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [], 'ho_letto' => false, 'nota_cancello' => '',
    ])->assertRedirect();
    $subentro = Subentro::sole();
    expect($proprietario)->toBeInt();

    $r = $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $this->immobile, $subentro]), ['copia_autentica_il' => '2026-05-20']);
    $r->assertRedirect();
    expect($r->getSession()->get('message')['type'])->toBe('error')
        ->and($subentro->fresh()->copia_autentica_il)->toBeNull();

    // Il vademecum della locazione, al presente.
    $p = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh())['subentri'][0];
    expect($p['copia_autentica_attesa'])->toBeFalse()
        ->and(implode("\n", $p['obbligati']))->toContain('All\'inquilino sono addebitate solo le voci')->not->toContain('verranno');
});

/*
|--------------------------------------------------------------------------
| S6, voce 8 — annullare il conguaglio: le due righe insieme, con una nota, solo finché sono libere
|--------------------------------------------------------------------------
*/

it('S6 — il conguaglio si annulla dallo storico con una nota: le righe di saldi (unità e pertinenze) spariscono insieme, il passaggio ricorda quando e perché', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();
    $subentro = Subentro::sole();
    expect(Saldo::where('subentro_id', $subentro->id)->count())->toBe(2);
    $rotta = route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$this->condominio, $this->immobile, $subentro]);

    // Senza nota, o troppo corta: rifiutato, le righe restano.
    $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'corta'])->assertUnprocessable()->assertJsonValidationErrors('nota_annullamento_conguaglio');
    expect(Saldo::count())->toBe(2);

    // Con la nota: via insieme, e il passaggio lo ricorda.
    $r = $this->actingAs($this->user)->delete($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto']);
    $r->assertRedirect();
    expect($r->getSession()->get('message')['type'])->toBe('success')
        ->and(Saldo::count())->toBe(0);
    $subentro->refresh();
    expect($subentro->conguaglioAnnullato())->toBeTrue()->and($subentro->nota_annullamento_conguaglio)->toBe('Regolato nel prezzo di vendita, come da atto');

    $p = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh())['subentri'][0];
    expect($p['conguaglio']['stato'])->toBe('annullato')->and($p['conguaglio']['nota_annullamento'])->toBe('Regolato nel prezzo di vendita, come da atto');

    // Una seconda volta: non c'è più niente da annullare.
    $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto'])->assertUnprocessable()->assertJsonValidationErrors('conguaglio');
});

it('S6 — assorbito da un piano, il conguaglio non si annulla più: il messaggio dice quale piano e cosa fare (non emesso → in bozza ed eliminalo; emesso a giornale → saldo manuale)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();
    $subentro = Subentro::sole();

    // Un piano in bozza ha assorbito la coppia (il lucchetto lo chiude GeneratePianoRateAction).
    $pianoId = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $this->gestione->id, 'condominio_id' => $this->condominio->id, 'nome' => 'Conguaglio 2026', 'numero_rate' => 1,
        'stato' => 'bozza', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Saldo::where('subentro_id', $subentro->id)->update(['is_applicato' => true, 'piano_rate_id' => $pianoId]);

    $rotta = route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$this->condominio, $this->immobile, $subentro]);
    $r = $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto']);
    $r->assertUnprocessable();
    expect($r->json('errors.conguaglio.0'))->toContain('già stato assorbito')->toContain('«Conguaglio 2026»')->toContain('non ha ancora emesso nulla')->toContain('elimina il piano')
        ->and(Saldo::count())->toBe(2)->and($subentro->fresh()->conguaglioAnnullato())->toBeFalse();

    // Approvato ma senza nulla a giornale: è lo stato normale fra la delibera e l'emissione, stessa strada (verifica S6, R9).
    DB::table('piani_rate')->where('id', $pianoId)->update(['stato' => 'approvato']);
    $r = $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto']);
    expect($r->json('errors.conguaglio.0'))->toContain('non ha ancora emesso nulla')->not->toContain('saldo manuale');

    // Una quota emessa a giornale (scrittura_contabile_id): il piano è immutabile, resta il saldo manuale.
    $rataId = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'numero_rata' => 1, 'data_scadenza' => '2026-07-31', 'importo_totale' => 1000, 'stato' => 'emessa', 'created_at' => now(), 'updated_at' => now()]);
    $scritturaId = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'gestione_id' => $this->gestione->id, 'data_registrazione' => now(), 'data_competenza' => now(), 'numero_protocollo' => 'TEST-EM-1', 'causale' => 'Emissione', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert(['rata_id' => $rataId, 'anagrafica_id' => $this->bianchi->id, 'immobile_id' => $this->immobile->id, 'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-07-31', 'scrittura_contabile_id' => $scritturaId, 'created_at' => now(), 'updated_at' => now()]);
    $r = $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto']);
    expect($r->json('errors.conguaglio.0'))->toContain('già emesso in contabilità')->toContain('saldo manuale di segno opposto');

    // Lo storico lo dice come «assorbito», e il Wallet rimanda allo storico.
    $p = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh())['subentri'][0];
    expect($p['conguaglio']['stato'])->toBe('proposto')->and($p['conguaglio']['applicato'])->toBeTrue();
});

it('verifica S6, R12/R13 — con una pertinenza spuntata: la copia autentica registrata dopo arriva anche alla riga del box, le quattro righe del conguaglio stanno tutte sul padre e si annullano insieme, e una sola riga assorbita ferma tutto', function () {
    $box = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $this->immobile->id]);
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsRiga($box, $this->rossi, 'proprietario', '2019-03-03');
    $pianoId = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    // Una quota emessa anche sul box, sulla stessa rata (rsQuotaEmessa non si richiama: unique piani_rate.gestione_id+nome).
    $rataId = DB::table('rate')->where('piano_rate_id', $pianoId)->value('id');
    DB::table('rate_quote')->insert([
        'rata_id' => $rataId, 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $box->id, 'importo' => 7300, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria',
        'data_scadenza' => '2026-06-30', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 7300, 'saldo_usato' => 0, 'totale_calcolato' => 7300]]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [$box->id], 'ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();
    $padre = Subentro::whereNull('subentro_padre_id')->sole();
    $figlio = Subentro::whereNotNull('subentro_padre_id')->sole();

    // Quattro righe (unità + box, due gambe ciascuna), tutte sul padre; il figlio non ha saldi.
    expect(Saldo::count())->toBe(4)->and(Saldo::where('subentro_id', $padre->id)->count())->toBe(4)->and(Saldo::where('subentro_id', $figlio->id)->count())->toBe(0)
        ->and((int) Saldo::sum('saldo_iniziale'))->toBe(0);
    $storico = app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($this->immobile->fresh())['subentri'][0];
    // 41.200 × 245/365 = 27.655 e 7.300 × 245/365 = 4.900: il conguaglio del passaggio è la somma.
    expect($storico['pertinenze'])->toBe(['Box 12'])->and($storico['conguaglio']['importo'])->toBe(27655 + 4900);

    // La copia autentica dal padre arriva anche al figlio; dal figlio non si registra (404).
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $box, $figlio]), ['copia_autentica_il' => '2026-05-20'])->assertNotFound();
    $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$this->condominio, $this->immobile, $padre]), ['copia_autentica_il' => '2026-05-20'])->assertRedirect();
    expect($padre->fresh()->copia_autentica_il->toDateString())->toBe('2026-05-20')->and($figlio->fresh()->copia_autentica_il->toDateString())->toBe('2026-05-20');

    // Una sola riga (quella del box) assorbita da un piano: l'annullamento si ferma, le quattro righe restano.
    $bozza = DB::table('piani_rate')->insertGetId(['gestione_id' => $this->gestione->id, 'condominio_id' => $this->condominio->id, 'nome' => 'Conguaglio 2026', 'numero_rate' => 1, 'stato' => 'bozza', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now()]);
    Saldo::where('immobile_id', $box->id)->where('saldo_iniziale', '>', 0)->update(['is_applicato' => true, 'piano_rate_id' => $bozza]);
    $rotta = route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$this->condominio, $this->immobile, $padre]);
    $this->actingAs($this->user)->deleteJson($rotta, ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto'])->assertUnprocessable()->assertJsonValidationErrors('conguaglio');
    expect(Saldo::count())->toBe(4);

    // Liberata, si annulla tutto insieme — anche chiamando la rotta dal figlio (che rimanda al padre).
    Saldo::where('immobile_id', $box->id)->update(['is_applicato' => false, 'piano_rate_id' => null]);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$this->condominio, $box, $figlio]), ['nota_annullamento_conguaglio' => 'Regolato nel prezzo di vendita, come da atto'])->assertRedirect()->assertSessionHasNoErrors();
    expect(Saldo::count())->toBe(0)->and($padre->fresh()->conguaglioAnnullato())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| S7 — la nota di solidarietà dell'art. 63 co. 4: una nota, non una quota
|--------------------------------------------------------------------------
*/

it('S7 — dopo una vendita l\'estratto conto di chi entra e la situazione debitoria dell\'unità portano la nota dell\'art. 63 co. 4 con il residuo di chi è uscito; nessuna quota intestata a chi entra', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect();

    // Estratto conto di chi entra: la nota c'è, nomina i due esercizi e il residuo di Rossi (€ 412,00 non pagati).
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$this->condominio, $this->bianchi]))->assertOk()->viewData('page')['props'];
    expect($props['solidarieta'])->toHaveCount(1);
    $nota = $props['solidarieta'][0];
    expect($nota['testo'])->toContain('Bianchi Anna risponde in solido con Rossi Mario')->toContain('esercizi 2026 e 2025')->toContain('art. 63 co. 4')
        ->toContain('a nome di Rossi Mario risultano oggi € 412,00 non pagati')
        ->and($nota['residuo_uscente_cents'])->toBe(41200);
    // Nessuna quota emessa a Bianchi: la nota non è una rata.
    expect(DB::table('rate_quote')->where('anagrafica_id', $this->bianchi->id)->count())->toBe(0);

    // Chi è uscito non ha la nota (è lui a rispondere, non a essere avvisato di un altro).
    expect($this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$this->condominio, $this->rossi]))->viewData('page')['props']['solidarieta'])->toBe([]);

    // La situazione debitoria dell'unità (il JSON dell'incasso) la porta come nota accanto alle rate.
    $json = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $this->condominio->id, 'immobile_id' => $this->immobile->id]))->assertOk()->json();
    expect($json['note_solidarieta'])->toHaveCount(1)->and($json['note_solidarieta'][0])->toContain('risponde in solido');
    $json = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $this->condominio->id, 'anagrafica_id' => $this->bianchi->id]))->assertOk()->json();
    expect($json['note_solidarieta'])->toHaveCount(1);

    // Il PDF dell'estratto conto la stampa.
    $pdf = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$this->condominio, $this->bianchi]));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('S7 — la nota vale solo per le vendite e solo nella finestra dell\'esercizio in corso e del precedente', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    // Una locazione: niente solidarietà.
    $this->actingAs($this->user)->post($this->rotta, [
        'tipo' => 'inizio_locazione', 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $this->bianchi->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'copia_autentica_il' => null, 'pertinenze' => [], 'ho_letto' => false, 'nota_cancello' => '',
    ])->assertRedirect();
    expect(app(\App\Services\Subentro\NotaSolidarieta::class)->per($this->condominio, $this->bianchi))->toBe([]);

    // Una vendita di tre esercizi fa: fuori dalla finestra.
    Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    Subentro::create(['condominio_id' => $this->condominio->id, 'immobile_id' => $this->immobile->id, 'anagrafica_uscente_id' => $this->rossi->id, 'anagrafica_entrante_id' => $neri->id,
        'riga_uscente_id' => $riga, 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => '2024-06-01', 'utente_id' => $this->user->id]);
    expect(app(\App\Services\Subentro\NotaSolidarieta::class)->per($this->condominio, $neri))->toBe([]);

    // La stessa vendita nell'esercizio precedente (2025): dentro la finestra.
    Subentro::where('anagrafica_entrante_id', $neri->id)->update(['decorrenza' => '2025-06-01']);
    expect(app(\App\Services\Subentro\NotaSolidarieta::class)->per($this->condominio, $neri))->toHaveCount(1);
});

it('S8-3 — catena dei passaggi nello stesso esercizio: Rossi → Bianchi (01/05) → Verdi (01/09) con una rata da 36.500 emessa a Rossi; il secondo passaggio propone e scrive Bianchi −12.200 / Verdi +12.200, il cancello scatta, i saldi finali sono 120/123/122 giorni', function () {
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500);

    // Primo passaggio: Rossi → Bianchi dal 1º maggio; coppia 36.500 × 245/365 = 24.500.
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'primo rogito']))->assertRedirect();
    $rigaBianchi = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->value('id');
    expect(Saldo::where('anagrafica_id', $this->bianchi->id)->sum('saldo_iniziale'))->toBe(24500);

    // Secondo passaggio: Bianchi → Verdi dal 1º settembre. Bianchi non ha quote emesse a suo nome: prima della
    // correzione il pannello diceva «niente da conguagliare» e il cancello non scattava.
    $corpo = rsVendita($rigaBianchi, $verdi, ['decorrenza' => '2026-09-01', 'copia_autentica_il' => '2026-09-05']);
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), $corpo)->assertOk()->json();
    $c = $anteprima['rate']['conguaglio'];
    expect($anteprima['rate']['stato'])->toBe('calcolato')
        ->and($anteprima['cancello']['richiesto'])->toBeTrue()
        ->and(implode(' ', $anteprima['cancello']['motivi']))->toContain('1 quota di rata già emessa a Rossi Mario, la cui competenza è passata a Bianchi Anna con un passaggio precedente')
        ->and($anteprima['rate']['emesse'])->toHaveCount(1)->and($anteprima['rate']['emesse'][0]['intestatario'])->toBe('Rossi Mario')
        ->and($c['coppie'])->toHaveCount(1)->and($c['coppie'][0]['importo'])->toBe(12200)
        // Verdi deve i suoi 122 giorni per competenza (36.500 × 122/365); Bianchi ne ha avuti 123 (24.500 − 12.200).
        ->and($c['quote'][0])->toMatchArray(['quota_pura' => 36500, 'giorni_uscente' => 123, 'giorni_entrante' => 122, 'giorni_periodo' => 245, 'uscente' => 12300, 'entrante' => 12200, 'ereditata_da' => 'Rossi Mario', 'decorrenza_acquisto' => '2026-05-01']);
    $frasi = implode("\n", $anteprima['rate']['frasi']);
    // Le bozze comprese (decisione 21) sono di Rossi, non di chi esce: la frase deve dirlo (visto a video il 20/09).
    expect($frasi)->not->toContain('intestate a Bianchi Anna')
        ->and($anteprima['cancello']['motivi'][0])->not->toContain(' a lui ');
    expect($frasi)->toContain('credito € 122,00 a Bianchi Anna, debito € 122,00 a Verdi Luca')
        ->toContain('è emessa a Rossi Mario: la sua competenza è passata a Bianchi Anna dal 1 maggio 2026 con un passaggio precedente')
        ->toContain('quella parte (245 giorni) è divisa in proporzione ai giorni: 123 a Bianchi Anna, 122 a Verdi Luca')->not->toContain('ha acquistato')
        ->not->toContain('niente da conguagliare');

    // Senza la presa d'atto il server rifiuta; con la nota registra la seconda coppia sul secondo passaggio.
    $this->actingAs($this->user)->postJson($this->rotta, $corpo)->assertUnprocessable()->assertJsonValidationErrors('nota_cancello');
    $this->actingAs($this->user)->post($this->rotta, array_replace($corpo, ['ho_letto' => true, 'nota_cancello' => 'secondo rogito']))->assertRedirect();

    $secondo = Subentro::where('anagrafica_entrante_id', $verdi->id)->sole();
    $coppia = Saldo::where('subentro_id', $secondo->id)->orderBy('saldo_iniziale')->get();
    expect($coppia)->toHaveCount(2)
        ->and($coppia[0])->toMatchArray(['anagrafica_id' => $this->bianchi->id, 'saldo_iniziale' => -12200])
        ->and($coppia[1])->toMatchArray(['anagrafica_id' => $verdi->id, 'saldo_iniziale' => 12200])
        ->and(Saldo::where('anagrafica_id', $this->rossi->id)->sum('saldo_iniziale'))->toBe(-24500)
        ->and(Saldo::where('anagrafica_id', $this->bianchi->id)->sum('saldo_iniziale'))->toBe(12300)
        ->and(Saldo::where('anagrafica_id', $verdi->id)->sum('saldo_iniziale'))->toBe(12200)
        ->and(Saldo::sum('saldo_iniziale'))->toBe(0);
});

it('S8-3 — se nel primo passaggio l\'amministratore ha rinunciato alla coppia, il secondo la propone comunque per competenza fra chi esce e chi entra (si propone, non si impone)', function () {
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'primo rogito', 'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'regolato fra le parti davanti al notaio']))->assertRedirect();
    expect(Saldo::count())->toBe(0);
    $rigaBianchi = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->value('id');

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($rigaBianchi, $verdi, ['decorrenza' => '2026-09-01', 'copia_autentica_il' => '2026-09-05']))->assertOk()->json();
    expect($anteprima['rate']['stato'])->toBe('calcolato')->and($anteprima['rate']['conguaglio']['coppie'][0]['importo'])->toBe(12200)->and($anteprima['cancello']['richiesto'])->toBeTrue();
});

it('S8-2 — la quota emessa a chi esce copre solo il tratto che il pro rata le ha dato: con il tratto 1º maggio–31 dicembre congelato in righe_riparto e la vendita al 1º settembre, la divisione è 123/122 su 245, non su 365', function () {
    // Rossi è proprietario dal 1º maggio: il piano gli ha emesso la quota già pro rata (67.124 su 245 giorni)
    // e ha congelato `giorni_titolarita = 245`. Vende il 1º settembre: a Bianchi vanno 122 dei 245 giorni.
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2026-05-01');
    $pianoId = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 67124);
    DB::table('righe_riparto')->insert([
        'piano_rate_id' => $pianoId, 'tipo' => 'riparto', 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $this->immobile->id,
        'conto_id' => null, 'conto_nome' => 'Spese generali', 'conto_radice_id' => null, 'conto_radice_nome' => 'Spese generali',
        'importo' => 67124, 'giorni_titolarita' => 245, 'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31', 'titolarita_dal' => '2026-05-01', 'titolarita_al' => '2026-12-31',
        'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi, ['decorrenza' => '2026-09-01', 'copia_autentica_il' => '2026-09-05']))->assertOk()->json();
    $c = $anteprima['rate']['conguaglio'];
    // Prima della correzione: 67.124 × 122/365 = 22.435 e «243 a Rossi, 122 a Bianchi» (giorni che Rossi non ha mai avuto).
    expect($c['coppie'][0]['importo'])->toBe(33425)
        ->and($c['quote'][0])->toMatchArray(['giorni_uscente' => 123, 'giorni_entrante' => 122, 'giorni_periodo' => 245, 'uscente' => 33699, 'entrante' => 33425])
        ->and(implode("\n", $anteprima['rate']['frasi']))->toContain('123 a Rossi Mario, 122 a Bianchi Anna');
});

it('S8-19 — gestione riusata su due esercizi: la coppia nata dal preventivo 2026 blocca l\'annullamento dell\'emissione solo sul piano 2026; la rata del consuntivo 2025 (nessun conguaglio da quel piano) torna in bozza', function () {
    $e2025 = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2025, $this->gestione->id);
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    // Consuntivo 2025 emesso il 10/02/2026 sotto l'esercizio 2025 (competenza tutta prima del rogito: entrante 0);
    // preventivo 2026 emesso il 15/01/2026 sotto l'esercizio 2026 (coppia 27.655).
    $piano2025 = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500, '2025-12-31');
    $piano2026 = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200, '2026-06-30');
    DB::table('piani_rate')->where('id', $piano2025)->update(['esercizio_id' => $e2025->id]);
    DB::table('piani_rate')->where('id', $piano2026)->update(['esercizio_id' => $this->esercizio->id]);
    DB::table('rate')->where('piano_rate_id', $piano2025)->update(['data_emissione' => '2026-02-10']);
    DB::table('rate')->where('piano_rate_id', $piano2026)->update(['data_emissione' => '2026-01-15']);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile']))->assertRedirect();
    expect(Saldo::count())->toBe(2)->and(Saldo::where('anagrafica_id', $this->bianchi->id)->sole())->toMatchArray(['saldo_iniziale' => 27655, 'esercizio_id' => $this->esercizio->id]);

    // Prima della correzione il filtro guardava la sola gestione e bloccava anche il 2025.
    $rata2025 = (int) DB::table('rate')->where('piano_rate_id', $piano2025)->value('id');
    $r = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $this->condominio->id, 'pianoRate' => $piano2025, 'rata' => $rata2025]));
    expect($r->getSession()->get('message')['type'])->toBe('success')->and(DB::table('rate')->where('id', $rata2025)->value('stato'))->toBe('bozza');

    $rata2026 = (int) DB::table('rate')->where('piano_rate_id', $piano2026)->value('id');
    $r2 = $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $this->condominio->id, 'pianoRate' => $piano2026, 'rata' => $rata2026]));
    expect($r2->getSession()->get('message')['type'])->toBe('error')
        ->and($r2->getSession()->get('message')['message'])->toContain('per l\'esercizio del piano')
        ->and(DB::table('rate')->where('id', $rata2026)->value('stato'))->toBe('emessa');
});

it('S8-20 — l\'inizio di una locazione non ha chi esce: una riga uscente nel corpo è rifiutata (422) da anteprima e registrazione, e il proprietario non viene chiuso', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $corpo = ['tipo' => 'inizio_locazione', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $verdi->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'letto tutto'];

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), $corpo)->assertUnprocessable()->assertJsonValidationErrors('riga_uscente_id');
    $this->actingAs($this->user)->postJson($this->rotta, $corpo)->assertUnprocessable()->assertJsonValidationErrors(['riga_uscente_id' => 'L\'inizio di una locazione non ha chi esce']);
    expect(Subentro::count())->toBe(0)->and(Saldo::count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('id', $riga)->value('data_fine'))->toBeNull()
        ->and(\App\Models\TitolaritaImmobile::find($riga)->haStoria())->toBeFalse();
});

it('S8-29 — due nomi da 130 caratteri non fanno fallire la registrazione: la descrizione della coppia resta dentro i 255 caratteri, con la testa intera e i nomi troncati con «…»', function () {
    $lungo = rsPersona($this->condominio, str_repeat('Lunghissimo ', 10) . 'Uscente');
    $lunga = rsPersona($this->condominio, str_repeat('Lunghissima ', 10) . 'Entrante');
    $riga = rsRiga($this->immobile, $lungo, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $lungo, 41200);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $lunga, ['ho_letto' => true, 'nota_cancello' => 'Rogito, letto il pannello']))->assertRedirect()->assertSessionHasNoErrors();
    $saldo = Saldo::where('anagrafica_id', $lunga->id)->sole();
    expect(mb_strlen($saldo->descrizione))->toBeLessThanOrEqual(255)
        ->and($saldo->descrizione)->toStartWith('Conguaglio passaggio del 1 maggio 2026 (Lunghissimo')->toEndWith('…)');
});

it('S8-27 — un\'unità con un passaggio registrato non si elimina: né con la coppia in saldi né dopo la rinuncia al conguaglio; storico e saldi restano intatti', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200);
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile']))->assertRedirect();

    $r = $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.destroy', [$this->condominio, $this->immobile]));
    $r->assertRedirect(route('admin.gestionale.immobili.index', $this->condominio));
    expect($r->getSession()->get('message')['type'])->toBe('error')->and($r->getSession()->get('message')['message'])->toContain('passaggi di titolarità registrati')
        ->and(Immobile::count())->toBe(1)->and(Subentro::count())->toBe(1)->and(Saldo::count())->toBe(2)->and(Saldo::sum('saldo_iniziale'))->toBe(0);

    // Con la rinuncia al conguaglio: nessuna coppia, ma lo storico c'è — l'unità resta.
    $altra = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 4', 'descrizione' => 'Appartamento', 'interno' => '4']);
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaAltra = rsRiga($altra, $this->rossi, 'proprietario', '2019-03-03');
    // Una quota emessa a Rossi anche qui, su un piano dal nome distinto (unique gestione+nome).
    DB::table('rate_quote')->insert(['rata_id' => DB::table('rate')->value('id'), 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $altra->id, 'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-06-30', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 10000, 'saldo_usato' => 0, 'totale_calcolato' => 10000]]), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$this->condominio, $altra]), rsVendita($rigaAltra, $verdi, ['ho_letto' => true, 'nota_cancello' => 'Rogito letto per intero', 'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'regolato fra le parti davanti al notaio']))->assertRedirect()->assertSessionHasNoErrors();
    expect(Subentro::count())->toBe(2);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.destroy', [$this->condominio, $altra]))->assertRedirect();
    expect(Immobile::count())->toBe(2)->and(Subentro::count())->toBe(2);
});

it('S8-30 — estinzione dell\'usufrutto con due nudi proprietari (60/40) e una rata da 36.500 emessa all\'usufruttuario: il conguaglio si divide per quota, una coppia per nudo (14.700 e 9.800), somma zero, e le frasi nominano entrambi', function () {
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $rigaUsu = rsRiga($this->immobile, $this->bianchi, 'usufruttuario', '2020-01-01');
    rsRiga($this->immobile, $this->rossi, 'nuda_proprietario', '2020-01-01', null, 60);
    rsRiga($this->immobile, $neri, 'nuda_proprietario', '2020-01-01', null, 40);
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->bianchi, 36500);
    $corpo = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letto il pannello'];

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), $corpo)->assertOk()->json();
    $coppie = collect($anteprima['rate']['conguaglio']['coppie']);
    // 36.500 × 245/365 = 24.500 → 60 % = 14.700 a Rossi, 40 % = 9.800 a Neri (prima: tutto al primo nudo).
    expect($coppie)->toHaveCount(2)
        ->and($coppie->firstWhere('anagrafica_entrante_id', $this->rossi->id)['importo'])->toBe(14700)
        ->and($coppie->firstWhere('anagrafica_entrante_id', $neri->id)['importo'])->toBe(9800)
        ->and($anteprima['riferimento']['frase'])->toContain('Rossi Mario (60 %) e Neri Paolo (40 %) tornano proprietari pieni')
        ->and(implode("\n", $anteprima['anagrafica']['frasi']))->toContain('Rossi Mario (60 %) e Neri Paolo (40 %) risulteranno proprietari pieni dal 1 maggio 2026')
        ->and(implode("\n", $anteprima['rate']['frasi']))->toContain('il debito di € 245,00 si divide per quota: € 147,00 a Rossi Mario (60 %), € 98,00 a Neri Paolo (40 %)')
        ->and(implode("\n", $anteprima['obbligati']['frasi']))->toContain('Rossi Mario (60 %) e Neri Paolo (40 %) tornano proprietari pieni e rispondono di tutte le spese');

    $this->actingAs($this->user)->post($this->rotta, $corpo)->assertRedirect()->assertSessionHasNoErrors();
    $saldi = Saldo::orderBy('saldo_iniziale')->get();
    expect($saldi)->toHaveCount(4)->and((int) Saldo::sum('saldo_iniziale'))->toBe(0)
        ->and((int) Saldo::where('anagrafica_id', $this->bianchi->id)->sum('saldo_iniziale'))->toBe(-24500)
        ->and((int) Saldo::where('anagrafica_id', $this->rossi->id)->sum('saldo_iniziale'))->toBe(14700)
        ->and((int) Saldo::where('anagrafica_id', $neri->id)->sum('saldo_iniziale'))->toBe(9800)
        ->and(Saldo::where('anagrafica_id', $neri->id)->value('descrizione'))->toContain('Bianchi Anna → Neri Paolo')
        ->and(Saldo::pluck('subentro_id')->unique())->toHaveCount(1);

    // Lo storico ricostruisce il vademecum dai fatti: anche lì entrambi.
    $vademecum = implode("\n", (new \App\Services\Subentro\FrasiObbligati())->daSubentro(Subentro::sole()));
    expect($vademecum)->toContain('Rossi Mario (60 %) e Neri Paolo (40 %) tornano proprietari pieni');
});

it('decisione 21 (S8-1) — piano da 4 rate emesso in parte a giornale: la coppia copre TUTTE le quote di chi esce, comprese le tre in bozza (27.655 su 41.200, non 6.914), il cancello lo dice e le bozze restano sue perché scadono prima del rogito (decisione 25, B3a)', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $pianoId = DB::table('piani_rate')->insertGetId(['gestione_id' => $this->gestione->id, 'condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'nome' => 'Piano 4 rate', 'numero_rate' => 4, 'giorno_scadenza' => 30, 'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'contesto_creazione' => 'preventivo_iniziale', 'created_at' => now(), 'updated_at' => now()]);
    $scritturaId = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'gestione_id' => $this->gestione->id, 'data_registrazione' => now(), 'data_competenza' => now(), 'numero_protocollo' => 'TEST-EM-21', 'causale' => 'Emissione', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([1, 2, 3, 4] as $n) {
        $emessa = $n === 1;
        $rataId = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'numero_rata' => $n, 'data_scadenza' => "2026-0{$n}-28", 'data_emissione' => $emessa ? '2026-01-10' : null, 'importo_totale' => 10300, 'stato' => $emessa ? 'emessa' : 'bozza', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rate_quote')->insert(['rata_id' => $rataId, 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $this->immobile->id, 'importo' => 10300, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => "2026-0{$n}-28", 'scrittura_contabile_id' => $emessa ? $scritturaId : null, 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 10300, 'saldo_usato' => 0, 'totale_calcolato' => 10300]]), 'created_at' => now(), 'updated_at' => now()]);
    }

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    $c = $anteprima['rate']['conguaglio'];
    // Prima: solo la rata 1 (10.300 × 245/365 = 6.914); le tre bozze sarebbero restate a Rossi senza conguaglio, perché il piano non si ricalcola più.
    expect($c['coppie'][0]['importo'])->toBe(27655)->and($c['quote'])->toHaveCount(4)
        ->and(collect($c['quote'])->where('in_bozza', true))->toHaveCount(3)
        // Le tre bozze scadono il 28 di febbraio, marzo e aprile, prima del rogito del 1° maggio: nella vendita non passano
        // a chi entra (decisione 25) e la frase dice perché.
        ->and($c['quote_in_bozza'])->toBe([['piano' => 'Piano 4 rate', 'intestatario' => 'Rossi Mario', 'n' => 3, 'motivo' => 'scade_prima']])
        ->and($c['bozze_riassegnate'])->toBe([])
        ->and($anteprima['rate']['frasi'][1])->toContain('Comprese le 3 quote del piano «Piano 4 rate» non ancora emesse che scadono prima del 1 maggio 2026: resteranno intestate a Rossi Mario e si conguagliano qui')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('il piano «Piano 4 rate» ha 3 quote non ancora emesse intestate a Rossi Mario: non si può più ricalcolare, restano sue e sono comprese nel conguaglio')
        ->not->toContain('il destinatario cambierebbe');

    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'Rogito del 30 aprile, letto il pannello']))->assertRedirect()->assertSessionHasNoErrors();
    expect((int) Saldo::where('anagrafica_id', $this->bianchi->id)->sum('saldo_iniziale'))->toBe(27655)
        ->and(DB::table('rate_quote')->where('anagrafica_id', $this->rossi->id)->count())->toBe(4);
});

it('decisione 21, controllo — un piano con rate in bozza che NON ha ancora emesso a giornale si ricalcola: le bozze non entrano nel conguaglio e il cancello dice «il destinatario cambierebbe»', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 41200); // «emessa» senza scrittura a giornale
    $pianoId = (int) DB::table('piani_rate')->value('id');
    $rataId = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'numero_rata' => 2, 'data_scadenza' => '2026-09-30', 'importo_totale' => 10000, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert(['rata_id' => $rataId, 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $this->immobile->id, 'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi))->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['coppie'][0]['importo'])->toBe(27655)->and($anteprima['rate']['conguaglio']['quote'])->toHaveCount(1)
        ->and($anteprima['rate']['conguaglio']['quote_in_bozza'])->toBe([])
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('il destinatario cambierebbe')->not->toContain('non si può più ricalcolare');
});

it('decisione 24 (S8-11) — il ruolo di una riga agganciata a un passaggio registrato non si cambia da «Modifica associazione» (422, riga intatta); quota e note sì; la Resource espone `agganciata_a_passaggio`; una riga senza passaggio cambia ruolo liberamente', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi))->assertRedirect();
    $rigaBianchi = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->value('id');
    $rotta = fn (int $id) => route('admin.gestionale.immobili.anagrafiche.update', [$this->condominio, $this->immobile, $id]);

    // Entrante: cambiare il ruolo è rifiutato con il messaggio della decisione 24.
    $r = $this->actingAs($this->user)->putJson($rotta($rigaBianchi), ['anagrafica_id' => $this->bianchi->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => null]);
    $r->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    expect($r->json('errors.tipologia.0'))->toContain('fa parte di un passaggio registrato')->toContain('annullalo dallo storico')->not->toContain('prossima versione')->not->toContain('annullane il conguaglio');
    expect(DB::table('anagrafica_immobile')->where('id', $rigaBianchi)->value('tipologia'))->toBe('proprietario');

    // Uscente (riga chiusa): idem.
    $this->actingAs($this->user)->putJson($rotta($riga), ['anagrafica_id' => $this->rossi->id, 'tipologia' => 'inquilino', 'quota' => 100, 'data_inizio' => '2019-03-03', 'data_fine' => '2026-04-30', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors('tipologia');

    // Stesso ruolo, quota e note: passa.
    $this->actingAs($this->user)->put($rotta($rigaBianchi), ['anagrafica_id' => $this->bianchi->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => 'atto rep. 12345'])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaBianchi)->value('note'))->toBe('atto rep. 12345');

    // La pagina «Modifica» sa che la riga è agganciata; una riga libera no, e cambia ruolo.
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.edit', [$this->condominio, $this->immobile, $rigaBianchi]))->assertOk();
    expect($pagina->viewData('page')['props']['agganciata_a_passaggio'])->toBeTrue();
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaLibera = rsRiga($this->immobile, $verdi, 'inquilino', '2024-01-01');
    $paginaLibera = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.edit', [$this->condominio, $this->immobile, $rigaLibera]))->assertOk();
    expect($paginaLibera->viewData('page')['props']['agganciata_a_passaggio'])->toBeFalse();
    // E nell'elenco dei titolari (Resource) la stessa informazione per riga.
    $lista = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$this->condominio, $this->immobile]))->assertOk()->viewData('page')['props'];
    $righe = collect($lista['immobile']['anagrafiche'] ?? $lista['anagrafiche'] ?? []);
    expect($righe->firstWhere('pivot.id', $rigaBianchi)['pivot']['agganciata_a_passaggio'])->toBeTrue()
        ->and($righe->firstWhere('pivot.id', $rigaLibera)['pivot']['agganciata_a_passaggio'])->toBeFalse();
    $this->actingAs($this->user)->put($rotta($rigaLibera), ['anagrafica_id' => $verdi->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'data_inizio' => '2024-01-01', 'data_fine' => null, 'note' => null])->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaLibera)->value('tipologia'))->toBe('usufruttuario');
});

it('S8-21 — nella vendita chi entra ha il ruolo di chi esce: nuda → proprietario pieno e proprietario → nuda rispondono 422 con la via che esiste; nuda → nuda passa', function () {
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $rigaNudo = rsRiga($this->immobile, $this->rossi, 'nuda_proprietario', '2020-01-01');
    rsRiga($this->immobile, $neri, 'usufruttuario', '2020-01-01');
    $anteprima = route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]);

    $r = $this->actingAs($this->user)->postJson($anteprima, rsVendita($rigaNudo, $this->bianchi, ['tipologia' => 'proprietario']));
    $r->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    expect($r->json('errors.tipologia.0'))->toContain('la passa come nuda proprietà')->toContain('«Usufrutto → estinzione»');
    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($rigaNudo, $this->bianchi, ['tipologia' => 'proprietario']))->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    expect(Subentro::count())->toBe(0);

    $this->actingAs($this->user)->postJson($anteprima, rsVendita($rigaNudo, $this->bianchi, ['tipologia' => 'nuda_proprietario']))->assertOk();

    // Piena → nuda: la riserva d'usufrutto non ha ancora una via (B3), e il messaggio dice quale strada esiste.
    $altra = Immobile::forceCreate(['condominio_id' => $this->condominio->id, 'nome' => 'Interno 4', 'descrizione' => 'Appartamento', 'interno' => '4']);
    $rigaPieno = rsRiga($altra, $this->rossi, 'proprietario', '2019-03-03');
    $r2 = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $altra]), rsVendita($rigaPieno, $this->bianchi, ['tipologia' => 'nuda_proprietario']));
    $r2->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    expect($r2->json('errors.tipologia.0'))->toContain('riserva d\'usufrutto')->toContain('non è ancora prevista');
});

it('S8-23 — la copia autentica non può essere stata ricevuta in un giorno futuro: 422 su anteprima e registrazione, con il testo del PATCH dallo storico', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $futuro = now()->addYear()->toDateString();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($riga, $this->bianchi, ['copia_autentica_il' => $futuro]))
        ->assertUnprocessable()->assertJsonValidationErrors(['copia_autentica_il' => 'La copia autentica non può essere stata ricevuta in un giorno futuro.']);
    $this->actingAs($this->user)->postJson($this->rotta, rsVendita($riga, $this->bianchi, ['copia_autentica_il' => $futuro]))->assertUnprocessable()->assertJsonValidationErrors('copia_autentica_il');
    expect(Subentro::count())->toBe(0);
});

it('decisione 21 + S8-3 — le bozze comprese possono essere del predecessore: dopo Rossi → Bianchi, alla vendita di Bianchi la frase dice che la bozza del piano di Rossi resterà intestata a Rossi, non a Bianchi (visto a video il 20/09); dalla B3a è una bozza scaduta prima del primo rogito, l\'unica che resta a Rossi', function () {
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $pianoId = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500);
    $scritturaId = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $this->condominio->id, 'esercizio_id' => $this->esercizio->id, 'gestione_id' => $this->gestione->id, 'data_registrazione' => now(), 'data_competenza' => now(), 'numero_protocollo' => 'TEST-EM-S83', 'causale' => 'Emissione', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->where('anagrafica_id', $this->rossi->id)->update(['scrittura_contabile_id' => $scritturaId]);
    // Scade il 15 aprile, prima del rogito del 1° maggio: resta a Rossi (decisione 25) anche dopo la prima vendita.
    // Una bozza di settembre sarebbe passata a Bianchi e poi a Verdi: è il caso di `RiassegnazioneBozzeTest`.
    $rataBozza = DB::table('rate')->insertGetId(['piano_rate_id' => $pianoId, 'numero_rata' => 2, 'data_scadenza' => '2026-04-15', 'importo_totale' => 3650, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert(['rata_id' => $rataBozza, 'anagrafica_id' => $this->rossi->id, 'immobile_id' => $this->immobile->id, 'importo' => 3650, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-04-15', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 3650, 'saldo_usato' => 0, 'totale_calcolato' => 3650]]), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $this->bianchi, ['ho_letto' => true, 'nota_cancello' => 'primo rogito, letto']))->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('rate_quote')->where('rata_id', $rataBozza)->value('anagrafica_id'))->toBe($this->rossi->id);
    $rigaBianchi = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $this->bianchi->id)->value('id');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($rigaBianchi, $verdi, ['decorrenza' => '2026-09-01', 'copia_autentica_il' => '2026-09-05']))->assertOk()->json();

    $frasi = implode("\n", $anteprima['rate']['frasi']);
    expect($frasi)->toContain('Compresa la quota del piano «Piano Ordinaria 2026 2026» non ancora emessa')->toContain('resterà intestata a Rossi Mario')->not->toContain('intestata a Bianchi Anna')
        ->and($anteprima['rate']['conguaglio']['quote_in_bozza'])->toBe([['piano' => 'Piano Ordinaria 2026 2026', 'intestatario' => 'Rossi Mario', 'n' => 1, 'motivo' => 'predecessore']])
        // L1-9: il cancello usa lo stesso insieme del conguaglio anche per le bozze, con il nome del predecessore.
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('il piano «Piano Ordinaria 2026 2026» ha 1 quota non ancora emessa intestata a Rossi Mario: non si può più ricalcolare')->not->toContain('intestata a Bianchi Anna')
        // 36.500 + 3.650 = 40.150 × 122/365 = 13.420 a Verdi.
        ->and($anteprima['rate']['conguaglio']['coppie'][0]['importo'])->toBe(13420);
});

it('S8-bis L1-6 — chi esce ha comprato due volte (da Rossi il 1/3 e da Neri il 1/6): ogni quota ereditata ha la SUA data di acquisto — 184 e 92 giorni a chi esce, coppia 36.600', function () {
    $neri = rsPersona($this->condominio, 'Neri Paolo');
    $verdi = rsPersona($this->condominio, 'Verdi Luca');
    $rigaRossi = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03', null, 50);
    $rigaNeri = rsRiga($this->immobile, $neri, 'proprietario', '2019-03-03', null, 50);
    // Due quote emesse: 36.500 a Rossi, 73.000 a Neri, sullo stesso piano.
    $pianoId = rsQuotaEmessa($this->gestione, $this->condominio, $this->immobile, $this->rossi, 36500);
    $rataId = (int) DB::table('rate')->where('piano_rate_id', $pianoId)->value('id');
    DB::table('rate_quote')->insert(['rata_id' => $rataId, 'anagrafica_id' => $neri->id, 'immobile_id' => $this->immobile->id, 'importo' => 73000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-06-30', 'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => 73000, 'saldo_usato' => 0, 'totale_calcolato' => 73000]]), 'created_at' => now(), 'updated_at' => now()]);

    // Verdi compra da Rossi il 1/3 (quota 50) e da Neri il 1/6 (decisione A: la sua riga al 50 si chiude e riapre al 100).
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaRossi, $verdi, ['decorrenza' => '2026-03-01', 'quota' => 50, 'copia_autentica_il' => '2026-03-05', 'ho_letto' => true, 'nota_cancello' => 'primo rogito, letto']))->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post($this->rotta, rsVendita($rigaNeri, $verdi, ['decorrenza' => '2026-06-01', 'quota' => 50, 'copia_autentica_il' => '2026-06-05', 'ho_letto' => true, 'nota_cancello' => 'secondo rogito, letto']))->assertRedirect()->assertSessionHasNoErrors();
    $rigaVerdi = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $verdi->id)->whereNull('data_fine')->value('id');
    expect((float) DB::table('anagrafica_immobile')->where('id', $rigaVerdi)->value('quota'))->toBe(100.0);

    // Verdi vende tutto a Bianchi il 1/9.
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$this->condominio, $this->immobile]), rsVendita($rigaVerdi, $this->bianchi, ['decorrenza' => '2026-09-01', 'copia_autentica_il' => '2026-09-05']))->assertOk()->json();
    $quote = collect($anteprima['rate']['conguaglio']['quote']);
    $diRossi = $quote->firstWhere('quota_pura', 36500);
    $diNeri = $quote->firstWhere('quota_pura', 73000);
    // Prima: una sola data (l'ultimo acquisto, 1/6) per entrambe → la quota di Rossi contava 92 giorni a Verdi invece di 184.
    expect($diRossi)->toMatchArray(['decorrenza_acquisto' => '2026-03-01', 'giorni_periodo' => 306, 'giorni_uscente' => 184, 'giorni_entrante' => 122, 'uscente' => 18400, 'entrante' => 12200])
        ->and($diNeri)->toMatchArray(['decorrenza_acquisto' => '2026-06-01', 'giorni_periodo' => 214, 'giorni_uscente' => 92, 'giorni_entrante' => 122, 'uscente' => 18400, 'entrante' => 24400])
        ->and($anteprima['rate']['conguaglio']['coppie'][0]['importo'])->toBe(36600);
    $frasi = implode("\n", $anteprima['rate']['frasi']);
    expect($frasi)->toContain('da quote emesse a intestatari diversi o acquistate in date diverse')
        ->toContain('emesse a Rossi Mario; a Verdi Luca dal 1 marzo 2026) divisi per giorni: 184 a Verdi Luca, 122 a Bianchi Anna → € 122,00 a chi entra')
        ->toContain('emesse a Neri Paolo; a Verdi Luca dal 1 giugno 2026) divisi per giorni: 92 a Verdi Luca, 122 a Bianchi Anna → € 244,00 a chi entra')
        ->not->toContain('piani con competenze diverse');
});

it('S8-bis L3-2 — «Associa soggetto» esclude solo chi ha una titolarità in corso oggi: dopo la vendita chi ha venduto torna selezionabile (per restare, ad esempio, come usufruttuario), chi è entrato no', function () {
    $riga = rsRiga($this->immobile, $this->rossi, 'proprietario', '2019-03-03');
    $this->actingAs($this->user)->post($this->rotta, rsVendita($riga, $this->bianchi))->assertRedirect();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.create', [$this->condominio, $this->immobile]))->assertOk()->viewData('page')['props'];
    $ids = collect($props['anagrafiche'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($ids)->toContain($this->rossi->id)->not->toContain($this->bianchi->id);
});
