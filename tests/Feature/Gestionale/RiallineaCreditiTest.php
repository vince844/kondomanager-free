<?php

/**
 * 1.11.0-beta.49, voce P9 del verbale (`docs/piano_esecutivo_beta49.md`), decisioni 75.1 e 76.1 del registro
 * (`docs/subentro_e_competenza_temporale.md`): il comando `kondomanager:riallinea-crediti` e la card «Crediti da riallineare»
 * nella pagina «Incassi rate».
 *
 * Che cosa fissano questi test:
 * - il comando scrive solo due cose (76.1): la rettifica di DC1 — una compensazione che ha preso il credito da una quota a
 *   credito ancora senza scrittura e lo ha scritto su «Crediti verso condomini» invece che su «Passate gestioni», riconosciuta
 *   dalla posizione (la quota è ancora senza scrittura e su «Crediti» resta un netto aperto) — e la quota a credito rimasta fuori
 *   da una rata mista già a giornale (DC2-b), con le regole dell'emissione nuova: il credito già uscito si legge dal giornale
 *   (contrasto 1 del critico), una scrittura `emissione_rata` legata alla quota, nella data, nell'esercizio e nella gestione
 *   della scrittura d'emissione che la rata ha già;
 * - le rate di soli crediti che la 1.9 e la 1.10 hanno segnato «emesse» senza righe il comando non le tocca: la card le elenca
 *   con il collegamento alla pagina del piano, e le emette l'amministratore con la data e l'avviso che sceglie (76.1); non quelle
 *   che l'emissione non emetterebbe (rilievo LDA-3 della Fase 1-bis: il credito già uscito tutto, o le quote senza il dettaglio);
 * - la rettifica di DC1 è figlia della compensazione, e lo storno dell'incasso la rovescia insieme a lei (contrasto 3);
 * - con l'esercizio chiuso il comando non scrive e lo dice (verbale, P9); la conferma della card non risponde 404 (sonde S3 e S4
 *   dello scettico); le voci che restano come sono la card le mostra in sola lettura (76.3 ritoccata e rilievo LDA-8, in
 *   `CreditiRevisioneEmissioneTest`);
 * - restano fuori le quote gemelle (decisione 25), il W1 (75.2), le quote senza il dettaglio del calcolo (contrasto 4);
 * - i promemoria che la 1.9 ha pubblicato ai condòmini non si toccano (sonda S5);
 * - il capolinea: lo stato patrimoniale (anche dalla sua pagina), l'estratto conto a video e nel PDF, la previsione di cassa,
 *   la pagina della scrittura.
 *
 * I DATI DELLA 1.10. Si costruiscono come li scriveva la 1.10.0, verificato su `git show v1.10.0:<file>`:
 * - l'emissione rifà riga per riga il ciclo di `v1.10.0:app/Http/Controllers/Gestionale/PianiRate/EmissioneRateController.php`
 *   (:121-268): salta le quote ≤ 0 («if ($importoQuota <= 0) continue;»), scrive in DARE su «Crediti» l'importo intero della
 *   quota, chiude su «Gestione rate» la `quota_pura_gestione` e su «Passate gestioni» la differenza, cancella con `forceDelete()`
 *   la testata rimasta senza righe e segna «emessa» ogni rata richiesta (`Rata::whereIn('id', $request->rate_ids)->update(...)`),
 *   anche quella senza righe. I promemoria del portale non contano per i numeri e qui non si rifanno, tranne dove sono il punto
 *   (la 1.9, sotto);
 * - la compensazione («Usa credito») è quella dell'azione di oggi, poi le righe del prelievo e del riaccredito della quota a
 *   credito si riportano su «Crediti verso condomini», come in `v1.10.0:app/Actions/Gestionale/Movimenti/StoreIncassoRateAction.php`
 *   (:402-403 e :506-507, «'conto_contabile_id' => $contoCrediti->id,»). Sul codice di oggi il prelievo va già su «Crediti» e lo
 *   spostamento non trova niente; sul codice nuovo (P2) va su «Passate» e lo spostamento lo riporta alla forma della 1.10.
 *   Struttura identica: l'incasso padre senza righe di cassa (importo 0) e la figlia `storno_credito`.
 * - la 1.9 (`refs/tags/v1.9.0`, :86-178) per una rata di soli crediti lasciava la testata vuota, aggiornava i promemoria dei
 *   condòmini (`is_emitted`, `is_published` = avviso, visibilità privata), toglieva il compito «Emettere rata» e segnava «emessa».
 *
 * Le cifre sono in centesimi. Lo stato patrimoniale è la terna [Crediti verso condomini, Gestione rate, Passate gestioni]
 * (saldo dei passivi = avere − dare: −20000 su «Passate» vuol dire un DARE netto di 20000). Segno dei saldi delle persone:
 * positivo = debito, negativo = credito.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\EventoTipo;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Listeners\Gestionale\SyncScadenziarioWithPianoRate;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
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
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// ─── Aiuti (prefisso rlc: `rcScenario()` esiste già in RimborsoCreditoTest.php) ──────────────────────────────────────────────

/**
 * Preventivo ordinario € 1.200,00 in `$numeroRate` rate mensili dal 5 gennaio 2026, un capitolo su una tabella di proprietà.
 * Unità A di Ugo (`$millesimiA`) e, se `$millesimiB` > 0, unità B di Bice. `$saldi` in centesimi, per persona.
 */
function rlcScenario(int $numeroRate, string $metodo, array $saldi, int $millesimiA = 1000, int $millesimiB = 0): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);

    $persone = [];
    $unita = [];
    foreach (array_filter(['A' => ['ugo', 'Ugo Uno', $millesimiA], 'B' => ['bice', 'Bice Due', $millesimiB]], fn ($x) => $x[2] > 0) as $u => [$chi, $nome, $mill]) {
        $unita[$u] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "RLC-{$seq}-{$u}", 'nome' => "Interno {$u}", 'interno' => $u]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita[$u]->id, 'valore' => (float) $mill, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] = Anagrafica::forceCreate(['nome' => $nome, 'email' => "rlc-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(str_pad("RLC{$chi}{$seq}", 16, 'X'))]);
        $persone[$chi]->condomini()->syncWithoutDetaching([$c->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $unita[$u]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
        if (($saldi[$chi] ?? 0) !== 0) {
            Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $unita[$u]->id, 'gestione_id' => $g->id, 'saldo_iniziale' => $saldi[$chi], 'origine' => 'manuale', 'is_applicato' => false]);
        }
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'persone', 'piano', 'cassa');
}

function rlcRata(array $s, int $n): Rata
{
    return Rata::where('piano_rate_id', $s['piano']->id)->where('numero_rata', $n)->firstOrFail();
}

function rlcQuota(array $s, string $chi, int $n): RataQuote
{
    return RataQuote::where('rata_id', rlcRata($s, $n)->id)->where('anagrafica_id', $s['persone'][$chi]->id)->firstOrFail();
}

function rlcConto(array $s, string $ruolo): ContoContabile
{
    return ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->firstOrFail();
}

/** L'emissione della 1.10.0, riga per riga (v1.10.0 EmissioneRateController.php:121-268; vedi il docblock in testa). */
function rlcEmettiComeLa110(array $s, array $numeri, string $data): void
{
    $crediti = rlcConto($s, 'crediti_condomini');
    $gestione = rlcConto($s, 'gestione_rate');
    $passate = rlcConto($s, 'passate_gestioni');
    DB::transaction(function () use ($s, $numeri, $data, $crediti, $gestione, $passate) {
        foreach ($numeri as $n) {
            $rata = rlcRata($s, $n)->load('rateQuote');
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
                $sc->righe()->create(['conto_contabile_id' => $crediti->id, 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id, 'rata_id' => $rata->id,
                    'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
                $q->update(['scrittura_contabile_id' => $sc->id]);
                $tg += $g;
                $tp += $imp - $g;
            }
            if ($tg + $tp > 0) {
                foreach ([[$gestione, $tg, 'Totale emissione '], [$passate, $tp, 'Riporto esercizi precedenti — ']] as [$conto, $t, $nota]) {
                    if ($t !== 0) {
                        $sc->righe()->create(['conto_contabile_id' => $conto->id, 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => $nota.$rata->descrizione]);
                    }
                }
            } else {
                $sc->forceDelete();                                                       // v1.10.0 :222
            }
        }
        Rata::whereIn('id', collect($numeri)->map(fn ($n) => rlcRata($s, $n)->id))->update(['stato' => 'emessa']); // v1.10.0 :268
    });
}

/**
 * La 1.9.0 su una rata di soli crediti (refs/tags/v1.9.0 EmissioneRateController.php:86-178): testata vuota lasciata a giornale,
 * promemoria dei condòmini «emessi» e pubblicati (avviso acceso), compito «Emettere rata» tolto, rata «emessa».
 */
function rlcEmettiComeLa19(array $s, int $n, string $data): void
{
    $rata = rlcRata($s, $n);
    ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['piano']->gestione_id,
        'data_registrazione' => now(), 'data_competenza' => $data, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
    foreach (Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->get()->filter(fn ($e) => (int) ($e->meta['context']['rata_id'] ?? 0) === (int) $rata->id) as $evt) {
        $meta = $evt->meta;
        $meta['is_emitted'] = true;
        $meta['is_published'] = true;
        $evt->update(['meta' => $meta, 'visibility' => VisibilityStatus::PRIVATE->value]);
    }
    Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)->get()->filter(fn ($e) => (int) ($e->meta['context']['rata_id'] ?? 0) === (int) $rata->id)->each->delete();
    $rata->update(['stato' => 'emessa']);
}

/**
 * «Usa credito» di `$chi` dalla sua quota a credito della rata `$daRata` sulle sue quote delle rate `$su` (centesimi per rata),
 * come la 1.10: prelievo e riaccredito su «Crediti verso condomini». Restituisce la compensazione (`storno_credito`).
 */
function rlcCompensaComeLa110(array $s, string $chi, int $daRata, array $su, string $il): ScritturaContabile
{
    $p = $s['persone'][$chi];
    $qc = rlcQuota($s, $chi, $daRata);
    $righe = [['rata_id' => $qc->id, 'importo' => -array_sum($su) / 100]];
    foreach ($su as $n => $cent) {
        $righe[] = ['rata_id' => rlcQuota($s, $chi, $n)->id, 'importo' => $cent / 100];
    }
    $incasso = app(StoreIncassoRateAction::class)->execute(['pagante_id' => $p->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => $il, 'importo_totale' => 0, 'descrizione' => 'Compensazione', 'dettaglio_pagamenti' => $righe], $s['c'], $s['e']);
    $comp = ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'storno_credito')->firstOrFail();
    DB::table('righe_scritture')->where('scrittura_id', $comp->id)->where('rata_id', $qc->rata_id)->where('anagrafica_id', $p->id)
        ->where('conto_contabile_id', rlcConto($s, 'passate_gestioni')->id)->update(['conto_contabile_id' => rlcConto($s, 'crediti_condomini')->id]);

    return $comp;
}

/** Stato patrimoniale per ruolo (`StatoPatrimonialeService::calcola`, il motore della pagina): [Crediti, Gestione rate, Passate]. */
function rlcSp(array $s): array
{
    $sp = app(App\Services\Gestionale\StatoPatrimonialeService::class)->calcola($s['c']);
    $v = collect([...$sp['attivo']['voci'], ...$sp['passivo']['voci']]);

    return array_map(fn ($r) => (int) $v->where('ruolo', $r)->sum('saldo'), ['crediti_condomini', 'gestione_rate', 'passate_gestioni']);
}

/** La pagina vera dello stato patrimoniale: la terna letta dai gruppi, la sintesi dei crediti e l'esito dei controlli PD e R6. */
function rlcSpPagina($test, array $s): array
{
    $pagina = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.index', [$s['c'], $s['e']]))
        ->assertOk()->viewData('page')['props']['pagina'];
    $voci = collect([...$pagina['situazione']['attivo']['gruppi'], ...$pagina['situazione']['passivo']['gruppi']])->flatMap(fn ($g) => $g['voci']);
    $esiti = collect($pagina['controlli'])->whereIn('id', ['PD', 'R6'])->pluck('esito', 'id')->all();

    return [
        'terna' => array_map(fn ($r) => (int) $voci->where('ruolo', $r)->sum('saldo'), ['crediti_condomini', 'gestione_rate', 'passate_gestioni']),
        'sintesi_crediti' => (int) $pagina['sintesi']['crediti_condomini'],
        'controlli' => $esiti,
    ];
}

/** Il mastrino di «Crediti verso condomini» a nome di `$chi`, nel condominio dello scenario. */
function rlcMastrino(array $s, string $chi): int
{
    return (int) DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->where('scritture_contabili.condominio_id', $s['c']->id)->whereNull('scritture_contabili.deleted_at')
        ->where('conti_contabili.ruolo', 'crediti_condomini')->where('righe_scritture.anagrafica_id', $s['persone'][$chi]->id)
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
}

/** Le props della pagina dell'estratto conto di `$chi`. */
function rlcEstratto($test, array $s, string $chi): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))
        ->assertOk()->viewData('page')['props'];
}

/** Il PDF dell'estratto conto: i dati che il controller passa alla vista e il testo che la vista ne stampa. */
function rlcEstrattoStampa($test, array $s, string $chi): array
{
    $dati = null;
    $pdf = Mockery::mock(\Mpdf\Mpdf::class);
    $pdf->shouldReceive('SetHeader');
    $pdf->shouldReceive('Output')->andReturn('%PDF-finto');
    $servizio = Mockery::mock(\App\Services\PDF\PdfService::class);
    $servizio->shouldReceive('generate')->once()->andReturnUsing(function ($vista, $d) use (&$dati, $pdf) {
        $dati = $d;

        return $pdf;
    });
    app()->instance(\App\Services\PDF\PdfService::class, $servizio);
    $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone'][$chi]]))->assertOk();
    app()->forgetInstance(\App\Services\PDF\PdfService::class);

    return ['stats' => $dati['stats'], 'righe' => count($dati['timeline']), 'html' => view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render()];
}

function rlcComando(array $s, bool $prova = false): void
{
    test()->artisan('kondomanager:riallinea-crediti', array_filter(['--condominio' => $s['c']->id, '--dry-run' => $prova]))->assertExitCode(0);
}

/** La prop della card nella pagina «Incassi rate». */
function rlcCard($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

/** L'emissione vera (con la decisione 71), dalla pagina del piano. */
function rlcEmetti($test, array $s, array $numeri, string $il)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => rlcRata($s, $n)->id, $numeri), 'data_emissione' => $il, 'invia_notifiche' => false,
    ]);
}

/** Lo storno vero di un incasso, dalla sua rotta. */
function rlcStorna($test, array $s, int $incassoId)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incassoId]));
}

function rlcUltimoId(): int
{
    return (int) DB::table('scritture_contabili')->max('id');
}

/** Il collegamento alla pagina del piano: lo stesso che il promemoria «Emettere rata» porta da sempre (SyncScadenziarioWithPianoRate). */
function rlcUrlPiano(array $s): string
{
    return route('admin.gestionale.esercizi.piani-rate.show', ['condominio' => $s['c']->id, 'esercizio' => $s['e']->id, 'pianoRate' => $s['piano']->id]);
}

/** Tutto ciò che il comando potrebbe toccare: scritture, righe, stato delle rate, legami delle quote, promemoria. */
function rlcImpronta(array $s): array
{
    $c = $s['c']->id;

    return [
        'scritture' => DB::table('scritture_contabili')->where('condominio_id', $c)->orderBy('id')->get(['id', 'stato', 'scrittura_padre_id', 'deleted_at'])->map(fn ($r) => (array) $r)->all(),
        'righe' => DB::table('righe_scritture')->join('scritture_contabili as s', 's.id', '=', 'righe_scritture.scrittura_id')->where('s.condominio_id', $c)
            ->orderBy('righe_scritture.id')->get(['righe_scritture.id', 'righe_scritture.conto_contabile_id', 'righe_scritture.tipo_riga', 'righe_scritture.importo', 'righe_scritture.rata_id'])
            ->map(fn ($r) => (array) $r)->all(),
        'rate' => DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('id')->pluck('stato', 'id')->all(),
        'quote' => DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
            ->orderBy('rate_quote.id')->pluck('rate_quote.scrittura_contabile_id', 'rate_quote.id')->all(),
        'eventi' => DB::table('eventi')->orderBy('id')->get(['id', 'visibility', 'meta'])->map(fn ($r) => (array) $r)->all(),
    ];
}

/** Le righe di una scrittura come [ruolo del conto, verso, importo, persona], in un ordine fisso. */
function rlcRighe(int $scritturaId): array
{
    return DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->where('righe_scritture.scrittura_id', $scritturaId)
        ->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo', 'righe_scritture.anagrafica_id'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->anagrafica_id === null ? null : (int) $r->anagrafica_id])
        ->sortBy(fn ($r) => $r[0].'|'.$r[1].'|'.str_pad((string) $r[2], 12, '0', STR_PAD_LEFT))->values()->all();
}

/** La previsione di cassa: gli incassi attesi dalle rate emesse (TreasuryGuardianService, `Rata::incassi`). */
function rlcPrevisione(array $s): int
{
    return app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio($s['c']->id)->incassiAttesiCents;
}

/** Una quota gemella (decisione 25): la quota di `$chi` sulla rata `$n` divisa in due, stessa persona, rata e unità. */
function rlcGemella(array $s, string $chi, int $n, int $secondaRiporto): void
{
    $q = rlcQuota($s, $chi, $n);
    $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
    $prima = $regole;
    $prima['importi']['saldo_usato'] = (int) $regole['importi']['saldo_usato'] - $secondaRiporto;
    $prima['importi']['totale_calcolato'] = (int) $q->importo - $secondaRiporto;
    $seconda = $regole;
    $seconda['importi']['quota_pura_gestione'] = 0;
    $seconda['importi']['saldo_usato'] = $secondaRiporto;
    $seconda['importi']['totale_calcolato'] = $secondaRiporto;
    DB::table('rate_quote')->where('id', $q->id)->update(['importo' => (int) $q->importo - $secondaRiporto, 'regole_calcolo' => json_encode($prima)]);
    $nuova = (array) DB::table('rate_quote')->where('id', $q->id)->first();
    unset($nuova['id']);
    DB::table('rate_quote')->insert(array_replace($nuova, ['importo' => $secondaRiporto, 'importo_pagato' => 0, 'regole_calcolo' => json_encode($seconda)]));
}

// ─── DC1 ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('R1 — DC1 sulla rata zero: la rettifica sposta i 20000 della compensazione da «Crediti» a «Passate gestioni», figlia della compensazione e datata con lei; la prova non scrive, la seconda esecuzione non trova niente', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');

    // Come la lascia la 1.10. Rata 0: quota −20000, nessuna riga, «emessa». Rate 1 e 2: DARE «Crediti» 10000 ciascuna, AVERE
    // «Gestione rate» 10000 ciascuna. Compensazione: DARE «Crediti» 20000 (rata 0), AVERE 10000 + 10000 (rate 1 e 2).
    // Crediti 10000 + 10000 + 20000 − 20000 = 20000; Gestione 20000; Passate 0. Mastrino di Ugo 20000 per un credito già usato.
    expect(rlcRata($s, 0)->stato)->toBe('emessa')
        ->and(rlcSp($s))->toBe([20000, 20000, 0])
        ->and(rlcMastrino($s, 'ugo'))->toBe(20000)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);

    $impronta = rlcImpronta($s);
    rlcComando($s, prova: true);
    expect(rlcImpronta($s))->toBe($impronta);

    $ultimo = rlcUltimoId();
    rlcComando($s);
    $nuove = ScritturaContabile::where('id', '>', $ultimo)->get();
    // Una sola scrittura: AVERE «Crediti» 20000 e DARE «Passate» 20000, a nome di Ugo, sulla rata 0. Crediti 20000 − 20000 = 0;
    // Passate 0 − 20000 = −20000 (DARE netto: il credito è uscito da dove stava). Mastrino 20000 − 20000 = 0; estratto invariato.
    expect($nuove)->toHaveCount(1);
    $r = $nuove->first();
    expect($r->tipo_movimento->value ?? $r->tipo_movimento)->toBe('rettifica')
        ->and($r->scrittura_padre_id)->toBe($comp->id)
        ->and($r->data_competenza->toDateString())->toBe('2026-03-10')
        ->and($r->esercizio_id)->toBe($s['e']->id)
        ->and(rlcRighe($r->id))->toBe([
            ['crediti_condomini', 'avere', 20000, $s['persone']['ugo']->id],
            ['passate_gestioni', 'dare', 20000, $s['persone']['ugo']->id],
        ])
        ->and(DB::table('righe_scritture')->where('scrittura_id', $r->id)->pluck('rata_id')->unique()->all())->toBe([rlcRata($s, 0)->id])
        ->and(rlcSp($s))->toBe([0, 20000, -20000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(0)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta);
});

it('R1-bis — decisione 76.1 e rilievo LDA-3: la rata zero che la 1.10 ha segnato «emessa» senza righe, con il credito usato tutto, il comando non la tocca e la card non la manda a emettere: dopo la rettifica di DC1 l\'emissione non avrebbe niente da portare', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');

    // Prima del comando la card propone la rettifica di DC1 (20000). La rata zero non va fra quelle da emettere: dopo la rettifica il
    // suo credito è uscito tutto da «Passate gestioni», e l'emissione la lascerebbe in bozza senza scrivere niente (l'anteprima conta
    // le rettifiche in arrivo, come per le rate miste, R26). Oggi la card la manda a emettere, prima e dopo il comando.
    $prima = rlcCard($this, $s);
    expect($prima)->not->toBeNull()
        ->and(collect($prima['compensazioni'])->pluck('importo')->all())->toBe([20000])
        ->and(collect($prima['da_emettere'])->pluck('rata_id')->all())->not->toContain(rlcRata($s, 0)->id);

    rlcComando($s);
    // La rettifica di DC1 c'è (R1); la rata zero resta com'era: «emessa», nessuna quota a giornale; e non è fra quelle da emettere.
    expect(rlcRata($s, 0)->fresh()->stato)->toBe('emessa')
        ->and(rlcQuota($s, 'ugo', 0)->scrittura_contabile_id)->toBeNull()
        ->and(collect(rlcCard($this, $s)['da_emettere'] ?? [])->pluck('rata_id')->all())->not->toContain(rlcRata($s, 0)->id);
    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id, '--dry-run' => true])
        ->doesntExpectOutputToContain('Da emettere dalla pagina del piano')->assertExitCode(0);

    // Se l'amministratore la emette comunque dalla pagina del piano: il credito della quota (20000) è già uscito tutto da «Passate»
    // (la rettifica), a giornale non c'è niente da portare, la rata resta in bozza e i conti non cambiano. L'esito lo dice per quello
    // che è (il credito già usato), non con la frase delle rate vuote.
    $esito = rlcEmetti($this, $s, [0], '2026-03-20')->assertSessionHasNoErrors()->getSession()->get('esito_emissione');
    expect(rlcRata($s, 0)->fresh()->stato)->toBe('bozza')
        ->and(rlcSp($s))->toBe([0, 20000, -20000])
        ->and($esito['testo'])->toContain('usato o rimborsato per intero')
        ->and($esito['testo'])->not->toContain('non ha quote da pagare')
        ->and(rlcCard($this, $s))->toBeNull();
});

it('R5 — come il condominio 16: nella rata zero mista il credito di Ugo (€ 100,00) è usato tutto in compensazione; il comando scrive solo la rettifica', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -10000, 'bice' => 6000], 500, 500);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 5000, 2 => 5000], '2026-03-10');
    // Rata 0: Bice DARE 6000 (pregresso, AVERE «Passate» 6000); Ugo −10000 fuori. Rate 1 e 2: 5000 + 5000, AVERE «Gestione» 10000.
    // Compensazione: DARE 10000 (rata 0 di Ugo), AVERE 5000 + 5000. Crediti 6000 + 10000 + 10000 + 10000 − 10000 = 26000, contro
    // un residuo delle quote a giornale di 16000 (Bice 6000 + 5000 + 5000; Ugo 0): lo scarto è la firma di DC1.
    expect(rlcSp($s))->toBe([26000, 20000, 6000])->and(rlcMastrino($s, 'ugo'))->toBe(10000);

    $ultimo = rlcUltimoId();
    rlcComando($s);
    // Rettifica di 10000. Crediti 26000 − 10000 = 16000; Passate 6000 − 10000 = −4000. La quota di Ugo sulla rata 0: credito 10000,
    // già uscito tutto (la rettifica) → nessuna riga da scrivere.
    expect(ScritturaContabile::where('id', '>', $ultimo)->pluck('tipo_movimento')->map(fn ($t) => $t->value ?? $t)->all())->toBe(['rettifica'])
        ->and(rlcSp($s))->toBe([16000, 20000, -4000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(0)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0)
        ->and(rlcRata($s, 0)->fresh()->stato)->toBe('emessa')
        ->and(rlcCard($this, $s))->toBeNull();
});

it('R25 — il credito di una quota strapagata, anche su una rata in bozza, sta già su «Crediti verso condomini»: la sua compensazione non è DC1 e il comando non la tocca', function () {
    // Ugo, solo, 10000 a rata, nessun saldo. Rata 1 in bozza (mai emessa): incasso di 12000 sulla sua
    // quota da 10000 (eccedenza 2000 lasciata sulla quota: credito 2000). Rata 2 emessa come la 1.10. Poi «Usa credito»: 2000 dalla
    // quota strapagata della rata 1 alla rata 2. La quota a credito è senza scrittura come quelle di DC1, ma l'importo è positivo:
    // il credito è denaro entrato in cassa e scritto in AVERE su «Crediti» dall'incasso, e il prelievo su «Crediti» è giusto.
    $s = rlcScenario(12, 'prima_rata', []);
    rlcEmettiComeLa110($s, [2], '2026-02-28');
    app(StoreIncassoRateAction::class)->execute(['pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => '2026-01-20', 'importo_totale' => 120.00, 'descrizione' => 'Rata 1 e qualcosa in più', 'eccedenza' => 20.00,
        'dettaglio_pagamenti' => [['rata_id' => rlcQuota($s, 'ugo', 1)->id, 'importo' => 100.00]]], $s['c'], $s['e']);
    expect(rlcQuota($s, 'ugo', 1)->credito_disponibile)->toBe(2000);
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 2000], '2026-03-10');
    // Crediti: rata 2 DARE 10000; incasso AVERE 12000 (rata 1); compensazione DARE 2000 (rata 1), AVERE 2000 (rata 2):
    // 10000 − 12000 + 2000 − 2000 = −2000, tutto di Ugo (mastrino −2000: ha versato 12000 e a giornale ha solo la rata 2, perché
    // la rata 1 è in bozza). Gestione 10000; Passate 0. Un comando che riconoscesse DC1 senza guardare il segno della quota
    // scriverebbe AVERE «Crediti» 2000 e DARE «Passate» 2000: [−4000, 10000, −2000], un credito da saldi che Ugo non ha mai avuto.
    expect(rlcSp($s))->toBe([-2000, 10000, 0])->and(rlcMastrino($s, 'ugo'))->toBe(-2000);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(rlcCard($this, $s))->toBeNull();
});

// ─── DC2-b ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('R2 — DC2-b: la quota a credito di Ugo (5000 di spesa, 20000 di credito) va a giornale con una scrittura d\'emissione legata alla quota, nella data, nell\'esercizio e nella gestione dell\'emissione della sua rata', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    $sorella = ScritturaContabile::findOrFail(rlcQuota($s, 'bice', 1)->scrittura_contabile_id);

    // Rata 1: Ugo 5000 − 20000 = −15000, fuori dal giornale; Bice DARE 5000, AVERE «Gestione» 5000. Rata 2: 5000 + 5000, AVERE
    // «Gestione» 10000. Crediti 15000, Gestione 15000, Passate 0. Estratto di Ugo: −20000 + 5000 (rata 2) = −15000: la spesa della
    // rata 1 non c'è.
    expect(rlcSp($s))->toBe([15000, 15000, 0])
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-15000);

    $ultimo = rlcUltimoId();
    rlcComando($s);
    $nuove = ScritturaContabile::where('id', '>', $ultimo)->get();
    expect($nuove)->toHaveCount(1);
    $em = $nuove->first();
    // DARE «Crediti» 5000 (spesa) e AVERE «Crediti» 20000 (credito riportato) a nome di Ugo; AVERE «Gestione» 5000; DARE «Passate» 20000.
    // Crediti 15000 + 5000 − 20000 = 0; Gestione 15000 + 5000 = 20000; Passate −20000. Ugo −20000 + 5000 + 5000 = −10000; Bice 10000.
    expect($em->tipo_movimento->value ?? $em->tipo_movimento)->toBe('emissione_rata')
        ->and($em->scrittura_padre_id)->toBeNull()
        ->and([$em->data_competenza->toDateString(), $em->esercizio_id, $em->gestione_id])->toBe(['2026-02-28', $sorella->esercizio_id, $sorella->gestione_id])
        ->and(rlcQuota($s, 'ugo', 1)->scrittura_contabile_id)->toBe($em->id)
        ->and(rlcRighe($em->id))->toBe([
            ['crediti_condomini', 'avere', 20000, $s['persone']['ugo']->id],
            ['crediti_condomini', 'dare', 5000, $s['persone']['ugo']->id],
            ['gestione_rate', 'avere', 5000, null],
            ['passate_gestioni', 'dare', 20000, null],
        ])
        ->and(rlcSp($s))->toBe([0, 20000, -20000])
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-10000)
        ->and(rlcEstratto($this, $s, 'bice')['stats']['saldo_raw'])->toBe(10000);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta);
});

it('R3 — DC1 e DC2-b insieme: prima la rettifica dei 5000 usati, poi la quota con il credito già uscito letto dal giornale (AVERE 15000, non 20000)', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');
    // Compensazione come la 1.10: DARE «Crediti» 5000 sulla rata 1, AVERE 5000 sulla rata 2. Crediti 15000 + 5000 − 5000 = 15000.
    // Mastrino di Ugo: 5000 (rata 2) + 5000 − 5000 = 5000.
    expect(rlcSp($s))->toBe([15000, 15000, 0])->and(rlcMastrino($s, 'ugo'))->toBe(5000);

    $ultimo = rlcUltimoId();
    rlcComando($s);
    $nuove = ScritturaContabile::where('id', '>', $ultimo)->orderBy('id')->get();
    // Rettifica: AVERE «Crediti» 5000, DARE «Passate» 5000. Quota: DARE 5000, AVERE 20000 − 5000 = 15000; AVERE «Gestione» 5000,
    // DARE «Passate» 15000. Crediti 15000 − 5000 + 5000 − 15000 = 0; Gestione 20000; Passate −5000 − 15000 = −20000.
    // Mastrino di Ugo 5000 − 5000 + 5000 − 15000 = −10000: il credito che gli resta. Con il credito già usato letto dopo la
    // rettifica ma senza contarla (o la quota scritta prima), l'AVERE sarebbe 20000: Crediti −5000 e Passate −25000.
    expect($nuove->map(fn ($x) => $x->tipo_movimento->value ?? $x->tipo_movimento)->all())->toBe(['rettifica', 'emissione_rata'])
        ->and($nuove[0]->scrittura_padre_id)->toBe($comp->id)
        ->and(rlcRighe($nuove[1]->id))->toBe([
            ['crediti_condomini', 'avere', 15000, $s['persone']['ugo']->id],
            ['crediti_condomini', 'dare', 5000, $s['persone']['ugo']->id],
            ['gestione_rate', 'avere', 5000, null],
            ['passate_gestioni', 'dare', 15000, null],
        ])
        ->and(rlcSp($s))->toBe([0, 20000, -20000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(-10000)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-10000);
});

it('R4 — senza il dettaglio del calcolo (piani della 1.7.x) la quota a credito della rata mista non si indovina: il comando non scrive niente, e la card la elenca in sola lettura fra le voci che restano come sono (76.3 ritoccata)', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -15000, 'bice' => 6000], 500, 500);
    DB::table('rate_quote')->where('id', rlcQuota($s, 'ugo', 0)->id)->update(['regole_calcolo' => null]);
    rlcEmettiComeLa110($s, [0], '2026-01-31');
    // Rata 0: Bice DARE 6000, AVERE «Passate» 6000; Ugo −15000 fuori, e non si sa quanta parte è spesa e quanta credito.
    expect(rlcSp($s))->toBe([6000, 0, 6000]);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta);

    // È la forma che la 76.3 ritoccata (11/10/2026, rilievo LDA-2) lascia anche con l'emissione nuova: la quota ferma resta fuori
    // con il suo credito, e la card la elenca fra «Restano come sono». Niente da scrivere: la card non offre il pulsante.
    $card = rlcCard($this, $s);
    expect($card)->not->toBeNull()
        ->and(collect($card['restano'])->pluck('motivo')->contains(fn ($m) => str_contains($m, 'Ugo Uno') && str_contains($m, 'rata 0') && str_contains($m, 'dettaglio del calcolo')))->toBeTrue()
        ->and($card['compensazioni'])->toBe([])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([])
        ->and($card['da_emettere'])->toBe([]);
});

it('R15 — la rata mista si riconosce dalle scritture, non dallo stato: a giornale con la quota a credito fuori, anche se lo stato dice «bozza»', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    DB::table('rate')->where('id', rlcRata($s, 1)->id)->update(['stato' => 'bozza']);
    rlcComando($s);
    // Come R2: [15000, 15000, 0] → [0, 20000, −20000]. Con un filtro su `stato = 'emessa'` la rata non si troverebbe.
    expect(rlcSp($s))->toBe([0, 20000, -20000]);
});

// ─── Le rate «emesse» senza righe (decisione 76.1) ───────────────────────────────────────────────────────────────────────────

it('R6 — decisione 76.1: le rate di soli crediti con la spesa dentro (DC2-c, «tutte le rate») che la 1.10 ha segnato «emesse» il comando non le scrive; la card le elenca e l\'amministratore le emette con la data che sceglie', function () {
    $s = rlcScenario(12, 'tutte_rate', ['ugo' => -150000]);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    // Ogni quota: 10000 di spesa − 12500 di credito = −2500. Due rate «emesse» senza righe: niente a giornale.
    expect(rlcSp($s))->toBe([0, 0, 0])->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-150000);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    // Il comando non scrive e non cambia lo stato: «finché non le emette, per quelle rate i conti restano come oggi».
    expect(rlcImpronta($s))->toBe($impronta);
    $card = rlcCard($this, $s);
    expect(collect($card['da_emettere'])->pluck('rata_id')->sort()->values()->all())->toBe([rlcRata($s, 1)->id, rlcRata($s, 2)->id])
        ->and(collect($card['da_emettere'])->pluck('url')->unique()->all())->toBe([rlcUrlPiano($s)]);

    // L'amministratore le emette il 15/03, senza avviso. Per rata: DARE «Crediti» 10000, AVERE «Crediti» 12500, AVERE «Gestione»
    // 10000, DARE «Passate» 12500. Crediti 2 × (10000 − 12500) = −5000; Gestione 20000; Passate −25000. Estratto −150000 + 20000.
    rlcEmetti($this, $s, [1, 2], '2026-03-15')->assertSessionHasNoErrors();
    expect(rlcSp($s))->toBe([-5000, 20000, -25000])
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-130000)
        ->and(ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->pluck('data_competenza')->map->toDateString()->unique()->all())->toBe(['2026-03-15'])
        ->and(rlcCard($this, $s))->toBeNull();
});

it('R8 — decisione 76.1: lo storno prima del riallineamento rovescia la compensazione dove l\'ha scritta; il comando non trova niente da scrivere, e la rata zero, con il credito tornato intero, la emette l\'amministratore', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    rlcStorna($this, $s, $comp->scrittura_padre_id)->assertSessionHasNoErrors();
    // Lo specchio della compensazione: AVERE «Crediti» 20000 (rata 0), DARE 10000 + 10000. Crediti 20000 (le rate 1 e 2 di nuovo da
    // pagare), Passate 0: giusto, il credito di Ugo è di nuovo fuori dal giornale. Estratto −20000 + 20000 = 0.
    expect(rlcSp($s))->toBe([20000, 20000, 0])->and(rlcMastrino($s, 'ugo'))->toBe(20000);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(collect(rlcCard($this, $s)['da_emettere'])->pluck('rata_id')->all())->toBe([rlcRata($s, 0)->id]);

    // Emessa con la 71: niente è uscito da «Passate» (lo specchio ha rovesciato «Crediti»), AVERE «Crediti» 20000, DARE «Passate» 20000.
    rlcEmetti($this, $s, [0], '2026-03-20')->assertSessionHasNoErrors();
    expect(rlcSp($s))->toBe([0, 20000, -20000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(0)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0)
        ->and(rlcRata($s, 0)->fresh()->stato)->toBe('emessa')
        ->and(rlcCard($this, $s))->toBeNull();
});

it('R9 — l\'amministratore riemette la rata zero con la versione nuova prima di lanciare il comando: l\'emissione sistema da sola DC1 ([−10000, 10000, −20000]) e il comando non cambia niente', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1], '2026-01-31');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000], '2026-02-10');
    // 1.10: rata 1 DARE 10000; compensazione DARE «Crediti» 10000 (rata 0), AVERE 10000 (rata 1). Crediti 10000, Gestione 10000.
    expect(rlcSp($s))->toBe([10000, 10000, 0]);

    // La riemissione con la 71: da «Passate» non è uscito niente (la compensazione è su «Crediti»), quindi AVERE «Crediti» 20000 e
    // DARE «Passate» 20000. Crediti 10000 − 20000 = −10000 (il credito che resta a Ugo); Gestione 10000; Passate −20000.
    rlcEmetti($this, $s, [0], '2026-03-01')->assertSessionHasNoErrors();
    expect(rlcSp($s))->toBe([-10000, 10000, -20000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(-10000);

    // La quota ha la sua scrittura: per la posizione non c'è più niente da spostare. Un comando che riconoscesse DC1 dalla storia
    // («la quota è andata a giornale dopo la compensazione») scriverebbe una seconda rettifica: [−20000, 10000, −30000].
    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(rlcSp($s))->toBe([-10000, 10000, -20000])
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-10000);
});

it('R10 — W1 (decisione 75.2): la rata «emessa» senza righe che un passaggio della .41 ha preso nel conguaglio non si tocca e la card non la elenca', function () {
    $s = rlcScenario(6, 'prima_rata', ['ugo' => -67100]);
    $elsa = Anagrafica::forceCreate(['nome' => 'Elsa Tre', 'email' => 'rlc-elsa@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RLCELSAXXXXXXXXX']);
    $elsa->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('rate')->where('id', rlcRata($s, 1)->id)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['ugo']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['A']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-02-07',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-02-07',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano da ricalcolare',
    ])->assertSessionHasNoErrors();
    // La forma della .41 (StatoDelPianoDopoPassaggioTest, W1): registro alla versione 1 senza `piani_presi`, la coppia ±17973 in saldi.
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    foreach ([[$s['persone']['ugo']->id, -17973], [$elsa->id, 17973]] as [$chi, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['unita']['A']->id, 'anagrafica_id' => $chi,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $p1->id, 'descrizione' => 'Conguaglio della .41']);
    }
    expect($s['piano']->fresh()->presoSoloInParteDa($p1))->toBeTrue();

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(ScritturaContabile::where('condominio_id', $s['c']->id)->count())->toBe(0)
        ->and(rlcCard($this, $s))->toBeNull();
});

it('R18 — sonda S5: la rata di soli crediti che la 1.9 ha pubblicato ai condòmini resta com\'è: il comando non tocca i promemoria, né la testata vuota, né lo stato', function () {
    $s = rlcScenario(12, 'tutte_rate', ['ugo' => -150000]);
    (new SyncScadenziarioWithPianoRate())->handle(new PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user, StatoPianoRate::BOZZA, StatoPianoRate::APPROVATO));
    $promemoria = fn () => Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->get()->filter(fn ($e) => (int) ($e->meta['context']['rata_id'] ?? 0) === (int) rlcRata($s, 1)->id)->values();
    expect($promemoria())->not->toBeEmpty();
    rlcEmettiComeLa19($s, 1, '2026-02-28');
    // Come la lascia la 1.9: il condòmino vede la rata 1 (emessa e pubblicata, visibilità privata), la testata vuota è a giornale.
    expect($promemoria()->every(fn ($e) => $e->meta['is_emitted'] === true && $e->meta['is_published'] === true && ($e->visibility->value ?? $e->visibility) === VisibilityStatus::PRIVATE->value))->toBeTrue();

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(collect(rlcCard($this, $s)['da_emettere'])->pluck('rata_id')->all())->toBe([rlcRata($s, 1)->id]);
});

// ─── Lo storno dopo il riallineamento (contrasto 3) ──────────────────────────────────────────────────────────────────────────

it('R7 — la rettifica è figlia della compensazione: lo storno dell\'incasso la rovescia insieme a lei, e tutto torna come prima della compensazione', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $ultimo = rlcUltimoId();
    rlcComando($s);
    $rettifica = ScritturaContabile::where('id', '>', $ultimo)->firstOrFail();
    expect($rettifica->scrittura_padre_id)->toBe($comp->id)->and(rlcSp($s))->toBe([0, 20000, -20000]);

    rlcStorna($this, $s, $comp->scrittura_padre_id)->assertSessionHasNoErrors();
    // Gli specchi: della compensazione (AVERE «Crediti» 20000 sulla rata 0, DARE 10000 + 10000) e della rettifica (DARE «Crediti»
    // 20000 sulla rata 0, AVERE «Passate» 20000). Crediti 0 − 20000 + 20000 + 20000 = 20000 (le rate 1 e 2 di nuovo da pagare);
    // Passate −20000 + 20000 = 0; mastrino di Ugo 20000; estratto −20000 + 20000 = 0. Se lo storno non prendesse la rettifica:
    // [0, 20000, −20000], cioè il credito di Ugo fuori da «Passate» due volte.
    expect(rlcSp($s))->toBe([20000, 20000, 0])
        ->and(rlcMastrino($s, 'ugo'))->toBe(20000)
        ->and(rlcEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0)
        ->and($rettifica->fresh()->stato)->toBe('annullata')
        ->and(ScritturaContabile::where('scrittura_padre_id', $rettifica->id)->where('tipo_movimento', 'rettifica')->count())->toBe(1);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta);
});

it('S2 — sonda dello scettico: la rata zero mista di R5 riallineata e poi stornata; il comando ritrova il credito di Ugo tornato fuori dal giornale e lo chiude senza contarlo due volte', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -10000, 'bice' => 6000], 500, 500);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 0, [1 => 5000, 2 => 5000], '2026-03-10');
    rlcComando($s);
    rlcStorna($this, $s, $comp->scrittura_padre_id)->assertSessionHasNoErrors();
    // Dopo lo storno, gli specchi della compensazione e della rettifica: [26000, 20000, 6000], come prima della compensazione.
    $ultimo = rlcUltimoId();
    rlcComando($s);
    // Dopo lo storno le rate 1 e 2 di Ugo sono di nuovo da pagare (10000) e il suo credito (10000) è di nuovo intero, in una rata
    // a giornale. Comunque lo si porti a giornale (lo storno o il comando), la fine è una: Crediti 16000 (Bice) + 10000 (Ugo, rate
    // 1 e 2) − 10000 (il credito di Ugo) = 16000; Gestione 20000; Passate 6000 (Bice) − 10000 (Ugo) = −4000. Mastrino di Ugo
    // 10000 − 10000 = 0. Con lo specchio della rettifica dimenticato nel credito già uscito (10000 invece di 10000 − 10000 = 0) la
    // quota sembrerebbe già chiusa e non si scriverebbe niente: [26000, 20000, 6000] (misurato).
    // Le cifre da sole non bastano: una seconda rettifica di DC1 sulla compensazione annullata (AVERE «Crediti» 10000, DARE
    // «Passate» 10000) porterebbe alla stessa terna, e la quota resterebbe fuori. Il comando non scrive figlie della compensazione.
    expect(rlcSp($s))->toBe([16000, 20000, -4000])
        ->and(rlcMastrino($s, 'ugo'))->toBe(0)
        ->and(ScritturaContabile::where('id', '>', $ultimo)->where('scrittura_padre_id', $comp->id)->count())->toBe(0);
});

// ─── Esercizio chiuso (verbale, P9; sonde S3 e S4) ───────────────────────────────────────────────────────────────────────────

it('R12 — esercizio chiuso: il comando non scrive né la rettifica né la quota in un bilancio chiuso (la card che lo dice in sola lettura è il test LDA-8 di CreditiRevisioneEmissioneTest)', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');
    $s['e']->update(['stato' => 'chiuso']);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo('2027-02-01 10:00:00');

    // Lo scenario di R3 (Crediti 15000, Gestione 15000, Passate 0), con il suo esercizio chiuso.
    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(rlcSp($s))->toBe([15000, 15000, 0]);

    // La conferma (da una pagina aperta prima, o da una richiesta diretta) non scrive e lo dice: il messaggio nomina l'esercizio
    // chiuso, e non dice «i crediti sono già a posto», che qui è falso (lo scarto di DC1 e la quota di Ugo restano).
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertRedirect()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'chiuso') && ! str_contains($m['message'], 'già a posto'));
    expect(rlcImpronta($s))->toBe($impronta);
});

it('R12-bis — sonda S4: esercizio chiuso e nessun esercizio aperto che contenga oggi (il 2027 è aperto, oggi è il 20/12/2026): la conferma della card risponde, non dà 404, e non scrive', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $s['e']->update(['stato' => 'chiuso']);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo('2026-12-20 10:00:00');

    $impronta = rlcImpronta($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertRedirect()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'chiuso') && ! str_contains($m['message'], 'già a posto'));
    expect(rlcImpronta($s))->toBe($impronta)->and(rlcSp($s))->toBe([20000, 20000, 0]);
});

it('R19 — il terminale dice che cosa resta com\'è e perché: l\'esercizio chiuso e la quota senza il dettaglio del calcolo', function () {
    $chiuso = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($chiuso, [1, 2], '2026-02-28');
    $chiuso['e']->update(['stato' => 'chiuso']);
    $senza = rlcScenario(12, 'rata_zero', ['ugo' => -15000, 'bice' => 6000], 500, 500);
    DB::table('rate_quote')->where('id', rlcQuota($senza, 'ugo', 0)->id)->update(['regole_calcolo' => null]);
    rlcEmettiComeLa110($senza, [0], '2026-01-31');

    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $chiuso['c']->id])
        ->expectsOutputToContain('Restano come sono')->expectsOutputToContain('chiuso')->assertExitCode(0);
    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $senza['c']->id])
        ->expectsOutputToContain('Restano come sono')->assertExitCode(0);
});

// ─── Quote gemelle (decisione 25) ────────────────────────────────────────────────────────────────────────────────────────────

it('R16 — le quote gemelle (decisione 25, dati delle sole beta) restano fuori: la compensazione presa da due gemelle non si riallinea, e nemmeno le gemelle', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -10000, 'bice' => 6000], 500, 500);
    rlcGemella($s, 'ugo', 0, -4000);                                     // Ugo sulla rata 0: −6000 e −4000, stessa unità
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 5000, 2 => 5000], '2026-03-10');
    // Come R5: [26000, 20000, 6000]. Il prelievo di 10000 viene dalle due gemelle.
    expect(rlcSp($s))->toBe([26000, 20000, 6000]);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)->and(rlcCard($this, $s))->toBeNull();
});

it('R17 — le quote gemelle restano fuori anche dalla rata mista (DC2-b): il comando non le scrive', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcGemella($s, 'ugo', 1, -5000);                                     // Ugo sulla rata 1: −10000 e −5000, stessa unità
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    expect(rlcSp($s))->toBe([15000, 15000, 0]);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)->and(rlcCard($this, $s))->toBeNull();
});

// ─── Il comando su più condomìni ─────────────────────────────────────────────────────────────────────────────────────────────

it('R11 — più condomìni: --condominio tocca solo quello; senza, tutti; la prova non scrive niente', function () {
    $a = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    $b = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($a, [1, 2], '2026-02-28');
    rlcEmettiComeLa110($b, [1, 2], '2026-02-28');
    $this->artisan('kondomanager:riallinea-crediti', ['--dry-run' => true])->assertExitCode(0);
    expect([rlcSp($a), rlcSp($b)])->toBe([[15000, 15000, 0], [15000, 15000, 0]]);
    rlcComando($a);
    expect([rlcSp($a), rlcSp($b)])->toBe([[0, 20000, -20000], [15000, 15000, 0]]);
    $this->artisan('kondomanager:riallinea-crediti')->assertExitCode(0);
    expect([rlcSp($a), rlcSp($b)])->toBe([[0, 20000, -20000], [0, 20000, -20000]]);
});

// ─── La card ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('R14 — la card: la pagina degli incassi la passa solo se c\'è qualcosa da fare; chi non ha il pannello riceve 403; la conferma scrive; la seconda conferma dice che non c\'è niente', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    $card = rlcCard($this, $s);
    expect(collect($card['rate'])->firstWhere('rata_id', rlcRata($s, 1)->id)['azione'])->toBe('scrivi');

    $estraneo = User::factory()->create();
    $this->actingAs($estraneo)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertForbidden();
    expect(rlcSp($s))->toBe([15000, 15000, 0]);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertRedirect()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'success');
    expect(rlcSp($s))->toBe([0, 20000, -20000])->and(rlcCard($this, $s))->toBeNull();

    $impronta = rlcImpronta($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))
        ->assertSessionHas('message', fn ($m) => $m['message'] === 'Niente da riallineare: i crediti sono già a posto.');
    expect(rlcImpronta($s))->toBe($impronta);
});

it('R14-bis — chi conferma dalla card resta scritto su ogni scrittura, e il messaggio nomina i protocolli scritti', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');
    $ultimo = rlcUltimoId();

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertRedirect();
    $nuove = ScritturaContabile::where('id', '>', $ultimo)->get();
    $messaggio = session('message')['message'] ?? '';
    // Lo scenario di R3: una rettifica e un'emissione.
    expect($nuove)->toHaveCount(2)
        ->and($nuove->pluck('created_by')->unique()->all())->toBe([$this->user->id])
        ->and($nuove->every(fn ($x) => str_contains($messaggio, (string) $x->numero_protocollo)))->toBeTrue();
});

it('R20 — senza il conto «Passate gestioni» la conferma della card si rifiuta con un messaggio, non con un errore, e non scrive', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();

    // La quota di Ugo porta 20000 di credito riportato: senza «Passate» non ha una contropartita da cui uscire.
    $impronta = rlcImpronta($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertRedirect()
        // Il conto con il nome e il codice che gli dà il programma, un nome solo (giro stretto sulle correzioni della Fase 1-bis, LT-2).
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], '«Fondo Passate Gestioni» (2301)'));
    expect(rlcImpronta($s))->toBe($impronta);
});

// ─── I capolinea ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('R13 — l\'estratto conto, a video e nel PDF, non mostra la rettifica del riallineamento come uno storno mai avvenuto (sonda SC2): stesse righe, stessi totali, stesso saldo', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $prima = rlcEstratto($this, $s, 'ugo');
    $stampaPrima = rlcEstrattoStampa($this, $s, 'ugo');

    rlcComando($s);
    $dopo = rlcEstratto($this, $s, 'ugo');
    $stampaDopo = rlcEstrattoStampa($this, $s, 'ugo');
    $testo = json_encode($dopo['timeline'], JSON_UNESCAPED_UNICODE);
    // Addebiti: rata 1 10000 + rata 2 10000 + prelievo dal credito 20000 = 40000 (€ 400,00); versato: 20000 di compensazione
    // (€ 200,00); saldo −20000 + 40000 − 20000 = 0. La rettifica letta come storno aggiungerebbe «Storno incasso su Rata …» (20000
    // in dare) e «Ripristino credito nel salvadanaio» (20000 in avere): 60000 e 40000 (€ 600,00 e € 400,00), due righe in più.
    expect([$dopo['stats']['totale_addebiti'], $dopo['stats']['totale_versamenti'], $dopo['stats']['saldo_raw']])->toBe(['€ 400,00', '€ 200,00', 0])
        ->and([$dopo['stats']['totale_addebiti'], $dopo['stats']['totale_versamenti'], $dopo['stats']['saldo_raw']])
            ->toBe([$prima['stats']['totale_addebiti'], $prima['stats']['totale_versamenti'], $prima['stats']['saldo_raw']])
        ->and(count($dopo['timeline']))->toBe(count($prima['timeline']))
        ->and($testo)->not->toContain('Ripristino credito nel salvadanaio')
        ->and($testo)->not->toContain('Storno incasso')
        ->and([$stampaDopo['stats']['totale_addebiti'], $stampaDopo['stats']['totale_versamenti'], $stampaDopo['stats']['saldo_raw'], $stampaDopo['righe']])
            ->toBe([$stampaPrima['stats']['totale_addebiti'], $stampaPrima['stats']['totale_versamenti'], $stampaPrima['stats']['saldo_raw'], $stampaPrima['righe']])
        ->and($stampaDopo['html'])->toContain('€ 400,00')
        ->and($stampaDopo['html'])->not->toContain('Ripristino credito nel salvadanaio');
});

it('R13-bis — dopo lo storno di un incasso riallineato l\'estratto conto è quello che sarebbe senza il riallineamento: né la rettifica né il suo specchio compaiono', function () {
    // Due condomìni uguali: in uno la compensazione si riallinea e poi si storna; nell'altro si storna e basta.
    $con = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($con, [0, 1, 2], '2026-02-28');
    $compCon = rlcCompensaComeLa110($con, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $ultimo = rlcUltimoId();
    rlcComando($con);
    $rettifica = ScritturaContabile::where('id', '>', $ultimo)->firstOrFail();
    rlcStorna($this, $con, $compCon->scrittura_padre_id)->assertSessionHasNoErrors();
    $specchio = ScritturaContabile::where('scrittura_padre_id', $rettifica->id)->firstOrFail();

    $senza = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($senza, [0, 1, 2], '2026-02-28');
    $compSenza = rlcCompensaComeLa110($senza, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    rlcStorna($this, $senza, $compSenza->scrittura_padre_id)->assertSessionHasNoErrors();

    $a = rlcEstratto($this, $con, 'ugo');
    $b = rlcEstratto($this, $senza, 'ugo');
    $forma = fn (array $p) => collect($p['timeline'])->map(fn ($r) => [$r['tipo_movimento'], $r['dare'], $r['avere']])->all();
    // Ugo: saldo −20000 + rate 1 e 2 (20000) = 0; la compensazione e il suo specchio si annullano. Lo specchio della rettifica, se
    // comparisse, porterebbe un DARE di € 200,00 in più («Storno incasso su Rata …») e il saldo a 20000.
    expect(collect($a['timeline'])->pluck('scrittura_id')->intersect([$rettifica->id, $specchio->id])->all())->toBe([])
        ->and($a['stats']['saldo_raw'])->toBe(0)
        ->and([$a['stats']['totale_addebiti'], $a['stats']['totale_versamenti'], $a['stats']['saldo_raw']])
            ->toBe([$b['stats']['totale_addebiti'], $b['stats']['totale_versamenti'], $b['stats']['saldo_raw']])
        ->and($forma($a))->toBe($forma($b));
});

it('R21 — la pagina dello stato patrimoniale, prima e dopo il comando (lo scenario di R3): i crediti verso condomini scendono a zero, «Passate gestioni» porta il credito di Ugo, e i controlli quadrano', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');

    // Prima: «Crediti verso condomini» 15000, ma la posizione vera dei due è 0: Bice deve 5000 + 5000, Ugo ha 10000 di credito
    // (20000 riportati, meno 5000 + 5000 di spesa delle sue rate). Gestione 15000: manca la spesa di Ugo della rata 1. Passate 0.
    $prima = rlcSpPagina($this, $s);
    expect($prima['terna'])->toBe([15000, 15000, 0])
        ->and($prima['sintesi_crediti'])->toBe(15000)
        ->and($prima['controlli'])->toBe(['PD' => 'quadra', 'R6' => 'quadra']);

    rlcComando($s);
    $dopo = rlcSpPagina($this, $s);
    // Dopo (cifre di R3): Crediti 0 (Bice 10000 − il credito di Ugo 10000), Gestione 20000 (le due rate intere), Passate −20000.
    expect($dopo['terna'])->toBe([0, 20000, -20000])
        ->and($dopo['sintesi_crediti'])->toBe(0)
        ->and($dopo['controlli'])->toBe(['PD' => 'quadra', 'R6' => 'quadra']);
});

it('R22 — la previsione di cassa: il riallineamento non muove denaro, e gli incassi attesi restano 15000 prima e dopo', function () {
    // Unità A di Ugo (200 millesimi) e B di Bice (800): per rata Ugo 2000, Bice 8000. Ugo ha −5000, «prima rata»: rata 1 di Ugo
    // 2000 − 5000 = −3000 (fuori dal giornale con la 1.10), totale della rata 1 = 8000 − 3000 = 5000; rata 2 = 10000.
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -5000], 200, 800);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 2000], '2026-03-10');
    // Compensazione: DARE «Crediti» 2000 sulla rata 1 (prelievo), AVERE 2000 sulla rata 2.
    // Incassi attesi, al netto delle righe con la rata fuori dall'emissione (P6): rata 1: 5000 − (0 − 2000) = 7000, cioè Bice 8000
    // meno il credito che resta a Ugo (1000); rata 2: 10000 − 2000 = 8000. Totale 15000.
    $prima = rlcPrevisione($s);

    rlcComando($s);
    // Il comando scrive la rettifica (AVERE «Crediti» 2000 e DARE «Passate» 2000, tutte e due con la rata 1: netto 0) e la quota
    // (DARE 2000, AVERE 5000 − 2000 = 3000: è un'emissione, fuori). Gli incassi attesi non cambiano: 15000. Il netto si legge su
    // tutte le righe con la rata (anche «Passate»), come la previsione netta della parte dell'emissione: contato solo su «Crediti»,
    // l'AVERE della rettifica sembrerebbe un incasso e la rata 1 scenderebbe da 7000 a 5000, totale 13000 (misurato). Con la
    // relazione di oggi (solo gli AVERE fuori dall'emissione, niente netto) la rata 1 passerebbe da 5000 a 3000: 13000 → 11000.
    expect(rlcSp($s))->toBe([15000, 20000, -5000])
        ->and(rlcPrevisione($s))->toBe($prima)
        ->and(rlcPrevisione($s))->toBe(15000);
});

it('R23 — sonda S1: la pagina della scrittura dà ai riquadri i dati per non dire «annullata» la compensazione, prima e dopo il comando: tipo e stato del padre e delle figlie', function () {
    $s = rlcScenario(12, 'rata_zero', ['ugo' => -20000]);
    rlcEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    $comp = rlcCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');

    // Il riquadro «Attenzione: movimento annullato» e quello «Movimento di storno» decidono con `figlieCheStornano()` e
    // `eStornoDelPadre()` (resources/js/lib/gestionale/scritture/storniScrittura.ts): servono tipo e stato di padre e figlie.
    // Già prima del comando: la compensazione ha per padre l'incasso, valido, e oggi la pagina la dice «Movimento di storno».
    $pagina = fn (int $id) => $this->actingAs($this->user)->get(route('admin.gestionale.scritture.show', [$s['c'], $id]))->assertOk()->viewData('page')['props']['scrittura'];
    expect($pagina($comp->id)['padre'])->toMatchArray(['id' => $comp->scrittura_padre_id, 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);

    $ultimo = rlcUltimoId();
    rlcComando($s);
    $rettifica = ScritturaContabile::where('id', '>', $ultimo)->firstOrFail();
    $compensazione = $pagina($comp->id);
    $figlia = collect($compensazione['figlie'])->firstWhere('id', $rettifica->id);
    expect($compensazione['stato'])->toBe('registrata')
        ->and($figlia)->toMatchArray(['tipo_movimento' => 'rettifica', 'stato' => 'registrata']);
    $pRettifica = $pagina($rettifica->id);
    expect($pRettifica['padre'])->toMatchArray(['id' => $comp->id, 'tipo_movimento' => 'storno_credito', 'stato' => 'registrata']);

    // Dopo lo storno dell'incasso (R7) la forma che il caso «R7» del vitest presuppone: la compensazione «annullata» ha due figlie
    // di tipo rettifica, la rettifica del riallineamento sigillata con lei («annullata») e il suo specchio («registrata»). Solo lo
    // specchio la annulla; un criterio che guardasse il tipo della figlia e lo stato del padre, senza lo stato della figlia,
    // elencherebbe anche la rettifica del riallineamento.
    rlcStorna($this, $s, $comp->scrittura_padre_id)->assertSessionHasNoErrors();
    $specchio = ScritturaContabile::where('scrittura_padre_id', $comp->id)->where('id', '!=', $rettifica->id)->firstOrFail();
    $compensazione = $pagina($comp->id);
    expect($compensazione['stato'])->toBe('annullata')
        ->and(collect($compensazione['figlie'])->map(fn ($f) => [$f['id'], $f['tipo_movimento'], $f['stato']])->sortBy(0)->values()->all())
        ->toBe([[$rettifica->id, 'rettifica', 'annullata'], [$specchio->id, 'rettifica', 'registrata']]);
});

// ─── La scrittura del comando è un'emissione legata alla quota (mancanza 4a del critico) ────────────────────────────────────

it('R24 — l\'annullamento dell\'emissione della rata mista porta via anche la scrittura del riallineamento, e la nuova emissione la riscrive uguale con la 71', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcComando($s);
    expect(rlcSp($s))->toBe([0, 20000, -20000]);

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => rlcRata($s, 1)->id]))
        ->assertSessionHasNoErrors();
    // Via l'emissione della 1.10 (Bice 5000) e quella del comando (Ugo: DARE 5000, AVERE 20000): resta la rata 2, Crediti 10000,
    // Gestione 10000, Passate 0. Se la scrittura del comando non fosse legata alla quota resterebbe a giornale da sola:
    // Crediti 10000 + 5000 − 20000 = −5000, Gestione 15000, Passate −20000 (misurato).
    expect(rlcSp($s))->toBe([10000, 10000, 0])
        ->and(rlcRata($s, 1)->fresh()->stato)->toBe('bozza')
        ->and(RataQuote::where('rata_id', rlcRata($s, 1)->id)->whereNotNull('scrittura_contabile_id')->count())->toBe(0);

    // La nuova emissione con la 71: Bice DARE 5000; Ugo DARE 5000 e AVERE 20000; Gestione AVERE 10000; Passate DARE 20000.
    rlcEmetti($this, $s, [1], '2026-04-01')->assertSessionHasNoErrors();
    expect(rlcSp($s))->toBe([0, 20000, -20000]);
    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta);
});

// ─── Mutazioni sopravvissute (fase finale della 1.11.0-beta.49): i test che le prendono ────────────────────────────────────

it('R26 — l\'anteprima della card dice gli importi che il comando scriverà: la quota della rata mista conta il credito che la rettifica di DC1 farà uscire prima (lo scenario di R3)', function () {
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    rlcEmettiComeLa110($s, [1, 2], '2026-02-28');
    rlcCompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');

    // La card: la rettifica di 5000 (€ 50,00) sulla rata 1, e la quota di Ugo sulla rata 1 con 5000 di spesa e 20000 − 5000 = 15000
    // di credito, perché la rettifica fa uscire 5000 da «Passate gestioni» prima che la quota vada a giornale (R3). Letto dal giornale
    // di adesso, senza la rettifica in arrivo, il credito sarebbe 20000: la card direbbe una cifra e il comando ne scriverebbe un'altra.
    $card = rlcCard($this, $s);
    expect(collect($card['compensazioni'])->map(fn ($c) => [$c['importo'], $c['importo_formattato'], $c['numero_rata'], $c['persona']])->all())->toBe([[5000, '€ 50,00', 1, 'Ugo Uno']])
        ->and(collect($card['rate'])->firstWhere('rata_id', rlcRata($s, 1)->id))->toMatchArray(['azione' => 'scrivi', 'persone' => [['nome' => 'Ugo Uno', 'spesa' => 5000, 'credito' => 15000]]]);

    // E il comando scrive quello che la card ha detto.
    $ultimo = rlcUltimoId();
    rlcComando($s);
    $emissione = ScritturaContabile::where('id', '>', $ultimo)->where('tipo_movimento', 'emissione_rata')->firstOrFail();
    expect(collect(rlcRighe($emissione->id))->where(0, 'crediti_condomini')->map(fn ($r) => [$r[1], $r[2]])->values()->all())->toBe([['avere', 15000], ['dare', 5000]]);
});

it('R27 — una rata «emessa» senza scritture che ha anche una quota da pagare non l\'hanno scritta così né la 1.9 né la 1.10: la card non la elenca e il comando non la tocca', function () {
    // Rata 1: Ugo 5000 − 20000 = −15000, Bice 5000. Segnata «emessa» senza nessuna scrittura (la 1.10 avrebbe scritto almeno la quota
    // di Bice): non è un dato della stabile, e il riallineamento non la propone all'amministratore.
    $s = rlcScenario(12, 'prima_rata', ['ugo' => -20000], 500, 500);
    DB::table('rate')->where('id', rlcRata($s, 1)->id)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);

    $impronta = rlcImpronta($s);
    rlcComando($s);
    expect(rlcImpronta($s))->toBe($impronta)
        ->and(rlcCard($this, $s))->toBeNull();
});
