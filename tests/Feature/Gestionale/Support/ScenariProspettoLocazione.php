<?php

/**
 * 1.11.0-beta.50 — gli scenari del prospetto degli oneri accessori dopo la fine di una locazione, condivisi da
 * `ProspettoFineLocazioneTest`, `ProspettoFineLocazioneStampaTest` e `ConguaglioNonScrittoLocazioneTest`. Li carica ciascuno con
 * `require_once`, così ogni file passa anche lanciato da solo. Non è un test: il nome non finisce in `Test.php` e PHPUnit non lo
 * raccoglie. Le funzioni hanno il prefisso `pfl` (prospetto, fine locazione): i nomi delle funzioni di Pest sono globali.
 *
 * Tutto passa dalle rotte vere: la generazione del piano (`esercizi.piani-rate.regenerate`), l'emissione
 * (`piani-rate.emetti`), l'anteprima e la registrazione del passaggio (`immobili.passaggi.anteprima` e `.store`),
 * l'annullamento del conguaglio e del passaggio, la stampa del prospetto (`immobili.prospetto-oneri`, letta sulla vista resa).
 *
 * Il caso di partenza è quello della decisione 32 e del piano della .50 (§7.1): un'unità da 1000 millesimi su 1000, «Spese
 * generali» € 1.200,00, 30 % «Inquilino» (€ 360,00 = 36000) e 70 % «Proprietario» (€ 840,00 = 84000), dodici rate mensili dal
 * 5 gennaio 2026; i titolari censiti dal 2019.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../GestionaleTestHelpers.php';

/**
 * Le cinque forme dell'unità della decisione 32, con i nomi di questi test: i titolari oltre all'inquilina. Per ciascuna, chi
 * sta al posto dell'inquilino (chi riceve i giorni dopo l'uscita con «Scrivi il conguaglio», ruolo dopo l'inquilino nella catena
 * del riparto) e chi no.
 *
 * @return array<string, array{0: string, 1: list<array{0: string, 1: string, 2: float}>, 2: list<string>, 3: list<string>}> [forma, titolari, chi sta al posto dell'inquilino, chi mai]
 */
function pflForme(): array
{
    return [
        'piena (Ugo)' => ['piena (Ugo)', [['Ugo Rinaldi', 'proprietario', 100]], ['Ugo Rinaldi'], []],
        'in comproprietà (Ugo 50, Rita 50)' => ['in comproprietà (Ugo 50, Rita 50)', [['Ugo Rinaldi', 'proprietario', 50], ['Rita Bassi', 'proprietario', 50]], ['Ugo Rinaldi', 'Rita Bassi'], []],
        // La parte dell'inquilino va all'usufruttuario (art. 1004 c.c.), non alla nuda proprietaria.
        'in usufrutto (Ugo usufruttuario, Bice nuda)' => ['in usufrutto (Ugo usufruttuario, Bice nuda)', [['Ugo Rinaldi', 'usufruttuario', 100], ['Bice Landi', 'nuda_proprietario', 100]], ['Ugo Rinaldi'], ['Bice Landi']],
        // Usufruttuario e pieno sono la stessa persona: due righe di Ugo, nessuna di Bice.
        'mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50)' => ['mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50)', [['Ugo Rinaldi', 'proprietario', 50], ['Ugo Rinaldi', 'usufruttuario', 50], ['Bice Landi', 'nuda_proprietario', 50]], ['Ugo Rinaldi'], ['Bice Landi']],
        'mista con due persone (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50)' => ['mista con due persone (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50)', [['Ugo Rinaldi', 'proprietario', 50], ['Carlo Mancini', 'usufruttuario', 50], ['Bice Landi', 'nuda_proprietario', 50]], ['Ugo Rinaldi', 'Carlo Mancini'], ['Bice Landi']],
    ];
}

/**
 * Le cifre a mano del piano sull'unità, per forma, prima di ogni passaggio (la strada «Non scriverlo», verso il condominio, le
 * lascia così) e dopo «Scrivi il conguaglio». Sono le cifre della decisione 32 (`d32Attesi` del laboratorio, rifatte qui):
 * - prima: Ines 36000 (30 % di 120000); il «Proprietario» 84000 com'è nel piano (al nudo nell'usufrutto, al pieno e al nudo a metà
 *   nell'unità mista: 42000 + 42000);
 * - con «Scrivi»: la coppia sposta da Ines i 184 giorni dopo l'uscita, 36000 × 184 / 365 = 18147,95 → 18148 (resti maggiori: 36000 ×
 *   181 / 365 = 17852,05 → 17852, il centesimo al resto più grande, ,95), a chi sta al posto dell'inquilino, per quota: Ugo 18148
 *   (piena, usufrutto, mista); 18148 / 2 = 9074 a testa (comproprietà, mista con due persone).
 *
 * @return array{prima: array<string, int>, scrivi: array<string, int>}
 */
function pflNettiAttesi(string $forma): array
{
    return match ($forma) {
        // Ugo 84000 + 18148 = 102148.
        'piena (Ugo)' => ['prima' => ['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000], 'scrivi' => ['Ines Moretti' => 17852, 'Ugo Rinaldi' => 102148]],
        // 84000 / 2 = 42000 a testa; 42000 + 9074 = 51074.
        'in comproprietà (Ugo 50, Rita 50)' => ['prima' => ['Ines Moretti' => 36000, 'Rita Bassi' => 42000, 'Ugo Rinaldi' => 42000], 'scrivi' => ['Ines Moretti' => 17852, 'Rita Bassi' => 51074, 'Ugo Rinaldi' => 51074]],
        // Bice nuda 84000; Ugo usufruttuario 0 + 18148.
        'in usufrutto (Ugo usufruttuario, Bice nuda)' => ['prima' => ['Bice Landi' => 84000, 'Ines Moretti' => 36000], 'scrivi' => ['Bice Landi' => 84000, 'Ines Moretti' => 17852, 'Ugo Rinaldi' => 18148]],
        // Ugo pieno 42000, Bice nuda 42000; Ugo 42000 + 18148 = 60148.
        'mista (Ugo pieno 50 e usufruttuario 50, Bice nuda 50)' => ['prima' => ['Bice Landi' => 42000, 'Ines Moretti' => 36000, 'Ugo Rinaldi' => 42000], 'scrivi' => ['Bice Landi' => 42000, 'Ines Moretti' => 17852, 'Ugo Rinaldi' => 60148]],
        // Ugo pieno 42000 + 9074 = 51074; Carlo usufruttuario 0 + 9074; Bice 42000.
        'mista con due persone (Ugo pieno 50, Carlo usufruttuario 50, Bice nuda 50)' => ['prima' => ['Bice Landi' => 42000, 'Ines Moretti' => 36000, 'Ugo Rinaldi' => 42000], 'scrivi' => ['Bice Landi' => 42000, 'Carlo Mancini' => 9074, 'Ines Moretti' => 17852, 'Ugo Rinaldi' => 51074]],
    };
}

/**
 * Il condominio: l'unità «Interno 1» e, con `$box`, il «Box 12» sua pertinenza (900 e 100 millesimi su 1000); i conti per
 * emettere; la gestione «Ordinaria 2026» con «Spese generali» € 1.200,00 divise 30 % «Inquilino» e 70 % «Proprietario» e il
 * suo piano «Preventivo 2026» approvato, dodici rate mensili dal 5 gennaio, **non ancora generato**.
 *
 * `$titolari`: [nome, ruolo, quota] sull'unità; `$inquilini`: [nome, quota] sull'unità (e sul box con `$box`); `$titolariBox`: i
 * titolari del box (di norma gli stessi dell'unità). Tutti censiti dal 2019.
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, box: ?Immobile, piano: PianoRate, persone: array<string, Anagrafica>}
 */
function pflScenario(array $titolari = [['Ugo Rinaldi', 'proprietario', 100]], array $inquilini = [['Ines Moretti', 100]], bool $box = false, ?array $titolariBox = null): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PFL-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => $box ? 900.0 : 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $pertinenza = null;
    if ($box) {
        $pertinenza = Immobile::forceCreate(['condominio_id' => $c->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $unita->id]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $pertinenza->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    }

    // I conti dell'emissione vera (gli stessi di `ilpfScenario`).
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);

    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC Ordinaria 2026']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata',
    ]);

    $s = ['c' => $c, 'e' => $e, 'g' => $g, 'unita' => $unita, 'box' => $pertinenza, 'piano' => $piano, 'persone' => []];
    foreach ($titolari as [$nome, $ruolo, $quota]) {
        pflTitolare($s, $unita, $nome, $ruolo, $quota);
    }
    foreach ($inquilini as [$nome, $quota]) {
        pflTitolare($s, $unita, $nome, 'inquilino', $quota);
    }
    if ($pertinenza !== null) {
        foreach ($titolariBox ?? $titolari as [$nome, $ruolo, $quota]) {
            pflTitolare($s, $pertinenza, $nome, $ruolo, $quota);
        }
        foreach ($inquilini as [$nome, $quota]) {
            pflTitolare($s, $pertinenza, $nome, 'inquilino', $quota);
        }
    }

    return $s;
}

/** Una persona del condominio dello scenario, per nome (la stessa se c'è già). */
function pflPersona(array &$s, string $nome): Anagrafica
{
    static $n = 0;
    if (isset($s['persone'][$nome])) {
        return $s['persone'][$nome];
    }
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'pfl' . $n . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PFLPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $s['persone'][$nome] = $p;
}

/** Una riga di titolarità censita dal 2019. */
function pflTitolare(array &$s, Immobile $unita, string $nome, string $ruolo, float $quota = 100): void
{
    $p = pflPersona($s, $nome);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** La generazione del piano dalla rotta vera (la stessa del ricalcolo), con la presa d'atto dei destinatari. */
function pflGenera($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano del preventivo 2026, titolari controllati',
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->count())->toBe(12, 'la generazione dalla rotta non ha fatto le dodici rate');
}

/**
 * L'emissione vera, dalla rotta della pagina del piano: le rate non ancora emesse in scadenza fino al giorno dato, emesse quel giorno
 * (o il giorno `$il`, per le rate future).
 */
function pflEmetti($test, array $s, string $fino, ?string $il = null): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => $rate, 'data_emissione' => $il ?? $fino, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
}

/** Il caso di partenza: il piano generato e le sei rate da gennaio a giugno emesse il 30/6, a giornale. */
function pflSeiRateEmesse($test, array $s): void
{
    pflGenera($test, $s);
    pflEmetti($test, $s, '2026-06-30');
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(6, 'le sei rate non sono a giornale');
}

/** La riga aperta di una persona con un ruolo su un'unità. */
function pflRigaAperta(array $s, Immobile $unita, string $nome, string $ruolo = 'inquilino'): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $s['persone'][$nome]->id)->where('tipologia', $ruolo)
        ->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/**
 * Il modulo della fine locazione: senza nuovo inquilino (`$entra` null) o il cambio d'inquilino in un passo. `$scelta` è il campo
 * `conguaglio` della decisione 77 («scrivi» / «non_scrivere»), assente se null; `$pertinenze` i box spuntati.
 */
function pflModuloFine(array &$s, string $esce, ?string $entra, string $il, ?string $scelta, array $pertinenze = []): array
{
    return ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => pflRigaAperta($s, $s['unita'], $esce),
        'anagrafica_entrante_id' => $entra !== null ? pflPersona($s, $entra)->id : null, 'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false,
        'pertinenze' => array_map(fn (Immobile $p) => (int) $p->id, $pertinenze)]
        + ($scelta !== null ? ['conguaglio' => $scelta] : []);
}

/**
 * La fine locazione dalla rotta vera: prima l'anteprima, poi la registrazione con la presa d'atto del cancello se il pannello la
 * chiede. Una rotta che rifiuta il modulo si dice con il campo e il messaggio (sul codice di prima della .50 il campo `conguaglio`
 * fuori dalla successione è vietato: è il campo nuovo della decisione 77).
 */
function pflFine($test, array &$s, string $esce, ?string $entra, string $il, ?string $scelta, array $pertinenze = [], array $extra = []): Subentro
{
    $dati = pflModuloFine($s, $esce, $entra, $il, $scelta, $pertinenze) + $extra;
    $ant = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
    expect($ant->status())->toBe(200, 'anteprima della fine locazione rifiutata: ' . json_encode($ant->json('errors') ?? $ant->json('message'), JSON_UNESCAPED_UNICODE));
    if ($ant->json('cancello.richiesto')) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Disdetta del contratto letta, rate emesse controllate'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** «Inizio locazione» dalla rotta vera, con la presa d'atto del cancello (il piano è fermo: il pannello informa). */
function pflInizio($test, array &$s, string $entra, string $il): Subentro
{
    $dati = ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => pflPersona($s, $entra)->id, 'decorrenza' => $il, 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Contratto di locazione letto'];
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** Il prospetto di un'unità dello scenario (l'unità, se non se ne dà un'altra) nell'esercizio 2026, dal servizio. */
function pflProspetto(array $s, ?Immobile $unita = null): array
{
    return app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);
}

/**
 * Per conduttore, in ordine di nome: [totale, nelle sue rate, da rimborsare, giorni] — i giorni sono la somma di quelli delle sue
 * voci, come in `d32Prospetto` del laboratorio (una voce senza giorni conta zero). Il segno di «da rimborsare» è quello del
 * prospetto: positivo = il conduttore deve a chi ha pagato, negativo = gli si deve.
 *
 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
 */
function pflConduttori(array $p): array
{
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['da_rimborsare'], (int) collect($c['voci'])->sum(fn ($v) => (int) ($v['giorni'] ?? 0))];
    }
    ksort($out);

    return $out;
}

/** La sezione di un conduttore, o null. */
function pflSezione(array $p, string $nome): ?array
{
    return collect($p['conduttori'])->firstWhere('nome', $nome);
}

/**
 * Le voci «senza conduttore»: [pagata da, importo, giorni], in ordine di pagatore.
 *
 * @return list<array{0: ?string, 1: int, 2: ?int}>
 */
function pflSenza(array $p): array
{
    return collect($p['senza_conduttore']['voci'])->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], $v['giorni'] === null ? null : (int) $v['giorni']])
        ->sortBy(fn ($v) => (string) $v[0])->values()->all();
}

/** Chi compare come «pagata da» nelle voci date, una volta per nome, in ordine. */
function pflPagatori(array $voci): array
{
    return collect($voci)->pluck('pagato_da')->filter()->unique()->sort()->values()->all();
}

/**
 * Tutte le frasi del prospetto (testa, note delle voci, note delle sezioni), senza i campi `*_formattato`: per cercare una frase
 * senza sapere dove il servizio la mette.
 *
 * @return list<string>
 */
function pflFrasi(array $p): array
{
    $frasi = [];
    $giro = function ($nodo, $chiave = null) use (&$giro, &$frasi) {
        if (is_array($nodo)) {
            foreach ($nodo as $k => $v) {
                $giro($v, $k);
            }
        } elseif (is_string($nodo) && ! (is_string($chiave) && str_ends_with($chiave, '_formattato'))) {
            $frasi[] = $nodo;
        }
    };
    $giro($p);

    return $frasi;
}

/**
 * Ciò che ciascuno paga del piano sull'unità: la quota pura delle quote a suo nome, più le righe dei conguagli dei passaggi. Senza
 * gli zeri, per nome.
 *
 * @return array<string, int>
 */
function pflNetti(array $s, ?Immobile $unita = null): array
{
    $unita ??= $s['unita'];
    $netti = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $unita->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $netti[(int) $q->anagrafica_id] = ($netti[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $unita->id)->get() as $r) {
        $netti[(int) $r->anagrafica_id] = ($netti[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($netti))->pluck('nome', 'id');
    $out = [];
    foreach ($netti as $id => $cifra) {
        if ($cifra !== 0) {
            $out[$nomi[$id]] = $cifra;
        }
    }
    ksort($out);

    return $out;
}

/**
 * La stampa del prospetto (`immobili.prospetto-oneri`). La rotta vera risponde con il PDF (lo stato e il tipo si controllano), e il
 * testo si legge sulla vista resa con gli stessi dati del controller, come `pciStampa` in `ProspettoCambioInquilinoTest`: non sul PDF
 * con `pdftotext`, per non fare della suite una dipendenza di poppler (verbale della .50, «Le scelte di progetto sulle questioni aperte
 * dai test»). Un testo solo: tag tolti (e con loro il titolo e i fogli di stile), entità sciolte, spazi uniti. Le etichette che la
 * vista stampa con `text-transform: uppercase` (il ruolo in testa alla sezione: «CONDUTTORE», «COMODATARIO») si leggono in maiuscolo,
 * come sulla carta. Non vede l'impaginazione.
 */
function pflStampa($test, array $s, ?Immobile $unita = null): string
{
    $unita ??= $s['unita'];
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $unita, 'esercizio' => $s['e']->id]));
    expect($r->status())->toBe(200)->and((string) $r->headers->get('Content-Type'))->toContain('application/pdf');

    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $unita->fresh(), 'prospetto' => pflProspetto($s, $unita),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();
    // Ciò che la carta non stampa: il titolo della pagina e i fogli di stile.
    $html = (string) preg_replace('#<(style|title|script)\b[^>]*>.*?</\1>#isu', ' ', $html);
    $html = (string) preg_replace_callback('/(<(\w+)[^>]*text-transform:\s*uppercase[^>]*>)([^<]*)(<\/\2>)/iu', fn (array $m) => $m[1] . mb_strtoupper($m[3]) . $m[4], $html);
    $testo = strtr(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ["\u{00A0}" => ' ']);
    expect(trim($testo))->not->toBe('', 'la vista della stampa non ha testo');

    return trim((string) preg_replace('/\s+/u', ' ', $testo));
}

/**
 * Le occorrenze di «pagata da» nella stampa che non sono seguite dal nome di una persona del condominio (né dai puntini della
 * legenda in testa, «pagata da …»): la voce che la stampa non sa dire.
 *
 * @return list<string>
 */
function pflPagataDaSenzaNome(string $testo): array
{
    $nomi = Anagrafica::pluck('nome')->filter()->all();
    $male = [];
    if (preg_match_all('/pagata da\s+(.{0,40})/u', $testo, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $x) {
            $dopo = $x[1];
            $ok = str_starts_with($dopo, '…') || collect($nomi)->contains(fn (string $n) => str_starts_with($dopo, $n));
            if (! $ok) {
                $male[] = 'pagata da ' . $dopo;
            }
        }
    }

    return $male;
}

/**
 * Il registro di una fine locazione senza nuovo inquilino com'era prima della .50: nessuna scelta (`conguaglio`), niente
 * `regolato_fuori`, nessun piano preso (decisione 47: un passaggio senza conguaglio non prende piani), nessuna nota del conguaglio.
 * Serve a provare le fini già nei dati con il codice nuovo, che senza la scelta non le registra più quando propone le coppie.
 */
function pflComePrimaDellaCinquanta(Subentro $fine): Subentro
{
    foreach (Subentro::where('id', $fine->id)->orWhere('subentro_padre_id', $fine->id)->get() as $s) {
        $registro = $s->registro ?? [];
        unset($registro['conguaglio'], $registro['regolato_fuori']);
        $registro['piani_presi'] = [];
        DB::table('subentri')->where('id', $s->id)->update(['registro' => json_encode($registro), 'nota_conguaglio' => null]);
    }

    return $fine->fresh();
}

/** Gli avvisi dell'annullamento del passaggio come li mostra lo storico dell'unità (`annullabile.avvisi`), in un testo solo. */
function pflAvvisiDelloStorico(array $s, Subentro $passaggio): string
{
    $voce = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id);

    return implode(' | ', $voce['annullabile']['avvisi'] ?? []);
}

/** `regolato_fuori` del registro, gestione per gestione: [gestione => [nome => cifra]], le persone in ordine di nome. */
function pflRegolatoFuori(Subentro $passaggio): array
{
    $out = [];
    foreach ($passaggio->fresh()->registro['regolato_fuori'] ?? [] as $v) {
        $persone = collect($v['persone'] ?? [])->mapWithKeys(fn ($p) => [(string) ($p['nome'] ?? '?') => (int) ($p['importo'] ?? 0)])->sortKeys()->all();
        $out[(string) ($v['gestione'] ?? '?')] = $persone;
    }

    return $out;
}

/**
 * Il già versato «dell'unità» (decisione 17, D8) sulla voce «Spese generali»: `contributi_versati` senza persona, prima della
 * generazione (come `pciVersato`). Aggiunto nella revisione dei test della Fase 1: la misura del prospetto aveva trovato su questo
 * caso un difetto da € 18,15, e i test di prima non lo presidiavano.
 */
function pflVersatoDellUnita(array $s, int $importo): void
{
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => $importo, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * La voce su due tabelle (come il rilievo P8 di `ProspettoCambioInquilinoTest`): «Spese generali» € 100,00 tutta sull'«Inquilino», a
 * metà su «Proprietà» e su «Scale», dove l'unità ha 1000 su 1000. Prima della generazione. Aggiunto nella revisione dei test della Fase
 * 1: è un caso misurato dal prospetto della Fase 1 e non portato nei test.
 */
function pflVoceSuDueTabelle(array $s): void
{
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conti')->where('id', $contoId)->update(['importo' => 10000]);
    DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->update(['coefficiente' => 50]);
    $ctm = (int) DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->value('id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->where('soggetto', 'proprietario')->delete();
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->where('soggetto', 'inquilino')->update(['percentuale' => 100]);
    $scale = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale->id, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm2 = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $scale->id, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm2, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * La vendita dell'unità dalla rotta vera, con la presa d'atto del cancello (dopo «Scrivi» la vendita si ferma sulla parte della coppia
 * e lo dice: decisione 77.3). Aggiunto nella revisione dei test della Fase 1, per il «pagata da» dopo una fermata.
 */
function pflVendita($test, array &$s, string $vende, string $compra, string $il): Subentro
{
    $dati = ['tipo' => 'vendita', 'riga_uscente_id' => pflRigaAperta($s, $s['unita'], $vende, 'proprietario'), 'anagrafica_entrante_id' => pflPersona($s, $compra)->id,
        'decorrenza' => $il, 'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => $il,
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, la parte della fine locazione si regola a mano'];
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/**
 * La sezione di un conduttore nella stampa (`pflStampa`): dal titolo («CONDUTTORE Ines Moretti») alla sezione dopo (un altro
 * conduttore, i giorni senza conduttore, il piede dell'unità). Dentro la sezione l'ordine delle celle non conta, si cerca solo che
 * ci siano. Vuota se la stampa non ha la sezione.
 */
function pflSezioneNellaStampa(string $testo, string $nome): string
{
    $inizio = mb_strpos($testo, 'CONDUTTORE ' . $nome);
    if ($inizio === false) {
        $inizio = mb_strpos($testo, 'COMODATARIO ' . $nome);
    }
    if ($inizio === false) {
        return '';
    }
    $resto = mb_substr($testo, $inizio + 1);
    $fine = collect(['CONDUTTORE ', 'COMODATARIO ', 'Giorni senza conduttore', 'Totale delle voci che il riparto'])
        ->map(fn (string $t) => mb_strpos($resto, $t))->filter(fn ($x) => $x !== false)->min();

    return mb_substr($testo, $inizio, $fine === null ? null : $fine + 1);
}
