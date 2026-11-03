<?php

/**
 * 1.11.0-beta.55 (Coda 346) — la parte che il defunto aveva ricevuto con una fermata passa all'erede, e la rivendita dell'erede la vede.
 *
 * La catena della riga che chi rivende ha ereditato non finiva con la successione: la riga del defunto, nata da un altro passaggio (l'estinzione dell'usufrutto che
 * lo fa tornare proprietario pieno, o la vendita con cui ha comprato la quota di un altro), si chiude con la successione e passa all'erede, ma la catena seguiva solo
 * le righe di chi vende. La fermata di quel passaggio, che diceva al defunto la sua parte e a carico di chi, non c'era per l'erede: la frase metteva tutta la cifra a suo
 * carico, con il Wallet, anche i giorni che stavano nella riga di un'altra persona. Ora la catena segue anche la riga del defunto, qualunque sia la sorte dell'arretrato
 * (i giorni dopo il decesso sono dell'erede, anche se la riga della coppia è rimasta al defunto), e le fermate dove il defunto riceveva si leggono come dell'erede, a
 * carico di chi le aveva.
 *
 * Fonte dell'atteso: il conto a mano. Unità mista (Ugo proprietario pieno della metà A e nudo proprietario della metà B, Zeno usufruttuario della metà B; la coppia è
 * Ugo 9074 e Zeno 9074): la vendita della metà piena a Bruno (1/10) ferma 18148 × 50 × 92/18400 = 4537 a carico di Ugo; l'estinzione dell'usufrutto di Zeno (5/10) toglie a
 * Zeno 50 × 88 giorni e li dà a Ugo, la cui parte sale da 4537 a 18148 × 9000/18400 = 8876,74 → 8877: la fermata è 4340, a carico di Zeno. Ugo muore il 15/10 e la sua riga
 * piena della metà B passa a Bruno, che somma 50 + 50. La rivendita a Carlo di tutte e due le metà (1/12) vale 18148 × 3100/18400 = 3057,54 → 3058 (resti maggiori): 1528,77
 * dalla metà A (la riga di Ugo, ora di Bruno) e 1528,77 dalla metà B (la riga di Zeno). Con la vendita: Ugo e Anna al 50 %, Ugo compra la metà di Anna (1/10, 4537 a carico di
 * Anna), muore il 15/10 (unico erede Bruno) e Bruno rivende il 100 % a Carlo (1/12): 18148 × 3100/18400 = 3057,54, con i resti maggiori 3057, metà dalla riga di Ugo e metà da
 * quella di Anna. Con l'arretrato a nome del defunto: Ugo 50 %, Anna 25 % e Carlo 25 %, Ugo compra il 25 % di Anna (1/10, 2268) e muore (Bruno eredita il 75 %): la rivendita a
 * Dora (1/12) vale 18148 × 2325/18400 = 2293,16 → 2293, dalla riga di Ugo (il 50 %) e da quella di Anna (il 25 % comprato).
 *
 * Che cosa NON copre: le frasi dei passaggi di prima (quali fermate la frase nomina), che sono nei test della Coda 348; più successioni in catena; MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

it('Coda 346 (estinzione dell\'usufrutto) — Ugo vende la metà piena a Bruno, l\'usufrutto di Zeno si estingue, Ugo muore (la sua metà piena a Bruno) e Bruno rivende tutto a Carlo: € 30,58 restano a carico di Zeno e Bruno, senza il Wallet', function () {
    $s = fer55Mista($this);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 50));
    $estinzione = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-05'));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita a Bruno ferma 4537 a carico di Ugo; l'estinzione porta Ugo da 4537 a 8877 (18148 × 9000/18400 = 8876,74) e ferma 4340 a carico di Zeno, la cui riga
    // (9074) contiene ancora i giorni della metà B dal 5/10. Alla rivendita (1/12) 3058 sono 1529 della metà A, nella riga di Ugo che è di Bruno, e 1529 della metà B, nella riga di Zeno.
    expect(fer55Registro($p1)[0]['cifra'])->toBe(4537)
        ->and(fer55Registro($estinzione))->toBe([['persone' => ['Zeno'], 'cifra' => 4340, 'riceve' => ['Ugo' => 4340], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $proposte = fer55Proposte($pannello);
    expect($proposte)->toHaveCount(1)
        ->and($proposte[0]['persone'])->toBe(['Zeno', 'Bruno'])
        ->and($proposte[0]['cifra'])->toBe(3058)
        ->and($proposte[0]['riceve'])->toBe(['Carlo' => 3058])
        ->and($proposte[0]['wallet'])->toBeFalse()
        ->and($proposte[0]['strada'])->toBeNull();
    expect(fer55Registro($p3))->toBe($proposte);

    // La frase non manda a registrare dal Wallet una cifra che sta in parte nella riga di Zeno: dice a carico di chi resta, e che si regola fra le parti. La fermata
    // dell'estinzione l'aveva ricevuta Ugo il 5/10, e a Bruno è passata con la successione del 15/10: la frase lo dice (aggiunto nella 1.11.0-beta.55, dopo la correzione
    // della Coda 346: prima diceva «la parte di Bruno, € 43,40»).
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect($frase)->toContain('€ 30,58 restano a carico di Zeno e Bruno, o di Bruno se ')
        ->and($frase)->toContain('si regola fra le parti')
        ->and($frase)->not->toContain('registra dal Wallet')
        ->and($frase)->toContain('L\'estinzione dell\'usufrutto del 5 ottobre 2026 non ha spostato la parte di Ugo, € 43,40, passata con la successione del 15 ottobre 2026 a Bruno, '
            . 'rimasta a carico di Zeno da regolare a mano.');
    expect(fds50Con($pannello, '30,58'))->not->toBeEmpty();
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 346 (vendita) — Ugo compra la metà di Anna, muore (unico erede Bruno) e Bruno rivende tutto a Carlo: € 30,57 restano a carico di Anna e Bruno, senza il Wallet', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita di Anna a Ugo (1/10) ferma 9074 × 92/184 = 4537 a carico di Anna; la riga di Ugo (9074) e il suo credito verso Anna passano a Bruno con la
    // successione. La rivendita a Carlo (1/12) vale 18148 × 3100/18400 = 3057,54 → 3057 (i resti maggiori vanno a Ugo e a Bruno), metà dalla riga di Ugo, ora di Bruno, e metà
    // dalla riga di Anna, i cui giorni dal 1/10 la fermata di Anna a Ugo diceva a carico di Anna.
    // La fermata di prima l'aveva ricevuta Ugo, non Bruno: la frase dice la parte di Ugo e la successione che l'ha passata a Bruno (cambiato nella 1.11.0-beta.55, dopo la
    // correzione della Coda 346: prima diceva «la parte di Bruno, € 45,37», e il registro non sapeva chi aveva ricevuto; `di` e `passata` sono le chiavi nuove).
    expect(fer55Registro($p1))->toBe([['persone' => ['Anna'], 'cifra' => 4537, 'riceve' => ['Ugo' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $fermata = [['persone' => ['Anna', 'Bruno'], 'cifra' => 3057, 'riceve' => ['Carlo' => 3057], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'persona' => 'Anna', 'di' => 'Ugo', 'passata' => 'la successione del 15 ottobre 2026 a Bruno'],
        'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Ugo, € 45,37, passata con la successione del 15 ottobre 2026 a Bruno, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,57 restano a carico di Anna e Bruno, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    expect(fer55Storico($s, $p3))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 30,57 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Anna e Bruno sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo, o fra Bruno e Carlo se la fermata della vendita del 1 ottobre 2026 era già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 346 (guardia) — Ugo vende una riga a Elsa e muore: la rivendita di Bruno, che eredita l\'altra riga, è a carico di Bruno, con il Wallet, perché la vendita di Ugo non è nella catena di quella riga', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 60], ['Ugo', 'proprietario', 40]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-15', 40));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 60, ['Bruno' => 60]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 60);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Ugo (18148) passa a Bruno con la successione. Dopo la rivendita i pesi sono Ugo 60 × 106 + 40 × 76, Elsa 40 × 108, Bruno 60 × 47, Carlo 60 × 31:
    // 18148 × 1860/18400 = 1834,53 → 1835, tutto dalla riga che è di Bruno. La vendita di Ugo a Elsa (il 40 %) riguarda un'altra riga, e Ugo in quella non ha ricevuto niente.
    expect(fer55Registro($p3))->toBe([['persone' => ['Bruno'], 'cifra' => 1835, 'riceve' => ['Carlo' => 1835], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 18,35 restano a carico di Bruno. Se vanno a Carlo, registra dal Wallet i saldi legati a questo passaggio');
});

it('Coda 346 (arretrato a nome del defunto) — Anna vende a Ugo, Ugo muore con «Non scriverlo» (Bruno unico erede) e Bruno rivende tutto: € 22,93 restano a carico di Ugo e Anna, non di Carlo, perché la quota che Ugo aveva comprato sta nella riga di Anna', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 75);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Ugo 9074, Anna 4537, Carlo 4537. La vendita di Anna a Ugo (1/10) ferma 4537 × 92/184 = 2268,5 → 2268 a carico di Anna. Ugo muore e la sua riga resta a
    // lui (arretrato a nome del defunto); Bruno eredita il 75 %, il suo e quello comprato da Anna. La rivendita a Dora (1/12) vale 18148 × 2325/18400 = 2293,16 → 2293: i giorni del 50 %
    // di Ugo stanno nella sua riga, quelli del 25 % comprato nella riga di Anna. Carlo, che ha la sua riga, non c'entra.
    expect(fer55Registro($p1))->toBe([['persone' => ['Anna'], 'cifra' => 2268, 'riceve' => ['Ugo' => 2268], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $fermata = [['persone' => ['Ugo', 'Anna'], 'cifra' => 2293, 'riceve' => ['Dora' => 2293], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo e Anna hanno preso € 136,11 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Bruno non è passata con la coppia, ed è rimasta a carico di Ugo e Anna. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Dora, ma il programma non la sposta: € 22,93 restano a carico di Ugo e Anna, '
        . 'o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Dora, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
    foreach (fds50Con($pannello, '22,93') as $testo) {
        expect($testo)->not->toContain('Carlo');
    }
});

it('Coda 346 (la fermata che aveva ricevuto il defunto, due eredi) — Anna vende a Ugo, Ugo muore (Bruno e Dora) e Bruno rivende il suo 50 %: la frase dice la parte di Ugo, € 45,37, passata a Bruno e Dora, non «la parte di Bruno»', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 50, 'Dora' => 50]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita di Anna a Ugo (1/10) ferma 9074 × 92/184 = 4537 a carico di Anna, verso Ugo; con la successione la riga di Ugo (9074) passa a Bruno e Dora, 4537 e 4537,
    // e con lei la fermata che Ugo aveva ricevuto: a Bruno ne tocca metà. La rivendita di Bruno vale 18148 × 50 × 31/18400 = 1528,77 → 1529, metà dalla riga di Ugo (di Bruno) e metà
    // da quella di Anna. La fermata di prima era di Ugo, € 45,37 per intero: la frase dice la parte di Ugo e a chi è passata.
    $fermata = [['persone' => ['Anna', 'Bruno'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'persona' => 'Anna', 'di' => 'Ugo', 'passata' => 'la successione del 15 ottobre 2026 a Bruno e Dora'],
        'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect($frase)->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 45,37, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Ugo, € 45,37, passata con la successione del 15 ottobre 2026 a Bruno e Dora, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Anna e Bruno, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.')
        ->and($frase)->not->toContain('la parte di Bruno, € 45,37');
    expect(fds50Testi($pannello))->toContain('€ 15,29 dei giorni dopo l\'uscita di Ines restano a carico di Anna e Bruno, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata '
        . '(gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Ugo con la vendita del 1 ottobre 2026');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 346 (più fermate di prima, ricevute da persone diverse) — Anna vende a Ugo, Ugo muore (Bruno e Dora), Carlo vende a Bruno e Bruno rivende: ogni fermata dice di chi era la parte, e il motivo a chi non era passato con quale passaggio', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Dora' => 37.5]));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Carlo', 'Bruno', '2026-11-01', 25));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Elsa', '2026-12-01', 62.5));

    // Conto a mano: la vendita di Anna a Ugo (1/10) ferma 25 × 92 giorni, 2268,5: a pari resto il centesimo ad Anna, che esce, 2268, verso Ugo; la riga di Ugo (9074) passa a Bruno e Dora.
    // La vendita di Carlo a Bruno (1/11) ferma 25 × 61 giorni: Bruno da 2885 a 4389, 1504, verso Bruno. La rivendita di Bruno vale 62,5 × 31 giorni, 1910,96 → 1911.
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect(fer55Proposte($pannello)[0]['cifra'])->toBe(1911)
        ->and($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Ugo, € 22,68, passata con la successione del 15 ottobre 2026 a Bruno e Dora, rimasta a carico di Anna da regolare a mano. '
            . 'La vendita del 1 novembre 2026 non ha spostato la parte di Bruno, € 15,04, rimasta a carico di Carlo da regolare a mano.');
    expect(collect(fds50Testi($pannello))->first(fn (string $t) => str_starts_with($t, '€ 19,11 dei giorni dopo l\'uscita di Ines')))
        ->toEndWith('come non era passato a Ugo con la vendita del 1 ottobre 2026 e a Bruno con la vendita del 1 novembre 2026');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 346 (la fermata del defunto passata con due successioni) — Anna vende a Mario, Mario muore (Ugo erede), Ugo muore (Bruno erede) e Bruno rivende: la frase dice la parte di Mario e le due successioni', function () {
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Mario', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-05', 100, ['Ugo' => 100]));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 100, ['Bruno' => 100]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));

    // Conto a mano: la vendita di Anna a Mario (1/10) ferma 9074 × 92/184 = 4537 a carico di Anna, verso Mario; la riga di Mario passa a Ugo e poi a Bruno, con l'arretrato agli eredi.
    // La rivendita di Bruno vale 18148 × 3100/18400 = 3057,54 → 3058 (il centesimo a Carlo, ,54, dopo Bruno, ,63). La fermata della vendita l'aveva ricevuta Mario, e a Bruno è passata
    // con le due successioni: la frase dice la parte di Mario, € 45,37, e tutte e due.
    expect(fer55Proposte($pannello)[0]['cifra'])->toBe(3058)
        ->and(fer55Frase($pannello, 'Con questo passaggio'))->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Mario, € 45,37, passata con la successione del 5 ottobre 2026 a Ugo '
            . 'e con la successione del 15 ottobre 2026 a Bruno, rimasta a carico di Anna da regolare a mano.');
    fer55SenzaNomiMancanti($pannello);
});
