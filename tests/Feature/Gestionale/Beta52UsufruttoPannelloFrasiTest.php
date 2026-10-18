<?php

/**
 * Cosa resta scoperto: l'estinzione, la vendita piena, la locazione e la successione (il blocco delle voci c'è solo alla costituzione e alla
 * riserva); il testo del modulo, che sta in `PassaggioNewVoceDiEntrata.test.ts`. Le frasi delle voci bloccate da un piano approvato e il
 * cancello della riserva sono in `Beta52UsufruttoEntrateBloccateTest.php`.
 *
 * Le frasi dell'ordinaria che l'anteprima della costituzione e della riserva d'usufrutto scrive (`ordinaria.frasi`), quando fra le voci che
 * passano dal «Proprietario» all'«Usufruttuario» c'è un'entrata. Il modulo ne mostra solo la prima (`PassaggioNew.vue`, `ordinaria.frasi[0]`,
 * dalla .41): le altre, compresa quella dell'art. 984 c.c., non arrivano a nessuna schermata (visto a video il 17/10/2026; Coda 280). A video
 * l'entrata si riconosce dal badge e dalla frase sotto la voce (`PassaggioNewVoceDiEntrata.test.ts`).
 *
 * Un'entrata passa all'«Usufruttuario» se è un frutto civile: l'art. 984 c.c. dà gli affitti e i canoni all'usufruttuario (se il titolo
 * dell'usufrutto dice altro, vale fra le parti); l'art. 1004 c.c. riguarda le spese. Ma una voce di entrata del programma non è solo un affitto o un canone (un rimborso, un
 * recupero spese non sono frutti civili): la frase lo dice in forma condizionale, al singolare o al plurale. L'elenco marca l'entrata; per le
 * spese le frasi restano com'erano.
 *
 * Le altre unità in usufrutto della tabella sono toccate dalla stessa voce: la frase delle spese («chi paga», con l'importo dell'ultimo
 * piano, che per le spese è il dovuto del nudo proprietario) e quella delle entrate («chi la riceve», senza importo: è un credito, e il segno
 * meno non dice niente a chi legge) contano ciascuna le sole voci del proprio tipo e le sole unità della tabella di quelle voci.
 *
 * Un usufrutto già nato con «come dice ogni voce» sulla stessa tabella, o con la legge, è toccato da una voce che si sposta: le due frasi
 * dicono «l'ordinaria», «l'entrata» o tutte e due, «paga», «riceve» o «paga o riceve», secondo il tipo delle voci; la frase descrive il
 * motore (il ruolo che la voce dice oggi), non la legge.
 *
 * Il modulo e questa frase dividono un frammento («frutto civile, come un affitto o un canone … se il titolo dell'usufrutto dice altro, vale fra le parti»): se si cambia
 * uno si cambia l'altro, e un test li confronta.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/Beta52UsufruttoScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

const B52US_FRASE_ENTRATA = 'L\'entrata Affitto antenna passa all\'«Usufruttuario»: se è un frutto civile, come un affitto o un canone, spetta all\'usufruttuario (art. 984 c.c.; se il titolo dell\'usufrutto dice altro, vale fra le parti); l\'art. 1004 c.c. riguarda le spese.';
const B52US_FRASE_ENTRATE = 'Le entrate Affitto antenna e Canone parcheggio passano all\'«Usufruttuario»: se sono frutti civili, come un affitto o un canone, spettano all\'usufruttuario (art. 984 c.c.; se il titolo dell\'usufrutto dice altro, vale fra le parti); l\'art. 1004 c.c. riguarda le spese.';
const B52US_CHI_PAGA_SU_INTERNO_2 = 'Una voce vale per tutta la tabella: cambia chi paga anche su Interno 2 (Usufruttuaria Nina), dal nudo proprietario all\'usufruttuario. Dove non c\'è usufrutto paga il proprietario, come prima.';
const B52US_CHI_RICEVE_SU_INTERNO_2 = 'Un\'entrata vale per tutta la tabella: cambia chi la riceve anche su Interno 2 (Usufruttuaria Nina), dal nudo proprietario all\'usufruttuario. Dove non c\'è usufrutto la riceve il proprietario, come prima.';

// --- L'elenco e la frase sull'art. 984 c.c. ------------------------------------------------------------------------------------------

it('controprova — la frase di apertura per le spese resta com\'è, con un\'entrata fra le voci', function () {
    $s = b52UsScenarioPannello();
    $frasi = b52UsFrasi($this, $s);

    expect(str_starts_with($frasi[0], 'Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario (art. 1004 c.c.): per le voci di oggi, nei piani generati o ricalcolati dopo.'))->toBeTrue($frasi[0]);
});

it('l\'elenco delle voci che passano marca l\'entrata e non marca la spesa', function () {
    $s = b52UsScenarioPannello();
    $testo = implode("\n", b52UsFrasi($this, $s));

    expect(str_contains($testo, 'Per i piani che verranno, queste voci passano dal «Proprietario» all\'«Usufruttuario»: Affitto antenna (Proprietà, Ordinaria 2026, entrata) e Spese generali (Proprietà, Ordinaria 2026).'))
        ->toBeTrue('L\'elenco non marca l\'entrata: ' . $testo);
});

it('una frase dice che l\'entrata passa se è un frutto civile, per l\'art. 984 c.c., non per l\'art. 1004 c.c.', function () {
    $s = b52UsScenarioPannello();
    $frasi = b52UsFrasi($this, $s);

    b52UsDicono($frasi, B52US_FRASE_ENTRATA);
});

it('due entrate che passano: la frase è al plurale', function () {
    $s = b52UsScenarioPannello();
    b52UsVoce($s, 'Canone parcheggio', 'entrata', 12000, ['proprietario' => 100]);
    $frasi = b52UsFrasi($this, $s);

    b52UsDicono($frasi, B52US_FRASE_ENTRATE);
});

it('controprova — con le sole spese nessuna frase cita l\'art. 984 c.c. né parla di entrate', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $testo = implode("\n", b52UsFrasi($this, $s));

    expect(str_contains($testo, '984'))->toBeFalse($testo)
        ->and(str_contains($testo, 'entrat'))->toBeFalse($testo)
        ->and(str_contains($testo, 'Per i piani che verranno, questa voce passa dal «Proprietario» all\'«Usufruttuario»: Spese generali (Proprietà, Ordinaria 2026).'))->toBeTrue($testo);
});

it('controprova — con la spunta tolta all\'entrata nessuna frase cita l\'art. 984 c.c. e la voce resta sul «Proprietario»', function () {
    $s = b52UsScenarioPannello();
    $testo = implode("\n", b52UsFrasi($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctmEntrata']]])));

    expect(str_contains($testo, 'art. 984'))->toBeFalse($testo)
        ->and(str_contains($testo, 'Resta sul «Proprietario» la voce Affitto antenna'))->toBeTrue($testo)
        ->and(str_contains($testo, 'passa all\'«Usufruttuario»'))->toBeFalse($testo);
});

it('controprova — con l\'entrata in un piano approvato nessuna frase dei passaggi cita l\'art. 984 c.c. per quella voce', function () {
    $s = b52UsScenarioPannello();
    $s['piano']->update(['stato' => 'approvato']);
    $s['piano']->capitoli()->syncWithoutDetaching([(int) DB::table('conto_tabella_millesimale')->where('id', $s['ctmEntrata'])->value('conto_id') => ['importo' => 24000]]);
    $testo = implode("\n", b52UsFrasi($this, $s));

    expect(str_contains($testo, 'art. 984 c.c.'))->toBeFalse($testo)
        ->and(str_contains($testo, 'passa all\'«Usufruttuario»'))->toBeFalse($testo);
});

it('il modulo e il server dicono la stessa cosa dell\'art. 984 c.c.: «frutto civile, come un affitto o un canone … se il titolo dell\'usufrutto dice altro, vale fra le parti»', function () {
    $s = b52UsScenarioPannello();
    $server = implode(' ', b52UsFrasi($this, $s));
    $modulo = preg_replace('/\s+/u', ' ', str_replace("\\'", "'", File::get(base_path('resources/js/pages/gestionale/immobili/anagrafiche/PassaggioNew.vue'))));

    $frammento = '/frutto civile, come un affitto o un canone,.{0,80}se il titolo dell\'usufrutto dice altro, vale fra le parti/u';
    expect(preg_match($frammento, $server))->toBe(1, 'Nella frase del server manca il frammento: ' . $server)
        ->and(preg_match($frammento, $modulo))->toBe(1, 'Nel modulo manca il frammento.')
        ->and(str_contains($modulo, 'art. 984 c.c.'))->toBeTrue()
        ->and(str_contains($modulo, 'Togli la spunta se deve restare al nudo proprietario'))->toBeTrue();
});

// --- Le altre unità in usufrutto della tabella --------------------------------------------------------------------------------------

it('le altre unità in usufrutto: per un\'entrata il pannello dice «chi la riceve», non «chi paga»', function () {
    $s = b52UsScenarioPannello();
    b52UsAltraUnita($s, b52UsTabella($s), 'Interno 2', 'Usufruttuaria Nina', 'Nudo Oreste');
    $frasi = b52UsFrasi($this, $s);

    b52UsDicono($frasi, B52US_CHI_PAGA_SU_INTERNO_2, B52US_CHI_RICEVE_SU_INTERNO_2);
});

it('con la sola entrata che passa la frase «cambia chi paga» non c\'è: c\'è quella di chi riceve', function () {
    $s = b52UsScenarioPannello();
    b52UsAltraUnita($s, b52UsTabella($s), 'Interno 2', 'Usufruttuaria Nina', 'Nudo Oreste');
    // La spunta tolta alle spese: passa solo l'entrata.
    $frasi = b52UsFrasi($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]));
    $testo = implode("\n", $frasi);

    expect(str_contains($testo, 'cambia chi paga anche su'))->toBeFalse($testo);
    b52UsDicono($frasi, B52US_CHI_RICEVE_SU_INTERNO_2);
});

it('controprova — con la sola spesa che passa la frase «chi la riceve» non c\'è: c\'è quella di chi paga', function () {
    $s = b52UsScenarioPannello();
    b52UsAltraUnita($s, b52UsTabella($s), 'Interno 2', 'Usufruttuaria Nina', 'Nudo Oreste');
    // La spunta tolta all'entrata: passa solo la spesa.
    $frasi = b52UsFrasi($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctmEntrata']]]));
    $testo = implode("\n", $frasi);

    expect(str_contains($testo, 'chi la riceve'))->toBeFalse($testo);
    b52UsDicono($frasi, B52US_CHI_PAGA_SU_INTERNO_2);
});

it('una spesa e un\'entrata su due tabelle diverse: la frase «chi paga» nomina l\'altra unità della tabella della spesa, quella «chi la riceve» l\'altra unità della tabella dell\'entrata', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    ruPianoInBozza($s);
    $proprieta = b52UsTabella($s);
    $box = b52UsSecondaTabella($s);
    b52UsAltraUnita($s, $proprieta, 'Interno 2', 'Usufruttuaria Nina', 'Nudo Oreste');
    b52UsAltraUnita($s, $box, 'Interno 3', 'Usufruttuaria Dina', 'Nudo Piero');
    b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, ['proprietario' => 100], $box);
    $frasi = b52UsFrasi($this, $s);

    b52UsDicono($frasi, B52US_CHI_PAGA_SU_INTERNO_2, 'Un\'entrata vale per tutta la tabella: cambia chi la riceve anche su Interno 3 (Usufruttuaria Dina), dal nudo proprietario all\'usufruttuario. Dove non c\'è usufrutto la riceve il proprietario, come prima.');
});

it('l\'importo dell\'ultimo piano è la sola somma delle spese: la frase delle entrate non porta cifre', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('quote_tabella')->where('tabella_id', b52UsTabella($s))->update(['valore' => 500]);
    b52UsAltraUnita($s, b52UsTabella($s), 'Interno 2', 'Usufruttuaria Nina', 'Nudo Oreste');
    b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, ['proprietario' => 100]);
    // Il piano si genera con tutte e due le voci: l'ultimo piano ha € 600,00 di spesa e € 120,00 di entrata per Interno 2.
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruPianoInBozza($s);
    $frasi = b52UsFrasi($this, $s);

    b52UsDicono($frasi, 'Una voce vale per tutta la tabella: cambia chi paga anche su Interno 2 (Usufruttuaria Nina), € 600,00 nell\'ultimo piano, dal nudo proprietario all\'usufruttuario. Dove non c\'è usufrutto paga il proprietario, come prima.', B52US_CHI_RICEVE_SU_INTERNO_2);
    expect(str_contains(implode("\n", $frasi), '€ -'))->toBeFalse(implode("\n", $frasi));
});

// --- Le frasi degli altri usufrutti della gestione, con un'entrata -------------------------------------------------------------------

it('un usufrutto nato «come dice ogni voce» sulla stessa tabella: per l\'entrata la frase dice «l\'entrata», non «l\'ordinaria»', function (bool $conLeSpese) {
    [$s, $dora, $rigaUgo, $ctmSpese] = b52UsDueUsufrutti($this, Subentro::ORDINARIA_COME_LA_VOCE);
    $dati = b52UsCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01', 'voci_da_tenere' => $conLeSpese ? [] : [$ctmSpese]]);
    $frasi = ruAnteprima($this, $s, $dati)['ordinaria']['frasi_altri_usufrutti'];

    $cosa = $conLeSpese ? 'l\'ordinaria o l\'entrata di quelle voci' : 'l\'entrata di quella voce';
    $spostando = $conLeSpese ? 'Affitto antenna e Spese generali' : 'Affitto antenna';
    expect($frasi)->toBe(['L\'usufrutto di Acquirente Elsa su Interno 1, dal 1 febbraio 2026 al 30 aprile 2026, è nato con la scelta «come dice ogni voce»: spostando ' . $spostando . ' sull\'«Usufruttuario», i piani della gestione «Ordinaria 2026» generati o ricalcolati dopo daranno ' . $cosa . ' a Acquirente Elsa, usufruttuario, anche per i giorni di quell\'usufrutto, e non più al nudo proprietario. Per lasciarla al nudo proprietario, togli la spunta alla voce qui sopra.']);
})->with(['con le spese' => [true], 'solo l\'entrata' => [false]]);

it('controprova — un usufrutto nato «come dice ogni voce» sulla stessa tabella: per le sole spese la frase dice «l\'ordinaria di quella voce» come prima', function () {
    [$s, $dora, $rigaUgo, $ctmSpese] = b52UsDueUsufrutti($this, Subentro::ORDINARIA_COME_LA_VOCE);
    // Passa solo la spesa: l'entrata resta sul «Proprietario».
    $dati = b52UsCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01', 'voci_da_tenere' => [$s['ctmEntrata']]]);
    $testo = implode("\n", ruAnteprima($this, $s, $dati)['ordinaria']['frasi_altri_usufrutti']);

    expect(str_contains($testo, 'spostando Spese generali sull\'«Usufruttuario», i piani della gestione «Ordinaria 2026» generati o ricalcolati dopo daranno l\'ordinaria di quella voce a Acquirente Elsa'))->toBeTrue($testo);
});

it('un usufrutto nato con la legge sulla stessa tabella: con «come dice ogni voce» l\'entrata «la riceve» l\'usufruttuario, le spese insieme all\'entrata «le paga o le riceve»', function (array $tieni) {
    [$s, $dora, $rigaUgo] = b52UsDueUsufrutti($this, Subentro::ORDINARIA_ALL_USUFRUTTUARIO, $tieni);
    $dati = b52UsCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01', 'ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]);
    $frasi = ruAnteprima($this, $s, $dati)['ordinaria']['frasi_altri_usufrutti'];

    // La frase descrive il motore (il ruolo che la voce dice oggi), non la legge: nessun richiamo all'art. 984 c.c.
    $attesa = $tieni === []
        ? 'Le voci Affitto antenna e Spese generali sono già sull\'«Usufruttuario» dall\'usufrutto di Acquirente Elsa su Interno 1, nato il 1 febbraio 2026 con la legge: con «come dice ogni voce» le paga o le riceve l\'usufruttuario anche qui, perché è il ruolo che la voce dice oggi.'
        : 'La voce Affitto antenna è già sull\'«Usufruttuario» dall\'usufrutto di Acquirente Elsa su Interno 1, nato il 1 febbraio 2026 con la legge: con «come dice ogni voce» la riceve l\'usufruttuario anche qui, perché è il ruolo che la voce dice oggi.';
    expect($frasi)->toBe([$attesa]);
})->with(['spese ed entrata' => [[]], 'solo l\'entrata' => [['spese']]]);

it('controprova — un usufrutto nato con la legge sulla stessa tabella: con «come dice ogni voce» le sole spese «le paga» l\'usufruttuario, come prima', function () {
    [$s, $dora, $rigaUgo] = b52UsDueUsufrutti($this, Subentro::ORDINARIA_ALL_USUFRUTTUARIO, ['entrata']);
    $dati = b52UsCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01', 'ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]);

    expect(ruAnteprima($this, $s, $dati)['ordinaria']['frasi_altri_usufrutti'])->toBe(['La voce Spese generali è già sull\'«Usufruttuario» dall\'usufrutto di Acquirente Elsa su Interno 1, nato il 1 febbraio 2026 con la legge: con «come dice ogni voce» la paga l\'usufruttuario anche qui, perché è il ruolo che la voce dice oggi.']);
});
