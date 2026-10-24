<?php

/**
 * 1.11.0-beta.55 (Coda 354) — la nuda comprata il giorno dell'estinzione dell'usufrutto.
 *
 * Bruno è pieno proprietario della metà A, Ugo nudo proprietario della metà B e Zeno usufruttuario della B; la fine di Ines il 1/7 con «Scrivi» dà la coppia a chi sta al posto
 * dell'inquilino, Bruno 9074 e Zeno 9074. Ugo vende la nuda a Bruno il 1/10 (niente da fermare: il nudo non sta al posto dell'inquilino) e l'usufrutto di Zeno si estingue lo stesso
 * giorno: i giorni della B dal 1/10 sono di Bruno, pieno, e stanno nella riga della coppia di Zeno. La fermata dell'estinzione non nasceva, perché nella simulazione la nuda di Bruno
 * tornava piena solo se la sua riga era in corso il giorno prima, e la sua comincia il giorno del passaggio. Con l'estinzione un mese dopo la fermata c'era (€ 30,08, a carico di Zeno).
 *
 * Fonte dell'atteso: il conto a mano. La coppia 18148 sui 184 giorni dal 1/7 al 31/12; i pesi sono quota × giorni su 100 × 184 = 18400. La fermata dell'estinzione del 1/10 è la
 * parte di Bruno dei 92 giorni della B: 18148 × 50 × 92 / 18400 = 4537, a carico di Zeno. La rivendita di Bruno a Carlo il 1/12 (tutto) dà a Carlo i 31 giorni di dicembre, A e B:
 * 18148 × 100 × 31 / 18400 = 3057,54 → 3058; metà dalla riga di Bruno, metà dalla riga di Zeno: 1528,77 e 1528,77 → 1529 e 1529.
 *
 * Che cosa NON copre: l'estinzione con più nudi o con l'accrescimento (`EstinzioneConPiuUsufruttiTest`); MySQL.
 */

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Bruno pieno della A, Ugo nudo della B, Zeno usufruttuario della B; Ugo vende la nuda a Bruno il 1/10, dalla rotta vera. */
function fne55Scenario($test): array
{
    $s = fer55Base($test, [['Bruno', 'proprietario', 50], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
    expect(fds50Coppia($s, $s['fine']))->toBe(['Bruno' => 9074, 'Ines' => -18148, 'Zeno' => 9074]);
    $s['nuda'] = fer55Passo($test, $s, ['tipo' => 'vendita', 'tipologia' => 'nuda_proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'nuda_proprietario'),
        'anagrafica_entrante_id' => $s['persone']['Bruno']->id, 'decorrenza' => '2026-10-01', 'quota' => 50, 'copia_autentica' => false,
        'estremi_titolo' => 'rogito notaio Verdi, rep. 4321', 'pertinenze' => []]);

    return $s;
}

it('Coda 354 — la nuda comprata il giorno dell\'estinzione: l\'estinzione dell\'usufrutto di Zeno il 1/10 lascia a carico di Zeno € 45,37 dei giorni di Bruno, con la spunta e la strada del Wallet', function () {
    $s = fne55Scenario($this);
    $dati = ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Era: nessuna fermata, il cancello non chiesto, e i € 45,37 restavano nella riga di Zeno in silenzio.
    expect(fer55Proposte($pannello))->toBe([['persone' => ['Zeno'], 'cifra' => 4537, 'riceve' => ['Bruno' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    fds50DiceLaFermata($pannello, '€ 45,37', 'Zeno', 'Bruno');

    $est = fer55Passo($this, $s, $dati);
    expect(fer55Registro($est))->toBe([['persone' => ['Zeno'], 'cifra' => 4537, 'riceve' => ['Bruno' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
});

it('Coda 354 — poi Bruno vende tutto a Carlo il 1/12: dei € 30,58 di Carlo, € 15,29 sono della riga di Bruno, con il Wallet, e € 15,29 della riga di Zeno, a suo carico o di Bruno se la fermata dell\'estinzione è già stata regolata', function () {
    $s = fne55Scenario($this);
    $est = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);

    // La riga che Bruno vende (100 dal 1/10) ha la metà A sua e la metà B che l'estinzione gli ha lasciato nella riga di Zeno: i 3058 di Carlo si dividono per quota,
    // 1529 e 1529. Era: € 30,58 tutti a carico di Bruno, con il Wallet, e seguita la frase Carlo pagava a Bruno € 15,29 che stanno nella riga di Zeno.
    expect(fer55Proposte($pannello))->toBe([
        ['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata'],
        ['persone' => ['Zeno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno', 'dopo_la_fermata' => ['passaggio' => 'l\'estinzione dell\'usufrutto del 1 ottobre 2026', 'cifra' => 4537], 'wallet' => false, 'strada' => null],
    ])
        ->and(fds50Con($pannello, '€ 30,58'))->toBe([])
        ->and(fer55Frase($pannello, 'restano a carico di Zeno'))->toContain('€ 15,29 restano a carico di Zeno, o di Bruno se la fermata dell\'estinzione dell\'usufrutto del 1 ottobre 2026 è già stata regolata');
    fds50DiceLaFermata($pannello, '€ 15,29', 'Bruno', 'Carlo');

    fer55Passo($this, $s, $dati);
    expect(fer55Storico($s, $est))->toEndWith('; di questi, € 15,29 sono passati a Carlo con la vendita del 1 dicembre 2026.');
});

it('Coda 354 — la stessa rivendita con l\'estinzione il 1/11 (la fermata c\'era già, € 30,08): anche lì la parte della riga di Zeno non resta a carico di Bruno', function () {
    $s = fne55Scenario($this);
    $est = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-11-01'));
    expect(fer55Registro($est))->toBe([['persone' => ['Zeno'], 'cifra' => 3008, 'riceve' => ['Bruno' => 3008], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));

    // Conto a mano: Carlo 3057 (le righe di Bruno qui sono due, la A fino al 31/10 e la 100 dal 1/11: i resti cadono diversamente), metà e metà della riga di Bruno, a pari
    // resto il centesimo alla sua parte: 1529 a carico di Bruno, 1528 a carico di Zeno.
    expect(fer55Proposte($pannello))->toBe([
        ['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata'],
        ['persone' => ['Zeno'], 'cifra' => 1528, 'riceve' => ['Carlo' => 1528], 'uscente' => 'Bruno', 'dopo_la_fermata' => ['passaggio' => 'l\'estinzione dell\'usufrutto del 1 novembre 2026', 'cifra' => 3008], 'wallet' => false, 'strada' => null],
    ]);
});

it('Coda 354 — dopo la rivendita a Carlo, Luca entra il 15/12: la fermata della vendita scende a € 13,81, e i giorni di Luca li hanno pagati Bruno e Zeno, metà ciascuno', function () {
    $s = fne55Scenario($this);
    fclPersona($s, 'Luca');
    fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: con Luca dal 15/12 a Carlo restano 14 giorni, 18148 × 1400/18400 = 1380,83 → 1381; Luca 1700/18400, 1676,72 → 1677. La riga di Carlo è per metà dalla
    // riga di Bruno e per metà dalla riga di Zeno: i giorni di Luca li hanno pagati Bruno e Zeno, 838,5 ciascuno, a pari resto il centesimo a Bruno, che viene prima.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 3058, 'prima' => 3058, 'residuo' => 1381, 'parti' => ['Carlo' => 1381]]])
        ->and(fer55Frase($pannello, 'hanno lasciato a carico'))->toBe('')
        ->and(fer55Frase($pannello, 'ha lasciato a carico di Bruno e Zeno'))->toContain('La vendita del 1 dicembre 2026 ha lasciato a carico di Bruno e Zeno € 30,58 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo.')
        ->and(collect($pannello['rate']['giorni_del_conduttore'])->pluck('pagato_da')->all())->toBe([['Zeno', 'Bruno']]);

    fer55Passo($this, $s, $dati);
    $luca = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');
    expect(collect($luca['voci'])->map(fn ($v) => [(int) $v['importo'], $v['pagato_da']])->all())->toBe([[839, 'Bruno'], [838, 'Zeno']]);
});

it('Coda 354 — dopo la rivendita a Carlo, Carlo vende a Dora il 15/12: la parte di Dora resta a carico di Bruno e Zeno insieme, come le due fermate della vendita a Carlo', function () {
    $s = fne55Scenario($this);
    fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Carlo', 'Dora', '2026-12-15', 100));

    // Conto a mano: Dora dal 15/12, 18148 × 1700/18400 = 1676,72 → 1677. La vendita a Carlo ha due fermate, la parte della riga di Bruno e quella della riga di Zeno: per la
    // seconda vendita sono una fermata sola (€ 30,58), e la parte di Dora resta a carico di tutti e due insieme, o di Carlo se quella vendita è già stata regolata
    // (decisione 81.2: il programma non sa quale, e non indica il Wallet). Le persone nell'ordine delle loro righe della coppia, uguali: come vengono.
    $f = $pannello['rate']['fermate_dopo_scrivi'];
    expect(fer55Proposte($pannello))->toHaveCount(1)
        ->and([(int) $f[0]['cifra'], $f[0]['persone'] ?? null, $f[0]['uscente'] ?? null, $f[0]['dopo_la_fermata']['cifra'] ?? null, $f[0]['wallet']])->toBe([1677, ['Zeno', 'Bruno'], 'Carlo', 3058, false])
        ->and(fer55Frase($pannello, 'tocca a Dora'))->toContain('La vendita del 1 dicembre 2026 non ha spostato la parte di Carlo, € 30,58, rimasta a carico di Zeno e Bruno da regolare a mano.')
        ->and(fer55Frase($pannello, 'tocca a Dora'))->toContain('€ 16,77 restano a carico di Zeno e Bruno, o di Carlo se la fermata della vendita del 1 dicembre 2026 è già stata regolata');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 354 (la quota da una fermata a carico di più persone) — Elsa compra da Anna e da Carlo e rivende a Bruno, che ha una sua riga; Bruno vende a Dora: la parte della quota resta a carico di Anna e Carlo insieme', function () {
    // Anna 25 %, Carlo 25 %, Bruno 50 % (la coppia 18148: 4537, 4537, 9074). Anna vende a Elsa il 1/10 (25 × 92: 2268,5 → 2268, a pari resto ad Anna, che esce); Carlo vende
    // a Elsa il 15/10 (25 × 78: 1923,3 → 1923); Elsa vende tutto a Bruno il 1/11: 50 × 61, 3008,2 → 3008, a carico di Anna e Carlo insieme (decisione 86.1), o di Elsa. Bruno
    // ha ora una riga da 100, 50 sua e 50 dalla fermata della vendita di Elsa; vende a Dora il 1/12. I pesi sui 184 giorni sono Anna 2300, Carlo 25 × 106 = 2650, Elsa 25 × 14
    // + 50 × 17 = 1200, Bruno 50 × 123 + 100 × 30 = 9150, Dora 100 × 31 = 3100: 2268,50, 2613,71, 1183,57, 9024,68, 3057,54 → i tre centesimi a Carlo, Bruno ed Elsa, Dora 3057.
    // Metà e metà, 1528,5: a pari resto il centesimo alla parte di Bruno, che viene prima: 1529 a carico di Bruno, con il Wallet, e 1528 a carico di Anna e Carlo insieme, o di
    // Bruno se quella fermata è già stata regolata.
    $s = fer55Base($this, [['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25], ['Bruno', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Elsa', '2026-10-15', 25));
    $elsa = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));
    expect(array_map(fn ($f) => [$f['persone'] ?? [$f['persona']], (int) $f['cifra']], fds50Fermate($elsa)))->toBe([[['Anna', 'Carlo'], 3008]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 100));

    expect(array_map(fn ($f) => [$f['persone'] ?? [$f['persona']], (int) $f['cifra'], $f['uscente'] ?? null, (bool) $f['wallet']], $pannello['rate']['fermate_dopo_scrivi']))
        ->toBe([[['Bruno'], 1529, null, true], [['Anna', 'Carlo'], 1528, 'Bruno', false]])
        ->and(fer55Frase($pannello, 'tocca a Dora, ma il programma non la sposta: € 15,28'))->toContain('€ 15,28 restano a carico di Anna e Carlo, o di Bruno se la fermata della vendita del 1 novembre 2026 è già stata regolata');
    fer55SenzaNomiMancanti($pannello);
});
