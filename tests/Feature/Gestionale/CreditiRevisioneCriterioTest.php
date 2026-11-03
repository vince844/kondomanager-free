<?php

/**
 * 1.11.0-beta.49, Fase 1-bis (`docs/piano_esecutivo_beta49.md`, «Fase 1-bis (11/10/2026)»): i rilievi del criterio del credito e
 * dello storno. Decisioni 71, 75 e 76 del registro (`docs/subentro_e_competenza_temporale.md`).
 *
 * K2 (alto) — la voce di tipo «entrata». Il motore rende negativa la quota di una voce di entrata (`CalcoloQuoteService`, «: -abs»):
 * la quota del box che sta solo nella tabella dell'antenna diventa «a credito» senza essere un credito riportato. Il suo credito è
 * dell'anno, non di un esercizio precedente: non è mai entrato in «Passate gestioni», e da lì non deve uscire. Il conto del credito
 * si decide dal riporto (`componenti()['riporto']`), non dal segno: su «Passate gestioni» va al più il riporto non ancora a giornale,
 * il resto sta fra i crediti verso condomini, come un incasso su una quota a debito ancora in bozza (l'AVERE dell'emissione lo chiude).
 * Vale per il prelievo e il riaccredito dell'incasso, per il rimborso, per lo storno, per il rilevamento DC1 del comando (che oggi
 * prende per DC1 i dati giusti della 1.10 e li guasta) e per le frasi del rimborso.
 *
 * K4 e LDA-1 (medi, la stessa radice) — lo storno di un credito usato tutto prima dell'emissione. La quota a credito non scrive righe
 * (il suo credito è già uscito da «Passate gestioni») e resta senza scrittura, mentre la rata va a giornale per gli altri. Lo storno
 * restituisce il credito su «Passate gestioni» ma non scrive la figlia dell'emissione, perché cerca solo una quota con scrittura: il
 * credito resta fuori dal giornale in una rata a giornale (DC2-b, scritto dalla .49 da sola), lo stato patrimoniale conta di nuovo da
 * pagare le rate che il credito aveva pagato, e la card «Crediti da riallineare» si accende su dati che non sono della 1.9 né della
 * 1.10. La decisione 76.3: lo storno di un credito usato prima dell'emissione è lo specchio più una figlia dell'emissione che riporta
 * il credito fra i crediti verso condomini. Con la figlia tornano veri anche i testi: la guida degli incassi («una seconda scrittura,
 * legata all'emissione»; «dopo l'emissione lo preleva da «Crediti verso condomini»») e il modulo del rimborso.
 *
 * S2 (basso, sicurezza: riallineamento e storno dello stesso incasso in contemporanea) qui non c'è: su SQLite in memoria non si
 * prova. `lockForUpdate()` su SQLite non scrive niente (`SQLiteGrammar::compileLock` restituisce ''), c'è una connessione sola e
 * nessuna transazione concorrente; l'intreccio che conta (lo storno legge la famiglia, il riallineamento scrive la rettifica e chiude,
 * lo storno prosegue senza vederla) si potrebbe solo simulare dentro la transazione dello storno, dove la correzione — l'ordine dei
 * blocchi — non si vede.
 *
 * Le cifre sono in centesimi interi, rifatte a mano persona per persona e conto per conto. Lo stato patrimoniale è la terna
 * [Crediti verso condomini, Gestione rate, Passate gestioni] di `StatoPatrimonialeService::calcola` (per i passivi saldo = avere −
 * dare: −4000 su «Passate gestioni» vuol dire un DARE netto di 4000). Il mastrino è «Crediti verso condomini» a nome della persona;
 * segno dei saldi delle persone: positivo = debito, negativo = credito. Il tempo è fermo all'11/10/2026: lo storno porta la data in
 * cui si fa (`StornoIncassoRateAction`), e la fotografia al 30/06 non deve vederlo.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00'));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// ─── Aiuti (prefisso crit49) ─────────────────────────────────────────────────────────────────────────────────────────────────

/** Il condominio, l'esercizio 2026 aperto, la gestione ordinaria, i cinque conti contabili e la banca con `$cassa` di apertura. */
function crit49Condominio(int $cassa = 20000): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => $cassa]);

    return ['c' => $c, 'e' => $e, 'g' => $g, 'pc' => $pc, 'cassa' => $cassa, 'seq' => $seq, 'persone' => [], 'unita' => []];
}

/** L'unità `$codice` intestata a `$chi` (creata alla prima unità), proprietario al 100% dal 2019. */
function crit49Unita(array &$s, string $codice, string $chi, string $nome, string $nomeUnita): void
{
    $s['unita'][$codice] = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "CRIT49-{$s['seq']}-{$codice}", 'nome' => $nomeUnita, 'interno' => $codice]);
    if (! isset($s['persone'][$chi])) {
        $s['persone'][$chi] = Anagrafica::forceCreate(['nome' => $nome, 'email' => "crit49-{$chi}{$s['seq']}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad("CRIT{$chi}{$s['seq']}", 16, 'X'))]);
        $s['persone'][$chi]->condomini()->syncWithoutDetaching([$s['c']->id]);
    }
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$codice]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
}

/** Una voce del preventivo su una tabella sua, a carico del proprietario, con i millesimi per unità. */
function crit49Voce(array $s, string $nome, string $tipo, int $importo, string $tabella, array $millesimi): void
{
    $conto = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => $tipo, 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $t = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => $tabella, 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $t->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($millesimi as $codice => $valore) {
        DB::table('quote_tabella')->insert(['tabella_id' => $t->id, 'immobile_id' => $s['unita'][$codice]->id, 'valore' => (float) $valore, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function crit49Saldo(array $s, string $chi, string $codice, int $importo): void
{
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita'][$codice]->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
}

/** Il preventivo 2026 in 12 rate mensili dal 5 gennaio, approvato e generato con il motore vero. */
function crit49Piano(array $s, string $metodo): array
{
    $s['piano'] = PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);

    return $s;
}

/**
 * K2: «Spese generali» € 1.200,00 su «Proprietà» (A di Ugo 500, C di Bice 500) e «Proventi antenna», di tipo entrata, € 240,00 su
 * «Antenna» (A 500, box B di Bice 500); 12 rate, metodo «tutte le rate». Per rata: A 5000 − 1000 = 4000, C 5000, box −1000 (una
 * spesa negativa, riporto 0). Con `$saldoBox` (un credito di Bice sul box da un esercizio precedente) il box porta anche
 * `$saldoBox / 12` di riporto per rata.
 */
function crit49Entrata(int $saldoBox = 0): array
{
    $s = crit49Condominio();
    crit49Unita($s, 'A', 'ugo', 'Ugo Uno', 'Interno A');
    crit49Unita($s, 'C', 'bice', 'Bice Due', 'Interno C');
    crit49Unita($s, 'B', 'bice', 'Bice Due', 'Box B');
    crit49Voce($s, 'Spese generali', 'spesa', 120000, 'Proprietà', ['A' => 500, 'C' => 500]);
    crit49Voce($s, 'Proventi antenna', 'entrata', 24000, 'Antenna', ['A' => 500, 'B' => 500]);
    if ($saldoBox !== 0) {
        crit49Saldo($s, 'bice', 'B', $saldoBox);
    }

    return crit49Piano($s, 'tutte_rate');
}

/** K4 e LDA-1: metodo «rata zero», € 1.200,00 in 12 rate su «Proprietà» (A di Ugo 500, B di Bice 500), 5000 a testa per rata. */
function crit49RataZero(int $saldoUgo, int $saldoBice): array
{
    $s = crit49Condominio();
    crit49Unita($s, 'A', 'ugo', 'Ugo Uno', 'Interno A');
    crit49Unita($s, 'B', 'bice', 'Bice Due', 'Interno B');
    crit49Voce($s, 'Spese generali', 'spesa', 120000, 'Proprietà', ['A' => 500, 'B' => 500]);
    crit49Saldo($s, 'ugo', 'A', $saldoUgo);
    crit49Saldo($s, 'bice', 'B', $saldoBice);

    return crit49Piano($s, 'rata_zero');
}

function crit49Rata(array $s, int $n): Rata
{
    return Rata::where('piano_rate_id', $s['piano']->id)->where('numero_rata', $n)->firstOrFail();
}

function crit49Quota(array $s, string $chi, string $codice, int $n): RataQuote
{
    return RataQuote::where('rata_id', crit49Rata($s, $n)->id)->where('anagrafica_id', $s['persone'][$chi]->id)->where('immobile_id', $s['unita'][$codice]->id)->firstOrFail();
}

function crit49ContoId(array $s, string $ruolo): int
{
    return (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
}

/** I centesimi come li manda il modulo degli incassi («10.00»): la conversione la fa la Request, una volta sola. */
function crit49Euro(int $c): string
{
    return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
}

function crit49Emetti($test, array $s, array $numeri, string $il)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => crit49Rata($s, $n)->id, $numeri), 'data_emissione' => $il, 'invia_notifiche' => false,
    ]);
}

/**
 * «Usa credito» dalla rotta vera: `$chi` paga le quote `$su` ([id della quota => centesimi]) con `$credito` centesimi presi dalla
 * quota `$da`, più `$contanti`; la parte in più dichiarata è quella che il modulo calcola. Restituisce l'incasso padre.
 */
function crit49Compensa($test, array $s, string $chi, RataQuote $da, array $su, int $credito, string $il, int $contanti = 0): int
{
    $righe = [['rata_id' => $da->id, 'importo' => crit49Euro(-$credito)]];
    foreach ($su as $quotaId => $cent) {
        $righe[] = ['rata_id' => $quotaId, 'importo' => crit49Euro($cent)];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => crit49Euro($contanti),
        'descrizione' => 'Compensazione', 'eccedenza' => crit49Euro(max(0, $contanti + $credito - array_sum($su))),
        'dettaglio_pagamenti' => $righe,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** La compensazione (`storno_credito`) figlia dell'incasso. */
function crit49Figlia(int $incasso): int
{
    return (int) DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'storno_credito')->value('id');
}

/** Com'era scritta dalla 1.9 e dalla 1.10: prelievo e riaccredito della quota a credito su «Crediti verso condomini» (v1.10.0 StoreIncassoRateAction :402-403, :506-507). */
function crit49ComeLa110(array $s, int $compensazione, RataQuote $da): void
{
    DB::table('righe_scritture')->where('scrittura_id', $compensazione)->where('rata_id', $da->rata_id)->where('anagrafica_id', $da->anagrafica_id)
        ->where('conto_contabile_id', crit49ContoId($s, 'passate_gestioni'))->update(['conto_contabile_id' => crit49ContoId($s, 'crediti_condomini')]);
}

function crit49Rimborsa($test, array $s, string $chi, RataQuote $da, int $importo, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone'][$chi]]), [
        'rata_quote_id' => $da->id, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => $il, 'importo' => number_format($importo / 100, 2, ',', ''),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->max('id');
}

function crit49Storna($test, array $s, int $id)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $id]));
}

function crit49AnnullaEmissione($test, array $s, int $n)
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => crit49Rata($s, $n)->id]));
}

/** [Crediti verso condomini, Gestione rate, Passate gestioni]; con `$allaData` la fotografia a quella data. */
function crit49Sp(array $s, ?string $allaData = null): array
{
    $sp = app(\App\Services\Gestionale\StatoPatrimonialeService::class)->calcola($s['c'], null, $allaData);
    $v = collect([...$sp['attivo']['voci'], ...$sp['passivo']['voci']]);

    return array_map(fn ($r) => (int) $v->where('ruolo', $r)->sum('saldo'), ['crediti_condomini', 'gestione_rate', 'passate_gestioni']);
}

function crit49Mastrino(array $s, string $chi): int
{
    return (int) DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->where('scritture_contabili.condominio_id', $s['c']->id)->whereNull('scritture_contabili.deleted_at')
        ->where('conti_contabili.ruolo', 'crediti_condomini')->where('righe_scritture.anagrafica_id', $s['persone'][$chi]->id)
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
}

/** Le props della pagina dell'estratto conto di `$chi`. */
function crit49Estratto($test, array $s, string $chi): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))
        ->assertOk()->viewData('page')['props'];
}

/** La prop della card «Crediti da riallineare» nella pagina «Incassi rate» (null se non compare). */
function crit49Card($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

function crit49Comando($test, array $s): void
{
    $test->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])->assertExitCode(0);
}

/** Le righe di una scrittura come [ruolo del conto, verso, importo], in un ordine fisso (l'ordine di scrittura non conta). */
function crit49Righe(int $scritturaId): array
{
    return DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('righe_scritture.scrittura_id', $scritturaId)
        ->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo])
        ->sortBy(fn ($r) => sprintf('%s|%s|%012d', $r[0], $r[1], $r[2]))->values()->all();
}

/** Le scritture figlie di un'emissione, di tipo emissione (quelle che lo storno scrive per il credito restituito). */
function crit49FiglieEmissione(int $emissione): \Illuminate\Support\Collection
{
    return DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->orderBy('id')->pluck('id');
}

/** L'emissione della 1.10.0, riga per riga (v1.10.0 EmissioneRateController.php:121-268): salta le quote ≤ 0, segna «emessa» ogni rata richiesta. */
function crit49EmettiComeLa110(array $s, array $numeri, string $data): void
{
    $crediti = crit49ContoId($s, 'crediti_condomini');
    $gestione = crit49ContoId($s, 'gestione_rate');
    $passate = crit49ContoId($s, 'passate_gestioni');
    DB::transaction(function () use ($s, $numeri, $data, $crediti, $gestione, $passate) {
        foreach ($numeri as $n) {
            $rata = crit49Rata($s, $n)->load('rateQuote');
            if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) {
                continue;
            }
            $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['piano']->gestione_id,
                'data_registrazione' => now(), 'data_competenza' => $data, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
            $tg = 0;
            $tp = 0;
            foreach ($rata->rateQuote as $q) {
                $imp = (int) $q->importo;
                if ($imp <= 0) {
                    continue;                                                            // v1.10.0 :148
                }
                $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
                $g = (int) ($regole['importi']['quota_pura_gestione'] ?? $imp);
                $sc->righe()->create(['conto_contabile_id' => $crediti, 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id, 'rata_id' => $rata->id,
                    'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
                $q->update(['scrittura_contabile_id' => $sc->id]);
                $tg += $g;
                $tp += $imp - $g;
            }
            if ($tg + $tp > 0) {
                foreach ([[$gestione, $tg, 'Totale emissione '], [$passate, $tp, 'Riporto esercizi precedenti — ']] as [$conto, $t, $nota]) {
                    if ($t !== 0) {
                        $sc->righe()->create(['conto_contabile_id' => $conto, 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => $nota.$rata->descrizione]);
                    }
                }
            } else {
                $sc->forceDelete();                                                       // v1.10.0 :222
            }
        }
        Rata::whereIn('id', collect($numeri)->map(fn ($n) => crit49Rata($s, $n)->id))->update(['stato' => 'emessa']); // v1.10.0 :268
    });
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// K2 — la voce di tipo «entrata»: il credito del box è dell'anno, non un riporto
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('K2 — la compensazione con il credito di una voce di entrata, con la sua rata in bozza, esce da «Crediti verso condomini»; emessa poi la rata, Bice è a 8000 e «Passate gestioni» non si muove', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    // Lo scenario è quello del rilievo: il box è una spesa negativa di 1000 per rata, senza riporto.
    expect([(int) crit49Quota($s, 'ugo', 'A', 1)->importo, (int) crit49Quota($s, 'bice', 'C', 1)->importo, (int) $box1->importo])->toBe([4000, 5000, -1000])
        ->and($box1->componenti())->toBe(['spesa' => -1000, 'riporto' => 0, 'dettaglio' => true]);

    crit49Emetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    // Bice usa i 1000 del box della rata 1, ancora in bozza, sulla rata 2 di C.
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 1000], 1000, '2026-03-10');

    // Il prelievo sta fra i crediti verso condomini, come un incasso su una quota a debito in bozza: DARE «Crediti» 1000 sul box
    // della rata 1, AVERE «Crediti» 1000 su C della rata 2. Oggi esce da «Passate gestioni», dove quel credito non è mai entrato.
    expect(crit49Righe(crit49Figlia($incasso)))->toBe([
        ['crediti_condomini', 'avere', 1000],
        ['crediti_condomini', 'dare', 1000],
    ]);

    crit49Emetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano, dopo le due emissioni. Bice: rata 1 C 5000 − box 1000; rata 2 C 5000 − box 1000; la compensazione +1000 − 1000 = 0.
    // Totale 8000 (le due rate di C meno i due crediti del box). Ugo: 4000 + 4000 = 8000. «Crediti» 16000. «Gestione rate»: per rata
    // A 4000 + C 5000 − box 1000 = 8000, due rate 16000. «Passate gestioni» 0: nessun riporto in questo piano.
    // Oggi: Bice 7000 (il credito del box contato due volte: il prelievo su «Passate» e l'AVERE intero dell'emissione), «Crediti»
    // 15000, «Passate» −1000 (un DARE senza contropartita).
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([8000, 8000])
        ->and(crit49Sp($s))->toBe([16000, 16000, 0])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(0);
});

it('K2 — il riaccredito del credito di entrata non usato torna dove è uscito il prelievo, fra i crediti verso condomini', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    crit49Emetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    // Rata 2 di C (5000): € 45,00 in contanti più € 10,00 del credito del box della rata 1. Del credito servono 500; gli altri 500
    // tornano al box (il riaccredito).
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 5000], 1000, '2026-03-10', contanti: 4500);

    // La compensazione: prelievo DARE «Crediti» 1000 (box, rata 1), chiusura di C AVERE «Crediti» 500, riaccredito AVERE «Crediti»
    // 500 (box, rata 1). Oggi prelievo e riaccredito vanno su «Passate gestioni».
    expect(crit49Righe(crit49Figlia($incasso)))->toBe([
        ['crediti_condomini', 'avere', 500],
        ['crediti_condomini', 'avere', 500],
        ['crediti_condomini', 'dare', 1000],
    ]);

    crit49Emetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano. Bice: C 5000 + 5000, box −1000 − 1000, contanti −4500, compensazione +1000 − 500 − 500 = 0 → 3500 (deve la rata 1 di
    // C, 5000, meno i 500 di credito rimasti al box della rata 1 e i 1000 del box della rata 2). Ugo 8000. «Crediti» 11500,
    // «Gestione rate» 16000, «Passate» 0. Credito del box della rata 1: 1000 − 1000 + 500 = 500.
    // Oggi: «Passate» −500 (DARE 1000 − AVERE 500) e Bice 3000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([3500, 8000])
        ->and(crit49Sp($s))->toBe([11500, 16000, 0])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(500);
});

it('K2 — il rimborso del credito di una voce di entrata, con la rata in bozza, rovescia «Crediti verso condomini»; emessa la rata, «Passate gestioni» resta a zero', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    $rimborso = crit49Rimborsa($this, $s, 'bice', $box1, 1000, '2026-02-20');

    // DARE «Crediti verso condomini» 1000 a nome di Bice sul box della rata 1, AVERE banca 1000. Oggi la contropartita è «Passate
    // gestioni», come per un credito riportato.
    expect(crit49Righe($rimborso))->toBe([
        ['banca', 'avere', 1000],
        ['crediti_condomini', 'dare', 1000],
    ]);

    crit49Emetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();

    // A mano. Bice: C 5000 − box 1000 + rimborso 1000 = 5000 (deve la rata 1 di C; il credito del box l'ha ricevuto in denaro).
    // Ugo 4000. «Crediti» 9000, «Gestione rate» 8000, «Passate» 0. Oggi: Bice 4000, «Passate» −1000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([5000, 4000])
        ->and(crit49Sp($s))->toBe([9000, 8000, 0])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(0);
});

it('K2 — il modulo del rimborso e la nota della scrittura non chiamano «credito riportato da un esercizio precedente» il credito di una voce di entrata', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);

    // Il modulo «Rimborsa il credito» dell'estratto conto di Bice: il credito del box non viene dai saldi (la quota non porta
    // riporto) e sta fra i crediti verso condomini. Oggi: origine «saldi» («da saldi», «Credito riportato da un esercizio
    // precedente, con la sua rata non ancora a giornale: in contabilità esce da «Passate gestioni»»).
    $nelModulo = collect(crit49Estratto($this, $s, 'bice')['rimborso']['quote'])->firstWhere('id', $box1->id);
    expect($nelModulo)->not->toBeNull()
        ->and($nelModulo['conto'])->toBe('crediti_condomini')
        ->and($nelModulo['origine'])->not->toBe('saldi');

    $rimborso = crit49Rimborsa($this, $s, 'bice', $box1, 1000, '2026-02-20');
    $nota = (string) DB::table('scritture_contabili')->where('id', $rimborso)->value('note');
    expect($nota)->not->toContain('riportato da un esercizio precedente')
        ->and($nota)->not->toContain('Passate gestioni');
});

it('K2 — lo storno, dopo l\'emissione, della compensazione con il credito di entrata è il solo specchio: niente figlia dell\'emissione, Bice torna a 8000 e «Passate gestioni» a zero', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    crit49Emetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 1000], 1000, '2026-03-10');
    crit49Emetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();
    $emissione1 = (int) crit49Quota($s, 'bice', 'C', 1)->scrittura_contabile_id;

    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();

    // A mano. Senza la compensazione: Bice C 5000 + 5000 − box 1000 − 1000 = 8000; Ugo 8000; «Crediti» 16000, «Gestione rate»
    // 16000, «Passate» 0. Il credito del box torna intero (1000), la rata 2 di C torna da pagare. Dal credito di un'entrata non è
    // uscito niente da «Passate gestioni»: lo storno non ha niente da riportare fra i crediti con una figlia dell'emissione.
    // Oggi: lo specchio rimette 1000 su «Passate» e la figlia dell'emissione li riprende: Bice 7000, «Passate» −1000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([8000, 8000])
        ->and(crit49Sp($s))->toBe([16000, 16000, 0])
        ->and(crit49FiglieEmissione($emissione1)->all())->toBe([])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(1000)
        ->and((int) crit49Quota($s, 'bice', 'C', 2)->importo_pagato)->toBe(0);
});

it('K2 — dati della 1.10 interi (rate 1 e 2 emesse senza il box, compensazione su «Crediti»): il comando non la prende per DC1, scrive solo le quote del box, e Bice arriva a 8000', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    crit49EmettiComeLa110($s, [1, 2], '2026-02-28');
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 1000], 1000, '2026-03-10');
    crit49ComeLa110($s, crit49Figlia($incasso), $box1);

    // Come li ha scritti la 1.10. Bice: C 5000 + 5000, compensazione DARE 1000 (box, rata 1) − AVERE 1000 (C, rata 2) = 10000.
    // Ugo 8000. «Crediti» 18000; «Gestione rate» 9000 + 9000 = 18000 (il box saltato); «Passate» 0.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([10000, 8000])
        ->and(crit49Sp($s))->toBe([18000, 18000, 0]);

    // La compensazione della 1.10 è giusta: il credito del box non è un riporto, e stava fra i crediti verso condomini. Non è DC1.
    // Mancano solo le quote del box, che la 1.10 ha lasciato fuori dalle rate 1 e 2 (DC2-b). Oggi la card propone di spostare
    // € 10,00 «da «Crediti verso condomini» a «Passate gestioni»».
    $card = crit49Card($this, $s);
    expect($card)->not->toBeNull()
        ->and($card['compensazioni'])->toBe([])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->pluck('numero_rata')->sort()->values()->all())->toBe([1, 2]);

    crit49Comando($this, $s);

    // A mano. Il comando scrive per ciascuna rata il box con le regole della 71: AVERE «Crediti» 1000 a nome di Bice, DARE
    // «Gestione rate» 1000. Bice 10000 − 2000 = 8000; Ugo 8000; «Crediti» 16000; «Gestione rate» 16000; «Passate» 0.
    // Oggi: la rettifica di DC1 (AVERE «Crediti» 1000 / DARE «Passate» 1000) più le quote del box: Bice 7000, «Passate» −1000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([8000, 8000])
        ->and(crit49Sp($s))->toBe([16000, 16000, 0])
        ->and(crit49Card($this, $s))->toBeNull();
});

it('K2 — dati della 1.10 con la rata del credito ancora in bozza: il comando non tocca la compensazione, e l\'emissione della rata 1 con la .49 porta Bice a 8000', function () {
    $s = crit49Entrata();
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    crit49EmettiComeLa110($s, [2], '2026-02-28');
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 1000], 1000, '2026-03-10');
    crit49ComeLa110($s, crit49Figlia($incasso), $box1);
    // Bice: C 5000 + 1000 − 1000 = 5000; Ugo 4000; «Crediti» 9000, «Gestione rate» 9000, «Passate» 0.
    expect(crit49Sp($s))->toBe([9000, 9000, 0]);

    crit49Comando($this, $s);

    // Il comando scrive solo il box della rata 2 (DC2-b): AVERE «Crediti» 1000, DARE «Gestione rate» 1000. Bice 4000; «Crediti»
    // 8000; «Gestione rate» 8000; «Passate» 0. Oggi scrive anche la rettifica di DC1: Bice 3000, «Passate» −1000.
    expect(crit49Mastrino($s, 'bice'))->toBe(4000)
        ->and(crit49Sp($s))->toBe([8000, 8000, 0]);

    crit49Emetti($this, $s, [1], '2026-03-20')->assertSessionHasNoErrors();

    // A mano. Rata 1: C DARE 5000, box AVERE 1000, A DARE 4000, «Gestione rate» AVERE 8000. Bice 4000 + 5000 − 1000 = 8000; Ugo
    // 8000; «Crediti» 16000; «Gestione rate» 16000; «Passate» 0. Oggi: Bice 7000, «Passate» −1000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([8000, 8000])
        ->and(crit49Sp($s))->toBe([16000, 16000, 0]);
});

it('K2 — una quota del box con la spesa negativa e un riporto (−1000 e −500) usata in parte: da «Passate gestioni» esce al più il riporto, e dopo l\'emissione il credito si conta una volta', function () {
    $s = crit49Entrata(saldoBox: -6000);
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    // Il box porta per rata −1000 di entrata e −500 di credito riportato (−6000 in 12 rate).
    expect((int) $box1->importo)->toBe(-1500)
        ->and($box1->componenti())->toBe(['spesa' => -1000, 'riporto' => -500, 'dettaglio' => true]);

    crit49Emetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = crit49Compensa($this, $s, 'bice', $box1, [crit49Quota($s, 'bice', 'C', 2)->id => 1200], 1200, '2026-03-10');

    // Dei 1200 presi dal box della rata 1 solo il riporto (500) stava fra le «Passate gestioni»: da lì ne esce al più quello, il
    // resto esce da «Crediti verso condomini». Oggi escono tutti e 1200 da «Passate».
    $daPassate = (int) DB::table('righe_scritture')->where('scrittura_id', crit49Figlia($incasso))->where('conto_contabile_id', crit49ContoId($s, 'passate_gestioni'))
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END), 0) AS n")->value('n');
    expect($daPassate)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(500);

    crit49Emetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano, comunque si divida il prelievo fra i due conti. Bice deve C 5000 (rata 1) + C 3800 (rata 2, 5000 − 1200 di credito)
    // = 8800 e ha di credito 300 sul box della rata 1 (1500 − 1200) e 1500 su quello della rata 2: 8800 − 1800 = 7000. Ugo 8000.
    // «Crediti» 15000. «Gestione rate»: 8000 per rata, 16000. «Passate gestioni»: i due riporti usati, 500 + 500 di DARE → −1000.
    // Oggi: Bice 6300 e «Passate» −1700 (il prelievo intero su «Passate», poi l'AVERE dell'entrata intero all'emissione).
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([7000, 8000])
        ->and(crit49Sp($s))->toBe([15000, 16000, -1000])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(300);
});

it('K2 — il rimborso della quota mista del box (−1000 e −500) oltre il riporto, con la rata in bozza: da «Passate gestioni» esce al più il riporto, il resto rovescia «Crediti verso condomini», e dopo l\'emissione il credito si conta una volta', function () {
    $s = crit49Entrata(saldoBox: -6000);
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    $rimborso = crit49Rimborsa($this, $s, 'bice', $box1, 1200, '2026-02-20');

    // Dei 1200 rimborsati solo il riporto (500) stava fra le «Passate gestioni»; la spesa negativa (1000) sta fra i crediti verso
    // condomini. Comunque si divida: in DARE «Passate» al più 500, «Crediti» al più 1000, in tutto 1200 contro la banca.
    // Oggi tutti e 1200 su «Passate».
    $dare = fn (string $ruolo) => (int) DB::table('righe_scritture')->where('scrittura_id', $rimborso)->where('conto_contabile_id', crit49ContoId($s, $ruolo))
        ->where('tipo_riga', 'dare')->sum('importo');
    expect([$dare('passate_gestioni') + $dare('crediti_condomini'), $dare('passate_gestioni') <= 500, $dare('crediti_condomini') <= 1000])->toBe([1200, true, true]);

    crit49Emetti($this, $s, [1], '2026-02-28')->assertSessionHasNoErrors();

    // A mano, comunque si divida il rimborso. Bice deve C 5000 della rata 1 e ha ancora 300 di credito sul box (1500 − 1200): 4700.
    // Ugo 4000. «Crediti» 8700, «Gestione rate» 8000 (A 4000 + C 5000 − box 1000), «Passate gestioni» il riporto del box, 500 di
    // DARE → −500. Oggi: Bice 4000 e «Passate» −1200 (il rimborso intero su «Passate», poi all'emissione l'AVERE della sola spesa).
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([4700, 4000])
        ->and(crit49Sp($s))->toBe([8700, 8000, -500])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(300);
});

it('K2 — la stessa quota mista usata due volte, la seconda con contanti più credito: il riaccredito del credito non usato torna sui conti del prelievo della sua scrittura, e nessuno dei due conti va in negativo per il box', function () {
    $s = crit49Entrata(saldoBox: -6000);
    $box1 = crit49Quota($s, 'bice', 'B', 1);
    $c2 = crit49Quota($s, 'bice', 'C', 2);
    crit49Emetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    // Prima € 5,00 del box della rata 1 sulla rata 2 di C. Poi la rata 2 di C, rimasta a 4500: € 40,00 in contanti più € 10,00 del
    // box; del credito servono 500, gli altri 500 tornano al box (il riaccredito).
    $prima = crit49Figlia(crit49Compensa($this, $s, 'bice', $box1, [$c2->id => 500], 500, '2026-03-05'));
    $seconda = crit49Figlia(crit49Compensa($this, $s, 'bice', $box1, [$c2->id => 4500], 1000, '2026-03-10', contanti: 4000));
    $netto = fn (int $comp, string $ruolo) => (int) DB::table('righe_scritture')->where('scrittura_id', $comp)->where('conto_contabile_id', crit49ContoId($s, $ruolo))
        ->where('rata_id', $box1->rata_id)->where('immobile_id', $box1->immobile_id)
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END), 0) AS n")->value('n');

    // Il box della rata 1 ha dato 500 netti per scrittura. In ciascuna il riaccredito torna sui conti da cui è uscito il prelievo di
    // quella scrittura, quindi nessun netto è sotto zero; in tutto, da «Passate gestioni» al più il riporto (500), da «Crediti verso
    // condomini» al più la spesa negativa (1000). Il caso che conta è la seconda scrittura quando la prima ha già preso tutto il riporto
    // da «Passate» (se il prelievo comincia da lì): il prelievo esce tutto da «Crediti», e un riaccredito deciso dal conto della quota
    // invece che dal prelievo tornerebbe su «Passate», lasciando lì un AVERE netto di 500 che quella scrittura non ha mai prelevato.
    // Oggi: la prima preleva 500 e la seconda 1000 − 500, tutti su «Passate» (in tutto 1000, il doppio del riporto).
    $p1 = $netto($prima, 'passate_gestioni');
    $c1 = $netto($prima, 'crediti_condomini');
    $p2 = $netto($seconda, 'passate_gestioni');
    $c2n = $netto($seconda, 'crediti_condomini');
    expect([$p1 + $c1, $p2 + $c2n])->toBe([500, 500])
        ->and([$p1 >= 0, $c1 >= 0, $p2 >= 0, $c2n >= 0])->toBe([true, true, true, true])
        ->and([$p1 + $p2 <= 500, $c1 + $c2n <= 1000])->toBe([true, true]);

    crit49Emetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano, comunque si dividano i prelievi. Bice deve C 5000 (rata 1); la rata 2 di C è pagata (500 + 500 di credito e 4000 in
    // contanti); ha di credito 500 sul box della rata 1 (1500 − 500 − 500) e 1500 su quello della rata 2: 5000 − 2000 = 3000. Ugo
    // 8000. «Crediti» 11000, «Gestione rate» 16000, «Passate gestioni» i due riporti, 1000 di DARE → −1000.
    expect([crit49Mastrino($s, 'bice'), crit49Mastrino($s, 'ugo')])->toBe([3000, 8000])
        ->and(crit49Sp($s))->toBe([11000, 16000, -1000])
        ->and((int) $box1->fresh()->credito_disponibile)->toBe(500)
        ->and((int) $c2->fresh()->importo_pagato)->toBe(5000);
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// K4 e LDA-1 — lo storno di un credito usato tutto prima dell'emissione: la rata è a giornale, la quota no
// ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('LDA-1 — la compensazione intera presa con la rata 0 in bozza, stornata dopo l\'emissione: una figlia dell\'emissione riporta il credito fra i crediti, e stato patrimoniale, mastrino ed estratto di Ugo dicono lo stesso', function () {
    // Ugo −10000 (un credito), Bice +6000. Il 10/01, con le rate in bozza, Ugo usa tutto il credito della rata 0 sulle rate 1 e 2.
    $s = crit49RataZero(-10000, 6000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    $incasso = crit49Compensa($this, $s, 'ugo', $q0, [crit49Quota($s, 'ugo', 'A', 1)->id => 5000, crit49Quota($s, 'ugo', 'A', 2)->id => 5000], 10000, '2026-01-10');
    crit49Emetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Dopo l'emissione (giusto anche oggi). «Crediti»: Bice 6000 + 5000 + 5000 = 16000, Ugo 5000 + 5000 − 10000 = 0. «Gestione
    // rate» 20000. «Passate»: DARE 10000 (il prelievo) − AVERE 6000 (il riporto di Bice) → −4000. La quota di Ugo sulla rata 0
    // non ha righe (il credito è già uscito tutto) e resta senza scrittura; la rata 0 è a giornale con Bice.
    expect(crit49Sp($s))->toBe([16000, 20000, -4000]);
    $emissione0 = (int) crit49Quota($s, 'bice', 'B', 0)->scrittura_contabile_id;

    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();

    // A mano. Lo specchio della compensazione: AVERE «Passate» 10000, DARE «Crediti» 5000 + 5000 (le rate 1 e 2 di Ugo di nuovo da
    // pagare). La figlia dell'emissione della rata 0 (decisione 76.3): AVERE «Crediti» 10000 a nome di Ugo sulla rata 0, DARE
    // «Passate» 10000. «Crediti» 16000 + 10000 − 10000 = 16000; «Passate» −4000 + 10000 − 10000 = −4000. Ugo: mastrino 0 + 10000
    // − 10000 = 0, estratto −10000 + 10000 = 0. Bice: 16000 e 16000.
    // Oggi niente figlia: [26000, 20000, 6000], e il mastrino di Ugo (10000) non dice quello che dice il suo estratto (0).
    expect(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0])
        ->and([crit49Mastrino($s, 'bice'), (int) crit49Estratto($this, $s, 'bice')['stats']['saldo_raw']])->toBe([16000, 16000]);
    $figlie = crit49FiglieEmissione($emissione0);
    expect($figlie)->toHaveCount(1)
        ->and(crit49Righe((int) $figlie[0]))->toBe([['crediti_condomini', 'avere', 10000], ['passate_gestioni', 'dare', 10000]]);
    // La quota di Ugo è ora a giornale (legata), il suo credito sta fra i crediti verso condomini, e la card non si accende: questi
    // dati li ha scritti la .49, non la 1.9 né la 1.10.
    $q0 = $q0->fresh();
    expect($q0->scrittura_contabile_id)->not->toBeNull()
        ->and($q0->ruoloDelCredito())->toBe('crediti_condomini')
        ->and((int) $q0->credito_disponibile)->toBe(10000)
        ->and(crit49Card($this, $s))->toBeNull();
});

it('LDA-1 — dopo quello storno il credito si riusa da «Crediti verso condomini», come dice la guida degli incassi, e lo stato patrimoniale non si sposta', function () {
    $s = crit49RataZero(-10000, 6000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    $incasso = crit49Compensa($this, $s, 'ugo', $q0, [crit49Quota($s, 'ugo', 'A', 1)->id => 5000, crit49Quota($s, 'ugo', 'A', 2)->id => 5000], 10000, '2026-01-10');
    crit49Emetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();

    // Ugo usa di nuovo metà del credito della rata 0, ormai a giornale, sulla rata 1.
    $nuovo = crit49Compensa($this, $s, 'ugo', $q0->fresh(), [crit49Quota($s, 'ugo', 'A', 1)->id => 5000], 5000, '2026-10-11');

    // La guida: «dopo l'emissione lo preleva da «Crediti verso condomini»». DARE «Crediti» 5000 sulla rata 0, AVERE «Crediti» 5000
    // sulla rata 1. A mano: [16000, 20000, −4000] resta com'è; Ugo: mastrino 0, estratto −10000 + 10000 = 0 (deve 5000 della rata 2
    // e ha 5000 di credito). Oggi il prelievo esce da «Passate gestioni»: [21000, 20000, 1000].
    expect(crit49Righe(crit49Figlia($nuovo)))->toBe([['crediti_condomini', 'avere', 5000], ['crediti_condomini', 'dare', 5000]])
        ->and(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0]);
});

it('LDA-1 — dopo quello storno l\'annullamento dell\'emissione della rata 0 porta via anche la figlia, e la nuova emissione riporta il credito intero', function () {
    $s = crit49RataZero(-10000, 6000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    $incasso = crit49Compensa($this, $s, 'ugo', $q0, [crit49Quota($s, 'ugo', 'A', 1)->id => 5000, crit49Quota($s, 'ugo', 'A', 2)->id => 5000], 10000, '2026-01-10');
    crit49Emetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $emissione0 = (int) crit49Quota($s, 'bice', 'B', 0)->scrittura_contabile_id;
    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();
    // La figlia c'è (oggi no: la quota di Ugo è senza scrittura e lo storno la salta).
    expect(crit49FiglieEmissione($emissione0))->toHaveCount(1);

    $r = crit49AnnullaEmissione($this, $s, 0);
    expect($r->getSession()->get('message')['type'])->toBe('success');

    // A mano. Se ne vanno l'emissione di Bice (DARE «Crediti» 6000 / AVERE «Passate» 6000) e la figlia (AVERE «Crediti» 10000 /
    // DARE «Passate» 10000). «Crediti» 16000 − 6000 + 10000 = 20000 (le rate 1 e 2, di nuovo da pagare); «Passate» −4000 − 6000 +
    // 10000 = 0; la rata 0 in bozza, nessuna quota legata, nessuna scrittura d'emissione rimasta sulla rata 0, la card spenta.
    $rata0 = crit49Rata($s, 0);
    expect(crit49Sp($s))->toBe([20000, 20000, 0])
        ->and($rata0->stato)->toBe('bozza')
        ->and(RataQuote::where('rata_id', $rata0->id)->whereNotNull('scrittura_contabile_id')->count())->toBe(0)
        ->and(DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
            ->where('righe_scritture.rata_id', $rata0->id)->where('scritture_contabili.tipo_movimento', 'emissione_rata')->count())->toBe(0)
        ->and(crit49Card($this, $s))->toBeNull();

    crit49Emetti($this, $s, [0], '2026-10-11')->assertSessionHasNoErrors();

    // A mano. Da «Passate» il credito di Ugo è uscito e tornato (prelievo e specchio): l'emissione lo porta intero. Ugo AVERE
    // 10000, Bice DARE 6000, «Passate» DARE 4000. [16000, 20000, −4000]; Ugo mastrino 0 ed estratto 0; la quota di Ugo legata.
    expect(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0])
        ->and($q0->fresh()->scrittura_contabile_id)->not->toBeNull()
        ->and(crit49Card($this, $s))->toBeNull();
});

it('LDA-1 — il rimborso intero del credito con la rata 0 in bozza, stornato dopo l\'emissione: il credito torna fra i crediti verso condomini, e il modulo del rimborso non dice più «con la sua rata non ancora a giornale»', function () {
    $s = crit49RataZero(-10000, 6000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    $rimborso = crit49Rimborsa($this, $s, 'ugo', $q0, 10000, '2026-01-20');
    crit49Emetti($this, $s, [0, 1, 2], '2026-02-28')->assertSessionHasNoErrors();
    // Il rimborso è uscito da «Passate gestioni» (DARE 10000 / AVERE banca 10000); la quota di Ugo sulla rata 0 non ha più niente
    // da portare. «Crediti»: Bice 16000 + Ugo 10000 (rate 1 e 2) = 26000; «Passate» −10000 + 6000 = −4000.
    expect(crit49Sp($s))->toBe([26000, 20000, -4000]);
    $emissione0 = (int) crit49Quota($s, 'bice', 'B', 0)->scrittura_contabile_id;

    crit49Storna($this, $s, $rimborso)->assertSessionHasNoErrors();

    // A mano. Specchio: AVERE «Passate» 10000, DARE banca 10000. Figlia dell'emissione: AVERE «Crediti» 10000 a nome di Ugo sulla
    // rata 0, DARE «Passate» 10000. «Crediti» 26000 − 10000 = 16000; «Passate» −4000; banca 20000 − 10000 + 10000 = 20000. Ugo:
    // mastrino 10000 − 10000 = 0, estratto 0 (deve le rate 1 e 2 e ha di nuovo il credito). Oggi: [26000, 20000, 6000].
    expect(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0])
        ->and(app(\App\Services\Gestionale\SaldoCassaService::class)->saldoDisponibile($s['cassa']))->toBe(20000)
        ->and(crit49FiglieEmissione($emissione0))->toHaveCount(1)
        ->and(crit49Card($this, $s))->toBeNull();

    // Il modulo del rimborso: la rata 0 è a giornale, il credito di Ugo sta fra i crediti verso condomini. Oggi dice
    // «Credito riportato da un esercizio precedente, con la sua rata non ancora a giornale: in contabilità esce da «Passate gestioni»».
    $nelModulo = collect(crit49Estratto($this, $s, 'ugo')['rimborso']['quote'])->firstWhere('id', $q0->id);
    expect($nelModulo['credito_cents'])->toBe(10000)
        ->and($nelModulo['conto'])->toBe('crediti_condomini');
});

it('LDA-1 — dati della 1.10 riallineati dal comando e poi stornati: lo storno porta il credito fra i crediti con la figlia dell\'emissione, e la card non ricompare', function () {
    $s = crit49RataZero(-10000, 6000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    // Come il condominio 16 (R5 del comando): le rate 0, 1 e 2 emesse dalla 1.10 (la quota di Ugo saltata), poi Ugo usa il credito
    // della rata 0 sulle rate 1 e 2 e la 1.10 lo preleva da «Crediti».
    crit49EmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $incasso = crit49Compensa($this, $s, 'ugo', $q0, [crit49Quota($s, 'ugo', 'A', 1)->id => 5000, crit49Quota($s, 'ugo', 'A', 2)->id => 5000], 10000, '2026-03-10');
    crit49ComeLa110($s, crit49Figlia($incasso), $q0);
    expect(crit49Sp($s))->toBe([26000, 20000, 6000]);
    crit49Comando($this, $s);
    // Il comando scrive la rettifica di DC1 (AVERE «Crediti» 10000 / DARE «Passate» 10000) e lascia la quota di Ugo senza
    // scrittura: il suo credito è già uscito tutto. [16000, 20000, −4000], card spenta.
    expect(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and(crit49Card($this, $s))->toBeNull();
    $emissione0 = (int) crit49Quota($s, 'bice', 'B', 0)->scrittura_contabile_id;

    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();

    // A mano. Gli specchi della compensazione (AVERE «Crediti» 10000 sulla rata 0, DARE 5000 + 5000) e della rettifica (DARE
    // «Crediti» 10000, AVERE «Passate» 10000), più la figlia dell'emissione della rata 0 (AVERE «Crediti» 10000 a nome di Ugo, DARE
    // «Passate» 10000). «Crediti» 16000 + 10000 − 10000 = 16000; «Passate» −4000 + 10000 − 10000 = −4000; Ugo mastrino 0 ed
    // estratto 0. Oggi niente figlia: [26000, 20000, 6000], e la card si riaccende chiedendo un'altra emissione retrodatata.
    expect(crit49Sp($s))->toBe([16000, 20000, -4000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0])
        ->and(crit49FiglieEmissione($emissione0))->toHaveCount(1)
        ->and(crit49Card($this, $s))->toBeNull();
});

it('K4 — rate 1–4 emesse, poi il credito della rata 0 (20000) usato tutto, poi la rata 0 emessa e la compensazione stornata: il comando non ha niente da fare e la fotografia al 30/06 non cambia', function () {
    // «Rata zero»: Ugo −20000 (A), Bice +5000 (B), 5000 a testa per rata.
    $s = crit49RataZero(-20000, 5000);
    $q0 = crit49Quota($s, 'ugo', 'A', 0);
    crit49Emetti($this, $s, [1, 2, 3, 4], '2026-02-28')->assertSessionHasNoErrors();
    $su = [];
    foreach ([1, 2, 3, 4] as $n) {
        $su[crit49Quota($s, 'ugo', 'A', $n)->id] = 5000;
    }
    $incasso = crit49Compensa($this, $s, 'ugo', $q0, $su, 20000, '2026-03-10');
    crit49Emetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    // Prima dello storno (giusto anche oggi). «Crediti»: Bice 20000 + 5000 = 25000, Ugo 20000 − 20000 = 0. «Gestione rate» 40000.
    // «Passate»: DARE 20000 − AVERE 5000 → −15000. La card è spenta.
    expect(crit49Sp($s))->toBe([25000, 40000, -15000])
        ->and(crit49Card($this, $s))->toBeNull();
    $emissione0 = (int) crit49Quota($s, 'bice', 'B', 0)->scrittura_contabile_id;

    crit49Storna($this, $s, $incasso)->assertSessionHasNoErrors();

    // A mano. Specchio: AVERE «Passate» 20000, DARE «Crediti» 4 × 5000 a nome di Ugo. Figlia dell'emissione, datata 11/10:
    // AVERE «Crediti» 20000 a nome di Ugo sulla rata 0, DARE «Passate» 20000. «Crediti» 25000 + 20000 − 20000 = 25000; «Passate»
    // −15000 + 20000 − 20000 = −15000. Ugo: mastrino 0, estratto −20000 + 20000 = 0. Bice 25000.
    // Oggi: «Crediti» 45000 (Ugo 20000, le rate 1–4 di nuovo da pagare senza il credito che le paga), «Passate» +5000.
    expect(crit49Sp($s))->toBe([25000, 40000, -15000])
        ->and([crit49Mastrino($s, 'ugo'), (int) crit49Estratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 0])
        ->and(crit49Mastrino($s, 'bice'))->toBe(25000)
        ->and(crit49FiglieEmissione($emissione0))->toHaveCount(1)
        ->and(crit49Card($this, $s))->toBeNull();

    // La card resta spenta, e il comando non scrive niente. Oggi scrive un'emissione datata 15/03 (AVERE «Crediti» 20000 / DARE
    // «Passate» 20000): la fotografia al 30/06 passa da 25000 a 5000 di crediti verso condomini, per uno storno dell'11/10.
    $prima = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count();
    crit49Comando($this, $s);
    expect((int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count())->toBe($prima)
        ->and(crit49Sp($s, '2026-06-30')[0])->toBe(25000)
        ->and(crit49Sp($s))->toBe([25000, 40000, -15000]);
});
