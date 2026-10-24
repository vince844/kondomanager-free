<?php

/**
 * 1.11.0-beta.55 — le righe di titolarità cambiate o aperte a mano («Modifica associazione», «Associa soggetto») e la fermata della rivendita.
 *
 * La catena dei passaggi di una riga (`passaggiDellaRiga`) si legge dal registro di ogni passaggio, che dice quali righe ha aperto, chiuso o cambiato. Una riga aperta a mano non
 * è in nessun registro: la sua catena è vuota, e il programma non sa da quali passaggi vengano i giorni che chi esce vende.
 *
 * L'erede con la riga riaperta a mano (il primo test): senza la catena la fermata è sua, con il Wallet, come nella .53, anche se prima aveva ricevuto una fermata di cui il programma non
 * può dire quanta parte sia rimasta in quella riga.
 *
 * Coda 353 (le persone che il registro non sa). Ugo 75 % e Anna 25 %, la fine di Ines il 1/7 con «Scrivi» (la coppia 18148: Ugo 13611, Anna 4537). A mano, la riga di Anna è chiusa il
 * 30/9 e Elsa è associata al 25 % dal 1/10, senza un passaggio. Elsa vende a Carlo il 1/11: i suoi 25 × 61 giorni valgono 18148 × 25 × 61/18400 = 1504,11 → 1504, e stanno nella riga di
 * Anna, ma il programma non lo sa: prima la frase diceva «a carico di Ugo e Anna», e Ugo non c'entra. Ora dice che i giorni di Elsa non vengono da un passaggio registrato che dica in
 * quale riga stanno, e che il programma non lo sa, e non nomina nessuno fra chi è a carico. Se Elsa vende a Ugo, che ha già una riga, prima non diceva niente (i 1504 restavano ad Anna
 * in silenzio): ora lo stesso avviso prende il posto del silenzio. Le cifre sono quelle di prima.
 *
 * Che cosa NON copre: il testo dell'avviso dove ricevono più persone insieme (la successione a due eredi che hanno la coppia: non c'è, perché ognuna potrebbe dover regolare con l'altra;
 * c'è solo la guardia); le frasi dell'erede con il registro di una beta di prima (si controlla solo che non dicano «non sa»); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Services\Subentro\FermataDopoScrivi;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

it('Coda 341 (l\'erede con la riga riaperta a mano) — senza la catena della riga il programma non sa da dove vengono i giorni: la fermata è dell\'erede, con il Wallet, come nella .53', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]));

    // Conto a mano: la coppia è Ugo 13611 e Anna 4537. La vendita di Anna a Bruno (1/10) ferma 4537 × 92/184 = 2268,5 → 2268 a carico di Anna; la riga di Ugo passa a Bruno con la
    // successione. La rivendita a Carlo (1/12) vale 18148 × 100 × 31/18400 = 3057,54 → 3058: con la catena intatta il 75 % sta nella riga di Ugo, ora di Bruno, e il 25 % in quella di Anna.
    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    expect(fer55Proposte(fds50Anteprima($this, $s, $dati)))->toBe([['persone' => ['Bruno', 'Anna'], 'cifra' => 3058, 'riceve' => ['Carlo' => 3058], 'uscente' => 'Bruno',
        'dopo_la_fermata' => ['passaggio' => 'la vendita del 1 ottobre 2026', 'cifra' => 2268, 'persona' => 'Anna'], 'wallet' => false, 'strada' => null]]);

    // A mano: la riga di Bruno (100 %, nata con la successione) chiusa il 31/10 da «Modifica associazione» e riaperta il 1/11 da «Associa soggetto». La nuova riga non è nel registro di
    // nessun passaggio.
    $riga = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Bruno']->id)->whereNull('data_fine')->first();
    expect(sl53Errori($this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $riga->id]), [
        'anagrafica_id' => $s['persone']['Bruno']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-10-15', 'data_fine' => '2026-10-31', 'note' => null,
    ])))->toBe([]);
    expect(sl53Errori($this->actingAs($this->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $s['persone']['Bruno']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-11-01',
    ])))->toBe([]);
    $this->travel(1)->minutes();

    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100));

    // Senza la catena la fermata è di Bruno, che ha la sua parte della coppia con la successione: tutta a suo carico, con il Wallet. La quota che aveva comprato da Anna (25 × 31 giorni,
    // 18148 × 25 × 31/18400 = 764,39) non si legge: è il limite di una riga cambiata a mano.
    expect(fer55Proposte($pannello))->toBe([['persone' => ['Bruno'], 'cifra' => 3058, 'riceve' => ['Carlo' => 3058], 'uscente' => null, 'dopo_la_fermata' => null, 'wallet' => true, 'strada' => 'legata']]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 30,58 restano a carico di Bruno. Se vanno a Carlo, registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: '
        . '€ 30,58 a credito di Bruno e € 30,58 a debito di Carlo.');
    fer55SenzaNomiMancanti($pannello);
});

/** Ugo 75 % e Anna 25 %, la fine di Ines con «Scrivi»; poi, a mano: la riga di Anna chiusa il 30/9 («Modifica associazione») e Elsa associata al 25 % dal 1/10 («Associa soggetto»). */
function fer55ElsaARigaAperta($test, array $titolari = [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]): array
{
    $s = fer55Base($test, $titolari);
    $riga = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone'][$titolari[array_key_last($titolari)][0]]->id)->first();
    expect(sl53Errori($test->actingAs($test->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $riga->id]), [
        'anagrafica_id' => $riga->anagrafica_id, 'tipologia' => 'proprietario', 'quota' => $riga->quota, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-09-30', 'note' => null,
    ])))->toBe([]);
    expect(sl53Errori($test->actingAs($test->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'tipologia' => 'proprietario', 'quota' => $riga->quota, 'data_inizio' => '2026-10-01',
    ])))->toBe([]);
    $test->travel(1)->minutes();

    return $s;
}

it('Coda 353 (la rivendita di una riga aperta a mano) — Elsa vende a Carlo: € 15,04 restano a carico di chi ha nella sua riga i giorni di Elsa, e la frase dice che il programma non sa in quale riga stanno', function () {
    $s = fer55ElsaARigaAperta($this);
    $dati = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 25);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è Ugo 13611 e Anna 4537. I giorni di Elsa dal 1/11 sono 25 × 61: 18148 × 1525/18400 meno quanto Elsa pesa ancora (25 × 31) = 18148 × 25 × 61/18400 = 1504,11 → 1504.
    // Le persone che hanno la coppia sono Ugo e Anna, ma la riga di Elsa non è nel registro di nessun passaggio: non si sa quale delle due contenga i suoi giorni.
    $fermata = [['persone' => ['Ugo', 'Anna'], 'cifra' => 1504, 'riceve' => ['Carlo' => 1504], 'uscente' => 'Elsa', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p))->toBe($fermata)
        ->and(fds50Fermate($p)[0]['riga_non_nota'])->toBeTrue();

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo e Anna hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Elsa non viene da un passaggio registrato che dica in quale riga stanno i suoi giorni, e il programma non lo sa. '
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,04 restano a carico di chi ha nella sua riga i giorni di Elsa, '
        . 'o di Elsa se la parte di Elsa è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Elsa, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 15,04 dei giorni dopo l\'uscita di Ines restano a carico di chi ha nella sua riga i giorni di Elsa, o di Elsa se la parte di Elsa è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Elsa');
    expect($pannello['cancello']['richiesto'])->toBeTrue();
    expect(fer55Storico($s, $p))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 15,04 dei giorni dal 1 novembre 2026 sono rimasti '
        . 'a carico di chi aveva nella sua riga i giorni di Elsa sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Carlo, o fra Elsa e Carlo se la parte di Elsa era già stata regolata.');
    expect(FermataDopoScrivi::avvisiDelConguaglio($s['fine']->fresh()))->toBe(['La vendita del 1 novembre 2026 si è fermata su questo conguaglio: € 15,04 dei giorni dal 1 novembre 2026 sono rimasti '
        . 'a carico di chi aveva nella sua riga i giorni di Elsa, o di Elsa se la parte di Elsa era già stata regolata, sulla gestione Ordinaria 2026, da regolare a mano con Carlo. '
        . 'Annullato il conguaglio, quella fermata non vale più: se le parti l\'hanno regolata fra loro, quell\'accordo va rivisto.']);
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (la rivendita di una riga aperta a mano, poi un inizio) — Luca dal 15/12: resta da regolare € 10,85 fra chi ne risponde e Carlo, senza nominare Ugo e Anna', function () {
    $s = fer55ElsaARigaAperta($this);
    $vendita = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 25));
    $datiInizio = fds50ModuloInizio($s, 'Luca', '2026-12-15');
    $pannello = fds50Anteprima($this, $s, $datiInizio);
    $inizio = fer55Passo($this, $s, $datiInizio);

    // Conto a mano: i 17 giorni di Luca (15/12–31/12) tolgono alla quota di Carlo 25 × 17: 18148 × 25 × 17/18400 = 419,18 → 419; restano 1504 − 419 = 1085 (25 × 44: 1084,93).
    expect(fer55Ridotte($pannello))->toBe([['passaggio' => 'la vendita del 1 novembre 2026', 'cifra' => 1504, 'prima' => 1504, 'residuo' => 1085, 'parti' => ['Carlo' => 1085]]]);
    expect(fer55Frase($pannello, 'La vendita del 1 novembre 2026'))->toBe('La vendita del 1 novembre 2026 ha lasciato a carico di chi ha nella sua riga i giorni di Elsa, o di Elsa se la parte di Elsa è già stata regolata, '
        . '€ 15,04 dei giorni dopo l\'uscita di Ines, da regolare a mano con Carlo. Con questo inizio i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori: '
        . 'resta da regolare € 10,85 con Carlo. Se chi ne risponde e Carlo hanno già regolato € 15,04, € 4,19 vanno restituiti a Carlo.');
    expect(fds50Testi($pannello))->toContain('della fermata della vendita del 1 novembre 2026 restano da regolare fra chi ne risponde (chi ha nella sua riga i giorni di Elsa, o Elsa se la parte di Elsa è già stata regolata) '
        . 'e Carlo € 10,85, non più € 15,04: i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori');
    fer55SenzaNomiMancanti($pannello);

    // Lo storico dell'inizio dice lo stesso, e annullato il conguaglio della fine le due fermate non valgono più: gli storici di tutti e due lo dicono senza nominare Ugo e Anna.
    expect(fds50Testi(fds50Storico($s, $inizio)))->toContain('Con questo inizio, della fermata della vendita del 1 novembre 2026 (€ 15,04 rimasti a carico di chi aveva nella sua riga i giorni di Elsa, '
        . 'o di Elsa se la parte di Elsa è già stata regolata) restano da regolare fra chi ne risponde e Carlo € 10,85: i giorni dal 15 dicembre 2026 li rimborsa Luca con il prospetto degli oneri accessori.');
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine']->fresh())))->toBe([]);
    expect(fer55Storico($s, $vendita))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 è stato annullato dopo questo passaggio: la sua fermata (€ 15,04 dei giorni dal 1 novembre 2026, '
        . 'rimasti a carico di chi aveva nella sua riga i giorni di Elsa, o di Elsa se la parte di Elsa era già stata regolata, da regolare a mano con Carlo) non vale più.');
    expect(fds50Testi(fds50Storico($s, $inizio)))->toContain('Il conguaglio scritto alla fine locazione di Ines è stato annullato dopo questo inizio: la fermata della vendita del 1 novembre 2026 '
        . '(€ 15,04 rimasti a carico di chi aveva nella sua riga i giorni di Elsa, o di Elsa se la parte di Elsa era già stata regolata), che con questo inizio restava da regolare per € 10,85, non vale più.');
});

it('Coda 353 (chi compra ha una riga) — Elsa vende a Ugo: nessuna fermata e nessuna spunta, ma l\'avviso dice che il programma non sa in quale riga stanno i giorni', function () {
    $s = fer55ElsaARigaAperta($this);
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 25);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p = fer55Passo($this, $s, $dati);

    // Conto a mano: se i giorni di Elsa stessero nella riga di Anna, Ugo li avrebbe in più: 18148 × 25 × 61/18400 = 1504,11 → 1504. Il programma non lo sa, e non propone una fermata.
    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Registro($p))->toBe([])
        ->and($pannello['cancello']['richiesto'])->toBeFalse();
    expect(fer55Frase($pannello, 'La parte di Elsa non viene da un passaggio registrato'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita '
        . 'Ugo e Anna hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Elsa non viene da un passaggio registrato che dica in quale riga stanno i suoi giorni, e il programma non lo sa. '
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Ugo, che ha già una sua riga della coppia, e il programma non sposta niente: '
        . 'se i giorni di Elsa stanno nella riga di un\'altra persona, fino a € 15,04 si regolano a mano con Ugo: da chi li ha nella sua riga, o da Elsa se la parte di Elsa è già stata regolata; '
        . 'se stanno nella riga di Ugo, non c\'è niente da regolare.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (guardia: una persona sola ha la coppia) — i giorni stanno nella sua riga, e la frase la nomina come prima', function () {
    $s = fer55ElsaARigaAperta($this, [['Ugo', 'proprietario', 100]]);
    $dati = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p = fer55Passo($this, $s, $dati);

    // Conto a mano: la coppia è tutta di Ugo (18148). Elsa, al 100 % dal 1/10, vende a Carlo il 1/11: 18148 × 100 × 61/18400 = 6016,46 → 6016 o 6017 con i resti; i giorni non possono stare in
    // nessuna riga che non sia quella di Ugo, l'unica della coppia.
    expect(fds50Fermate($p)[0]['persone'] ?? ['Ugo'])->toBe(['Ugo'])
        ->and(fds50Fermate($p)[0])->not->toHaveKey('riga_non_nota');
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('La parte di Elsa non è passata con la coppia, ed è rimasta a carico di Ugo.')
        ->and(fer55Frase($pannello, 'Con questo passaggio'))->not->toContain('non viene da un passaggio registrato');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (guardia: una persona sola ha la coppia e ricompra) — Elsa, che ha una riga aperta a mano, rivende a Ugo: i giorni tornano nella sua riga, l\'unica, e non c\'è niente da dire', function () {
    $s = fer55ElsaARigaAperta($this, [['Ugo', 'proprietario', 100]]);
    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100));

    expect(fer55Proposte($pannello))->toBe([])
        ->and(fer55Ridotte($pannello))->toBe([])
        ->and($pannello['rate']['senza_riga'])->toBe([])
        ->and(fds50Con($pannello, 'non viene da un passaggio registrato'))->toBe([])
        ->and($pannello['cancello']['richiesto'])->toBeFalse();
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (guardia: l\'erede con il registro di prima senza gli id) — la fermata dell\'erede nomina le persone come prima, e non dice che non sa nella riga di chi stanno i giorni', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Bruno', '2026-10-01', 25));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'proprietario', '2026-10-15', 75, ['Bruno' => 75]));
    // La fermata della vendita di Anna come la scriveva una beta di prima della .53 con più persone a carico: i nomi, non i loro id.
    $registro = $p1->fresh()->registro;
    $registro['fermata_dopo_scrivi'][0]['piu_persone'] = true;
    $registro['fermata_dopo_scrivi'][0]['persone'] = ['Anna', 'Ugo'];
    unset($registro['fermata_dopo_scrivi'][0]['persone_ids']);
    $p1->forceFill(['registro' => $registro])->save();
    $this->travel(1)->minutes();

    $dati = fds50ModuloVendita($s, 'Bruno', 'Carlo', '2026-12-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la rivendita a Carlo vale 18148 × 100 × 31/18400 = 3057,54 → 3058, dalle righe di Bruno (che ha quella di Ugo) e di chi gli aveva venduto: senza gli id il programma non sa
    // quali, e nomina le persone che hanno la coppia, come prima.
    expect(fds50Fermate($p3))->not->toBeEmpty()
        ->and(fds50Fermate($p3)[0])->not->toHaveKey('riga_non_nota')
        ->and(fds50Fermate($p3)[0]['ereditata']['da'])->toBe('Ugo');
    expect(fds50Con($pannello, 'non viene da un passaggio registrato'))->toBe([]);
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (guardia: ricevono più persone) — Elsa, con una riga aperta a mano, muore e lascia a Ugo e Anna, che hanno la coppia: nessuna fermata e nessun avviso, perché ognuna potrebbe dover regolare con l\'altra', function () {
    $s = fer55ElsaARigaAperta($this);
    $pannello = fds50Anteprima($this, $s, fer55Successione($s, 'Elsa', 'proprietario', '2026-10-15', 25, ['Ugo' => 12.5, 'Anna' => 12.5], Subentro::ARRETRATO_AL_DEFUNTO, Subentro::NON_SCRIVERE_IL_CONGUAGLIO));

    expect(fer55Proposte($pannello))->toBe([])
        ->and($pannello['rate']['senza_riga'])->toBe([])
        ->and(fds50Con($pannello, 'non viene da un passaggio registrato'))->toBe([]);
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (il registro della prima vendita senza gli id, compra chi non ha una riga) — Anna vende a Elsa e Elsa a Carlo: la frase nomina la vendita e dice che il suo registro non sa nella riga di chi stanno i giorni', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    // La fermata della vendita di Anna come la scriveva una beta di prima della .53 con più persone a carico: i nomi, non i loro id.
    $registro = $p1->fresh()->registro;
    $registro['fermata_dopo_scrivi'][0]['piu_persone'] = true;
    $registro['fermata_dopo_scrivi'][0]['persone'] = ['Anna', 'Ugo'];
    unset($registro['fermata_dopo_scrivi'][0]['persone_ids']);
    $p1->forceFill(['registro' => $registro])->save();
    $this->travel(1)->minutes();

    $pannello = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 25));

    // Conto a mano: la vendita di Anna ferma 4537 × 92/184 = 2268,5 → 2268 (il centesimo ad Anna); la rivendita di Elsa a Carlo (1/11) vale 18148 × 25 × 61/18400 = 1504,11 → 1504.
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo e Anna hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La vendita del 1 ottobre 2026 non ha spostato la parte di Elsa, € 22,68, rimasta a carico di chi ha nella sua riga i giorni di Elsa da regolare a mano. '
        . 'Il registro della vendita del 1 ottobre 2026 non dice in quale riga stanno i giorni di Elsa, e il programma non lo sa. '
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Carlo, ma il programma non la sposta: € 15,04 restano a carico di chi ha nella sua riga i giorni di Elsa, '
        . 'o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata. '
        . 'Se vanno a Carlo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la fermata della vendita del 1 ottobre 2026, e il programma non lo sa.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 353 (riga aperta a mano) — con le righe legate, l\'inizio di un conduttore non nomina fra chi è a carico le persone che il programma non sa', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 75], ['Anna', 'proprietario', 25]]);
    $riga = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Anna']->id)->first();
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $riga->id]), [
        'anagrafica_id' => $riga->anagrafica_id, 'tipologia' => 'proprietario', 'quota' => $riga->quota, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-09-30', 'note' => null,
    ]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'tipologia' => 'proprietario', 'quota' => $riga->quota, 'data_inizio' => '2026-10-01',
    ]);
    $this->travel(1)->minutes();
    $v = fer55Passo($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 25));
    sl53Posta($this, $s, 'Anna', -1504, (int) $v->id);
    sl53Posta($this, $s, 'Carlo', 1504, (int) $v->id);
    $this->travel(1)->minutes();
    $pannello = fds50Anteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-15'));

    // La frase della fermata dice «chi ha nella sua riga i giorni di Elsa»; la parte dei saldi legati non si attribuisce a persone che il programma non sa.
    $frase = fer55Frase($pannello, 'La vendita del 1 novembre 2026');
    expect($frase)->toContain('chi ha nella sua riga i giorni di Elsa')
        ->and($frase)->not->toContain('Ugo e Anna insieme');
});
