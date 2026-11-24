<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 786 (prova a video del 23/11/2026, decisione 705): le frasi del pannello «Cosa cambierà» dei passaggi registrati insieme non tenevano conto del
 * gruppo. Qui la forma di «Beta14PiuVenditoriTest»: Cecilia Angeli e Marta Acciaioli vendono a Luca Toscani con lo stesso atto, e Tina Angeli, che non
 * esce, ha una terza parte dell'unità.
 *
 * 1. nella parte di chi esce, le quote di chi esce con lo stesso atto non sono «le altre quote che restano»;
 * 2. nella parte della seconda persona, il piano non è «preso nel conguaglio» del passaggio gemello, che nella prova a secco è già scritto.
 *
 * **Che cosa non copre**: MySQL (la suite gira su SQLite). La testa dello storico è in `TitolaritaSheetInsieme.test.ts`.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    foreach (['Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->travelTo('2026-11-23 10:00:00');
});

function c786Scenario(bool $conPiano = true, bool $terza = false, string $ruolo = 'proprietario', array $ripartizione = ['proprietario' => 100], bool $lucaInt2 = false): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 96000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "C786-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "c786-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'C786' . strtoupper($sigla) . 'PERSONA' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $cecilia = $persona('Cecilia Angeli', 'c');
    $marta = $persona('Marta Acciaioli', 'm');
    $luca = $persona('Luca Toscani', 'l');
    $riga = fn (Anagrafica $p, float $quota) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo,
        'quota' => $quota, 'attivo' => true, 'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $tina = null;
    $rigaT = null;
    if ($terza) {
        $rigaC = $riga($cecilia, 33.34);
        $rigaM = $riga($marta, 33.33);
        $tina = $persona('Tina Angeli', 't');
        $rigaT = $riga($tina, 33.33);
    } else {
        $rigaC = $riga($cecilia, 50);
        $rigaM = $riga($marta, 50);
    }

    // L'Int. 2 di Luca Toscani: chi compra ha già un'altra unità (le teste in assemblea).
    if ($lucaInt2) {
        $unita2 = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "C7862-{$seq}", 'nome' => 'Interno 2', 'interno' => '2']);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita2->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $luca->id, 'immobile_id' => $unita2->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
            'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        ]);
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'cecilia', 'marta', 'luca', 'rigaC', 'rigaM', 'piano', 'tina', 'rigaT', 'conto');
}
function c786Emetti(array $s, string $fino = '2026-11-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-C786-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}
function c786Modulo(array $s, array $righe, ?Anagrafica $entrante = null, array $extra = []): array
{
    $quota = round((float) DB::table('anagrafica_immobile')->whereIn('id', $righe)->sum('quota'), 2);

    return array_merge([
        'tipo' => 'vendita', 'righe_uscenti' => $righe, 'riga_uscente_id' => null, 'anagrafica_entrante_id' => ($entrante ?? $s['luca'])->id,
        'decorrenza' => '2026-11-16', 'quota' => $quota, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-11-16',
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'pertinenze' => [],
    ], $extra);
}
function c786Anteprima($test, array $s, array $dati)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** Il modulo di una persona sola (il passaggio di oggi): la riga in «Chi esce», senza l'elenco. */
function c786Solo(array $s, int $riga): array
{
    $m = c786Modulo($s, [$riga]);
    unset($m['righe_uscenti']);

    return array_merge($m, ['riga_uscente_id' => $riga]);
}

/** Il pannello dell'insieme di Cecilia e Marta, e le frasi di una delle due parti. */
function c786Parti($test, array $s, array $righe): array
{
    $p = c786Anteprima($test, $s, c786Modulo($s, $righe))->assertOk()->json();

    return [$p, array_map(fn (array $x) => $x['anteprima'], $p['parti'])];
}

/** Il piano con un incasso su una sua quota: «ha un incasso», e nessuna rata a giornale (il caso della prova a video: il piano si conguaglia qui). */
function c786ConIncasso(array $s): void
{
    $rata = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('data_scadenza')->value('id');
    DB::table('rate_quote')->where('rata_id', $rata)->update(['importo_pagato' => 1000, 'stato' => 'parzialmente_pagata']);
}

it('1 — le quote di chi esce con lo stesso atto non sono «le altre che restano»: Marta nella parte di Cecilia, Cecilia nella parte di Marta; Tina resta «altra»', function () {
    $s = c786Scenario(terza: true);
    c786Emetti($s);
    [, $pannelli] = c786Parti($this, $s, [$s['rigaC'], $s['rigaM']]);

    $frasiC = implode(' ', $pannelli[0]['rate']['frasi']);
    $frasiM = implode(' ', $pannelli[1]['rate']['frasi']);
    // Le quote emesse: 11 a testa; le altre, che restano, sono solo quelle di Tina.
    expect($frasiC)->toContain('Le altre 11 quote emesse su questa unità restano a Tina Angeli: questo passaggio non le riguarda. Le quote di Marta Acciaioli, che esce con lo stesso atto, sono nella sua parte.')
        ->and($frasiM)->toContain('Le altre 11 quote emesse su questa unità restano a Tina Angeli: questo passaggio non le riguarda. Le quote di Cecilia Angeli, che esce con lo stesso atto, sono nella sua parte.')
        ->and($frasiC)->not->toContain('restano a Marta Acciaioli')
        ->and($frasiM)->not->toContain('restano a Cecilia Angeli');
});

it('1 bis — senza nessun altro titolare la frase «Le altre … restano …» non compare: resta solo quella del gruppo', function () {
    $s = c786Scenario();
    c786Emetti($s);
    [, $pannelli] = c786Parti($this, $s, [$s['rigaC'], $s['rigaM']]);

    foreach ([0 => 'Marta Acciaioli', 1 => 'Cecilia Angeli'] as $i => $gemella) {
        $testo = implode(' ', $pannelli[$i]['rate']['frasi']);
        expect($testo)->not->toContain('Le altre')->and($testo)->not->toContain('L\'altra quota')->and($testo)->not->toContain('non le riguarda')
            ->and($testo)->toContain("Le quote di {$gemella}, che esce con lo stesso atto, sono nella sua parte.");
    }
});

it('1 ter — un passaggio da solo dice «Le altre quote … restano …» come prima, anche con le quote di Marta', function () {
    $s = c786Scenario(terza: true);
    c786Emetti($s);
    $p = c786Anteprima($this, $s, c786Solo($s, $s['rigaC']))->assertOk()->json();

    expect($p['rate']['frasi'])->toContain('Le altre 22 quote emesse su questa unità restano a Marta Acciaioli e Tina Angeli: questo passaggio non le riguarda.')
        ->and(implode(' ', $p['rate']['frasi']))->not->toContain('esce con lo stesso atto');
});

it('2 — nella parte di Marta il piano non è «preso nel conguaglio» del passaggio gemello: è preso anche nel conguaglio della parte di Cecilia, qui sopra; la parte di Cecilia dice solo l\'incasso', function () {
    $s = c786Scenario(terza: true);
    c786ConIncasso($s);
    [$p, $pannelli] = c786Parti($this, $s, [$s['rigaC'], $s['rigaM']]);

    $motiviC = implode(' ', $pannelli[0]['cancello']['motivi']);
    $motiviM = implode(' ', $pannelli[1]['cancello']['motivi']);
    expect($motiviC)->toContain('(ha un incasso su una sua quota)')
        ->and($motiviM)->toContain('(ha un incasso su una sua quota ed è preso anche nel conguaglio della parte di Cecilia Angeli, qui sopra)')
        ->and($pannelli[1]['rate']['frasi'][0])->toContain('ha un incasso su una sua quota ed è preso anche nel conguaglio della parte di Cecilia Angeli, qui sopra: non si ricalcola più')
        ->and(json_encode($p, JSON_UNESCAPED_UNICODE))->not->toContain('è stato preso nel conguaglio del passaggio Cecilia');
});

it('2 bis — un passaggio vero di prima, fuori dal gruppo, si nomina ancora come oggi', function () {
    $s = c786Scenario(terza: true);
    c786ConIncasso($s);
    // Il passaggio di Tina verso Luca, registrato prima e da sola, che ha già conguagliato il piano.
    $uno = c786Anteprima($this, $s, c786Solo($s, $s['rigaT']))->assertOk()->json();
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]),
        c786Solo($s, $s['rigaT']) + ['ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi: Tina vende prima.'])->assertSessionHasNoErrors();
    expect($uno)->toHaveKey('rate');

    [, $pannelli] = c786Parti($this, $s, [$s['rigaC'], $s['rigaM']]);
    $motiviM = implode(' ', $pannelli[1]['cancello']['motivi']);
    expect($motiviM)->toContain('è stato preso nel conguaglio del passaggio Tina Angeli → Luca Toscani')
        ->and($motiviM)->toContain('e anche in quello della parte di Cecilia Angeli, qui sopra')
        ->and(implode(' ', $pannelli[0]['cancello']['motivi']))->not->toContain('qui sopra');
});

it('Fase 1-bis — dopo «è tenuto fermo dalla successione …» la frase del gruppo non dice «e anche in quello»: dice «ed è preso anche nel conguaglio della parte di …, qui sopra»', function () {
    $piano = new PianoRate(['nome' => 'Preventivo 2026']);
    $unita = new Immobile(['nome' => 'Interno 1']);
    $ugo = new Anagrafica(['nome' => 'Ugo Bassi']);
    $cecilia = new Anagrafica(['nome' => 'Cecilia Angeli']);
    $luca = new Anagrafica(['nome' => 'Luca Toscani']);
    $successione = (new \App\Models\Gestionale\Subentro())->forceFill(['id' => 1, 'tipo_passaggio' => 'successione', 'tipologia' => 'proprietario', 'decorrenza' => '2026-03-01',
        'registro' => ['conguaglio' => ['scelta' => \App\Models\Gestionale\Subentro::CONGUAGLIO_NON_SCRITTO]]])->setRelation('immobile', $unita)->setRelation('uscente', $ugo);
    $gemello = (new \App\Models\Gestionale\Subentro())->forceFill(['id' => 2, 'tipo_passaggio' => 'vendita', 'tipologia' => 'proprietario', 'decorrenza' => '2026-11-16', 'registro' => []])
        ->setRelation('immobile', $unita)->setRelation('uscente', $cecilia)->setRelation('entrante', $luca);
    $r = new \ReflectionProperty(PianoRate::class, 'passaggiDelConguaglio');
    $r->setAccessible(true);
    $r->setValue($piano, [$successione, $gemello]);

    $frase = $piano->fraseDelFermo(delGruppo: [2]);

    expect($frase)->toContain('tenuto fermo dalla successione di Ugo Bassi, Interno 1, dal 1 marzo 2026 ed è preso anche nel conguaglio della parte di Cecilia Angeli, qui sopra')
        ->not->toContain('e anche in quello');
});
