<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Services\RipartoCapitoliService;
use App\Services\RipartoTabelleService;
use Illuminate\Support\Facades\DB;

/**
 * CODA 76 — Il residuo che sommava due cose opposte.
 *
 * La stampa del riparto per capitoli costruisce la matrice sugli importi **lordi** dei capitoli, che
 * non conoscono il netting del già versato, e poi la riallinea alle quote realmente emesse con un
 * «residuo» per riga. Fin qui è corretto, ed è la garanzia legale di questa stampa: la riga stampata
 * deve valere quanto `rate_quote`.
 *
 * Il guaio era **dove finiva quel residuo**, e cosa conteneva. Dentro ci sono due grandezze di segno
 * opposto — il pregresso, che aumenta il dovuto, e il già versato, che lo diminuisce — e finivano
 * sommate sul **capitolo a peso maggiore**, cioè sulla voce di spesa più grossa. Sul caso misurato
 * il 23/08/2026 (quattro unità, € 5.000,00 di lavori, € 1.600,00 di amministrazione, € 2.000,00 già
 * versati e € 1.800,00 di arretrati) la stampa mostrava «Rifacimento facciata € 4.800,00»: né il
 * deliberato (€ 5.000,00), né quanto veniva chiesto (€ 3.000,00). E il pregresso di € 1.800,00 non
 * compariva da nessuna parte.
 *
 * Il gran totale tornava — € 6.400,00 — ed è esattamente ciò che rendeva il difetto invisibile a un
 * controllo di quadratura.
 *
 * ⚠️ **Nella beta.75 si era tentato di classificare il residuo per segno**, e la correzione è stata
 * ritirata prima del rilascio: il segno di una somma dice quale addendo è maggiore, non quanto
 * valgono. Con € 1.800,00 di pregresso e € 2.000,00 di già versato il residuo vale −€ 200,00, e quel
 * numero non è nessuna delle due grandezze.
 *
 * La separazione non ha bisogno di euristiche perché **una delle due componenti è già registrata**:
 * `regole_calcolo.importi.saldo_usato`, scritto su ogni quota da `GenerateRateQuotesAction`. Da lì
 * il netting si ottiene per differenza, esatto.
 */

/** Il condominio del caso misurato: due capitoli, quattro unità da 250 millesimi. */
function dcScenario(): object
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Ordinaria',
        'data_inizio'   => '2026-01-01',
        'tipo'          => 'ordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'Preventivo 2026',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Millesimi Proprietà',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    // La facciata è il capitolo a peso maggiore: è quello su cui il residuo finiva.
    $facciata = dcConto($pianoConto, $tabella, 'Rifacimento facciata', 500_000);
    $ammin    = dcConto($pianoConto, $tabella, 'Amministrazione', 160_000);

    $unita = [];
    foreach (range(1, 4) as $n) {
        $unita[] = dcUnita($tabella, $n, 250);
    }

    return (object) compact('condominio', 'esercizio', 'gestione', 'tabella', 'facciata', 'ammin', 'unita');
}

function dcConto(PianoConto $pianoConto, Tabella $tabella, string $nome, int $importo): Conto
{
    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => $nome,
        'importo'        => $importo,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $conto->id,
        'tabella_id'   => $tabella->id,
        'coefficiente' => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto'                     => 'proprietario',
        'percentuale'                  => 100,
        'created_at'                   => now(),
        'updated_at'                   => now(),
    ]);

    return $conto;
}

function dcUnita(Tabella $tabella, int $n, int $millesimi): object
{
    $immobile = Immobile::forceCreate([
        'condominio_id' => $tabella->condominio_id,
        'nome'          => 'Interno '.$n,
        'descrizione'   => 'Appartamento',
        'interno'       => (string) $n,
    ]);

    $anagrafica = Anagrafica::forceCreate([
        'nome'           => 'Condomino '.$n,
        'email'          => 'dc'.$n.'@example.com',
        'indirizzo'      => 'Via Ostiense 40',
        'codice_fiscale' => 'DCTEST'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
    ]);

    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id'   => $immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100.0,
        'attivo'        => true,
        'data_inizio'   => now()->format('Y-m-d'),
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id'  => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore'      => $millesimi,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return (object) ['immobile' => $immobile, 'anagrafica' => $anagrafica];
}

/** Quanto quest'unità ha già versato verso una voce di spesa. */
function dcGiaVersato(object $sc, object $unita, Conto $conto, int $cents): void
{
    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $conto->id,
        'immobile_id'   => $unita->immobile->id,
        'anagrafica_id' => $unita->anagrafica->id,
        'importo_cents' => $cents,
        'natura'        => 'fondo_vincolato',
        'origine'       => 'migrazione',
        'descrizione'   => 'Versato nel 2025',
    ]);
}

/** Il saldo di apertura: positivo = debito del condòmino. */
function dcPregresso(object $sc, object $unita, int $cents): void
{
    Saldo::create([
        'esercizio_id'   => $sc->esercizio->id,
        'condominio_id'  => $sc->condominio->id,
        'anagrafica_id'  => $unita->anagrafica->id,
        'immobile_id'    => $unita->immobile->id,
        'gestione_id'    => $sc->gestione->id,
        'saldo_iniziale' => $cents,
        'origine'        => 'importato',
        'is_applicato'   => false,
    ]);
}

function dcGeneraPiano(object $sc): PianoRate
{
    $piano = PianoRate::create([
        'gestione_id'          => $sc->gestione->id,
        'condominio_id'        => $sc->condominio->id,
        'nome'                 => 'Preventivo 2026',
        'stato'                => 'bozza',
        'tipo'                 => 'ordinario',
        'numero_rate'          => 1,
        'giorno_scadenza'      => 5,
        'metodo_distribuzione' => 'rata_zero',
        'applica_saldi'        => true,
        'attivo'               => true,
    ]);

    $piano->capitoli()->sync([
        $sc->facciata->id => ['importo' => 500_000],
        $sc->ammin->id    => ['importo' => 160_000],
    ]);

    app(GeneratePianoRateAction::class)->execute($piano);

    return $piano;
}

// ─────────────────────────────────────────────────────────────────────────────

/**
 * ★ IL CASO MISURATO IN ROADMAP, con i suoi numeri.
 */
it('separa il pregresso dal già versato invece di sommarli sul capitolo più grosso', function () {
    $sc = dcScenario();

    // € 500,00 già versati da ciascuna delle quattro unità sulla facciata: € 2.000,00.
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
    }
    // € 450,00 di arretrati a testa: € 1.800,00.
    foreach ($sc->unita as $u) {
        dcPregresso($sc, $u, 45_000);
    }

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));
    $tot     = $matrice['tot_per_capitolo'];

    // Il capitolo torna a valere il **deliberato**. Prima stampava € 4.800,00: né il budget
    // (€ 5.000,00), né quanto viene chiesto (€ 3.000,00) — un numero che non rispondeva a nessuna
    // domanda che si possa fare in assemblea.
    expect($tot[$sc->facciata->id])->toBe(500_000)
        ->and($tot[$sc->ammin->id])->toBe(160_000);

    // E le due grandezze hanno ciascuna la sua colonna, col suo segno.
    expect($tot[RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-200_000)
        ->and($tot[RipartoCapitoliService::COLONNA_PREGRESSO])->toBe(180_000);

    // Il gran totale non cambia — ed è il motivo per cui il difetto era invisibile a un controllo
    // di quadratura: tornava anche prima.
    expect($matrice['gran_totale'])->toBe(640_000);
});

it('non lascia niente fuori riparto quando le due fonti spiegano tutto', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
        dcPregresso($sc, $u, 45_000);
    }

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));

    // La pseudo-colonna «Fuori riparto» esiste per ciò che nessuno spiega. Qui è tutto spiegato,
    // quindi non deve nemmeno comparire: una colonna a zero in un documento d'assemblea è una
    // domanda che qualcuno farà.
    expect($matrice['tot_per_capitolo'])
        ->not->toHaveKey(RipartoCapitoliService::COLONNA_FUORI_RIPARTO);
});

/**
 * L'invariante che su questa matrice non aveva un presidio, misurata cella per cella.
 */
it('ogni riga vale la somma delle sue celle, colonne nuove comprese', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
        dcPregresso($sc, $u, 45_000);
    }

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));

    $sommaRighe = 0;
    foreach ($matrice['righe'] as $riga) {
        foreach ($riga['soggetti'] as $sogg) {
            // Ogni cella è `['quota' => millesimi, 'importo' => centesimi]`: si somma la seconda.
            expect(array_sum(array_column($sogg['per_capitolo'], 'importo')))->toBe($sogg['totale']);
            $sommaRighe += $sogg['totale'];
        }
    }

    // Ogni unità: € 1.250,00 di facciata + € 400,00 di amministrazione − € 500,00 già versati
    // + € 450,00 di arretrati = € 1.600,00.
    expect($sommaRighe)->toBe(640_000)
        ->and($matrice['gran_totale'])->toBe(640_000);
});

it('col solo pregresso lo mostra nella sua colonna, senza gonfiare nessun capitolo', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcPregresso($sc, $u, 45_000);
    }

    $tot = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc))['tot_per_capitolo'];

    expect($tot[$sc->facciata->id])->toBe(500_000)
        ->and($tot[RipartoCapitoliService::COLONNA_PREGRESSO])->toBe(180_000)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_GIA_VERSATO);
});

it('col solo già versato il capitolo resta al deliberato e lo sconto si legge a parte', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
    }

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));
    $tot     = $matrice['tot_per_capitolo'];

    expect($tot[$sc->facciata->id])->toBe(500_000)
        ->and($tot[RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-200_000)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_PREGRESSO)
        // Chiesto davvero: € 6.600,00 di deliberato meno € 2.000,00 già in cassa.
        ->and($matrice['gran_totale'])->toBe(460_000);
});

it('senza né pregresso né già versato la stampa non cambia di un centesimo', function () {
    $sc  = dcScenario();
    $tot = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc))['tot_per_capitolo'];

    // Retrocompatibilità: nessuna delle due colonne nuove compare su un condominio ordinario,
    // che è il caso di tutte le installazioni esistenti.
    expect($tot[$sc->facciata->id])->toBe(500_000)
        ->and($tot[$sc->ammin->id])->toBe(160_000)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_GIA_VERSATO)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_PREGRESSO)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_FUORI_RIPARTO);
});

/**
 * Le due stampe devono continuare a raccontare la stessa cifra. La gemella non ha le colonne
 * separate — lì il residuo va tutto in «addebito diretto» dalla beta.73 — ma il gran totale è la
 * cifra che finisce nel verbale, e deve coincidere.
 */
it('la stampa gemella per tabelle resta d\'accordo sul gran totale', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
        dcPregresso($sc, $u, 45_000);
    }
    $piano = dcGeneraPiano($sc);

    $perCapitoli = (new RipartoCapitoliService())->buildMatrice($piano);
    $perTabelle  = (new RipartoTabelleService())->buildMatrice($piano);

    expect($perCapitoli['gran_totale'])->toBe($perTabelle['gran_totale'])
        ->and($perCapitoli['gran_totale'])->toBe(640_000);
});

/**
 * Il collo di bottiglia onesto: chi ha versato più della sua parte.
 *
 * Il motore limita il netting al lordo dell'unità (`min($copertura, $lordoImmobile)`), quindi il
 * versato in eccesso **non** viene scontato — vive in `getEccedenzeCopertura()` e ha una sua
 * segnalazione. La stampa deve seguire lo stesso limite: se mostrasse l'intero registrato,
 * dichiarerebbe uno sconto che nessuno ha ricevuto.
 */
it('non sconta più di quanto il motore abbia davvero applicato', function () {
    $sc = dcScenario();

    // Prima unità: € 2.000,00 versati contro una quota di facciata da € 1.250,00.
    dcGiaVersato($sc, $sc->unita[0], $sc->facciata, 200_000);

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));
    $u       = $sc->unita[0];
    $riga    = $matrice['righe'][$u->immobile->id]['soggetti'][$u->anagrafica->id];

    // La riga non può scendere sotto zero: il netting si ferma dove finisce il dovuto.
    expect($riga['totale'])->toBeGreaterThanOrEqual(0)
        ->and(array_sum(array_column($riga['per_capitolo'], 'importo')))->toBe($riga['totale']);

    // E lo sconto dichiarato non supera il lordo della riga: € 1.250,00 di facciata più € 400,00
    // di amministrazione. I € 2.000,00 registrati non si vedono, perché non sono stati applicati.
    $cella  = $riga['per_capitolo'][RipartoCapitoliService::COLONNA_GIA_VERSATO] ?? null;
    $sconto = abs($cella['importo'] ?? 0);
    expect($sconto)->toBeLessThanOrEqual(165_000);
});

/**
 * Le colonne esistono nella matrice: questo verifica che arrivino sulla **carta**.
 *
 * Il modello itera `$matrice['capitoli']` senza sapere quali siano pseudo-colonne, quindi «si
 * disegnano da sole» è una deduzione ragionevole — ed è esattamente il tipo di deduzione che su
 * questo progetto è già stata smentita da un test. Qui si rende il blade e si legge l'HTML.
 */
it('stampa le due colonne nuove con i loro nomi e i loro importi', function () {
    $sc = dcScenario();
    foreach ($sc->unita as $u) {
        dcGiaVersato($sc, $u, $sc->facciata, 50_000);
        dcPregresso($sc, $u, 45_000);
    }
    $piano   = dcGeneraPiano($sc);
    $matrice = (new RipartoCapitoliService())->buildMatrice($piano);

    $html = view('pdf.gestionale.riparto_capitoli', [
        'condominio' => $sc->condominio,
        'esercizio'  => $sc->esercizio,
        'pianoRate'  => $piano,
        'matrice'    => $matrice,
        'nCapitoli'  => count($matrice['capitoli']),
    ])->render();

    // Le intestazioni.
    expect($html)->toContain('Già versato')
        // ⚠️ Il nome sta in **sedici** caratteri per una ragione misurata: il modello tronca a
        // `Str::limit(..., 22)` con sei colonne o meno, e a 12 sopra. «Saldi esercizi precedenti»
        // ne ha 25 e in stampa diventava «Saldi esercizi prece…».
        ->and($html)->toContain('Saldi precedenti')
        // La legenda spiega le due colonne: un numero negativo in un documento d'assemblea senza
        // una riga che lo giustifichi è una domanda che qualcuno farà.
        ->and($html)->toContain('art. 63 disp. att. c.c.')
        ->and($html)->toContain('gestioni chiuse')
        // Il deliberato della facciata, che prima stampava 4.800,00.
        ->and($html)->toContain('5.000,00')
        ->and($html)->not->toContain('4.800,00')
        // Le due grandezze, ciascuna leggibile.
        ->and($html)->toContain('1.800,00')
        ->and($html)->toContain('2.000,00');
});

/**
 * ★ IL CASO CHE IL PRIMO TEST DI QUESTA CORREZIONE NON VEDEVA.
 *
 * Un condòmino con € 600,00 di arretrati che ha versato in anticipo esattamente € 600,00 ha residuo
 * **zero**: le due componenti si annullano. La prima stesura guardava il solo residuo per decidere se
 * decomporre, quindi su quella riga saltava tutto e le due colonne restavano vuote — la stampa
 * dichiarava che non aveva arretrati e non aveva versato niente, mentre aveva entrambi.
 *
 * **Il totale di riga restava giusto**, perché si annullano davvero: nessuna quadratura poteva
 * accorgersene, e infatti nessuna se n'era accorta.
 *
 * Trovato il 24/08/2026 generando il PDF vero del condominio `Via Ostiense 40`, costruito
 * dall'interfaccia per la verifica a video: su € 1.800,00 di pregresso la stampa ne mostrava
 * € 1.200,00. I test sintetici davano a tutte e quattro le unità gli stessi importi, e **con quote
 * uguali il residuo non è mai zero** — la simmetria dello scenario nascondeva il caso.
 */
it('non perde le due componenti quando si annullano esattamente fra loro', function () {
    $sc = dcScenario();

    // Una sola unità, e i due importi identici: € 600,00 di arretrati, € 600,00 già versati.
    dcGiaVersato($sc, $sc->unita[1], $sc->facciata, 60_000);
    dcPregresso($sc, $sc->unita[1], 60_000);

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));
    $u       = $sc->unita[1];
    $riga    = $matrice['righe'][$u->immobile->id]['soggetti'][$u->anagrafica->id];

    // Il residuo di questa riga è zero: è la condizione del difetto.
    $lordo = 125_000 + 40_000;
    expect($riga['totale'])->toBe($lordo);

    // E le due colonne devono esserci comunque, ciascuna col suo importo.
    expect($riga['per_capitolo'][RipartoCapitoliService::COLONNA_PREGRESSO]['importo'] ?? 0)->toBe(60_000)
        ->and($riga['per_capitolo'][RipartoCapitoliService::COLONNA_GIA_VERSATO]['importo'] ?? 0)->toBe(-60_000)
        ->and(array_sum(array_column($riga['per_capitolo'], 'importo')))->toBe($riga['totale']);

    // I totali di colonna vedono l'importo pieno, non zero.
    expect($matrice['tot_per_capitolo'][RipartoCapitoliService::COLONNA_PREGRESSO])->toBe(60_000)
        ->and($matrice['tot_per_capitolo'][RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-60_000)
        // E il capitolo resta al deliberato.
        ->and($matrice['tot_per_capitolo'][$sc->facciata->id])->toBe(500_000);
});

/**
 * La controprova del caso qui sopra: importi **diversi** fra le unità, che è la condizione normale
 * di un condominio vero. Serve a impedire che un domani qualcuno «semplifichi» lo scenario
 * rendendolo di nuovo simmetrico, che è esattamente ciò che aveva nascosto il difetto.
 */
it('regge con importi diversi da unità a unità, come in un condominio vero', function () {
    $sc = dcScenario();

    $giaVersato = [50_000, 60_000, 40_000, 50_000];
    $pregressi  = [45_000, 60_000, 30_000, 45_000];
    foreach ($sc->unita as $i => $u) {
        dcGiaVersato($sc, $u, $sc->facciata, $giaVersato[$i]);
        dcPregresso($sc, $u, $pregressi[$i]);
    }

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));
    $tot     = $matrice['tot_per_capitolo'];

    expect($tot[RipartoCapitoliService::COLONNA_PREGRESSO])->toBe(array_sum($pregressi))
        ->and($tot[RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-array_sum($giaVersato))
        ->and($tot[$sc->facciata->id])->toBe(500_000)
        ->and($tot)->not->toHaveKey(RipartoCapitoliService::COLONNA_FUORI_RIPARTO);

    foreach ($matrice['righe'] as $riga) {
        foreach ($riga['soggetti'] as $sogg) {
            expect(array_sum(array_column($sogg['per_capitolo'], 'importo')))->toBe($sogg['totale']);
        }
    }
});

/**
 * ★ REPERTO DELLA FASE 1-BIS — «Già versato» dichiarato dove nessuno ha versato.
 *
 * Il netting si ricava per differenza, e la differenza **non sa da cosa nasce**: prima di questo
 * limite, qualunque scarto verso il basso fra il lordo ricalcolato dal vivo e le quote emesse
 * veniva battezzato «già versato», purché cadesse nell'intervallo `[0, lordoRiga]`.
 *
 * Il caso qui sotto è il **subentro fatto a mano**, che è il rimedio che gli amministratori usano
 * oggi perché il motore non legge le date di competenza: si generano le rate, si stacca chi è
 * uscito, si attacca il subentrante, si ristampa. Il subentrante ha pesi vivi sulla pivot e zero
 * quote emesse, quindi il suo lordo è **interamente fantasma**.
 *
 * Misurato prima del limite: «Manutenzione € 600,00 | Già versato −€ 600,00 | Totale € 0,00», e la
 * colonna del documento dichiarava −€ 600,00 di versamenti in un condominio dove nessuno aveva
 * versato niente. **Senza nemmeno un avviso nel registro**, perché il resto era esattamente zero.
 *
 * La correzione ha due parti: il netting non può superare quanto risulta **registrato** per
 * quell'immobile, e lo scarto negativo che avanza viene tolto dalle colonne che l'hanno prodotto —
 * è una correzione di un'allocazione eccessiva, non un addebito.
 */
it('non dichiara «già versato» quando non c\'è nessun contributo registrato', function () {
    $sc    = dcScenario();
    $piano = dcGeneraPiano($sc);

    // Il subentro fatto a mano, dopo la generazione.
    $uscente = $sc->unita[0];
    DB::table('anagrafica_immobile')->where('immobile_id', $uscente->immobile->id)->delete();

    $subentrante = Anagrafica::forceCreate([
        'nome'           => 'Subentrante',
        'email'          => 'dcsub@example.com',
        'indirizzo'      => 'Via Ostiense 40',
        'codice_fiscale' => 'DCSUB00000000',
    ]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $subentrante->id,
        'immobile_id'   => $uscente->immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100.0,
        'attivo'        => true,
        'data_inizio'   => now()->format('Y-m-d'),
    ]);

    $matrice = (new RipartoCapitoliService())->buildMatrice($piano->fresh());

    // Nessuna colonna «Già versato»: in questo condominio non c'è un solo contributo registrato.
    expect($matrice['tot_per_capitolo'])->not->toHaveKey(RipartoCapitoliService::COLONNA_GIA_VERSATO);

    // Il subentrante non porta niente, perché niente gli è stato addebitato.
    $riga = $matrice['righe'][$uscente->immobile->id]['soggetti'][$subentrante->id] ?? null;
    expect($riga)->not->toBeNull()
        ->and($riga['totale'])->toBe(0)
        ->and(array_sum(array_column($riga['per_capitolo'], 'importo')))->toBe(0);

    // E il documento continua a valere le rate emesse: € 6.600,00, perché in questo scenario non
    // ci sono né arretrati né versamenti — le quote dell'uscente restano in `rate_quote` e
    // compaiono «fuori riparto», che è il presidio della beta.52.
    expect($matrice['gran_totale'])->toBe(660_000);
});

/**
 * La controprova del limite: **con** una copertura registrata la colonna deve comparire, e valere
 * quella. Un limite che non lascia passare nemmeno il caso buono non è un limite, è un blocco.
 */
it('il limite lascia passare il già versato vero, fino a quanto è registrato', function () {
    $sc = dcScenario();
    // € 500,00 registrati su una sola unità: solo quelli possono comparire.
    dcGiaVersato($sc, $sc->unita[0], $sc->facciata, 50_000);

    $tot = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc))['tot_per_capitolo'];

    expect($tot[RipartoCapitoliService::COLONNA_GIA_VERSATO])->toBe(-50_000)
        ->and($tot[$sc->facciata->id])->toBe(500_000);
});

/**
 * Il tetto è **per immobile**, non per persona: due contitolari attingono alla stessa copertura,
 * perché segue l'unità (art. 63 disp. att. c.c.). Senza il decremento la stessa copertura verrebbe
 * dichiarata due volte, una per testa.
 */
it('due contitolari non dichiarano due volte la stessa copertura', function () {
    $sc = dcScenario();

    // Un secondo proprietario al 50% sulla prima unità, e il primo sceso al 50%.
    $u = $sc->unita[0];
    DB::table('anagrafica_immobile')->where('immobile_id', $u->immobile->id)->update(['quota' => 50.0]);

    $comproprietario = Anagrafica::forceCreate([
        'nome'           => 'Comproprietario',
        'email'          => 'dccomp@example.com',
        'indirizzo'      => 'Via Ostiense 40',
        'codice_fiscale' => 'DCCOMP0000000',
    ]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $comproprietario->id,
        'immobile_id'   => $u->immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 50.0,
        'attivo'        => true,
        'data_inizio'   => now()->format('Y-m-d'),
    ]);

    dcGiaVersato($sc, $u, $sc->facciata, 60_000);

    $matrice = (new RipartoCapitoliService())->buildMatrice(dcGeneraPiano($sc));

    // € 600,00 registrati, € 600,00 dichiarati in tutto — non € 1.200,00.
    expect($matrice['tot_per_capitolo'][RipartoCapitoliService::COLONNA_GIA_VERSATO])
        ->toBeGreaterThanOrEqual(-60_000);

    foreach ($matrice['righe'] as $riga) {
        foreach ($riga['soggetti'] as $sogg) {
            expect(array_sum(array_column($sogg['per_capitolo'], 'importo')))->toBe($sogg['totale']);
        }
    }
});
