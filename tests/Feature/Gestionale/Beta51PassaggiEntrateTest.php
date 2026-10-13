<?php

/**
 * 1.11.0-beta.51 — la Coda 252 (i casi limite della .50 con una voce nei giorni dopo l'uscita o dopo un cambio d'inquilino) e la
 * 251-b (il piede della stampa), con i test che mancavano (252.7).
 *
 * 252.1 (soldi fra le parti in silenzio; non serve un'entrata). Basta una voce di spesa di € 400,00 a competenza 1/10–31/12 (30 %
 * all'inquilino = 12000) e un cambio d'inquilino in un passo da Ines a Marco l'1/4 con «Scrivi»; fine di Marco l'1/7 con «Scrivi», Luca
 * dall'1/10. La voce è tutta dopo il cambio: non è di Ines, che è uscita, ma di chi è entrato (con «Scrivi») e poi di Luca. Oggi
 * (misurato su TEST 591b2c11) il prospetto dà a Ines € 208,77 invece di € 88,77, e a Luca € 90,74 invece di € 210,74.
 *   Conto a mano: giorni Ines 1/1–31/3 = 90, Marco 1/4–30/6 = 91, senza conduttore 1/7–30/9 = 92, Luca 1/10–31/12 = 92. Spese generali
 *   36000 (30 % di 1200,00): Ines 36000 × 90/365 = 8876,7 → 8877; Marco 8975; Ugo 9074; Luca 9074 (somma 36000). Voce dopo 12000 tutta dopo
 *   il cambio: la dà a Marco il cambio, a Ugo la fine di Marco, e Luca la rimborsa a Ugo: Luca = 9074 + 12000 = 21074. Controllo:
 *   8877 + 8975 + 21074 + 9074 = 48000.
 *
 * 252.2 e 251-b, il piede della stampa: nel piede delle sezioni, nel blocco «Giorni senza conduttore» e nella frase finale nessuna cifra
 * si stampa con «€ -»: il segno è nelle parole, e «da restituire a …: i giorni dopo la sua uscita» si dice solo di chi è uscito. La
 * colonna «Importo» delle righe tiene invece il segno (decisione 86.4): il verso lo dice la colonna «Come».
 *
 * 252.3 (un verso per pagatore), 252.4 (il verso nel cambio con «Non scriverlo»), 252.5 (il piano della 1.10 senza riparto: non si tira a
 * indovinare), 252.6 (una frase per unità solo se differiscono) e le tre frasi con il box spuntato: vedi i commenti dei singoli test.
 *
 * 252.7 (i test che mancano; nessun codice): la strada A′ per la fine senza la scelta, il piano che la coppia non ha preso, la coppia
 * tutta a credito nei sei passaggi.
 *
 * Che cosa NON copre: la stampa del PDF vera (si guarda la vista resa); MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/** Ogni punto in cui compare la cifra nel testo, con un po' di contesto prima (110 caratteri) e dopo (70). */
function b51aContesti(string $testo, string $cifra): array
{
    $out = [];
    $da = 0;
    while (($i = mb_strpos($testo, $cifra, $da)) !== false) {
        $out[] = mb_substr($testo, max(0, $i - 110), min($i, 110) + mb_strlen($cifra) + 70);
        $da = $i + 1;
    }

    return $out;
}

/** La coda della stampa dal blocco «Giorni senza conduttore» in poi (il piede del blocco e la frase finale). */
function b51aCodaDellaStampa(string $stampa): string
{
    $i = mb_strpos($stampa, 'Giorni senza conduttore');

    return $i === false ? '' : mb_substr($stampa, $i);
}

// --- 252.1: la voce tutta dopo un cambio d'inquilino non è di chi è uscito ----------------------------------------------------------

it('O-252.1-S — spesa dopo il cambio Ines → Marco con «Scrivi», fine di Marco e Luca: Ines non ha la «Voce dopo» (€ 88,77), Luca la rimborsa a Ugo (€ 210,74)', function () {
    $s = b51aCambioMarco($this, 'spesa');
    $p = b51aProspettoInBreve($s);

    expect($p['Ines']['tot'])->toBe([8877, 8877, 0])
        ->and(collect($p['Ines']['voci'])->pluck(0)->unique()->values()->all())->toBe(['Spese generali'], 'Ines ha ancora la «Voce dopo» nelle sue rate: ' . json_encode($p['Ines']['voci'], JSON_UNESCAPED_UNICODE))
        ->and($p['Marco']['tot'])->toBe([8975, 0, 0])
        ->and($p['Luca']['tot'])->toBe([21074, 0, 21074])
        ->and(collect($p['Luca']['voci'])->map(fn ($v) => [$v[0], $v[2], $v[3]])->sortBy(0)->values()->all())->toBe([['Spese generali', 'Ugo', 9074], ['Voce dopo', 'Ugo', 12000]])
        ->and(b51aSenzaConduttore($s))->toBe([['Ugo', 9074, 92, null]])
        // Controllo: 8877 + 8975 + 21074 + 9074 = 48000 (le spese 36000 e la voce 12000).
        ->and(collect($p)->sum(fn ($c) => $c['tot'][0]) + 9074)->toBe(48000);
});

it('O-252.1-E — entrata dopo il cambio: l\'entrata di Luca non è a danno di Ines (€ 88,77 e non € -31,23); Luca ha € -29,26, cioè Ugo gli restituisce', function () {
    // Fonte: conto a mano in testa con la voce a credito: Luca 9074 − 12000 = −2926; somma 8877 + 8975 − 2926 + 9074 = 24000 = 36000 − 12000.
    $s = b51aCambioMarco($this, 'entrata');
    $p = b51aProspettoInBreve($s);

    expect($p['Ines']['tot'])->toBe([8877, 8877, 0])
        ->and($p['Marco']['tot'])->toBe([8975, 0, 0])
        ->and($p['Luca']['tot'])->toBe([-2926, 0, -2926])
        ->and(collect($p['Luca']['voci'])->map(fn ($v) => [$v[0], $v[2], $v[3]])->sortBy(0)->values()->all())->toBe([['Spese generali', 'Ugo', 9074], ['Voce dopo', 'Ugo', -12000]]);
});

it('O-252.1-N — con «Non scriverlo» nel cambio la «Voce dopo» resta nelle rate di Ines e Luca la rimborsa a lei (€ 210,74), non a Ugo; Ines ha la restituzione dell\'intera voce', function () {
    // Fonte: nessuna coppia nel cambio: Ines tiene le rate; i giorni dopo il cambio li rimborsano a Ines chi li usa. Luca = 9074 + 12000 =
    // 21074 pagata da Ines; Marco 8975 pagata da Ines. Ines: totale 8877, nelle sue rate 48000 (36000 + 12000), da rimborsare −39123.
    $s = b51aCambioMarco($this, 'spesa', 'non_scrivere');
    $p = b51aProspettoInBreve($s);

    expect($p['Ines']['tot'])->toBe([8877, 48000, -39123])
        ->and(collect($p['Ines']['voci'])->filter(fn ($v) => $v[0] === 'Voce dopo')->map(fn ($v) => [$v[1], $v[3]])->sortBy(0)->values()->all())->toBe([['rate', 12000], ['restituzione', -12000]])
        ->and($p['Marco']['tot'])->toBe([8975, 0, 8975])
        ->and($p['Luca']['tot'])->toBe([21074, 0, 21074])
        ->and(collect($p['Luca']['voci'])->map(fn ($v) => [$v[0], $v[2], $v[3]])->sortBy(0)->values()->all())->toBe([['Spese generali', 'Ines', 9074], ['Voce dopo', 'Ines', 12000]]);
});

it('O-252.1-C — solo il cambio con «Scrivi» (senza la fine di Marco): la «Voce dopo» è di Marco (€ 391,23 = 271,23 + 120,00), non di Ines', function () {
    // Fonte: Marco dal 1/4 al 31/12 = 275 giorni: Spese generali 36000 × 275/365 = 27123,3 → 27123 e Voce dopo 12000 (tutta sua).
    $s = b51aCambioMarco($this, 'spesa', 'scrivi', false);
    $p = b51aProspettoInBreve($s);

    expect($p['Ines']['tot'])->toBe([8877, 8877, 0])
        ->and($p['Marco']['tot'])->toBe([39123, 0, 0])
        ->and(collect($p['Marco']['voci'])->map(fn ($v) => [$v[0], $v[1], $v[3], $v[4]])->sortBy(0)->values()->all())->toBe([['Spese generali', 'conguaglio', 27123, 275], ['Voce dopo', 'conguaglio', 12000, 92]])
        ->and(b51aSenzaConduttore($s))->toBe([]);
});

it('sentinella — O-252.1-NP: se il cambio con «Scrivi» non ha preso il piano della voce, la voce resta nelle rate di Ines (decisione 43): come oggi, prima e dopo', function () {
    // Fonte: decisione 43. Il «Riscaldamento 2026» (Combustibile € 400,00, 1/10–31/12) è generato prima del cambio e non emesso: la coppia
    // del cambio prende solo il preventivo. Misurato su TEST: Ines [48877, 48877, 0] con «Combustibile» 40000 per 92 giorni; Luca solo con
    // le spese generali (9074).
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco', 'Luca']);
    fclGenera($s);
    $riscaldamento = b51aRiscaldamento($s, 40000, '2026-10-01', '2026-12-31');
    fclEmetti($this, $s, '2026-03-31');
    $cambio = fclRegistra($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-04-01') + ['conguaglio' => 'scrivi']);
    expect($s['piano']->fresh()->presoNelConguaglioDa($cambio))->toBeTrue()
        ->and($riscaldamento->fresh()->presoNelConguaglioDa($cambio))->toBeFalse();
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [], 'Marco') + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    b51aEmettiPiano($this, $s, $riscaldamento, '2026-09-30');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));

    $p = b51aProspettoInBreve($s);
    expect($p['Ines']['tot'])->toBe([48877, 48877, 0])
        ->and(collect($p['Ines']['voci'])->filter(fn ($v) => $v[0] === 'Combustibile')->map(fn ($v) => [$v[1], $v[3], $v[4]])->values()->all())->toBe([['rate', 40000, 92]])
        ->and($p['Luca']['tot'])->toBe([9074, 0, 9074]);
});

it('sentinella — O-252.1-R: il cambio «regolato fra le parti» (conguaglio annullato) e poi la fine di Marco: la voce resta com\'è oggi, nelle rate di Ines (decisioni 43 e 46)', function () {
    // Fonte: decisioni 43 e 46. Misurato su TEST: Ines [20877, 20877, 0] con la «Voce dopo» 12000 per 92 giorni; Marco 8975 «regolato»;
    // Luca [9074, 0, 9074] con le sole spese generali.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco', 'Luca']);
    b51aCapitolo($s, 'Voce dopo', 'spesa', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-03-31');
    $cambio = fclRegistra($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-04-01') + ['conguaglio' => 'scrivi']);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertStatus(302);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [], 'Marco') + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));

    $p = b51aProspettoInBreve($s);
    expect($p['Ines']['tot'])->toBe([20877, 20877, 0])
        ->and(collect($p['Ines']['voci'])->filter(fn ($v) => $v[0] === 'Voce dopo')->map(fn ($v) => [$v[1], $v[3], $v[4]])->values()->all())->toBe([['rate', 12000, 92]])
        ->and($p['Marco']['tot'])->toBe([8975, 0, 0])
        ->and($p['Luca']['tot'])->toBe([9074, 0, 9074]);
});

// --- 252.2 e 251-b: il piede della stampa ----------------------------------------------------------------------------------------------------

it('O-252.2-S1 — fine di Ines con «Scrivi» e antenna € 1.000,00: il piede dei giorni senza conduttore e la frase finale dicono € 118,52 di credito, mai «€ -118,52»', function () {
    // Fonte: giorni senza conduttore dopo l'uscita: spese 36000 × 184/365 = 18148 (€ 181,48), antenna −30000 (€ -300,00 nella riga, resta);
    // totale −11852 = € -118,52. Oggi: «Totale che resta a chi ha pagato € -118,52» e «… € 60,00, di cui € 178,52 ai conduttori e € -118,52
    // che restano a chi le ha pagate». Le parole le sceglie chi scrive le frasi: il test cerca la cifra, il verso («credito») e l'assenza di «€ -».
    $s = b51aCoppiaACredito($this);
    $stampa = fclStampa($s);

    expect(str_contains($stampa, '€ -118,52'))->toBeFalse('La stampa dice ancora «€ -118,52».');
    $contesti = b51aContesti(b51aCodaDellaStampa($stampa), '€ 118,52');
    expect(count($contesti))->toBeGreaterThanOrEqual(2, 'Il piede e la frase finale non dicono € 118,52: ' . b51aCodaDellaStampa($stampa));
    foreach ($contesti as $c) {
        expect(str_contains($c, 'credito'))->toBeTrue("Una cifra € 118,52 non dice che è a credito: «{$c}»");
    }
});

it('O-252.2-S2 — fine di Ines con «Non scriverlo»: è Ines, che è uscita, a dover restituire € 118,52 fra le parti; la stampa non dice più «da restituire a chi le ha pagate € -118,52»', function () {
    // Fonte: Ines ha nelle rate i giorni dopo l'uscita: spese +18148 e entrata −30000 (−11852 netto, un credito che non è suo); sezione di
    // Ines [17852, 6000, 11852]. Oggi: «Totale da restituire a chi le ha pagate, fra le parti € -118,52» (verso rovesciato) e «… e € -118,52
    // da restituire a chi è uscito, fra le parti». Atteso: chi è uscito deve restituire, e le cifre senza «€ -». Il piede della sezione di
    // Ines («di cui da rimborsare a chi le ha pagate € 118,52») resta.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    fclEmetti($this, $s, '2026-09-30');
    $stampa = fclStampa($s);

    expect(b51aConduttori($s))->toBe(['Ines' => [17852, 6000, 11852]]);
    expect(str_contains($stampa, '€ -118,52'))->toBeFalse('La stampa dice ancora «€ -118,52».');
    $coda = b51aCodaDellaStampa($stampa);
    $contesti = b51aContesti($coda, '€ 118,52');
    expect(count($contesti))->toBeGreaterThanOrEqual(2, 'Il piede e la frase finale non dicono € 118,52: ' . $coda);
    foreach ($contesti as $c) {
        expect(str_contains($c, 'restituire') && str_contains($c, 'chi è uscito'))->toBeTrue("Una cifra € 118,52 non dice che la restituisce chi è uscito: «{$c}»");
    }
    // Il piede della sezione di Ines resta com'è.
    expect(str_contains(b51aSezioneDelConduttore($stampa, 'Ines'), 'da rimborsare a chi le ha pagate € 118,52'))->toBeTrue('Il piede della sezione di Ines è cambiato.');
});

it('O-252.2-S3 — Luca entra l\'1/10 con la coppia a credito: la sua sezione non dice «da restituire a Luca: i giorni dopo la sua uscita» (non è uscito) e la frase finale non ha «€ -»', function () {
    // Fonte: Luca 92 giorni: spese 9074, antenna −30000 → −20926 (€ -209,26: gli spetta); Ines [17852, 17852, 0]; senza conduttore Ugo 9074
    // (18148 × 92/184); totale dell'unità 17852 − 20926 + 9074 = 6000. Oggi la sezione di Luca stampa «Totale a carico di Luca € -209,26» e
    // «da restituire a Luca: i giorni dopo la sua uscita, che ha pagato € 209,26» (Luca non è uscito), e la frase finale «di cui € -30,74 ai
    // conduttori». Atteso: a favore di Luca € 209,26, da ricevere da chi ha pagato i suoi giorni; la frase finale: € 178,52 a carico di
    // Ines, € 209,26 a favore di Luca e € 90,74 che restano a chi li ha pagati.
    $s = b51aCoppiaACredito($this);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $stampa = fclStampa($s);

    expect(b51aConduttori($s))->toBe(['Ines' => [17852, 17852, 0], 'Luca' => [-20926, 0, -20926]]);
    $luca = b51aSezioneDelConduttore($stampa, 'Luca');
    expect($luca)->not->toBe('');
    expect(str_contains($stampa, '€ -209,26'))->toBeFalse('La stampa dice ancora «€ -209,26».')
        ->and(str_contains($stampa, '€ -30,74'))->toBeFalse('La stampa dice ancora «€ -30,74».')
        ->and(str_contains($luca, 'da restituire a Luca'))->toBeFalse('Luca non è uscito: «da restituire a Luca» è del verso sbagliato.')
        ->and(str_contains($luca, 'dopo la sua uscita'))->toBeFalse('Luca non è uscito: la sua sezione dice «dopo la sua uscita».');
    $contesti = b51aContesti($luca, '€ 209,26');
    expect(count($contesti))->toBeGreaterThanOrEqual(1, 'La sezione di Luca non dice € 209,26: ' . $luca);
    expect(collect($contesti)->contains(fn ($c) => str_contains($c, 'favore') || str_contains($c, 'ricever')))->toBeTrue('La sezione di Luca non dice che € 209,26 sono a suo favore: ' . implode(' | ', $contesti));
    // La frase finale: nessun «€ -», e le tre cifre.
    $finale = mb_substr($stampa, (int) mb_strpos($stampa, 'Totale delle voci che il riparto'));
    expect(str_contains($finale, '€ -'))->toBeFalse("La frase finale ha un «€ -»: {$finale}");
    foreach (['€ 178,52', '€ 209,26', '€ 90,74'] as $cifra) {
        expect(str_contains($finale, $cifra))->toBeTrue("La frase finale non dice {$cifra}: {$finale}");
    }
});

it('decisione 86.4 — la colonna «Importo» dei giorni senza conduttore tiene il segno: l\'antenna è « 92 giorni € -300,00», la spesa « 184 giorni € 181,48»', function () {
    // Conto a mano (fine di Ines l'1/7, antenna € 1.000,00 a 1/10–31/12, 30 % all'inquilino): spese 36000 × 184/365 = 18148 (€ 181,48) a carico
    // dell'inquilino nei 184 giorni dopo l'uscita; antenna 100000 × 30 % = 30000, tutta nei 92 giorni di competenza, con il segno dell'entrata:
    // −30000 (€ -300,00). Il piede e la frase finale dicono € 118,52 con le parole; la colonna «Importo» delle righe no, tiene il segno e il
    // verso lo dice la colonna «Come».
    $s = b51aCoppiaACredito($this);
    $coda = b51aCodaDellaStampa(fclStampa($s));

    expect(b51aSenzaConduttore($s))->toBe([['Ugo', 18148, 184, null], ['Ugo', -30000, 92, null]])
        ->and(str_contains($coda, 'Spese generali 184 giorni € 181,48'))->toBeTrue("La riga delle spese cambia: {$coda}")
        ->and(str_contains($coda, 'Affitto antenna 92 giorni € -300,00'))->toBeTrue("La riga dell'antenna ha perso il segno: {$coda}")
        ->and(str_contains($coda, 'Affitto antenna 92 giorni € 300,00'))->toBeFalse("La riga dell'antenna dice € 300,00 senza segno: {$coda}");
});

it('decisione 86.4 — la colonna «Importo» delle voci di un conduttore tiene il segno: per Luca l\'antenna è «92 € -300,00» e il totale a suo favore € 209,26', function () {
    // Conto a mano (Luca entra l'1/10, 92 giorni): spese 36000 × 92/365 = 9074 (€ 90,74), antenna 100000 × 30 % = 30000 tutta nei suoi 92
    // giorni, con il segno dell'entrata: −30000 (€ -300,00). Totale 9074 − 30000 = −20926: a favore di Luca € 209,26, detto con le parole.
    $s = b51aCoppiaACredito($this);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $luca = b51aSezioneDelConduttore(fclStampa($s), 'Luca');

    expect(b51aConduttori($s)['Luca'])->toBe([-20926, 0, -20926])
        ->and(str_contains($luca, 'Affitto antenna Proprietà1.000 su 1.000 millesimi 30 % 01/10/2026–31/12/2026 92 € -300,00'))->toBeTrue("La riga dell'antenna di Luca ha perso il segno: {$luca}")
        ->and(str_contains($luca, '92 € 300,00'))->toBeFalse("La riga di Luca dice € 300,00 senza segno: {$luca}")
        ->and(str_contains($luca, 'Totale a favore di Luca € 209,26'))->toBeTrue("Il totale di Luca non è a suo favore: {$luca}");
});

// --- 252.3: un verso per pagatore ------------------------------------------------------------------------------------------------------------

/**
 * Il caso 252.3: antenna € 1.000,00 a 1/1–30/9; rate fino al 31/8; vendita Ugo → Elsa l'1/9 (registrata PRIMA della fine di Ines); fine di
 * Ines l'1/7 con «Scrivi» (coppia: Ines −8038, Ugo −698, Elsa +8736); rate fino al 30/9. Luca entra l'1/8: paga 31 giorni a Ugo e 122 a
 * Elsa.
 */
function b51aVersoPerPagatore($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca', 'Elsa']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-01-01', '2026-09-30');
    fclGenera($s);
    fclEmetti($test, $s, '2026-08-31');
    b51aVendi($test, $s, 'Ugo', 'Elsa', '2026-09-01');
    $s['fine_ines'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fds50Coppia($s, $s['fine_ines']))->toBe(['Elsa' => 8736, 'Ines' => -8038, 'Ugo' => -698]);
    fclEmetti($test, $s, '2026-09-30');

    return $s;
}

it('O-252.3 — pagatori di segno diverso: la frase dell\'inizio di Luca dice che rimborsa € 87,36 a Elsa mentre Ugo gli restituisce € 3,49, non «li rimborsa Luca a Ugo e Elsa»', function () {
    // Fonte: Luca dall'1/8 paga a Ugo per 31 giorni (1/8–31/8): spese 18148 × 31/184 = 3057,5 → 3058, entrata −10110 × 31/92 = −3406,6 → −3407:
    // netto −349. A Elsa per 122 giorni: spese 18148 × 122/184 = 12033, entrata (30 giorni, fino al 30/9) −10110 × 30/92 = −3297: netto 8736.
    // Totale di Luca 8387. Oggi: «… li hanno presi con il conguaglio Ugo e Elsa, e li rimborsa Luca a Ugo e Elsa».
    $s = b51aVersoPerPagatore($this);
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    $frasi = implode(' ', $a['rate']['frasi']);

    expect(str_contains($frasi, 'rimborsa Luca a Ugo'))->toBeFalse('La frase fa rimborsare Luca a Ugo, che invece gli restituisce: ' . $frasi)
        ->and(preg_match('/Elsa[^.]{0,80}87,36|87,36[^.]{0,80}Elsa/u', $frasi))->toBe(1, 'La frase non dice € 87,36 con Elsa: ' . $frasi)
        ->and(preg_match('/Ugo[^.]{0,80}3,49|3,49[^.]{0,80}Ugo/u', $frasi))->toBe(1, 'La frase non dice € 3,49 con Ugo: ' . $frasi)
        ->and(preg_match('/Ugo[^.]{0,80}restituisc|restituisc[^.]{0,80}Ugo/u', $frasi))->toBe(1, 'La frase non dice che Ugo restituisce: ' . $frasi);
});

it('O-252.3 — concordanza: il prospetto di Luca dice Ugo −€ 3,49 ed Elsa +€ 87,36, e la frase del pannello dice gli stessi nomi, versi e cifre (entro un centesimo)', function () {
    // Fonte unica del chi: `catenaDeiConduttori`. Qui si legge dal prospetto (misurato: Ugo −349, Elsa +8736) e si
    // controlla che la frase dica, per ogni pagatore, la sua cifra.
    $s = b51aVersoPerPagatore($this);
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-08-01'));

    $netto = [];
    foreach (b51aProspetto($s)['conduttori'] as $c) {
        if ($c['nome'] !== 'Luca') {
            continue;
        }
        foreach ($c['voci'] as $v) {
            if (! empty($v['pagato_da'])) {
                $netto[$v['pagato_da']] = ($netto[$v['pagato_da']] ?? 0) + (int) $v['importo'];
            }
        }
    }
    ksort($netto);
    expect($netto)->toBe(['Elsa' => 8736, 'Ugo' => -349]);

    $frasi = implode(' ', $a['rate']['frasi']);
    foreach ($netto as $nome => $cent) {
        $euro = number_format(abs($cent) / 100, 2, ',', '.');
        expect(preg_match('/' . $nome . '[^.]{0,80}' . preg_quote($euro, '/') . '|' . preg_quote($euro, '/') . '[^.]{0,80}' . $nome . '/u', $frasi))
            ->toBe(1, "La frase non dice la cifra di {$nome} (€ {$euro}): {$frasi}");
    }
});

// --- 252.4: il verso di chi entra nel cambio con «Non scriverlo» --------------------------------------------------------------------------------

it('O-252.4 — cambio Ines → Marco con antenna € 1.000,00: un credito di € 209,26 passa a Marco, e con «Non scriverlo» è Ines a restituire la differenza a Marco', function (string $scelta) {
    // Fonte: parte di Marco dall'1/10: spese 36000 × 92/365 = 9074, entrata −30000 → −20926 (totale_entrante). Oggi (misurato) la frase dice
    // «€ 209,26 dei giorni dopo l'uscita passano a Marco» e «Marco glieli rimborsa con il prospetto degli oneri accessori».
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-09-30');
    $a = fclAnteprima($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-10-01') + ['conguaglio' => $scelta]);
    $frasi = implode(' ', $a['rate']['frasi']);

    expect($a['rate']['conguaglio']['totale_entrante'])->toBe(-20926)
        ->and(preg_match('/credito[^.]{0,40}209,26/u', $frasi))->toBe(1, 'La frase non dice «un credito di € 209,26»: ' . $frasi)
        ->and(str_contains($frasi, 'Marco glieli rimborsa'))->toBeFalse('La frase dice che Marco rimborsa un credito: ' . $frasi)
        ->and(preg_match('/Ines restituisce[^.]{0,40}Marco/u', $frasi))->toBe(1, 'La frase non dice che Ines restituisce la differenza a Marco: ' . $frasi);
})->with(['scrivi', 'non_scrivere']);

it('sentinella — O-252.4 con il verso positivo: il cambio con una spesa dopo (€ 210,74) dice come oggi che i giorni passano a Marco e che Marco li rimborsa', function () {
    // Fonte: Marco dall'1/10: spese 9074 + «Altra spesa» € 400,00 al 30 % = 12000 → 21074. Con il verso positivo la frase resta com'è.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Marco']);
    b51aCapitolo($s, 'Altra spesa', 'spesa', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-09-30');
    $a = fclAnteprima($this, $s, fclModulo($s, $s['persone']['Marco'], '2026-10-01') + ['conguaglio' => 'scrivi']);
    $frasi = implode(' ', $a['rate']['frasi']);

    expect($a['rate']['conguaglio']['totale_entrante'])->toBe(21074)
        ->and(str_contains($frasi, '€ 210,74 dei giorni dopo l\'uscita passano a Marco'))->toBeTrue('La frase positiva è cambiata: ' . $frasi)
        ->and(str_contains($frasi, 'Marco glieli rimborsa con il prospetto degli oneri accessori'))->toBeTrue('La frase positiva è cambiata: ' . $frasi);
});

// --- 252.5: il piano della 1.10 non ha il dato del verso ---------------------------------------------------------------------------------------

/** Fine di Ines con «Non scriverlo», rate fino al 30/9, e (se si dice) il piano senza riparto registrato come uno della 1.10. */
function b51aPiano110($test, bool $conAntenna, bool $senzaRiparto): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca']);
    if ($conAntenna) {
        b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine_ines'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    fclEmetti($test, $s, '2026-09-30');
    if ($senzaRiparto) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }

    return $s;
}

it('O-252.5 — piano della 1.10 senza riparto con un capitolo di entrata: la frase dell\'inizio di Luca non dice chi rimborsa chi (il dato non c\'è), dice che si regola fra le parti', function () {
    // Fonte: regola della beta.14, non si tira a indovinare. Con il riparto la frase dice giusto («Ines restituisce la differenza a Luca»,
    // controllo qui sotto); senza riparto oggi dice «i giorni dopo la sua uscita li rimborsa Luca a Ines, fra le parti» (verso sbagliato: la
    // parte di Luca è −20926, un credito).
    $s = b51aPiano110($this, true, true);
    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-10-01'))['rate']['frasi']);

    expect(str_contains($frasi, 'li rimborsa Luca a Ines'))->toBeFalse('La frase indovina il verso (Luca rimborsa a Ines): ' . $frasi)
        ->and(str_contains($frasi, 'Ines restituisce'))->toBeFalse('La frase indovina il verso (Ines restituisce): ' . $frasi)
        ->and(str_contains($frasi, 'fra le parti'))->toBeTrue('La frase non rimanda alle parti: ' . $frasi);
});

it('sentinella — O-252.5 con il riparto: lo stesso caso dice «Ines restituisce la differenza a Luca» (il verso si sa); senza capitoli di entrata il piano della 1.10 dice «li rimborsa Luca a Ines, fra le parti»', function () {
    $conRiparto = implode(' ', fclAnteprima($this, $s1 = b51aPiano110($this, true, false), fds50ModuloInizio($s1, 'Luca', '2026-10-01'))['rate']['frasi']);
    expect(str_contains($conRiparto, 'Ines restituisce la differenza a Luca'))->toBeTrue('Il verso con il riparto è cambiato: ' . $conRiparto);

    $senzaEntrate = implode(' ', fclAnteprima($this, $s2 = b51aPiano110($this, false, true), fds50ModuloInizio($s2, 'Luca', '2026-10-01'))['rate']['frasi']);
    expect(str_contains($senzaEntrate, 'li rimborsa Luca a Ines, fra le parti'))->toBeTrue('La frase del piano della 1.10 senza entrate è cambiata: ' . $senzaEntrate);
});

// --- 252.6 e le tre frasi con il box spuntato ----------------------------------------------------------------------------------------------------

/** L'Interno 1 con il Box 12 (Ugo proprietario di tutti e due, Ines inquilina di tutti e due), sei rate emesse. */
function b51aConBox($test, string $proprietarioDelBox = 'Ugo'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [[$proprietarioDelBox, 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    b51aPersone($s, ['Luca']);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');

    return $s;
}

it('O-BOX-FRASI — la fine di Ines con il box spuntato: lo scaduto è € 180,00 (€ 162,00 sull\'unità, € 18,00 sul box), le quote sono «sull\'unità e sulle pertinenze spuntate», e il box segue il passaggio con i suoi importi', function () {
    // Fonte: scaduto di Ines: appartamento 36000 × 0,9/12 × 6 = 16200; box 36000 × 0,1/12 × 6 = 1800; totale 18000. Oggi (misurato):
    // «Ines ha € 162,00 scaduti e non pagati», «12 quote di rate già emesse a Ines su questa unità» (6 sull'appartamento e 6 sul box) e in
    // «cosa non cambia» «Pertinenze: il collegamento è descrittivo, non sposta importi.».
    $s = b51aConBox($this);
    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);

    // Lo scaduto: la cifra e la divisione.
    expect($a['rate']['morosita']['importo'])->toBe(18000);
    $frasiMorosita = collect($a['rate']['frasi'])->filter(fn ($t) => str_contains($t, 'scaduti'))->values();
    expect($frasiMorosita)->toHaveCount(1);
    expect(str_contains($frasiMorosita[0], '€ 180,00') && str_contains($frasiMorosita[0], '€ 162,00') && str_contains($frasiMorosita[0], '€ 18,00') && str_contains($frasiMorosita[0], 'Box 12'))
        ->toBeTrue('La frase dello scaduto non dice € 180,00 (€ 162,00 sull\'unità, € 18,00 su «Box 12»): ' . $frasiMorosita[0]);

    // «N quote … su questa unità» solo se sono tutte della principale.
    // (Il filtro è «quote di rate già emesse»: un altro motivo dice «ha 12 quote non ancora emesse», le bozze del piano fermo.)
    $quote = collect($a['cancello']['motivi'])->filter(fn ($m) => str_contains($m, '12 quote di rate già emesse'))->values();
    expect($quote)->toHaveCount(1);
    expect(str_contains($quote[0], 'su questa unità'))->toBeFalse("Il motivo conta le quote del box e dice «su questa unità»: {$quote[0]}")
        ->and(str_contains($quote[0], 'pertinenze spuntate'))->toBeTrue("Il motivo non dice «sull'unità e sulle pertinenze spuntate»: {$quote[0]}");

    // Il blocco «cosa non cambia»: con il box spuntato segue il passaggio con i suoi importi.
    $pertinenze = collect($a['invarianti']['frasi'])->filter(fn ($t) => str_contains($t, 'Box 12') || str_contains($t, 'sposta importi'))->values();
    expect($pertinenze)->toHaveCount(1, 'Il blocco «cosa non cambia» non ha una frase sola sulle pertinenze: ' . implode(' | ', $a['invarianti']['frasi']));
    expect(str_contains($pertinenze[0], 'non sposta importi'))->toBeFalse("Con il box spuntato la frase dice ancora che non sposta importi: {$pertinenze[0]}")
        ->and(str_contains($pertinenze[0], 'Box 12') && str_contains($pertinenze[0], 'seguono il passaggio'))->toBeTrue("La frase non dice che «Box 12» segue il passaggio: {$pertinenze[0]}");
});

it('sentinella — O-BOX-FRASI senza il box spuntato: lo scaduto è quello dell\'unità (€ 162,00), «su questa unità» e «Pertinenze: il collegamento è descrittivo, non sposta importi.» restano com\'erano', function () {
    // Fonte: nessuna pertinenza spuntata: le quote contate sono tutte della principale. Il box c'è ma non segue il passaggio.
    $s = b51aConBox($this);
    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', []) + ['conguaglio' => 'scrivi']);

    expect($a['rate']['morosita']['importo'])->toBe(16200)
        ->and(collect($a['invarianti']['frasi'])->contains('Pertinenze: il collegamento è descrittivo, non sposta importi.'))->toBeTrue()
        ->and(collect($a['cancello']['motivi'])->contains(fn ($m) => str_contains($m, 'quote di rate già emesse a Ines su questa unità')))->toBeTrue('Il motivo delle quote è cambiato: ' . implode(' | ', $a['cancello']['motivi']));
});

it('O-252.6 — l\'inizio di Luca con il box spuntato dopo una fine con il box: le due unità hanno la stessa storia e la frase è una sola, senza il nome dell\'unità; le 24 quote sono «sull\'unità e sulle pertinenze spuntate»', function () {
    // Fonte: 24 quote emesse (6 rate × Ines e Ugo × appartamento e box); stessa storia sulle due unità. Oggi (misurato): «Le 24 quote già
    // emesse su questa unità …» e due frasi uguali «Nell'unità «Int. 1 (Interno 1)» la parte … » e «Nell'unità «Int. B12 (Box 12)» la parte … ».
    $s = b51aConBox($this);
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $a = fclAnteprima($this, $s, ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $frasi = implode(' ', $a['rate']['frasi']);

    expect(substr_count($frasi, 'li rimborsa Luca a Ugo'))->toBe(1, 'La frase è ripetuta per ogni unità: ' . $frasi)
        ->and(substr_count($frasi, 'Nell\'unità «'))->toBe(0, 'La frase nomina ancora l\'unità: ' . $frasi)
        ->and(str_contains($frasi, '24 quote già emesse su questa unità'))->toBeFalse('Le 24 quote sono dette «su questa unità»: ' . $frasi)
        ->and(preg_match('/24 quote già emesse sull.unità e sulle pertinenze spuntate/u', $frasi))->toBe(1, 'Le 24 quote non sono «sull\'unità e sulle pertinenze spuntate»: ' . $frasi);
});

it('sentinella — O-252.6-ALTRO: se il box ha un altro proprietario (Rita) le due unità hanno storie diverse e le frasi restano due, con il nome dell\'unità, come oggi', function () {
    // Fonte: le storie differiscono fra appartamento e box (chi ha preso la coppia: Ugo sull'unità, Rita sul box): una frase per unità con
    // il nome. Ciò che separa i due casi è la storia diversa, non l'inquilino sul box.
    $s = b51aConBox($this, 'Rita');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    $a = fclAnteprima($this, $s, ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloInizio($s, 'Luca', '2026-10-01'));
    $frasi = implode(' ', $a['rate']['frasi']);

    expect(substr_count($frasi, 'Nell\'unità «'))->toBe(2, 'Le frasi per unità non sono più due: ' . $frasi)
        ->and(str_contains($frasi, 'li rimborsa Luca a Ugo'))->toBeTrue()
        ->and(str_contains($frasi, 'li rimborsa Luca a Rita'))->toBeTrue();
});

// --- 252.7: i test che mancano (nessun codice) ---------------------------------------------------------------------------------------------------

it('sentinella — O-252.7a: la fine di Ines senza la scelta (una fine di prima della .50) con l\'antenna da € 400,00 resta nelle rate di Ines con la restituzione dell\'entrata', function () {
    // Fonte: strada A′. Ines: 181 giorni = 17852; giorni dopo 184: spese 18148 restituite, entrata −12000 restituita (+12000). Misurato su
    // TEST: Ines [17852, 24000, −6148]; senza conduttore Ines 18148 e −12000 «da_restituire». Già giusto, mancava il test.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca']);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    pflComePrimaDellaCinquanta($fine);
    fclEmetti($this, $s, '2026-09-30');

    $p = b51aProspettoInBreve($s);
    expect($p['Ines']['tot'])->toBe([17852, 24000, -6148])
        ->and(collect($p['Ines']['voci'])->filter(fn ($v) => $v[0] === 'Affitto antenna')->map(fn ($v) => [$v[1], $v[3]])->sortBy(0)->values()->all())->toBe([['rate', -12000], ['restituzione', 12000]])
        ->and(collect(b51aProspetto($s)['senza_conduttore']['voci'])->filter(fn ($v) => (int) $v['importo'] !== 0)->map(fn ($v) => [$v['pagato_da'] ?? null, $v['conto'], (int) $v['importo'], $v['modo'] ?? null])->sortBy(1)->values()->all())
        ->toBe([['Ines', 'Affitto antenna', -12000, 'da_restituire'], ['Ines', 'Spese generali', 18148, 'da_restituire']]);
});

it('sentinella — O-252.7b: un piano che la coppia non ha preso («Combustibile» € 730,00, 1/10–31/12) resta nelle rate di Ines, 73000 per 92 giorni, e non passa a Ugo', function () {
    // Fonte: decisione 43. Misurato su TEST: Ines [17852, 90852, −73000] con «Combustibile» in rate +73000 e restituzione −73000; i giorni
    // senza conduttore per «Combustibile» sono di Ines (73000, 92 giorni, «da_restituire»), non di Ugo; la coppia (Ugo +18148) è sul solo
    // preventivo.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    b51aPersone($s, ['Luca']);
    fclGenera($s);
    $riscaldamento = b51aRiscaldamento($s, 73000, '2026-10-01', '2026-12-31');
    fclEmetti($this, $s, '2026-06-30');
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($riscaldamento->fresh()->presoNelConguaglioDa($fine))->toBeFalse();
    b51aEmettiPiano($this, $s, $riscaldamento, '2026-09-30');

    $p = b51aProspettoInBreve($s);
    expect(collect($p['Ines']['voci'])->filter(fn ($v) => $v[0] === 'Combustibile')->map(fn ($v) => [$v[1], $v[3]])->sortBy(0)->values()->all())->toBe([['rate', 73000], ['restituzione', -73000]])
        ->and(collect(b51aProspetto($s)['senza_conduttore']['voci'])->filter(fn ($v) => $v['conto'] === 'Combustibile')->map(fn ($v) => [$v['pagato_da'] ?? null, (int) $v['importo'], (int) $v['giorni'], $v['modo'] ?? null])->values()->all())
        ->toBe([['Ines', 73000, 92, 'da_restituire']]);
});

it('sentinella — O-252.7c — la costituzione d\'usufrutto a Elsa l\'1/10 con la coppia tutta a credito: € 209,26 di credito restano a Ugo, e il Wallet va nel verso giusto', function () {
    // Fonte: coppia −11852 a Ugo. Elsa dall'1/10 (92 giorni): spese 18148 × 92/184 = 9074, antenna −30000 → −20926.
    $s = b51aCoppiaACredito($this);
    $a = fds50Anteprima($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));

    expect(b51aFermate($a))->toBe([['Ugo', -20926, ['Elsa']]]);
    $frasi = b51aFrasiConCifra($a, '209,26');
    expect(collect($frasi)->contains(fn ($t) => str_contains($t, '€ 209,26 di credito') && str_contains($t, 'Ugo')))->toBeTrue('Nessuna frase dice «€ 209,26 di credito» a Ugo: ' . implode(' | ', $frasi))
        ->and(collect($frasi)->contains(fn ($t) => str_contains($t, 'Wallet') && preg_match('/debito[^.|]{0,20}Ugo/u', $t) === 1 && preg_match('/credito[^.|]{0,20}Elsa/u', $t) === 1))->toBeTrue('Il Wallet non è nel verso giusto: ' . implode(' | ', $frasi))
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('O-252.7c — la riga di una voce di entrata maturata dal giorno del passaggio in poi dice «l\'entrata è di chi entra per intero» (€ 700,00 a credito di chi entra), non «la spesa»', function (string $passaggio) {
    // Fonte: «Affitto antenna» € 1.000,00 di entrata a competenza 1/10–31/12, 70 % al proprietario = 700,00, tutta dopo il passaggio dell'1/10: è di chi entra per
    // intero, e a chi entra è un credito.
    $s = b51aCoppiaACredito($this);
    $modulo = $passaggio === 'costituzione' ? fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01') : fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');
    $a = fds50Anteprima($this, $s, $modulo);

    $riga = collect($a['rate']['conguaglio']['frasi'])->first(fn ($t) => str_contains($t, 'Affitto antenna'));
    expect($riga)->toContain('maturata dal giorno del passaggio in poi: l\'entrata è di chi entra per intero → € 700,00 a credito di chi entra.')
        ->and($riga)->not->toContain('la spesa è di chi entra');
})->with(['la costituzione dell\'usufrutto' => ['costituzione'], 'la vendita' => ['vendita']]);

it('sentinella — O-252.7c — l\'estinzione dell\'usufrutto di Ugo l\'1/10 con la coppia tutta a credito: Bice torna piena e le restano € 209,26 di credito a Ugo', function () {
    // Fonte: stesso conto della costituzione (Bice dall'1/10: −20926); Ugo usufruttuario ha la coppia.
    $s = b51aCoppiaACredito($this, [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]]);
    $a = fds50Anteprima($this, $s, fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'));

    expect(b51aFermate($a))->toBe([['Ugo', -20926, ['Bice']]])
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('sentinella — O-252.7c — la successione di Ugo il 1/10 con l\'arretrato al defunto: Anna e Bruno hanno € 104,63 di credito ciascuno (€ 209,26 a Ugo)', function () {
    // Fonte: −20926 / 2 = −10463 ciascuno (92 giorni, metà a testa).
    $s = b51aCoppiaACredito($this);
    $a = fds50Anteprima($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'));

    expect(b51aFermate($a))->toBe([['Ugo', -20926, ['Anna', 'Bruno']]])
        ->and(collect($a['rate']['fermate_dopo_scrivi'][0]['parti'])->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Anna', -10463], ['Bruno', -10463]])
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('sentinella — O-252.7c — la seconda vendita (Elsa a Carlo l\'1/11) dopo la costituzione/vendita a Elsa l\'1/10: € 138,75 di credito verso Carlo, a Ugo o a Elsa se la fermata di prima è regolata', function () {
    // Fonte: Carlo dall'1/11 (61 giorni): spese 18148 × 61/184 = 6016,46; antenna −30000 × 61/92 = −19891,30; somma −13874,84 → −13875
    // (dopo la divisione, Carlo −13875).
    $s = b51aCoppiaACredito($this);
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-10-01');
    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'));

    expect(b51aFermate($a))->toBe([['Ugo', -13875, ['Carlo']]])
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('O-252.7c — il coinquilino che resta con la coppia a credito: i giorni dopo l\'uscita di Ines (€ 59,26 di credito) restano nelle sue rate, detti con le parole', function () {
    // Fonte: Ines e Dora al 50 %: spese 18000 × 184/365 = 9074; antenna 15000 (la sua metà, tutta dopo l'uscita) → 9074 − 15000 = −5926.
    // Misurato su TEST: fermata_coinquilino cifra −5926, «€ 59,26 di credito».
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01'));

    expect(collect($a['rate']['fermata_coinquilino'])->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['restano']])->all())->toBe([['Ines', -5926, ['Dora']]])
        ->and(str_contains(implode(' ', $a['rate']['frasi']), '€ 59,26 di credito'))->toBeTrue('La frase del coinquilino non dice € 59,26 di credito.')
        ->and(b51aFrasiConMenoEuro(['f' => b51aTestiDelleFermate($a)]))->toBe([], 'Il pannello dice la fermata con una cifra «€ -».');
});

it('sentinella — O-252.7c — il box con la coppia a credito: la fine di Ines con il box spuntato scrive due coppie, € 106,67 a credito sull\'unità e € 11,85 sul box', function () {
    // Fonte: antenna 30000 × 900/1000 = 27000 sull'appartamento e 3000 sul box; coppie: appartamento 32400 × 184/365 = 16333 − 27000 = −10667;
    // box 3600 × 184/365 = 1815 − 3000 = −1185 (misurato: Ugo −10667 e −1185, Ines l'opposto).
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    b51aCapitolo($s, 'Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $fine = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);

    $righe = DB::table('saldi')->where('anagrafica_id', $s['persone']['Ugo']->id)->whereIn('subentro_id', Subentro::where('id', $fine->id)->orWhere('subentro_padre_id', $fine->id)->pluck('id'))
        ->get(['immobile_id', 'saldo_iniziale'])->mapWithKeys(fn ($r) => [(int) $r->immobile_id => (int) $r->saldo_iniziale])->all();
    expect($righe)->toBe([(int) $s['unita']->id => -10667, (int) $s['box']->id => -1185]);
});
