<?php

/**
 * 1.11.0-beta.51 — le due precisazioni della decisione 86 sul residuo di una fermata: la 86.1 (più fermate sulla stessa persona) e la
 * 86.2 (il cambio d'inquilino in due passi).
 *
 * 86.1, O-2F. Ugo e Anna, comproprietari al 50 %, vendono a Elsa il 12/3 e l'1/4; poi Marco entra l'1/6. Le due vendite si fermano ciascuna sulla
 * parte di Elsa (fermate di € 153,33 e di € 142,94, in tutto € 296,27), e l'inizio di Marco toglie dalla parte di Elsa i giorni dal 1/6. Il difetto
 * della .50: `fermateRidotte` sottraeva da OGNI fermata tutto ciò che Marco toglie a Elsa, e ogni fermata si azzerava da sola: il pannello diceva
 * «non resta niente da regolare» con € 73,81 ancora da regolare. Il residuo vero è uno, per persona che riceve, e il pannello lo dice in una
 * frase che nomina le fermate.
 *
 * I CONTI A MANO (centesimi; il 2026 ha 365 giorni; coppia della fine di Ines il 24/1: 126475 × 30 % × 342/365 = 35551,60 → 35552, divisa fra
 * Ugo e Anna a metà; regola dei resti maggiori):
 *   - pesi dopo le due vendite (nessun conduttore): Ugo 0,5 × 47 giorni (24/1–11/3) = 23,5; Anna 0,5 × 67 (24/1–31/3) = 33,5; Elsa 0,5 × 295
 *     (12/3–31/12) + 0,5 × 275 (1/4–31/12) = 285 → su 35552 × p/342: Ugo 2443, Anna 3482, Elsa 29627;
 *   - le due fermate: dopo la vendita di Ugo Elsa ha 15333 (0,5 × 295 = 147,5 giorni), dopo quella di Anna 29627: la seconda è 29627 − 15333 = 14294;
 *   - Marco dall'1/6 (214 giorni): pesi Ugo 23,5, Anna 33,5, Elsa 0,5 × 81 (12/3–31/5) + 0,5 × 61 (1/4–31/5) = 71, Marco 214 → Marco 22246, Elsa 7381
 *     (7380,7), Ugo 2443, Anna 3482. Marco toglie a Elsa 29627 − 7381 = 22246, una volta sola: residuo 29627 − 22246 = 7381 (€ 73,81).
 *   La somma dei residui che dava la .50 (0 e 0) era la somma di due sottrazioni dello stesso 22246, ognuna fermata a zero.
 *
 * 86.2, O-DL7-DUEPASSI. Nel caso DL7 (fine di Luca il 18/4 che ferma € 268,20 verso Elsa a carico di Ugo) Marco entra lo stesso giorno, il 18/4,
 * come inizio a parte: riprende i 258 giorni, e la parte di Elsa torna a zero. La fine di Luca ha già chiesto la spunta per € 268,20; l'inizio di
 * Marco, che azzera la fermata, non ne chiede una seconda per «restano da regolare € 0,00, non più € 268,20»: lo dice come informazione (la
 * frase del pannello c'è, il motivo del cancello no).
 *
 * Che cosa NON copre: la fermata ridotta con un conduttore entrato fra le due fermate della stessa persona (la riduzione si applica alla somma
 * che c'è in quel momento: è la regola della funzione, provata solo dalla somma delle sue parti); il PDF; MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/**
 * Lo scenario di O-2F fino alle due vendite (12/3 e 1/4): Ugo e Anna al 50 %, la fine di Ines il 24/1 con «Scrivi» (coppia 35552 a metà), nessun
 * conduttore dopo. Restituisce lo scenario con le due vendite in `vendita_ugo` e `vendita_anna`.
 */
function b51aDueComproprietari($test): array
{
    $s = b51aDl7($test, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $s['vendita_ugo'] = b51aVendi($test, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $s['vendita_anna'] = b51aVendi($test, $s, 'Anna', 'Elsa', '2026-04-01', 50);

    return $s;
}

// --- 86.1: più fermate sulla stessa persona -----------------------------------------------------------------------------------------------------

it('O-2F — due vendite sulla parte di Elsa (€ 153,33 e € 142,94) e Marco dall\'1/6: il residuo è uno, € 73,81, non «niente da regolare»', function () {
    $s = b51aDueComproprietari($this);
    // Le due fermate, come sono registrate (conto a mano in testa): 15333 per la vendita di Ugo e 14294 per quella di Anna.
    expect(collect(fds50Fermate($s['vendita_ugo']))->map(fn ($f) => [$f['persona'], (int) $f['cifra']])->all())->toBe([['Ugo', 15333]])
        ->and(collect(fds50Fermate($s['vendita_anna']))->map(fn ($f) => [$f['persona'], (int) $f['cifra']])->all())->toBe([['Anna', 14294]]);

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $ridotte = $a['rate']['fermate_ridotte'];

    expect($ridotte)->toHaveCount(1, 'Il pannello dice più voci per la stessa persona: ' . json_encode($ridotte, JSON_UNESCAPED_UNICODE));
    $r = $ridotte[0];
    expect([(int) $r['cifra'], (int) $r['prima'], (int) $r['residuo']])->toBe([29627, 29627, 7381])
        ->and(collect($r['parti'])->map(fn ($p) => [$p['nome'], (int) $p['cifra']])->all())->toBe([['Elsa', 7381]])
        ->and($r['verso'])->toBe(['Elsa'])
        ->and((int) $r['passaggio_id'])->toBe((int) $s['vendita_ugo']->id)
        ->and(array_map('intval', $r['passaggi_ids'] ?? []))->toBe([(int) $s['vendita_ugo']->id, (int) $s['vendita_anna']->id]);

    // La frase è una sola, nomina le due vendite e dice il residuo vero; il motivo del cancello lo stesso.
    $frasi = collect($a['rate']['frasi'])->filter(fn ($t) => str_contains($t, 'resta da regolare') || str_contains($t, 'restano da regolare') || str_contains($t, 'non resta niente'))->values();
    expect($frasi)->toHaveCount(1, 'Le frasi sulla fermata ridotta non sono una sola: ' . implode(' | ', $a['rate']['frasi']));
    foreach (['12 marzo 2026', '1 aprile 2026', '€ 73,81', 'Elsa', '€ 296,27'] as $pezzo) {
        expect(str_contains($frasi[0], $pezzo))->toBeTrue("La frase non dice «{$pezzo}»: {$frasi[0]}");
    }
    expect(str_contains($frasi[0], 'non resta niente da regolare'))->toBeFalse('La frase dice ancora «non resta niente da regolare»: ' . $frasi[0]);
    // Il residuo è uno solo per Ugo e Anna insieme (la fermata ridotta non lo divide): la frase dice che la parte di ognuno la decide l'amministratore, e da
    // quale cifra partire (quella della fermata di ciascuno: 15333 e 14294).
    expect($frasi[0])->toContain(' Il residuo è uno solo per Ugo e Anna insieme: la parte di ognuno la decidi tu, partendo da quella della sua fermata (€ 153,33 per Ugo e € 142,94 per Anna).');
    // Dalla 1.11.0-beta.53 la frase dice i saldi da correggere solo se ci sono saldi legati alle fermate; qui non ce ne sono (le frasi con i
    // saldi legati sono in `Beta53FrasiStradaLegataTest`).
    expect($frasi[0])->not->toContain('hai registrato il saldo dal Wallet');
    $motivi = collect($a['cancello']['motivi'])->filter(fn ($m) => str_contains($m, 'fermata') || str_contains($m, 'fermate'))->values();
    expect($motivi)->toHaveCount(1)
        ->and(str_contains($motivi[0], '€ 73,81') && str_contains($motivi[0], '€ 296,27'))->toBeTrue('Il motivo non dice € 73,81 e € 296,27: ' . $motivi[0])
        ->and($a['cancello']['richiesto'])->toBeTrue();
});

it('O-2F — registrato l\'inizio di Marco, il registro ha una voce sola (`passaggio_id` il primo, `passaggi_ids` tutti e due, residuo 7381) e lo storico dice il residuo vero', function () {
    $s = b51aDueComproprietari($this);
    $marco = b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');
    $ridotta = b51aRegistro($marco)['fermata_ridotta'] ?? [];

    expect($ridotta)->toHaveCount(1);
    foreach (['passaggio_id', 'passaggi_ids', 'passaggio', 'fine_id', 'persona', 'cifra', 'prima', 'residuo', 'verso', 'parti', 'conduttore', 'dal'] as $chiave) {
        expect(array_key_exists($chiave, $ridotta[0]))->toBeTrue("Il registro dell'inizio di Marco non conserva la chiave «{$chiave}»: " . json_encode(array_keys($ridotta[0])));
    }
    expect([(int) $ridotta[0]['cifra'], (int) $ridotta[0]['prima'], (int) $ridotta[0]['residuo']])->toBe([29627, 29627, 7381])
        ->and(array_map('intval', $ridotta[0]['passaggi_ids']))->toBe([(int) $s['vendita_ugo']->id, (int) $s['vendita_anna']->id]);

    $testo = b51aTestoStorico($s, $marco);
    foreach (['12 marzo 2026', '1 aprile 2026', '73,81', 'Elsa'] as $pezzo) {
        expect(str_contains($testo, $pezzo))->toBeTrue("Lo storico dell'inizio di Marco non dice «{$pezzo}»: {$testo}");
    }
    expect(str_contains($testo, 'non resta niente'))->toBeFalse('Lo storico dice «non resta niente»: ' . $testo);
});

it('sentinella — O-2F con una fermata sola: la forma di prima non cambia (nessuna chiave `passaggi_ids`), il residuo è quello di oggi (Ugo vende, Anna resta; Marco dall\'1/6)', function () {
    // Fonte: solo la vendita di Ugo (fermata 15333). Dopo Marco: Elsa ha 0,5 × 81 = 40,5 giorni su 342 → 35552 × 40,5/342 = 4210,1 → 4210; Marco toglie
    // 15333 − 4210 = 11123, e il residuo è 15333 − 11123 = 4210. (Programma a parte: Ugo 2443, Anna 6653, Elsa 4210, Marco 22246.)
    $s = b51aDl7($this, ['titolari' => [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]], 'senzaLuca' => true]);
    $vendita = b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12', 50);
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-06-01'));
    $r = $a['rate']['fermate_ridotte'][0] ?? null;

    expect($r)->not->toBeNull('Il pannello non dice la fermata ridotta.');
    expect(array_key_exists('a_carico', $r))->toBeFalse('Con una fermata sola compare `a_carico`: ' . json_encode(array_keys($r)))
        ->and(collect($a['rate']['frasi'])->contains(fn ($t) => str_contains($t, 'Il residuo è uno solo')))->toBeFalse();
    expect([(int) $r['cifra'], (int) $r['prima'], (int) $r['residuo']])->toBe([15333, 15333, 4210])
        ->and((int) $r['passaggio_id'])->toBe((int) $vendita->id)
        ->and(array_key_exists('passaggi_ids', $r))->toBeFalse('Con una fermata sola compare `passaggi_ids`: ' . json_encode(array_keys($r)))
        ->and($r['persona'])->toBe('Ugo');
});

// --- 86.2: il cambio d'inquilino in due passi ---------------------------------------------------------------------------------------------------

it('O-DL7-DUEPASSI — la fine di Luca il 18/4 ferma € 268,20, e l\'inizio di Marco lo stesso giorno lo azzera: lo dice come informazione, senza una seconda spunta per «€ 0,00»', function () {
    // Fonte: conto a mano di DL7 (26820 per Elsa). Marco dal 18/4 riprende i 258 giorni: pesi [Luca 69, Ugo 15, Marco 258], Elsa 0.
    $s = b51aDl7ConVendita($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    expect(collect(b51aRegistro($fine)['fermata_dopo_scrivi'] ?? [])->map(fn ($f) => (int) $f['cifra'])->all())->toBe([26820]);

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-04-18'));
    $r = $a['rate']['fermate_ridotte'][0] ?? null;
    expect($r)->not->toBeNull('Il pannello dell\'inizio di Marco non dice la fermata ridotta.');
    expect([(int) $r['prima'], (int) $r['residuo']])->toBe([26820, 0]);

    // L'informazione c'è: la frase dice che non resta niente da regolare e quanto era; il cancello non chiede una seconda spunta.
    $frasi = collect($a['rate']['frasi'])->filter(fn ($t) => str_contains($t, '268,20'))->values();
    expect($frasi)->toHaveCount(1, 'Nessuna frase del pannello dice la fermata azzerata: ' . implode(' | ', $a['rate']['frasi']))
        ->and(str_contains($frasi[0], 'non resta niente da regolare'))->toBeTrue('La frase non dice «non resta niente da regolare»: ' . $frasi[0])
        ->and(str_contains($frasi[0], 'fine della locazione di Luca'))->toBeTrue('La frase non dice la fine della locazione di Luca: ' . $frasi[0]);
    $motivi = $a['cancello']['motivi'];
    expect(collect($motivi)->contains(fn ($m) => str_contains($m, '€ 0,00') || str_contains($m, '268,20')))->toBeFalse('Il cancello chiede ancora la spunta per la fermata azzerata: ' . implode(' | ', $motivi))
        ->and($a['cancello']['richiesto'])->toBeFalse('Il cancello è richiesto per altro: ' . implode(' | ', $motivi));

    // Senza «Ho letto» l'inizio si registra, e il registro conserva comunque la fermata ridotta a zero (lo storico la ricorda).
    $marco = b51aRegistraInizio($this, $s, 'Marco', '2026-04-18');
    expect(collect(b51aRegistro($marco)['fermata_ridotta'] ?? [])->map(fn ($x) => [(int) $x['prima'], (int) $x['residuo']])->all())->toBe([[26820, 0]]);
    $storico = b51aTestoStorico($s, $marco);
    expect(str_contains($storico, '268,20') && str_contains($storico, 'non resta niente'))->toBeTrue('Lo storico dell\'inizio di Marco non ricorda la fermata azzerata: ' . $storico);
});

it('O-DL7-DUEPASSI — se Marco entra due giorni dopo (il 20/4) la fermata non si azzera (€ 2,08 restano a Elsa) e il motivo del cancello c\'è: la regola è «residuo zero», non «dopo la fine di Luca»', function () {
    // Fonte: pesi [Luca 69, Ugo 15, Elsa 2, Marco 256] su 35552 → Luca 7173, Ugo 1559, Elsa 208 (207,9), Marco 26612. Marco toglie a
    // Elsa 26820 − 208 = 26612 e restano 208 dei 26820: € 2,08, i due giorni 18 e 19 aprile.
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-04-20'));
    $r = $a['rate']['fermate_ridotte'][0] ?? null;
    expect($r)->not->toBeNull();
    expect([(int) $r['prima'], (int) $r['residuo']])->toBe([26820, 208])
        ->and(collect($a['cancello']['motivi'])->contains(fn ($m) => str_contains($m, '€ 2,08') && str_contains($m, '€ 268,20')))->toBeTrue('Il motivo del cancello non c\'è per una fermata che non si è azzerata: ' . implode(' | ', $a['cancello']['motivi']))
        ->and($a['cancello']['richiesto'])->toBeTrue();
});

it('O-DL7-DUEPASSI, il saldo del Wallet — la fine di Luca ha indicato di registrare dal Wallet € 268,20 (a credito di Ugo e a debito di Elsa), legati a lei: se l\'inizio di Marco lo stesso giorno azzera la fermata, la frase dice di correggere quei saldi legati, perché il programma non li cambia', function (string $il, string $quanto) {
    $s = b51aDl7ConVendita($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $fermata = collect(b51aRegistro($fine)['fermata_dopo_scrivi'] ?? [])->first();
    expect($fermata['wallet'] ?? null)->toBeTrue();

    // I saldi come li indica la fermata, legati alla fine di Luca dal modale del Wallet: Ugo a credito e Elsa a debito di € 268,20 (positivo = debito).
    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }
    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', $il));
    $frase = collect($a['rate']['frasi'])->first(fn ($t) => str_contains($t, 'fine della locazione di Luca') && str_contains($t, 'da regolare'));

    // La frase, in coda a quella di sempre: quel che l'inizio riduce o azzera ha un saldo registrato su indicazione del programma.
    expect($frase)->not->toBeNull('Nessuna frase del pannello dice la fermata ridotta: ' . implode(' | ', $a['rate']['frasi']))
        ->and($frase)->toContain($quanto)
        ->and($frase)->toContain('I saldi del Wallet legati alla fine della locazione di Luca del 18 aprile 2026 sommano oggi € 268,20 a credito di Ugo e € 268,20 a debito di Elsa; la parte che resta è ')
        ->and($frase)->toContain('Il programma non li cambia: correggi dal Wallet i saldi che si modificano.')
        ->and($frase)->not->toContain('correggilo: il programma non lo toglie');
})->with([
    'azzerata, lo stesso giorno (86.2, senza spunta)' => ['2026-04-18', 'non resta niente da regolare'],
    'ridotta, due giorni dopo (con la spunta)' => ['2026-04-20', 'resta da regolare € 2,08 con Elsa'],
]);

it('O-DL7-DUEPASSI, il saldo del Wallet — con un riporto di Ugo e nessun saldo legato alla fine di Luca la frase non parla di un saldo da correggere', function () {
    $s = b51aDl7ConVendita($this);
    // Un saldo già nel Wallet per Ugo (un riporto) prima della fine di Luca: dalla 1.11.0-beta.53 la fermata indica lo stesso il Wallet legato.
    b51aSaldoManuale($s, 'Ugo', 5000);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $fermata = collect(b51aRegistro($fine)['fermata_dopo_scrivi'] ?? [])->first();
    expect($fermata['wallet'] ?? null)->toBeTrue();

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-04-18'));
    $frase = collect($a['rate']['frasi'])->first(fn ($t) => str_contains($t, 'fine della locazione di Luca') && str_contains($t, 'da regolare'));
    expect($frase)->not->toBeNull()->and($frase)->not->toContain('Wallet');
});

it('O-DL7-DUEPASSI, l\'annullamento — annullare l\'inizio di Marco che aveva azzerato la fermata avvisa che la parte verso Elsa torna da € 0,00 a € 268,20, e che un accordo fra le parti o un saldo del Wallet adeguati nel frattempo si rivedono', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $marco = b51aRegistraInizio($this, $s, 'Marco', '2026-04-18');
    expect(collect(b51aRegistro($marco)['fermata_ridotta'] ?? [])->map(fn ($x) => [(int) $x['prima'], (int) $x['residuo']])->all())->toBe([[26820, 0]]);

    // Gli avvisi che lo storico dice prima di confermare (l'inizio di Marco è l'ultimo passaggio, si annulla).
    $avvisi = fds50Storico($s, $marco)['annullabile']['avvisi'] ?? [];
    $avviso = collect($avvisi)->first(fn ($t) => str_contains($t, '268,20'));
    expect($avviso)->not->toBeNull('Gli avvisi dell\'annullamento dell\'inizio di Marco non nominano la fermata (€ 268,20): ' . implode(' | ', $avvisi))
        ->and($avviso)->toBe('Con questo inizio la parte verso Elsa della fermata della fine della locazione di Luca del 18 aprile 2026 è scesa da € 268,20 a € 0,00: annullandolo torna da € 0,00 a € 268,20. Se le parti hanno già regolato la differenza, o per questo hai registrato un saldo manuale dal Wallet, rivedilo: annullare il passaggio non lo toglie.');
});

it('sentinella — O-INT-DUEPASSI: nel caso INT la fine di Luca (1/12) e l\'inizio di Marco lo stesso giorno riportano la parte di Elsa da € 61,15 a € 30,57 (entro un centesimo da quella di prima): non è zero, e la spunta c\'è', function () {
    // Fonte: conto a mano di INT. Con Marco dall'1/12 (31 giorni) e Luca fino al 30/11 (30 giorni) i pesi sono [Ugo 92, Elsa 31, Luca 30, Marco 31] su 18148:
    // Ugo 9074,00, Elsa 3057,54, Luca 2958,91, Marco 3057,54; interi 9074 + 3057 + 2958 + 3057 = 18146, due centesimi ai resti più alti: Luca ,91 e, a pari
    // resto (,54) fra Elsa e Marco, chi viene prima nei pesi (i conduttori stanno prima dei proprietari) → Marco. Elsa 3057, non 3058: la parte di prima della
    // fine di Luca (3058) meno un centesimo di resto che si sposta. Dopo la fine di Luca (registrata) la parte di Elsa è 6115; non è zero e il motivo resta.
    $s = b51aInt($this);
    fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'));

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Marco', '2026-12-01'));
    $r = $a['rate']['fermate_ridotte'][0] ?? null;
    expect($r)->not->toBeNull();
    expect([(int) $r['prima'], (int) $r['residuo']])->toBe([6115, 3057])
        ->and(abs((int) $r['residuo'] - 3058))->toBeLessThanOrEqual(1)
        ->and($a['cancello']['richiesto'])->toBeTrue()
        ->and(collect($a['cancello']['motivi'])->contains(fn ($m) => str_contains($m, '€ 30,57') && str_contains($m, '€ 61,15')))->toBeTrue('Nessun motivo dice 30,57 e 61,15: ' . implode(' | ', $a['cancello']['motivi']));
});
