<?php

/**
 * 1.11.0-beta.55 (Coda 348) — le tre frasi del pannello delle fermate dell'erede, vere ma imprecise.
 *
 * (1) Con più acquisti, se uno è dal defunto di cui chi rivende è erede unico, la frase diceva «rimasta a carico di Bruno» per una parte che è già di Bruno: quella fermata
 *     è sua, non c'è niente da regolare, e la frase non la dice più (con una fermata di prima sola, è «la» fermata di prima, e l'alternativa e il motivo la nominano).
 * (2) Con due eredi la frase non diceva di chi era rimasta la fermata di prima, anche se il registro lo sa: ora dice «rimasta a carico di Bruno e Elsa».
 * (3) Il motivo del cancello diceva «come non erano passate a Bruno le parti che aveva comprato» anche per l'estinzione dell'usufrutto e per la parte passata con la
 *     successione, che non sono acquisti: ora nomina i passaggi che le fermate di prima hanno lasciato.
 *
 * Fonte dell'atteso: il conto a mano. Ugo 25 + 25 (due righe), Anna 25, Carlo 25 (la coppia 18148: Ugo 9074, Anna 4537, Carlo 4537). Ugo vende una riga a Bruno il 1/10
 * (18148 × 25 × 92/18400 = 2268,5, il centesimo a pari resto a chi esce: 2268), Anna vende a Bruno il 5/10 (25 × 88: 2169,87 → 2170), Ugo muore il 15/10 (l'altra riga a
 * Bruno) e Bruno rivende il 75 % a Dora l'1/12: i pesi di Bruno sono 25 × 61 + 25 × 57 + 25 × 47 = 4125 e Dora 75 × 31 = 2325, e 18148 × 2325/18400 = 2293,16 → 2293. Con
 * Ugo 50 + 25 e Anna 25: la riga da 25 a Bruno (1/10, 2268), la riga da 50 ai due eredi a metà (13611 → 6806 e 6805), la rivendita del 50 % (1/12) 18148 × 1550/18400 = 1528,77 →
 * 1529. Con l'estinzione dell'usufrutto di Zeno l'1/11 dopo la successione della nuda: la fermata è 18148 × 50 × 61/18400 = 3008,23 → 3008, e la rivendita del 100 %
 * (1/12) 18148 × 3100/18400 = 3057,54 → 3057 (resti maggiori).
 *
 * Che cosa NON copre: lo storico della prima vendita, che con l'erede in mezzo non dice la parte passata (Beta54FermataEredeTest); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Ugo 25 + 25 (due righe), Anna 25, Carlo 25: Ugo vende una riga a Bruno (1/10), Anna vende a Bruno (5/10), Ugo muore il 15/10 (l'altra riga ai suoi eredi). */
function fer55PiuAcquisti($test, array $eredi): array
{
    $s = fer55Base($test, [['Ugo', 'proprietario', 25], ['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25], ['Carlo', 'proprietario', 25]]);
    $s['p1'] = fer55Passo($test, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 25));
    $s['p2'] = fer55Passo($test, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-05', 25));
    fer55Passo($test, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 25, $eredi));

    return $s;
}

it('Coda 348 (la parte che è già dell\'erede) — Bruno compra da Ugo e da Anna, Ugo muore (Bruno unico erede) e Bruno rivende: la frase dice la sola fermata di Anna, non «rimasta a carico di Bruno»', function () {
    $s = fer55PiuAcquisti($this, ['Bruno' => 25]);
    $dati = fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 75);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p4 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita di Ugo (2268, a carico di Ugo, cioè di Bruno che è il suo erede unico) è già sua: non c'è niente da regolare. Resta la vendita di Anna
    // (2170, a carico di Anna). La rivendita vale 2293, a carico di Bruno e Anna insieme, o di Bruno se la fermata della vendita di Anna è regolata.
    $fermata = [['persone' => ['Bruno', 'Anna'], 'cifra' => 2293, 'riceve' => ['Dora' => 2293], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 5 ottobre 2026', 'cifra' => 2170, 'persona' => 'Anna'], 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p4))->toBe($fermata);
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect($frase)->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'La vendita del 5 ottobre 2026 non ha spostato la parte di Bruno, € 21,70, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Dora, ma il programma non la sposta: € 22,93 restano a carico di Bruno e Anna, '
        . 'o di Bruno se la fermata della vendita del 5 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Dora, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 5 ottobre 2026, e il programma non lo sa.');
    expect($frase)->not->toContain('rimasta a carico di Bruno');

    // Il motivo del cancello nomina la stessa fermata, e lo storico la stessa alternativa.
    expect(fds50Testi($pannello))->toContain('€ 22,93 dei giorni dopo l\'uscita di Ines restano a carico di Bruno e Anna, o di Bruno se la fermata della vendita del 5 ottobre 2026 è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Dora con questo passaggio, come non era passato a Bruno con la vendita del 5 ottobre 2026');
    expect(fer55Storico($s, $p4))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 22,93 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Bruno e Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Dora, o fra Bruno e Dora se la fermata della vendita del 5 ottobre 2026 era già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 348 (due eredi) — Ugo vende una riga a Bruno e muore (l\'altra, di 50, a Bruno e a Elsa), Bruno rivende il suo 50 %: la frase dice la fermata di prima e che è rimasta a carico di Bruno e Elsa', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Elsa' => 25]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Ugo (13611) passa ai due eredi, 6806 e 6805. La vendita della riga da 25 ferma 2268 a carico di Ugo, cioè dei suoi eredi insieme. La rivendita del
    // 50 % vale 1529, a carico di Bruno e di Elsa insieme, o di Bruno se la fermata della vendita è regolata.
    $fermata = [['persone' => ['Bruno', 'Elsa'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268, 'persona' => 'Bruno e Elsa'], 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 136,11 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 68,06, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Bruno e Elsa da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno e Elsa, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 348 (più fermate di prima, due eredi) — Bruno compra da Ugo e da Anna, Ugo muore (Bruno ed Elsa) e Bruno rivende: la frase dice di chi è rimasta ogni fermata, e il motivo nomina i due passaggi', function () {
    $s = fer55PiuAcquisti($this, ['Bruno' => 12.5, 'Elsa' => 12.5]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 62.5));

    // Conto a mano: la riga di Ugo nella coppia (9074) passa a Bruno e a Elsa a metà, 4537 ciascuno; la rivendita del 62,5 % vale 18148 × 62,5 × 31/18400 = 1910,96 → 1911. Le due
    // fermate di prima restano, perché con due eredi la vendita di Ugo (2268) non è più solo di Bruno: è rimasta a carico di Bruno e Elsa, e quella di Anna (2170) a carico di Anna.
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 22,68, rimasta a carico di Bruno e Elsa da regolare a mano. '
        . 'La vendita del 5 ottobre 2026 non ha spostato la parte di Bruno, € 21,70, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Dora, ma il programma non la sposta: € 19,11 restano a carico di Anna, Bruno e Elsa, '
        . 'o di Bruno se la parte di Bruno è già stata regolata.');
    expect(fds50Testi($pannello))->toContain('€ 19,11 dei giorni dopo l\'uscita di Ines restano a carico di Anna, Bruno e Elsa, o di Bruno se la parte di Bruno è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Dora con questo passaggio, come non era passato a Bruno con la vendita del 1 ottobre 2026 e con la vendita del 5 ottobre 2026');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 348 (l\'estinzione non è un acquisto) — Ugo vende la metà piena a Bruno, muore (la nuda a Bruno), l\'usufrutto di Zeno si estingue e Bruno rivende tutto: il motivo non dice «le parti che aveva comprato», e la frase dice la sola fermata di Zeno', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $estinzione = fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-11-01'));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p4 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita a Bruno (4537, a carico di Ugo) è già sua per la successione; l'estinzione ferma 3008 a carico di Zeno. La rivendita del 100 % (1/12) vale 3057, a carico
    // di Zeno e Bruno, o di Bruno se la fermata dell'estinzione è regolata. Non c'è niente che Bruno abbia «comprato» oltre la prima vendita, che è già sua.
    expect(fer55Registro($estinzione)[0]['cifra'])->toBe(3008);
    $fermata = [['persone' => ['Zeno', 'Bruno'], 'cifra' => 3057, 'riceve' => ['Carlo' => 3057], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'l\'estinzione dell\'usufrutto del 1 novembre 2026', 'cifra' => 3008, 'persona' => 'Zeno'], 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p4))->toBe($fermata);
    expect(fds50Testi($pannello))->toContain('€ 30,57 dei giorni dopo l\'uscita di Ines restano a carico di Zeno e Bruno, o di Bruno se la fermata dell\'estinzione dell\'usufrutto del 1 novembre 2026 è già stata regolata '
        . '(gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno con l\'estinzione dell\'usufrutto del 1 novembre 2026');
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'L\'estinzione dell\'usufrutto del 1 novembre 2026 non ha spostato la parte di Bruno, € 30,08, rimasta a carico di Zeno da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,57 restano a carico di Zeno e Bruno, '
        . 'o di Bruno se la fermata dell\'estinzione dell\'usufrutto del 1 novembre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata dell\'estinzione dell\'usufrutto del 1 novembre 2026, e il programma non lo sa.');
    foreach (fds50Testi($pannello) as $testo) {
        expect($testo)->not->toContain('le parti che aveva comprato');
    }
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 348 (la parte avuta con l\'estinzione, vista dalla successione) — Ugo vende la metà piena a Bruno, l\'usufrutto di Zeno si estingue, Ugo muore e Bruno rivende tutto: la frase dice la fermata di Zeno e non la vendita, che è già di Bruno', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Bruno', '2026-10-01', 50));
    fer55Passo($this, $s, ['quota' => 50] + fds50ModuloEstinzione($s, 'Zeno', '2026-10-05'));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));

    // Conto a mano: la vendita a Bruno ferma 4537 a carico di Ugo, ora di Bruno; l'estinzione ferma 4340 a carico di Zeno. La rivendita (1/12) vale 18148 × 3100/18400 = 3057,54 → 3058:
    // metà dalla riga di Ugo, ora di Bruno, e metà da quella di Zeno. La fermata dell'estinzione l'aveva ricevuta Ugo (cambiato nella 1.11.0-beta.55, dopo la correzione della
    // Coda 346: prima la frase diceva «la parte di Bruno, € 43,40»; ora la parte di Ugo, passata a Bruno con la successione).
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'L\'estinzione dell\'usufrutto del 5 ottobre 2026 non ha spostato la parte di Ugo, € 43,40, passata con la successione del 15 ottobre 2026 a Bruno, rimasta a carico di Zeno da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,58 restano a carico di Zeno e Bruno, '
        . 'o di Bruno se la fermata dell\'estinzione dell\'usufrutto del 5 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata dell\'estinzione dell\'usufrutto del 5 ottobre 2026, e il programma non lo sa.');
});
