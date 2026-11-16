<?php

/**
 * La regola del passo proposto, pura: i dati di una persona e il giorno di oggi danno il caso, il passo, la frase e i passi possibili. Vale il primo caso che
 * va bene, nell'ordine della tabella (da R0 a R15, con R9b). Il passo è un suggerimento: la regola non manda niente e non scrive niente.
 *
 * Le date: oggi è il 14/11/2026. Una persona «tipo» ha tre rate scadute (12|1, 13|1, 14|1) da € 100,00 ciascuna, la data dei sei mesi al 30/06/2027
 * (oltre 60 giorni) e nessuna lettera; ogni test cambia solo ciò che gli serve. Centesimi.
 *
 * Dopo una lettera, quando scadono rate nuove, il condominio sceglie (la scheda «Impostazioni»): con «La salita va avanti» (di serie) una rata nuova non
 * riporta indietro il passo; con «Per le rate nuove si ricomincia» le rate scadute dopo la lettera ripartono come prima della lettera. In tutti e due i
 * casi una rata che la lettera non chiedeva e che è ancora nella tolleranza non toglie alla lettera il suo caso, e una nota lo dice.
 */

use App\Services\Gestionale\Accessori\SuggerimentiDelRecupero;
use App\Services\Gestionale\Debiti\PassoProposto;

const PPT_OGGI = '2026-11-14';

/** Una rata aperta della persona. */
function pptRata(string $chiave, int $residuo = 10000, string $scadenza = '2026-09-05', ?string $data = '2027-06-30', array $altro = []): array
{
    return $altro + ['chiave' => $chiave, 'residuo' => $residuo, 'scadenza' => $scadenza, 'giorni_ritardo' => 70, 'data' => $data, 'dispensa' => null,
        'senza_data' => null, 'in_accordo' => 0, 'fuori_dalle_lettere' => false];
}

/** Una lettera del registro, come la dà `per_tipo`. */
function pptLettera(string $tipo, string $stato, string $data, array $altro = []): array
{
    return $altro + ['id' => random_int(1, 99999), 'tipo' => $tipo, 'stato' => $stato, 'data' => $data, 'canale' => $stato === 'inviata' || $stato === 'in_coda' || $stato === 'non_partita' ? 'email' : 'stampa',
        'spedizione' => $stato === 'spedita' ? ['data' => $data, 'canale' => 'raccomandata'] : null, 'termine' => 15, 'termine_forma' => 'giorni', 'termine_data' => null,
        'righe' => ['12|1', '13|1', '14|1']];
}

/** La persona tipo, con le aggiunte del test. */
function pptPersona(array $altro = []): array
{
    return $altro + [
        'rate' => [pptRata('12|1'), pptRata('13|1'), pptRata('14|1')],
        'agito' => [], 'tornati' => [], 'credito' => null, 'accordi' => [],
        'lettere' => ['diffida' => null, 'sollecito' => null, 'promemoria' => null],
        'fascicolo' => null, 'tolleranza' => null, 'salita' => null,
    ];
}

function pptPer(array $persona, string $vista = PassoProposto::RIQUADRO): array
{
    return PassoProposto::per($persona, PPT_OGGI, $vista);
}

it('R0: «hai agito» su tutte le rate aperte, nessun passo, con la frase del giorno, della nota e di chi', function () {
    $p = pptPer(pptPersona(['rate' => [], 'agito' => [['giorno' => '2026-03-12', 'nota' => 'pratica data all\'avv. Gallo', 'utente' => 'Vincenzo']]]));

    expect($p['caso'])->toBe('R0')->and($p['passo'])->toBeNull()
        ->and($p['frase'])->toBe('Hai agito il 12/03/2026: «pratica data all\'avv. Gallo» (Vincenzo).');
});

it('R1: chi torna dopo «hai agito» porta la frase del ritorno e si va avanti con la tabella', function () {
    $torna = 'Dopo che hai registrato (il 14/11/2026), le rate di questo esercizio sono salite da € 400,00 a € 520,00.';
    $p = pptPer(pptPersona(['tornati' => [$torna]]));

    expect($p['note'])->toBe([$torna])->and($p['caso'])->toBe('R15')->and($p['passo'])->toBe(PassoProposto::SOLLECITO);
});

it('R2: il credito della stessa gestione copre tutte le rate aperte: «Compensa» con la prima rata coperta, e la frase del credito', function () {
    $credito = ['importo_cents' => 30000, 'debito_altrove_cents' => 0, 'frase' => 'Copre Rata 12 in scadenza il 05/09/2026 e altre 2 rate.',
        'rate_coperte' => [['rata_id' => 12, 'copre_tutto' => true], ['rata_id' => 13, 'copre_tutto' => true], ['rata_id' => 14, 'copre_tutto' => true]]];
    $p = pptPer(pptPersona(['credito' => $credito]));

    expect($p['caso'])->toBe('R2')->and($p['passo'])->toBe(PassoProposto::COMPENSA)->and($p['rata_compensa'])->toBe(12)
        ->and($p['frase'])->toBe('Copre Rata 12 in scadenza il 05/09/2026 e altre 2 rate.')
        ->and($p['possibili'])->toContain(PassoProposto::COMPENSA);
});

it('il credito di un\'altra gestione non dà «Compensa»: la frase del credito è una nota e la regola va avanti', function () {
    $credito = ['importo_cents' => 0, 'debito_altrove_cents' => 30000, 'rate_coperte' => [],
        'frase' => 'Le rate aperte sono su un\'altra gestione: la compensazione è possibile, ma va confermata da te perché attraversa ordinaria e straordinaria.'];
    $p = pptPer(pptPersona(['credito' => $credito]));

    expect($p['caso'])->toBe('R15')->and($p['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($p['note'])->toContain($credito['frase'])
        ->and($p['possibili'])->not->toContain(PassoProposto::COMPENSA);
});

it('R3: l\'accordo in corso copre tutte le rate aperte: nessun passo, «Vedi l\'accordo» fra i possibili; senza calendario la data e «fino al», con «invariato» la frase dei versamenti', function () {
    $rate = [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['in_accordo' => 10000, 'fuori_dalle_lettere' => true]),
        pptRata('13|1', 10000, '2026-09-05', '2027-06-30', ['in_accordo' => 10000, 'fuori_dalle_lettere' => true])];
    $segno = ['data_accordo' => '2026-01-10', 'fino_al' => '2026-12-30', 'invariato' => false, 'fino_al_passato' => false, 'calendario' => null, 'rate_non_versate' => null,
        'segnali' => ['rate' => null, 'giorni' => null]];
    $p = pptPer(pptPersona(['rate' => $rate, 'accordi' => [$segno]]));
    $invariato = pptPer(pptPersona(['rate' => $rate, 'accordi' => [['invariato' => true] + $segno]]));

    expect($p['caso'])->toBe('R3')->and($p['passo'])->toBeNull()
        ->and($p['frase'])->toBe('Accordo di rientro del 10/01/2026, fino al 30/12/2026.')
        ->and($p['possibili'])->toContain(PassoProposto::ACCORDO)
        ->and($invariato['frase'])->toBe('Accordo di rientro del 10/01/2026, fino al 30/12/2026. Dall\'accordo del 10/01/2026 nessun versamento sulle sue rate.')
        ->and($invariato['passo'])->toBeNull();
});

it('R3 con il calendario: la prima rata dell\'accordo scaduta e non versata, il segnale della decadenza, mai un passo in più', function () {
    $rate = [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['in_accordo' => 10000, 'fuori_dalle_lettere' => true])];
    $base = ['data_accordo' => '2026-01-10', 'fino_al' => '2026-12-30', 'invariato' => false, 'fino_al_passato' => false, 'segnali' => ['rate' => null, 'giorni' => null]];
    $nonVersata = $base + ['rate_non_versate' => 1, 'calendario' => [
        ['numero' => 1, 'data' => '2026-01-31', 'scaduta' => true, 'versata' => false, 'mancano' => 5000],
        ['numero' => 2, 'data' => '2026-12-30', 'scaduta' => false, 'versata' => false, 'mancano' => 5000]]];
    $versate = $base + ['rate_non_versate' => 0, 'calendario' => [['numero' => 1, 'data' => '2026-01-31', 'scaduta' => true, 'versata' => true, 'mancano' => 0]]];
    $conSegnale = ['segnali' => ['rate' => null, 'giorni' => ['il' => '2026-02-15', 'smentito' => false]]] + $nonVersata;

    expect(pptPer(pptPersona(['rate' => $rate, 'accordi' => [$nonVersata]]))['frase'])->toBe('La rata dell\'accordo del 31/01/2026 non è versata.')
        ->and(pptPer(pptPersona(['rate' => $rate, 'accordi' => [$versate]]))['frase'])->toBe('Accordo di rientro del 10/01/2026, fino al 30/12/2026: le rate scadute sono versate.')
        ->and(pptPer(pptPersona(['rate' => $rate, 'accordi' => [$conSegnale]]))['frase'])
        ->toBe('La rata dell\'accordo del 31/01/2026 non è versata. La condizione di decadenza scritta nell\'accordo si è verificata il 15/02/2026.')
        ->and(pptPer(pptPersona(['rate' => $rate, 'accordi' => [$conSegnale]]))['passo'])->toBeNull();
});

it('R4: l\'accordo con «fino al» passato e le sue rate ancora aperte: la frase, e la tabella su tutte le rate', function () {
    $rate = [pptRata('12|1', 30000, '2026-09-05', '2027-06-30', ['in_accordo' => 30000, 'fuori_dalle_lettere' => false])];
    $segno = ['data_accordo' => '2026-01-10', 'fino_al' => '2026-04-30', 'invariato' => false, 'fino_al_passato' => true, 'calendario' => null, 'rate_non_versate' => null,
        'segnali' => ['rate' => null, 'giorni' => null]];
    $p = pptPer(pptPersona(['rate' => $rate, 'accordi' => [$segno]]));

    expect($p['note'])->toContain('L\'accordo arrivava fino al 30/04/2026: delle sue rate restano € 300,00.')
        ->and($p['caso'])->toBe('R15')->and($p['passo'])->toBe(PassoProposto::SOLLECITO);
});

it('R5: l\'accordo copre una parte: la tabella sulla parte fuori, con la frase della cifra fuori', function () {
    $rate = [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['in_accordo' => 10000, 'fuori_dalle_lettere' => true]), pptRata('13|1', 34000)];
    $segno = ['data_accordo' => '2026-01-10', 'fino_al' => '2026-12-30', 'invariato' => false, 'fino_al_passato' => false, 'calendario' => null, 'rate_non_versate' => null,
        'segnali' => ['rate' => null, 'giorni' => null]];
    $p = pptPer(pptPersona(['rate' => $rate, 'accordi' => [$segno]]));

    expect($p['note'])->toContain('Fuori dall\'accordo: € 340,00.')->and($p['caso'])->toBe('R15');
});

it('R6: il fascicolo preparato dopo l\'ultima lettera propone «Registra che hai agito»', function () {
    $p = pptPer(pptPersona(['fascicolo' => '2026-10-20', 'lettere' => ['diffida' => pptLettera('diffida', 'spedita', '2026-09-01'), 'sollecito' => null, 'promemoria' => null]]));

    expect($p['caso'])->toBe('R6')->and($p['passo'])->toBe(PassoProposto::AGITO)->and($p['frase'])->toBe('Fascicolo preparato il 20/10/2026.');
});

it('R7: l\'ultima lettera stampata e non spedita propone «Registra la spedizione»', function () {
    $p = pptPer(pptPersona(['lettere' => ['diffida' => pptLettera('diffida', 'stampata', '2026-05-10'), 'sollecito' => null, 'promemoria' => null]]));
    $s = pptPer(pptPersona(['lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'stampata', '2026-10-10'), 'promemoria' => null]]));

    expect($p['caso'])->toBe('R7')->and($p['passo'])->toBe(PassoProposto::SPEDIZIONE)->and($p['frase'])->toBe('Diffida stampata il 10/05/2026: la spedizione non è registrata.')
        ->and($p['possibili'])->toContain(PassoProposto::SPEDIZIONE)
        ->and($s['frase'])->toBe('Sollecito stampato il 10/10/2026: la spedizione non è registrata.');
});

it('R8 e R9: la diffida spedita che chiede tutto, con il termine il giorno stesso non passato e il giorno dopo passato', function () {
    // Spedita il 30/10/2026 con 15 giorni: il termine è il 14/11/2026, oggi.
    $diffida = pptLettera('diffida', 'spedita', '2026-10-30');
    $oggi = pptPer(pptPersona(['lettere' => ['diffida' => $diffida, 'sollecito' => null, 'promemoria' => null]]));
    $domani = PassoProposto::per(pptPersona(['lettere' => ['diffida' => $diffida, 'sollecito' => null, 'promemoria' => null]]), '2026-11-15');

    expect($oggi['caso'])->toBe('R8')->and($oggi['passo'])->toBeNull()->and($oggi['frase'])->toBe('Diffida spedita il 30/10/2026: il termine è il 14/11/2026.')
        ->and($oggi['termine'])->toBe('15 giorni dal ricevimento: contati dalla spedizione del 30/10/2026.')
        ->and($domani['caso'])->toBe('R9')->and($domani['passo'])->toBe(PassoProposto::FASCICOLO)
        ->and($domani['frase'])->toBe('Diffida spedita il 30/10/2026: il termine del 14/11/2026 è passato.');
});

it('una diffida spedita e poi un sollecito per una rata davvero nuova (fuori dalla diffida): con la salita il fascicolo e la nota; ricominciando la diffida; mai il sollecito', function () {
    $diffida = pptLettera('diffida', 'spedita', '2026-09-01');
    $sollecito = pptLettera('sollecito', 'inviata', '2026-11-09', ['righe' => ['15|1']]);
    $persona = fn (?string $salita) => pptPersona(['rate' => [pptRata('12|1'), pptRata('13|1'), pptRata('14|1'), pptRata('15|1', 10000, '2026-10-05', '2027-06-30', ['giorni_ritardo' => 40])],
        'lettere' => ['diffida' => $diffida, 'sollecito' => $sollecito, 'promemoria' => null], 'salita' => $salita]);
    $avanti = pptPer($persona(null));
    $ricomincia = pptPer($persona(PassoProposto::SALITA_RICOMINCIA));

    expect($avanti['caso'])->toBe('R9')->and($avanti['passo'])->toBe(PassoProposto::FASCICOLO)
        ->and($avanti['frase'])->toBe('Diffida spedita il 01/09/2026: il termine del 16/09/2026 è passato.')
        ->and($avanti['note'])->toBe(['La diffida del 01/09/2026 non comprende € 100,00 di rate scadute dopo.'])
        ->and($ricomincia['caso'])->toBe('R10')->and($ricomincia['passo'])->toBe(PassoProposto::DIFFIDA)
        ->and(pptPer($persona(null), PassoProposto::TESORERIA)['passo'])->not->toBe(PassoProposto::SOLLECITO)
        ->and(pptPer($persona(PassoProposto::SALITA_RICOMINCIA), PassoProposto::TESORERIA)['passo'])->not->toBe(PassoProposto::SOLLECITO);
});

it('R9b: la diffida spedita senza un termine non propone il fascicolo, che resta a portata', function () {
    $p = pptPer(pptPersona(['lettere' => ['diffida' => pptLettera('diffida', 'spedita', '2026-10-03', ['termine' => null, 'termine_forma' => 'nessuno']), 'sollecito' => null, 'promemoria' => null]]));

    expect($p['caso'])->toBe('R9b')->and($p['passo'])->toBeNull()
        ->and($p['frase'])->toBe('La diffida spedita il 03/10/2026 non aveva un termine: quando preparare il fascicolo lo decidi tu.')
        ->and($p['possibili'])->toContain(PassoProposto::FASCICOLO)->toContain(PassoProposto::AGITO);
});

it('il termine con la data scritta nella lettera, e una lettera vecchia con i giorni senza la forma', function () {
    $conData = pptLettera('messa_in_mora', 'spedita', '2026-10-03', ['termine' => null, 'termine_forma' => 'data', 'termine_data' => '2026-11-20']);
    $vecchia = pptLettera('diffida', 'spedita', '2026-10-03', ['termine' => 10, 'termine_forma' => null]);

    expect(pptPer(pptPersona(['lettere' => ['diffida' => $conData, 'sollecito' => null, 'promemoria' => null]]))['frase'])->toBe('Messa in mora spedita il 03/10/2026: il termine è il 20/11/2026.')
        ->and(pptPer(pptPersona(['lettere' => ['diffida' => $vecchia, 'sollecito' => null, 'promemoria' => null]]))['frase'])->toBe('Diffida spedita il 03/10/2026: il termine del 13/10/2026 è passato.');
});

it('R10, ricominciando: la diffida che non chiede le rate scadute dopo propone una diffida nuova, con la cifra lasciata fuori', function () {
    $diffida = pptLettera('diffida', 'spedita', '2025-10-03', ['righe' => ['12|1', '13|1']]);
    $p = pptPer(pptPersona(['rate' => [pptRata('12|1'), pptRata('13|1'), pptRata('14|1', 22000)], 'lettere' => ['diffida' => $diffida, 'sollecito' => null, 'promemoria' => null],
        'salita' => PassoProposto::SALITA_RICOMINCIA]));

    expect($p['caso'])->toBe('R10')->and($p['passo'])->toBe(PassoProposto::DIFFIDA)->and($p['frase'])->toBe('La diffida del 03/10/2025 non comprende € 220,00 di rate scadute dopo.');
});

it('con la salita (di serie) la diffida resta il suo caso anche con le rate scadute dopo: il termine passato propone il fascicolo, con la nota delle rate dopo', function () {
    $diffida = pptLettera('diffida', 'spedita', '2025-10-03', ['righe' => ['12|1', '13|1']]);
    $termineNonPassato = pptLettera('diffida', 'spedita', '2026-11-10', ['righe' => ['12|1', '13|1']]);
    $rate = [pptRata('12|1'), pptRata('13|1'), pptRata('14|1', 22000)];
    $p = pptPer(pptPersona(['rate' => $rate, 'lettere' => ['diffida' => $diffida, 'sollecito' => null, 'promemoria' => null]]));
    $attesa = pptPer(pptPersona(['rate' => $rate, 'lettere' => ['diffida' => $termineNonPassato, 'sollecito' => null, 'promemoria' => null], 'salita' => PassoProposto::SALITA_AVANTI]));

    expect($p['caso'])->toBe('R9')->and($p['passo'])->toBe(PassoProposto::FASCICOLO)
        ->and($p['frase'])->toBe('Diffida spedita il 03/10/2025: il termine del 18/10/2025 è passato.')
        ->and($p['note'])->toBe(['La diffida del 03/10/2025 non comprende € 220,00 di rate scadute dopo.'])
        ->and($attesa['caso'])->toBe('R8')->and($attesa['passo'])->toBeNull()
        ->and($attesa['note'])->toBe(['La diffida del 10/11/2026 non comprende € 220,00 di rate scadute dopo.']);
});

it('R11: senza diffida, con la data dei sei mesi entro 60 giorni o passata, «Manda la diffida»; con un sollecito prima, «Nessuna diffida»', function () {
    $vicine = [pptRata('12|1', 10000, '2026-05-05', '2027-01-13'), pptRata('13|1', 10000, '2026-06-05', '2027-06-30')];
    $p = pptPer(pptPersona(['rate' => $vicine]));
    $sessantuno = pptPer(pptPersona(['rate' => [pptRata('12|1', 10000, '2026-05-05', '2027-01-14')]]));
    $conSollecito = pptPer(pptPersona(['rate' => $vicine, 'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'inviata', '2026-10-01'), 'promemoria' => null]]));
    $passata = pptPer(pptPersona(['rate' => [pptRata('12|1', 10000, '2025-05-05', '2026-06-30')]]));

    expect($p['caso'])->toBe('R11')->and($p['passo'])->toBe(PassoProposto::DIFFIDA)->and($p['frase'])->toBe('Nessuna lettera; la data dei sei mesi è il 13/01/2027.')
        ->and($sessantuno['caso'])->toBe('R15')
        ->and($conSollecito['frase'])->toBe('Nessuna diffida; la data dei sei mesi è il 13/01/2027.')
        ->and($passata['caso'])->toBe('R11');
});

it('R12 e R13: il sollecito che chiede tutto, con la data lontana, spedito o partito per email, con e senza termine', function () {
    $spedito = pptLettera('sollecito', 'spedita', '2026-11-01');
    $email = pptLettera('sollecito', 'inviata', '2026-11-01');
    $senza = pptLettera('sollecito', 'spedita', '2026-09-12', ['termine' => null, 'termine_forma' => 'nessuno']);
    $passato = pptLettera('sollecito', 'spedita', '2026-09-12');
    $l = fn ($s) => pptPer(pptPersona(['lettere' => ['diffida' => null, 'sollecito' => $s, 'promemoria' => null]]));

    expect($l($spedito)['caso'])->toBe('R12')->and($l($spedito)['passo'])->toBeNull()->and($l($spedito)['frase'])->toBe('Sollecito spedito il 01/11/2026: il termine è il 16/11/2026.')
        ->and($l($email)['frase'])->toBe('Sollecito partito per email il 01/11/2026: il termine è il 16/11/2026.')
        ->and($l($email)['termine'])->toBe('15 giorni dal ricevimento: contati dall\'invio del 01/11/2026.')
        ->and($l($senza)['caso'])->toBe('R12')->and($l($senza)['frase'])->toBe('Sollecito spedito il 12/09/2026, senza un termine.')
        ->and($l($passato)['caso'])->toBe('R13')->and($l($passato)['passo'])->toBe(PassoProposto::DIFFIDA)
        ->and($l($passato)['frase'])->toBe('Sollecito spedito il 12/09/2026: il termine del 27/09/2026 è passato.');
});

it('R14: il sollecito per email non partito propone di rifarlo; in coda, nessun passo', function () {
    $l = fn ($s) => pptPer(pptPersona(['lettere' => ['diffida' => null, 'sollecito' => $s, 'promemoria' => null]]));

    expect($l(pptLettera('sollecito', 'non_partita', '2026-09-12'))['caso'])->toBe('R14')
        ->and($l(pptLettera('sollecito', 'non_partita', '2026-09-12'))['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($l(pptLettera('sollecito', 'non_partita', '2026-09-12'))['frase'])->toBe('Il sollecito del 12/09/2026 non è partito.')
        ->and($l(pptLettera('sollecito', 'in_coda', '2026-09-12'))['passo'])->toBeNull()
        ->and($l(pptLettera('sollecito', 'in_coda', '2026-09-12'))['frase'])->toBe('Il sollecito del 12/09/2026 è in coda per l\'email.');
});

it('R15: nessuna lettera, solo un promemoria, o un sollecito che non chiede le rate scadute dopo', function () {
    expect(pptPer(pptPersona())['caso'])->toBe('R15')
        ->and(pptPer(pptPersona())['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and(pptPer(pptPersona())['frase'])->toBe('Nessuna lettera.')
        ->and(pptPer(pptPersona(['lettere' => ['diffida' => null, 'sollecito' => null, 'promemoria' => pptLettera('promemoria', 'inviata', '2026-09-01')]]))['frase'])
        ->toBe('Solo un promemoria, il 01/09/2026.')
        ->and(pptPer(pptPersona(['rate' => [pptRata('12|1'), pptRata('13|1'), pptRata('14|1'), pptRata('15|1', 22000)],
            'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'inviata', '2026-11-12'), 'promemoria' => null], 'salita' => PassoProposto::SALITA_RICOMINCIA]))['frase'])
        ->toBe('Il sollecito del 12/11/2026 non comprende € 220,00 di rate scadute dopo.');
});

it('con la salita (di serie) il sollecito resta il suo caso anche con le rate scadute dopo: si aspetta il termine, poi la diffida, con la nota', function () {
    $rate = [pptRata('12|1'), pptRata('13|1'), pptRata('14|1'), pptRata('15|1', 22000)];
    $aspetta = pptPer(pptPersona(['rate' => $rate, 'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'inviata', '2026-11-12'), 'promemoria' => null]]));
    $passato = pptPer(pptPersona(['rate' => $rate, 'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'spedita', '2026-09-12'), 'promemoria' => null]]));

    expect($aspetta['caso'])->toBe('R12')->and($aspetta['passo'])->toBeNull()
        ->and($aspetta['frase'])->toBe('Sollecito partito per email il 12/11/2026: il termine è il 27/11/2026.')
        ->and($aspetta['note'])->toBe(['Il sollecito del 12/11/2026 non comprende € 220,00 di rate scadute dopo.'])
        ->and($passato['caso'])->toBe('R13')->and($passato['passo'])->toBe(PassoProposto::DIFFIDA)
        ->and($passato['note'])->toBe(['Il sollecito del 12/09/2026 non comprende € 220,00 di rate scadute dopo.']);
});

/**
 * Il caso trovato con le rate mensili: le rate del 05/09 e del 25/09 chieste da un sollecito spedito il 01/10 (termine passato) e una rata del 10/11,
 * scaduta da 4 giorni, con 15 giorni di tolleranza. Prima la persona tornava a «Manda un sollecito» per la sola rata nuova.
 */
function pptP4(?string $salita, string $scadenzaNuova = '2026-11-10', int $giorni = 4): array
{
    return pptPer(pptPersona(['tolleranza' => 15, 'salita' => $salita,
        'rate' => [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['giorni_ritardo' => 70]), pptRata('13|1', 10000, '2026-09-25', '2027-06-30', ['giorni_ritardo' => 50]),
            pptRata('14|1', 10000, $scadenzaNuova, '2027-06-30', ['giorni_ritardo' => $giorni])],
        'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'spedita', '2026-10-01', ['righe' => ['12|1', '13|1']]), 'promemoria' => null]]));
}

it('la rata nuova nella tolleranza non toglie al sollecito il suo caso, con la salita e ricominciando: «Manda la diffida», e la nota lo dice', function () {
    foreach ([null, PassoProposto::SALITA_AVANTI, PassoProposto::SALITA_RICOMINCIA] as $salita) {
        $p = pptP4($salita);
        expect($p['caso'])->toBe('R13')->and($p['passo'])->toBe(PassoProposto::DIFFIDA)
            ->and($p['frase'])->toBe('Sollecito spedito il 01/10/2026: il termine del 16/10/2026 è passato.')
            ->and($p['note'])->toBe(['La rata del 10/11/2026, € 100,00, non è nel sollecito: è nella tolleranza.']);
    }
});

it('la rata nuova oltre la tolleranza: con la salita la diffida, con la nota; ricominciando il sollecito nuovo', function () {
    $avanti = pptP4(PassoProposto::SALITA_AVANTI, '2026-10-20', 25);
    $ricomincia = pptP4(PassoProposto::SALITA_RICOMINCIA, '2026-10-20', 25);

    expect($avanti['caso'])->toBe('R13')->and($avanti['passo'])->toBe(PassoProposto::DIFFIDA)
        ->and($avanti['note'])->toBe(['Il sollecito del 01/10/2026 non comprende € 100,00 di rate scadute dopo.'])
        ->and($ricomincia['caso'])->toBe('R15')->and($ricomincia['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($ricomincia['frase'])->toBe('Il sollecito del 01/10/2026 non comprende € 100,00 di rate scadute dopo.');
});

it('più rate nuove nella tolleranza: una nota sola, con i giorni e la cifra; e sulla diffida la stessa nota, «nella diffida»', function () {
    $rate = [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['giorni_ritardo' => 70]), pptRata('13|1', 10000, '2026-11-08', '2027-06-30', ['giorni_ritardo' => 6]),
        pptRata('14|1', 10000, '2026-11-10', '2027-06-30', ['giorni_ritardo' => 4])];
    $sollecito = pptPer(pptPersona(['tolleranza' => 15, 'salita' => PassoProposto::SALITA_RICOMINCIA, 'rate' => $rate,
        'lettere' => ['diffida' => null, 'sollecito' => pptLettera('sollecito', 'spedita', '2026-10-01', ['righe' => ['12|1']]), 'promemoria' => null]]));
    $diffida = pptPer(pptPersona(['tolleranza' => 15, 'salita' => PassoProposto::SALITA_RICOMINCIA, 'rate' => $rate,
        'lettere' => ['diffida' => pptLettera('diffida', 'spedita', '2026-10-01', ['righe' => ['12|1']]), 'sollecito' => null, 'promemoria' => null]]));

    expect($sollecito['caso'])->toBe('R13')
        ->and($sollecito['note'])->toBe(['Le rate del 08/11/2026 e del 10/11/2026, € 200,00, non sono nel sollecito: sono nella tolleranza.'])
        ->and($diffida['caso'])->toBe('R9')->and($diffida['passo'])->toBe(PassoProposto::FASCICOLO)
        ->and($diffida['note'])->toBe(['Le rate del 08/11/2026 e del 10/11/2026, € 200,00, non sono nella diffida: sono nella tolleranza.']);
});

it('senza la data dei sei mesi la persona va come una data lontana, e la frase dice perché la data manca', function () {
    $perche = 'L\'esercizio che contiene il 15/01/2027 non c\'è: questa data non si calcola.';
    $p = pptPer(pptPersona(['rate' => [pptRata('12|1', 10000, '2026-09-05', null, ['senza_data' => $perche])]]));

    expect($p['caso'])->toBe('R15')->and($p['passo'])->toBe(PassoProposto::SOLLECITO)->and($p['frase'])->toBe('Nessuna lettera. ' . $perche);
});

it('la dispensa su tutte le rate aperte: nel riquadro nessun passo; in tesoreria toglie i passi verso il legale, e con la casella anche il sollecito', function () {
    $d = ['id' => 1, 'data_delibera' => '2026-03-15', 'numero_verbale' => '2', 'revocata_il' => null, 'togli_sollecito' => false];
    $vicine = [pptRata('12|1', 10000, '2026-05-05', '2027-01-13', ['dispensa' => $d])];
    $lontane = [pptRata('12|1', 10000, '2026-05-05', '2027-06-30', ['dispensa' => $d])];
    $conCasella = [pptRata('12|1', 10000, '2026-05-05', '2027-06-30', ['dispensa' => ['togli_sollecito' => true] + $d])];

    $riquadro = pptPer(pptPersona(['rate' => $vicine]));
    $tesoreriaVicina = pptPer(pptPersona(['rate' => $vicine]), PassoProposto::TESORERIA);
    $tesoreriaLontana = pptPer(pptPersona(['rate' => $lontane]), PassoProposto::TESORERIA);
    $tesoreriaCasella = pptPer(pptPersona(['rate' => $conCasella]), PassoProposto::TESORERIA);

    expect($riquadro['caso'])->toBe('dispensa')->and($riquadro['passo'])->toBeNull()
        ->and($riquadro['frase'])->toBe('Dispensa dell\'assemblea del 15/03/2026 (verbale n. 2): per queste rate il programma non suggerisce azioni.')
        ->and($riquadro['possibili'])->toContain(PassoProposto::SOLLECITO)->toContain(PassoProposto::DIFFIDA)
        ->and($tesoreriaVicina['passo'])->toBeNull()->and($tesoreriaVicina['frase'])->toBe('Dispensa dell\'assemblea del 15/03/2026: niente diffida né fascicolo.')
        ->and($tesoreriaLontana['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($tesoreriaCasella['passo'])->toBeNull()->and($tesoreriaCasella['frase'])->toBe('Dispensa dell\'assemblea del 15/03/2026: niente solleciti, come hai scelto.');
});

it('la tolleranza del condominio: dentro, nessun sollecito e il giorno da cui si propone; fuori, il sollecito; la data vicina vale lo stesso', function () {
    // La rata scade il 05/11/2026: oggi è in ritardo di 9 giorni.
    $dentro = [pptRata('12|1', 10000, '2026-11-05', '2027-06-30', ['giorni_ritardo' => 9])];
    $fuori = [pptRata('12|1', 10000, '2026-10-05', '2027-06-30', ['giorni_ritardo' => 40])];
    $vicina = [pptRata('12|1', 10000, '2026-11-05', '2026-12-30', ['giorni_ritardo' => 9])];

    $p = pptPer(pptPersona(['rate' => $dentro, 'tolleranza' => 15]));

    expect($p['caso'])->toBe('R15')->and($p['passo'])->toBeNull()
        ->and($p['frase'])->toBe('Nella tolleranza di 15 giorni del condominio: il sollecito si propone dal 21/11/2026.')
        ->and(pptPer(pptPersona(['rate' => $fuori, 'tolleranza' => 15]))['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and(pptPer(pptPersona(['rate' => $vicina, 'tolleranza' => 15]))['passo'])->toBe(PassoProposto::DIFFIDA);
});

it('la tolleranza è la stessa condizione del suggerimento «oltre la tolleranza»: una funzione sola', function () {
    $rate = [['scadenza' => '2026-11-05', 'giorni_ritardo' => 9, 'residuo' => 10000, 'accordo' => null], ['scadenza' => '2026-10-05', 'giorni_ritardo' => 40, 'residuo' => 10000, 'accordo' => null],
        ['scadenza' => '2026-01-05', 'giorni_ritardo' => null, 'residuo' => 10000, 'accordo' => ['in_accordo' => 10000, 'fuori_dalle_lettere' => true]]];

    expect(SuggerimentiDelRecupero::scadenzeOltreLaTolleranza($rate, 15))->toBe(['2026-10-05'])
        ->and(SuggerimentiDelRecupero::scadenzeOltreLaTolleranza($rate, 60))->toBe([]);
});

it('i passi possibili, in ordine, in ogni caso; in tesoreria senza «Registra che hai agito»', function () {
    $base = pptPer(pptPersona());
    $conTutto = pptPer(pptPersona([
        'lettere' => ['diffida' => pptLettera('diffida', 'stampata', '2026-05-10'), 'sollecito' => null, 'promemoria' => null],
        'accordi' => [['data_accordo' => '2026-01-10', 'fino_al' => '2026-12-30', 'invariato' => false, 'fino_al_passato' => false, 'calendario' => null, 'rate_non_versate' => null,
            'segnali' => ['rate' => null, 'giorni' => null]]],
        'credito' => ['importo_cents' => 5000, 'debito_altrove_cents' => 0, 'frase' => 'Copre in parte Rata 12.', 'rate_coperte' => [['rata_id' => 12, 'copre_tutto' => false]]],
    ]));

    expect($base['possibili'])->toBe([PassoProposto::SOLLECITO, PassoProposto::DIFFIDA, PassoProposto::FASCICOLO, PassoProposto::AGITO])
        ->and($conTutto['possibili'])->toBe([PassoProposto::SOLLECITO, PassoProposto::DIFFIDA, PassoProposto::SPEDIZIONE, PassoProposto::FASCICOLO, PassoProposto::ACCORDO,
            PassoProposto::COMPENSA, PassoProposto::AGITO])
        ->and(pptPer(pptPersona(), PassoProposto::TESORERIA)['possibili'])->not->toContain(PassoProposto::AGITO);
});

it('l\'ordine fra i casi: il primo che va bene vince (R0 prima del credito, il credito prima dell\'accordo, la spedizione prima della diffida)', function () {
    $credito = ['importo_cents' => 30000, 'debito_altrove_cents' => 0, 'frase' => 'Copre.', 'rate_coperte' => [['rata_id' => 12, 'copre_tutto' => true]]];
    $rataInAccordo = [pptRata('12|1', 10000, '2026-09-05', '2027-06-30', ['in_accordo' => 10000, 'fuori_dalle_lettere' => true])];
    $segno = ['data_accordo' => '2026-01-10', 'fino_al' => '2026-12-30', 'invariato' => false, 'fino_al_passato' => false, 'calendario' => null, 'rate_non_versate' => null,
        'segnali' => ['rate' => null, 'giorni' => null]];

    expect(pptPer(pptPersona(['rate' => [], 'agito' => [['giorno' => '2026-03-12', 'nota' => 'x', 'utente' => 'V']], 'credito' => $credito]))['caso'])->toBe('R0')
        ->and(pptPer(pptPersona(['rate' => $rataInAccordo, 'credito' => $credito, 'accordi' => [$segno]]))['caso'])->toBe('R2')
        ->and(pptPer(pptPersona(['lettere' => ['diffida' => pptLettera('diffida', 'stampata', '2026-11-01'), 'sollecito' => pptLettera('sollecito', 'spedita', '2026-09-01'), 'promemoria' => null]]))['caso'])
        ->toBe('R7');
});

it('le frasi della regola non usano parole vietate né dicono che qualcuno deve o è in ritardo', function () {
    $frasi = implode(' ', array_map(fn ($c) => $c['frase'] . ' ' . implode(' ', $c['note']), [
        pptPer(pptPersona()), pptPer(pptPersona(['lettere' => ['diffida' => pptLettera('diffida', 'spedita', '2026-09-01'), 'sollecito' => null, 'promemoria' => null]])),
        pptPer(pptPersona(['rate' => [pptRata('12|1', 10000, '2026-05-05', '2027-01-13')]])),
    ]));

    foreach (['errore', 'in ritardo', 'deve agire', 'esigibile', 'ingiungibile', 'moros', 'diffidat', 'decadut', 'termine scaduto'] as $parola) {
        expect(mb_strtolower($frasi))->not->toContain($parola);
    }
});

it('«Compensa» fra i possibili porta sempre la prima rata che il credito copre, anche quando non è il passo proposto', function () {
    $credito = ['importo_cents' => 5000, 'debito_altrove_cents' => 0, 'frase' => 'Copre in parte Rata 12 in scadenza il 05/09/2026.',
        'rate_coperte' => [['rata_id' => 12, 'copre_tutto' => false]]];
    $p = pptPer(pptPersona(['credito' => $credito]));

    expect($p['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($p['possibili'])->toContain(PassoProposto::COMPENSA)
        ->and($p['rata_compensa'])->toBe(12)
        ->and(pptPer(pptPersona())['rata_compensa'])->toBeNull();
});

it('la frase dei fatti: nella tolleranza dice fin quando, senza proporre; negli altri casi è la frase', function () {
    $dentro = pptPer(pptPersona(['rate' => [pptRata('12|1', 10000, '2026-11-05', '2027-06-30', ['giorni_ritardo' => 9])], 'tolleranza' => 15]));

    expect($dentro['frase'])->toBe('Nella tolleranza di 15 giorni del condominio: il sollecito si propone dal 21/11/2026.')
        ->and($dentro['frase_dei_fatti'])->toBe('Nella tolleranza di 15 giorni del condominio, fino al 20/11/2026.')
        ->and(pptPer(pptPersona())['frase_dei_fatti'])->toBe('Nessuna lettera.');
});

it('una lettera che non chiede più nessuna rata aperta (le sue sono state versate) non tiene il suo caso, con la salita o ricominciando', function () {
    $vecchio = pptLettera('sollecito', 'spedita', '2026-05-01', ['righe' => ['9|1']]);
    $vecchia = pptLettera('diffida', 'spedita', '2026-05-01', ['righe' => ['9|1']]);
    $nellaTolleranza = [pptRata('12|1', 10000, '2026-11-10', '2027-06-30', ['giorni_ritardo' => 4])];
    $l = fn (array $lettere, array $altro = []) => pptPer(pptPersona($altro + ['lettere' => $lettere + ['diffida' => null, 'sollecito' => null, 'promemoria' => null]]));

    expect($l(['sollecito' => $vecchio])['caso'])->toBe('R15')
        ->and($l(['sollecito' => $vecchio])['passo'])->toBe(PassoProposto::SOLLECITO)
        ->and($l(['diffida' => $vecchia])['caso'])->toBe('R10')
        ->and($l(['diffida' => $vecchia])['passo'])->toBe(PassoProposto::DIFFIDA)
        // Le sole rate aperte sono nella tolleranza: nessuna lettera di prima le chiede, e il programma aspetta.
        ->and($l(['sollecito' => $vecchio], ['rate' => $nellaTolleranza, 'tolleranza' => 15])['passo'])->toBeNull()
        ->and($l(['diffida' => $vecchia], ['rate' => $nellaTolleranza, 'tolleranza' => 15])['passo'])->toBeNull();
});
