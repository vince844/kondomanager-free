<?php

/**
 * 1.11.0-beta.51 — la parte che risale quando finisce un conduttore (caso INT, Coda 254).
 *
 * Il difetto, misurato su TEST (591b2c11): Ines esce il 1/7 con «Scrivi» (coppia 18148 a Ugo), Ugo vende a Elsa il 1/10 (fermata 9074
 * verso Elsa), Luca entra l'1/11 (la fermata si riduce: restano 3058 con Elsa), Luca finisce l'1/12. I giorni di Luca dopo la sua fine
 * sono di Elsa, e la parte della fermata di Elsa risale da € 30,58 a € 61,15: ma il registro della fine di Luca non dice niente (nessuna
 * fermata, nessuna salita), il pannello tace e lo storico non lo ricorda.
 *
 * LA REGOLA: per ogni fermata di prima che nomina la persona, la salita è il residuo con le righe dopo la fine meno
 * il residuo con le righe prima; la fermata NUOVA della fine è solo ciò che resta: max(0, variazione della parte − salita). Qui la
 * variazione è tutta salita (3057 − 3057 = 0): nessuna fermata nuova, ma il pannello, il cancello (spunta e nota), il registro
 * (`fermata_risale`) e lo storico dicono «da € 30,58 a € 61,15».
 *
 * I CONTI A MANO (centesimi; coppia 36000 × 184/365 = 18147,95 → 18148, 1/7–31/12; regola dei resti maggiori, a pari resto chi esce per
 * primo):
 *   - vendita l'1/10: Ugo 92 giorni, Elsa 92 → 9074 e 9074; la fermata di Elsa è 9074;
 *   - Luca dall'1/11 (61 giorni, fine aperta): pesi [Ugo 92, Elsa 31, Luca 61] → 9074,00 / 3057,54 / 6016,46; interi 9074 + 3057 + 6016 =
 *     18147, il centesimo che avanza al resto più alto (Elsa ,54) → Elsa 3058, Luca 6016: la fermata si riduce a 3058;
 *   - fine di Luca l'1/12 (Luca fino al 30/11, 30 giorni): pesi [Ugo 92, Elsa 62, Luca 30] → 9074 / 6115,09 / 2958,91; interi 9074 + 6115 +
 *     2958 = 18147, il centesimo a Luca (,91) → Elsa 6115, Luca 2959. T = 6016 − 2959 = 3057 = la salita di Elsa (6115 − 3058). Fermata
 *     nuova per Elsa: 3057 − 3057 = 0;
 *   - Marco dal 15/12 (17 giorni): pesi [Ugo 92, Elsa 45, Luca 30, Marco 17] → Elsa 4438,37 → 4438 (45 giorni su 184: 31 di ottobre e 14 dall'1/12);
 *   - Marco dal 15/12 al 19/12 (5 giorni), fine il 20/12: Elsa 57 giorni → 5622 (18148 × 57/184 = 5622,13);
 *   - seconda vendita Elsa → Carlo il 15/12: pesi [Ugo 92, Elsa 45, Luca 30, Carlo 17] → Elsa 4438, Luca 2959, Carlo 1677 (1676,72).
 *
 * Che cosa NON copre: il cambio in due passi (fine di Luca e inizio di Marco lo stesso giorno, O-INT-DUEPASSI) — ha una domanda aperta
 * per Vincenzo, la spunta per «restano € 0,00»; il residuo con più fermate sulla stessa persona (domanda aperta); la stampa del PDF; MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

// --- O-INT: il pannello, il cancello, il registro e lo storico -------------------------------------------------------------------------------

it('O-INT — la fine di Luca l\'1/12: nessuna fermata nuova, ma la parte verso Elsa risale da € 30,58 a € 61,15, e il pannello e il cancello (con la spunta) lo dicono', function () {
    // Fonte: conto a mano in testa (3058 → 6115, salita 3057 = parte 3057: fermata nuova 0). Oggi: nessuna fermata, nessuna salita, nessuna
    // spunta (il prospetto dice già 61,15 «oppure Elsa»).
    $s = b51aInt($this);
    $a = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'));

    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
    $risale = $a['rate']['fermate_risalgono'] ?? null;
    expect($risale)->not->toBeNull('Il pannello non ha la chiave rate.fermate_risalgono (la parte che risale senza una fermata nuova).');
    expect(collect($risale)->map(fn ($r) => [(int) $r['prima'], (int) $r['dopo'], array_values((array) $r['verso']), $r['passaggio']])->all())
        ->toBe([[3058, 6115, ['Elsa'], 'la vendita del 1 ottobre 2026']]);

    $frasi = collect($a['rate']['frasi'])->filter(fn ($t) => str_contains($t, '30,58') && str_contains($t, '61,15'))->values();
    expect($frasi)->toHaveCount(1, 'Nessuna frase del pannello dice la parte che risale (€ 30,58 → € 61,15): ' . implode(' | ', $a['rate']['frasi']));
    expect(str_contains($frasi[0], 'Elsa') && str_contains($frasi[0], 'Luca'))->toBeTrue('La frase non nomina Elsa e Luca: ' . $frasi[0]);
    $motivi = collect($a['cancello']['motivi']);
    expect($a['cancello']['richiesto'])->toBeTrue('La salita non chiede la spunta.')
        ->and($motivi->contains(fn ($m) => str_contains($m, '€ 30,58') && str_contains($m, '€ 61,15')))->toBeTrue('Nessun motivo del cancello dice 30,58 e 61,15: ' . $motivi->implode(' | '));
});

it('O-INT — il motivo del cancello per la parte che risale nomina il conguaglio che l\'ha fermata, quello della fine di Ines (1 luglio 2026), non il giorno della fine di Luca (1 dicembre 2026)', function () {
    $s = b51aInt($this);
    $a = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'));

    $motivo = collect($a['cancello']['motivi'])->first(fn ($m) => str_contains($m, '€ 30,58') && str_contains($m, '€ 61,15'));
    expect($motivo)->not->toBeNull('Nessun motivo del cancello dice 30,58 e 61,15: ' . implode(' | ', $a['cancello']['motivi']))
        ->and($motivo)->toContain('il conguaglio scritto alla fine locazione del 1 luglio 2026 non li sposta')
        ->and($motivo)->not->toContain('del 1 dicembre 2026 non li sposta');
    // E nei dati della voce: la data della fine che ha scritto la coppia.
    expect($a['rate']['fermate_risalgono'][0]['fine_il'] ?? null)->toBe('2026-07-01');
});

it('O-INT — registrata la fine di Luca, il registro ha `fermata_risale` (vendita, prima 3058, dopo 6115, verso Elsa) e nessuna fermata nuova; lo storico dice da € 30,58 a € 61,15', function () {
    $s = b51aInt($this);
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];
    $registro = b51aRegistro($fine);

    expect($registro['fermata_dopo_scrivi'] ?? [])->toBe([]);
    expect($registro['fermata_risale'] ?? [])->toHaveCount(1);
    $r = $registro['fermata_risale'][0];
    // La lista bianca del registro: ogni chiave dichiarata deve esserci.
    foreach (['passaggio_id', 'passaggio', 'fine_id', 'prima', 'dopo', 'verso', 'conduttore_uscente'] as $chiave) {
        expect(array_key_exists($chiave, $r))->toBeTrue("La salita nel registro non ha la chiave «{$chiave}»: " . json_encode(array_keys($r)));
    }
    expect((int) $r['passaggio_id'])->toBe((int) $s['vendita']->id)
        ->and($r['passaggio'])->toBe('la vendita del 1 ottobre 2026')
        ->and((int) $r['fine_id'])->toBe((int) $s['fine_ines']->id)
        ->and([(int) $r['prima'], (int) $r['dopo']])->toBe([3058, 6115])
        ->and(array_values((array) $r['verso']))->toBe(['Elsa'])
        ->and($r['conduttore_uscente'])->toBe('Luca');

    $testo = b51aTestoStorico($s, $fine);
    expect(str_contains($testo, '30,58') && str_contains($testo, '61,15') && str_contains($testo, 'Elsa'))->toBeTrue('Lo storico della fine di Luca non dice la salita (€ 30,58 → € 61,15, Elsa): ' . $testo);
    $voce = fds50Storico($s, $fine);
    expect(collect($voce['fermate'] ?? [])->contains(fn ($t) => str_contains((string) $t, '30,58') && str_contains((string) $t, '61,15')))
        ->toBeTrue('Le fermate dello storico non dicono la salita: ' . json_encode($voce['fermate'] ?? []));
});

it('O-INT — senza «Ho letto» e la nota la fine di Luca non si registra: la salita è un motivo del cancello', function () {
    $s = b51aInt($this);
    $prima = Subentro::where('immobile_id', $s['unita']->id)->count();

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), b51aIntFine($s, '2026-12-01'))->assertSessionHasErrors('nota_cancello');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe($prima);
});

// --- Un conduttore dopo: il doppio conteggio -----------------------------------------------------------------------------------------------

it('sentinella — O-INT-MARCO: con Marco dal 15/12 dopo la fine di Luca un solo residuo della fermata della vendita (€ 44,38), non la somma di due fermate', function () {
    // Fonte: Elsa 45 giorni su 184 = 4438,37 → 4438. Se la fine di Luca scrivesse come nuova anche la parte che sale dentro la fermata della
    // vendita, fermateRidotte rileggerebbe le righe vive e conterebbe due fermate sugli stessi giorni. Oggi (senza salita) il residuo è già
    // 4438: la sentinella tiene fermo che la correzione non lo cambi.
    $s = b51aInt($this);
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-15'));
    expect(collect($a['rate']['fermate_ridotte'])->map(fn ($r) => [$r['passaggio'], (int) $r['residuo']])->all())->toBe([['la vendita del 1 ottobre 2026', 4438]]);
});

it('O-INT-MARCO-FINE — la fine di Marco il 20/12 (dopo Luca e dopo l\'inizio di Marco il 15/12): nessuna fermata nuova, la parte verso Elsa della vendita risale da € 44,38 a € 56,22', function () {
    // Fonte: conto a mano: prima (Marco dal 15/12 aperto) Elsa 45 giorni → 4438; dopo (Marco fino al 19/12, 5 giorni) Elsa 57 giorni
    // (31 + 14 + 12) → 18148 × 57/184 = 5622,13 → 5622. Salita 1184 = parte 5622 − 4438: fermata nuova 0 (prima {Ugo 9074,
    // Elsa 4438, Luca 2959, Marco 1677}, dopo {Ugo 9074, Elsa 5622, Luca 2959, Marco 493}).
    $s = b51aInt($this);
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-15'));

    $a = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-20', 'Marco'));
    expect($a['rate']['fermate_dopo_scrivi'])->toBe([]);
    $risale = $a['rate']['fermate_risalgono'] ?? null;
    expect($risale)->not->toBeNull('Il pannello non ha la chiave rate.fermate_risalgono.');
    expect(collect($risale)->map(fn ($r) => [(int) $r['prima'], (int) $r['dopo'], array_values((array) $r['verso'])])->all())->toBe([[4438, 5622, ['Elsa']]])
        ->and($a['cancello']['richiesto'])->toBeTrue();
});

it('sentinella — O-INT-V2: la seconda vendita (Elsa a Carlo il 15/12) dopo la fine di Luca ferma € 16,77 verso Carlo, e la frase dice la cifra della fermata registrata (€ 90,74), come oggi', function () {
    // Fonte: pesi [Ugo 92, Elsa 45, Luca 30, Carlo 17] con Elsa per prima → Elsa 4438, Luca 2959, Carlo 1677 (1676,72). Prima della vendita
    // Elsa aveva 6115: la parte passata a Carlo è 1677. La frase della decisione 81.2 dice la fermata di prima come è registrata, 9074.
    $s = b51aInt($this);
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));

    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-15'));
    expect(b51aFermate($a))->toBe([['Ugo', 1677, ['Carlo']]]);
    $f = $a['rate']['fermate_dopo_scrivi'][0];
    expect((int) ($f['dopo_la_fermata']['cifra'] ?? 0))->toBe(9074);
});

// --- L'annullamento e gli avvisi -------------------------------------------------------------------------------------------------------------

it('O-INT-ANNULLA — gli avvisi dell\'annullamento della fine di Luca dicono che la parte torna da € 61,15 a € 30,58; annullata, la salita non vale più nello storico', function () {
    $s = b51aInt($this);
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];

    $avvisi = fds50Storico($s, $fine)['annullabile']['avvisi'] ?? [];
    expect(collect($avvisi)->contains(fn ($t) => preg_match('/61,15.*30,58/u', (string) $t) === 1 && str_contains((string) $t, 'Elsa')))
        ->toBeTrue('Gli avvisi non dicono la parte che torna da € 61,15 a € 30,58 (Elsa): ' . implode(' | ', $avvisi));

    fclAnnulla($this, $s, $fine)->assertStatus(302);
    expect(fds50Storico($s, $fine)['fermate'] ?? [])->toBe([])
        // Annullata la fine, la riga di Luca si riapre e la fermata della vendita torna al residuo di prima (3058): la fermata ridotta
        // dell'inizio di Luca non è toccata.
        ->and(collect(b51aRegistro($s['inizio_luca'])['fermata_ridotta'] ?? [])->map(fn ($r) => (int) $r['residuo'])->all())->toBe([3058]);
});

it('O-INT-AVVISI — «Annulla il conguaglio» di Ines, con la fine di Luca registrata: gli avvisi nominano anche la salita della parte verso Elsa (da € 30,58 a € 61,15) e il Wallet da rivedere', function () {
    $s = b51aInt($this);
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));

    $avvisi = b51aAvvisiDelConguaglio($s['fine_ines']);
    $salita = collect($avvisi)->filter(fn ($t) => str_contains($t, '30,58') && str_contains($t, '61,15'))->values();
    expect($salita)->toHaveCount(1, 'Gli avvisi di «Annulla il conguaglio» non dicono la salita (€ 30,58 → € 61,15): ' . implode(' | ', $avvisi));
    expect(str_contains($salita[0], 'Elsa'))->toBeTrue('L\'avviso non nomina Elsa: ' . $salita[0]);
    // Il Wallet della fermata della vendita resta fra gli avvisi, com'era.
    expect(collect($avvisi)->contains(fn ($t) => str_contains($t, 'La vendita del 1 ottobre 2026') && str_contains($t, 'Wallet')))->toBeTrue('Manca l\'avviso della vendita con il Wallet: ' . implode(' | ', $avvisi));
});
