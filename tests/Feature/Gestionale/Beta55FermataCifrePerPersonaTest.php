<?php

/**
 * 1.11.0-beta.55 — la fermata di chi compra da chi gli aveva venduto: la cifra si dice per persona, non intera a carico di tutti.
 *
 * Quando a ricevere è una delle persone a carico (Elsa ha comprato da Anna e da Ugo e rivende tutto a Ugo), una parte dei giorni sta già nella riga di chi riceve, e l'altra in quella
 * dell'altra persona: la frase «€ 60,17 restano a carico di Ugo e Anna» le metteva tutte e due a carico di tutti e due. La cifra si divide per origine, in proporzione alla quota che
 * ogni fermata di prima ha dato alla riga che chi esce vende, e dentro l'origine fra le sue persone (gli eredi di un defunto), in proporzione alla loro riga della coppia; il centesimo
 * che avanza va alla fermata più vecchia.
 *
 * Fonte dell'atteso: il conto a mano. La coppia è 36000 × 184/365 = 18147,95 → 18148 sui 184 giorni dal 1/7. Ugo 50 % e Anna 50 % (9074 e 9074): Anna vende a Elsa il 1/10
 * (50 × 92 giorni), Ugo il 5/10 (50 × 88), Elsa rivende tutto a Ugo il 1/11: i giorni dal 1/11 sono 61, 18148 × 100 × 61/18400 = 6016,46 → 6017 con i resti maggiori (Ugo passa da
 * 4734 a 10751); a metà fra le due origini 3008,5: 3009 alla vendita di Anna (la più vecchia) e 3008 a quella di Ugo. Con le righe 75 % e 25 % (Ugo 13611, Anna 4537) la divisione per
 * quota è ancora a metà, 50 e 50 comprati: 18148 × 50 × 61/18400 = 3008,23 → 3008, 1504 e 1504; per righe sarebbe 2256 e 752.
 *
 * Che cosa NON copre: la rivendita dell'erede la cui catena passa per una successione (la frase resta con le persone senza le cifre); più persone a carico dove non riceve nessuna di
 * loro (la parte di ognuna la decide l'amministratore, decisione 86.1); MySQL.
 */

use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta55FermataScenari.php';

beforeEach(function () {
    sl53Amministratore($this);
});

/** Le cifre per persona che il registro del passaggio conserva: nome → centesimi. */
function fer55CifrePerPersona(App\Models\Gestionale\Subentro $passaggio): array
{
    return collect(fds50Fermate($passaggio)[0]['cifre_per_persona'] ?? [])->mapWithKeys(fn (array $p) => [$p['nome'] => (int) $p['cifra']])->all();
}

it('Coda 347 (cifra per persona) — Anna e Ugo vendono a Elsa, che rivende tutto a Ugo: € 60,17 restano a carico di Ugo (€ 30,08) e Anna (€ 30,09), o di Elsa', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    $p1 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 50));
    $p2 = fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-05', 50));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano (in cima al file): la vendita di Anna ferma 18148 × 50 × 92/18400 = 4537, quella di Ugo 18148 × 50 × 88/18400 = 4339,74 → 4340; la rivendita 6017, a metà fra le due origini.
    expect(fer55Registro($p1)[0]['cifra'])->toBe(4537)
        ->and(fer55Registro($p2)[0]['cifra'])->toBe(4340);
    $fermata = [['persone' => ['Ugo', 'Anna'], 'cifra' => 6017, 'riceve' => ['Ugo' => 6017], 'uscente' => 'Elsa', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]];
    expect(fer55Proposte($pannello))->toBe($fermata)
        ->and(fer55Registro($p3))->toBe($fermata)
        ->and(fer55CifrePerPersona($p3))->toBe(['Ugo' => 3008, 'Anna' => 3009]);

    expect(fer55Frase($pannello, 'Con questo passaggio'))->toBe('La fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo e Anna hanno preso € 181,48 sulla gestione Ordinaria 2026 (Interno 1). '
        . 'La parte di Elsa non è passata con la coppia, ed è rimasta a carico di Ugo e Anna. '
        . 'Con questo passaggio la parte di quei giorni dal 1 novembre 2026 tocca a Ugo, ma il programma non la sposta: € 60,17 restano a carico di Ugo (€ 30,08) e Anna (€ 30,09), '
        . 'o di Elsa se la parte di Elsa è già stata regolata. '
        . 'Se vanno a Ugo, la parte si regola fra le parti: chi la restituisce dipende da come è stata regolata la parte di Elsa, e il programma non lo sa.');
    expect(fds50Testi($pannello))->toContain('€ 60,17 dei giorni dopo l\'uscita di Ines restano a carico di Ugo (€ 30,08) e Anna (€ 30,09), o di Elsa se la parte di Elsa è già stata regolata (gestione Ordinaria 2026): '
        . 'il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Ugo con questo passaggio, come non era passato a Elsa');
    expect(fer55Storico($s, $p3))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 non è passato con questo passaggio: € 60,17 dei giorni dal 1 novembre 2026 sono rimasti '
        . 'a carico di Ugo (€ 30,08) e Anna (€ 30,09) sulla gestione Ordinaria 2026 (Interno 1), da regolare a mano con Ugo, o fra Elsa e Ugo se la parte di Elsa era già stata regolata.');
    fer55SenzaNomiMancanti($pannello);

    // Annullato il conguaglio della fine la fermata non vale più, e lo storico lo dice con le stesse cifre per persona.
    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine']->fresh())))->toBe([]);
    expect(fer55Storico($s, $p3))->toBe('Il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026 è stato annullato dopo questo passaggio: la sua fermata (€ 60,17 dei giorni dal 1 novembre 2026, '
        . 'rimasti a carico di Ugo (€ 30,08) e Anna (€ 30,09), o di Elsa se la parte di Elsa era già stata regolata, da regolare a mano con Ugo) non vale più.');
});

it('Coda 347 (cifra per persona, quote diverse) — Ugo 50 e 25, Anna 25: la divisione segue le quote comprate (25 e 25), non le righe della coppia (75 e 25)', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-05', 25));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano (in cima al file): la cifra è 3008 (Ugo passa da 11441 a 14449), a metà fra la quota di Anna (25) e quella di Ugo (25): 1504 e 1504. Le righe della coppia sono Ugo 13611 e Anna 4537.
    expect(fer55Registro($p3))->toBe([['persone' => ['Ugo', 'Anna'], 'cifra' => 3008, 'riceve' => ['Ugo' => 3008], 'uscente' => 'Elsa', 'dopo_la_fermata' => null, 'wallet' => false, 'strada' => null]])
        ->and(fer55CifrePerPersona($p3))->toBe(['Ugo' => 1504, 'Anna' => 1504]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 30,08 restano a carico di Ugo (€ 15,04) e Anna (€ 15,04), o di Elsa se la parte di Elsa è già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (cifra per persona, due eredi) — Ugo vende la metà piena a Elsa e muore (la nuda a Bruno e Dora), Elsa vende a Bruno: € 30,08 restano a carico di Bruno (€ 15,04) e Dora (€ 15,04)', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 25, 'Dora' => 25]));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la vendita di Ugo a Elsa (1/10) ferma 4537 a carico di Ugo, la cui riga (9074) passa a metà a Bruno e Dora (4537 e 4537). La rivendita a Bruno (1/11) vale
    // 18148 × 50 × 61/18400 = 3008,23 → 3008, tutta dalla riga di Ugo: 1504 a Bruno (che la ha già) e 1504 a Dora.
    expect(fer55Registro($p3)[0]['cifra'])->toBe(3008)
        ->and(fer55CifrePerPersona($p3))->toBe(['Bruno' => 1504, 'Dora' => 1504]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 30,08 restano a carico di Bruno (€ 15,04) e Dora (€ 15,04), o di Elsa se la fermata della vendita del 1 ottobre 2026 è già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (cifra per persona, guardia) — se non riceve nessuna delle persone a carico la cifra resta intera, a carico di tutte insieme', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 50], ['Anna', 'proprietario', 50]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-05', 50));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 100);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: Carlo compra i 61 giorni di Elsa, 18148 × 100 × 61/18400 = 6016,46 → 6017 con i resti maggiori (Carlo ha il resto più alto, ,46), dalle righe di Anna e di Ugo insieme:
    // la parte di ognuno la decide l'amministratore.
    expect(fer55Registro($p3)[0]['cifra'])->toBe(6017)
        ->and(fer55CifrePerPersona($p3))->toBe([]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 60,17 restano a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata.');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (cifra per persona, eredi con quote diverse) — Bruno 30 e Dora 20: la parte dentro l\'origine segue la riga di ciascuno, 60 e 40', function () {
    $s = fer55Mista($this);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01', 50));
    fer55Passo($this, $s, fer55Successione($s, 'Ugo', 'nuda_proprietario', '2026-10-15', 50, ['Bruno' => 30, 'Dora' => 20]));
    $dati = fds50ModuloVendita($s, 'Elsa', 'Bruno', '2026-11-01', 50);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    // Conto a mano: la riga di Ugo (9074) passa a Bruno e Dora per 30 e 20 su 50: 5444,4 → 5444 e 3629,6 → 3630. La rivendita a Bruno vale 3008 (18148 × 50 × 61/18400 = 3008,23), tutta dalla riga
    // di Ugo: a Bruno il 60 % (1804,8 → 1805), a Dora il 40 % (1203,2 → 1203).
    expect(fer55Registro($p3)[0]['cifra'])->toBe(3008)
        ->and(fer55CifrePerPersona($p3))->toBe(['Bruno' => 1805, 'Dora' => 1203]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('€ 30,08 restano a carico di Bruno (€ 18,05) e Dora (€ 12,03), o di Elsa');
    fer55SenzaNomiMancanti($pannello);
});

it('Coda 347 (cifra per persona, riga cambiata a mano) — se la quota della riga di Elsa non è tutta nel registro dei passaggi, non si divide: la cifra resta intera a carico di tutti', function () {
    $s = fer55Base($this, [['Ugo', 'proprietario', 25], ['Anna', 'proprietario', 25]]);
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Anna', 'Elsa', '2026-10-01', 25));
    fer55Passo($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-05', 25));
    // A mano, da «Modifica associazione»: la riga di Elsa (50 dal 5/10, nata dalla somma dei due acquisti) passa al 75 %. Il registro dei passaggi dice 25 + 25.
    $riga = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->whereNull('data_fine')->first();
    expect(sl53Errori($this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $riga->id]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'tipologia' => 'proprietario', 'quota' => 75, 'data_inizio' => substr((string) $riga->data_inizio, 0, 10), 'data_fine' => null, 'note' => null,
    ])))->toBe([]);
    $this->travel(1)->minutes();

    $dati = fds50ModuloVendita($s, 'Elsa', 'Ugo', '2026-11-01', 75);
    $pannello = fds50Anteprima($this, $s, $dati);
    $p3 = fer55Passo($this, $s, $dati);

    expect(fer55Registro($p3)[0]['persone'])->toBe(['Ugo', 'Anna'])
        ->and(fer55CifrePerPersona($p3))->toBe([]);
    expect(fer55Frase($pannello, 'Con questo passaggio'))->toContain('restano a carico di Ugo e Anna, o di Elsa se la parte di Elsa è già stata regolata.')
        ->and(fer55Frase($pannello, 'Con questo passaggio'))->not->toContain('(€ ');
    fer55SenzaNomiMancanti($pannello);
});
