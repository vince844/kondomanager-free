<?php

/**
 * 1.11.0-beta.55 (Coda 345) — la rivendita di chi ha ereditato con l'arretrato a nome del defunto e «Non scriverlo»: il defunto è fra le persone a carico.
 *
 * Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). Anna vende a Bruno il 1/10 (€ 22,68 a carico di Anna). Ugo muore
 * il 15/10, eredi Bruno ed Elsa al 37,5 % ciascuno, con l'arretrato a nome del defunto e «Non scriverlo»: la sua riga della coppia resta a lui, e la successione non ha
 * fermata (la posizione del defunto resta com'è). Bruno rivende tutto (62,5 %) a Carlo il 1/12. La cifra viene da due origini: la parte ereditata, i cui giorni stanno nella
 * riga che è rimasta a Ugo, e la parte comprata da Anna, i cui giorni stanno nella riga di Anna. Prima la catena della riga di Bruno vedeva solo la vendita di Anna, e la
 * frase metteva anche i giorni della parte di Ugo a carico di Anna. Con «Scrivi» la successione ha la sua fermata, a carico di Ugo, e la frase nomina Ugo e Anna: ora la
 * successione senza fermata porta Ugo fra le persone a carico nello stesso modo, e le due frasi sono la stessa.
 *
 * Fonte dell'atteso: il conto a mano. Pesi sui 184 giorni della coppia: dopo la rivendita Ugo 75 × 106 (1/7–14/10), Anna 25 × 92 (fino al 30/9: la sua vendita), Bruno
 * 25 × 61 + 37,5 × 47 (le due parti fino al 30/11), Elsa 37,5 × 78, Carlo 62,5 × 31: 18148 × 1937,5/18400 = 1910,96 → 1911; per origine 18148 × 37,5 × 31/18400 = 1146,58 (la
 * parte ereditata) e 18148 × 25 × 31/18400 = 764,39 (quella comprata da Anna). L'erede che non ha comprato niente rivende il suo 37,5 %: 18148 × 37,5 × 31/18400 = 1146,58 → 1147.
 *
 * Il defunto conta come un'origine solo se ha una riga fra le persone a carico: Ugo che ha comprato da Anna con una fermata non ne ha una, i suoi giorni stanno nella riga di Anna, e la
 * fermata di prima resta l'unica origine (la frase nomina la vendita di Anna e la successione che ha passato la parte a Bruno). Dell'erede agli eredi che ha anche una successione senza
 * fermata fra le origini la frase e il motivo dicono quella successione e di chi è rimasta la parte avuta con lei.
 *
 * Che cosa NON copre: le successioni con l'arretrato agli eredi di fila (`Beta55FermataSuccessioniInCatenaTest.php`) e la fermata che il defunto aveva ricevuto con la sua riga
 * (`Beta55FermataRigaDelDefuntoTest.php`); la successione senza fermata nella frase di chi non è un erede agli eredi (il primo test: la frase nomina Ugo e non la successione); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/**
 * Ugo 75 % e Anna 25 %: Anna vende a Bruno il 1/10, Ugo muore il 15/10 (eredi Bruno ed Elsa al 37,5 %, arretrato a nome del defunto, `$conguaglio` la scelta), Bruno
 * rivende tutto a Carlo il 1/12. Restituisce lo scenario con la successione (`p2`), la rivendita (`p3`) e il pannello della rivendita.
 */
function fer55AnnaBrunoUgoMuore($test, string $conguaglio): array
{
    $s = fer55Base($test, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $s['p1'] = fer55Passo($test, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    $s['p2'] = fer55Passo($test, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5], Subentro::ARRETRATO_AL_DEFUNTO, $conguaglio));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 62.5);
    $s['pannello'] = fds50Anteprima($test, $s, $dati);
    $s['p3'] = fer55Passo($test, $s, $dati);

    return $s;
}

const FER55_RIVENDITA_DI_UGO_E_ANNA = 'La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo e Anna hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). '
    . 'La parte di Bruno non è passata con la coppia, ed è rimasta a carico di Ugo e Anna. '
    . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 19,11 restano a carico di Ugo e Anna, o di Bruno se la parte di Bruno è già stata regolata. '
    . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.';

it('Coda 345 (successione senza fermata) — Anna vende a Bruno, Ugo muore con l\'arretrato a nome del defunto e «Non scriverlo», Bruno rivende tutto: € 19,11 restano a carico di Ugo e Anna, o di Bruno', function () {
    $s = fer55AnnaBrunoUgoMuore($this, Subentro::NON_SCRIVERE_IL_CONGUAGLIO);

    // La successione non scrive niente: la posizione di Ugo resta com'è, e la sua riga della coppia (13611) resta a lui.
    expect($s['p2']->fresh()->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO)
        ->and(fer55Registro($s['p2']))->toBe([]);

    // Conto a mano: 1911 in tutto, 1146,58 dalla parte ereditata (la riga di Ugo) e 764,39 da quella comprata (la riga di Anna): a carico di Ugo e Anna insieme, o di
    // Bruno se la sua parte è già regolata. Il Wallet non si indica: il programma non sa quale parte stia in quale riga.
    $fermata = [['persone' => ['Ugo', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($s['pannello']))->toBe($fermata)
        ->and(fer55Registro($s['p3']))->toBe($fermata);
    expect(fer55Frase($s['pannello'], 'Con questo passaggio'))->toBe(FER55_RIVENDITA_DI_UGO_E_ANNA);
    expect(fds50Testi($s['pannello']))->toContain('€ 19,11 dei giorni dopo l\'uscita di Ines restano a carico di Ugo e Anna, o di Bruno se la parte di Bruno è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno');
    expect(fer55Storico($s, $s['p3']))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 19,11 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Ugo e Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo, o fra Bruno e Carlo se la parte di Bruno era già stata regolata.');
    fer55SenzaNomiMancanti($s['pannello']);
});

it('Coda 345 (parità con «Scrivi») — la rivendita dell\'erede dice la stessa frase quando la successione ha la sua fermata, a carico di Ugo', function () {
    $s = fer55AnnaBrunoUgoMuore($this, Subentro::SCRIVI_IL_CONGUAGLIO);

    // Conto a mano: con «Scrivi» la successione ferma i giorni dal 15/10 degli eredi, a carico di Ugo: 18148 × 75 × 78/18400 = 5769,88 → 5770 (2885 a Bruno e 2885 a Elsa). La
    // rivendita di Bruno ha le stesse due origini di prima, e la stessa cifra (1911).
    expect(fer55Registro($s['p2']))->toHaveCount(1)
        ->and(fer55Registro($s['p2'])[0]['persone'])->toBe(['Ugo'])
        ->and(fer55Registro($s['p2'])[0]['cifra'])->toBe(5770)
        ->and(fer55Registro($s['p3']))->toBe([['persone' => ['Ugo', 'Anna'], 'cifra' => 1911, 'riceve' => ['Carlo' => 1911], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]]);
    expect(fer55Frase($s['pannello'], 'Con questo passaggio'))->toBe(FER55_RIVENDITA_DI_UGO_E_ANNA);
});

it('Coda 345 (l\'erede che non ha comprato) — Ugo muore con l\'arretrato a nome del defunto e «Non scriverlo», Bruno rivende il suo 37,5 %: € 11,47 restano a carico di Ugo, non di Anna', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 37.5);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: i giorni di Bruno stanno nella riga di Ugo (13611), che è rimasta a lui; la riga di Anna (4537) non c'entra. 18148 × 37,5 × 31/18400 = 1146,58 → 1147.
    $fermata = [['persone' => ['Ugo'], 'cifra' => 1147, 'riceve' => ['Carlo' => 1147], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 136,11 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Bruno non è passata con la coppia, ed è rimasta a carico di Ugo. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 11,47 restano a carico di Ugo, o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
    foreach (fds50Con($pannello, '11,47') as $testo) {
        expect($testo)->not->toContain('Anna');
    }
});

it('Coda 345 (la successione non è nella catena) — Ugo muore con «Non scriverlo» e lascia a Elsa, Bruno rivende la parte comprata da Anna: € 7,64 restano a carico di Anna, non di Ugo', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Elsa' => 37.5, 'Dora' => 37.5], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 25);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: Bruno non è erede di Ugo, e la riga che vende (25) l'ha comprata da Anna: 18148 × 25 × 31/18400 = 764,39 → 764, a carico di Anna, o di Bruno se la
    // fermata della vendita del 1/10 è regolata. La successione di Ugo non è nella catena di quella riga, e Ugo non c'entra.
    $fermata = [['persone' => ['Anna'], 'cifra' => 764, 'riceve' => ['Carlo' => 764], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268], 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    foreach (fds50Con($pannello, '7,64') as $testo) {
        expect($testo)->not->toContain('Ugo');
    }
});

it('Coda 345 (tre origini) — Anna vende a Bruno, Mario muore con «Non scriverlo» e Ugo con l\'arretrato agli eredi (tutti e due a Bruno), Bruno rivende tutto: € 30,57 restano a carico di Bruno, Anna e Mario', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Mario', 'proprietario', 10], ['Anna', 'proprietario', 40]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 40));
    $p2 = fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-05', 10, ['Bruno' => 10], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p4 = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Ugo 9074, Mario 1815, Anna 7259 (18148 × 50, 10 e 40 %). La vendita di Anna (1/10) ferma 3629 a carico di Anna; la successione di Mario non scrive niente
    // e la sua riga resta a lui; la riga di Ugo passa a Bruno. I pesi di Bruno alla rivendita sono 40 × 61 + 10 × 57 + 50 × 47 = 5360 e Carlo 100 × 31 = 3100: 18148 × 3100/18400 =
    // 3057,54 → 3057. La cifra è a carico di Bruno (la riga di Ugo), di Anna e di Mario insieme, o di Bruno se la parte di Bruno è già stata regolata.
    expect(fer55Registro($p1)[0]['cifra'])->toBe(3629)
        ->and(fer55Registro($p2))->toBe([]);
    $fermata = [['persone' => ['Bruno', 'Anna', 'Mario'], 'cifra' => 3057, 'riceve' => ['Carlo' => 3057], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p4))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Bruno, € 36,29, rimasta a carico di Anna da regolare a mano. '
        . 'La parte avuta con la successione del 5 ottobre 2026, senza il conguaglio scritto, è rimasta a carico di Mario. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,57 restano a carico di Bruno, Anna e Mario, '
        . 'o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 30,57 dei giorni dopo l\'uscita di Ines restano a carico di Bruno, Anna e Mario, o di Bruno se la parte di Bruno è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno con la vendita del 1 ottobre 2026 e con la successione del 5 ottobre 2026');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 345 (due successioni di fila) — Mario muore con «Non scriverlo» (Ugo eredita), Ugo muore con «Non scriverlo» (Bruno eredita) e Bruno rivende: € 15,29 restano a carico di Mario, nella cui riga stanno i giorni di tutti e tre', function () {
    $s = fer55Base($this, [['Mario', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-01', 50, ['Ugo' => 50], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Mario 9074 e Anna 9074. Mario muore il 1/10 e lascia a Ugo, che muore il 15/10 e lascia a Bruno; tutte e due le successioni non scrivono niente, e la
    // riga della coppia resta a Mario. I pesi alla rivendita (1/12) sono Mario 50 × 92, Anna 50 × 184, Ugo 50 × 14, Bruno 50 × 47 e Carlo 50 × 31: 18148 × 1550/18400 = 1528,77 → 1529,
    // tutto dalla riga di Mario. Anna, che ha la sua riga, non c'entra.
    $fermata = [['persone' => ['Mario'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Mario ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Bruno non è passata con la coppia, ed è rimasta a carico di Mario. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Mario, '
        . 'o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
});

it('Coda 345 (il defunto senza riga della coppia) — Anna vende a Ugo, Ugo muore con «Non scriverlo» (Bruno eredita) e Bruno rivende: la fermata di prima è una sola, e la frase nomina la vendita di Anna', function () {
    $s = fer55Base($this, [['Anna', 'proprietario', 50], ['Carlo', 'proprietario', 50]]);
    fclPersona($s, 'Ugo');
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Ugo', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Dora', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Anna 9074 e Carlo 9074. La vendita di Anna a Ugo (1/10) ferma 18148 × 50 × 92/18400 = 4537 a carico di Anna. Ugo non ha una riga della coppia: i suoi
    // giorni stanno nella riga di Anna, e la sua successione senza fermata non è un'origine in più. La rivendita di Bruno a Dora (1/12) vale 18148 × 50 × 31/18400 = 1528,77 → 1529,
    // dalla riga di Anna: la fermata di prima è una sola, la vendita del 1/10, ricevuta da Ugo e passata a Bruno con la successione.
    $fermata = [['persone' => ['Anna'], 'cifra' => 1529, 'riceve' => ['Dora' => 1529], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537, 'di' => 'Ugo', 'passata' => 'la successione del 15 ottobre 2026 a Bruno'], 'wallet' => false, 'strada' => null]];
    expect(fer55Registro($p1)[0]['cifra'])->toBe(4537)
        ->and(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Anna ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Ugo, € 45,37, passata con la successione del 15 ottobre 2026 a Bruno, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Dora, ma il programma non la sposta: € 15,29 restano a carico di Anna, '
        . 'o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Dora, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 15,29 dei giorni dopo l\'uscita di Ines restano a carico di Anna, o di Bruno se la fermata della vendita del 1 ottobre 2026 è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Dora con questo passaggio, come non era passato a Ugo con la vendita del 1 ottobre 2026');
    expect(fer55Storico($s, $p3))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 15,29 dei giorni dal 1 dicembre 2026 sono rimasti '
        . 'a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Dora, o fra Bruno e Dora se la fermata della vendita del 1 ottobre 2026 era già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 345 (l\'erede agli eredi con un\'origine senza fermata) — Ugo 50 e Mario 50: Mario muore con «Non scriverlo» e Ugo agli eredi, tutti e due a Bruno, che rivende tutto: la frase e il motivo dicono la successione di Mario', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Mario', 'proprietario', 50]]);
    fer55Passo($this, $s, fer55Successione($s, 'Mario', 'proprietario', '2026-10-05', 50, ['Bruno' => 50], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Ugo 9074 e Mario 9074. Mario muore il 5/10 e Ugo il 15/10, e Bruno li eredita tutti e due; la riga di Mario resta a lui (arretrato a nome del defunto,
    // niente fermata), quella di Ugo passa a Bruno. La rivendita a Carlo (1/12) vale 18148 × 3100/18400 = 3057,54 → 3058: metà dalla riga di Mario e metà da quella che ora è di Bruno.
    $fermata = [['persone' => ['Mario', 'Bruno'], 'cifra' => 3058, 'riceve' => ['Carlo' => 3058], 'uscente' => 'Bruno', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1), '
        . 'e con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'La parte avuta con la successione del 5 ottobre 2026, senza il conguaglio scritto, è rimasta a carico di Mario. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 30,58 restano a carico di Mario e Bruno, '
        . 'o di Bruno se la parte di Bruno è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Bruno, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 30,58 dei giorni dopo l\'uscita di Ines restano a carico di Mario e Bruno, o di Bruno se la parte di Bruno è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Bruno con la successione del 5 ottobre 2026');
    fer55SenzaNomiMancanti($pannello);
});
