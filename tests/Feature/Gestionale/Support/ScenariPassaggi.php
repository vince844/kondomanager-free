<?php

/**
 * Gli scenari dei passaggi di titolarità (1.11.0-beta.38), condivisi da `RiservaUsufruttoTest` e `InvariantiPassaggiTest`.
 * Li carica ciascuno con `require_once`, così ogni file passa anche lanciato da solo. Non è un test: il nome non finisce
 * in `Test.php` e PHPUnit non lo raccoglie.
 *
 * - Da `ruScenario` a `ruStraordinariaDopoUnaVendita`: gli helper di `RiservaUsufruttoTest`, spostati qui così com'erano
 *   (stesso codice, stesso comportamento).
 * - Da `ruEmettiBozze` in fondo: gli helper della griglia degli invarianti — l'emissione che segue il tempo, le due
 *   fotografie (esatta e normalizzata), l'annullamento, il modulo di un passaggio, il costruttore del caso (`ruCaso`) e
 *   il generatore a coppie (`ruGriglia`).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Subentro\StoricoTitolarita;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../GestionaleTestHelpers.php';

/**
 * Lo stesso condominio di `RiassegnazioneBozzeTest` (rbScenario): un'unità, € 1.200,00 in dodici rate mensili il 5 del
 * mese, generate a gennaio con il solo venditore; con `$natura = 'straordinaria'` una spesa straordinaria deliberata il
 * giorno dato, da una fattura vera collegata al piano. `$soggetto` è il ruolo su cui ricade la voce; con `$genera` falso il
 * piano resta da generare, per i piani generati **dopo** i passaggi (rilievo B4 della Fase 1-bis).
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, v: Anagrafica, a: Anagrafica, rigaV: int, piano: PianoRate}
 */
function ruScenario(string $metodo, int $saldoVenditore, string $natura = 'ordinaria', ?string $delibera = null, string $primaScadenza = '2026-01-05', int $numeroRate = 12, string $soggetto = 'proprietario', bool $genera = true): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => $natura === 'ordinaria' ? 'Ordinaria 2026' : 'Facciata', 'tipo' => $natura, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $natura === 'ordinaria' ? 'Spese generali' : 'Rifacimento facciata', 'tipo' => 'spesa', 'natura_spesa' => $natura, 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "RU-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $v = Anagrafica::forceCreate(['nome' => 'Venditore Ugo', 'email' => "ru-v{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUVENDITORE' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $a = Anagrafica::forceCreate(['nome' => 'Acquirente Elsa', 'email' => "ru-a{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUACQUIRENT' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
    $v->condomini()->syncWithoutDetaching([$c->id]);
    $a->condomini()->syncWithoutDetaching([$c->id]);
    $rigaV = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    if ($saldoVenditore !== 0) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $v->id, 'immobile_id' => $unita->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldoVenditore, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => $natura === 'ordinaria' ? 'Preventivo 2026' : 'Rifacimento facciata', 'stato' => 'approvato',
        'tipo' => $natura === 'ordinaria' ? 'ordinario' : 'straordinario', 'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => $primaScadenza,
        'metodo_distribuzione' => $metodo, 'applica_saldi' => true, 'data_delibera_assemblea' => $delibera,
    ]);
    if ($natura !== 'ordinaria') {
        $fornitoreId = DB::table('fornitori')->insertGetId([
            'ragione_sociale' => 'Impresa Facciate Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
            'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fattura = FatturaPassiva::create([
            'condominio_id' => $c->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $e->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-RU{$seq}",
            'data_documento' => $delibera, 'data_scadenza' => $delibera, 'is_pregresso' => false, 'importo_imponibile' => 120000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
            'totale_documento' => 120000, 'netto_a_pagare' => 120000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        ]);
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Rifacimento facciata', 'aliquota_iva' => 0,
            'importo_imponibile' => 120000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $piano->fatture()->attach($fattura->id, ['importo_collegato' => 120000]);
    }
    if ($genera) {
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'v', 'a', 'rigaV', 'piano');
}

/**
 * Decisione 31.9 (1.11.0-beta.41): il piano dello scenario è un piano «globale» (senza capitoli) approvato, e blocca lo
 * spostamento delle voci della gestione come un piano con i capitoli (31.8). Le prove dello spostamento lo riportano in
 * bozza: è il caso in cui le voci si spostano davvero, il passaggio prima che il preventivo sia approvato.
 */
function ruPianoInBozza(array $s): void
{
    $s['piano']->update(['stato' => 'bozza']);
}

/** Emette a giornale le rate con scadenza fino al giorno dato (di norma le prime quattro). */
function ruEmetti(array $s, string $fino = '2026-04-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-RU-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/** Il modulo della vendita con la riserva dichiarata. */
function ruRiserva(array $s, string $decorrenza = '2026-05-01', array $extra = []): array
{
    return array_merge([
        'tipo' => 'vendita', 'sottotipo' => 'riserva_usufrutto', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'nuda_proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06',
        'estremi_titolo' => 'atto notaio Verdi, rep. 777', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto: vendita della nuda proprietà con riserva d\'usufrutto',
        // La scelta sull'ordinaria è obbligatoria (rilievo A3 della beta.41): la proposta di legge, come nel modulo.
        'ordinaria_dopo_atto' => 'usufruttuario',
    ], $extra);
}

/** Il preventivo del piano per persona, dalle regole congelate di ogni quota. */
function ruQuote(array $s): array
{
    $righe = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo', 'rate.stato']);
    $importi = fn ($r) => json_decode((string) $r->regole_calcolo, true)['importi'] ?? [];
    $preventivo = fn (int $id) => (int) $righe->where('anagrafica_id', $id)->sum(fn ($r) => (int) ($importi($r)['quota_pura_gestione'] ?? 0));
    $pregresso = fn (int $id) => (int) $righe->where('anagrafica_id', $id)->sum(fn ($r) => (int) ($importi($r)['saldo_usato'] ?? 0));

    return ['v_preventivo' => $preventivo($s['v']->id), 'v_pregresso' => $pregresso($s['v']->id), 'a_preventivo' => $preventivo($s['a']->id), 'a_pregresso' => $pregresso($s['a']->id)];
}

/** La coppia del conguaglio: [debito di chi compra, credito di chi vende]. */
function ruCoppia(array $s): array
{
    $coppia = Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get();

    return [(int) $coppia->where('anagrafica_id', $s['a']->id)->sum('saldo_iniziale'), (int) $coppia->where('anagrafica_id', $s['v']->id)->sum('saldo_iniziale')];
}

function ruRegistra($test, array $s, array $dati): Subentro
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

function ruAnteprima($test, array $s, array $dati): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertOk()->json();
}

/** Le righe di titolarità dell'unità, nell'ordine in cui sono nate. */
function ruRighe(int $immobileId): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $immobileId)->orderBy('id')
        ->get(['anagrafica_id', 'tipologia', 'quota', 'data_inizio', 'data_fine'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, $r->tipologia, (float) $r->quota, substr((string) $r->data_inizio, 0, 10), $r->data_fine ? substr((string) $r->data_fine, 0, 10) : null])
        ->all();
}

/**
 * I due genitori proprietari al 50 % dal 2019: Ugo (il padre, `v`) e Rita (la madre); Elsa (`a`) è il figlio che riceve,
 * da ciascuno, la nuda proprietà della sua metà. Il piano generato a gennaio non conta qui: si guardano le righe.
 *
 * @return array{0: array, 1: Anagrafica, 2: int} lo scenario, la madre e la sua riga
 */
function ruDueGenitori(): array
{
    $s = ruScenario('prima_rata', 0);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $madre = Anagrafica::forceCreate(['nome' => 'Madre Rita', 'email' => "ru-m{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUMADRERITA' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $madre->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaM = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $madre->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return [$s, $madre, $rigaM];
}

/**
 * Ugo, proprietario dal 2019, vende a Zeta il 1/3/2026 (vendita piena); il 1/5/2026 Zeta vende a Elsa la nuda proprietà
 * con riserva d'usufrutto. La voce ordinaria da € 1.200,00 è sul ruolo «Inquilino»: senza inquilino cerca l'usufruttuario,
 * poi il proprietario (la guida «Ruoli e usufrutto», decisione 28.5). Il piano 2026 si genera **dopo** i due passaggi,
 * dalle rotte vere, con la presa d'atto del cancello (2).
 *
 * @return array{0: array, 1: Anagrafica} lo scenario e Zeta
 */
function ruDopoUnaVendita($test): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "ru-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUZETAVENDI' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    ruRegistra($test, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);
    $rigaZ = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');
    ruRegistra($test, $s, ruRiserva(['rigaV' => $rigaZ] + $s));

    return [$s, $zeta];
}

/**
 * Gli avvisi dell'annullamento senza quello sulle voci spostate all'«Usufruttuario» (decisione 31.7, 1.11.0-beta.41): con la
 * legge proposta la riserva e la costituzione spostano le voci, e l'annullamento lo dice sempre. I test che guardano altro
 * lo tolgono; quelli della decisione 31.7 lo leggono.
 */
function ruAvvisiSenzaVoci(array $avvisi): array
{
    return array_values(array_filter($avvisi, fn (string $a) => ! str_contains($a, 'all\'«Usufruttuario»')));
}

/** Il piano per persona, in centesimi: la somma delle quote di ciascuno. */
function ruPerPersona(PianoRate $piano): array
{
    $totali = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->get(['rate_quote.anagrafica_id', 'rate_quote.importo'])
        ->groupBy('anagrafica_id')->map(fn ($q) => (int) $q->sum('importo'))->all();
    ksort($totali);

    return $totali;
}

/** Le righe di riparto del piano: chi, con quale ruolo, quanto e su quale tratto. */
function ruRiparto(PianoRate $piano): array
{
    return DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'riparto')->orderBy('anagrafica_id')->orderBy('id')
        ->get(['anagrafica_id', 'ruolo_risolto', 'importo', 'titolarita_dal', 'titolarita_al'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, $r->ruolo_risolto, (int) $r->importo, $r->titolarita_dal ? substr((string) $r->titolarita_dal, 0, 10) : null, $r->titolarita_al ? substr((string) $r->titolarita_al, 0, 10) : null])
        ->all();
}

/** La pagina «Modifica» e l'elenco dei titolari: la riga è agganciata a un passaggio? */
function ruAgganciata($test, array $s, int $rigaId): array
{
    $edit = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.anagrafiche.edit', [$s['c'], $s['unita'], $rigaId]))->assertOk()
        ->viewData('page')['props']['agganciata_a_passaggio'];
    $lista = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['unita']]))->assertOk()->viewData('page')['props'];
    $elenco = collect($lista['immobile']['anagrafiche'] ?? $lista['anagrafiche'] ?? [])->firstWhere('pivot.id', $rigaId)['pivot']['agganciata_a_passaggio'] ?? null;

    return ['edit' => $edit, 'elenco' => $elenco];
}

/**
 * Il «Consuntivo 2025» da € 300,00 in una rata, con la gestione e l'esercizio 2025 veri e la voce sul «Proprietario»,
 * generato quando lo si chiama — di norma dopo il passaggio, come quando il consuntivo si approva dopo il rogito.
 */
function ruConsuntivo2025(array $s): PianoRate
{
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC 2025']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali 2025', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 30000]);
    $tabella = Tabella::where('condominio_id', $s['c']->id)->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'nome' => 'Consuntivo 2025', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-07-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: false, accettaDestinatari: true, notaDestinatari: 'Letto: consuntivo dell\'anno prima', esercizio: $e);

    return $piano;
}

/** Le competenze del riparto di un piano: [dal, al] distinti. */
function ruCompetenze(PianoRate $piano): array
{
    return DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->get(['competenza_dal', 'competenza_al'])
        ->map(fn ($r) => [substr((string) $r->competenza_dal, 0, 10), substr((string) $r->competenza_al, 0, 10)])->unique()->values()->all();
}

/**
 * Elsa (`a`), che ha comprato la nuda proprietà con la riserva, vende a Carlo dalla sua riga aperta con quel ruolo: la
 * sola nuda proprietà, o — dopo l'estinzione dell'usufrutto — la piena (`$tipologia = 'proprietario'`).
 *
 * @return array{0: Anagrafica, 1: array} Carlo e il modulo della vendita
 */
function ruRivendita(array $s, string $decorrenza = '2026-09-01', string $tipologia = 'nuda_proprietario'): array
{
    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => "ru-c{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUCOMPRATOR' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', $tipologia)->whereNull('data_fine')->value('id');

    return [$carlo, [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => $decorrenza, 'quota' => 100, 'tipologia' => $tipologia,
        'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Bianchi, rep. 888', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Rogito letto: Elsa vende a Carlo',
    ]];
}

/** L'usufrutto di chi ha venduto con riserva si estingue (morte dell'usufruttuario): chi ha la nuda proprietà torna pieno. */
function ruEstinzione($test, array $s, string $decorrenza = '2026-09-01'): Subentro
{
    $rigaUsufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');

    return ruRegistra($test, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsufrutto, 'decorrenza' => $decorrenza,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta',
    ]);
}

/** Il ricalcolo come lo fa `PianoRateGenerationController`: le rate si cancellano e il piano si rigenera, con la presa d'atto. */
function ruRicalcola(array $s): void
{
    $s['piano']->rate()->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: ricalcolo dopo la riserva', esercizio: $s['e']);
}

/**
 * Ugo e Bice proprietari al 50 % dal 2019; Ugo vende con riserva la nuda proprietà della sua metà a Elsa dal 1/5/2026, e il
 * piano 2026 si genera **dopo**, con la presa d'atto del cancello (2).
 *
 * @return array{0: array, 1: Anagrafica} lo scenario e Bice
 */
function ruMista($test, string $natura, string $soggetto, array $extra = []): array
{
    $s = $natura === 'ordinaria'
        ? ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false)
        : ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, soggetto: $soggetto, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $bice = Anagrafica::forceCreate(['nome' => 'Comproprietaria Bice', 'email' => "ru-bi{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUBICECOMPR' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $bice->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $bice->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    // La riserva prima dell'approvazione del preventivo: con la legge la voce si sposta (decisione 31.9).
    ruPianoInBozza($s);
    ruRegistra($test, $s, ruRiserva($s, extra: ['quota' => 50] + $extra));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: riserva su metà', esercizio: $s['e']);

    return [$s, $bice];
}

/**
 * Ugo vende a Zeta il 1/3/2026 (vendita piena) quando il piano straordinario, generato a gennaio con Ugo, non ha ancora
 * emesso nulla: il piano resta intestato a Ugo, e le rate di marzo e aprile si emettono dopo. Il modulo del secondo
 * passaggio parte dalla riga di Zeta.
 *
 * @return array{0: array, 1: Anagrafica, 2: int} lo scenario, Zeta e la sua riga
 */
function ruStraordinariaDopoUnaVendita($test, string $delibera, bool $senzaRighe = false): array
{
    $s = ruScenario('prima_rata', 0, 'straordinaria', $delibera, '2026-03-05', 6);
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "ru-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUZETAVENDI' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Dalla 1.11.0-beta.42 (decisione 35) il piano si emette prima della vendita: emesso dopo, senza ricalcolo, le quote di Ugo non
    // passerebbero a Zeta e il conguaglio dopo si fermerebbe (era la forma di U1, che questi scenari fissavano come attesa).
    ruEmetti($s);
    ruRegistra($test, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);

    return [$s, $zeta, (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id')];
}

// --- La griglia degli invarianti (`InvariantiPassaggiTest`) ----------------------------------------------------------------

/**
 * Emette a giornale le rate **ancora in bozza** del piano con scadenza fino al giorno dato, con una scrittura sua. È
 * `ruEmetti` per le catene, dove l'emissione segue il tempo: prima di ogni passaggio si emette ciò che scade prima della
 * sua decorrenza, e le rate già emesse non si toccano.
 */
function ruEmettiBozze(array $s, PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    if ($rate->isEmpty()) {
        return;
    }
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $piano->gestione_id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-INV-' . $piano->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/**
 * I dati che un passaggio può toccare, senza lo storico: le righe di titolarità delle unità con il loro id, le quote
 * delle unità con le regole congelate, gli importi delle rate e i saldi del condominio. Per id, così un confronto dice
 * quale riga è cambiata.
 *
 * @param list<int> $unita
 */
function ruFotoDati(array $unita, int $condominioId): array
{
    return [
        'righe' => DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita)->orderBy('id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id => ['immobile_id' => (int) $r->immobile_id, 'anagrafica_id' => (int) $r->anagrafica_id, 'tipologia' => (string) $r->tipologia,
                'quota' => round((float) $r->quota, 2), 'data_inizio' => $r->data_inizio === null ? null : substr((string) $r->data_inizio, 0, 10),
                'data_fine' => $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10), 'attivo' => (bool) $r->attivo]])->all(),
        'quote' => DB::table('rate_quote')->whereIn('immobile_id', $unita)->orderBy('id')
            ->get(['id', 'rata_id', 'anagrafica_id', 'immobile_id', 'importo', 'importo_pagato', 'stato', 'tipo', 'regole_calcolo', 'scrittura_contabile_id'])
            ->mapWithKeys(fn ($q) => [(int) $q->id => ['rata_id' => (int) $q->rata_id, 'anagrafica_id' => (int) $q->anagrafica_id, 'immobile_id' => (int) $q->immobile_id, 'importo' => (int) $q->importo,
                'importo_pagato' => (int) $q->importo_pagato, 'stato' => (string) $q->stato, 'tipo' => $q->tipo, 'regole_calcolo' => $q->regole_calcolo,
                'scrittura_contabile_id' => $q->scrittura_contabile_id === null ? null : (int) $q->scrittura_contabile_id]])->all(),
        'rate' => DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')->where('piani_rate.condominio_id', $condominioId)->orderBy('rate.id')
            ->get(['rate.id', 'rate.piano_rate_id', 'rate.importo_totale', 'rate.stato'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => ['piano_rate_id' => (int) $r->piano_rate_id, 'importo_totale' => (int) $r->importo_totale, 'stato' => (string) $r->stato]])->all(),
        'saldi' => DB::table('saldi')->where('condominio_id', $condominioId)->orderBy('id')
            ->get(['id', 'anagrafica_id', 'immobile_id', 'gestione_id', 'esercizio_id', 'saldo_iniziale', 'subentro_id', 'is_applicato'])
            ->mapWithKeys(fn ($x) => [(int) $x->id => ['anagrafica_id' => $x->anagrafica_id === null ? null : (int) $x->anagrafica_id, 'immobile_id' => (int) $x->immobile_id,
                'gestione_id' => $x->gestione_id === null ? null : (int) $x->gestione_id, 'esercizio_id' => $x->esercizio_id === null ? null : (int) $x->esercizio_id,
                'saldo_iniziale' => (int) $x->saldo_iniziale, 'subentro_id' => $x->subentro_id === null ? null : (int) $x->subentro_id, 'is_applicato' => (bool) $x->is_applicato]])->all(),
    ];
}

/**
 * La fotografia di ciò che un passaggio può toccare, per confrontarla prima e dopo (invarianti I1, I3 e I4): i dati di
 * `ruFotoDati` e lo storico di ogni unità — le righe, i passaggi non annullati con `annullabile`, il numero dei passaggi.
 * Fuori, per scelta: `updated_at`, i promemoria in agenda, l'allegato e `anagrafica_condominio`, che l'annullamento non
 * tocca (anteriore, beta.37: chi è entrato resta agganciato al condominio anche dopo l'annullamento).
 *
 * @param list<int> $unita
 */
function ruFoto(array $unita, int $condominioId): array
{
    $storico = [];
    foreach ($unita as $id) {
        $st = app(StoricoTitolarita::class)->perImmobile(Immobile::findOrFail($id));
        $storico[$id] = ['passaggi' => $st['passaggi'], 'righe' => $st['righe'], 'subentri' => array_values(array_filter($st['subentri'], fn (array $p) => ! $p['annullato']))];
    }

    return ruFotoDati($unita, $condominioId) + ['storico' => $storico];
}

/**
 * La fotografia senza ciò che cambia a ogni registrazione anche quando il passaggio è lo stesso — la riserva annullata e
 * registrata di nuovo: gli id delle righe, delle quote e dei saldi, gli id dei passaggi (`subentro_id`, dentro le regole
 * congelate e sui saldi) e l'ora (`il`). Le righe restano in ordine di nascita (l'id dice solo chi viene prima), le quote
 * e i saldi si ordinano per contenuto; dei saldi resta se sono di un passaggio. Lo storico non c'è: è fatto di id.
 *
 * @param list<int> $unita
 */
function ruFotoNormalizzata(array $unita, int $condominioId): array
{
    $dati = ruFotoDati($unita, $condominioId);
    $pulisci = function (mixed $x) use (&$pulisci): mixed {
        if (! is_array($x)) {
            return $x;
        }
        unset($x['subentro_id'], $x['il']);
        ksort($x);

        return array_map($pulisci, $x);
    };
    $ordina = function (array $righe): array {
        $righe = array_values($righe);
        usort($righe, fn ($a, $b) => json_encode($a) <=> json_encode($b));

        return $righe;
    };

    return [
        'righe' => array_values($dati['righe']),
        'quote' => $ordina(array_map(fn (array $q) => ['regole_calcolo' => $pulisci(is_string($q['regole_calcolo']) ? json_decode($q['regole_calcolo'], true) : $q['regole_calcolo'])] + $q, $dati['quote'])),
        'rate' => $dati['rate'],
        'saldi' => $ordina(array_map(fn (array $x) => ['del_passaggio' => $x['subentro_id'] !== null] + array_diff_key($x, ['subentro_id' => true]), $dati['saldi'])),
    ];
}

/** L'annullamento dalla rotta, come nello storico (`deleteJson`: 302 se annulla, 422 se si ferma). */
function ruAnnulla($test, array $s, Subentro $subentro, string $nota = 'Registrato per sbaglio: lo annullo', ?Immobile $unita = null)
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $unita ?? $s['unita'], $subentro]), ['nota_annullamento' => $nota]);
}

/** Il ruolo di chi esce, per tipo di passaggio della griglia (lo stesso di `AnteprimaPassaggioRequest::ruoliUscente`). */
function ruRuoloUscente(string $tipo): string
{
    return match ($tipo) {
        'nuda' => 'nuda_proprietario',
        'estinzione' => 'usufruttuario',
        default => 'proprietario',
    };
}

/**
 * Il modulo di un passaggio della griglia, per tipo: `vendita` (piena), `riserva` (vendita o donazione con riserva
 * d'usufrutto), `nuda` (vendita della sola nuda proprietà), `costituzione` ed `estinzione` dell'usufrutto. `$riga` è la
 * riga di chi esce e `$quota` la sua. La copia autentica non si dichiara; spunta e nota del cancello si aggiungono fuori.
 */
function ruPassaggio(string $tipo, int $riga, ?Anagrafica $entrante, string $dal, float $quota, array $pertinenze = []): array
{
    $base = ['riga_uscente_id' => $riga, 'decorrenza' => $dal, 'quota' => $quota, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => $pertinenze]
        + ($entrante !== null ? ['anagrafica_entrante_id' => $entrante->id] : []);

    return $base + match ($tipo) {
        'vendita' => ['tipo' => 'vendita', 'tipologia' => 'proprietario'],
        'riserva' => ['tipo' => 'vendita', 'sottotipo' => Subentro::RISERVA_USUFRUTTO, 'tipologia' => 'nuda_proprietario', 'ordinaria_dopo_atto' => 'usufruttuario'],
        'nuda' => ['tipo' => 'vendita', 'tipologia' => 'nuda_proprietario'],
        'costituzione' => ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario', 'ordinaria_dopo_atto' => 'usufruttuario'],
        'estinzione' => ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'tipologia' => 'proprietario'],
    };
}

/**
 * Un piano straordinario in più sul condominio di `ruScenario`, per l'ordinaria e la straordinaria insieme sulla stessa
 * unità (il caso reale più comune): la gestione «Facciata», una fattura vera da € 1.200,00 collegata al piano, sei rate
 * da aprile, la stessa tabella. Non generato.
 */
function ruAggiungiStraordinaria(array $s, string $delibera, string $soggetto = 'proprietario'): PianoRate
{
    $c = $s['c'];
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC facciata']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Rifacimento facciata', 'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 120000]);
    $tabella = Tabella::where('condominio_id', $c->id)->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Impresa Facciate Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fattura = FatturaPassiva::create([
        'condominio_id' => $c->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-INV{$c->id}",
        'data_documento' => $delibera, 'data_scadenza' => $delibera, 'is_pregresso' => false, 'importo_imponibile' => 120000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 120000, 'netto_a_pagare' => 120000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Rifacimento facciata', 'aliquota_iva' => 0,
        'importo_imponibile' => 120000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rifacimento facciata', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 6, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-04-05',
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true, 'data_delibera_assemblea' => $delibera,
    ]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 120000]);

    return $piano;
}

/**
 * Costruisce un caso della griglia da una ricetta e da una forma (`invForme()` in `InvariantiPassaggiTest`), fino al
 * modulo del passaggio sotto esame, che non registra.
 *
 * La ricetta ha le dimensioni di `invDimensioni()`: F forma, N natura e date, E emissione, S saldi, V ruolo della voce,
 * D giorno dell'atto, P pertinenza, K rinuncia al conguaglio, G generazione del piano (più `E = NP`, nessun piano generato,
 * per la griglia del risolutore, e `prima_scadenza`, la prima rata della straordinaria, per le catene della griglia B:
 * di norma il 5 aprile). La forma ha i titolari censiti dal 2019 (`titolari`, il primo è sempre Ugo
 * proprietario: la sua riga è quella di `ruScenario`) e i passaggi (`passaggi`, l'ultimo è quello sotto esame), ciascuno
 * come [tipo, chi esce, chi entra, quando]. «Quando» è una data, `'esame'` (il giorno del passaggio sotto esame) o un
 * mese (`'05'`): il primo del mese o, con D=5, il 5 — il giorno di una scadenza.
 *
 * L'ordine è quello vero: il condominio, i titolari (anche sul box con P=box, che ha la sua quota nella tabella e quindi
 * nel piano), la generazione a gennaio (G=prima), i passaggi che precedono, la generazione dopo di loro (G=dopo, con la
 * presa d'atto del cancello 2), l'emissione. Con E4 e SR l'emissione segue il tempo: prima di ogni passaggio si emette ciò
 * che scade prima della sua decorrenza; con `tempo = inizio` si emette una volta sola, prima del primo passaggio (la
 * griglia B: un'emissione dopo un passaggio lo rende non annullabile, per la regola delle rate intatte). `$primaDi($caso,
 * $i)`, se c'è, si chiama prima di registrare l'i-esimo passaggio che precede (la griglia B ci fotografa).
 *
 * @return array{s: array, piani: list<PianoRate>, persone: array<string, Anagrafica>, box: ?Immobile, unita: list<int>, esame: array, passaggi: list<Subentro>, d: string, delibere: array<int, ?string>, competenza: ?array, versato: array}
 */
function ruCaso($test, array $r, array $forma, ?callable $primaDi = null): array
{
    $passi = $forma['passaggi'];
    $ultimo = $passi[array_key_last($passi)];
    $giorno = ($r['D'] ?? '1') === '5' ? '05' : '01';
    $quando = fn (string $q, string $esame = '') => $q === 'esame' ? $esame : (strlen($q) === 2 ? "2026-{$q}-{$giorno}" : $q);
    $d = $quando($ultimo[3]);
    $dd = CarbonImmutable::parse($d);
    $N = $r['N'] ?? 'O';
    $E = $r['E'] ?? 'E4';
    $G = $r['G'] ?? 'prima';

    // La natura: il primo piano, ordinario o straordinario, e con OS un secondo piano straordinario sulla stessa unità. Le
    // date sono dette rispetto al passaggio sotto esame: SP 47 giorni prima, SG il giorno stesso, SD, SN e OS 19 giorni dopo,
    // SC una competenza dichiarata sulla fattura da due mesi prima a due mesi dopo.
    $straordinaria = ! in_array($N, ['O', 'OS'], true);
    $delibera = match ($N) {
        'SP', 'SC' => $dd->subDays(47)->toDateString(),
        'SG' => $d,
        'SD', 'SN', 'OS' => $dd->addDays(19)->toDateString(),
        default => null,
    };
    $competenza = $N === 'SC' ? [$dd->subMonths(2)->toDateString(), $dd->addMonths(2)->subDay()->toDateString()] : null;
    [$metodo, $saldo] = match ($r['S'] ?? '0') {
        'T+' => ['tutte_rate', 18000],
        'T-' => ['tutte_rate', -6000],
        'Z+' => ['rata_zero', 18000],
        'UN' => ['tutte_rate', 0],
        default => ['prima_rata', 0],
    };
    // I saldi della persona (T+, T−, Z+, VE) sono di chi esce nel passaggio sotto esame se è fra i titolari censiti — Ugo,
    // o Rita nelle seconde riserve di S1 e S2 —, altrimenti di Ugo: chi esce è entrato con un passaggio precedente (VR, RV) e
    // un pregresso suo, nel piano di gennaio, non c'è.
    $chiSaldo = in_array($ultimo[1], array_column($forma['titolari'], 0), true) ? $ultimo[1] : 'v';
    $soggetto = ($r['V'] ?? 'P') === 'U' ? 'usufruttuario' : 'proprietario';
    $s = $straordinaria
        ? ruScenario($metodo, $chiSaldo === 'v' ? $saldo : 0, 'straordinaria', $delibera, $r['prima_scadenza'] ?? '2026-04-05', 6, $soggetto, genera: false)
        : ruScenario($metodo, $chiSaldo === 'v' ? $saldo : 0, 'ordinaria', null, '2026-01-05', 12, $soggetto, genera: false);
    $piani = [$s['piano']];
    if ($N === 'OS') {
        $piani[] = ruAggiungiStraordinaria($s, $delibera, $soggetto);
    }
    $straordinario = collect($piani)->first(fn (PianoRate $p) => $p->tipo === 'straordinario');
    $delibere = collect($piani)->mapWithKeys(fn (PianoRate $p) => [$p->id => $p->tipo === 'straordinario' ? $delibera : null])->all();
    if ($competenza !== null) {
        DB::table('fatture_passive')->whereIn('id', $straordinario->fatture()->pluck('fatture_passive.id'))->update(['competenza_dal' => $competenza[0], 'competenza_al' => $competenza[1]]);
    }
    // S=UN: un saldo pregresso intestato all'unità, senza persona (il saldo solidale, ramo B2 di `GenerateSaldiAction`).
    if (($r['S'] ?? '0') === 'UN') {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);
    }

    // Le persone, create alla prima volta che servono.
    $nomi = ['rita' => 'Madre Rita', 'bice' => 'Comproprietaria Bice', 'nora' => 'Figlia Nora', 'zeta' => 'Venditrice Zeta', 'carlo' => 'Compratore Carlo', 'ursula' => 'Usufruttuaria Ursula'];
    $persone = ['v' => $s['v'], 'a' => $s['a']];
    $persona = function (string $chi) use (&$persone, $nomi, $s): Anagrafica {
        if (! isset($persone[$chi])) {
            $n = str_pad((string) $s['unita']->id, 7, '0', STR_PAD_LEFT);
            $persone[$chi] = Anagrafica::forceCreate(['nome' => $nomi[$chi], 'email' => "inv-{$chi}{$n}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad(substr($chi, 0, 5), 5, 'X')) . 'INVT' . $n]);
            $persone[$chi]->condomini()->syncWithoutDetaching([$s['c']->id]);
        }

        return $persone[$chi];
    };

    // Il box, con la sua quota nella tabella della voce: lo porta ogni passaggio della catena, e ha gli stessi titolari.
    $box = null;
    if (($r['P'] ?? 'no') === 'box') {
        $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
        $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
        DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = array_values(array_filter([(int) $s['unita']->id, $box?->id === null ? null : (int) $box->id]));
    foreach ($forma['titolari'] as $i => [$chi, $tipologia, $quota]) {
        foreach ($unita as $u) {
            if ($i === 0 && $u === (int) $s['unita']->id) {
                DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => $quota]);
                continue;
            }
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona($chi)->id, 'immobile_id' => $u, 'tipologia' => $tipologia, 'quota' => $quota, 'attivo' => true,
                'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    // Il saldo della persona, quando non è di Ugo (per Ugo lo scrive `ruScenario`), prima della generazione.
    if ($chiSaldo !== 'v' && $saldo !== 0) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $persona($chiSaldo)->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $saldo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    // S=VE: il già versato della persona sulla voce (decisione 17): sulla straordinaria, se c'è.
    $versato = [];
    if (($r['S'] ?? '0') === 'VE') {
        $pianoV = $straordinario ?? $s['piano'];
        $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $pianoV->gestione_id)->value('conti.id');
        DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id, 'anagrafica_id' => $persona($chiSaldo)->id,
            'importo_cents' => 30000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
        $versato = ['anagrafica_id' => $persona($chiSaldo)->id, 'piano_rate_id' => $pianoV->id, 'immobile_id' => $s['unita']->id, 'importo' => 30000];
    }

    $genera = function (bool $dopo) use ($piani, $s, $N, $E, $straordinario): void {
        foreach ($piani as $p) {
            $dopo
                ? app(GeneratePianoRateAction::class)->execute($p, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: piano generato dopo i passaggi', esercizio: $s['e'])
                : app(GeneratePianoRateAction::class)->execute($p, forzaApplicazioneSaldi: true, esercizio: $s['e']);
        }
        // SN: la generazione pretende la delibera; la si toglie dopo, come quando la data non è mai stata registrata.
        if ($N === 'SN') {
            DB::table('piani_rate')->where('id', $straordinario->id)->update(['data_delibera_assemblea' => null]);
        }
        // SR: il piano anteriore alla beta.29, senza righe di riparto.
        if ($E === 'SR') {
            DB::table('righe_riparto')->whereIn('piano_rate_id', collect($piani)->pluck('id'))->delete();
        }
    };
    // Dalla 1.11.0-beta.42 (decisione 35) un piano che un passaggio ha lasciato al ricalcolo non si emette prima di ricalcolarlo:
    // l'emissione lo rifiuta. La griglia fa ciò che il programma chiede — ricalcola, con gli stessi ritocchi della generazione —
    // invece di emettere le quote rimaste a chi è uscito, che sono la forma dei dati di prima (U1) e fermerebbero il conguaglio
    // del passaggio dopo. Prima la griglia la costruiva, e la regola della .41 la nascondeva con l'ora del passaggio.
    $emetti = function (string $fino) use ($test, $piani, $s, $N, $E, $delibere): void {
        foreach ($piani as $p) {
            $daEmettere = DB::table('rate')->where('piano_rate_id', $p->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->exists();
            if ($daEmettere && $p->fresh()->passaggiDaSeguire() !== []) {
                // Il ricalcolo dalla sua rotta, come lo fa l'amministratore. SN: la delibera torna per il ricalcolo, che la pretende.
                if ($N === 'SN' && $delibere[$p->id] !== null) {
                    DB::table('piani_rate')->where('id', $p->id)->update(['data_delibera_assemblea' => $delibere[$p->id]]);
                }
                $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $p->id]), [
                    'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: piano ricalcolato dopo il passaggio, prima di emettere',
                ])->assertSessionHasNoErrors();
                if (DB::table('rate')->where('piano_rate_id', $p->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->doesntExist()
                    || $p->fresh()->passaggiDaSeguire() !== []) {
                    throw new \RuntimeException("Il ricalcolo del piano {$p->id} prima dell'emissione non è riuscito: " . json_encode(session('message'), JSON_UNESCAPED_UNICODE));
                }
                if ($N === 'SN' && $delibere[$p->id] !== null) {
                    DB::table('piani_rate')->where('id', $p->id)->update(['data_delibera_assemblea' => null]);
                }
                if ($E === 'SR') {
                    DB::table('righe_riparto')->where('piano_rate_id', $p->id)->delete();
                }
            }
            ruEmettiBozze($s, $p, $fino);
        }
    };
    // `tempo = inizio` (la griglia B): l'emissione parziale si fa una volta sola, prima del primo passaggio, così ogni passo
    // della catena si può annullare a rate intatte; altrimenti segue il tempo.
    $unaVolta = in_array($E, ['E4', 'SR'], true) && ($r['tempo'] ?? 'segue') === 'inizio';
    $segueIlTempo = in_array($E, ['E4', 'SR'], true) && ! $unaVolta;
    if ($E !== 'NP' && $G === 'prima') {
        $genera(false);
        if ($E === 'E12') {
            $emetti('2026-12-31');
        }
        if ($unaVolta) {
            $emetti(CarbonImmutable::parse($quando($passi[0][3], $d))->subDay()->toDateString());
        }
    }

    // Il modulo di un passo: chi esce, dalla sua riga aperta con il ruolo del tipo.
    $modulo = function (array $passo, string $dal) use ($persona, $s, $box): array {
        [$tipo, $esce, $entra] = $passo;
        $chiEsce = $persona($esce);
        $riga = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $chiEsce->id)
            ->where('tipologia', ruRuoloUscente($tipo))->whereNull('data_fine')->orderByDesc('id')->first();
        $chiEntra = $entra !== null ? $persona($entra) : null;

        return ['tipo' => $tipo, 'uscente' => $chiEsce, 'entrante' => $chiEntra, 'decorrenza' => $dal,
            'dati' => ruPassaggio($tipo, (int) $riga->id, $chiEntra, $dal, (float) $riga->quota, $box !== null ? [$box->id] : [])];
    };

    $caso = ['s' => $s, 'piani' => $piani, 'box' => $box, 'unita' => $unita, 'd' => $d, 'delibere' => $delibere, 'competenza' => $competenza, 'versato' => $versato];
    $passaggi = [];
    foreach (array_slice($passi, 0, -1) as $i => $passo) {
        $dal = $quando($passo[3], $d);
        if ($segueIlTempo && $G === 'prima') {
            $emetti(CarbonImmutable::parse($dal)->subDay()->toDateString());
        }
        $m = $modulo($passo, $dal);
        if ($primaDi !== null) {
            $primaDi($caso + ['persone' => $persone], $i);
        }
        $passaggi[] = ruRegistra($test, $s, $m['dati'] + ['ho_letto' => true, 'nota_cancello' => 'Passaggio precedente, letto e controllato']);
    }

    if ($E !== 'NP' && $G === 'dopo') {
        $genera(true);
        if ($E === 'E12') {
            $emetti('2026-12-31');
        }
    }
    if ($segueIlTempo) {
        $emetti($dd->subDay()->toDateString());
    }
    $esame = $modulo($ultimo, $d);
    $esame['dati'] += ['ho_letto' => false, 'nota_cancello' => null];
    if (($r['K'] ?? 'no') === 'si') {
        $esame['dati'] += ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio nel rogito'];
    }

    return $caso + ['persone' => $persone, 'esame' => $esame, 'passaggi' => $passaggi];
}

/**
 * Il generatore della griglia A: combina a coppie (pairwise) i valori delle dimensioni, in modo deterministico.
 *
 * Ogni coppia di valori di due dimensioni diverse compare in almeno una ricetta, salvo le coppie che un vincolo esclude:
 * quelle finiscono in `escluse`, con il motivo. I vincoli sono a due a due — [dimensione, valore, altra dimensione, valori
 * ammessi, motivo]: «con N=SN, E solo SR». Prima le ricette mirate (combinazioni di tre o più valori che le coppie non
 * garantiscono), completate come le altre; poi, finché resta una coppia scoperta, una ricetta nuova parte da quella e
 * sceglie ogni altro valore, nell'ordine delle dimensioni, fra quelli ammessi: quello che copre più coppie ancora
 * scoperte, e a pari merito il primo dell'elenco. L'etichetta di una ricetta è il nome del caso nel dataset.
 *
 * @param array<string, list<string>> $dimensioni
 * @param list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string}> $vincoli
 * @param list<array<string, string>> $mirate
 * @return array{casi: array<string, array<string, string>>, escluse: array<string, string>}
 */
function ruGriglia(array $dimensioni, array $vincoli, array $mirate = []): array
{
    $nomi = array_keys($dimensioni);
    $motivo = function (string $x, string $a, string $y, string $b) use ($vincoli): ?string {
        foreach ($vincoli as [$d1, $v1, $d2, $ammessi, $perche]) {
            if (($d1 === $x && $v1 === $a && $d2 === $y && ! in_array($b, $ammessi, true))
                || ($d1 === $y && $v1 === $b && $d2 === $x && ! in_array($a, $ammessi, true))) {
                return $perche;
            }
        }

        return null;
    };
    $chiave = fn (string $x, string $a, string $y, string $b) => array_search($x, $nomi, true) < array_search($y, $nomi, true) ? "{$x}={$a} × {$y}={$b}" : "{$y}={$b} × {$x}={$a}";

    $scoperte = [];
    $escluse = [];
    foreach ($nomi as $i => $x) {
        foreach (array_slice($nomi, $i + 1) as $y) {
            foreach ($dimensioni[$x] as $a) {
                foreach ($dimensioni[$y] as $b) {
                    $m = $motivo($x, $a, $y, $b);
                    if ($m === null) {
                        $scoperte[$chiave($x, $a, $y, $b)] = [$x, $a, $y, $b];
                    } else {
                        $escluse[$chiave($x, $a, $y, $b)] = $m;
                    }
                }
            }
        }
    }

    $completa = function (array $parziale) use ($nomi, $dimensioni, $motivo, &$scoperte, $chiave): array {
        $r = $parziale;
        foreach ($nomi as $x) {
            if (isset($r[$x])) {
                continue;
            }
            $meglio = null;
            $punti = -1;
            foreach ($dimensioni[$x] as $a) {
                $p = 0;
                foreach ($r as $y => $b) {
                    if ($motivo($x, $a, $y, $b) !== null) {
                        continue 2;
                    }
                    $p += isset($scoperte[$chiave($x, $a, $y, $b)]) ? 1 : 0;
                }
                if ($p > $punti) {
                    [$meglio, $punti] = [$a, $p];
                }
            }
            $r[$x] = $meglio;
        }

        return array_merge(array_flip($nomi), $r);
    };
    $casi = [];
    $aggiungi = function (array $ricetta) use (&$casi, &$scoperte, $nomi, $chiave): void {
        $casi[implode(' · ', array_map(fn ($x) => "{$x}={$ricetta[$x]}", $nomi))] = $ricetta;
        foreach ($nomi as $i => $x) {
            foreach (array_slice($nomi, $i + 1) as $y) {
                unset($scoperte[$chiave($x, $ricetta[$x], $y, $ricetta[$y])]);
            }
        }
    };

    foreach ($mirate as $m) {
        $aggiungi($completa($m));
    }
    while ($scoperte !== []) {
        [$x, $a, $y, $b] = reset($scoperte);
        $aggiungi($completa([$x => $a, $y => $b]));
    }

    return ['casi' => $casi, 'escluse' => $escluse];
}
