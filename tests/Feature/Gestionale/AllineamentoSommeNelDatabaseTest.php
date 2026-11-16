<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il verdetto di allineamento di un piano (`PianoRateQuoteService`: la copertura del bilancio della dashboard e la pagina del piano lo chiedono allo stesso
 * posto) confronta quanto il piano dovrebbe mettere in rata con quanto ha messo (`totalePuroGeneratoCents`). Quella seconda cifra si somma nel database, senza
 * caricare le quote come modelli: a 3.000 persone × 12 rate la dashboard ne caricava 36.000 (Coda 520).
 *
 * La prova differenziale: la somma di prima, che leggeva le quote una per una con lo snapshot decodificato in PHP, resta qui com'era (`AsdServizioDiPrima`), e su
 * ogni forma delle quote e dei piani dà la stessa cifra, lo stesso scarto, lo stesso stato e lo stesso verdetto della somma nel database; sulla dashboard, con
 * due gestioni, lo stesso elenco dei piani disallineati e la stessa copertura.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Tabella;
use App\Services\PianoRateQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

/** Il servizio con la somma di prima: le rate con le quote caricate come modelli, lo snapshot letto in PHP. Tutto il resto è il servizio di oggi. */
final class AsdServizioDiPrima extends PianoRateQuoteService
{
    public function totalePuroGeneratoCents(PianoRate $pianoRate): int
    {
        $pianoRate->loadMissing('rate.rateQuote');
        $totale = 0;
        foreach ($pianoRate->rate as $rata) {
            foreach ($rata->rateQuote as $quota) {
                $regole = $quota->regole_calcolo;
                if (is_array($regole) && isset($regole['importi']['quota_pura_gestione'])) {
                    $totale += (int) $regole['importi']['quota_pura_gestione'];

                    continue;
                }
                if ($rata->numero_rata !== 0 && $quota->tipo !== 'saldo_iniziale') {
                    $totale += (int) $quota->importo;
                }
            }
        }

        return $totale;
    }
}

beforeEach(function () {
    $this->withoutVite();
});

/**
 * Tre persone, 12 rate, un pregresso a debito e uno a credito (di un'unità, senza persona): le quote come le scrive la generazione. La voce del preventivo è
 * nel piano (`piano_rate_capitoli`), come la scrive la pagina: l'atteso è la voce, e il piano generato è allineato.
 */
function asdPiano($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 300], 'B' => ['bianchi', 300], 'C' => ['verdi', 400]], 12, 'prima_rata', [['rossi', 'A', 12345], [null, 'B', -6789]], 120000);
    asdVoceNelPiano($s['piano'], 'Spese generali', 120000);

    return $s;
}

/** La voce del preventivo nel piano, con il suo importo. */
function asdVoceNelPiano(PianoRate $piano, string $voce, int $importo): void
{
    $conto = Conto::where('nome', $voce)->whereIn('piano_conto_id', PianoConto::where('gestione_id', $piano->gestione_id)->pluck('id'))->firstOrFail();
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $conto->id, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
}

/** Le cifre del verdetto, con un servizio, su un piano riletto ogni volta. */
function asdCifre(PianoRateQuoteService $svc, int $pianoId): array
{
    $piano = fn () => PianoRate::findOrFail($pianoId);

    return ['puro' => $svc->totalePuroGeneratoCents($piano()), 'atteso' => $svc->totaleAttesoCents($piano()), 'scarto' => $svc->scartoCents($piano()),
        'stato' => $svc->statoAllineamento($piano()), 'disallineato' => $svc->eDisallineato($piano())];
}

/** Le quote di una rata del piano, in ordine. */
function asdQuote(array $s, int $numero): \Illuminate\Support\Collection
{
    return DB::table('rate_quote')->where('rata_id', lcdRata($s, $numero))->orderBy('id')->get();
}

/** Lo snapshot di una quota cambiato con una funzione; `$grezzo` lo scrive com'è (anche un JSON che non è un oggetto). */
function asdSnapshot(int $quotaId, ?callable $cambia, ?string $grezzo = null): void
{
    if ($cambia === null) {
        DB::table('rate_quote')->where('id', $quotaId)->update(['regole_calcolo' => $grezzo]);

        return;
    }
    $regole = json_decode((string) DB::table('rate_quote')->where('id', $quotaId)->value('regole_calcolo'), true);
    DB::table('rate_quote')->where('id', $quotaId)->update(['regole_calcolo' => json_encode($cambia($regole))]);
}

/** La cifra pura di una quota spostata di `$delta` centesimi (e l'importo con lei). */
function asdSposta(int $quotaId, int $delta, bool $ancheImporto = true): void
{
    asdSnapshot($quotaId, function (array $r) use ($delta) {
        $r['importi']['quota_pura_gestione'] = (int) $r['importi']['quota_pura_gestione'] + $delta;

        return $r;
    });
    if ($ancheImporto) {
        DB::table('rate_quote')->where('id', $quotaId)->increment('importo', $delta);
    }
}

/** Le forme delle quote e dei piani su cui le due somme si confrontano, e lo stato che ognuna deve dare quando è sicuro. */
const ASD_VARIANTI = [
    'come generato' => 'allineato',
    'una quota in più' => 'disallineato',
    'un centesimo' => 'disallineato',
    'quote pagate in parte' => 'allineato',
    'senza lo snapshot' => null,
    'la cifra pura nulla' => null,
    'senza gli importi' => null,
    'lo snapshot che non è un oggetto' => null,
    'la cifra scritta come testo' => 'allineato',
    // `(int)` tronca, e il database tronca allo stesso modo: lo snapshot con 0,7 centesimi in più resta allineato.
    'la cifra con i decimali' => 'allineato',
    'la rata zero e il pregresso' => null,
    'il credito di una voce di entrata' => 'disallineato',
    'quota aperta' => 'quota_aperta',
    'straordinario' => 'disallineato',
    'senza rate' => 'allineato',
];

function asdApplica(string $variante, array $s): void
{
    $piano = (int) $s['piano']->id;
    $prima = fn (int $n) => (int) asdQuote($s, $n)->first()->id;
    match ($variante) {
        'come generato' => null,
        'una quota in più' => asdSposta($prima(3), 500),
        'un centesimo' => asdSposta($prima(4), 1, false),
        'quote pagate in parte' => asdQuote($s, 1)->each(fn ($q) => DB::table('rate_quote')->where('id', $q->id)
            ->update(['importo_pagato' => intdiv((int) $q->importo, 2), 'stato' => 'parzialmente_pagata'])),
        'senza lo snapshot' => asdQuote($s, 1)->concat(asdQuote($s, 2))->each(fn ($q) => asdSnapshot((int) $q->id, null, null)),
        'la cifra pura nulla' => asdQuote($s, 5)->each(fn ($q) => asdSnapshot((int) $q->id, fn ($r) => array_replace_recursive($r, ['importi' => ['quota_pura_gestione' => null]]))),
        'senza gli importi' => asdQuote($s, 6)->each(fn ($q) => asdSnapshot((int) $q->id, fn ($r) => array_diff_key($r, ['importi' => true]))),
        'lo snapshot che non è un oggetto' => [asdSnapshot($prima(7), null, '[1, 2, 3]'), asdSnapshot((int) asdQuote($s, 7)->last()->id, null, json_encode('{"importi":{"quota_pura_gestione":1}}'))],
        'la cifra scritta come testo' => asdSnapshot($prima(8), fn ($r) => array_replace_recursive($r, ['importi' => ['quota_pura_gestione' => (string) $r['importi']['quota_pura_gestione']]])),
        'la cifra con i decimali' => asdSnapshot($prima(9), fn ($r) => array_replace_recursive($r, ['importi' => ['quota_pura_gestione' => $r['importi']['quota_pura_gestione'] + 0.7]])),
        'la rata zero e il pregresso' => (function () use ($s, $piano) {
            $rataZero = DB::table('rate')->insertGetId(['piano_rate_id' => $piano, 'numero_rata' => 0, 'data_scadenza' => '2026-01-05', 'importo_totale' => 8000,
                'created_at' => now(), 'updated_at' => now()]);
            $base = (array) asdQuote($s, 10)->first();
            foreach ([['saldo_iniziale', 5000], ['ordinaria', 3000]] as [$tipo, $importo]) {
                DB::table('rate_quote')->insert(['rata_id' => $rataZero, 'anagrafica_id' => $base['anagrafica_id'], 'immobile_id' => $base['immobile_id'], 'importo' => $importo,
                    'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => $tipo, 'regole_calcolo' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
            // Una quota di pregresso senza snapshot su una rata vera: fuori anche lei; una ordinaria senza snapshot: dentro con il suo importo.
            DB::table('rate_quote')->where('id', $base['id'])->update(['tipo' => 'saldo_iniziale', 'regole_calcolo' => null]);
            DB::table('rate_quote')->where('id', (int) asdQuote($s, 10)->last()->id)->update(['regole_calcolo' => null]);
        })(),
        'il credito di una voce di entrata' => [asdSnapshot($prima(11), fn ($r) => array_replace_recursive($r, ['importi' => ['quota_pura_gestione' => -300]])),
            DB::table('rate_quote')->where('id', $prima(11))->update(['importo' => -300])],
        'quota aperta' => (function () use ($s, $piano) {
            asdSposta((int) asdQuote($s, 12)->first()->id, -500);
            DB::table('piani_rate')->where('id', $piano)->update(['quote_non_assegnate' => json_encode([
                'scarto_cents' => 500, 'voci' => [['importo_cents' => 500, 'segno' => 1, 'accettata' => true]]])]);
        })(),
        'straordinario' => DB::table('piani_rate')->where('id', $piano)->update(['tipo' => 'straordinario']),
        'senza rate' => [DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $piano)->pluck('id'))->delete(),
            DB::table('rate')->where('piano_rate_id', $piano)->delete()],
    };
}

it('la somma nel database e quella di prima dicono la stessa cosa', function (string $variante) {
    $s = asdPiano($this);
    asdApplica($variante, $s);
    $piano = (int) $s['piano']->id;

    $oggi = asdCifre(new PianoRateQuoteService(), $piano);
    $prima = asdCifre(new AsdServizioDiPrima(), $piano);
    expect($oggi)->toBe($prima);
    if (ASD_VARIANTI[$variante] !== null) {
        expect($oggi['stato'])->toBe(ASD_VARIANTI[$variante]);
    }
})->with(array_keys(ASD_VARIANTI));

it('le varianti cambiano davvero la cifra: un centesimo è un centesimo, la quota in più è € 5,00, la rata zero e il pregresso restano fuori', function () {
    $cifra = function (string $variante) {
        $s = asdPiano($this);
        asdApplica($variante, $s);

        return asdCifre(new PianoRateQuoteService(), (int) $s['piano']->id);
    };
    $base = $cifra('come generato');

    expect($base['scarto'])->toBe(0)->and($base['puro'])->toBeGreaterThan(0)
        ->and($cifra('un centesimo')['puro'])->toBe($base['puro'] + 1)
        ->and($cifra('una quota in più')['puro'])->toBe($base['puro'] + 500)
        ->and($cifra('quota aperta')['scarto'])->toBe(500)
        ->and($cifra('senza rate')['puro'])->toBe(0);
});

it('la somma non carica le quote come modelli', function () {
    $s = asdPiano($this);
    $lette = 0;
    Event::listen('eloquent.retrieved: ' . RataQuote::class, function () use (&$lette) {
        $lette++;
    });

    $stato = app(PianoRateQuoteService::class)->statoAllineamento(PianoRate::findOrFail($s['piano']->id));
    app(PianoRateQuoteService::class)->totalePuroGeneratoCents(PianoRate::findOrFail($s['piano']->id));

    expect($stato)->toBe(PianoRateQuoteService::ALLINEATO)->and($lette)->toBe(0);
});

it('la dashboard con due gestioni: lo stesso elenco dei piani disallineati, con lo stesso scarto, e la stessa copertura di prima', function () {
    $s = asdPiano($this);
    $tabella = (int) Tabella::where('condominio_id', $s['c']->id)->value('id');
    $g2 = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Lavori 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g2->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g2->id, 'nome' => 'PC lavori']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Facciata', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 60000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $lavori = PianoRate::create(['gestione_id' => $g2->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-02-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($lavori, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    asdVoceNelPiano($lavori, 'Facciata', 60000);
    // Il piano dei lavori scostato di un centesimo; quello ordinario di € 5,00 in una quota.
    asdSposta((int) DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $lavori->id)->pluck('id'))->orderBy('id')->value('id'), 1, false);
    asdSposta((int) asdQuote($s, 3)->first()->id, 500);
    $utente = smaUtente();
    $props = fn () => $this->actingAs($utente)->get(route('admin.gestionale.index', $s['c']))->assertOk()->viewData('page')['props'];

    app()->instance(PianoRateQuoteService::class, new AsdServizioDiPrima());
    $prima = $props();
    app()->instance(PianoRateQuoteService::class, new PianoRateQuoteService());
    $oggi = $props();

    expect(collect($oggi['pianiDisallineati'])->sortBy('id')->values()->all())->toBe(collect($prima['pianiDisallineati'])->sortBy('id')->values()->all())
        ->and(collect($oggi['pianiDisallineati'])->pluck('delta', 'nome')->sortKeys()->all())->toBe(['Facciata 2026' => -1, 'Preventivo 2026' => -500])
        ->and($oggi['copertura'])->toBe($prima['copertura']);
});
