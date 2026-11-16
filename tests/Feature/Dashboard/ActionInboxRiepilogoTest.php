<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'Action inbox: le cinque card, i due grafici, i gruppi dell'elenco e i filtri leggono la stessa regola di «scaduto», scritta in un posto
 * (`FasceDeiCompiti`): il giorno del compito confrontato con il giorno dell'amministratore, non con l'ora del server. Un compito di oggi non è scaduto: è
 * nei prossimi 7 giorni. I bonifici segnalati sono un gruppo a sé: né scaduti né più avanti, e fuori dal grafico per età.
 *
 * I conteggi arrivano in una lettura aggregata che rispetta i compiti aperti (`Evento::scopeCompitiAperti()`) e il condominio scelto: un compito chiuso con
 * «Fatto» o «Non serve», o in attesa di «Ricordamelo», non conta da nessuna parte. Oggi è il 20/05/2026, alle 10:00 di Roma.
 */

use App\Models\Condominio;
use App\Models\Evento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Il giorno a `$d` giorni da oggi (20/05/2026), alle 09:00 come lo scrive l'agenda. */
function airGiorno(int $d): string
{
    return CarbonImmutable::parse('2026-05-20')->addDays($d)->format('Y-m-d') . ' 09:00:00';
}

/** Un compito del tipo dato, al giorno a `$d` giorni da oggi, nel condominio dato (di serie quello dello scenario). */
function airCompito($test, string $tipo, int $d, ?Condominio $condominio = null, array $meta = []): Evento
{
    $e = icsCompito($test, $tipo, $meta, "{$tipo} {$d}");
    Evento::whereKey($e->id)->update(['start_time' => airGiorno($d), 'end_time' => airGiorno($d)]);
    if ($condominio !== null) {
        $e->condomini()->sync([$condominio->id]);
    }

    return $e->fresh();
}

/** Le props della pagina, con i filtri dati. */
function airPagina($test, array $filtri = []): array
{
    return $test->actingAs($test->admin)->get(route('admin.inbox', $filtri))->assertOk()->viewData('page')['props'];
}

/** I titoli di tutte le righe dell'elenco, pagina dopo pagina. */
function airTutteLeRighe($test, array $filtri = []): array
{
    $righe = [];
    for ($p = 1; $p <= 20; $p++) {
        $props = airPagina($test, $filtri + ['page' => $p]);
        $dati = $props['tasks']['data'] ?? [];
        $righe = [...$righe, ...$dati];
        if (count($dati) < 15) {
            break;
        }
    }

    return $righe;
}

/** I dieci giorni ai bordi delle fasce, un bonifico segnalato di 40 giorni fa e una segnalazione di guasto aperta 267 giorni fa. */
function airBordi($test): void
{
    foreach ([-91, -90, -31, -30, -1, 0, 7, 8, 30, 31] as $d) {
        airCompito($test, 'controllo_incassi', $d);
    }
    airCompito($test, 'segnalazione_guasto', -267);
    [$bonifico] = icsBonifico($test);
    Evento::whereKey($bonifico->id)->update(['start_time' => airGiorno(-40), 'end_time' => airGiorno(-40)]);
}

it('la regola di «scaduto» è una: le card, il grafico per età, i gruppi dell\'elenco e i filtri contano gli stessi compiti', function () {
    airBordi($this);
    $p = airPagina($this);

    // Le card: scaduti i giorni prima di oggi; oggi è nei prossimi 7 giorni (fino al 27/05 compreso); il bonifico e la segnalazione sono a parte: la
    // segnalazione aperta 267 giorni fa non è scaduta (non ha una scadenza) e non è la più vecchia.
    expect($p['counts'])->toMatchArray(['all' => 12, 'scaduti' => 5, 'settimana' => 2, 'entro_30' => 2, 'bonifici' => 1, 'segnalazioni' => 1, 'piu_vecchio' => '2026-02-18']);
    // Il grafico: oltre 90 giorni fa, da 31 a 90, ultimi 30, prossimi 7, fra 8 e 30; fuori la segnalazione, il bonifico e il compito fra 31 giorni.
    expect(collect($p['grafici']['eta'])->map(fn ($b) => [$b['chiave'], $b['valore'], $b['scaduti']])->all())->toBe([
        ['oltre_90', 1, true], ['da_31_a_90', 2, true], ['ultimi_30', 2, true], ['prossimi_7', 2, false], ['fra_8_e_30', 2, false],
    ])->and($p['grafici']['fuori'])->toBe(['segnalazioni' => 1, 'bonifici' => 1, 'oltre_30' => 1]);
    // I gruppi dell'elenco, dal server, nel loro ordine; e le schede, che non contano la segnalazione e il bonifico fra gli scaduti né fra quelli in arrivo.
    expect($p['gruppi'])->toBe(['scaduti' => 5, 'segnalazioni' => 1, 'bonifici' => 1, 'avanti' => 5])
        ->and($p['schede'])->toBe(['tutti' => 12, 'scaduti' => 5, 'in_arrivo' => 5]);

    // Ogni riga dice la sua fascia e il suo gruppo, con la stessa regola; i giorni dal giorno dell'amministratore.
    $righe = collect(airTutteLeRighe($this))->keyBy('title');
    expect($righe['controllo_incassi -1']['gruppo'])->toBe('scaduti')
        ->and($righe['controllo_incassi -1']['giorni'])->toBe(-1)
        ->and($righe['controllo_incassi 0']['gruppo'])->toBe('avanti')
        ->and($righe['controllo_incassi 0']['fascia'])->toBe('prossimi_7')
        ->and($righe['controllo_incassi 0']['giorni'])->toBe(0)
        ->and($righe['controllo_incassi -91']['fascia'])->toBe('oltre_90')
        ->and($righe['controllo_incassi -90']['fascia'])->toBe('da_31_a_90')
        ->and($righe['controllo_incassi 31']['fascia'])->toBe('oltre_30')
        ->and($righe['Verifica incasso della rata 3 di Mario']['gruppo'])->toBe('bonifici')
        ->and($righe['segnalazione_guasto -267']['gruppo'])->toBe('segnalazioni')
        ->and($righe['segnalazione_guasto -267']['fascia'])->toBe('segnalazioni')
        ->and($righe['segnalazione_guasto -267']['giorni'])->toBe(-267);

    // I filtri danno le stesse righe che le card e le barre contano.
    $titoli = fn (array $f) => collect(airTutteLeRighe($this, $f))->pluck('title')->sort()->values()->all();
    expect($titoli(['quando' => 'scaduti']))->toBe(collect([-1, -30, -31, -90, -91])->map(fn ($d) => "controllo_incassi {$d}")->sort()->values()->all())
        ->and($titoli(['quando' => 'prossimi_7']))->toBe(['controllo_incassi 0', 'controllo_incassi 7'])
        ->and($titoli(['quando' => 'fra_8_e_30']))->toBe(['controllo_incassi 30', 'controllo_incassi 8'])
        ->and($titoli(['quando' => 'oltre_90']))->toBe(['controllo_incassi -91'])
        ->and($titoli(['quando' => 'da_31_a_90']))->toBe(['controllo_incassi -31', 'controllo_incassi -90'])
        ->and($titoli(['quando' => 'ultimi_30']))->toBe(['controllo_incassi -1', 'controllo_incassi -30']);
    // Con un filtro i gruppi contano le righe filtrate.
    expect(airPagina($this, ['quando' => 'prossimi_7'])['gruppi'])->toBe(['scaduti' => 0, 'segnalazioni' => 0, 'bonifici' => 0, 'avanti' => 2]);
});

it('il giorno è quello dell\'amministratore: alle 00:30 di Roma il compito di ieri è scaduto, anche se sul server è ancora ieri', function () {
    airCompito($this, 'emissione_rata', 0);
    expect(airPagina($this)['counts']['scaduti'])->toBe(0);

    // Il 21/05/2026 alle 00:30 di Roma è il 20/05 alle 22:30 sul server (UTC).
    $this->travelTo(Carbon::parse('2026-05-21 00:30', 'Europe/Rome')->utc());
    $p = airPagina($this);
    expect($p['counts']['scaduti'])->toBe(1)
        ->and($p['gruppi']['scaduti'])->toBe(1)
        ->and($p['tasks']['data'][0]['giorni'])->toBe(-1);
});

it('l\'elenco: prima gli scaduti, poi le segnalazioni aperte, poi i bonifici da verificare, poi gli altri; dentro ogni gruppo i più vecchi prima', function () {
    airCompito($this, 'emissione_rata', 5);
    airCompito($this, 'emissione_rata', -3);
    airCompito($this, 'pagamento_fornitore', -60);
    airCompito($this, 'controllo_incassi', 2);
    airCompito($this, 'segnalazione_guasto', -100);
    icsBonifico($this);

    expect(collect(airTutteLeRighe($this))->map(fn ($r) => [$r['gruppo'], $r['title']])->all())->toBe([
        ['scaduti', 'pagamento_fornitore -60'],
        ['scaduti', 'emissione_rata -3'],
        ['segnalazioni', 'segnalazione_guasto -100'],
        ['bonifici', 'Verifica incasso della rata 3 di Mario'],
        ['avanti', 'controllo_incassi 2'],
        ['avanti', 'emissione_rata 5'],
    ]);
});

it('i conteggi aggregati, con e senza il condominio: i compiti chiusi con «Fatto», con «Non serve» e in attesa di «Ricordamelo» non contano', function () {
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $fatto = airCompito($this, 'emissione_rata', -5);
    $nonServe = airCompito($this, 'controllo_incassi', -4);
    $ricordamelo = airCompito($this, 'pagamento_fornitore', -3);
    airCompito($this, 'scadenza', -2);
    airCompito($this, 'versamento_ritenuta', 3);
    airCompito($this, 'emissione_rata', -10, $altro);
    airCompito($this, 'segnalazione_guasto', 1, $altro);

    icsRispondi($this, $fatto, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    icsRispondi($this, $nonServe, ['tipo' => 'non_serve', 'motivo' => 'già fatto in banca'])->assertSessionHasNoErrors();
    icsRispondi($this, $ricordamelo, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-15'])->assertSessionHasNoErrors();

    $tutti = airPagina($this);
    // La segnalazione fra un giorno non è nei prossimi 7 giorni: non ha una scadenza.
    expect($tutti['counts'])->toMatchArray(['all' => 4, 'scaduti' => 2, 'settimana' => 1, 'bonifici' => 0, 'segnalazioni' => 1])
        ->and(collect($tutti['grafici']['tipi'])->mapWithKeys(fn ($t) => [$t['tipo'] => $t['valore']])->all())
            // I tipi con più compiti prima, a pari numero per nome: «Emissione rate», «Ritenute da versare», «Scadenze», «Segnalazioni».
            ->toBe(['emissione_rata' => 1, 'versamento_ritenuta' => 1, 'scadenza' => 1, 'segnalazione_guasto' => 1])
        ->and(collect($tutti['dove'])->map(fn ($d) => [$d['nome'], $d['valore']])->all())->toBe([['Condominio Aurora', 2], ['Corso Italia 3', 2]]);

    $aurora = airPagina($this, ['condominio_id' => $this->condominio->id]);
    expect($aurora['counts'])->toMatchArray(['all' => 2, 'scaduti' => 1, 'settimana' => 1, 'bonifici' => 0, 'segnalazioni' => 0])
        ->and(collect($aurora['grafici']['eta'])->sum('valore'))->toBe(2)
        ->and(collect($aurora['grafici']['tipi'])->pluck('tipo')->sort()->values()->all())->toBe(['scadenza', 'versamento_ritenuta'])
        ->and(collect($aurora['dove'])->map(fn ($d) => [$d['nome'], $d['valore']])->all())->toBe([['Condominio Aurora', 2]])
        ->and(collect(airTutteLeRighe($this, ['condominio_id' => $this->condominio->id]))->pluck('title')->all())->toBe(['scadenza -2', 'versamento_ritenuta 3']);
    // Il numero accanto al titolo è quello dei compiti aperti del condominio scelto.
    expect($aurora['counts']['all'])->toBe(2);
});

it('i filtri si sommano: per età, per tipo e per condominio; un valore che non c\'è non filtra', function () {
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    airCompito($this, 'emissione_rata', -100);
    airCompito($this, 'emissione_rata', -10);
    airCompito($this, 'controllo_incassi', -10);
    airCompito($this, 'emissione_rata', -10, $altro);
    airCompito($this, 'emissione_rata', 3, $altro);

    $titoli = fn (array $f) => collect(airTutteLeRighe($this, $f))->pluck('title')->sort()->values()->all();
    expect($titoli(['tipo' => 'emissione_rata']))->toBe(['emissione_rata -10', 'emissione_rata -10', 'emissione_rata -100', 'emissione_rata 3'])
        ->and($titoli(['tipo' => 'emissione_rata', 'quando' => 'ultimi_30']))->toBe(['emissione_rata -10', 'emissione_rata -10'])
        ->and($titoli(['tipo' => 'emissione_rata', 'quando' => 'ultimi_30', 'condominio_id' => $altro->id]))->toBe(['emissione_rata -10'])
        ->and($titoli(['quando' => 'non_esiste', 'tipo' => 'ma il tipo?']))->toHaveCount(5);
    $p = airPagina($this, ['tipo' => 'emissione_rata', 'quando' => 'scaduti']);
    expect($p['filtri'])->toBe(['quando' => 'scaduti', 'tipo' => 'emissione_rata', 'cerca' => null, 'ordine' => 'vecchi', 'condominio_id' => null, 'scheda' => 'scaduti',
        'tipo_nome' => 'Emissione rate'])
        ->and($p['gruppi'])->toBe(['scaduti' => 3, 'segnalazioni' => 0, 'bonifici' => 0, 'avanti' => 0])
        // Le card e i grafici restano quelli di tutti i compiti (del condominio scelto): un filtro cambia l'elenco, non il quadro.
        ->and($p['counts']['all'])->toBe(5);
});

it('«Dove si accumulano»: i cinque condomini con più compiti aperti, poi il nome; i compiti senza condominio sono «Generale»', function () {
    $nomi = ['Via Alfieri', 'Via Bellini', 'Via Carducci', 'Via Dante', 'Via Ennio', 'Via Foscolo'];
    foreach ($nomi as $i => $nome) {
        $c = Condominio::factory()->create(['nome' => $nome]);
        foreach (range(0, $i) as $n) {
            airCompito($this, 'emissione_rata', -$n - 1, $c);
        }
    }
    $senza = airCompito($this, 'scadenza', -1);
    $senza->condomini()->detach();

    expect(collect(airPagina($this)['dove'])->map(fn ($d) => [$d['nome'], $d['valore'], $d['condominio_id'] === null])->all())->toBe([
        ['Via Foscolo', 6, false], ['Via Ennio', 5, false], ['Via Dante', 4, false], ['Via Carducci', 3, false], ['Via Bellini', 2, false],
    ]);
    foreach (range(1, 6) as $n) {
        airCompito($this, 'scadenza', -$n - 1)->condomini()->detach();
    }
    expect(collect(airPagina($this)['dove'])->map(fn ($d) => [$d['nome'], $d['valore'], $d['condominio_id']])->first())->toBe(['Generale', 7, null]);
    $righe = collect(airTutteLeRighe($this, ['quando' => 'ultimi_30']))->keyBy('title');
    expect($righe['scadenza -1']['condominio'])->toBe('Generale')
        ->and($righe['scadenza -1']['condominio_id'])->toBeNull();
});

it('il numero nel menu: i compiti con il giorno arrivato (oggi compreso), i bonifici segnalati e le segnalazioni; dal giorno dell\'amministratore', function () {
    airCompito($this, 'emissione_rata', -2);
    airCompito($this, 'controllo_incassi', 0);
    airCompito($this, 'pagamento_fornitore', 1);
    airCompito($this, 'segnalazione_guasto', 9);
    icsBonifico($this);
    \Illuminate\Support\Facades\Cache::flush();

    expect(icsBarra($this))->toBe(4);

    \Illuminate\Support\Facades\Cache::flush();
    $this->travelTo(Carbon::parse('2026-05-21 00:30', 'Europe/Rome')->utc());
    expect(icsBarra($this))->toBe(5);
});
