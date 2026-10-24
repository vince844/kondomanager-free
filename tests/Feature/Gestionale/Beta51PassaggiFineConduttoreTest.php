<?php

/**
 * 1.11.0-beta.51 — la fine di un conduttore (Coda 254, DL7; decisione 85, punto 4: la cifra nel registro dell'inizio).
 *
 * Il difetto, misurato su TEST (591b2c11): Ines esce il 24/1 con «Scrivi» (la coppia a Ugo), Luca entra l'8/2, Ugo vende a Elsa il
 * 12/3, Luca finisce il 18/4. I 258 giorni dopo la fine di Luca (18/4–31/12) li paga Elsa, ma la coppia è a nome di Ugo: € 268,20
 * di Elsa restano nella coppia di Ugo, in silenzio. Il pannello della fine di Luca non dice niente (fermate vuote, cancello non
 * richiesto), il registro non conserva niente, il prospetto dice 273 giorni senza conduttore a Ugo in un rigo solo (€ 283,79).
 *
 * LA REGOLA: quando finisce la locazione di un conduttore, i giorni che lui copriva e che
 * nessun altro conduttore riprende tornano a chi paga al posto dell'inquilino da quel giorno; per le persone che non hanno già la
 * coppia, la variazione della loro parte nella coppia ridivisa (dopo la fine meno prima) è la cifra; se è diversa da zero la fine
 * si ferma come le altre fermate: persona, cifra, strada a mano, spunta. NON è «la parte del conduttore prima della fine meno quella
 * dopo» (T): T coincide con la cifra solo se tutti i giorni liberati vanno a persone senza la coppia. Dove vanno a un altro
 * conduttore (coinquilino, cambio d'inquilino) o a chi ha già la coppia (la fine registrata prima della vendita) T è diverso da zero
 * e la cifra no: i test «sentinella —» di questo file la tengono ferma.
 *
 * I CONTI A MANO (centesimi; il 2026 ha 365 giorni; regola della coppia: parti intere, il centesimo che avanza al resto più grande):
 *   parte annua dell'inquilino 126475 × 30 % = 37942,50; coppia della fine di Ines (342 giorni, 24/1–31/12)
 *   37942,50 × 342/365 = 35551,60 → 35552. Giorni: Luca dall'8/2 = 327; scoperti 24/1–7/2 = 15 (Ugo); Luca fino al 17/4 = 69;
 *   liberi dal 18/4 = 258. Prima della fine di Luca: pesi [Luca 327, Ugo 15] → 35552 × 327/342 = 33992,70 → Luca 33993, Ugo 1559.
 *   Dopo: pesi [Luca 69, Ugo 15, Elsa 258] → 7172,77 / 1559,30 / 26819,93; parti intere 7172 + 1559 + 26819 = 35550, due centesimi ai
 *   resti più alti (Elsa ,93; Luca ,77) → Luca 7173, Ugo 1559, Elsa 26820. La cifra è la parte di Elsa (che non ha la coppia):
 *   26820. Controllo, non formula: 33993 − 7173 = 26820.
 *   (I commenti del test DL7 del laboratorio, «26819,68» e «268,19», sono conti sbagliati: 35552 × 258/342 = 26819,93.)
 *
 * Ogni cifra di questo file è rifatta a mano con la regola dei resti maggiori (a pari resto chi esce per primo).
 *
 * Che cosa NON copre: la parte che risale senza una fermata nuova è in `Beta51PassaggiParteCheRisaleTest`; la cifra dell'inizio nel
 * registro in `Beta51PassaggiRegistroInizioTest`; il residuo con più fermate sulla stessa persona e la spunta per € 0,00 quando la fine e
 * l'inizio sono lo stesso giorno (decisione 86) in `Beta51PassaggiDueFermateTest`; il segno nella colonna «Importo» del PDF in
 * `Beta51PassaggiEntrateTest`; la stampa del PDF (si guarda la vista resa), la pagina Vue, MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

// --- O-DL7: il caso del laboratorio -----------------------------------------------------------------------------------------------

it('O-DL7 — la fine di Luca il 18/4 dopo la vendita del 12/3: il pannello ferma € 268,20 verso Elsa a carico di Ugo, con il Wallet e la spunta', function () {
    // Fonte: conto a mano in testa (26820). Oggi le fermate sono vuote e il cancello non è richiesto.
    $s = b51aDl7ConVendita($this);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');

    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]]);
    $f = $a['rate']['fermate_dopo_scrivi'][0];
    expect((int) $f['coppia'])->toBe(35552)
        ->and($f['dal'])->toBe('2026-04-18')
        ->and((int) $f['fine_id'])->toBe((int) $s['fine_ines']->id)
        ->and($f['fine_il'])->toBe('2026-01-24')
        ->and($f['inquilino'])->toBe('Ines')
        ->and($f['gestione'])->toBe('Ordinaria 2026')
        ->and($f['unita'])->toBe('Interno 1')
        ->and($f['wallet'])->toBeTrue()
        ->and($f['strada'] ?? null)->toBe('legata')
        // La chiave `conduttore` in `fermata_ridotta` vuol dire chi ENTRA: nella fermata della fine di un conduttore chi ESCE
        // si chiama `conduttore_uscente`.
        ->and($f['conduttore_uscente'] ?? null)->toBe('Luca')
        ->and((int) ($f['conduttore_uscente_id'] ?? 0))->toBe((int) $s['persone']['Luca']->id)
        ->and(collect($f['parti'])->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Elsa', 26820]]);
    // Il pannello dice la fermata come le altre: la cifra, la persona, la strada del Wallet con il verso delle due righe.
    fds50DiceLaFermata($a, '€ 268,20', 'Ugo', 'Elsa');
    // E dice che è la fine della locazione di Luca a liberare i giorni, non «il passaggio».
    $frasi = b51aFrasiConCifra($a, '€ 268,20');
    expect(collect($frasi)->contains(fn (string $t) => str_contains($t, 'Luca')))->toBeTrue('Nessuna frase con € 268,20 nomina Luca: ' . implode(' | ', $frasi));
    // La spunta (decisioni 77.3 e 79): un motivo con la persona, la cifra e chi riceve.
    $motivi = collect($a['cancello']['motivi']);
    expect($a['cancello']['richiesto'])->toBeTrue('La fine di Luca non chiede la spunta: ' . $motivi->implode(' | '))
        ->and($motivi->contains(fn (string $m) => str_contains($m, '€ 268,20') && str_contains($m, 'Ugo') && str_contains($m, 'Elsa')))
        ->toBeTrue('Nessun motivo del cancello dice € 268,20, Ugo ed Elsa: ' . $motivi->implode(' | '));
});

it('O-DL7 — senza «Ho letto» e la nota la fine di Luca non si registra; con la spunta si registra e il registro conserva la fermata con le sue chiavi', function () {
    $s = b51aDl7ConVendita($this);
    $modulo = b51aModuloFine($s, 'Luca', '2026-04-18');
    $prima = Subentro::where('immobile_id', $s['unita']->id)->count();

    // Il cancello sul server: senza la presa d'atto la registrazione si rifiuta, e non si scrive niente.
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)->assertSessionHasErrors('nota_cancello');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe($prima);

    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $registro = b51aRegistro($fine);
    expect($registro['fermata_dopo_scrivi'] ?? [])->toHaveCount(1);
    $f = $registro['fermata_dopo_scrivi'][0];
    // `perIlRegistro` è una lista bianca: una chiave non elencata sparisce in silenzio dal registro.
    foreach (['fine_id', 'fine_il', 'inquilino', 'persona_id', 'persona', 'immobile_id', 'unita', 'gestione_id', 'gestione', 'coppia', 'cifra', 'dal', 'verso', 'parti', 'wallet',
        'conduttore_uscente', 'conduttore_uscente_id'] as $chiave) {
        expect(array_key_exists($chiave, $f))->toBeTrue("Il registro della fine di Luca non conserva la chiave «{$chiave}»: " . json_encode(array_keys($f)));
    }
    expect((int) $f['cifra'])->toBe(26820)
        ->and($f['persona'])->toBe('Ugo')
        ->and($f['verso'])->toBe(['Elsa'])
        ->and($f['dal'])->toBe('2026-04-18')
        ->and((int) $f['fine_id'])->toBe((int) $s['fine_ines']->id)
        ->and($f['conduttore_uscente'])->toBe('Luca')
        // Il denaro non si muove da solo: la coppia della fine di Ines resta com'era (Ugo +35552, Ines −35552), e la fine di Luca non scrive righe.
        ->and(fds50Coppia($s, $s['fine_ines']))->toBe(['Ines' => -35552, 'Ugo' => 35552])
        ->and(DB::table('saldi')->where('subentro_id', $fine->id)->count())->toBe(0);
});

it('O-DL7 — il prospetto dopo la fine di Luca divide i giorni senza conduttore: Ugo 15 giorni (€ 15,59) e 258 giorni (€ 268,20) «oppure Elsa», non un rigo da € 283,79', function () {
    // Fonte: conto a mano (1559 per i 15 giorni prima di Luca; 26820 per i 258 dopo). Oggi un rigo solo: 28379 per 273 giorni.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $righe = collect(b51aProspetto($s)['senza_conduttore']['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)->sortBy('giorni')->values();
    expect($righe->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], (int) $v['giorni']])->all())->toBe([['Ugo', 1559, 15], ['Ugo', 26820, 258]]);
    // Il rigo dei 258 giorni nomina chi li ha ricevuti, e dice di quale passaggio: la fine della locazione di Luca, con la data del prospetto.
    $oppure = (string) ($righe[1]['oppure'] ?? '');
    expect(str_contains($oppure, 'Elsa'))->toBeTrue("Il rigo dei 258 giorni non nomina Elsa: «{$oppure}»")
        ->and(str_contains($oppure, 'fine della locazione di Luca'))->toBeTrue("Il rigo non dice la fine della locazione di Luca: «{$oppure}»")
        ->and(str_contains($oppure, '18/04/2026'))->toBeTrue("Il rigo non ha la data del prospetto (d/m/Y): «{$oppure}»");
    expect($righe[0]['oppure'] ?? null)->toBeNull();
});

it('O-DL7-VISTE — € 268,20 in ogni vista: pannello, cancello, registro, storico, prospetto, stampa, avvisi dell\'annullamento; e la cifra dell\'inizio meno la parte rimasta è la cifra, entro un centesimo', function () {
    $s = b51aDl7ConVendita($this);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $viste = [
        'pannello' => implode(' | ', $a['rate']['frasi']),
        'cancello' => implode(' | ', $a['cancello']['motivi']),
        'storico' => b51aTestoStorico($s, $fine),
        'stampa' => fclStampa($s),
    ];
    foreach ($viste as $nome => $testo) {
        expect(str_contains($testo, '268,20'))->toBeTrue("La vista «{$nome}» non dice € 268,20.");
    }
    // Lo storico della fine di Luca dice la fermata (sezione «fermate»), non solo gli avvisi.
    $voce = fds50Storico($s, $fine);
    expect(collect($voce['fermate'] ?? [])->contains(fn ($t) => str_contains((string) $t, '268,20')))->toBeTrue('Le fermate dello storico non dicono € 268,20: ' . json_encode($voce['fermate'] ?? []));
    // I saldi della strada a mano, legati alla fine di Luca dal modale del Wallet (dalla 1.11.0-beta.53): l'annullamento li toglie e lo dice.
    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }
    // Gli avvisi e gli effetti dell'annullamento (fine di Luca, l'ultimo passaggio): i saldi legati alla fine si tolgono con lei.
    $voce = fds50Storico($s, $fine);
    $avvisi = implode(' | ', [...($voce['annullabile']['avvisi'] ?? []), ...($voce['annullabile']['effetti'] ?? [])]);
    expect(str_contains($avvisi, '268,20'))->toBeTrue("Gli avvisi e gli effetti dell'annullamento della fine di Luca non dicono € 268,20: «{$avvisi}»");

    // Concordanza (decisione 85.4): la cifra dei giorni di Luca all'inizio (33993) meno la parte che gli resta dopo la fine (7173, il
    // prospetto) è la parte che torna: 26820, con la tolleranza di un centesimo scritta come asserzione (il tie-break dell'inizio
    // divide senza primo, la fine con chi ha la coppia per primo).
    $parteRimasta = b51aConduttori($s)['Luca'][0];
    expect($parteRimasta)->toBe(7173);
    $giorni = b51aRegistro($s['inizio_luca'])['giorni_del_conduttore'][0]['cifra'] ?? null;
    expect($giorni)->not->toBeNull('Il registro dell\'inizio di Luca non ha la cifra dei suoi giorni.')
        ->and(abs(($giorni - $parteRimasta) - 26820))->toBeLessThanOrEqual(1);
});

// --- Le strade della cifra: ordine, persone, unità --------------------------------------------------------------------------------

it('sentinella — O-DL7-INV: ordine inverso, la fine di Luca registrata PRIMA della vendita non dice niente, e la vendita dice € 268,20 una volta sola', function () {
    // Fonte: stessi pesi di O-DL7. Alla fine di Luca, registrata prima, Ugo è ancora il proprietario: i giorni liberati vanno a chi ha
    // la coppia, cifra 0 (la formula T dichiarerebbe 26820 due volte, qui e alla vendita).
    $s = b51aDl7($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aRegistro($fine)['fermata_dopo_scrivi'] ?? [])->toBe([]);

    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-03-12'));
    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]]);
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-03-12'))['subentro'];

    // Le due strade (vendita prima della fine, vendita dopo) dichiarano la stessa cifra, una volta sola: la somma delle fermate di
    // tutti i passaggi dello scenario è 26820.
    $somma = Subentro::where('immobile_id', $s['unita']->id)->get()->sum(fn (Subentro $p) => collect(b51aRegistro($p)['fermata_dopo_scrivi'] ?? [])->sum('cifra'));
    expect($somma)->toBe(26820)
        ->and(collect(b51aRegistro($vendita)['fermata_dopo_scrivi'] ?? [])->sum('cifra'))->toBe(26820);
});

it('O-DL7-INV — differenziale: la vendita prima della fine di Luca o dopo dà la stessa fermata, € 268,20, e mai due', function () {
    $normale = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $normale, 'Luca', '2026-04-18');
    $sommaNormale = Subentro::where('immobile_id', $normale['unita']->id)->get()->sum(fn (Subentro $p) => collect(b51aRegistro($p)['fermata_dopo_scrivi'] ?? [])->sum('cifra'));

    $inverso = b51aDl7($this);
    b51aRegistraFine($this, $inverso, 'Luca', '2026-04-18');
    b51aVendi($this, $inverso, 'Ugo', 'Elsa', '2026-03-12');
    $sommaInverso = Subentro::where('immobile_id', $inverso['unita']->id)->get()->sum(fn (Subentro $p) => collect(b51aRegistro($p)['fermata_dopo_scrivi'] ?? [])->sum('cifra'));

    expect([$sommaNormale, $sommaInverso])->toBe([26820, 26820]);
});

it('O-DL7-MARCO — dopo la fine di Luca, l\'inizio di Marco il 1/6 riduce la fermata di Luca a € 45,74 e dice «la fine della locazione di Luca», non «il passaggio»', function () {
    // Fonte: pesi [Luca 69, Ugo 15, Elsa 44, Marco 214] → 7172,77 / 1559,30 / 4573,94 / 22245,99; interi 7172 + 1559 + 4573 + 22245 = 35549, tre
    // centesimi ai resti più alti (Marco ,99; Elsa ,94; Luca ,77) → Marco 22246, Elsa 4574, Luca 7173, Ugo 1559. Residuo della fermata di
    // Luca: 26820 − (26820 − 4574) = 4574.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $ridotte = $a['rate']['fermate_ridotte'];
    expect(collect($ridotte)->map(fn ($r) => [(int) $r['prima'], (int) $r['residuo'], $r['conduttore']])->all())->toBe([[26820, 4574, 'Marco']]);
    $testo = b51aTestoAnteprima(['rate' => ['frasi' => $a['rate']['frasi']], 'cancello' => $a['cancello']]);
    expect(str_contains($testo, 'fine della locazione di Luca'))->toBeTrue('Il pannello dell\'inizio di Marco non dice la fine della locazione di Luca: ' . $testo)
        ->and(str_contains($testo, 'il passaggio del 18 aprile'))->toBeFalse('Il pannello dice «il passaggio del 18 aprile»: ' . $testo)
        ->and(str_contains($testo, '45,74'))->toBeTrue('Il pannello non dice il residuo € 45,74: ' . $testo)
        ->and($a['cancello']['richiesto'])->toBeTrue()
        ->and(collect($a['cancello']['motivi'])->contains(fn ($m) => str_contains($m, '€ 45,74') && str_contains($m, '€ 268,20')))->toBeTrue('Il cancello non dice 45,74 e 268,20: ' . implode(' | ', $a['cancello']['motivi']));
});

it('O-DL7-MARCO-FINE — dopo la fine di Luca e l\'inizio di Marco il 1/6, la fine di Marco l\'1/10 non dice una fermata nuova ma la parte di Elsa che risale da € 45,74 a € 141,38', function () {
    // Fonte (resti maggiori): Marco dal 1/6 al 30/9 = 122 giorni. Prima {Ugo 1559, Luca 7173, Elsa 4574, Marco 22246}, dopo
    // {Ugo 1559, Luca 7173, Elsa 14138, Marco 12682}: la parte che torna a Elsa è 14138 − 4574 = 9564 (T), ed è tutta salita della fermata di Luca
    // (residuo da 4574 a 14138 = 26820 − 12682): fermata nuova 0. Il pannello dice «risale da € 45,74 a € 141,38».
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');

    $a = b51aAnteprimaFine($this, $s, 'Marco', '2026-10-01');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
    $risale = $a['rate']['fermate_risalgono'] ?? null;
    expect($risale)->not->toBeNull('La fine di Marco non ha rate.fermate_risalgono (la parte che risale).');
    expect(collect($risale)->map(fn ($r) => [(int) $r['prima'], (int) $r['dopo'], array_values((array) $r['verso']), $r['passaggio']])->all())
        ->toBe([[4574, 14138, ['Elsa'], 'la fine della locazione di Luca del 18 aprile 2026']])
        ->and($a['cancello']['richiesto'])->toBeTrue()
        ->and(collect($a['cancello']['motivi'])->contains(fn ($m) => str_contains($m, '€ 45,74') && str_contains($m, '€ 141,38')))->toBeTrue('Nessun motivo del cancello dice 45,74 e 141,38: ' . implode(' | ', $a['cancello']['motivi']));
});

it('O-DL7-V2 — dopo la fine di Luca, la seconda vendita (Elsa a Carlo il 1/9) ferma € 126,82 verso Carlo a carico di Ugo, e dice la fermata di Luca da cui viene', function () {
    // Fonte: pesi [Luca 69, Ugo 15, Elsa 136, Carlo 122] → 7172,77 / 1559,30 / 14137,64 / 12682,29; interi 7172 + 1559 + 14137 + 12682 = 35550,
    // due centesimi a Luca (,77) ed Elsa (,64) → Carlo 12682, Elsa 14138. Decisione 81.2: la persona è chi ha la coppia (Ugo), la parte
    // passata a chi entra; la fermata di prima è quella della fine di Luca (26820).
    $s = b51aDl7ConVendita($this);
    $fineLuca = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-09-01'));
    expect(b51aFermate($a))->toBe([['Ugo', 12682, ['Carlo']]]);
    $f = $a['rate']['fermate_dopo_scrivi'][0];
    expect($f['uscente'] ?? null)->toBe('Elsa')
        // La fermata di prima, quella della fine di Luca: 26820. Oggi `dopo_la_fermata` è vuoto perché la fine di Luca non ha una fermata.
        ->and((int) ($f['dopo_la_fermata']['cifra'] ?? 0))->toBe(26820);

    $seconda = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-09-01'))['subentro'];
    // Lo storico della fermata di Luca dice quanto ne è passato a Carlo con la seconda vendita (decisione 82.2).
    $testo = b51aTestoStorico($s, $fineLuca);
    expect(str_contains($testo, '126,82') && str_contains($testo, 'Carlo'))->toBeTrue('Lo storico della fine di Luca non dice i € 126,82 passati a Carlo: ' . $testo)
        ->and((int) b51aRegistro($seconda)['fermata_dopo_scrivi'][0]['cifra'])->toBe(12682);
});

it('O-DL7-COMPR — Ugo e Anna comproprietari al 50 %, vende Ugo a Elsa: la fine di Luca ferma la parte di Elsa (€ 134,10), non quella di Anna che ha già la coppia', function () {
    // Fonte: dopo il 12/3 la metà di Ugo è di Elsa; dei 258 giorni liberati 129 sono di Anna e 129 di Elsa. Elsa: 35552 × 129/342 = 13409,96
    // → 13410. La salita di Anna è di chi ha già la riga della coppia: non è una fermata. Tolleranza di un centesimo per persona
    // (Ugo e Anna si scambiano un centesimo nel resto).
    $s = b51aDl7ConVendita($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'quotaVendita' => 50]);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');

    expect(collect($a['rate']['fermate_dopo_scrivi'])->count())->toBe(1);
    $f = $a['rate']['fermate_dopo_scrivi'][0];
    expect($f['verso'])->toBe(['Elsa'])
        ->and(abs((int) $f['cifra'] - 13410))->toBeLessThanOrEqual(1)
        ->and(abs((int) $f['coppia'] - 35552))->toBeLessThanOrEqual(0)
        ->and(str_contains((string) $f['persona'], 'Ugo') && str_contains((string) $f['persona'], 'Anna'))->toBeTrue('La fermata non nomina Ugo e Anna: ' . $f['persona']);
});

it('O-DL7-BOX — con il box spuntato una fermata per coppia: € 54,15 sull\'appartamento e € 6,02 sul box, verso Elsa a carico di Ugo', function () {
    // Fonte: appartamento 32400 × 184/365 = 16333,15 → 16333; box 3600 × 184/365 = 1814,79 → 1815 (coppie della fine di Ines il 1/7).
    // Giorni liberati dal 1/11 = 61: 16333 × 61/184 = 5414,74 → 5415; 1815 × 61/184 = 601,71 → 602. La quota del conduttore sul box è
    // quella della sua riga lì (applicaAllaPertinenza), non quella del modulo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $box = $s['box'];
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$box]) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-10-31');
    fds50Registra($this, $s, ['pertinenze' => [(int) $box->id]] + fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    fds50Registra($this, $s, ['pertinenze' => [(int) $box->id]] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-11-01', [$box], 'Luca'));
    $fermate = collect($a['rate']['fermate_dopo_scrivi'])->map(fn ($f) => [$f['unita'], (int) $f['cifra'], $f['persona'], $f['verso']])->sortBy(fn ($x) => $x[0])->values()->all();
    expect($fermate)->toBe([['Box 12', 602, 'Ugo', ['Elsa']], ['Interno 1', 5415, 'Ugo', ['Elsa']]]);
    $motivi = collect($a['cancello']['motivi']);
    expect($motivi->filter(fn ($m) => str_contains($m, '€ 54,15') || str_contains($m, '€ 6,02')))->toHaveCount(2);
});

it('O-DL7-BOX-ALTRO — il box è di Rita per tutto l\'anno e la vendita è solo dell\'appartamento: una fermata sola, sull\'appartamento (€ 54,15); nessuna sul box', function () {
    // Fonte: come O-DL7-BOX, con il box di Rita (proprietaria) per tutto l'anno: i giorni liberati sul box tornano a lei, che ha la
    // coppia del box; sull'appartamento 16333 × 61/184 = 5414,74 → 5415.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $box = $s['box'];
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$box]) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-10-31');
    fds50Registra($this, $s, ['pertinenze' => [(int) $box->id]] + fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01'));

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-11-01', [$box], 'Luca'));
    expect(collect($a['rate']['fermate_dopo_scrivi'])->map(fn ($f) => [$f['unita'], (int) $f['cifra'], $f['persona'], $f['verso']])->all())->toBe([['Interno 1', 5415, 'Ugo', ['Elsa']]]);
});

it('O-DL7-ENTRATA — con un\'entrata che supera la spesa la fermata è un credito di € 70,51 detto con le parole, mai «€ -»', function () {
    // Fonte: coppia della fine di Ines il 1/7: spese 36000 × 184/365 = 18148, antenna −30000 (1/10–31/12): −11852 a Ugo. Luca dal 1/10:
    // spese 18148 × 92/184 = 9074, entrata −30000 → −20926. Vendita a Elsa l'1/11, fine di Luca l'1/12 (Luca fino al 30/11, 61 giorni): spese
    // 18148 × 61/184 = 6016,46, entrata −30000 × 61/92 = −19891,30 → −13875 (arrotondato). Prima −20926, dopo −13875: la parte di Elsa è −7051
    // (a mano: 31 giorni, 3057,54 − 10108,70 = −7051,16). Le parti: prima {Ugo 9074, Luca −20926}, dopo
    // {Ugo 9074, Luca −13875, Elsa −7051}.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fds50Coppia($s, $s['fine_ines']))->toBe(['Ines' => 11852, 'Ugo' => -11852]);
    fclEmetti($this, $s, '2026-09-30');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-11-01');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-12-01');
    expect(b51aFermate($a))->toBe([['Ugo', -7051, ['Elsa']]]);
    $frasi = b51aFrasiConCifra($a, '70,51');
    expect($frasi)->not->toBeEmpty('Il pannello non dice la cifra € 70,51.');
    expect(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».')
        ->and(collect($frasi)->contains(fn ($t) => str_contains($t, '€ 70,51 di credito')))->toBeTrue('Nessuna frase dice «€ 70,51 di credito»: ' . implode(' | ', $frasi));
    // Il Wallet nel verso giusto: a debito di Ugo e a credito di Elsa (un credito che resta a chi ha la coppia).
    expect(collect($frasi)->contains(fn ($t) => str_contains($t, 'Wallet') && preg_match('/debito[^.|]{0,20}Ugo/u', $t) === 1 && preg_match('/credito[^.|]{0,20}Elsa/u', $t) === 1))
        ->toBeTrue('La frase non dice il Wallet nel verso giusto: ' . implode(' | ', $frasi));
});

it('O-DL7-MISTA — coppia con una spesa e un\'entrata, vendita l\'1/9 e fine di Luca l\'1/11: la fermata è la variazione della parte di Elsa pezzo per pezzo, con il segno (−€ 138,75)', function () {
    // Fonte (resti maggiori): coppia −11852 (spese 18148 su 184 giorni, antenna −30000 su 92), Luca dall'1/8 al 31/10,
    // Elsa dall'1/9. Prima {Ugo 3058, Luca −14910}, dopo {Ugo 3058, Luca −1035, Elsa −13875}. A mano: 61 giorni liberati (1/11–31/12): spese
    // 18148 × 61/184 = 6016,46; antenna −30000 × 61/92 = −19891,30; somma −13874,84 → −13875.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-07-31');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-09-01');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-11-01');
    expect(b51aFermate($a))->toBe([['Ugo', -13875, ['Elsa']]])
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('sentinella — O-DL7-MISTA (guardia): con la vendita registrata PRIMA della fine di Ines, Ugo ed Elsa hanno già ciascuno la loro riga della coppia, e la fine di Luca non dice niente (la cifra è 0 per costruzione)', function () {
    // I giorni liberati vanno a persone che hanno già la coppia (chiHaLaCoppia): cifra 0. La formula T (parte del conduttore prima meno dopo)
    // darebbe una fermata falsa.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-09-01');
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(array_keys(fds50Coppia($s, $s['fine_ines'])))->toContain('Elsa');
    fclEmetti($this, $s, '2026-07-31');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-11-01');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
});

// --- Le fini che non devono fermarsi (sentinelle verdi: proteggono dalla formula T) ---------------------------------------------------

it('sentinella — O-DL7-NS: con «Non scriverlo» alla fine di Ines non c\'è nessuna coppia e la fine di Luca non si ferma, cancello non richiesto', function () {
    // Fonte: nessuna coppia scritta, niente da ridividere (fermate vuote, nessuna chiave fermata_risale).
    // Lo scenario di base ha la fine di Ines con «Scrivi»: qui serve «Non scriverlo», quindi si costruisce da capo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['importo' => 126475]);
    b51aPersone($s, ['Luca', 'Elsa']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-01-07');
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s, null, '2026-01-24') + ['conguaglio' => Subentro::NON_SCRIVERE_IL_CONGUAGLIO]);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-02-08'));
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and($a['rate'])->not->toHaveKey('fermate_risalgono')
        ->and($a['cancello']['richiesto'])->toBeFalse();
});

it('sentinella — O-DL7-COINQ: con Luca e Dora al 50 % la fine di Luca non si ferma (T sarebbe € 110,74, la cifra è 0: i giorni liberati restano a Dora, che rimborsa con il prospetto)', function () {
    // Fonte: conto a mano. Prima della fine di Luca, Luca e Dora si dividono i 327 giorni coperti (163,5 ciascuno); dopo, Luca 69 giorni al 50 %
    // e Dora 327 al 50 %: i pesi del codice (quota × giorni, normalizzati sui giorni coperti) danno Luca 56,98 e Dora 270,02. T = (163,5 −
    // 56,98) × 35552/342 = 11074. I giorni liberati vanno a Dora, che è conduttrice e rimborsa con il prospetto, non
    // a chi paga al posto dell'inquilino: Ugo ed Elsa non cambiano peso. Cifra 0.
    // Coda 245 (1.11.0-beta.55): resta così. Dora resta, ma Luca non ha quote del piano (è entrato su un piano che non si ricalcolava più): la fine
    // di Luca non ha una coppia, né quella della regola del piano; e l'anello non ha una fine con la regola del piano prima. Sull'anello di una
    // fine con la regola del piano la stessa fine sarebbe un'informazione (decisione 45, art. 9 L. 392/1978).
    $s = b51aDl7($this, ['senzaLuca' => true]);
    fds50Registra($this, $s, ['quota' => 50] + fds50ModuloInizio($s, 'Luca', '2026-02-08'));
    fds50Registra($this, $s, ['quota' => 50] + fds50ModuloInizio($s, 'Dora', '2026-02-08'));
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18', null, ['quota' => 50]);
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and($a['cancello']['richiesto'])->toBeFalse('Il cancello chiede la spunta per una fermata che non c\'è: ' . implode(' | ', $a['cancello']['motivi']));
});

it('sentinella — O-DL7-CAMBIO: il cambio d\'inquilino da Luca a Marco il 18/4 in un passo non ferma niente (T sarebbe € 268,20, la cifra è 0); e una quota di Marco diversa da quella di Luca non si può inserire', function () {
    // Fonte: Marco riprende i 258 giorni: pesi dopo [Luca 69, Ugo 15, Marco 258], chi paga al posto dell'inquilino non cambia peso. Decisione
    // 37: il passaggio porta tutta la quota di chi esce, quindi la quota diversa è rifiutata (422, misurato).
    $s = b51aDl7ConVendita($this);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18', 'Marco');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);

    $r = fclAnteprimaRisposta($this, $s, b51aModuloFine($s, 'Luca', '2026-04-18', 'Marco', ['quota' => 50]));
    expect($r->status())->toBe(422);
});

it('sentinella — O-DL7-CAMBIO-DOPO: un cambio d\'inquilino in un passo dopo la fine di Luca e Marco dal 1/6 (Marco passa a Dora il 1/9) non cambia la copertura dei giorni: nessuna fermata, nessuna salita', function () {
    // Fonte: la copertura dei giorni non cambia, Dora riprende i giorni di Marco.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');

    $a = b51aAnteprimaFine($this, $s, 'Marco', '2026-09-01', 'Dora');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and($a['rate'])->not->toHaveKey('fermate_risalgono');
});

it('O-DL7-RIENTRO — chi esce con «Scrivi», rientra e riesce dopo una vendita lascia € 268,20 verso Elsa: la fine registrata dalla stessa persona si ferma come quella di un altro', function () {
    // Fonte: stessi giorni e pesi di O-DL7 con Ines al posto di Luca: dall'8/2 Ines rimborsa a Ugo la sua parte (rientro, decisione 45); dal
    // 18/4 i 258 giorni sono di Elsa e restano nella coppia di Ugo: 26820 (nessuna esclusione di chi esce: se il pannello
    // escludesse «un'altra persona» tacerebbe). La frase non dice «una delle persone ha già un saldo»: nessuno ce l'ha.
    $s = b51aDl7($this, ['senzaLuca' => true]);
    b51aRegistraInizio($this, $s, 'Ines', '2026-02-08');
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');

    $a = b51aAnteprimaFine($this, $s, 'Ines', '2026-04-18');
    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]]);
    $f = $a['rate']['fermate_dopo_scrivi'][0];
    expect($f['wallet'])->toBeTrue()
        ->and($f['strada'] ?? null)->toBe('legata');
    expect(str_contains(b51aTestoAnteprima($a), 'una delle persone ha'))->toBeFalse('La frase dice un saldo che nessuno ha: ' . b51aTestoAnteprima($a));
});

// --- Le altre strade del passaggio prima della fine di Luca -------------------------------------------------------------------------------

it('O-DL7-USUF — la costituzione d\'usufrutto a Elsa il 12/3 al posto della vendita: la fine di Luca ferma lo stesso € 268,20 verso Elsa (l\'usufruttuaria sta al posto dell\'inquilino)', function () {
    // Fonte: l'usufruttuario è il primo anello della catena del ripiego: Elsa prende i 258 giorni dal 18/4 con lo stesso peso, 26820.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-03-12'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]])
        ->and($a['rate']['fermate_dopo_scrivi'][0]['wallet'])->toBeTrue();
});

it('O-DL7-ESTINZ — estinzione dell\'usufrutto di Elsa l\'1/6 registrata prima della fine di Luca: la fermata è € 45,74, non € 268,20 (dei 258 giorni liberati 214 tornano a Ugo, che ha la coppia)', function () {
    // Fonte: dei 258 giorni liberati, 44 (18/4–31/5) sono di Elsa e 214 (1/6–31/12) tornano a Ugo. Pesi dopo [Luca 69, Ugo 15 + 214 = 229, Elsa 44]
    // → 7172,77 / 23805,30 / 4573,94; interi 7172 + 23805 + 4573 = 35550, due centesimi ai resti più alti (Elsa ,94; Luca ,77) → Luca 7173,
    // Ugo 23805, Elsa 4574. T sarebbe 26820 (misurato): la cifra è 4574.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-03-12'));
    fds50Registra($this, $s, fds50ModuloEstinzione($s, 'Elsa', '2026-06-01'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aFermate($a))->toBe([['Ugo', 4574, ['Elsa']]]);
});

it('O-DL7-SUCC — successione di Ugo ad Anna e Bruno il 12/3 con l\'arretrato al defunto e «Scrivi»: la fine di Luca ferma € 268,20 a carico di Ugo, € 134,10 ciascuno verso Anna e Bruno', function () {
    // Fonte: Anna e Bruno prendono ciascuno metà dei 258 giorni: pesi dopo [Luca 69, Ugo 15, Anna 129, Bruno 129] → Anna e Bruno 13409,96, Luca
    // 7172,77, Ugo 1559,30; interi 13409 + 13409 + 7172 + 1559 = 35549, tre centesimi ai resti più alti (Anna, Bruno ,96; Luca ,77): 13410,
    // 13410, 7173, 1559. La coppia resta a nome del defunto Ugo.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-03-12'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    $f = $a['rate']['fermate_dopo_scrivi'][0] ?? [];
    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Anna', 'Bruno']]])
        ->and(collect($f['parti'])->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Anna', 13410], ['Bruno', 13410]]);
});

it('sentinella — O-DL7-SUCC-EREDI: con l\'arretrato agli eredi la coppia passa ad Anna e Bruno (che ce l\'hanno già), e la fine di Luca non si ferma', function () {
    // Fonte: chiHaLaCoppia (decisione 65): i giorni liberati vanno a chi ha già la coppia. Cifra 0.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, '2026-03-12'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([])
        ->and($a['cancello']['richiesto'])->toBeFalse();
});

it('sentinella — O-DL7-RISERVA: con la vendita della nuda con riserva d\'usufrutto Ugo resta usufruttuario e al posto dell\'inquilino: la fine di Luca non si ferma', function () {
    // Fonte: il primo anello della catena è Ugo, che ha la coppia: i 258 giorni tornano a lui. Cifra 0.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloRiserva($s, 'Ugo', 'Elsa', '2026-03-12'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
});

// --- Il Wallet e la sua regola (decisione 80) ----------------------------------------------------------------------------------------------

it('O-DL7-WALLET — senza saldi e con un saldo manuale di Ugo o di Elsa il Wallet è la strada, con i saldi legati alla fine di Luca: nessun «fra le parti»', function () {
    // Fonte: dalla 1.11.0-beta.53 il posto del Wallet non conta le righe legate a un passaggio, e la riga legata si scrive anche accanto a un
    // riporto (prima, con un saldo manuale di Ugo o di Elsa, la frase diceva «fra le parti»). `conUnSaldo` conta ancora i saldi non legati.
    $casi = ['nessuno' => b51aDl7ConVendita($this), 'Ugo' => b51aDl7ConVendita($this), 'Elsa' => b51aDl7ConVendita($this)];
    foreach (['Ugo', 'Elsa'] as $chi) {
        b51aSaldoManuale($casi[$chi], $chi, 5000);
        // Controllo del setup: `conUnSaldo` vede il saldo manuale di quella persona sulla gestione, l'unità e l'esercizio della coppia.
        $esercizio = DB::table('saldi')->where('subentro_id', $casi[$chi]['fine_ines']->id)->value('esercizio_id');
        expect(\App\Services\Subentro\FermataDopoScrivi::conUnSaldo([(int) $casi[$chi]['persone']['Ugo']->id, (int) $casi[$chi]['persone']['Elsa']->id], (int) $casi[$chi]['g']->id,
            (int) $casi[$chi]['unita']->id, $esercizio === null ? null : (int) $esercizio))->toBe([(int) $casi[$chi]['persone'][$chi]->id]);
    }

    foreach (['nessuno', 'Ugo', 'Elsa'] as $chi) {
        $a = b51aAnteprimaFine($this, $casi[$chi], 'Luca', '2026-04-18');
        $f = $a['rate']['fermate_dopo_scrivi'][0] ?? [];
        expect($f['wallet'] ?? null)->toBeTrue("Con un saldo di {$chi} il Wallet non è la strada.")
            ->and($f['strada'] ?? null)->toBe('legata');
        $frasi = b51aFrasiConCifra($a, '€ 268,20');
        expect(collect($frasi)->contains(fn ($t) => str_contains($t, 'registra dal Wallet i saldi legati a questo passaggio')))
            ->toBeTrue("La frase non indica il Wallet con i saldi legati ({$chi}): " . implode(' | ', $frasi));
        expect(str_contains(implode(' | ', $frasi), 'fra le parti'))->toBeFalse('La frase dice ancora «fra le parti»: ' . implode(' | ', $frasi));
    }
});

// --- L'annullamento, gli avvisi e lo storico -----------------------------------------------------------------------------------------------

it('O-DL7-ANNULLA — annullata la fine di Luca (l\'ultimo passaggio) la riga si riapre: la fermata non vale più, il prospetto torna a 15 giorni senza conduttore, e prima di annullare il programma nomina € 268,20 e i saldi del Wallet legati alla fine', function () {
    // Fonte: annullata la fine la riga di Luca si riapre e i 258 giorni tornano a Luca nei pesi: senza conduttore restano i 15 giorni di Ugo
    // prima dell'inizio di Luca (1559), e Luca ha di nuovo 33993 (327 giorni). Gli avvisi dicono la fermata che cade e il saldo manuale
    // dal Wallet che l'annullamento non toglie.
    $s = b51aDl7ConVendita($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    // I saldi della strada a mano, legati alla fine di Luca dal modale del Wallet (dalla 1.11.0-beta.53): l'annullamento li toglie e lo dice.
    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }

    $annullabile = fds50Storico($s, $fine)['annullabile'] ?? [];
    $testo = implode(' | ', [...($annullabile['avvisi'] ?? []), ...($annullabile['effetti'] ?? [])]);
    expect(str_contains($testo, '268,20'))->toBeTrue("Gli avvisi non nominano € 268,20: «{$testo}»")
        ->and(str_contains($testo, 'Wallet'))->toBeTrue("Gli avvisi non nominano il Wallet: «{$testo}»");

    fclAnnulla($this, $s, $fine)->assertStatus(302);
    expect(b51aSenzaConduttore($s))->toBe([['Ugo', 1559, 15, null]])
        ->and(b51aConduttori($s)['Luca'][0])->toBe(33993)
        ->and(fds50Storico($s, $fine)['fermate'] ?? [])->toBe([]);
});

it('O-DL7-AVVISI — «Annulla il conguaglio» di Ines, con la fine di Luca registrata: l\'avviso e lo storico nominano la fermata della fine di Luca (€ 268,20) come quella della vendita', function () {
    // Fonte: avvisiDelConguaglio legge anche le fermate delle fini di conduttore: cade con la coppia, il saldo manuale dal Wallet da rivedere.
    $s = b51aDl7ConVendita($this);
    $fineLuca = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    // I saldi della strada a mano, legati alla fine di Luca dal modale del Wallet (dalla 1.11.0-beta.53): l'annullamento li toglie e lo dice.
    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'passaggio_id' => $fineLuca->id,
        ])->assertSessionHasNoErrors();
    }

    $avvisi = b51aAvvisiDelConguaglio($s['fine_ines']);
    $luca = collect($avvisi)->filter(fn ($t) => str_contains($t, '268,20') && str_contains($t, 'fine della locazione di Luca'))->values();
    expect($luca)->toHaveCount(1, 'Gli avvisi di «Annulla il conguaglio» non nominano la fine di Luca e € 268,20: ' . implode(' | ', $avvisi));
    expect(str_contains((string) $luca[0], 'Wallet'))->toBeTrue('L\'avviso non nomina il Wallet: ' . $luca[0]);
    // Lo storico di Ines lo dice prima di confermare.
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $s['fine_ines']->id);
    expect(collect($storico['conguaglio']['avvisi'] ?? [])->contains(fn ($t) => str_contains((string) $t, '268,20') && str_contains((string) $t, 'fine della locazione di Luca')))
        ->toBeTrue('Lo storico di Ines non dice la fine di Luca fra gli avvisi del conguaglio: ' . json_encode($storico['conguaglio']['avvisi'] ?? []));
    expect($fineLuca->id)->toBeGreaterThan($s['fine_ines']->id);
});

it('sentinella — O-DL7-ANN-ORDINE: con la fine di Luca registrata dopo, la vendita non si annulla (regola dell\'ultimo registrato) e il rifiuto lo dice', function () {
    // Fonte: decisione 83.1 (annullamento dall'ultimo).
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $r = fclAnnulla($this, $s, $s['vendita']);
    expect($r->status())->toBe(422);
    $testo = json_encode($r->json(), JSON_UNESCAPED_UNICODE);
    expect(str_contains($testo, 'c\'è un altro passaggio') && str_contains($testo, 'fine locazione di Luca'))->toBeTrue('Il rifiuto non dice perché: ' . $testo);
});

// --- Le frasi: un sostantivo solo per pannello, storico e prospetto ------------------------------------------------------------------------

it('O-DL7-FRASI — pannello, storico e prospetto dicono «la fine della locazione di Luca del 18 aprile 2026» (il prospetto con la data d/m/Y), mai «il passaggio del 18 aprile»', function () {
    $s = b51aDl7ConVendita($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-09-01'));

    $pannello = b51aTestoAnteprima($a);
    $storico = b51aTestoStorico($s, $fine) . ' | ' . b51aTestoStorico($s, Subentro::where('tipo_passaggio', 'inizio_locazione')->orderByDesc('id')->firstOrFail());
    $oppure = collect(b51aProspetto($s)['senza_conduttore']['voci'])->pluck('oppure')->filter()->implode(' | ');
    foreach (['pannello' => $pannello, 'storico' => $storico] as $nome => $testo) {
        expect(str_contains($testo, 'fine della locazione di Luca del 18 aprile 2026'))->toBeTrue("Il {$nome} non dice la fine della locazione di Luca del 18 aprile 2026: {$testo}")
            ->and(str_contains($testo, 'il passaggio del 18 aprile'))->toBeFalse("Il {$nome} dice «il passaggio del 18 aprile»: {$testo}");
    }
    expect(str_contains($oppure, 'fine della locazione di Luca del 18/04/2026'))->toBeTrue("Il prospetto non dice la fine della locazione di Luca del 18/04/2026: {$oppure}")
        ->and(str_contains($oppure, 'il passaggio del'))->toBeFalse("Il prospetto dice «il passaggio del …»: {$oppure}");
});

// --- Dopo la fermata della fine di Luca: gli altri passaggi di Elsa ---------------------------------------------------------------------------

it('O-DL7-DOPO-USUF — dopo la fine di Luca, Elsa dà l\'usufrutto a Carlo l\'1/9: la parte passata è € 126,82 verso Carlo, come per la seconda vendita, e la frase nomina la fermata di Luca', function () {
    // Fonte: stessi pesi di O-DL7-V2 (Carlo usufruttuario dall'1/9 prende i 122 giorni): 12682.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $a = fds50Anteprima($this, $s, fds50ModuloCostituzione($s, 'Elsa', 'Carlo', '2026-09-01'));

    expect(b51aFermate($a))->toBe([['Ugo', 12682, ['Carlo']]])
        ->and((int) ($a['rate']['fermate_dopo_scrivi'][0]['dopo_la_fermata']['cifra'] ?? 0))->toBe(26820);
});

it('O-DL7-DOPO-SUCC — dopo la fine di Luca, Elsa muore l\'1/9 e i suoi eredi Anna e Bruno prendono i giorni (arretrato al defunto, «Scrivi»): € 63,41 ciascuno, € 126,82 in tutto a carico di Ugo', function () {
    // Fonte: pesi [Luca 69, Ugo 15, Elsa 136, Anna 61, Bruno 61] su 35552 → 7172,77 / 1559,30 / 14137,64 / 6341,4 / 6341,4; interi 7172 + 1559 +
    // 14137 + 6341 + 6341 = 35550, due centesimi a Luca (,77) ed Elsa (,64) → Anna 6341, Bruno 6341.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $modulo = ['riga_uscente_id' => fds50Riga($s, 'Elsa', 'proprietario')] + fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-09-01');
    $a = fds50Anteprima($this, $s, $modulo);

    expect(b51aFermate($a))->toBe([['Ugo', 12682, ['Anna', 'Bruno']]])
        ->and(collect($a['rate']['fermate_dopo_scrivi'][0]['parti'])->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Anna', 6341], ['Bruno', 6341]])
        ->and((int) ($a['rate']['fermate_dopo_scrivi'][0]['dopo_la_fermata']['cifra'] ?? 0))->toBe(26820);
});

it('O-DL7-ESTINZ-ACC — Elsa e Carlo usufruttuari al 50 % dal 12/3 e l\'usufrutto di Elsa si estingue l\'1/6 con l\'accrescimento a Carlo: la fine di Luca ferma due persone, Elsa € 22,87 e Carlo € 245,33 (€ 268,20)', function () {
    // Fonte (resti maggiori): dei 258 giorni liberati dal 18/4, 44 (18/4–31/5) sono di Elsa e Carlo a metà (22 giorni ciascuno) e 214
    // (1/6–31/12) tutti di Carlo. Prima {Ugo 1559, Luca 33993}, dopo {Ugo 1559, Luca 7173, Elsa 2287, Carlo 24533}: 35552 × 22/342 = 2286,8 e
    // 35552 × 236/342 = 24532,9. Ugo ha la coppia e non riceve. Setup a mano per Carlo (riga d'usufrutto al 50 % dal 12/3), come
    // `EstinzioneConPiuUsufruttiTest`; il resto dalle rotte vere.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-03-12'));
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->where('tipologia', 'usufruttuario')->update(['quota' => 50]);
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Carlo']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2026-03-12', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    fds50Registra($this, $s, ['quota' => 50, 'accrescimento' => true] + fds50ModuloEstinzione($s, 'Elsa', '2026-06-01'));

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    $f = $a['rate']['fermate_dopo_scrivi'][0] ?? null;
    expect($f)->not->toBeNull('La fine di Luca non dice nessuna fermata.');
    expect($f['persona'])->toBe('Ugo')
        ->and(collect($f['parti'])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['cifra']])->sortKeys()->all())->toBe(['Carlo' => 24533, 'Elsa' => 2287])
        ->and((int) $f['cifra'])->toBe(26820);
});

it('sentinella — O-DL7-DOPO-ESTINZ: se l\'usufrutto di Elsa si estingue dopo la fine di Luca e Ugo torna pieno, i giorni tornano a Ugo (che ha la coppia): nessuna fermata nuova', function () {
    // Fonte: Ugo ha la coppia (chiHaLaCoppia): quello che l'estinzione gli rida non è una parte da regolare.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-03-12'));
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $a = fds50Anteprima($this, $s, fds50ModuloEstinzione($s, 'Elsa', '2026-09-01'));

    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
});

// --- Le fini che la .50 non sa leggere (sentinelle) --------------------------------------------------------------------------------------------------------

it('sentinella — O-DL7-110: se la fine di Ines è di prima della .50 (senza la scelta nel registro) la coppia non è «scritta» e la fine di Luca non si ferma', function () {
    // Senza la scelta nel registro la fine di Ines non ha una coppia di cui parlare: il programma non deve inventarsi una fermata.
    $s = b51aDl7($this);
    pflComePrimaDellaCinquanta($s['fine_ines']);
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([])
        // La fermata di prima non c'è: la fine di Luca si registra senza spunta.
        ->and($a['cancello']['richiesto'])->toBeFalse('Il cancello chiede la spunta per una fermata che non c\'è: ' . implode(' | ', $a['cancello']['motivi']));
});

it('O-DL7-110 — senza il riparto registrato del piano (righe_riparto vuote) la coppia della fine di Ines c\'è lo stesso, con i suoi periodi nel registro, e la fine di Luca ferma € 268,20 come con il riparto', function (string $quando) {
    // La coppia della fine di Ines (35552, e i suoi periodi in `conguaglio.periodi`) non dipende da `righe_riparto`: la fermata si calcola dal registro
    // della fine, e il riparto serve al prospetto. Vale sia con le righe cancellate dopo la fine di Ines (e prima della vendita) sia con le righe
    // cancellate prima della fine di Ines, che è il piano senza riparto fin dalla generazione: in tutti e due i casi coppia Ugo +35552 / Ines −35552,
    // periodi 24/1–31/12. Il conto è quello di O-DL7: 26820.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['importo' => 126475]);
    b51aPersone($s, ['Luca', 'Elsa', 'Marco', 'Carlo', 'Dora', 'Anna', 'Bruno']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-01-07');
    if ($quando === 'prima') {
        \Illuminate\Support\Facades\DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    $s['fine_ines'] = fclRegistra($this, $s, fclModulo($s, null, '2026-01-24') + ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]);
    expect(fds50Coppia($s, $s['fine_ines']))->toBe(['Ines' => -35552, 'Ugo' => 35552]);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-02-08'));
    if ($quando === 'dopo') {
        \Illuminate\Support\Facades\DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');

    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]])
        ->and($a['cancello']['richiesto'])->toBeTrue();
})->with(['righe cancellate prima della fine di Ines' => ['prima'], 'righe cancellate dopo la fine di Ines' => ['dopo']]);

// --- La fine di Luca con rate sue: due cancelli -----------------------------------------------------------------------------------------------------------------

/** DL7 con la vendita e un «Riscaldamento 2026» generato dopo l'inizio di Luca (le sue rate, 4 emesse): la fine di Luca ha una coppia sua. */
function b51aDl7ConRateDiLuca($test): array
{
    $s = b51aDl7($test);
    $s['riscaldamento'] = b51aRiscaldamento($s, 73000, null, null);
    b51aEmettiPiano($test, $s, $s['riscaldamento'], '2026-04-10');
    $s['vendita'] = b51aVendi($test, $s, 'Ugo', 'Elsa', '2026-03-12');

    return $s;
}

it('O-DL7-DUE-COPPIE — la fine di Luca che ha rate sue ha due ragioni nel cancello (le sue quote e la fermata di Ines, € 268,20), una spunta e una nota; la coppia di Luca (€ 516,00) e la fermata non si confondono', function () {
    // Fonte: Luca ha 4 quote emesse e 8 in bozza del «Riscaldamento 2026» (€ 730,00 tutto all'inquilino): con «Scrivi» i 258 giorni dopo la sua uscita
    // (730,00 × 258/365 = 516,00) vanno a Elsa sulla gestione del riscaldamento; i 258 giorni dell'Ordinaria li ferma la fine di Ines (26820).
    $s = b51aDl7ConRateDiLuca($this);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18', null, ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]);

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 51600]])
        ->and(b51aFermate($a))->toBe([['Ugo', 26820, ['Elsa']]]);
    $motivi = collect($a['cancello']['motivi']);
    expect($motivi->contains(fn ($m) => str_contains($m, 'quote di rate già emesse a Luca')))->toBeTrue('Manca il motivo delle quote di Luca: ' . $motivi->implode(' | '))
        ->and($motivi->contains(fn ($m) => str_contains($m, '€ 268,20') && str_contains($m, 'Ugo')))->toBeTrue('Manca il motivo della fermata: ' . $motivi->implode(' | '));

    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18', null, ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]);
    $registro = b51aRegistro($fine);
    expect($registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and(collect($registro['fermata_dopo_scrivi'] ?? [])->map(fn ($f) => [(int) $f['fine_id'], (int) $f['cifra']])->all())->toBe([[(int) $s['fine_ines']->id, 26820]]);
});

it('O-DL7-CONG-LUCA — «Annulla il conguaglio» di Luca (la sua coppia sul riscaldamento) non fa cadere la fermata di Luca, che sta sulla coppia di Ines: lo storico non la dice annullata', function () {
    // Fonte: la fermata sta sulla coppia di Ines (`fine_id` = la fine di Ines); `fineSenzaConguaglio` guarda quella, non la fine di Luca. Senza la
    // fermata sulla coppia di Ines il test cade: è la sentinella di questo caso.
    $s = b51aDl7ConRateDiLuca($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18', null, ['conguaglio' => Subentro::SCRIVI_IL_CONGUAGLIO]);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertStatus(302);
    $voce = fds50Storico($s, $fine);
    expect(collect($voce['fermate'] ?? [])->contains(fn ($t) => str_contains((string) $t, '268,20') && ! str_contains((string) $t, 'annullato')))->toBeTrue('Lo storico non dice più la fermata di Luca: ' . json_encode($voce['fermate'] ?? [], JSON_UNESCAPED_UNICODE));
});

it('O-DL7-GRUPPO — una fermata con due riceventi (Elsa e Carlo) e un solo che varia: la fine di Marco l\'1/10 dice la parte verso Carlo da € 22,87 a € 118,51, non la somma anche di Elsa (da € 45,74 a € 141,38)', function () {
    // Conto a mano (coppia di Ines 35552, regola dei resti maggiori; Elsa e Carlo usufruttuari al 50 % dal 12/3, l'usufrutto di Elsa si estingue l'1/6 con
    // l'accrescimento a Carlo, fine di Luca il 18/4 che ferma Elsa 2287 e Carlo 24533 — come O-DL7-ESTINZ-ACC; Marco inquilino dall'1/6):
    //   - con Marco dall'1/6 (214 giorni): i 44 giorni 18/4–31/5 restano 22 a Elsa e 22 a Carlo, i 214 dall'1/6 sono di Marco. Pesi [Luca 69, Ugo 15, Elsa 22,
    //     Carlo 22, Marco 214] → Elsa 2286,97, Carlo 2286,97, Marco 22245,99, Luca 7172,77, Ugo 1559,30; interi 35548, quattro centesimi ai resti più alti
    //     (Marco, Elsa, Carlo, Luca) → Elsa 2287, Carlo 2287. I residui della fermata: Elsa 2287 (il suo peso non cala), Carlo 24533 − (24533 − 2287) = 2287;
    //   - con la fine di Marco l'1/10 (Marco fino al 30/9 = 122 giorni): i 92 giorni dall'1/10 tornano a Carlo, che ne ha 22 + 92 = 114. Pesi [Luca 69, Ugo 15,
    //     Elsa 22, Carlo 114, Marco 122] → Carlo 11850,67, Marco 12682,29, Elsa 2286,97, Luca 7172,77, Ugo 1559,30; interi 35549, tre centesimi (Elsa, Luca,
    //     Carlo) → Carlo 11851, Elsa 2287. La parte di Carlo risale da 2287 a 11851; quella di Elsa non varia.
    $s = b51aDl7($this);
    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-03-12'));
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->where('tipologia', 'usufruttuario')->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Carlo']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2026-03-12', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    fds50Registra($this, $s, ['quota' => 50, 'accrescimento' => true] + fds50ModuloEstinzione($s, 'Elsa', '2026-06-01'));
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');

    $a = b51aAnteprimaFine($this, $s, 'Marco', '2026-10-01');
    $risale = $a['rate']['fermate_risalgono'] ?? [];
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
    expect(collect($risale)->map(fn ($r) => [(int) $r['prima'], (int) $r['dopo'], array_values((array) $r['verso'])])->all())->toBe([[2287, 11851, ['Carlo']]]);
    $testo = b51aTestoAnteprima(['rate' => ['frasi' => $a['rate']['frasi']], 'cancello' => $a['cancello']]);
    expect(str_contains($testo, '€ 22,87') && str_contains($testo, '€ 118,51'))->toBeTrue("Il pannello non dice la parte di Carlo, da € 22,87 a € 118,51: {$testo}")
        ->and(str_contains($testo, '45,74') || str_contains($testo, '141,38'))->toBeFalse("Il pannello somma anche la parte di Elsa, che non varia: {$testo}");
});
