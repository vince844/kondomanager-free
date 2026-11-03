<?php

/**
 * Cosa resta scoperto: la figura del sito e la prosa del sito, che mostrano la frase com'era; il testo della guida in-app, che è in
 * `GuideRegolaWallet.test.ts`; i testi di un passaggio che non sia una fine di conduttore (la guardia guarda due casi, DL7 con la vendita
 * e INT, non ogni testo del prodotto).
 *
 * Le tre frasi della fine di un conduttore che libera i giorni: «Con la fine della locazione di Luca i giorni dal 18 aprile 2026
 * tornano a chi paga al posto dell'inquilino».
 *
 * `dal` è il primo giorno libero: la locazione di Luca finisce il 17 aprile. «I suoi giorni dal 18 aprile» si legge come se da quel
 * giorno i giorni fossero ancora di Luca. Le frasi sono tre, generate da `FermataDopoScrivi` e lette dal pannello e dallo storico: la fermata
 * nuova, la parte che risale e lo storico della fine che l'ha fatta risalire. Il cancello (`motivoCancello`, `motivoRisale`) non porta la
 * data e non cambia: una controprova fissa i suoi motivi.
 *
 * Le cifre non cambiano (€ 268,20; da € 30,58 a € 61,15): cambiano solo le parole, quindi il registro non cambia e i passaggi già
 * registrati si rileggono con le frasi nuove.
 */

require_once __DIR__.'/Beta51PassaggiScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

it('la fermata della fine di Luca il 18/4: «i giorni dal 18 aprile 2026 tornano», non «i suoi giorni»', function () {
    $s = b51aDl7ConVendita($this);
    $a = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    $frase = collect($a['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'Con la fine della locazione di Luca'));

    expect($frase)->not->toBeNull();
    expect(str_contains($frase, 'Con la fine della locazione di Luca i giorni dal 18 aprile 2026 tornano a chi paga al posto dell\'inquilino'))
        ->toBeTrue('La frase dice: ' . $frase);
    expect(str_contains($frase, 'suoi giorni'))->toBeFalse('La frase dice ancora «suoi giorni»: ' . $frase);
});

it('la parte che risale (fine di Luca l\'1/12 dopo la vendita): «i giorni dal 1 dicembre 2026 tornano»', function () {
    $s = b51aInt($this);
    $a = fds50Anteprima($this, $s, b51aIntFine($s, '2026-12-01'));
    $frase = collect($a['rate']['frasi'])->first(fn (string $t) => str_contains($t, 'Con la fine della locazione di Luca'));

    expect($frase)->not->toBeNull();
    expect(str_contains($frase, 'Con la fine della locazione di Luca i giorni dal 1 dicembre 2026 tornano a chi paga al posto dell\'inquilino:'))
        ->toBeTrue('La frase dice: ' . $frase);
    expect(str_contains($frase, 'suoi giorni'))->toBeFalse('La frase dice ancora «suoi giorni»: ' . $frase);
});

it('lo storico della fine che ha fatto risalire la parte: l\'ordine delle parole è dritto e «i giorni di Luca dal» non c\'è più', function () {
    $s = b51aInt($this);
    $fine = fds50Registra($this, $s, b51aIntFine($s, '2026-12-01'))['subentro'];
    $frase = collect(fds50Testi(fds50Storico($s, $fine)))->first(fn (string $t) => str_contains($t, 'risale da'));

    expect($frase)->not->toBeNull();
    expect(str_contains($frase, 'Con questa fine la parte verso Elsa della fermata della vendita del 1 ottobre 2026 risale da € 30,58 a € 61,15 (gestione Ordinaria 2026, Interno 1): dal 1 dicembre 2026, finita la locazione di Luca, i giorni tornano a chi paga al posto dell\'inquilino, da regolare a mano fra Ugo e Elsa.'))
        ->toBeTrue('La frase dice: ' . $frase);
    expect(str_contains($frase, 'i giorni di Luca dal'))->toBeFalse('La frase dice ancora «i giorni di Luca dal»: ' . $frase);
});

it('guardia — nessun testo dell\'anteprima e dello storico di una fine di conduttore dice «i suoi giorni dal …» o «i giorni di <Nome> dal …»', function () {
    $s = b51aDl7ConVendita($this);
    $testi = [b51aTestoAnteprima(b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18'))];
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $testi[] = b51aTestoStorico($s, $fine);

    $t = b51aInt($this);
    $testi[] = b51aTestoAnteprima(fds50Anteprima($this, $t, b51aIntFine($t, '2026-12-01')));
    $testi[] = b51aTestoStorico($t, fds50Registra($this, $t, b51aIntFine($t, '2026-12-01'))['subentro']);

    foreach ($testi as $i => $testo) {
        expect(preg_match('/suoi giorni dal|giorni di [A-Z]\w+ dal/u', $testo, $m))->toBe(0, "Testo $i: «" . ($m[0] ?? '') . '» in ' . $testo);
    }
});

it('controprova — il cancello della fine di Luca resta com\'è: i motivi non portano la data né «i suoi giorni»', function () {
    $s = b51aDl7ConVendita($this);
    $dl7 = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18')['cancello'];

    expect($dl7['richiesto'])->toBeTrue()
        ->and($dl7['motivi'])->toBe(['€ 268,20 dei giorni dopo l\'uscita di Ines restano a carico di Ugo (gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 24 gennaio 2026 non passa a Elsa con la fine della locazione di Luca']);

    $t = b51aInt($this);
    $int = fds50Anteprima($this, $t, b51aIntFine($t, '2026-12-01'))['cancello'];

    expect($int['richiesto'])->toBeTrue()
        ->and($int['motivi'])->toBe(['la parte verso Elsa della fermata della vendita del 1 ottobre 2026 risale da € 30,58 a € 61,15 (gestione Ordinaria 2026): con la fine della locazione di Luca quei giorni tornano a chi paga al posto dell\'inquilino, e il conguaglio scritto alla fine locazione del 1 luglio 2026 non li sposta']);
});

it('controprova — le cifre della fine di Luca non cambiano: € 268,20 a carico di Ugo, da € 30,58 a € 61,15', function () {
    $s = b51aDl7ConVendita($this);
    $dl7 = b51aAnteprimaFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aFermate($dl7))->toBe([['Ugo', 26820, ['Elsa']]]);

    $t = b51aInt($this);
    $a = fds50Anteprima($this, $t, b51aIntFine($t, '2026-12-01'));
    $frase = implode(' ', $a['rate']['frasi']);

    expect(str_contains($frase, 'sale da € 30,58 a € 61,15 sulla gestione Ordinaria 2026 (Interno 1)'))->toBeTrue($frase);
});
