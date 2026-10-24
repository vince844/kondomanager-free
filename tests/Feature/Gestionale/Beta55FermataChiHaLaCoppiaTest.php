<?php

/**
 * 1.11.0-beta.55 (Coda 347) — chi ha la coppia, se compra una quota da chi non ce l'ha, fa nascere la fermata.
 *
 * Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). Anna vende a Elsa il 1/10 (la sua parte dei giorni dal 1/10,
 * € 22,68, resta a carico di Anna), poi Elsa vende a Ugo il 1/11. Ugo ha già una riga nella coppia, ma i giorni che compra stanno nella riga di Anna, non nella
 * sua. Prima chi aveva una riga della coppia non riceveva mai: la seconda vendita non diceva niente, e € 15,04 restavano ad Anna (o a Elsa) in silenzio. Ora
 * non riceve solo chi è l'unica persona a carico di quei giorni: se la fermata di prima è di Ugo stesso, i giorni stanno già nella sua riga e la fermata non
 * c'è (il proprietario unico che vende e ricompra); se è di Anna, o di più persone insieme, la parte che sta nella riga di un altro si dice.
 *
 * È la causa dei due limiti che l'erede che compra dopo l'eredità lasciava: con due eredi (la nuda di Ugo a Bruno e a Dora) la rivendita di Bruno nomina anche
 * Dora, e la parte venuta da una persona viva (Anna) resta a carico di Anna, non di Bruno. La condizione che dice «a carico dell'erede, con il Wallet» quando nella
 * catena della riga non c'è nessuna fermata di prima serve ancora per chi ricompra da chi aveva i giorni nella sua stessa riga (l'unico erede di Ugo che compra
 * da Elsa la metà piena che Ugo le aveva venduto): lì la fermata non nasce, perché quei giorni sono già suoi.
 *
 * Fonte dell'atteso: il conto a mano. Pesi sui 184 giorni della coppia (1/7–31/12), cifre dei resti maggiori, a pari resto il centesimo a chi esce. Il primo
 * caso: Elsa pesa 25 × 92 prima e 25 × 31 dopo; Ugo passa da 13800 a 13800 + 25 × 61 = 15325 su 18400: 18148 × 15325/18400 = 15115,11 → 15115, meno 13611 =
 * 1504 (€ 15,04). Se Ugo avesse comprato da sé stesso (il proprietario unico) la cifra sarebbe 6016, e non c'è.
 *
 * Che cosa NON copre: la rivendita di chi aveva la coppia e ha comprato (la fermata «a carico di chi esce», che non legge la catena della sua riga); MySQL.
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** La vendita del 1/10 di Anna a Elsa: € 22,68 dei giorni dal 1/10 restano a carico di Anna (4537 × 92/184 = 2268,5, il centesimo a pari resto a chi esce). */
const FER55_VENDITA_DI_ANNA = ['persone' => ['Anna'], 'cifra' => 2268, 'riceve' => ['Elsa' => 2268], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata'];

it('Coda 347 (chi ha la coppia compra da chi non ce l\'ha) — Anna vende a Elsa e Elsa vende a Ugo: € 15,04 restano a carico di Anna, o di Elsa se la fermata della vendita del 1 ottobre è già stata regolata', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: i 61 giorni dal 1/11 al 31/12 della quota di Elsa passano a Ugo, che li ha nella riga di Anna e non nella sua: 18148 × 25 × 61/18400 = 1504,11 → 1504.
    $fermata = [['persone' => ['Anna'], 'cifra' => 1504, 'riceve' => ['Ugo' => 1504], 'uscente' => 'Elsa',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268], 'wallet' => false, 'strada' => null]];
    expect(fer55Registro($p1))->toBe([FER55_VENDITA_DI_ANNA])
        ->and(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p2))->toBe($fermata)
        ->and($pannello['cancello']['richiesto'])->toBeTrue();

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Anna ha preso € 45,37 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Elsa, € 22,68, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Ugo, ma il programma non la sposta: € 15,04 restano a carico di Anna, '
        . 'o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Ugo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 15,04 dei giorni dopo l\'uscita di Ines restano a carico di Anna, o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Ugo con questo passaggio, come non era passato a Elsa con la vendita del 1 ottobre 2026');

    // Lo storico della seconda vendita dice la sua parte, quello della prima dice quanto ne è passato a Ugo.
    expect(fer55Storico($s, $p2))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 15,04 dei giorni dal 1 novembre 2026 sono rimasti '
        . 'a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Ugo, o fra Elsa e Ugo se la fermata della vendita del 1 ottobre 2026 era già stata regolata.');
    expect(fer55Storico($s, $p1))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 22,68 dei giorni dal 1 ottobre 2026 sono rimasti '
        . 'a carico di Anna sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Elsa; di questi, € 15,04 sono passati a Ugo con la vendita del 1 novembre 2026.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (il proprietario unico che ricompra) — Ugo vende a Elsa e la ricompra il 1/11: i giorni tornano nella riga che li conteneva, la seconda vendita non ha fermata', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 100]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 100));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: la prima vendita ferma 18148 × 92/184 = 9074 a carico di Ugo. Alla seconda i pesi di Ugo passano da 9200 a 9200 + 100 × 61 = 15300 su 18400:
    // 18148 × 15300/18400 = 15090,46 → 15090, meno 9074 = 6016 (€ 60,16). Ma Ugo è l'unica persona a carico dei giorni di Elsa: li ha già nella sua riga, e non c'è
    // niente da regolare con nessuno.
    expect(fer55Registro($p1))->toBe([['persone' => ['Ugo'], 'cifra' => 9074, 'riceve' => ['Elsa' => 9074], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']])
        ->and(fer55Proposte($pannello))->toBe([])
        ->and(fer55Registro($p2))->toBe([])
        ->and(fds50Con($pannello, 'restano a carico'))->toBe([]);
});

it('Coda 347 (due eredi che comprano) — Ugo vende la metà piena a Elsa, muore (la nuda a Bruno e Dora), Elsa vende a Bruno e Bruno rivende a Carlo: € 15,29 restano a carico di Bruno e Dora, non di Zeno', function () {
    $s = fer55Mista($this);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Dora' => 25]));
    $datiElsa = fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50);
    $pannelloElsa = fds50Anteprima($this, $s, $datiElsa);
    $p3 = fer55Passo($this, $s, $datiElsa);
    $datiBruno = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $datiBruno);
    $p4 = fer55Passo($this, $s, $datiBruno);

    // Conto a mano: la riga di Ugo (9074) passa ai due eredi a metà, 4537 e 4537. La vendita a Elsa (1/10) ferma 4537 a carico di Ugo, cioè dei suoi eredi. Quando
    // Elsa vende a Bruno (1/11), i suoi 50 × 61 giorni passano a Bruno, e stanno nella riga che era di Ugo, ora di Bruno e di Dora: 18148 × 3050/18400 = 3008,23 → 3008,
    // a carico dei due insieme. La rivendita di Bruno a Carlo (1/12) vale 18148 × 50 × 31/18400 = 1528,77 → 1529, anche lei dalla riga di Ugo. Zeno ha la sua riga
    // (l'usufrutto della metà B) e non c'entra con nessuna delle due.
    expect(fer55Registro($p1))->toBe([['persone' => ['Ugo'], 'cifra' => 4537, 'riceve' => ['Elsa' => 4537], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    $dallaVenditaDiUgo = ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 4537];
    expect(fer55Proposte($pannelloElsa))->toBe(fer55Registro($p3))
        ->and(fer55Registro($p3))->toBe([['persone' => ['Bruno', 'Dora'], 'cifra' => 3008, 'riceve' => ['Bruno' => 3008], 'uscente' => 'Elsa', 'dopo_la_fermata' => $dallaVenditaDiUgo, 'wallet' => false, 'strada' => null]]);
    $dallaVenditaDiElsa = ['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 3008];
    expect(fer55Proposte($pannello))->toBe(fer55Registro($p4))
        ->and(fer55Registro($p4))->toBe([['persone' => ['Bruno', 'Dora'], 'cifra' => 1529, 'riceve' => ['Carlo' => 1529], 'uscente' => 'Bruno', 'dopo_la_fermata' => $dallaVenditaDiElsa, 'wallet' => false, 'strada' => null]]);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Bruno e Dora hanno preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 novembre 2026 non ha spostato la parte di Bruno, € 30,08, rimasta a carico di Bruno e Dora da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,29 restano a carico di Bruno e Dora, '
        . 'o di Bruno se la fermata della vendita del 1 novembre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 novembre 2026, e il programma non lo sa.');
    // Elsa vende a Bruno, che è una delle due persone a carico: 1504 dei 3008 stanno già nella sua riga (la metà di quella di Ugo), e 1504 in quella di Dora. La frase dice la cifra per persona
    // (la rivendita di Bruno a Carlo, che non è fra le persone a carico, resta intera a carico dei due insieme).
    expect(fer55Frase($pannelloElsa, 'Con questo passaggio'))->toContain('€ 30,08 restano a carico di Bruno (€ 15,04) e Dora (€ 15,04), o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata.');
    foreach ([...fer55FrasiDellaCifra($pannello, fds50Storico($s, $p4), '15,29'), ...fer55FrasiDellaCifra($pannelloElsa, fds50Storico($s, $p3), '30,08')] as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    expect(fer55FrasiDellaCifra($pannello, fds50Storico($s, $p4), '15,29'))->not->toBeEmpty();
    fer55SenzaNomiMancanti($pannelloElsa);
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (la parte venuta da una persona viva) — Anna vende a Elsa, Ugo muore (la nuda a Bruno), Elsa vende a Bruno e Bruno rivende a Carlo la parte di Anna: € 7,64 restano a carico di Anna, o di Bruno, non di Zeno', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25], ['Ugo', 'nuda_proprietario', 50], ['Zeno', 'usufruttuario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Dora', '2026-09-15', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $datiElsa = fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 25);
    $pannelloElsa = fds50Anteprima($this, $s, $datiElsa);
    $p3 = fer55Passo($this, $s, $datiElsa);
    $datiBruno = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 25);
    $pannello = fds50Anteprima($this, $s, $datiBruno);
    $p4 = fer55Passo($this, $s, $datiBruno);

    // Conto a mano: la coppia è Ugo 4537 (la sua quota piena di 25), Anna 4537, Zeno 9074. La vendita di Anna a Elsa (1/10) ferma 4537 × 92/184 = 2268,5 → 2268 a carico di
    // Anna. Ugo muore e la sua riga passa a Bruno, che ha così una riga nella coppia, ma i giorni della quota che compra da Elsa (1/11) stanno nella riga di Anna: la
    // seconda vendita ferma 18148 × 25 × 61/18400 = 1504,11 → 1504 a carico di Anna, o di Elsa se la prima è regolata. La rivendita di Bruno (1/12) vale 18148 × 25 × 31/18400 =
    // 764,39 → 764, ancora dalla riga di Anna: la parte ereditata da Ugo è un'altra riga (la nuda), e per questa quota Bruno non è a carico.
    $dallaVenditaDiAnna = ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268];
    expect(fer55Proposte($pannelloElsa))->toBe(fer55Registro($p3))
        ->and(fer55Registro($p3))->toBe([['persone' => ['Anna'], 'cifra' => 1504, 'riceve' => ['Bruno' => 1504], 'uscente' => 'Elsa', 'dopo_la_fermata' => $dallaVenditaDiAnna, 'wallet' => false, 'strada' => null]]);
    expect(fer55Proposte($pannello))->toBe(fer55Registro($p4))
        ->and(fer55Registro($p4))->toBe([['persone' => ['Anna'], 'cifra' => 764, 'riceve' => ['Carlo' => 764], 'uscente' => 'Bruno',
            'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 1504], 'wallet' => false, 'strada' => null]]);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Anna ha preso € 45,37 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 novembre 2026 non ha spostato la parte di Bruno, € 15,04, rimasta a carico di Anna da regolare a mano. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Carlo, ma il programma non la sposta: € 7,64 restano a carico di Anna, '
        . 'o di Bruno se la fermata della vendita del 1 novembre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 novembre 2026, e il programma non lo sa.');
    foreach ([...fer55FrasiDellaCifra($pannello, fds50Storico($s, $p4), '7,64'), ...fer55FrasiDellaCifra($pannelloElsa, fds50Storico($s, $p3), '15,04')] as $testo) {
        expect($testo)->not->toContain('Zeno');
    }
    expect(fer55FrasiDellaCifra($pannello, fds50Storico($s, $p4), '7,64'))->not->toBeEmpty();
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (registro di prima senza gli id) — se la fermata di prima ha più persone senza i loro id, non si sa quale riga conteneva i giorni: chi ha la coppia non riceve, come prima', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    // La fermata della prima vendita come la scriveva una beta di prima della .53 con più persone a carico: i nomi, non i loro id.
    $registro = $p1->fresh()->registro;
    $registro['fermata_dopo_scrivi'][0]['piu_persone'] = true;
    $registro['fermata_dopo_scrivi'][0]['persone'] = ['Anna', 'Ugo'];
    unset($registro['fermata_dopo_scrivi'][0]['persone_ids']);
    $p1->forceFill(['registro' => $registro])->save();
    $this->travel(1)->minutes();

    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25));

    // Conto a mano: i 1504 di Ugo ci sarebbero (come nella prima prova), ma senza gli id delle persone non si sa chi li aveva nella riga: il programma non li inventa.
    // Coda 351: per la stessa ragione non dice che la fermata di prima si riduce perché i giorni tornano a Ugo: potrebbero stare nella riga di Anna.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fds50Con($pannello, 'restano a carico'))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([]);
    // Coda 353: al posto del silenzio c'è l'avviso che il programma non sa in quale riga stavano i giorni (qui il registro della prima vendita non dice le persone), con la cifra che sarebbe da
    // regolare se stessero in un'altra riga: 1504, e nessun motivo nel cancello (l'avviso è un'informazione).
    expect(fer55Frase($pannello, 'La parte di Elsa non viene da un passaggio registrato'))->toContain('fino a € 15,04 si regolano a mano con Ugo')
        ->and(collect($pannello['cancello']['motivi'])->filter(fn (string $m) => str_contains($m, 'passaggio registrato che dica'))->all())->toBe([]);
});

it('Coda 347 (l\'erede vende a chi ha la coppia) — Ugo muore (Bruno ed Elsa, arretrato agli eredi) e Bruno vende a Anna, che ha la sua riga: € 11,47 restano a carico di Bruno, con il Wallet', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 37.5, 'Elsa' => 37.5]));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Anna', '2026-12-01', 37.5);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p2 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Ugo (13611) passa a Bruno ed Elsa, 6806 e 6805. I giorni di Bruno stanno nella sua riga, non in quella di Anna: quando li vende ad Anna,
    // che pesa 25 × 184 e dal 1/12 anche 37,5 × 31, la sua parte sale da 4537 a 18148 × 5762,5/18400 = 5683,58 → 5684, e la fermata è 18148 × 37,5 × 31/18400 = 1146,58 → 1147,
    // a carico di Bruno, che ha le due righe di quei giorni nella sua parte della coppia.
    expect(fer55Proposte($pannello))->toBe(fer55Registro($p2))
        ->and(fer55Registro($p2))->toBe([['persone' => ['Bruno'], 'cifra' => 1147, 'riceve' => ['Anna' => 1147], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 136,11 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con la successione del 15 ottobre 2026 una parte, € 68,06, è passata a Bruno. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca ad Anna, ma il programma non la sposta: € 11,47 restano a carico di Bruno. '
        . 'Se vanno ad Anna, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 11,47 a credito di Bruno e € 11,47 a debito di Anna. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1147, (int) $p2->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Anna', 1147, (int) $p2->id)))->toBe([])
        ->and(sl53Legate($s, $p2))->toBe(['Anna' => 1147, 'Bruno' => -1147]);
});

it('Coda 347 (l\'erede che ricompra vende a chi ha la coppia) — Ugo vende la metà piena a Elsa, muore (la nuda a Bruno), Bruno la ricompra da Elsa e la vende a Zeno, che ha la sua riga: € 15,29 restano a carico di Bruno, con il Wallet', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 50]));
    $p3 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));
    $dati = fds50ModuloVendita($s, 'Bruno', 'Zeno', '2026-12-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p4 = fer55Passo($this, $s, $dati);

    // Conto a mano: Bruno è l'unico erede di Ugo e ricompra da Elsa la metà che Ugo le aveva venduto: quei giorni stavano già nella riga che è sua, e la seconda vendita
    // non ha fermata. Quando la rivende a Zeno (1/12), 18148 × 50 × 31/18400 = 1528,77 → 1529 stanno nella riga di Bruno e non in quella di Zeno (l'usufrutto della metà B):
    // la fermata è di Bruno, con il Wallet.
    expect(fer55Registro($p3))->toBe([])
        ->and(fer55Proposte($pannello))->toBe(fer55Registro($p4))
        ->and(fer55Registro($p4))->toBe([['persone' => ['Bruno'], 'cifra' => 1529, 'riceve' => ['Zeno' => 1529], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 90,74 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'Con la successione del 15 ottobre 2026 una parte, € 90,74, è passata a Bruno. '
        . 'Con questo passaggio la parte di quei giorni dal 1 dicembre 2026 tocca a Zeno, ma il programma non la sposta: € 15,29 restano a carico di Bruno. '
        . 'Se vanno a Zeno, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 15,29 a credito di Bruno e € 15,29 a debito di Zeno. '
        . 'Un saldo legato non occupa il posto di un riporto, e si toglie se annulli il passaggio.');
    expect(sl53Errori(sl53Posta($this, $s, 'Bruno', -1529, (int) $p4->id)))->toBe([])
        ->and(sl53Errori(sl53Posta($this, $s, 'Zeno', 1529, (int) $p4->id)))->toBe([])
        ->and(sl53Legate($s, $p4))->toBe(['Bruno' => -1529, 'Zeno' => 1529]);
});

it('Coda 348 (la cifra per persona) — la fermata di prima si dice con le sue persone, senza le cifre della rivendita: due eredi che comprano da Elsa', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Dora' => 25]));
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50));

    // Conto a mano: la fermata di prima è 4537 (50 × 92 giorni), a carico di Ugo, cioè dei suoi eredi, 2268,5 ciascuno; la cifra di adesso è 3008 (50 × 61:
    // 3008,23), 1504 e 1504. Le parti di € 30,08 non si dicono accanto a € 45,37.
    $frase = fer55Frase($pannello, 'Con questo passaggio');
    expect($frase)->toContain('La vendita del 1 ottobre 2026 non ha spostato la parte di Elsa, € 45,37, rimasta a carico di Bruno e Dora da regolare a mano.')
        ->and($frase)->not->toContain('€ 45,37, rimasta a carico di Bruno (€ 15,04)')
        ->and($frase)->toContain('€ 30,08 restano a carico di Bruno (€ 15,04) e Dora (€ 15,04), o di Elsa');
});
