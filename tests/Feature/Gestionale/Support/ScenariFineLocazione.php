<?php

/**
 * Gli scenari della fine di una locazione e del cambio d'inquilino (1.11.0-beta.50), condivisi da `FineLocazioneSceltaTest`,
 * `CambioInquilinoSceltaTest` e `CoinquilinoCheRestaTest`. Ogni file lo carica con `require_once`, così passa anche lanciato da
 * solo. Non è un test: il nome non finisce in `Test.php` e PHPUnit non lo raccoglie. Le funzioni hanno il prefisso `fcl` (fine e
 * cambio di locazione): i nomi delle funzioni di Pest sono globali.
 *
 * Il condominio è quello della decisione 32 e di `FineLocazioneFrasiTest`: «Spese generali» € 1.200,00 sulla tabella «Proprietà»,
 * 30 % «Inquilino» e 70 % «Proprietario» (si può cambiare), il «Preventivo 2026» in dodici rate mensili dal 5 gennaio, approvato e
 * non generato. L'unità «Interno 1» ha 1000 millesimi su 1000; con il box, l'Interno 1 900 e il «Box 12» (sua pertinenza) 100.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\Riparto\ProspettoOneriAccessori;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../GestionaleTestHelpers.php';

/**
 * Lo scenario. `$titolari` sono le righe dell'Interno 1 oltre agli inquilini: [nome, ruolo, quota], dal 2019. Opzioni:
 * - `inquilini`: [nome, quota] dell'Interno 1, dal 2019 (di partenza Ines al 100 %);
 * - `box`: le righe del «Box 12», pertinenza dell'Interno 1, [nome, ruolo, quota] dal 2019, inquilini compresi; con il box i
 *   millesimi diventano 900 e 100;
 * - `ripartizione`: la voce fra i ruoli (di partenza 30 % «Inquilino», 70 % «Proprietario»);
 * - `importo`: la voce in centesimi (di partenza 120000);
 * - `anno`: l'anno dell'esercizio, della gestione e del piano (di partenza il 2026; il 2028 ha 366 giorni). I nomi restano
 *   «Ordinaria <anno>» e «Preventivo <anno>».
 *
 * @param list<array{0: string, 1: string, 2: float|int}> $titolari
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, box: ?Immobile, piano: PianoRate, persone: array<string, Anagrafica>}
 */
function fclScenario(array $titolari, array $opzioni = []): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $anno = (int) ($opzioni['anno'] ?? 2026);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => "Ordinaria {$anno}", 'tipo' => 'ordinaria', 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31"]);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => (int) ($opzioni['importo'] ?? 120000)]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($opzioni['ripartizione'] ?? ['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $conBox = isset($opzioni['box']);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "FCL-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => $conBox ? 900.0 : 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $box = null;
    if ($conBox) {
        $box = Immobile::forceCreate(['condominio_id' => $c->id, 'codice_immobile' => "FCL-B{$seq}", 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $unita->id]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    }

    $persone = [];
    $riga = function (Immobile $u, string $nome, string $ruolo, float|int $quota) use (&$persone, $c, $seq): void {
        if (! isset($persone[$nome])) {
            $n = count($persone);
            $persone[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . "-fcl{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
                'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 4), 4, 'X')) . 'FCL' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT) . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
            $persone[$nome]->condomini()->syncWithoutDetaching([$c->id]);
        }
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$nome]->id, 'immobile_id' => $u->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    };
    foreach ($titolari as [$nome, $ruolo, $quota]) {
        $riga($unita, $nome, $ruolo, $quota);
    }
    foreach ($opzioni['inquilini'] ?? [['Ines', 100]] as [$nome, $quota]) {
        $riga($unita, $nome, 'inquilino', $quota);
    }
    foreach ($opzioni['box'] ?? [] as [$nome, $ruolo, $quota]) {
        $riga($box, $nome, $ruolo, $quota);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => "Preventivo {$anno}", 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => "{$anno}-01-05",
        'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);

    return compact('c', 'e', 'g', 'unita', 'box', 'piano', 'persone');
}

/** Una persona del condominio che non ha ancora righe sulle unità (Luca, il nuovo inquilino; Elsa, chi compra). */
function fclPersona(array &$s, string $nome): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-fcl-n' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(str_pad(substr($nome, 0, 4), 4, 'X')) . 'FCLNUOVO' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['persone'][$nome] = $p;

    return $p;
}

/** La generazione del piano, con la presa d'atto del cancello (2) come la dà l'amministratore (come i test della locazione). */
function fclGenera(array $s): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
}

/** L'emissione vera, dalla rotta della pagina del piano, delle rate ancora in bozza con scadenza fino al giorno dato. */
function fclEmetti($test, array $s, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
    ]);
}

/** Quante rate del piano sono a giornale. */
function fclEmesse(array $s): int
{
    return DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count();
}

/** La riga aperta di una persona con un ruolo su un'unità (l'Interno 1 se non si dice). */
function fclRiga(array $s, string $nome, string $ruolo = 'inquilino', ?Immobile $unita = null): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', ($unita ?? $s['unita'])->id)->where('anagrafica_id', $s['persone'][$nome]->id)
        ->where('tipologia', $ruolo)->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/**
 * Il modulo della fine locazione di chi esce (di partenza Ines) il giorno dato, con il nuovo inquilino (il cambio d'inquilino) o
 * senza, e le pertinenze spuntate. Senza la scelta sul conguaglio: chi la vuole la aggiunge con `+ ['conguaglio' => …]`.
 */
function fclModulo(array $s, ?Anagrafica $entra = null, string $il = '2026-07-01', array $pertinenze = [], string $esce = 'Ines'): array
{
    return [
        'tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => fclRiga($s, $esce), 'anagrafica_entrante_id' => $entra?->id,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => array_map(fn (Immobile $p) => (int) $p->id, $pertinenze),
    ];
}

/** L'anteprima dalla rotta vera («Cosa cambierà»): la risposta da controllare. */
function fclAnteprimaRisposta($test, array $s, array $dati): TestResponse
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
}

/** L'anteprima che deve riuscire, come array. */
function fclAnteprima($test, array $s, array $dati): array
{
    return fclAnteprimaRisposta($test, $s, $dati)->assertOk()->json();
}

/**
 * La registrazione dalla rotta vera, con la presa d'atto del cancello (1) solo se il pannello la chiede — come l'amministratore, che
 * spunta «ho letto» dopo aver visto il pannello. La risposta da controllare (un redirect se riesce, gli errori in sessione se no).
 */
function fclStore($test, array $s, array $dati): TestResponse
{
    $anteprima = fclAnteprimaRisposta($test, $s, $dati);
    if ($anteprima->status() === 200 && ($anteprima->json('cancello.richiesto') ?? false)) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Disdetta letta, rate emesse controllate'];
    }

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
}

/** La registrazione che deve riuscire; il passaggio padre appena scritto. */
function fclRegistra($test, array $s, array $dati): Subentro
{
    fclStore($test, $s, $dati)->assertSessionHasNoErrors();

    return fclUltimo($s);
}

/** Il passaggio padre più recente dell'Interno 1. */
function fclUltimo(array $s): Subentro
{
    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** Il nome di ogni anagrafica dello scenario, per id. */
function fclNomi(array $s): array
{
    return collect($s['persone'])->mapWithKeys(fn (Anagrafica $a, string $nome) => [(int) $a->id => $nome])->all();
}

/**
 * Quanto paga ogni persona su un'unità (l'Interno 1 se non si dice), per nome: le quote pure del piano più le righe in saldi dei
 * passaggi, senza gli zeri. È il «netto» della decisione 21: la cifra che il piano generato dopo darebbe.
 */
function fclNetti(array $s, ?Immobile $unita = null): array
{
    $unita ??= $s['unita'];
    $nomi = fclNomi($s);
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $unita->id)->where('rate_quote.stato', '!=', 'annullata')->get(['rate_quote.anagrafica_id', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $chi = $nomi[(int) $q->anagrafica_id] ?? "anagrafica {$q->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) (json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? 0);
    }
    foreach (fclSaldi($s, $unita) as $chi => $c) {
        $out[$chi] = ($out[$chi] ?? 0) + $c;
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/** Le righe in saldi scritte dai passaggi su un'unità (l'Interno 1 se non si dice), sommate per persona: nome → centesimi, senza gli zeri. */
function fclSaldi(array $s, ?Immobile $unita = null): array
{
    $nomi = fclNomi($s);
    $out = [];
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('gestione_id', $s['g']->id)->where('immobile_id', ($unita ?? $s['unita'])->id)->get(['anagrafica_id', 'saldo_iniziale']) as $r) {
        $chi = $nomi[(int) $r->anagrafica_id] ?? "anagrafica {$r->anagrafica_id}";
        $out[$chi] = ($out[$chi] ?? 0) + (int) $r->saldo_iniziale;
    }
    $out = array_filter($out, fn (int $c) => $c !== 0);
    ksort($out);

    return $out;
}

/** Quante righe in saldi hanno scritto i passaggi dello scenario, su tutte le unità. */
function fclRigheDeiPassaggi(array $s): int
{
    return DB::table('saldi')->whereNotNull('subentro_id')->where('condominio_id', $s['c']->id)->count();
}

/**
 * Il prospetto degli oneri accessori di un'unità (l'Interno 1 se non si dice) in breve: per conduttore [totale, nelle sue rate,
 * da rimborsare, giorni]; «senza conduttore» [totale, giorni]. I nomi in minuscolo, come `d32Prospetto` del laboratorio.
 */
function fclProspetto(array $s, ?Immobile $unita = null): array
{
    $p = app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);
    $out = ['conduttori' => [], 'senza' => [(int) $p['senza_conduttore']['totale'], (int) collect($p['senza_conduttore']['voci'])->sum('giorni')]];
    foreach ($p['conduttori'] as $c) {
        $out['conduttori'][mb_strtolower($c['nome'])] = [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare'], (int) collect($c['voci'])->sum('giorni')];
    }
    ksort($out['conduttori']);

    return $out;
}

/** La stampa del prospetto di un'unità, come testo (tag tolti, spazi uniti), dalla vista vera del PDF. */
function fclStampa(array $s, ?Immobile $unita = null): string
{
    $unita = ($unita ?? $s['unita'])->fresh();
    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $unita,
        'prospetto' => app(ProspettoOneriAccessori::class)->calcola($unita, $s['e']),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();

    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Le frasi «Chi resta obbligato» di un passaggio nello storico dell'Interno 1, in un testo solo. */
function fclStorico(array $s, Subentro $subentro): string
{
    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);

    return implode(' ', $voce['obbligati'] ?? []);
}

/** Tutte le frasi del pannello «Cosa cambierà» che parlano di soldi e di chi resta obbligato, in un testo solo. */
function fclFrasi(array $anteprima): string
{
    return implode(' ', [
        ...($anteprima['rate']['frasi'] ?? []), ...($anteprima['rate']['frasi_del_conguaglio'] ?? []), ...($anteprima['rate']['conguaglio']['frasi'] ?? []),
        ...($anteprima['obbligati']['frasi'] ?? []), ...($anteprima['cancello']['motivi'] ?? []), ...($anteprima['cancello']['informazioni'] ?? []),
    ]);
}

/** Le frasi del pannello e del cancello divise in periodi (al punto seguito da uno spazio), per cercare la frase che contiene una cifra. */
function fclPeriodi(string $testo): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.:])\s+(?=[A-ZÈ«])/u', $testo)), fn ($p) => $p !== ''));
}

/** Le coppie proposte dall'anteprima: [unità, persona, centesimi] per ogni riga a debito (la controparte di chi esce). */
function fclCoppie(array $anteprima): array
{
    return collect($anteprima['rate']['conguaglio']['coppie'] ?? [])
        ->map(fn ($c) => [(int) $c['immobile_id'], (string) ($c['entrante_nome'] ?? '?'), (int) $c['importo']])
        ->sortBy(fn ($c) => $c[0] . '|' . $c[1])->values()->all();
}

/** Un saldo pregresso a mano (il riporto) di una persona su un'unità e sulla gestione dello scenario, come lo scrive il Wallet. */
function fclRiporto(array $s, string $nome, int $centesimi, ?Immobile $unita = null): void
{
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$nome]->id, 'immobile_id' => ($unita ?? $s['unita'])->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi, 'origine' => 'manuale', 'is_applicato' => false]);
}

/** L'annullamento del passaggio dalla rotta dello storico (`deleteJson`: 302 se annulla, 422 se si ferma). */
function fclAnnulla($test, array $s, Subentro $subentro, string $nota = 'Registrato per sbaglio: lo annullo'): TestResponse
{
    return $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => $nota]);
}
