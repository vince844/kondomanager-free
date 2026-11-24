<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, decisione 705: i passaggi registrati insieme (più persone che escono con lo stesso atto) si annullano **tutti insieme**,
 * con un motivo solo, l'ultimo registrato per primo, nella stessa transazione e sotto una riga sola del registro delle modifiche; nello
 * storico dell'unità sono **una voce sola**, con una parte per persona; la finestra «A chi va» della issue 58 li dice in una riga.
 *
 * Il caso è quello della bozza approvata (`docs/design/beta14_piu_venditori_bozza.html`): Cecilia Angeli e Marta Acciaioli, al 50 %
 * dell'Int. 1, vendono a Luca Toscani con l'atto del 16 novembre 2026; il «Preventivo 2026» da € 960,00 in dodici rate da € 80,00 (€ 40,00
 * a testa), emesse fino a novembre. Il conguaglio di ciascuna è € 20,49 (€ 40,98 a Luca), la rata del 5 dicembre in bozza passa a Luca.
 *
 * Tutto passa dalle rotte: la registrazione con `righe_uscenti` come la fa la pagina (il pannello, poi «Registra i 2 passaggi» con l'impronta),
 * l'annullamento, «Annulla il conguaglio» e la vendita dopo l'insieme.
 *
 * **Che cosa non copre.** MySQL (la suite gira su SQLite: i lock di tutti i passaggi presi prima di ogni lettura non si provano qui); il messaggio
 * del controllore dopo l'annullamento (`Beta14PiuVenditoriCorrezioniTest`); la scheda dello storico a video.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateSaldiAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Subentro\StoricoTitolarita;
use Carbon\CarbonImmutable;
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
});

/*
|--------------------------------------------------------------------------
| Helper (prefisso `pvan`: Pest carica tutti i file nello stesso processo)
|--------------------------------------------------------------------------
*/

/**
 * L'Int. 1 di Cecilia Angeli e Marta Acciaioli al 50 % dal 3 marzo 2019, il «Preventivo 2026» generato a gennaio con loro due, Luca Toscani del
 * condominio. Con `$terza` la sorella Tina al 33,33 % accanto alle due (33,34 % e 33,33 %).
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, cecilia: Anagrafica, marta: Anagrafica, luca: Anagrafica, rigaC: int, rigaM: int, piano: ?PianoRate, tina: ?Anagrafica, rigaT: ?int}
 */
function pvanScenario(bool $conPiano = true, bool $terza = false): array
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
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PVAN-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "pvan-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PVAN' . strtoupper($sigla) . 'PERSON' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $cecilia = $persona('Cecilia Angeli', 'c');
    $marta = $persona('Marta Acciaioli', 'm');
    $luca = $persona('Luca Toscani', 'l');
    $riga = fn (Anagrafica $p, float $quota) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario',
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

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
            'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        ]);
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'cecilia', 'marta', 'luca', 'rigaC', 'rigaM', 'piano', 'tina', 'rigaT');
}

/** Emette a giornale le rate del piano con scadenza fino al giorno dato (di norma da gennaio a novembre). */
function pvanEmetti(array $s, string $fino = '2026-11-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-PVAN-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/** I dati di una persona dell'insieme nella forma di `AnteprimaPassaggioRequest::datiPassaggio()` (gli stessi di `pvMembro` del pacchetto A). */
function pvanMembro(int $riga, Anagrafica $entrante, string $decorrenza = '2026-11-16', array $extra = []): array
{
    $uscente = TitolaritaImmobile::with('anagrafica')->findOrFail($riga);

    return array_merge([
        'tipo' => 'vendita', 'sottotipo' => null, 'riga_uscente' => $uscente, 'entrante' => $entrante, 'decorrenza' => CarbonImmutable::parse($decorrenza)->startOfDay(),
        'quota' => (float) $uscente->quota, 'tipologia' => (string) $uscente->tipologia, 'copia_autentica' => true, 'copia_autentica_il' => CarbonImmutable::parse($decorrenza),
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'nota' => null, 'data_fine_locazione' => null, 'regime_contratto' => null, 'pertinenze' => [],
        'nudi_che_tornano' => null, 'nudi_per_quota' => false, 'accrescimento' => false, 'ho_letto' => true,
        'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.', 'allegato_titolo' => null, 'promemoria_scadenza' => false, 'promemoria_giorni' => null,
        'rinuncia_conguaglio' => false, 'nota_conguaglio' => null, 'conguaglio' => null, 'ordinaria_dopo_atto' => null, 'voci_da_tenere' => [], 'ordinaria_impronta' => null,
        'eredi' => [], 'legato' => false, 'arretrato' => null, 'erede_di_riferimento' => null, 'accessori_segnalati_agli_eredi' => [], 'accordo_del_defunto' => null,
        'interessi_del_defunto' => null, 'interessi_eredi' => null,
    ], $extra);
}

/**
 * L'insieme registrato dalla rotta, come lo registra la pagina: il modulo con `righe_uscenti` (una persona per riga, nell'ordine dato), il pannello
 * dell'anteprima, poi la registrazione con la sua impronta e la spunta se il pannello la chiede.
 *
 * @param list<int> $righe
 * @return list<Subentro> i passaggi, nell'ordine in cui sono stati scritti
 */
function pvanRegistra($test, array $s, array $righe, Anagrafica $entrante, string $decorrenza = '2026-11-16'): array
{
    $modulo = ['tipo' => 'vendita', 'righe_uscenti' => $righe, 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $entrante->id, 'decorrenza' => $decorrenza,
        'quota' => round((float) DB::table('anagrafica_immobile')->whereIn('id', $righe)->sum('quota'), 2), 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => $decorrenza, 'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'pertinenze' => []];
    $pannello = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $modulo)->assertOk()->json();
    $modulo += ['impronta_del_gruppo' => $pannello['impronta']];
    if ($pannello['cancello']['richiesto'] ?? false) {
        $modulo += ['ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)->assertSessionHasNoErrors()->assertRedirect();
    $insieme = (int) Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->max('insieme_id');

    return Subentro::where('insieme_id', $insieme)->whereNull('subentro_padre_id')->orderBy('id')->get()->all();
}

function pvanAnnulla($test, array $s, Subentro $subentro, string $nota = 'Atto registrato con la data sbagliata: lo rifaccio.')
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => $nota]);
}

/** Le righe di titolarità dell'unità, senza id né timestamp: ciò che deve tornare identico. */
function pvanFotoRighe(array $s): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->get()
        ->map(fn ($r) => [(int) $r->anagrafica_id, $r->tipologia, round((float) $r->quota, 2), (string) $r->data_inizio, $r->data_fine === null ? null : (string) $r->data_fine, (bool) $r->attivo])
        ->sort()->values()->all();
}

/** Le quote del piano, con le regole congelate: id compreso, perché devono tornare le stesse righe. */
function pvanFotoQuote(?PianoRate $piano): array
{
    if ($piano === null) {
        return [];
    }

    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')
        ->get(['rate_quote.id', 'rate_quote.rata_id', 'rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.importo_pagato', 'rate_quote.stato', 'rate_quote.regole_calcolo', 'rate_quote.scrittura_contabile_id'])
        ->map(fn ($q) => (array) $q)->all();
}

/** Tutto ciò che l'annullamento deve rimettere: righe, quote, saldi. */
function pvanFoto(array $s): array
{
    return ['righe' => pvanFotoRighe($s), 'quote' => pvanFotoQuote($s['piano']),
        'saldi' => Saldo::orderBy('id')->get(['id', 'anagrafica_id', 'saldo_iniziale', 'subentro_id'])->toArray()];
}

/** La voce dello storico dell'unità con l'id dato (il primo dell'insieme). */
function pvanVoce(array $s, int $id): ?array
{
    return collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $id);
}

/*
|--------------------------------------------------------------------------
| (1) L'annullamento di tutti insieme
|--------------------------------------------------------------------------
*/

it('(1) annullato dall\'id del primo o del secondo: tutti e due annullati, l\'ultimo per primo, righe quote e saldi come prima, una riga del registro con il motivo; poi si registra di nuovo', function (int $quale) {
    $s = pvanScenario();
    pvanEmetti($s);
    $prima = pvanFoto($s);
    $passaggi = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);
    expect(Saldo::whereIn('subentro_id', collect($passaggi)->pluck('id'))->count())->toBe(4);
    $righeDelRegistro = DB::table('registro_modifiche')->count();

    pvanAnnulla($this, $s, $passaggi[$quale])->assertSessionHasNoErrors()->assertRedirect();

    expect(pvanFoto($s))->toBe($prima);
    foreach ($passaggi as $p) {
        $p = Subentro::conAnnullati()->findOrFail($p->id);
        expect($p->annullato_il)->not->toBeNull()
            ->and((int) $p->annullato_da)->toBe($this->user->id)
            ->and($p->nota_annullamento)->toBe('Atto registrato con la data sbagliata: lo rifaccio.');
    }

    // Una riga sola del registro delle modifiche, con il motivo, le persone e il rimando al primo; i due annullamenti contati nel lotto.
    expect(DB::table('registro_modifiche')->count())->toBe($righeDelRegistro + 1);
    $riga = DB::table('registro_modifiche')->orderByDesc('id')->first();
    expect($riga->riepilogo)->toBe('Passaggi annullati insieme: vendita dell\'unità Int. 1 da Cecilia Angeli e Marta Acciaioli a Luca Toscani dal 16/11/2026')
        ->and($riga->perche)->toBe('Atto registrato con la data sbagliata: lo rifaccio.')
        ->and((int) $riga->oggetto_id)->toBe($passaggi[0]->id)
        ->and(json_decode($riga->rimando, true)['id'])->toBe($passaggi[0]->id)
        ->and(json_decode($riga->lotto, true)['conteggi']['passaggio.gesto'] ?? null)->toBe(2)
        ->and(DB::table('registro_modifiche_soggetti')->where('riga_id', $riga->id)->where('soggetto_tipo', 'anagrafica')->pluck('soggetto_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$s['cecilia']->id, $s['marta']->id, $s['luca']->id])->sort()->values()->all());

    // Annullati insieme, restano insieme: lo storico ha una voce annullata, con le due parti.
    $voce = pvanVoce($s, $passaggi[0]->id);
    expect($voce['annullato'])->toBeTrue()
        ->and(collect($voce['insieme']['passaggi'])->pluck('id')->all())->toBe(collect($passaggi)->pluck('id')->all())
        ->and($voce['annullabile']['si'])->toBeFalse()
        ->and(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['passaggi'])->toBe(0);

    // L'insieme si registra di nuovo, con la data giusta.
    $rifatti = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca'], '2026-11-18');
    expect($rifatti)->toHaveCount(2)
        ->and(DB::table('anagrafica_immobile')->whereIn('id', [$s['rigaC'], $s['rigaM']])->pluck('data_fine')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all())->toBe(['2026-11-17']);
})->with(['dall\'id del primo' => [0], 'dall\'id del secondo' => [1]]);

/*
|--------------------------------------------------------------------------
| (2) Un motivo in una parte ferma tutto
|--------------------------------------------------------------------------
*/

it('(2) la coppia di una delle due assorbita da un piano: niente annullato, il messaggio dice di chi è; lo storico lo dice prima di confermare', function (string $chi, bool $parolaPerParola) {
    $s = pvanScenario();
    pvanEmetti($s);
    [$primo, $secondo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);
    $assorbito = $chi === 'Marta Acciaioli' ? $secondo : $primo;
    Saldo::where('subentro_id', $assorbito->id)->update(['is_applicato' => true, 'piano_rate_id' => $s['piano']->id]);
    $prima = pvanFoto($s);
    $righeDelRegistro = DB::table('registro_modifiche')->count();

    $messaggio = pvanAnnulla($this, $s, $primo)->assertUnprocessable()->json('errors.passaggio.0');

    expect($messaggio)->toStartWith("La parte di {$chi}: il conguaglio di questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com'è.")
        ->toContain('Preventivo 2026')
        ->and(pvanFoto($s))->toBe($prima)
        ->and(Subentro::whereIn('id', [$primo->id, $secondo->id])->count())->toBe(2)
        ->and(DB::table('registro_modifiche')->count())->toBe($righeDelRegistro);

    // Lo storico legge la stessa regola, senza scrivere. Per l'ultima registrata lo stesso motivo, parola per parola. Per la prima lo stesso motivo,
    // ma la frase del piano elenca i passaggi che il piano ha preso nel conguaglio: lo storico li legge tutti e due, l'azione dopo aver annullato Marta.
    $voce = pvanVoce($s, $primo->id);
    expect($voce['annullabile']['si'])->toBeFalse();
    if ($parolaPerParola) {
        expect($voce['annullabile']['motivo'])->toBe($messaggio);
    } else {
        expect($voce['annullabile']['motivo'])->toStartWith("La parte di {$chi}: il conguaglio di questo passaggio è già stato assorbito da un piano rate, e il passaggio non si annulla così com'è.")
            ->toContain('Preventivo 2026');
    }
})->with(['Marta, l\'ultima registrata' => ['Marta Acciaioli', true], 'Cecilia, la prima: Marta si annulla e torna indietro' => ['Cecilia Angeli', false]]);

/*
|--------------------------------------------------------------------------
| (3) Lo storico: una voce sola, con una parte per persona
|--------------------------------------------------------------------------
*/

it('(3) lo storico: una voce con due parti, la quota e il conguaglio di ciascuna; annullabile, con gli effetti di tutte e due; il contatore conta i due passaggi', function () {
    $s = pvanScenario();
    pvanEmetti($s);
    [$primo, $secondo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);

    $storico = app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh());
    expect($storico['subentri'])->toHaveCount(1)
        ->and($storico['passaggi'])->toBe(2);
    $voce = $storico['subentri'][0];

    // I campi di oggi restano sul primo; le parti sono le voci di ciascuna, con la quota.
    expect($voce['id'])->toBe($primo->id)
        ->and($voce['entrante'])->toBe('Luca Toscani')
        ->and(collect($voce['insieme']['passaggi'])->map(fn (array $p) => [$p['id'], $p['uscente'], (float) $p['quota'], $p['conguaglio']['stato'], $p['conguaglio']['importo'], $p['conguaglio']['importo_formattato'], $p['fermate']])->all())
        ->toBe([
            [$primo->id, 'Cecilia Angeli', 50.0, 'proposto', 2049, '€ 20,49', []],
            [$secondo->id, 'Marta Acciaioli', 50.0, 'proposto', 2049, '€ 20,49', []],
        ])
        ->and($voce['insieme']['quota_entrante'])->toBe(100.0)
        ->and($voce['insieme']['passaggi'][0])->not->toHaveKey('annullabile')
        // La solidarietà con tutte le persone che escono, non con la prima sola.
        ->and(implode("\n", $voce['obbligati']))->toContain('Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli');

    // Si annulla: niente lo ferma (né la riga di Luca sommata dal secondo, né il secondo «registrato dopo» il primo), e gli effetti dicono tutto.
    expect($voce['annullabile']['si'])->toBeTrue()
        ->and($voce['annullabile']['motivo'])->toBeNull()
        // La frase di chi torna titolare è quella della bozza approvata (lo spazio fra il numero e «%» non si separa).
        ->and($voce['annullabile']['effetti'])->toBe([
            "Cecilia Angeli e Marta Acciaioli tornano titolari al 50\u{00A0}% senza data di fine; Luca Toscani esce dall'unità.",
            'La quota di una rata passata a Luca Toscani torna a Cecilia Angeli, con le regole di prima.',
            'La quota di una rata passata a Luca Toscani torna a Marta Acciaioli, con le regole di prima.',
            'Le 4 righe del conguaglio si tolgono dai saldi della gestione.',
        ]);
});

it('(3) i tre fratelli: Cecilia e Marta vendono a Tina, che aveva già il 33,33 %; lo storico non ferma il primo per la riga di Tina sommata dal secondo, e l\'annullamento rimette tutto', function () {
    $s = pvanScenario(terza: true);
    pvanEmetti($s);
    $prima = pvanFoto($s);
    [$primo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['tina']);
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $s['tina']->id)->whereNull('data_fine')->pluck('quota')->map(fn ($q) => (float) $q)->all())->toBe([100.0]);

    $voce = pvanVoce($s, $primo->id);
    expect($voce['annullabile']['motivo'])->toBeNull()
        ->and($voce['annullabile']['si'])->toBeTrue()
        ->and($voce['insieme']['quota_entrante'])->toBe(100.0)
        ->and(collect($voce['insieme']['passaggi'])->map(fn (array $p) => (float) $p['quota'])->all())->toBe([33.34, 33.33]);

    pvanAnnulla($this, $s, $primo)->assertSessionHasNoErrors()->assertRedirect();
    expect(pvanFoto($s))->toBe($prima);
});

/*
|--------------------------------------------------------------------------
| (4) Un passaggio registrato dopo l'insieme
|--------------------------------------------------------------------------
*/

it('(4) un passaggio registrato dopo l\'insieme sull\'unità lo ferma con la frase di oggi; annullato quello, l\'insieme si annulla', function () {
    $s = pvanScenario();
    pvanEmetti($s);
    [$primo, $secondo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);
    $bice = Anagrafica::forceCreate(['nome' => 'Bice Ferri', 'email' => "pvan-bice{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PVANBICEFER' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $bice->condomini()->syncWithoutDetaching([$s['c']->id]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => (int) $secondo->riga_entrante_id, 'anagrafica_entrante_id' => $bice->id, 'decorrenza' => '2026-11-20',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-11-20', 'estremi_titolo' => 'Atto del notaio Bianchi, rep. 77',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, la rata in bozza a chi compra',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $dopo = Subentro::whereNull('subentro_padre_id')->latest('id')->firstOrFail();

    $attesa = 'Dopo questo, su Interno 1, c\'è un altro passaggio: la vendita da Luca Toscani a Bice Ferri, dal 20 novembre 2026. Si annulla prima quello, poi questo.';
    expect(pvanAnnulla($this, $s, $secondo)->assertUnprocessable()->json('errors.passaggio.0'))->toBe($attesa)
        ->and(pvanVoce($s, $primo->id)['annullabile']['motivo'])->toBe($attesa)
        ->and(Subentro::whereIn('id', [$primo->id, $secondo->id])->count())->toBe(2);

    pvanAnnulla($this, $s, $dopo)->assertSessionHasNoErrors()->assertRedirect();
    pvanAnnulla($this, $s, $primo)->assertSessionHasNoErrors()->assertRedirect();
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->whereIn('id', [$s['rigaC'], $s['rigaM']])->whereNull('data_fine')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| (5) «Annulla il conguaglio» resta per passaggio
|--------------------------------------------------------------------------
*/

it('(5) «Annulla il conguaglio» di Cecilia dalla rotta: le sue righe se ne vanno, quelle di Marta restano; lo storico lo dice per persona, e l\'insieme si annulla ancora', function () {
    $s = pvanScenario();
    pvanEmetti($s);
    [$primo, $secondo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $primo]), [
        'nota_annullamento_conguaglio' => 'Cecilia e Luca hanno regolato dal notaio.',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(Saldo::where('subentro_id', $primo->id)->count())->toBe(0)
        ->and(Saldo::where('subentro_id', $secondo->id)->count())->toBe(2);
    $voce = pvanVoce($s, $primo->id);
    expect(collect($voce['insieme']['passaggi'])->map(fn (array $p) => [$p['uscente'], $p['conguaglio']['stato'], $p['conguaglio']['nota_annullamento']])->all())->toBe([
        ['Cecilia Angeli', 'annullato', 'Cecilia e Luca hanno regolato dal notaio.'],
        ['Marta Acciaioli', 'proposto', null],
    ])
        ->and($voce['annullabile']['si'])->toBeTrue()
        ->and($voce['annullabile']['effetti'])->toContain('Le 2 righe del conguaglio si tolgono dai saldi della gestione.');

    pvanAnnulla($this, $s, $primo)->assertSessionHasNoErrors()->assertRedirect();
    expect(Saldo::count())->toBe(0)
        ->and(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| (6) La finestra «A chi va» della issue 58
|--------------------------------------------------------------------------
*/

it('(6) la finestra «A chi va» della issue 58: l\'insieme è una riga sola, con chi esce in tutte e due, e le scelte non cambiano', function () {
    $this->travelTo('2026-11-23 10:00:00');
    $s = pvanScenario(conPiano: false);
    $saldo = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);
    pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);

    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($saldo->fresh(), $s['g']);

    expect($anteprima['passaggi'])->toBe([[
        'data' => '2026-11-16', 'tipo' => 'vendita', 'etichetta' => 'Vendita', 'riserva' => false,
        'uscenti' => ['Cecilia Angeli', 'Marta Acciaioli'], 'entranti' => ['Luca Toscani'],
    ]])
        ->and(collect($anteprima['scelte'])->map(fn (array $c) => [$c['alla_data'], $c['regola'], collect($c['quote'])->mapWithKeys(fn ($q) => [$q['nome'] => $q['importo']])->all()])->all())
        ->toBe([
            ['2026-01-01', true, ['Cecilia Angeli' => 9000, 'Marta Acciaioli' => 9000]],
            ['2026-11-16', false, ['Luca Toscani' => 18000]],
        ]);
});

/*
|--------------------------------------------------------------------------
| (7) L'esito per il messaggio del controllore
|--------------------------------------------------------------------------
*/

it('(7) l\'esito dell\'annullamento dice quanti passaggi ha annullato, quali, chi esce, e le frasi di tutti; un passaggio da solo ne annulla uno, senza insieme', function () {
    $s = pvanScenario();
    pvanEmetti($s);
    [$primo, $secondo] = pvanRegistra($this, $s, [$s['rigaC'], $s['rigaM']], $s['luca']);

    $esito = app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->execute($secondo, 'Atto registrato due volte, lo tolgo.', $this->user);

    expect($esito['passaggi_annullati'])->toBe(2)
        ->and($esito['insieme']['passaggi'])->toBe([$primo->id, $secondo->id])
        ->and($esito['insieme']['nomi'])->toBe(['Cecilia Angeli', 'Marta Acciaioli'])
        ->and($esito['uscente'])->toBe('Cecilia Angeli e Marta Acciaioli')
        ->and($esito['entrante'])->toBe('Luca Toscani')
        // Le righe: Cecilia chiusa e Luca aperta dal primo, Marta chiusa e Luca sommata dal secondo; una rata per ciascuna; due coppie.
        ->and([$esito['righe'], $esito['rate'], $esito['saldi']])->toBe([4, 2, 4])
        ->and($esito['insieme']['frasi'])->toBe([
            'Le righe di titolarità scritte dai passaggi sono tornate come prima.',
            'La quota di una rata passata a Luca Toscani è tornata a Cecilia Angeli, con le regole di prima.',
            'La quota di una rata passata a Luca Toscani è tornata a Marta Acciaioli, con le regole di prima.',
            'Le 4 righe del conguaglio sono state tolte dai saldi della gestione.',
        ]);

    // Un passaggio da solo, su un'altra unità: uno, e nessun insieme.
    $t = pvanScenario();
    app(\App\Actions\Subentro\RegistraSubentroAction::class)->execute($t['c'], $t['unita'], pvanMembro($t['rigaC'], $t['luca']), $this->user);
    $solo = Subentro::where('immobile_id', $t['unita']->id)->firstOrFail();
    $uno = app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->execute($solo, 'Atto registrato due volte, lo tolgo.', $this->user);
    expect($uno['passaggi_annullati'])->toBe(1)
        ->and($uno['insieme'])->toBeNull()
        ->and($uno['uscente'])->toBe('Cecilia Angeli');
});
