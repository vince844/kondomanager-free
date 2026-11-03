<?php

/**
 * 1.11.0-beta.49, la contabilità dei crediti — i rilievi della Fase 1-bis dell'area «emissione dei piani della 1.7 e testi»
 * (`docs/piano_esecutivo_beta49.md`, «Fase 1-bis (11/10/2026)» e «La risposta su LDA-2»; decisioni 71, 75 e 76 del registro,
 * con il ritocco della 76.3 dell'11/10/2026).
 *
 * - LDA-2 (ritocco della 76.3): una quota a credito senza il dettaglio del calcolo (piani generati con la 1.7.x) ferma **la
 *   sola quota**, non la rata. Le altre quote della rata vanno a giornale e la rata diventa «emessa»; la quota ferma resta fuori
 *   con il suo credito; l'esito nomina la persona e la rata, e dice la strada; la card «Crediti da riallineare» la elenca fra le
 *   voci che restano come sono.
 * - K6: una quota a zero senza il dettaglio del calcolo (il credito pari alla spesa della rata) non si distingue da un credito
 *   che paga la spesa: si ferma come le quote a credito, invece di restare fuori dal giornale in silenzio.
 * - LDA-3: la card non manda a emettere una rata che l'emissione non emette (le quote senza dettaglio della 1.7.x; il credito
 *   già uscito tutto è in `RiallineaCreditiTest`, R1-bis, e la frase dell'esito in `CreditiEmissioneEstrattoContoTest`).
 * - LDA-5: il rifiuto di W1 dice la strada con le parole della pagina, non con i nomi del codice.
 * - LDA-6: il comando conta le scritture con il singolare e il plurale giusti, e nomina solo i tipi che ha scritto.
 * - LDA-7: senza il conto «Passate gestioni» il rifiuto della compensazione e del rimborso dice la strada e il codice del conto.
 * - LDA-8: con l'esercizio chiuso la card compare in sola lettura e dice che cosa resta com'è, anche quando è l'unica cosa da dire.
 *
 * Cosa NON copre: i testi delle pagine Vue e delle guide (LDA-4, e le parti di LDA-2, LDA-3, LDA-6 e LDA-7 che stanno nei
 * template), che non hanno un test PHP; la conferma dello storno (rilievo S3), che sta nel vitest
 * `resources/js/lib/gestionale/incassi/confermaStorno.test.ts`.
 *
 * I DATI DELLA 1.7. Come in `CreditiEmissioneEstrattoContoTest` (`ceDati17`): le quote del piano senza `regole_calcolo` (la colonna
 * nasce con la 1.8.0) e i saldi con `is_applicato` falso e senza piano (la migrazione del 01/03/2026). Le quote si generano con il
 * generatore di oggi, che somma il saldo, e il saldo è scritto con il segno di oggi (negativo = credito). La 1.7.0 il saldo lo
 * sottraeva (`v1.7.0 GenerateRateQuotesAction.php`, «$importo -= $quotaSaldo;»): con i suoi dati veri una quota di −7500 nasce da
 * un saldo salvato come +150000, che oggi si legge come debito (avvertenza dello scettico di LDA-2). Per l'emissione, il comando e
 * la card il segno del saldo non conta, perché leggono le quote; conta per l'estratto conto di chi ha il saldo, che qui (LDA-2,
 * Ugo) misura la regola «la quota ferma lascia il saldo libero», non la cifra che darebbero i dati veri della 1.7.0.
 * I DATI DELLA 1.10: l'emissione e la compensazione rifatte come in `RiallineaCreditiTest`.
 *
 * Tutte le cifre sono in centesimi. Segno dei saldi: positivo = debito, negativo = credito.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

// ─── Aiuti (prefisso lda49: «rve» lo usa CreditiRevisioneEstrattoTest) ────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Preventivo ordinario da 120000 in `$numeroRate` rate mensili dal 5 gennaio 2026, ripartito a millesimi sulle unità date
 * (['A' => ['ugo', 500], 'B' => ['bice', 500]]); i saldi pregressi [[persona|null, unità, centesimi]]; i conti per emettere.
 * La persona si chiama con il nome dato, con la maiuscola («Ugo», «Bice»).
 */
function lda49Scenario(array $unita, int $numeroRate, string $metodo, array $saldi = []): array
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
    $immobili = [];
    foreach ($unita as $nome => [$chi, $millesimi]) {
        $immobili[$nome] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "RVE-{$seq}-{$nome}", 'nome' => "Interno {$nome}", 'interno' => $nome]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobili[$nome]->id, 'valore' => (float) $millesimi, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "rve-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(str_pad('RVE' . $chi, 6, 'X')) . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$c->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($saldi as [$chi, $nome, $importo]) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $chi === null ? null : $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id,
            'gestione_id' => $g->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return compact('c', 'e', 'g', 'immobili', 'persone', 'piano', 'cassa');
}

/** I dati di un piano generato con la 1.7.x e portato avanti dalle migrazioni (vedi il docblock in testa). */
function lda49Dati17(array $s): void
{
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['is_applicato' => false, 'piano_rate_id' => null]);
}

function lda49Rata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function lda49Quota(array $s, string $chi, int $numero): int
{
    return (int) DB::table('rate_quote')->where('rata_id', lda49Rata($s, $numero))->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
}

/** L'emissione vera, dalla rotta della pagina del piano. */
function lda49Emetti($test, array $s, array $numeri, string $data)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => lda49Rata($s, $n), $numeri), 'data_emissione' => $data, 'invia_notifiche' => false,
    ]);
}

/**
 * L'emissione della 1.10.0, riga per riga (v1.10.0 EmissioneRateController.php:121-268, come `rlcEmettiComeLa110`): salta le
 * quote ≤ 0, scrive in DARE l'importo intero delle altre, chiude su «Gestione rate» e «Passate gestioni», cancella la testata
 * rimasta senza righe e segna «emessa» ogni rata richiesta.
 */
function lda49EmettiComeLa110(array $s, array $numeri, string $data): void
{
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    DB::transaction(function () use ($s, $numeri, $data, $conti) {
        foreach ($numeri as $n) {
            $rata = Rata::with('rateQuote')->findOrFail(lda49Rata($s, $n));
            $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['piano']->gestione_id,
                'data_registrazione' => now(), 'data_competenza' => $data, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
            $tg = 0;
            $tp = 0;
            foreach ($rata->rateQuote as $q) {
                $imp = (int) $q->importo;
                if ($imp <= 0) {
                    continue;
                }
                $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
                $g = (int) ($regole['importi']['quota_pura_gestione'] ?? $imp);
                $sc->righe()->create(['conto_contabile_id' => $conti['crediti_condomini'], 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id, 'rata_id' => $rata->id,
                    'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
                $q->update(['scrittura_contabile_id' => $sc->id]);
                $tg += $g;
                $tp += $imp - $g;
            }
            if ($tg + $tp > 0) {
                foreach ([[$conti['gestione_rate'], $tg, 'Totale emissione '], [$conti['passate_gestioni'], $tp, 'Riporto esercizi precedenti — ']] as [$conto, $t, $nota]) {
                    if ($t !== 0) {
                        $sc->righe()->create(['conto_contabile_id' => $conto, 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => $nota.$rata->descrizione]);
                    }
                }
            } else {
                $sc->forceDelete();
            }
        }
        Rata::whereIn('id', array_map(fn ($n) => lda49Rata($s, $n), $numeri))->update(['stato' => 'emessa']);
    });
}

/**
 * «Usa credito» di `$chi` dalla sua quota a credito della rata `$daRata` sulle sue quote delle rate `$su` (centesimi per rata), come
 * la 1.10: il prelievo e il riaccredito su «Crediti verso condomini» (come `rlcCompensaComeLa110`). Restituisce la compensazione.
 */
function lda49CompensaComeLa110(array $s, string $chi, int $daRata, array $su, string $il): ScritturaContabile
{
    $p = $s['persone'][$chi];
    $righe = [['rata_id' => lda49Quota($s, $chi, $daRata), 'importo' => -array_sum($su) / 100]];
    foreach ($su as $n => $cent) {
        $righe[] = ['rata_id' => lda49Quota($s, $chi, $n), 'importo' => $cent / 100];
    }
    $incasso = app(StoreIncassoRateAction::class)->execute(['pagante_id' => $p->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => $il, 'importo_totale' => 0, 'descrizione' => 'Compensazione', 'dettaglio_pagamenti' => $righe], $s['c'], $s['e']);
    $comp = ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'storno_credito')->firstOrFail();
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    DB::table('righe_scritture')->where('scrittura_id', $comp->id)->where('rata_id', lda49Rata($s, $daRata))->where('anagrafica_id', $p->id)
        ->where('conto_contabile_id', $conti['passate_gestioni'])->update(['conto_contabile_id' => $conti['crediti_condomini']]);

    return $comp;
}

/** Il saldo di un conto per ruolo, dare − avere, sulle scritture vive (con `$chi`, solo le righe di quella persona). */
function lda49Conto(array $s, string $ruolo, ?string $chi = null): int
{
    $q = DB::table('righe_scritture as r')->join('scritture_contabili as sc', 'sc.id', '=', 'r.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')
        ->where('sc.condominio_id', $s['c']->id)->whereNull('sc.deleted_at')->where('cc.ruolo', $ruolo);
    if ($chi !== null) {
        $q->where('r.anagrafica_id', $s['persone'][$chi]->id);
    }

    return (int) $q->sum(DB::raw("CASE WHEN r.tipo_riga = 'dare' THEN r.importo ELSE -r.importo END"));
}

/** Le righe della scrittura di emissione della rata: [ruolo, persona, verso, importo], in ordine. */
function lda49RigheEmissione(array $s, int $numero): array
{
    $id = DB::table('rate_quote')->where('rata_id', lda49Rata($s, $numero))->whereNotNull('scrittura_contabile_id')->value('scrittura_contabile_id');
    if ($id === null) {
        return [];
    }
    $nomi = collect($s['persone'])->mapWithKeys(fn ($a, $k) => [$a->id => $k]);

    return DB::table('righe_scritture as r')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $id)->orderBy('r.id')
        ->get(['cc.ruolo', 'r.anagrafica_id', 'r.tipo_riga', 'r.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->anagrafica_id ? $nomi[$r->anagrafica_id] : null, $r->tipo_riga, (int) $r->importo])->all();
}

/** Il saldo dell'estratto conto della persona, dalla pagina. */
function lda49Estratto($test, array $s, string $chi): int
{
    return (int) $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))
        ->assertOk()->viewData('page')['props']['stats']['saldo_raw'];
}

/** La prop della card «Crediti da riallineare» nella pagina «Incassi rate» (null: la card non compare). */
function lda49Card($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

/** I motivi delle voci che restano come sono, come li mostrano la card e il comando. */
function lda49Motivi(?array $rilevato): array
{
    return collect($rilevato['restano'] ?? [])->pluck('motivo')->all();
}

/** Il comando `kondomanager:riallinea-crediti` sul condominio dello scenario: quello che stampa. */
function lda49Comando(array $s, bool $prova = false): string
{
    $esito = Artisan::call('kondomanager:riallinea-crediti', array_filter(['--condominio' => $s['c']->id, '--dry-run' => $prova]));
    expect($esito)->toBe(0);

    return Artisan::output();
}

// ─── LDA-2, il ritocco della 76.3: si ferma la sola quota ────────────────────────────────────────────────────────────────────

it('LDA-2 (76.3 ritoccata) — dati della 1.7, «tutte le rate»: la quota a credito di Ugo senza il dettaglio del calcolo si ferma da sola; la quota di Bice va a giornale e la rata diventa «emessa»; l\'esito nomina Ugo e la rata e dice la strada; la card elenca la quota fra quelle che restano come sono', function () {
    // A e B da 500 millesimi: 60000 l'anno ciascuna, 5000 a rata. Credito di Ugo −150000 su dodici rate: −12500 a rata, ogni quota
    // di Ugo 5000 − 12500 = −7500; Bice 5000. Le quote senza `regole_calcolo` (1.7.x). La 1.10 ha emesso le rate 1 e 2 saltando la
    // quota di Ugo (Bice DARE 5000 per rata). Oggi l'emissione della rata 3 la ferma tutta: «La rata 3 non è stata emessa … la rata
    // resta in bozza», e i 5000 di Bice non vanno mai a giornale (10 rate × 5000 = € 500,00 con le rate 3–12).
    $s = lda49Scenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    lda49Dati17($s);
    expect([(int) DB::table('rate_quote')->where('id', lda49Quota($s, 'ugo', 3))->value('importo'), (int) DB::table('rate_quote')->where('id', lda49Quota($s, 'bice', 3))->value('importo')])->toBe([-7500, 5000]);
    lda49EmettiComeLa110($s, [1, 2], '2026-02-28');

    $r = lda49Emetti($this, $s, [3], '2026-03-31');
    $r->assertSessionHasNoErrors();
    $esito = $r->getSession()->get('esito_emissione');

    // La rata 3 va a giornale per Bice: DARE «Crediti» 5000 a suo nome, AVERE «Gestione rate» 5000. La quota di Ugo resta senza
    // scrittura, e niente si muove su «Passate gestioni»: il suo credito resta dove sta (`RataQuote::ruoloDelCredito`).
    expect(DB::table('rate')->where('id', lda49Rata($s, 3))->value('stato'))->toBe('emessa')
        ->and(DB::table('rate_quote')->where('id', lda49Quota($s, 'bice', 3))->value('scrittura_contabile_id'))->not->toBeNull()
        ->and(DB::table('rate_quote')->where('id', lda49Quota($s, 'ugo', 3))->value('scrittura_contabile_id'))->toBeNull()
        ->and(lda49RigheEmissione($s, 3))->toBe([['crediti_condomini', 'bice', 'dare', 5000], ['gestione_rate', null, 'avere', 5000]])
        // Il giornale: Bice 3 × 5000 = 15000 su «Crediti»; «Gestione rate» 15000 in avere; «Passate» 0; Ugo 0.
        ->and([lda49Conto($s, 'crediti_condomini', 'bice'), lda49Conto($s, 'crediti_condomini', 'ugo'), lda49Conto($s, 'gestione_rate'), lda49Conto($s, 'passate_gestioni')])
            ->toBe([15000, 0, -15000, 0])
        // Al capolinea: l'estratto di Bice porta la rata 3 (15000); quello di Ugo tiene il suo credito intero (−150000), perché
        // nessuna sua quota è a giornale e il saldo resta libero (con il segno di oggi: vedi il docblock in testa).
        ->and([lda49Estratto($this, $s, 'bice'), lda49Estratto($this, $s, 'ugo')])->toBe([15000, -150000]);

    // L'esito conta la rata emessa e nomina chi è rimasto fuori, e su quale rata; dice perché (il dettaglio del calcolo) e la strada
    // senza consigliare il solo ricalcolo (correzione rivista dello scettico di LDA-2): il ricalcolo, che chiede di annullare prima
    // incassi ed emissioni del piano, e il saldo pregresso da controllare prima (la 1.7.0 lo sottraeva).
    expect($esito['titolo'])->toBe('Emissione completata')
        ->and($esito['testo'])->toStartWith('È stata emessa 1 rata.')
        ->and($esito['testo'])->toContain('Ugo')
        ->and($esito['testo'])->toContain('rata 3')
        ->and($esito['testo'])->toContain('dettaglio del calcolo')
        ->and($esito['testo'])->toContain('ricalcol')
        ->and($esito['testo'])->toContain('annull')
        ->and($esito['testo'])->toContain('saldo')
        ->and($esito['testo'])->not->toContain('La rata 3 non è stata emessa');

    // La card compare e la elenca fra le voci che restano come sono, con la persona e la rata. Niente da scrivere: la quota non si
    // indovina, e il comando non la tocca.
    $card = lda49Card($this, $s);
    expect($card)->not->toBeNull()
        ->and(collect(lda49Motivi($card))->contains(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 3') && str_contains($m, 'dettaglio del calcolo')))->toBeTrue()
        ->and($card['compensazioni'])->toBe([])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([]);
});

// ─── K6: la quota a zero della 1.7 ───────────────────────────────────────────────────────────────────────────────────────────

it('K6 — dati della 1.7, «prima rata»: la quota di Ugo a zero (il credito pari alla spesa) senza il dettaglio del calcolo non si distingue da un credito che paga la spesa: si ferma come le quote a credito, e l\'esito e la card lo dicono', function () {
    // A e B da 500 millesimi, 5000 a rata ciascuna. Credito di Ugo −5000, «prima rata»: rata 1 di Ugo 5000 − 5000 = 0; Bice 5000.
    // Le quote senza `regole_calcolo` (1.7.x): per la quota di Ugo `componenti()` dà spesa 0 e riporto 0, e la condizione della
    // fermata («importo < 0») non la prende. Oggi la rata va a giornale con la sola quota di Bice e nessuno lo dice: «Gestione
    // rate» conta 5000 invece di 10000, e il credito di Ugo resta su «Passate gestioni» senza consumarsi. Con la 71 la quota andrebbe
    // a giornale con la spesa e il credito (S5); senza il dettaglio non si separano, e la 1.7.0 una quota a zero la creava solo così
    // (saltava le unità senza spesa, `v1.7.0 GenerateRateQuotesAction.php`: «if ($totaleImmobile == 0) continue;»).
    $s = lda49Scenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -5000]]);
    lda49Dati17($s);
    expect((int) DB::table('rate_quote')->where('id', lda49Quota($s, 'ugo', 1))->value('importo'))->toBe(0);

    $r = lda49Emetti($this, $s, [1], '2026-01-31');
    $r->assertSessionHasNoErrors();
    $esito = $r->getSession()->get('esito_emissione');

    // La quota di Bice va a giornale, la rata è «emessa» (oggi è già così, ed è giusto con la 76.3 ritoccata); quella di Ugo resta
    // fuori, e l'esito la nomina come le quote a credito senza dettaglio (oggi: «È stata emessa 1 rata.» e basta).
    expect($esito['testo'])->toContain('Ugo')
        ->and($esito['testo'])->toContain('rata 1')
        ->and($esito['testo'])->toContain('dettaglio del calcolo')
        ->and($esito['titolo'])->toBe('Emissione completata')
        ->and(DB::table('rate')->where('id', lda49Rata($s, 1))->value('stato'))->toBe('emessa')
        ->and(DB::table('rate_quote')->where('id', lda49Quota($s, 'ugo', 1))->value('scrittura_contabile_id'))->toBeNull()
        ->and(lda49RigheEmissione($s, 1))->toBe([['crediti_condomini', 'bice', 'dare', 5000], ['gestione_rate', null, 'avere', 5000]]);

    // La card la elenca fra le voci che restano come sono (oggi il riallineamento la scarta in silenzio: `restano` vuoto).
    $card = lda49Card($this, $s);
    expect($card)->not->toBeNull()
        ->and(collect(lda49Motivi($card))->contains(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 1')))->toBeTrue();
});

// ─── LDA-3: la card non manda a emettere rate che l'emissione non emette ─────────────────────────────────────────────────────

it('LDA-3 — dati della 1.7 segnati «emessi» dalla 1.10: la rata di soli crediti senza il dettaglio del calcolo non va fra quelle da emettere, perché l\'emissione non la emette; la card e il comando la dicono fra quelle che restano come sono', function () {
    // «Prima rata», credito di Ugo −20000, unico condòmino: rata 1 = 10000 − 20000 = −10000, senza `regole_calcolo` (1.7.x). La 1.10
    // l'ha segnata «emessa» senza righe (ha saltato la quota e cancellato la testata vuota). Oggi la card la manda a emettere («Si
    // emette dalla pagina del piano, con la data e l'avviso che scegli tu»), e il comando la elenca «Da emettere dalla pagina del
    // piano»; ma l'emissione la ferma per il dettaglio mancante (76.3), la riporta in bozza, e la card sparisce senza che il credito
    // di Ugo sia mai entrato nel giornale.
    $s = lda49Scenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    lda49Dati17($s);
    lda49EmettiComeLa110($s, [1], '2026-01-31');
    expect(DB::table('rate')->where('id', lda49Rata($s, 1))->value('stato'))->toBe('emessa')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count())->toBe(0);

    $rilevato = app(\App\Services\Gestionale\RiallineaCreditiService::class)->rileva($s['c']);
    expect(collect($rilevato['da_emettere'])->pluck('rata_id')->all())->toBe([])
        ->and(collect(lda49Motivi($rilevato))->contains(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 1') && str_contains($m, 'dettaglio del calcolo')))->toBeTrue();

    // La card dice la stessa cosa, in sola lettura (niente da scrivere, niente da emettere).
    $card = lda49Card($this, $s);
    expect($card)->not->toBeNull()
        ->and($card['da_emettere'])->toBe([])
        ->and(collect(lda49Motivi($card))->contains(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 1')))->toBeTrue();

    // Il comando: fra quelle che restano come sono, non fra quelle da emettere; e non scrive niente.
    $uscita = lda49Comando($s);
    expect($uscita)->toContain('Restano come sono')
        ->and($uscita)->not->toContain('Da emettere dalla pagina del piano')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->count())->toBe(0);

    // Se l'amministratore la emette comunque dalla pagina del piano, l'esito nomina Ugo e il dettaglio mancante.
    $esito = lda49Emetti($this, $s, [1], '2026-03-20')->getSession()->get('esito_emissione');
    expect($esito['testo'])->toContain('Ugo')
        ->and($esito['testo'])->toContain('dettaglio del calcolo');
});

// ─── LDA-5: il rifiuto di W1 con le parole della pagina ──────────────────────────────────────────────────────────────────────

it('LDA-5 — il rifiuto di W1 (decisione 75.2) dice dove sta la strada con le parole della pagina, il pulsante «Ricalcola», non con i nomi del codice («i rimedi del fermo»)', function () {
    // Lo scenario di W1 (`StatoDelPianoDopoPassaggioTest`, R10 di `RiallineaCreditiTest`): 6 rate da 20000, «prima rata», credito di
    // Ugo −67100, rata 1 = −47100 «emessa» senza scritture come la lasciava la .41; la vendita a Elsa registrata allora, con il
    // registro senza `piani_presi` e la coppia ±17973 in saldi. La pagina del piano non mostra un «fermo» né dei «rimedi»: sul
    // pulsante «Ricalcola» scrive «Disabilitato: …» e «Per riaprirlo:» (`PianiRateShow.vue`, `fermo_del_piano.rimedi`).
    $s = lda49Scenario(['A' => ['ugo', 1000]], 6, 'prima_rata', [['ugo', 'A', -67100]]);
    $elsa = Anagrafica::forceCreate(['nome' => 'Elsa', 'email' => 'rve-elsa@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RVEELSAXXXXXXXXX']);
    $elsa->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('rate')->where('id', lda49Rata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['ugo']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['immobili']['A']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-02-07',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-02-07',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano da ricalcolare',
    ])->assertSessionHasNoErrors();
    $p1 = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();
    $registro = $p1->registro;
    unset($registro['piani_presi']);
    $p1->update(['registro' => array_replace($registro, ['versione' => 1])]);
    foreach ([[$s['persone']['ugo']->id, -17973], [$elsa->id, 17973]] as [$chi, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'immobile_id' => $s['immobili']['A']->id, 'anagrafica_id' => $chi,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $p1->id, 'descrizione' => 'Conguaglio della .41']);
    }
    // Il giorno dopo, sempre con la .41, la rata 2 va a giornale (come nel test W1): il piano è preso, e l'emissione arriva a W1.
    $this->travel(1)->days();
    DB::table('rate')->where('id', lda49Rata($s, 2))->update(['stato' => 'emessa', 'data_emissione' => '2026-02-08']);
    aGiornaleNeiTest((int) $s['piano']->id, [lda49Rata($s, 2)]);
    $this->travel(1)->days();
    expect($s['piano']->fresh()->presoSoloInParteDa($p1))->toBeTrue();

    $esito = lda49Emetti($this, $s, [1], '2026-02-10')->getSession()->get('esito_emissione');

    // È il rifiuto di W1 (la rata resta «emessa» senza scritture), e la strada si dice con il nome del pulsante della pagina.
    expect($esito['testo'])->toStartWith('La rata 1 non è stata emessa: è segnata come emessa senza scritture')
        ->and($esito['testo'])->not->toContain('rimedi del fermo')
        ->and($esito['testo'])->toContain('«Ricalcola»')
        ->and(DB::table('rate')->where('id', lda49Rata($s, 1))->value('stato'))->toBe('emessa');
});

// ─── LDA-6: le concordanze del comando ───────────────────────────────────────────────────────────────────────────────────────

it('LDA-6 — il comando dice quante scritture ha fatto con il singolare e il plurale giusti, e nomina solo i tipi che ha scritto', function () {
    // (a) Solo una rettifica di DC1: rata zero di soli crediti (Ugo −20000) emessa come la 1.10, il credito usato tutto sulle rate 1
    // e 2 con il prelievo su «Crediti». Oggi: «→ scritte 1 rettifiche (…) e 0 emissioni (—).».
    $a = lda49Scenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lda49EmettiComeLa110($a, [0, 1, 2], '2026-02-28');
    lda49CompensaComeLa110($a, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $uscitaA = lda49Comando($a);
    expect(DB::table('scritture_contabili')->where('condominio_id', $a['c']->id)->where('tipo_movimento', 'rettifica')->count())->toBe(1)
        ->and($uscitaA)->not->toContain('1 rettifiche')
        ->and($uscitaA)->toContain('1 rettifica')
        ->and($uscitaA)->not->toContain('0 emissioni');

    // (b) Solo l'emissione di una quota rimasta fuori da una rata mista (DC2-b): Ugo −15000 sulla rata 1, Bice 5000, emessa come la
    // 1.10. Oggi: «→ scritte 0 rettifiche (—) e 1 emissioni (…).».
    $b = lda49Scenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    lda49EmettiComeLa110($b, [1, 2], '2026-02-28');
    $uscitaB = lda49Comando($b);
    expect(DB::table('rate_quote')->where('id', lda49Quota($b, 'ugo', 1))->value('scrittura_contabile_id'))->not->toBeNull()
        ->and($uscitaB)->not->toContain('1 emissioni')
        ->and($uscitaB)->toContain('1 emissione')
        ->and($uscitaB)->not->toContain('0 rettifiche');
});

// ─── LDA-7: il rifiuto senza «Passate gestioni» dice la strada ───────────────────────────────────────────────────────────────

it('LDA-7 — senza il conto «Passate gestioni» il rifiuto della compensazione e del rimborso di un credito riportato dice la strada e il codice del conto, come l\'emissione', function () {
    // «Rata zero», credito di Ugo −20000; emesse le rate 1 e 2; poi il conto «Passate gestioni» non c'è più. Il conto che il
    // programma crea si chiama «Fondo Passate Gestioni», codice 2301 (`CondominioService`). La strada che diceva la correzione di
    // LDA-7, «ricrealo dal piano dei conti», non esiste: nel programma il piano dei conti è il preventivo, e il conto di sistema
    // dalle pagine non si ricrea (giro stretto sulle correzioni della Fase 1-bis, LT-2). La frase lo dice, con un nome solo.
    $s = lda49Scenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lda49Emetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();
    DB::table('casse')->where('id', $s['cassa']->id)->update(['saldo_iniziale' => 10000]);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => lda49Quota($s, 'ugo', 1), 'importo' => '100.00'], ['rata_id' => lda49Quota($s, 'ugo', 0), 'importo' => '-100.00']],
    ])->assertSessionHasErrors('dettaglio_pagamenti');
    $fraseIncasso = session('errors')->first('dettaglio_pagamenti');

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['ugo']]), [
        'rata_quote_id' => lda49Quota($s, 'ugo', 0), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-11', 'importo' => '50,00',
    ])->assertSessionHasErrors('rata_quote_id');
    $fraseRimborso = session('errors')->first('rata_quote_id');

    expect($fraseIncasso)->toContain('«Fondo Passate Gestioni»')
        ->and($fraseIncasso)->not->toContain('piano dei conti')
        ->and($fraseIncasso)->toContain('va ripristinato da chi gestisce l\'installazione')
        ->and($fraseIncasso)->toContain('2301')
        ->and($fraseRimborso)->toBe($fraseIncasso)
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->whereIn('tipo_movimento', ['incasso_rata', 'storno_credito', 'rimborso_condomino'])->count())->toBe(0);
});

// ─── LDA-8: con l'esercizio chiuso la card lo dice ───────────────────────────────────────────────────────────────────────────

it('LDA-8 — esercizio chiuso: il riallineamento non scrive in un bilancio chiuso, e la card lo dice anche quando è l\'unica cosa da dire: compare in sola lettura, senza niente da scrivere, con le voci che restano come sono', function () {
    // Lo scenario di R12 (`RiallineaCreditiTest`): rata 1 mista della 1.10 (Ugo −15000 fuori, Bice 5000), la compensazione di 5000
    // dal credito di Ugo sulla rata 2 con il prelievo su «Crediti» (DC1); poi il 2026 si chiude, il 2027 è aperto. Le due voci non
    // si scrivono (un bilancio chiuso non si tocca). Oggi la card non compare (`vuoto()` ignora `restano`): che quei crediti restino
    // sbagliati lo dicono solo il terminale e la conferma di una pagina aperta prima. La 76.3 ritoccata vuole la card che elenca le
    // voci che restano come sono: la regola di `vuoto()` è la stessa.
    $s = lda49Scenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    lda49EmettiComeLa110($s, [1, 2], '2026-02-28');
    lda49CompensaComeLa110($s, 'ugo', 1, [2 => 5000], '2026-03-10');
    $s['e']->update(['stato' => 'chiuso']);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo('2027-02-01 10:00:00');

    $card = lda49Card($this, $s);
    expect($card)->not->toBeNull()
        ->and(count(lda49Motivi($card)))->toBe(2)
        ->and(collect(lda49Motivi($card))->every(fn ($m) => str_contains($m, 'chiuso')))->toBeTrue()
        // Sola lettura: niente da scrivere (il pulsante «Scrivi…» della pagina compare solo con qualcosa da scrivere) e niente da emettere.
        ->and($card['compensazioni'])->toBe([])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([])
        ->and($card['da_emettere'])->toBe([]);
});
