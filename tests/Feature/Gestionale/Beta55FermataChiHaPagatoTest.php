<?php

/**
 * 1.11.0-beta.55, Coda 352 (decisione 112.1, la forma piena) — dopo una fermata, i giorni della quota che il passaggio non ha spostato li ha pagati chi è
 * rimasto a carico della fermata, o chi ha ricevuto se le parti l'hanno regolata.
 *
 * Il prospetto degli oneri accessori (i giorni dopo l'uscita con «Scrivi», chi li ha pagati) e il registro dell'inizio (`giorni_del_conduttore`, `pagato_da`)
 * davano a chi riceve tutti i giorni della sua riga, anche quelli della quota che gli è arrivata con un passaggio fermato: stanno nella riga di un altro, che
 * con la coppia li ha pagati. Ora una voce dopo una fermata si divide per chi l'ha pagata: la parte di chi riceve, sua, e quella della quota, «pagata da» chi è
 * rimasto a carico della fermata, con l'alternativa di chi l'ha ricevuta (e, se la fermata segue un'altra fermata, di chi la riceveva prima). La frase
 * dell'inizio, che legge il prospetto, nomina tutti e due. Dopo chi ricompra la sua stessa parte i giorni sono di nuovo suoi: la voce non dice più
 * l'alternativa di chi gliel'aveva comprata.
 *
 * Lo scenario è quello della fermata dopo «Scrivi» (`fer55Base`): Ines inquilina al 100 % esce il 1/7 con «Scrivi», la coppia 36000 × 184/365 = 18147,95 → 18148
 * sui 184 giorni dopo l'uscita, divisa fra chi sta al posto dell'inquilino per quota.
 *
 * I CONTI A MANO (centesimi; frazioni esatte, resti maggiori, a pari resto il primo: la parte di chi riceve viene prima della quota della fermata):
 * - I pezzi della coppia per giorni: 18148 × 92/184 = 9074 (1/7–30/9), × 31/184 = 3057,5 → 3058 (ottobre), × 61/184 = 6016,5 → 6016 (1/11–31/12; il centesimo a
 *   ottobre, che viene prima).
 * - L1a: Ugo 75, Anna 25; Anna vende a Elsa il 1/10 (la fermata a carico di Anna), Elsa vende a Ugo il 1/11 (a carico di Anna, o di Elsa se la prima è regolata).
 *   Luca entra il 15/12 (17 giorni): del pezzo da novembre, 6016 × 17/61 = 1676,59 → Luca 1677, senza conduttore 4339. La riga di Ugo da novembre ha la sua quota
 *   (75) e quella della fermata (25): Luca 1677 × 75/100 = 1257,75 → 1258 a Ugo, 1677 × 25/100 = 419,25 → 419 ad Anna (la fermata dice «€ 4,19 vanno restituiti a
 *   Ugo»); senza conduttore 4339 → 3254,25 → 3254 Ugo e 1084,75 → 1085 Anna (il residuo della fermata, € 10,85). In ottobre la riga di Elsa (25) le è arrivata con
 *   la prima fermata: 3058 → Ugo 2293,5 → 2294, Anna 764,5 → 764.
 * - L1e: Ugo 50 e Anna 50; Ugo vende la sua metà ad Anna il 1/10 (la fermata a carico di Ugo verso Anna). Pezzi 9074 (1/7–30/9) e 9074 (1/10–31/12). Luca dal 15/12:
 *   9074 × 17/92 = 1676,72 → 1677; Anna 838,5 → 839 (la sua), Ugo 838,5 → 838 (la fermata dice «€ 8,38 vanno restituiti ad Anna»).
 * - B5: dopo le due vendite di L1a, Luca dal 1/12 alla fine il 15/12 (14 giorni) e Marco dal 20/12 (12 giorni): del pezzo da novembre i conduttori 6016 × 26/61 =
 *   2564,20 → 2564, poi Luca 1400/2600 → 1380,62 → 1381 e Marco 1183,38 → 1183; Marco 1183 × 75/100 = 887,25 → 887 a Ugo e 295,75 → 296 ad Anna (la fermata
 *   dice «€ 2,96 vanno restituiti a Ugo»).
 * - L3: Ugo 100 vende a Elsa il 1/10 (la fermata a carico di Ugo) ed Elsa gli rivende il 1/11: da novembre i giorni sono di Ugo, anche se la fermata è regolata
 *   (la Coda 351: Ugo ne restituisce i giorni di ottobre). Luca dal 15/12: 6016 × 17/61 → 1677 «pagata da Ugo», senza l'alternativa di Elsa; ottobre, 3058, «pagata
 *   da Ugo, o da Elsa, se …».
 *
 * Che cosa NON copre: il prospetto sull'anello della regola del piano (`Beta55CoinquilinoProspettoTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Le voci di un conduttore del prospetto (o, con null, dei giorni senza conduttore), in breve: [pagata da, importo, giorni, l'alternativa], nell'ordine dei giorni. */
function fer55Pagate(array $s, ?string $nome): array
{
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $voci = $nome === null ? $p['senza_conduttore']['voci'] : (collect($p['conduttori'])->firstWhere('nome', $nome)['voci'] ?? []);

    return collect($voci)->where('modo', 'proprietario')->map(fn ($v) => [$v['pagato_da'], (int) $v['importo'], (int) $v['giorni'], $v['oppure'] ?? null])->values()->all();
}

/** Il testo dell'alternativa di una fermata, come lo scrive il prospetto. */
function fer55Alternativa(string $chi, string $vendita): string
{
    return sprintf('%s, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del %s non ha spostato', $chi, $vendita);
}

/** L1a: Anna vende a Elsa il 1/10, Elsa vende a Ugo il 1/11. */
function fer55DueVendite($test): array
{
    $s = fer55Base($test, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($test, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($test, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));

    return $s;
}

it('Coda 352 — L1a: dopo la seconda vendita a Ugo i giorni di Luca della quota comprata, € 4,19, li ha pagati Anna, o Elsa, o Ugo se le parti hanno regolato le fermate; i suoi € 12,58 Ugo', function () {
    $s = fer55DueVendite($this);
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);
    $catena = fer55Alternativa('Elsa', '01/10/2026') . '; ' . fer55Alternativa('Ugo', '01/11/2026');

    // Il pannello dell'inizio legge il prospetto: Luca rimborsa tutti e due.
    expect(implode(' ', $pannello['rate']['frasi']))->toContain('i giorni dopo la sua uscita li hanno presi con il conguaglio Ugo e Anna, e li rimborsa Luca a Ugo e Anna, con il prospetto degli oneri accessori')
        ->and(implode(' ', $pannello['rate']['frasi']))->not->toContain('li rimborsa Luca a Ugo,');
    $luca = fer55Passo($this, $s, $dati);
    expect(collect($luca->fresh()->registro['giorni_del_conduttore'])->map(fn ($g) => [(int) $g['cifra'], $g['pagato_da']])->all())->toBe([[1677, ['Ugo', 'Anna']]]);

    expect(fer55Pagate($s, 'Luca'))->toBe([['Ugo', 1258, 17, null], ['Anna', 419, 17, $catena]])
        ->and(collect(fer55Pagate($s, null))->sortBy(fn ($v) => sprintf('%03d|%s', 200 - $v[2], $v[0]))->values()->all())->toBe([
            ['Ugo e Anna', 9074, 92, null],
            // La parte di Ugo di ottobre (2294) e quella da novembre (3254), tutte e due sue senza alternativa, sono una voce sola: 5548 per 31 + 44 = 75 giorni.
            ['Ugo', 5548, 75, null],
            ['Anna', 1085, 44, $catena],
            ['Anna', 764, 31, fer55Alternativa('Elsa', '01/10/2026')],
        ]);
    expect(fclStampa($s))->toContain('pagata da Anna con il conguaglio della fine locazione del 01/07/2026, o da ' . $catena);
});

it('Coda 352 — L1e: Ugo vende la sua metà ad Anna; dei giorni di Luca € 8,38 stanno nella riga di Ugo, che li ha pagati, o Anna se le parti hanno regolato la fermata', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Anna', '2026-10-01', 50));
    $luca = fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    expect(collect($luca->fresh()->registro['giorni_del_conduttore'])->map(fn ($g) => [(int) $g['cifra'], $g['pagato_da']])->all())->toBe([[1677, ['Anna', 'Ugo']]])
        ->and(fer55Pagate($s, 'Luca'))->toBe([['Anna', 839, 17, null], ['Ugo', 838, 17, fer55Alternativa('Anna', '01/10/2026')]]);
});

it('Coda 352 — B5: dopo le due vendite, Luca dal 1/12 al 14/12 e Marco dal 20/12: dei giorni di Marco € 2,96 sono della quota comprata, pagati da Anna', function () {
    $s = fer55DueVendite($this);
    fclPersona($s, 'Marco');
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'));
    fer55Passo($this, $s, ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => fds50Riga($s, 'Luca', 'inquilino'), 'anagrafica_entrante_id' => null,
        'decorrenza' => '2026-12-15', 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [], 'conguaglio' => 'scrivi']);
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-20'));
    $catena = fer55Alternativa('Elsa', '01/10/2026') . '; ' . fer55Alternativa('Ugo', '01/11/2026');

    expect(fer55Pagate($s, 'Marco'))->toBe([['Ugo', 887, 12, null], ['Anna', 296, 12, $catena]])
        ->and(fer55Pagate($s, 'Luca'))->toBe([['Ugo', 1036, 14, null], ['Anna', 345, 14, $catena]]);
});

it('Coda 352 — L3, dopo la ricompera: Elsa rivende a Ugo il 1/11, e i giorni di Luca sono di Ugo, senza l\'alternativa di Elsa; ottobre la tiene', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    expect(fer55Pagate($s, 'Luca'))->toBe([['Ugo', 1677, 17, null]])
        ->and(collect(fer55Pagate($s, null))->sortBy(fn ($v) => 200 - $v[2])->values()->all())->toBe([
            // Le due voci senza conduttore pagate da Ugo senza alternativa (1/7–30/9 e 1/11–14/12) sono una sola: 9074 + 4339 = 13413, 92 + 44 = 136 giorni.
            ['Ugo', 13413, 136, null], ['Ugo', 3058, 31, fer55Alternativa('Elsa', '01/10/2026')],
        ]);
});

it('Coda 352 — R2: l\'unità mista; la metà piena di Ugo passa a Elsa, Ugo muore e la nuda va a Bruno, Elsa la rivende a Bruno e Bruno a Zeno: dei giorni di Luca € 8,38 li ha pagati Bruno, € 8,38 Zeno', function () {
    // Ugo pieno della metà A e nudo della metà B, Zeno usufruttuario della metà B: la coppia Ugo 9074, Zeno 9074. Dopo la rivendita di Bruno a Zeno (la fermata a carico
    // di Bruno, che ha la riga di Ugo come erede) Zeno ha la sua metà B e la metà A comprata. I pezzi dei giorni dopo l'uscita: 92 (1/7–30/9), 31 (ottobre, con la fermata
    // della vendita a Elsa, che la ricompera di Bruno, erede, chiude il 31/10), 30 (novembre) e 31 (dicembre): 18148 × 92, 31, 30, 31 / 184 = 9074, 3057,5, 2958,91,
    // 3057,5 → 9074, 3058, 2959, 3057 (i due centesimi a novembre, ,91, e a pari resto a ottobre, che viene prima). Luca dal 15/12: 3057 × 17/31 = 1676,45 → 1676; la
    // metà di Zeno 838, la metà A 838 a Bruno, o a Zeno se le parti hanno regolato la fermata.
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Zeno', '2026-12-01', 50));
    $luca = fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    expect(fer55Pagate($s, 'Luca'))->toBe([['Zeno', 838, 17, null], ['Bruno', 838, 17, fer55Alternativa('Zeno', '01/12/2026')]])
        ->and(collect($luca->fresh()->registro['giorni_del_conduttore'])->map(fn ($g) => [(int) $g['cifra'], $g['pagato_da']])->all())->toBe([[1677, ['Zeno', 'Bruno']]]);
});
