<?php

/**
 * 1.11.0-beta.55 (decisione 112.1) — quanto resta di una fermata dopo l'inizio di un conduttore si misura sui giorni della quota passata con il passaggio della
 * fermata, non sulla parte intera di chi riceve.
 *
 * Quando chi riceve ha anche una sua parte della coppia (Ugo che ricompra da Elsa la quota di Anna; Anna, Zeno o l'altro erede che comprano dall'erede; Anna che
 * compra la metà di Ugo), il conduttore che entra toglieva alla fermata la differenza della parte intera di chi riceve, anche dei giorni della sua riga, che con la
 * fermata non c'entrano: la fermata risultava azzerata, o ridotta troppo, e la frase mandava a correggere le righe legate a una cifra sbagliata. Ora chi riceve si
 * divide in due: la parte che aveva e la quota aperta dal passaggio della fermata (le righe del suo registro). La quota vale la parte della coppia dei suoi giorni,
 * al centesimo più vicino (a pari resto il centesimo non le va, come nella fermata, dove a pari resto va a chi esce); il resto della ripartizione non cambia, e il
 * conduttore toglie alla fermata la differenza della quota. Vale per l'inizio e per la fine di un conduttore (la parte che risale), e una fermata che non è più
 * azzerata per questa via torna a chiedere la spunta. Chi riceve senza una sua parte della coppia resta com'era.
 *
 * Fonte dell'atteso: il conto a mano. La coppia è 36000 × 184/365 = 18147,95 → 18148 sui 184 giorni dal 1/7 al 31/12; i pesi sono quota × giorni, il conduttore al
 * 100 % per i suoi giorni e chi paga al posto dell'inquilino per gli altri; cifre dei resti maggiori, a pari resto il centesimo a chi esce. Luca dal 15/12 ha 17
 * giorni: alla quota comprata da Ugo il 1/11 (25 × 61) ne toglie 25 × 17 = 419,18, e ne restano 25 × 44 = 1084,93.
 *
 * Che cosa NON copre: chi ha pagato i giorni del conduttore nel prospetto degli oneri accessori e nel registro dell'inizio (`pagato_da`): dalla Coda 352 per i giorni della
 * quota della fermata chi ne risponde (`Beta55FermataChiHaPagatoTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

it('Decisione 112.1 (il residuo sulla quota) — Anna vende a Elsa, Elsa vende a Ugo e Luca entra il 15/12: della fermata di € 15,04 restano € 10,85, non zero, e il cancello chiede la spunta', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata della seconda vendita è la quota di Elsa, 25 × 61 giorni, che sta nella riga di Anna: 18148 × 1525/18400 = 1504,11 → 1504. Ugo ha anche la
    // sua riga da 75. La quota senza Luca vale 1504; con Luca i suoi giorni sono 25 × 44, 18148 × 1100/18400 = 1084,93 → 1085. Luca toglie alla fermata 1504 − 1085 = 419, e
    // restano 1085: se chi ne risponde e Ugo hanno già regolato € 15,04, € 4,19 vanno restituiti a Ugo. Prima Luca toglieva la parte intera di Ugo, 15115 − 13438 = 1677
    // (pesi 15325 e 13625), più della fermata: zero, senza la spunta.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 1504, 'prima' => 1504, 'residuo' => 1085, 'parti' => ['Ugo' => 1085]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    expect(fer55Frase($pannello, 'Con questo inizio'))->toBe('La vendita del 1 novembre 2026 ha lasciato a carico di Anna, o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata, '
        . '€ 15,04 dei giorni dopo l\'uscita di Ines, da regolare a mano con Ugo. Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: '
        . 'resta da regolare € 10,85 con Ugo. Se chi ne risponde e Ugo hanno già regolato € 15,04, € 4,19 vanno restituiti a Ugo.');
    expect(fds50Testi($pannello))->toContain('della fermata della vendita del 1 novembre 2026 restano da regolare fra chi ne risponde (Anna, o Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata) '
        . 'e Ugo € 10,85, non più € 15,04: i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori');

    // Registrato, il registro dell'inizio conserva quanto resta.
    $luca = fer55Passo($this, $s, $dati);
    expect(array_map(fn (array $f) => [(int) $f['residuo'], (int) $f['prima']], $luca->fresh()->registro['fermata_ridotta'] ?? []))->toBe([[1085, 1504]]);
    fer55SenzaNomiMancanti($pannello);
});

it('Decisione 112.1 (il residuo sulla quota) — l\'erede vende ad Anna, che ha la sua riga, con le righe legate, e Luca entra il 15/12: restano € 5,18, e seguita la frase le righe legate sono € 5,18', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Anna', '2026-12-01', 37.5));
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1147, (int) $p2->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Anna', 1147, (int) $p2->id)))->toBe([]);
    $this->travel(1)->minutes();
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata è la quota di Bruno, 37,5 × 31 giorni: Anna passa da 4537 a 5684 (pesi 4600 e 4600 + 1162,5), 1147. La quota senza Luca vale 18148 × 1162,5/18400
    // = 1146,58 → 1147; con Luca i suoi giorni sono 37,5 × 14, 517,81 → 518. Restano 518, e se Bruno e Anna hanno già regolato la fermata ne vanno restituiti 1147 − 518 = 629.
    // Prima Luca toglieva la parte intera di Anna, 5684 − 4636 = 1048 (pesi 5762,5 e 4700): restavano 99.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 1147, 'prima' => 1147, 'residuo' => 518, 'parti' => ['Anna' => 518]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    $frase = fer55Frase($pannello, 'Con questo inizio');
    expect($frase)->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Bruno € 11,47 dei giorni dopo l\'uscita di Ines, da regolare a mano con Anna. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 5,18 con Anna. '
        . 'Se Bruno e Anna hanno già regolato € 11,47, € 6,29 vanno restituiti ad Anna. '
        . 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 11,47 a credito di Bruno e € 11,47 a debito di Anna; la parte che resta è € 5,18 a credito di Bruno e € 5,18 a debito di Anna. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.');

    // Seguita dalle rotte la strada che la frase dice, le righe legate sono la parte che resta.
    fer55Passo($this, $s, $dati);
    expect(sl53SeguiIFatti($this, $s, $p2, ['Bruno' => -518, 'Anna' => 518], $frase))->toBe([])
        ->and(sl53Legate($s, $p2))->toBe(['Anna' => 518, 'Bruno' => -518]);
});

it('Decisione 112.1 (il residuo sulla quota) — l\'erede che ricompra vende la metà piena a Zeno, che ha l\'usufrutto dell\'altra metà, e Luca entra il 15/12: restano € 6,90, e seguita la frase le righe legate sono € 6,90', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));
    $p4 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Zeno', '2026-12-01', 50));
    sl53Posta($this, $s, 'Bruno', -1529, (int) $p4->id);
    sl53Posta($this, $s, 'Zeno', 1529, (int) $p4->id);
    $this->travel(1)->minutes();
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata è la metà piena che Zeno compra il 1/12, 50 × 31 giorni: 1529. Zeno ha anche l'usufrutto della metà B (50 × 184). La quota senza Luca vale
    // 18148 × 1550/18400 = 1528,77 → 1529; con Luca i suoi giorni sono 50 × 14, 690,41 → 690. Restano 690, e se regolata ne vanno restituiti 839. Prima Luca toglieva la parte
    // intera di Zeno, 10603 − 8926 = 1677 (pesi 10750 e 9050): zero.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 1529, 'prima' => 1529, 'residuo' => 690, 'parti' => ['Zeno' => 690]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    $frase = fer55Frase($pannello, 'Con questo inizio');
    expect($frase)->toBe('La vendita del 1 dicembre 2026 ha lasciato a carico di Bruno € 15,29 dei giorni dopo l\'uscita di Ines, da regolare a mano con Zeno. '
        . 'Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: resta da regolare € 6,90 con Zeno. '
        . 'Se Bruno e Zeno hanno già regolato € 15,29, € 8,39 vanno restituiti a Zeno. '
        . 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 15,29 a credito di Bruno e € 15,29 a debito di Zeno; la parte che resta è € 6,90 a credito di Bruno e € 6,90 a debito di Zeno. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.');
    fer55Passo($this, $s, $dati);
    expect(sl53SeguiIFatti($this, $s, $p4, ['Bruno' => -690, 'Zeno' => 690], $frase))->toBe([])
        ->and(sl53Legate($s, $p4))->toBe(['Bruno' => -690, 'Zeno' => 690]);
});

it('Decisione 112.1 (il residuo sulla quota) — l\'erede vende all\'altro erede, che ha la sua parte ereditata, e Luca entra il 15/12: restano € 5,18, e seguita la frase le righe legate sono € 5,18', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Elsa', '2026-12-01', 37.5));
    sl53Posta($this, $s, 'Bruno', -1147, (int) $p2->id);
    sl53Posta($this, $s, 'Elsa', 1147, (int) $p2->id);
    $this->travel(1)->minutes();
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata è la quota di Bruno, 37,5 × 31 giorni: Elsa passa da 2885 a 4032, 1147. Elsa ha anche la sua parte ereditata (37,5 dal 15/10). La quota senza Luca
    // vale 18148 × 1162,5/18400 = 1146,58 → 1147; con Luca i suoi giorni sono 37,5 × 14, 517,81 → 518. Restano 518, se regolata ne vanno restituiti 629. Prima Luca toglieva la
    // parte intera di Elsa, 4032 − 2774 = 1258 (pesi 4087,5 e 2812,5): zero.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 1147, 'prima' => 1147, 'residuo' => 518, 'parti' => ['Elsa' => 518]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    $frase = fer55Frase($pannello, 'Con questo inizio');
    expect($frase)->toContain('resta da regolare € 5,18 con Elsa. Se Bruno e Elsa hanno già regolato € 11,47, € 6,29 vanno restituiti a Elsa.')
        ->and($frase)->toContain('la parte che resta è € 5,18 a credito di Bruno e € 5,18 a debito di Elsa.');
    fer55Passo($this, $s, $dati);
    expect(sl53SeguiIFatti($this, $s, $p2, ['Bruno' => -518, 'Elsa' => 518], $frase))->toBe([])
        ->and(sl53Legate($s, $p2))->toBe(['Bruno' => -518, 'Elsa' => 518]);
});

it('Decisione 112.1 (il residuo sulla quota) — Ugo vende la sua metà ad Anna, che ha l\'altra, con le righe legate, e Luca entra il 15/12: restano € 36,99, non € 28,60', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Anna', '2026-10-01', 50));
    expect(fer55Registro($p1))->toBe([['persone' => ['Ugo'], 'cifra' => 4537, 'riceve' => ['Anna' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    sl53Posta($this, $s, 'Ugo', -4537, (int) $p1->id);
    sl53Posta($this, $s, 'Anna', 4537, (int) $p1->id);
    $this->travel(1)->minutes();
    $dati = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $dati);

    // Conto a mano: la fermata è la metà di Ugo, 50 × 92 giorni: 4537. Anna ha anche la sua metà. La quota senza Luca vale 18148 × 4600/18400 = 4537; con Luca i suoi giorni sono
    // 50 × 75, 3698,64 → 3699. Restano 3699, e se regolata ne vanno restituiti 838 (50 × 17: 838,36). Prima Luca toglieva la parte intera di Anna, 13611 − 11934 = 1677 (pesi 13800
    // e 12100): restavano 2860.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'prima' => 4537, 'residuo' => 3699, 'parti' => ['Anna' => 3699]]]);
    $frase = fer55Frase($pannello, 'Con questo inizio');
    expect($frase)->toContain('resta da regolare € 36,99 con Anna. Se Ugo e Anna hanno già regolato € 45,37, € 8,38 vanno restituiti ad Anna.')
        ->and($frase)->toContain('la parte che resta è € 36,99 a credito di Ugo e € 36,99 a debito di Anna.');
    fer55Passo($this, $s, $dati);
    expect(sl53SeguiIFatti($this, $s, $p1, ['Ugo' => -3699, 'Anna' => 3699], $frase))->toBe([])
        ->and(sl53Legate($s, $p1))->toBe(['Anna' => 3699, 'Ugo' => -3699]);
});

it('Decisione 112.1 (il residuo sulla quota) — due eredi con una parte loro: Elsa vende a Bruno la riga che Ugo le aveva venduto, e Luca entra il 15/12: restano € 10,86, non € 6,66', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25]]);
    $r25 = (int) \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->where('quota', 25)->value('id');
    fer55Passo($this, $s, ['riga_uscente_id' => $r25] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Dora' => 25]));
    $p3 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 25));
    expect(fer55Registro($p3))->toBe([['persone' => ['Bruno', 'Dora'], 'cifra' => 1505, 'riceve' => ['Bruno' => 1505], 'uscente' => 'Elsa',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268], 'wallet' => false, 'strada' => null]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: la riga da 50 di Ugo passa a Bruno e Dora; la fermata della vendita di Elsa a Bruno è la quota di Elsa, 25 × 61 giorni: Bruno passa da 1923 a 3428, 1505. Bruno
    // ha anche la sua parte ereditata (25 dal 15/10). La quota senza Luca vale 18148 × 1525/18400 = 1504,11 → 1504; con Luca i suoi giorni sono 25 × 44, 1084,93 → 1085. Luca toglie
    // 1504 − 1085 = 419, e restano 1505 − 419 = 1086. Prima toglieva la parte intera di Bruno, 3428 − 2589 = 839 (pesi 3475 e 2625): restavano 666.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 1505, 'prima' => 1505, 'residuo' => 1086, 'parti' => ['Bruno' => 1086]]]);
    expect(fer55Frase($pannello, 'Con questo inizio'))->toBe('La vendita del 1 novembre 2026 ha lasciato a carico di Bruno e Dora, o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata, '
        . '€ 15,05 dei giorni dopo l\'uscita di Ines, da regolare a mano con Bruno. Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: '
        . 'resta da regolare € 10,86 con Bruno. Se chi ne risponde e Bruno hanno già regolato € 15,05, € 4,19 vanno restituiti a Bruno.');
    fer55SenzaNomiMancanti($pannello);
});

it('Decisione 112.1 (il residuo sulla quota) — la fine di Luca il 20/12 dopo la vendita dell\'erede ad Anna: la parte che risale va da € 5,18 a € 9,62, e seguita la frase le righe legate sono € 9,62', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Bruno', 'Anna', '2026-12-01', 37.5));
    sl53Posta($this, $s, 'Bruno', -1147, (int) $p2->id);
    sl53Posta($this, $s, 'Anna', 1147, (int) $p2->id);
    $this->travel(1)->minutes();
    fer55Passo($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));
    $pannello = fds50Anteprima($this, $s, fds50ModuloFine($s, Subentro::NON_SCRIVERE_IL_CONGUAGLIO, '2026-12-20', 'Luca'));

    // Conto a mano: con Luca dal 15/12 restano 518 (37,5 × 14 giorni). Con la sua fine il 20/12 i giorni dal 20/12 tornano, e i giorni della quota sono 37,5 × 26 (dal 1/12 al 14/12 e
    // dal 20/12 al 31/12): 18148 × 975/18400 = 961,63 → 962. La parte verso Anna risale da 518 a 962. Prima risaliva sulla parte intera di Anna, da 99 a 838.
    expect(fer55Risalgono($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'prima' => 518, 'dopo' => 962, 'verso' => ['Anna']]]);
    $frase = fer55Frase($pannello, 'Con la fine della locazione di Luca');
    expect($frase)->toBe('Con la fine della locazione di Luca i giorni dal 20 dicembre 2026 tornano a chi paga al posto dell\'inquilino: la parte verso Anna della fermata della vendita del 1 dicembre 2026 '
        . 'sale da € 5,18 a € 9,62 sulla gestione Ordinaria 2026 (Interno 1). La parte da regolare a mano fra Bruno e Anna è questa, e il programma non la sposta. '
        . 'I saldi del Wallet legati alla vendita del 1 dicembre 2026 sommano oggi € 11,47 a credito di Bruno e € 11,47 a debito di Anna; la parte nuova è € 9,62 a credito di Bruno e € 9,62 a debito di Anna. '
        . 'Il programma non li cambia: correggi dal Wallet i saldi che si modificano.');
    expect(sl53SeguiIFatti($this, $s, $p2, ['Bruno' => -962, 'Anna' => 962], $frase))->toBe([])
        ->and(sl53Legate($s, $p2))->toBe(['Anna' => 962, 'Bruno' => -962]);
});

it('Decisione 112.1 (il residuo sulla quota) — dopo la seconda vendita a Ugo, Luca dal 1/12 lascia € 7,40, e con la sua fine il 15/12 la parte verso Ugo sale da € 7,40 a € 11,59', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));
    $inizio = fds50ModuloInizio($s, 'Luca', '2026-12-01');
    $pannello = fds50Anteprima($this, $s, $inizio);

    // Conto a mano: Luca dal 1/12 ha 31 giorni, e i giorni della quota sono 25 × 30 (novembre): 18148 × 750/18400 = 739,73 → 740: restano 740. Con la fine di Luca il 15/12
    // Luca ha 14 giorni, e i giorni della quota sono 25 × 47 (novembre e dal 15/12 al 31/12): 18148 × 1175/18400 = 1158,91 → 1159. Prima, con la parte intera di Ugo: zero
    // all'inizio, e da zero a € 1,23 alla fine.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 1504, 'prima' => 1504, 'residuo' => 740, 'parti' => ['Ugo' => 740]]])
        ->and($pannello['cancello']['richiesto'])->toBeTrue();
    fer55Passo($this, $s, $inizio);
    $fine = fds50Anteprima($this, $s, fds50ModuloFine($s, null, '2026-12-15', 'Luca'));
    expect(fer55Risalgono($fine))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'prima' => 740, 'dopo' => 1159, 'verso' => ['Ugo']]])
        ->and(fer55Frase($fine, 'Con la fine della locazione di Luca'))->toContain('la parte verso Ugo della fermata della vendita del 1 novembre 2026 sale da € 7,40 a € 11,59 sulla gestione Ordinaria 2026 (Interno 1).');
});

it('Decisione 112.1 (il residuo sulla quota) — l\'estinzione dell\'usufrutto fa tornare pieno Ugo, che lo era già della metà A, e Luca entra il 15/12: restano € 36,99, non € 28,60', function () {
    $s = fer55Mista($this);
    $estinzione = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-01'));
    expect(fer55Registro($estinzione))->toBe([['persone' => ['Zeno'], 'cifra' => 4537, 'riceve' => ['Ugo' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: Ugo torna pieno della metà B dal 1/10 e ha una riga sola da 100 (la piena della A si chiude e si somma): la quota dell'estinzione è la metà B, 50 × 92 giorni,
    // 18148 × 4600/18400 = 4537, a carico di Zeno. La nuda che si chiude non è una parte di Ugo al posto dell'inquilino, e non si toglie. Con Luca la quota è 50 × 75 giorni,
    // 3698,64 → 3699: restano 3699. Prima Luca toglieva la parte intera di Ugo, 13611 − 11934 = 1677: restavano 2860.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'l\'estinzione dell\'usufrutto del 1 ottobre 2026', 'cifra' => 4537, 'prima' => 4537, 'residuo' => 3699, 'parti' => ['Ugo' => 3699]]])
        ->and(fer55Frase($pannello, 'Con questo inizio'))->toContain('resta da regolare € 36,99 con Ugo. Se Zeno e Ugo hanno già regolato € 45,37, € 8,38 vanno restituiti a Ugo.');
});

it('Decisione 112.1 (il residuo sulla quota) — Ugo riceve due quote, da Elsa il 1/11 e da Carlo il 1/12, e la seconda vendita chiude la sua riga per sommarla: con Luca dal 15/12 restano € 12,82 delle due fermate', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 20], ['Carlo', 'proprietario', 30]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 20));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 20));
    $p3 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Ugo', '2026-12-01', 30));
    expect(fer55Registro($p2)[0]['cifra'])->toBe(1203)
        ->and(fer55Registro($p3)[0]['cifra'])->toBe(918);
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: la coppia è Ugo 9074, Anna 3630, Carlo 5444. La vendita di Elsa a Ugo ferma la quota di Elsa, 20 × 61 giorni: Ugo passa da 9074 a 10277, 1203 (a carico di Anna). La
    // vendita di Carlo a Ugo ferma 30 × 31 giorni: Ugo passa da 10277 a 11195, 918 (a carico di Carlo); chiude la riga di Ugo da 70 e ne apre una da 100, che porta anche la quota di
    // prima. Le due fermate sono una voce sola (la stessa persona riceve): 2121. Senza Luca la quota è 20 × 61 + 30 × 31 = 2150, 2120,55 → 2121; con Luca 20 × 44 + 30 × 14 = 1300,
    // 1282,20 → 1282: Luca toglie 839, e restano 1282. Se la quota di prima non passasse alla riga nuova resterebbero 1618; con la parte intera di Ugo, 444.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 2121, 'prima' => 2121, 'residuo' => 1282, 'parti' => ['Ugo' => 1282]]])
        ->and(fer55Frase($pannello, 'Con questo inizio'))->toContain('resta da regolare € 12,82 con Ugo.');
});

it('Decisione 112.1 (il residuo sulla quota) — Anna e Carlo vendono a Ugo lo stesso giorno, e la seconda vendita somma la sua quota alla riga nata quel giorno: con Luca dal 15/12 restano € 6,90', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 20], ['Carlo', 'proprietario', 30]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-12-01', 20));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Ugo', '2026-12-01', 30));
    expect(collect($p2->fresh()->registro['righe'])->pluck('operazione')->all())->toContain('modificata')
        ->and(fer55Registro($p1)[0]['cifra'])->toBe(612)
        ->and(fer55Registro($p2)[0]['cifra'])->toBe(917);
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: la vendita di Anna (1/12) apre a Ugo una riga da 70 e ferma 20 × 31 giorni: Ugo da 9074 a 9686, 612. Quella di Carlo, lo stesso giorno, porta la stessa riga a 100
    // e ferma 30 × 31 giorni: Ugo da 9686 a 10603, 917. Insieme 1529. La quota è 50 × 31 giorni, 1528,77 → 1529; con Luca 50 × 14, 690,41 → 690: restano 690. Senza la quota sommata
    // lo stesso giorno resterebbero 1193; con la parte intera di Ugo, zero.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 dicembre 2026', 'cifra' => 1529, 'prima' => 1529, 'residuo' => 690, 'parti' => ['Ugo' => 690]]])
        ->and(fer55Frase($pannello, 'Con questo inizio'))->toContain('resta da regolare € 6,90 con Ugo.');
});

it('Decisione 112.1 (il residuo sulla quota, a pari resto) — Anna vende il suo 25 % a Ugo il 1/10 e Luca entra il 15/12: la quota vale 2268,5 e il centesimo non le va, come nella fermata: restano € 18,49', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 25));
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: dopo la vendita Ugo pesa 75 × 184 + 25 × 92 = 16100, 15879,5, e Anna 25 × 92, 2268,5: a pari resto il centesimo va ad Anna, che esce, e la fermata è
    // 15879 − 13611 = 2268. La quota di Ugo vale 25 × 92 giorni, 2268,5: a pari resto il centesimo non va alla quota, 2268, come la fermata. Con Luca la quota è 25 × 75 giorni,
    // 1849,32 → 1849: Luca toglie 419 (25 × 17 giorni: 419,18), e restano 1849. Con il centesimo alla quota resterebbero 1848.
    expect(fer55Registro($p1)[0]['cifra'])->toBe(2268)
        ->and(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268, 'prima' => 2268, 'residuo' => 1849, 'parti' => ['Ugo' => 1849]]])
        ->and(fer55Frase($pannello, 'Con questo inizio'))->toContain('resta da regolare € 18,49 con Ugo. Se Anna e Ugo hanno già regolato € 22,68, € 4,19 vanno restituiti a Ugo.');
});

it('Decisione 112.1 (guardia: chi riceve non ha una sua parte della coppia) — Ugo vende tutto a Elsa il 1/11 e Luca entra il 15/12: restano € 43,40, come prima', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01', 100));
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // Conto a mano: la fermata è 18148 × 6100/18400 = 6016,46 → 6016 (il centesimo a Ugo, 12131,54). Elsa ha solo la quota comprata: con Luca la sua parte è 18148 × 4400/18400 =
    // 4339,74 → 4340 (i due centesimi a Elsa e a Luca, i resti più grandi), e Luca le toglie 6016 − 4340 = 1676. Restano 4340, come con la regola di prima.
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 6016, 'prima' => 6016, 'residuo' => 4340, 'parti' => ['Elsa' => 4340]]])
        ->and(fer55Frase($pannello, 'Con questo inizio'))->toContain('resta da regolare € 43,40 con Elsa. Se Ugo e Elsa hanno già regolato € 60,16, € 16,76 vanno restituiti a Elsa.');
});
