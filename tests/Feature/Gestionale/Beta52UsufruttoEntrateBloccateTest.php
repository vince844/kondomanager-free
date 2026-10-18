<?php

/**
 * Cosa resta scoperto: l'estinzione, la vendita piena, la locazione e la successione (il blocco delle voci c'è solo alla costituzione e alla
 * riserva); il richiamo all'art. 984 c.c. per una voce bloccata (la voce non si sposta, e la frase dell'art. 984 c.c. è di quelle che passano);
 * la frase del conguaglio, che per un'unità il cui ordinario netto è un'entrata cita ancora l'art. 1004 c.c.
 *
 * Le voci di entrata nelle frasi delle voci bloccate da un piano e nei motivi del cancello della riserva, e le frasi che aprono il pannello e lo
 * storico quando a passare sono solo entrate.
 *
 * Una voce compresa in un piano approvato non si sposta, e il pannello dice perché e che cosa fare: il rimedio dipende dal piano (senza niente
 * a giornale, con rate a giornale, da fatture, più piani insieme). Per una voce di entrata le stesse frasi la nominano con la sua etichetta
 * («Affitto antenna (Proprietà, Ordinaria 2026, entrata)») e dicono «l'entrata di quella voce», non «l'ordinaria»: l'art. 1004 c.c. riguarda
 * le spese. Con spese ed entrate insieme dicono «l'ordinaria o l'entrata». Le frasi delle sole spese sono quelle di `OrdinariaDopoAttoTest.php`,
 * e una controprova le tiene ferme anche qui.
 *
 * Il cancello della riserva elenca le voci che un piano ricalcolato darebbe al nudo proprietario. Se fra loro c'è un'entrata, l'elenco sta fra
 * trattini invece che fra parentesi (le etichette portano già le loro) e la clausola «fra le parti l'ordinaria è dell'usufruttuario (art.
 * 1004 c.c.)» diventa quella dell'entrata, che spetta all'usufruttuario se è un frutto civile.
 *
 * Quando le voci che passano sono solo entrate, la frase che il modulo mostra e l'apertura dello storico non citano l'art. 1004 c.c.: nessuna
 * spesa passa all'«Usufruttuario». Con una spesa fra le voci che passano citano l'art. 1004 c.c. come sempre.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52UsufruttoScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

const B52EB_ETICHETTA_ENTRATA = 'Affitto antenna (Proprietà, Ordinaria 2026, entrata)';
const B52EB_ETICHETTA_SPESA = 'Spese generali (Proprietà, Ordinaria 2026)';

/** Il conto di un'associazione voce × tabella. */
function b52EbConto(int $associazione): int
{
    return (int) DB::table('conto_tabella_millesimale')->where('id', $associazione)->value('conto_id');
}

/** Lo scenario del pannello con il piano approvato, che comprende nei capitoli l'entrata (bloccata) e, se si chiede, anche la spesa. */
function b52EbApprovato(bool $conLaSpesa = false): array
{
    $s = b52UsScenarioPannello();
    $s['piano']->update(['stato' => 'approvato']);
    $capitoli = [b52EbConto($s['ctmEntrata']) => ['importo' => 24000]];
    if ($conLaSpesa) {
        $capitoli[b52EbConto(b52UsAssociazioneSpese($s))] = ['importo' => 120000];
    }
    $s['piano']->capitoli()->syncWithoutDetaching($capitoli);

    return $s;
}

/** Come `b52EbApprovato`, con le prime quattro rate emesse a giornale: il piano non si ricalcola più. */
function b52EbAGiornale(): array
{
    $s = b52EbApprovato();
    ruEmetti($s);

    return $s;
}

/** Un piano straordinario con una fattura su una voce: la voce è bloccata anche dalle fatture del piano, se è approvato. */
function b52EbPianoDaFatture(array $s, int $conto, string $stato = 'approvato'): void
{
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Spese da finanziare', 'stato' => $stato,
        'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-07-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $fattura = FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-B52EB{$s['unita']->id}",
        'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 10000, 'netto_a_pagare' => 10000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $conto, 'immobile_id' => null, 'descrizione' => 'Canone', 'aliquota_iva' => 0,
        'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 10000]);
}

/**
 * Il piano è generato con la spesa e l'entrata nei capitoli, ancora in bozza: le sue righe di riparto intestano all'unità la parte delle
 * voci sul «Proprietario», e il cancello della riserva le conosce. Poi il piano prende lo stato dato, con i capitoli che restano.
 *
 * @param list<string> $capitoliDopo quali voci restano nei capitoli: «spese», «entrata», «canone» (la seconda entrata, se c'è)
 */
function b52EbConRighe(array $capitoliDopo = ['spese', 'entrata'], string $stato = 'approvato', ?array $ripartizioneEntrata = null, bool $conUnSecondoCanone = false): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    ruPianoInBozza($s);
    $s['ctmEntrata'] = b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, $ripartizioneEntrata ?? ['proprietario' => 100]);
    $conti = ['spese' => b52EbConto(b52UsAssociazioneSpese($s)), 'entrata' => b52EbConto($s['ctmEntrata'])];
    if ($conUnSecondoCanone) {
        $conti['canone'] = b52EbConto(b52UsVoce($s, 'Canone parcheggio', 'entrata', 12000, ['proprietario' => 100]));
    }
    $s['piano']->capitoli()->syncWithoutDetaching(collect($conti)->mapWithKeys(fn (int $id) => [$id => ['importo' => 10000]])->all());
    // Coefficienti che non fanno 100 lasciano una parte scoperta: la generazione chiede di accettarla.
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaScoperti: $ripartizioneEntrata !== null, notaScoperti: $ripartizioneEntrata !== null ? 'Coefficienti da correggere' : null, esercizio: $s['e']);
    $s['piano']->capitoli()->sync(collect($conti)->only($capitoliDopo)->mapWithKeys(fn (int $id) => [$id => ['importo' => 10000]])->all());
    $s['piano']->update(['stato' => $stato]);

    return $s;
}

/** I motivi del cancello della riserva, in un testo solo. */
function b52EbMotivi($test, array $s): string
{
    return implode(' | ', ruAnteprima($test, $s, ruRiserva($s))['cancello']['motivi']);
}

// --- Le frasi delle voci bloccate -----------------------------------------------------------------------------------------------

it('piano approvato senza niente a giornale: la voce di entrata si nomina con la sua etichetta e il piano darà «l\'entrata di quella voce», non l\'ordinaria', function (string $passaggio, string $chi) {
    $s = b52EbApprovato();
    $dati = $passaggio === 'riserva' ? ruRiserva($s) : b52UsCostituzione($s);
    $bloccate = ruAnteprima($this, $s, $dati)['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['La voce ' . B52EB_ETICHETTA_ENTRATA . ' è nel piano «Preventivo 2026», approvato e ancora senza niente a giornale: non si sposta, non c\'è conguaglio, e generato o ricalcolato il piano darà dal 1 maggio 2026 l\'entrata di quella voce a ' . $chi . ', nudo proprietario: la scelta non lo raggiunge. Per applicarla, prima di registrare il passaggio riporta il piano in bozza dalla sua pagina: con il passaggio la voce si sposterà; poi riapprova il piano e ricalcolalo.']);
})->with([
    'riserva' => ['riserva', 'Acquirente Elsa'],
    'costituzione' => ['costituzione', 'Venditore Ugo'],
]);

it('piano approvato senza niente a giornale, con una spesa e un\'entrata: «l\'ordinaria o l\'entrata di quelle voci», e nessuna delle due è chiamata solo «ordinaria»', function () {
    $s = b52EbApprovato(conLaSpesa: true);
    $bloccate = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['Le voci ' . B52EB_ETICHETTA_ENTRATA . ' e ' . B52EB_ETICHETTA_SPESA . ' sono nel piano «Preventivo 2026», approvato e ancora senza niente a giornale: non si spostano, non c\'è conguaglio, e generato o ricalcolato il piano darà dal 1 maggio 2026 l\'ordinaria o l\'entrata di quelle voci a Acquirente Elsa, nudo proprietario: la scelta non lo raggiunge. Per applicarla, prima di registrare il passaggio riporta il piano in bozza dalla sua pagina: con il passaggio le voci si sposteranno; poi riapprova il piano e ricalcolalo.']);
});

it('piano con rate a giornale: nella riserva «l\'entrata di quella voce resta» a chi vende, nella costituzione «il conguaglio dà» l\'entrata a chi entra', function (string $passaggio, string $frase) {
    $s = b52EbAGiornale();
    $dati = $passaggio === 'riserva' ? ruRiserva($s) : b52UsCostituzione($s);
    $bloccate = ruAnteprima($this, $s, $dati)['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['La voce ' . B52EB_ETICHETTA_ENTRATA . ' è nel piano «Preventivo 2026», che non si ricalcola più: non si sposta, e per quel piano non serve: ' . $frase . ' Resta sul «Proprietario» per i piani che verranno in questa gestione.']);
})->with([
    'riserva' => ['riserva', 'l\'entrata di quella voce resta a Venditore Ugo, che resta usufruttuario, anche sulle quote ancora in bozza, e non si conguaglia.'],
    'costituzione' => ['costituzione', 'il conguaglio dà a Acquirente Elsa l\'entrata di quella voce dal 1 maggio 2026, anche sulle quote ancora in bozza.'],
]);

it('voce nelle fatture di un piano straordinario approvato: nominata come entrata, con la sua etichetta', function () {
    $s = b52UsScenarioPannello();
    b52EbPianoDaFatture($s, b52EbConto($s['ctmEntrata']));
    $bloccate = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['La voce ' . B52EB_ETICHETTA_ENTRATA . ' è nelle fatture del piano straordinario «Spese da finanziare», approvato: resta sul «Proprietario», e nei piani ordinari generati o ricalcolati andrà a Acquirente Elsa, nudo proprietario.']);
});

it('voce bloccata da più piani: la frase la nomina come entrata e per ogni piano dice «l\'entrata della voce»', function (string $primoPiano, string $parte) {
    $s = b52UsScenarioPannello();
    $s['piano']->update(['stato' => 'approvato']);
    $s['piano']->capitoli()->syncWithoutDetaching([b52EbConto($s['ctmEntrata']) => ['importo' => 24000]]);
    if ($primoPiano === 'giornale') {
        ruEmetti($s);
    }
    b52EbPianoDaFatture($s, b52EbConto($s['ctmEntrata']));
    $bloccate = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['La voce ' . B52EB_ETICHETTA_ENTRATA . ' è bloccata da più piani — ' . $parte . '; «Spese da finanziare», straordinario, nelle sue fatture. Non si sposta.']);
})->with([
    'preventivo a giornale' => ['giornale', '«Preventivo 2026», che non si ricalcola più: lì l\'entrata di quella voce resta a Venditore Ugo, che resta usufruttuario, anche sulle quote ancora in bozza, e non si conguaglia'],
    'preventivo senza niente a giornale' => ['senza', '«Preventivo 2026», approvato e ancora senza niente a giornale: generato o ricalcolato, darà dal 1 maggio 2026 l\'entrata della voce a Acquirente Elsa, nudo proprietario'],
]);

it('controprova — una spesa bloccata dice «l\'ordinaria di quella voce» e si nomina per il solo nome, come prima', function () {
    $s = ruScenario('prima_rata', 0);
    $bloccate = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['frasi_bloccate'];

    expect($bloccate)->toBe(['La voce Spese generali è nel piano «Preventivo 2026», approvato e ancora senza niente a giornale: non si sposta, non c\'è conguaglio, e generato o ricalcolato il piano darà dal 1 maggio 2026 l\'ordinaria di quella voce a Acquirente Elsa, nudo proprietario: la scelta non lo raggiunge. Per applicarla, prima di registrare il passaggio riporta il piano in bozza dalla sua pagina: con il passaggio la voce si sposterà; poi riapprova il piano e ricalcolalo.']);
});

// --- I motivi del cancello della riserva ------------------------------------------------------------------------------------------

it('cancello — l\'entrata bloccata dal piano che si ricalcola: elenco fra trattini con l\'etichetta, e la clausola dell\'entrata al posto dell\'art. 1004 c.c.', function () {
    $s = b52EbConRighe(['entrata']);
    $motivi = b52EbMotivi($this, $s);

    expect($motivi)->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» — ' . B52EB_ETICHETTA_ENTRATA . ' — vanno a Acquirente Elsa, nudo proprietario, anche se fra le parti l\'entrata di quella voce, se è un frutto civile, è dell\'usufruttuario: il piano le blocca, e il passaggio non le sposta. Se devono restare a Venditore Ugo, che resta usufruttuario, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio')
        ->not->toContain('1004')
        ->not->toContain('l\'ordinaria');
});

it('cancello — una spesa e un\'entrata bloccate insieme: l\'art. 1004 c.c. resta per l\'ordinaria, e l\'entrata ha la sua clausola', function () {
    $s = b52EbConRighe(['spese', 'entrata']);
    $motivi = b52EbMotivi($this, $s);

    expect($motivi)->toContain('le voci sul «Proprietario» — ' . B52EB_ETICHETTA_SPESA . ' e ' . B52EB_ETICHETTA_ENTRATA . ' — vanno a Acquirente Elsa, nudo proprietario, anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.), e lo è l\'entrata, se è un frutto civile: il piano le blocca, e il passaggio non le sposta.');
});

it('cancello — due entrate bloccate dal piano: l\'elenco le nomina tutte e due e la clausola va al plurale', function () {
    $s = b52EbConRighe(['entrata', 'canone'], conUnSecondoCanone: true);
    $motivi = b52EbMotivi($this, $s);

    expect($motivi)->toContain('le voci sul «Proprietario» — ' . B52EB_ETICHETTA_ENTRATA . ' e Canone parcheggio (Proprietà, Ordinaria 2026, entrata) — vanno a Acquirente Elsa, nudo proprietario, anche se fra le parti l\'entrata di quelle voci, se sono frutti civili, è dell\'usufruttuario: il piano le blocca')
        ->not->toContain('1004');
});

it('cancello — l\'entrata bloccata da un altro piano approvato: stesse parole, e il rimando al riquadro delle voci', function () {
    $s = b52EbConRighe(['spese', 'entrata'], stato: 'bozza');
    b52EbPianoDaFatture($s, b52EbConto($s['ctmEntrata']));
    $motivi = b52EbMotivi($this, $s);

    expect($motivi)->toContain('le voci sul «Proprietario» — ' . B52EB_ETICHETTA_ENTRATA . ' — vanno a Acquirente Elsa, nudo proprietario, anche se fra le parti l\'entrata di quella voce, se è un frutto civile, è dell\'usufruttuario: un altro piano approvato le blocca, e il passaggio non le sposta — il riquadro «Chi paga l\'ordinaria dal giorno dell\'atto» dice quale')
        ->not->toContain('1004');
});

it('cancello — l\'entrata che il passaggio non propone (coefficienti che non fanno 100): elenco con l\'etichetta, clausola dell\'entrata, e «la dà all\'inquilino»', function () {
    $s = b52EbConRighe(['spese'], stato: 'bozza', ripartizioneEntrata: ['proprietario' => 60, 'usufruttuario' => 30]);
    $motivi = b52EbMotivi($this, $s);

    expect($motivi)->toContain('le voci sul «Proprietario» — ' . B52EB_ETICHETTA_ENTRATA . ' — vanno a Acquirente Elsa, nudo proprietario, perché il programma addebita secondo i coefficienti anche se fra le parti l\'entrata di quella voce, se è un frutto civile, è dell\'usufruttuario;')
        ->toContain('non su «Inquilino», che su un\'unità affittata le dà all\'inquilino')
        ->not->toContain('1004')
        ->not->toContain('fa pagare');
});

it('cancello — l\'entrata a cui hai tolto la spunta: nominata con l\'etichetta, il motivo non cita l\'art. 1004 c.c. (non lo citava nemmeno per le spese)', function () {
    $s = b52EbConRighe(['spese', 'entrata'], stato: 'bozza');
    $dati = ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctmEntrata']]]);
    $motivi = implode(' | ', ruAnteprima($this, $s, $dati)['cancello']['motivi']);

    expect($motivi)->toContain('le voci sul «Proprietario» — ' . B52EB_ETICHETTA_ENTRATA . ' — vanno a Acquirente Elsa, nudo proprietario, perché hai tolto loro la spunta');
});

// --- Quando a passare sono solo entrate -------------------------------------------------------------------------------------------

it('passa solo l\'entrata (spunta tolta alla spesa), senza rate emesse: la frase che il modulo mostra non cita l\'art. 1004 c.c.', function () {
    $s = b52UsScenarioPannello();
    $frasi = b52UsFrasi($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]));

    expect($frasi[0])->toBe('Dal 1 maggio 2026 l\'usufruttuario è Venditore Ugo: nei piani generati o ricalcolati dopo passano all\'«Usufruttuario» le sole entrate. Le voci create dopo, e quelle delle gestioni che si apriranno, partono dal «Proprietario»: per darle all\'usufruttuario mettile su «Usufruttuario».')
        ->and(str_contains($frasi[0], '1004'))->toBeFalse();
});

it('passa solo l\'entrata, con rate emesse: le spese ordinarie seguono il conguaglio e l\'entrata passa nei piani, senza l\'art. 1004 c.c.', function (string $passaggio, string $frase) {
    $s = b52UsScenarioPannello();
    ruEmetti($s);
    $dati = $passaggio === 'riserva' ? ruRiserva($s, extra: ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]) : b52UsCostituzione($s, ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]);
    $frasi = b52UsFrasi($this, $s, $dati);

    expect(str_starts_with($frasi[0], $frase))->toBeTrue($frasi[0])
        ->and(str_contains($frasi[0], '1004'))->toBeFalse($frasi[0]);
})->with([
    'riserva' => ['riserva', 'Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario: restano sue anche sulle rate già emesse, senza conguaglio, e, per le entrate che passano all\'«Usufruttuario», nei piani generati o ricalcolati dopo.'],
    'costituzione' => ['costituzione', 'Dal 1 maggio 2026 le spese ordinarie sono di Acquirente Elsa, usufruttuario: nel conguaglio delle rate già emesse e, per le entrate che passano all\'«Usufruttuario», nei piani generati o ricalcolati dopo.'],
]);

it('controprova — con una spesa fra le voci che passano la frase di apertura cita l\'art. 1004 c.c. come prima', function (array $tieni) {
    $s = b52UsScenarioPannello();
    $extra = $tieni === [] ? [] : ['voci_da_tenere' => [$s['ctmEntrata']]];
    $frasi = b52UsFrasi($this, $s, ruRiserva($s, extra: $extra));

    expect(str_starts_with($frasi[0], 'Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario (art. 1004 c.c.): '))->toBeTrue($frasi[0]);
})->with([
    'spesa ed entrata' => [[]],
    'la sola spesa' => [['entrata']],
]);

it('lo storico, se a passare sono solo entrate, apre senza l\'art. 1004 c.c. e dice «questa entrata è passata»', function () {
    $s = b52UsScenarioPannello();
    $sub = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]));

    expect(fds50Storico($s, $sub)['ordinaria']['testo'])->toBe('Dal 1 maggio 2026 all\'usufruttuario, la proposta di legge: questa entrata è passata dal «Proprietario» all\'«Usufruttuario».');
});

it('lo storico con due entrate che passano: «queste entrate sono passate», senza l\'art. 1004 c.c.', function () {
    $s = b52UsScenarioPannello();
    b52UsVoce($s, 'Canone parcheggio', 'entrata', 12000, ['proprietario' => 100]);
    $sub = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [b52UsAssociazioneSpese($s)]]));

    expect(fds50Storico($s, $sub)['ordinaria']['testo'])->toBe('Dal 1 maggio 2026 all\'usufruttuario, la proposta di legge: queste entrate sono passate dal «Proprietario» all\'«Usufruttuario».');
});

it('controprova — lo storico con una spesa fra le voci che passano cita l\'art. 1004 c.c. come prima', function () {
    $s = b52UsScenarioPannello();
    $sub = ruRegistra($this, $s, ruRiserva($s));

    expect(fds50Storico($s, $sub)['ordinaria']['testo'])->toBe('Dal 1 maggio 2026 all\'usufruttuario (art. 1004 c.c.), la proposta di legge: queste voci sono passate dal «Proprietario» all\'«Usufruttuario».');
});
