<?php

/**
 * «Sposta spesa» e la voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51).
 *
 * Il motore addebita una voce di spesa per il valore assoluto del suo importo o della copia che il piano ne tiene (`CalcoloQuoteService::processaConti`).
 * Spostare € 100,00 da «Spese generali» (copia 120000) a «Rimborso vecchio» (copia −24000) portava le copie a 110000 e −14000: il piano passava da
 * 144000 a 124000 di spese, il doppio dello spostamento, mentre il modale promette di non cambiare il totale. Con € 240,00 la copia arrivava a zero,
 * l'avviso della pagina si spegneva, «Ricalcola» passava e le quote scendevano da 120000 a 72000, con la voce ancora a −€ 240,00 nel piano dei conti.
 * Verso una spesa scritta con il meno fuori dal piano nasceva una copia positiva: il piano la addebitava, cioè l'addebito che la creazione rifiuta.
 *
 * Il server rifiuta, sul campo della voce, una sorgente o una destinazione che l'avviso della pagina nomina (`SpeseNegativeNelPiano::trova`: la voce con
 * la copia negativa, o senza copia con l'importo negativo, anche già corretta con la copia ancora negativa; il capitolo che ripartisce un sottoconto
 * negativo, con la sua copia o senza) e, come destinazione, ogni voce di spesa con l'importo negativo e il capitolo fuori dal piano con un sottoconto
 * negativo, su cui il motore ripartirebbe la copia nuova. Le parole sono di `VoceDiSpesaNegativa`. La pagina non offre queste voci come destinazione,
 * e non offre come sorgente quelle che l'avviso nomina, con il capitolo che le ripartisce. Il capitolo del piano da cui il motore non scende fino alla
 * voce negativa (il capitolo ha le sue tabelle, o la voce è nata dopo il piano) non la ripartisce: la voce conta con la sua riga, e il capitolo sposta
 * budget come ogni altra voce (T297.54: 70000 + |−10000| + 30000 = 110000 prima e dopo). Con la copia nulla vale lo stesso se la voce ha la sua riga,
 * perché con la copia che lo spostamento gli dà il motore si fermerebbe lì; senza la riga della voce, la copia nuova la toglierebbe dal piano.
 * Lo storno di un movimento già scritto resta fuori dal controllo: restituisce ciò che è già stato spostato. Un sottoconto al terzo livello si salva
 * solo con un capitolo padre di primo livello, che lo porta fuori dal suo capitolo: la frase nomina i piani che lo addebitano da quel capitolo, e
 * quanto (T297.55).
 *
 * Fonte dell'atteso: i conti a mano dello scenario V2 di `Beta53SpesaNegativaScenari.php` (spese 120000 + |−24000| = 144000, entrate 24000, da
 * ripartire 120000) e del capitolo (copia 70000 divisa fra «Ascensore» 60000 e «Rimborso» −10000 con pesi 50000: 84000 e −14000, cioè 98000).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: la schermata del modale (`ModalSpostaSpesaTipo.test.ts`: sorgenti e destinazioni arrivano già filtrate dal server); i database
 * diversi da sqlite. Le voci di entrata con l'importo negativo cambiano ancora il totale come destinazione: T297.53 fissa il comportamento di oggi.
 * Dopo «Entrata» un piano che addebita il sottoconto con la copia della sua riga, o di un capitolo di primo livello, lo accredita, e il piano che lo
 * raggiunge da un capitolo di primo livello lo perde con un padre diverso da quel capitolo: la frase non lo dice.
 */

use App\Models\Gestionale\BudgetMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Il rifiuto della destinazione «Rimborso vecchio» nello scenario V2. */
const B53SSP_RIFIUTO_DESTINAZIONE = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. '
    .'Spostare budget su «Rimborso vecchio» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.';

/** Lo stesso rifiuto, con «Rimborso vecchio» come sorgente. */
const B53SSP_RIFIUTO_SORGENTE = 'La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. '
    .'Prelevare budget da «Rimborso vecchio» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.';

/** La correzione di una voce che nessun piano addebita e nessun piano approvato blocca: è quella della nota del selettore. */
const B53SSP_CORREGGI = 'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

/** «Sposta spesa» dalla rotta della pagina. */
function b53sspSposta($test, array $s, int $da, int $a, string $euro = '100.00')
{
    return $test->actingAs($test->user)->from('/pagina-del-piano')->post(route('admin.gestionale.piani-rate.move-budget', [$s['c'], $s['piano']]), [
        'source_id' => $da, 'destination_id' => $a, 'amount' => $euro, 'reason' => 'Prova',
    ]);
}

/** Le copie del piano: conto => centesimi (nulla se il piano legge l'importo della voce). */
function b53sspCopie(array $s): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->orderBy('conto_id')->pluck('importo', 'conto_id')
        ->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

/** I nomi delle voci che la pagina del piano offre come destinazione. */
function b53sspDestinazioni($test, array $s): array
{
    return collect(json_decode(json_encode(b53snProps($test, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']])['destinations']), true))
        ->pluck('nome')->all();
}

/** I nomi delle voci che la pagina del piano offre come sorgente, in ordine alfabetico. */
function b53sspSorgenti($test, array $s): array
{
    return collect(json_decode(json_encode(b53snProps($test, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']])['sources']), true))
        ->pluck('nome')->sort()->values()->all();
}

/**
 * Il capitolo «Manutenzione» di `b53snCapitolo` nel piano «Ordinario 2026» con la copia € 700,00, accanto a «Pulizia scale» € 300,00 (copia nulla); poi
 * «Rimborso» riscritto a −10000. Il motore divide la copia fra «Ascensore» e «Rimborso» con pesi 50000 e l'avviso della pagina nomina «Rimborso».
 * `$copia`: la cifra del capitolo, vuota per la copia nulla (il motore porta i sottoconti con il loro importo); null se il capitolo non è nel piano.
 */
function b53sspCapitolo($test, ?string $copia = '700,00'): array
{
    $s = b53snCapitolo($test);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    $s['pulizia'] = b52tVoce($test, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);
    $voci = $copia === null ? [$s['pulizia']->id => ''] : [$s['capitolo']->id => $copia, $s['pulizia']->id => ''];
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => array_keys($voci),
        'capitoli_config' => array_map(fn ($id, $importo) => ['id' => $id, 'importo' => $importo, 'note' => ''], array_keys($voci), array_values($voci))], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

// ─── La voce con la copia negativa (V2) ─────────────────────────────────────────────────────────────────────────

it('T297.40 «Sposta spesa» verso la spesa scritta col meno si rifiuta e il totale resta 144000, con l\'avviso acceso', function (string $euro) {
    $s = b53snV2($this);
    $copie = b53sspCopie($s);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id, $euro);
    $errori = b53snErrori();

    $pagina = b53snPaginaPiano($this, $s, $s['piano']);
    expect($errori['destination_id'] ?? [])->toBe([B53SSP_RIFIUTO_DESTINAZIONE])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and($copie)->toBe([(int) $s['generali']->id => 120000, (int) $s['antenna']->id => 24000, (int) $s['rimborso']->id => -24000])
        ->and(BudgetMovement::where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and($pagina['pianoRate']['totale_spese'])->toBe(144000)
        ->and($pagina['pianoRate']['totale_da_ripartire'])->toBe(120000)
        ->and($pagina['avviso_spesa_negativa'])->not->toBeNull();
})->with(['€ 100,00' => ['100.00'], '€ 240,00, la cifra che portava la copia a zero' => ['240.00']]);

it('T297.41 la pagina non offre la spesa scritta col meno fra le destinazioni', function () {
    $s = b53snV2($this);

    expect(b53sspDestinazioni($this, $s))->toBe(['Affitto antenna', 'Spese generali']);
});

it('T297.42 da una richiesta a mano la spesa scritta col meno come sorgente si rifiuta con le stesse parole, non con «Fondi insufficienti»', function () {
    $s = b53snV2($this);
    $copie = b53sspCopie($s);

    b53sspSposta($this, $s, (int) $s['rimborso']->id, (int) $s['generali']->id);

    expect(b53snErrori())->toBe(['source_id' => [B53SSP_RIFIUTO_SORGENTE]])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and(BudgetMovement::where('piano_rate_id', $s['piano']->id)->count())->toBe(0);
});

it('T297.43 la voce già corretta con la copia ancora negativa si rifiuta come destinazione, con le parole della copia', function () {
    $s = b53snV2($this);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 24000]);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id);

    expect(b53snErrori()['destination_id'] ?? [])->toBe(['La voce di spesa «Rimborso vecchio» è già corretta, ma il piano ne tiene la copia negativa (meno € 240,00) e la '
        .'addebiterebbe in positivo, per € 240,00. Spostare budget su «Rimborso vecchio» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.'])
        ->and(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(-24000)
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Rimborso vecchio');
});

it('T297.43 con la copia negativa diversa dall\'importo della voce l\'avviso e il rifiuto dicono le due cifre, e il piano addebita quella della copia', function () {
    $s = b53snV2($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => -4000]);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id);

    // Il motore addebita la copia, in positivo: 120000 + |−4000| di spese, 24000 di entrate.
    expect(b53snRipartito($s, $s['piano']))->toBe([(int) $s['generali']->id => 120000, (int) $s['antenna']->id => -24000, (int) $s['rimborso']->id => 4000])
        ->and(b53snErrori()['destination_id'] ?? [])->toBe(['La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e nel piano una copia negativa '
            .'(meno € 40,00): il piano la addebiterebbe in positivo, per € 40,00. Spostare budget su «Rimborso vecchio» cambierebbe il totale del piano: l\'avviso della '
            .'pagina dice che cosa fare.'])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa']['voci'] ?? null)->toBe(['La voce «Rimborso vecchio» ha un importo negativo '
            .'(meno € 240,00) e nel piano una copia negativa (meno € 40,00): il piano la addebita in positivo, per € 40,00.']);
});

it('controprova — T297.43 con la copia negativa diversa dall\'importo della voce il piano dei conti conta la voce per € 240,00 e rimanda al piano, la cui pagina dice la cifra della copia', function () {
    $s = b53snV2($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => -4000]);

    // La frase della pagina (`ContiNewSpesaNegativa.test.ts`): «il preventivo la conta in positivo, per € 240,00. È nel piano «Ordinario 2026»: la pagina del piano dice che cosa fare.»
    $pagina = json_decode(json_encode(b53snPianoDeiConti($this, $s)), true);
    expect($pagina['totalePreventivo'])->toBe(144000)
        ->and($pagina['speseNegative'])->toBe([['id' => (int) $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000,
            'piani' => [['id' => (int) $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true]], 'bloccano' => [], 'terzo_livello' => false]])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa']['voci'] ?? null)->toBe(['La voce «Rimborso vecchio» ha un importo negativo '
            .'(meno € 240,00) e nel piano una copia negativa (meno € 40,00): il piano la addebita in positivo, per € 40,00.']);
});

it('T297.43 con più voci la testa dice le due cifre solo per la voce la cui copia è diversa dall\'importo', function () {
    $s = b53snV2($this);
    $vecchia = b52tVoce($this, $s, 'Conguaglio vecchio', 'spesa', '50,00', $s['tabella']);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $vecchia->id, 'importo' => -5000, 'created_at' => now(), 'updated_at' => now()]);
    b53snRiscrivi($vecchia, -5000);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => -4000]);

    expect(\App\Services\Riparto\SpeseNegativeNelPiano::testa(app(\App\Services\Riparto\SpeseNegativeNelPiano::class)->trova($s['piano']->fresh())))
        ->toBe('2 voci di spesa hanno un importo negativo e il piano le addebiterebbe in positivo: «Rimborso vecchio» (meno € 240,00, con la copia a meno € 40,00), '
            .'«Conguaglio vecchio» (meno € 50,00)');
});

// ─── La spesa scritta col meno che l'avviso non nomina ──────────────────────────────────────────────────────────

it('T297.44 una spesa scritta col meno fuori dal piano come destinazione si rifiuta, e il piano non ne prende la copia', function () {
    $s = b53snV2($this);
    $vecchia = b52tVoce($this, $s, 'Conguaglio vecchio', 'spesa', '50,00', $s['tabella']);
    b53snRiscrivi($vecchia, -5000);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $vecchia->id);

    expect(b53snErrori()['destination_id'] ?? [])->toBe(['La voce di spesa «Conguaglio vecchio» ha un importo negativo (meno € 50,00): spostandoci budget, questo '
        .'piano la addebiterebbe in positivo. '.B53SSP_CORREGGI])
        ->and(b53sspCopie($s))->not->toHaveKey((int) $vecchia->id)
        ->and(BudgetMovement::where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and(b53sspDestinazioni($this, $s))->toBe(['Affitto antenna', 'Spese generali']);
});

it('T297.45 la spesa scritta col meno di cui il piano tiene una copia positiva si rifiuta come destinazione; corretta la voce, lo spostamento passa', function () {
    $s = b53snV2($this);
    // La copia torna positiva: il piano la addebita per la cifra che aveva, l'avviso non la nomina.
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => 24000]);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id);

    expect(b53snErrori()['destination_id'] ?? [])->toBe(['La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e questo piano la addebita '
        .'già in positivo, per € 240,00: spostandoci budget la addebiterebbe di più. '.B53SSP_CORREGGI])
        ->and(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(24000)
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Rimborso vecchio');

    // La strada della frase: la voce si corregge dal piano dei conti, poi lo spostamento passa e il totale resta com'è.
    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['rimborso']]), [
        'nome' => 'Rimborso vecchio', 'codice' => '', 'tipo' => 'spesa', 'importo' => '240,00', 'descrizione' => '', 'note' => '',
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(34000)
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(144000);
});

it('T297.45 con il piano approvato la frase non propone la correzione che il piano rifiuta: nomina il piano e la strada che passa', function () {
    $s = b53snV2($this, 'approvato');
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => 24000]);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['rimborso']->id);

    expect(b53snErrori()['destination_id'] ?? [])->toBe(['La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e questo piano la addebita '
        .'già in positivo, per € 240,00: spostandoci budget la addebiterebbe di più. L\'importo, il padre e il tipo della voce non si cambiano finché la voce è nel '
        .'piano «Ordinario 2026», approvato: riporta prima il piano in bozza dalla sua pagina, poi correggi l\'importo nel piano dei conti; se è un provento, scegli '
        .'«Entrata» e scrivi l\'importo senza il segno. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con '
        .'delle rate emesse, prima vanno annullate le emissioni.'])
        ->and(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(24000);
});

// ─── Il capitolo che ripartisce un sottoconto negativo ──────────────────────────────────────────────────────────

it('T297.46 il capitolo che ripartisce un sottoconto negativo si rifiuta come destinazione e come sorgente, e la pagina non lo offre', function () {
    $s = b53sspCapitolo($this);
    $copie = b53sspCopie($s);
    $testa = 'L\'importo del capitolo «Manutenzione» si ripartisce anche sul sottoconto «Rimborso», che ha un importo negativo (meno € 100,00): il totale '
        .'del piano supererebbe quello del capitolo. ';

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id);
    $comeDestinazione = b53snErrori();
    session()->forget('errors');
    b53sspSposta($this, $s, (int) $s['capitolo']->id, (int) $s['pulizia']->id);
    $comeSorgente = b53snErrori();

    expect($comeDestinazione)->toBe(['destination_id' => [$testa.'Spostare budget su «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and($comeSorgente)->toBe(['source_id' => [$testa.'Prelevare budget da «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'])->not->toBeNull()
        ->and(b53sspDestinazioni($this, $s))->toBe(['Pulizia scale']);
});

it('T297.49 il capitolo nel piano con la copia nulla porta il sottoconto negativo con il suo importo: si rifiuta come destinazione', function () {
    $s = b53sspCapitolo($this, '');
    $copie = b53sspCopie($s);

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id);

    expect(b53snErrori())->toBe(['destination_id' => ['La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, '
        .'per € 100,00. Spostare budget su «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and(b53sspDestinazioni($this, $s))->toBe(['Pulizia scale']);
});

it('T297.50 il capitolo fuori dal piano con un sottoconto negativo si rifiuta come destinazione; corretto il sottoconto, lo spostamento passa e il totale resta com\'è', function () {
    $s = b53sspCapitolo($this, null);

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id);

    // Con la riga nuova di € 100,00 il motore la dividerebbe fra «Ascensore» (60000) e «Rimborso» (−10000): 12000 e −2000, cioè € 140,00 di spese.
    expect(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Manutenzione» ha il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
        .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
        .'Correggi l\'importo del sottoconto «Rimborso» nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.']])
        ->and(b53sspCopie($s))->toBe([(int) $s['pulizia']->id => null])
        ->and(b53sspDestinazioni($this, $s))->toBe(['Ascensore', 'Pulizia scale']);

    // La strada della frase: il sottoconto corretto dal piano dei conti, poi lo spostamento passa e il piano resta a € 300,00.
    session()->forget('errors');
    $this->actingAs($this->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['rimborso']]), [
        'nome' => 'Rimborso', 'codice' => '', 'tipo' => 'spesa', 'importo' => '100,00', 'descrizione' => '', 'note' => '', 'parent_id' => $s['capitolo']->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    expect(b53sspDestinazioni($this, $s))->toContain('Manutenzione');
    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['capitolo']->id => 10000, (int) $s['pulizia']->id => 20000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(30000);
});

it('T297.55 il capitolo fuori dal piano con il sottoconto negativo al terzo livello: la frase dice dove sta e di sceglierle prima un capitolo padre; la strada passa, poi lo spostamento', function () {
    $s = b53snTerzoLivello($this);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['capitolo']->id);

    expect(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Manutenzione» ha, sotto «Impianti», il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
        .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
        .'Il sottoconto «Rimborso» è sotto un altro sottoconto: nel piano dei conti, che non lo prevede, scegli prima come suo capitolo padre una voce di primo livello, '
        .'poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.']])
        ->and(b53sspCopie($s))->toBe([(int) $s['generali']->id => null])
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Manutenzione');

    // La strada della frase: il capitolo padre di primo livello e l'importo corretto, poi lo spostamento passa e il totale resta com'è.
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect(b53sspDestinazioni($this, $s))->toContain('Manutenzione');
    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['generali']->id => 110000, (int) $s['capitolo']->id => 10000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(120000);
});

it('T297.55 il capitolo di secondo livello fuori dal piano con il sottoconto negativo sotto di lui: la frase lo nomina come sottoconto, non come un altro; la strada passa', function () {
    $s = b53snTerzoLivello($this);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['impianti']->id);

    expect(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Impianti» ha il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
        .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
        .'«Impianti» è a sua volta un sottoconto, e il piano dei conti non lo prevede: scegli prima come capitolo padre di «Rimborso» una voce di primo livello, '
        .'poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.']])
        ->and(b53sspCopie($s))->toBe([(int) $s['generali']->id => null]);

    // La strada della frase: «Rimborso» sotto «Manutenzione», di primo livello, con l'importo corretto; poi lo spostamento su «Manutenzione» lascia il totale com'è.
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b53snRipartito($s, $s['piano']))->toBe([(int) $s['generali']->id => 110000, (int) $s['rimborso']->id => 10000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(120000);
});

it('T297.55 il capitolo che è a sua volta un sottoconto, con un piano approvato che blocca l\'importo del sottoconto: la frase lo nomina come sottoconto', function () {
    $bloccano = [['id' => 7, 'nome' => 'Preventivo 2026', 'capitolo' => null, 'movimenti' => []]];

    expect(\App\Services\Riparto\VoceDiSpesaNegativa::spostamentoSulCapitolo('Impianti', 'Rimborso', -10000, $bloccano, null, true))
        ->toContain('lo addebiterebbe in positivo. «Impianti» è a sua volta un sottoconto, e il piano dei conti non lo prevede: ogni modifica di «Rimborso» chiede '
            .'come capitolo padre una voce di primo livello. L\'importo, il padre e il tipo del sottoconto «Rimborso» non si cambiano finché il sottoconto è nel piano '
            .'«Preventivo 2026», approvato: riporta prima il piano in bozza dalla sua pagina, poi scegli il capitolo padre e correggi l\'importo; ')
        ->not->toContain('un altro sottoconto')
        // Con il padre sotto il capitolo, la frase resta quella di prima: il padre non è la voce che la frase chiama capitolo.
        ->and(\App\Services\Riparto\VoceDiSpesaNegativa::spostamentoSulCapitolo('Manutenzione', 'Rimborso', -10000, $bloccano, 'Impianti', true))
        ->toContain('Il sottoconto «Rimborso» è sotto un altro sottoconto: nel piano dei conti, che non lo prevede, ogni sua modifica chiede come capitolo padre una voce di primo livello. ');
});

/**
 * «Rimborso» al terzo livello («Manutenzione» → «Impianti» → «Rimborso», € 100,00 sulla tabella «Proprietà», poi riscritto a −10000), con il piano
 * «Preventivo B», nato quando «Rimborso» era ancora positivo, che tiene la riga `$riga` («impianti», il padre diretto, o «rimborso») con la copia
 * `$copia`; `$approvato`: «Preventivo B» è approvato. `$fratello`: «Caldaia» € 200,00 sotto «Impianti». Il piano «Ordinario 2026», in bozza, tiene
 * «Spese generali» e basta.
 *
 * Il motore di «Preventivo B» dà a «Rimborso» la copia di «Impianti»: da solo € 300,00; con «Caldaia» i pesi sono 20000 e −10000, e «Caldaia» prende
 * 60000, «Rimborso», l'ultimo, il resto: −30000, addebitato per € 300,00. Ogni modifica di «Rimborso» chiede un capitolo padre di primo livello, e lo
 * porta fuori da «Impianti»: con «Preventivo B» approvato il padre non cambia, in bozza «Preventivo B» non lo raggiunge più.
 */
function b53sspTerzoLivelloNelPreventivoB($test, string $riga, bool $approvato = true, bool $fratello = false, string $copia = '300,00'): array
{
    $s = b53snBase($test);
    $lega = function ($voce) use ($s) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    };
    $voce = fn (string $nome, int $importo, ?int $padre, bool $capitolo) => \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre, 'is_capitolo' => $capitolo]);
    $s['capitolo'] = $voce('Manutenzione', 0, null, true);
    $s['impianti'] = $voce('Impianti', 0, $s['capitolo']->id, true);
    $lega($s['rimborso'] = $voce('Rimborso', 10000, $s['impianti']->id, false));
    if ($fratello) {
        $lega($s['caldaia'] = $voce('Caldaia', 20000, $s['impianti']->id, false));
    }
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    $tenuta = $s[$riga];
    $s['b'] = b52tPiano($test, $s, ['capitoli_ids' => [$tenuta->id], 'capitoli_config' => [['id' => $tenuta->id, 'importo' => $copia, 'note' => '']]], 'Preventivo B');
    if ($approvato) {
        b52tApprova($test, $s, $s['b']);
    }
    $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['generali']->id], 'capitoli_config' => [['id' => $s['generali']->id, 'importo' => '', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['b'] = $s['b']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

it('T297.55 il capitolo di secondo livello che un piano approvato tiene con la copia: la frase dice la strada della bozza, senza la bozza il padre e il tipo si rifiutano, e il piano non cambia', function (bool $fratello) {
    $s = b53sspTerzoLivelloNelPreventivoB($this, 'impianti', true, $fratello);
    $prima = b53snRipartito($s, $s['b']);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['impianti']->id);

    expect($prima[(int) $s['rimborso']->id])->toBe(30000)
        ->and(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Impianti» ha il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
            .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
            .'«Impianti» è a sua volta un sottoconto, e il piano dei conti non lo prevede: ogni modifica di «Rimborso» chiede come capitolo padre una voce di primo livello. '
            .'L\'importo, il padre e il tipo del sottoconto «Rimborso» non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: riporta '
            .'prima il piano in bozza dalla sua pagina, poi scegli il capitolo padre e correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza '
            .'il segno. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate '
            .'le emissioni.']])
        ->and(b53sspCopie($s))->toBe([(int) $s['generali']->id => null]);

    // Con il capitolo di primo livello come destinazione la frase dice lo stesso, del sottoconto che sta sotto «Impianti».
    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['capitolo']->id);
    expect(b53snErrori()['destination_id'][0])->toContain('Il sottoconto «Rimborso» è sotto un altro sottoconto: nel piano dei conti, che non lo prevede, ogni sua modifica chiede '
        .'come capitolo padre una voce di primo livello. L\'importo, il padre e il tipo del sottoconto «Rimborso» non si cambiano finché il suo capitolo «Impianti» è '
        .'nel piano «Preventivo B», approvato: riporta prima il piano in bozza');

    // Senza la bozza il provento con il padre di primo livello si rifiuta, e «Preventivo B» lo addebita come prima. La strada della frase: la bozza, poi
    // la correzione con il capitolo padre.
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(b53snRipartito($s, $s['b']))->toBe($prima);

    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['b']]), ['approvato' => false])->assertSessionHasNoErrors();
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->parent_id)->toBe((int) $s['capitolo']->id);
})->with(['da solo sotto «Impianti»' => [false], 'con un altro sottoconto sotto «Impianti»' => [true]]);

it('T297.55 il capitolo di secondo livello che un piano in bozza tiene con la copia: la frase dice di sceglierle un capitolo padre, e che così il sottoconto esce dal capitolo da cui quel piano lo addebita', function () {
    $s = b53sspTerzoLivelloNelPreventivoB($this, 'impianti', false);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['impianti']->id);

    expect(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Impianti» ha il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
        .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. '
        .'«Impianti» è a sua volta un sottoconto, e il piano dei conti non lo prevede: scegli prima come capitolo padre di «Rimborso» una voce di primo livello, '
        .'poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. '
        .'Il nuovo padre porta «Rimborso» fuori da «Impianti», da cui oggi il piano «Preventivo B» lo addebita per € 300,00.']]);

    // La strada della frase, dalla rotta: «Preventivo B» non addebita più «Rimborso».
    expect(b53snRipartito($s, $s['b']))->toBe([(int) $s['rimborso']->id => 30000]);
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect(b53snRipartito($s, $s['b']))->not->toHaveKey((int) $s['rimborso']->id);
});

it('T297.55 i piani che perdono il sottoconto: al plurale, e per la voce', function () {
    $perdono = [['id' => 7, 'nome' => 'Preventivo B', 'capitolo' => 'Impianti', 'cifra' => 30000], ['id' => 9, 'nome' => 'Integrativo 2026', 'capitolo' => 'Impianti', 'cifra' => 5000]];

    expect(\App\Services\Riparto\VoceDiSpesaNegativa::spostamentoSulCapitolo('Impianti', 'Rimborso', -10000, [], null, true, $perdono))
        ->toEndWith('scrivi l\'importo senza il segno. Il nuovo padre porta «Rimborso» fuori da «Impianti», da cui oggi i piani «Preventivo B» e «Integrativo 2026» lo addebitano per € 300,00 e € 50,00.')
        ->and(\App\Services\Riparto\VoceDiSpesaNegativa::spostamentoSuUnaSpesaNegativa('Rimborso', -10000, null, [], [], true, [$perdono[0]]))
        ->toEndWith('scrivi l\'importo senza il segno. Il nuovo padre porta la voce fuori da «Impianti», da cui oggi il piano «Preventivo B» la addebita per € 300,00.');
});

it('T297.55 un piano approvato non è fra quelli che perdono il sottoconto, perché finché lo tiene il padre non cambia; in bozza sì, con il capitolo e la cifra', function (bool $approvato) {
    $s = b53sspTerzoLivelloNelPreventivoB($this, 'impianti', $approvato);

    expect(\App\Services\Riparto\VoceDiSpesaNegativa::pianiCheLaPerdono((int) $s['rimborso']->id))
        ->toBe($approvato ? [] : [['id' => (int) $s['b']->id, 'nome' => 'Preventivo B', 'capitolo' => 'Impianti', 'cifra' => 30000]]);
})->with(['approvato' => [true], 'in bozza' => [false]]);

it('T297.55 il piano in bozza che tiene il capitolo di primo livello sopra il sottoconto lo raggiunge anche con il padre «Manutenzione»: non è fra quelli che lo perdono', function () {
    $s = b53sspTerzoLivelloNelPreventivoB($this, 'capitolo', false);

    expect(b53snRipartito($s, $s['b']))->toBe([(int) $s['rimborso']->id => 30000])
        ->and(\App\Services\Riparto\VoceDiSpesaNegativa::pianiCheLaPerdono((int) $s['rimborso']->id))->toBe([]);

    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect(b53snRipartito($s, $s['b']))->toBe([(int) $s['rimborso']->id => 30000]);
});

it('T297.55 il piano approvato che tiene la riga del sottoconto a zero: la frase non parla di toglierlo, il provento si rifiuta e la riga resta nel piano', function () {
    $s = b53sspTerzoLivelloNelPreventivoB($this, 'rimborso', true, false, '0,00');

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['impianti']->id);

    expect(b53snErrori()['destination_id'][0])->toContain('ogni modifica di «Rimborso» chiede come capitolo padre una voce di primo livello. L\'importo, il padre e il tipo '
        .'del sottoconto «Rimborso» non si cambiano finché il sottoconto è nel piano «Preventivo B», approvato: ')
        ->not->toContain('Il nuovo padre');

    // Il provento con il padre di primo livello si rifiuta: la riga resta in «Preventivo B», che con la copia a zero non lo addebita.
    expect(b53snRipartito($s, $s['b']))->not->toHaveKey((int) $s['rimborso']->id);
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita');
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['b']->id)->pluck('conto_id')->all())->toBe([(int) $s['rimborso']->id])
        ->and(b53snRipartito($s, $s['b']))->not->toHaveKey((int) $s['rimborso']->id);
});

/**
 * Il capitolo «Manutenzione» nel piano con la copia € 700,00, «Rimborso» con la sua riga e «Pulizia scale» € 300,00 (copia nulla); poi «Rimborso» e la
 * sua copia riscritti a −10000. Il motore non passa la copia del capitolo a «Rimborso»: il capitolo si ripartisce sulle sue tabelle, oppure «Rimborso»
 * è nato dopo il piano e il capitolo passa la copia al solo «Ascensore». La riga di «Rimborso» conta da sola, per il valore assoluto.
 */
function b53sspCapitoloCheNonLoRipartisce($test, bool $natoDopo): array
{
    $s = b53snCapitolo($test);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    $s['pulizia'] = b52tVoce($test, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);

    if (! $natoDopo) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        b53snCrea($test, $s, ['numero_rate' => 12, 'capitoli_ids' => [$s['capitolo']->id, $s['rimborso']->id, $s['pulizia']->id], 'capitoli_config' => [
            ['id' => $s['capitolo']->id, 'importo' => '700,00', 'note' => ''], ['id' => $s['rimborso']->id, 'importo' => '100,00', 'note' => ''], ['id' => $s['pulizia']->id, 'importo' => '', 'note' => ''],
        ]], 'Ordinario 2026')->assertSessionHasNoErrors();
        $s['piano'] = \App\Models\Gestionale\PianoRate::where('nome', 'Ordinario 2026')->firstOrFail();
    } else {
        $s['piano'] = b52tPiano($test, $s, ['capitoli_ids' => [$s['capitolo']->id, $s['pulizia']->id], 'capitoli_config' => [
            ['id' => $s['capitolo']->id, 'importo' => '700,00', 'note' => ''], ['id' => $s['pulizia']->id, 'importo' => '', 'note' => ''],
        ]], 'Ordinario 2026');
        DB::table('conti')->where('id', $s['rimborso']->id)->update(['created_at' => $s['piano']->created_at->copy()->addDay()]);
        DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['rimborso']->id, 'importo' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    }

    b53snRiscrivi($s['rimborso'], -10000, [$s['piano']]);
    $s['rimborso'] = $s['rimborso']->fresh();
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

it('T297.54 il capitolo del piano che non ripartisce il sottoconto con la sua riga negativa sposta budget, e il totale resta 110000', function (bool $natoDopo) {
    $s = b53sspCapitoloCheNonLoRipartisce($this, $natoDopo);
    $prima = b53snPaginaPiano($this, $s, $s['piano']);
    // Chi porta la copia del capitolo nel motore: il capitolo sulle sue tabelle, o «Ascensore», l'unico sottoconto nato fino al piano.
    $copiaDelCapitolo = (int) ($natoDopo ? $s['ascensore'] : $s['capitolo'])->id;

    // L'avviso nomina «Rimborso», che resta fuori dai menu; il capitolo e «Pulizia scale» ci sono.
    expect($prima['avviso_spesa_negativa'])->not->toBeNull()
        ->and($prima['pianoRate']['totale_spese'])->toBe(110000)
        ->and(b53snRipartito($s, $s['piano']))->toBe([$copiaDelCapitolo => 70000, (int) $s['rimborso']->id => 10000, (int) $s['pulizia']->id => 30000])
        ->and(b53sspSorgenti($this, $s))->toBe(['Manutenzione', 'Pulizia scale'])
        ->and(b53sspDestinazioni($this, $s))->toContain('Manutenzione', 'Pulizia scale')
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Rimborso');

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['capitolo']->id => 80000, (int) $s['rimborso']->id => -10000, (int) $s['pulizia']->id => 20000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(110000)
        ->and(b53snRipartito($s, $s['piano']))->toBe([$copiaDelCapitolo => 80000, (int) $s['rimborso']->id => 10000, (int) $s['pulizia']->id => 20000]);

    // Dal capitolo il budget torna indietro, con il totale com'è.
    b53sspSposta($this, $s, (int) $s['capitolo']->id, (int) $s['pulizia']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['capitolo']->id => 70000, (int) $s['rimborso']->id => -10000, (int) $s['pulizia']->id => 30000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(110000);
})->with(['il capitolo con le sue tabelle' => [false], 'il sottoconto nato dopo il piano' => [true]]);

it('controprova — T297.54 il capitolo del piano due livelli sopra il sottoconto negativo lo ripartisce: si rifiuta in tutti e due i versi e la pagina non lo offre', function () {
    $s = b53snBase($this);
    $lega = function ($voce) use ($s) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    };
    $voce = fn (string $nome, int $importo, ?int $padre, bool $capitolo) => \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => $nome, 'tipo' => 'spesa',
        'natura_spesa' => 'ordinaria', 'importo' => $importo, 'parent_id' => $padre, 'is_capitolo' => $capitolo]);
    $s['capitolo'] = $voce('Manutenzione', 0, null, true);
    $impianti = $voce('Impianti', 0, $s['capitolo']->id, true);
    $lega($ascensore = $voce('Ascensore', 60000, $impianti->id, false));
    $lega($s['rimborso'] = $voce('Rimborso', 10000, $impianti->id, false));
    $s['piano'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id, $s['generali']->id], 'capitoli_config' => [
        ['id' => $s['capitolo']->id, 'importo' => '700,00', 'note' => ''], ['id' => $s['generali']->id, 'importo' => '', 'note' => ''],
    ]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $copie = b53sspCopie($s);
    $testa = 'L\'importo del capitolo «Impianti» si ripartisce anche sul sottoconto «Rimborso», che ha un importo negativo (meno € 100,00): il totale del piano '
        .'supererebbe quello del capitolo. ';

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['capitolo']->id);
    $comeDestinazione = b53snErrori();
    session()->forget('errors');
    b53sspSposta($this, $s, (int) $s['capitolo']->id, (int) $s['generali']->id);

    // Il motore divide la copia 70000 di «Manutenzione», attraverso «Impianti», fra «Ascensore» e «Rimborso» con pesi 50000.
    expect(b53snRipartito($s, $s['piano']))->toBe([(int) $s['generali']->id => 120000, (int) $ascensore->id => 84000, (int) $s['rimborso']->id => 14000])
        ->and($comeDestinazione)->toBe(['destination_id' => [$testa.'Spostare budget su «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and(b53snErrori())->toBe(['source_id' => [$testa.'Prelevare budget da «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and(b53sspSorgenti($this, $s))->toBe(['Spese generali'])
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Manutenzione');
});

it('controprova — T297.54 il capitolo con le sue tabelle e la copia nulla: il motore visita i sottoconti con il loro importo, e il capitolo si rifiuta', function () {
    $s = b53snCapitolo($this);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $s['pulizia'] = b52tVoce($this, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);
    $s['piano'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['capitolo']->id, $s['pulizia']->id], 'capitoli_config' => [
        ['id' => $s['capitolo']->id, 'importo' => '', 'note' => ''], ['id' => $s['pulizia']->id, 'importo' => '', 'note' => ''],
    ]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    $copie = b53sspCopie($s);

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id);

    // Con la copia nulla il motore porta «Ascensore» e «Rimborso» con il loro importo; con una copia la ripartirebbe sulle tabelle del capitolo e basta.
    expect(b53snRipartito($s, $s['piano']))->toBe([(int) $s['ascensore']->id => 60000, (int) $s['rimborso']->id => 10000, (int) $s['pulizia']->id => 30000])
        ->and(b53snErrori())->toBe(['destination_id' => ['La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, '
            .'per € 100,00. Spostare budget su «Manutenzione» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.']])
        ->and(b53sspCopie($s))->toBe($copie)
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Manutenzione');
});

/**
 * Il capitolo «Manutenzione» con le sue tabelle e la copia nulla, e i suoi sottoconti con la loro riga: «Ascensore» con la copia nulla e «Rimborso» con la
 * copia € 100,00, poi riscritti a −10000; «Pulizia scale» € 300,00 con la copia nulla. Senza copia il motore ripartisce l'importo del capitolo, zero, e
 * visita i sottoconti con la loro riga: 60000 + |−10000| + 30000 = 100000. Con la copia che lo spostamento gli dà la ripartisce sulle sue tabelle, e le
 * righe dei sottoconti contano da sole: 10000 + 60000 + 10000 + 20000 = 100000.
 */
it('T297.54 il capitolo con le sue tabelle e la copia nulla, sopra il sottoconto con la sua riga negativa, riceve budget e il totale resta 100000', function () {
    $s = b53snCapitolo($this);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $s['pulizia'] = b52tVoce($this, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);
    $righe = [['id' => $s['capitolo']->id, 'importo' => '', 'note' => ''], ['id' => $s['ascensore']->id, 'importo' => '', 'note' => ''],
        ['id' => $s['rimborso']->id, 'importo' => '100,00', 'note' => ''], ['id' => $s['pulizia']->id, 'importo' => '', 'note' => '']];
    b53snCrea($this, $s, ['numero_rate' => 12, 'capitoli_ids' => array_column($righe, 'id'), 'capitoli_config' => $righe], 'Ordinario 2026')->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('nome', 'Ordinario 2026')->firstOrFail();
    b53snRiscrivi($s['rimborso'], -10000, [$s['piano']]);
    $s['rimborso'] = $s['rimborso']->fresh();
    $prima = b53snPaginaPiano($this, $s, $s['piano']);

    expect($prima['avviso_spesa_negativa'])->not->toBeNull()
        ->and($prima['pianoRate']['totale_spese'])->toBe(100000)
        ->and(b53snRipartito($s, $s['piano']))->toBe([(int) $s['ascensore']->id => 60000, (int) $s['rimborso']->id => 10000, (int) $s['pulizia']->id => 30000])
        ->and(b53sspSorgenti($this, $s))->toBe(['Ascensore', 'Manutenzione', 'Pulizia scale'])
        ->and(b53sspDestinazioni($this, $s))->toContain('Manutenzione');

    b53sspSposta($this, $s, (int) $s['pulizia']->id, (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['capitolo']->id => 10000, (int) $s['ascensore']->id => null, (int) $s['rimborso']->id => -10000, (int) $s['pulizia']->id => 20000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(100000)
        ->and(b53snRipartito($s, $s['piano']))->toBe([(int) $s['capitolo']->id => 10000, (int) $s['ascensore']->id => 60000, (int) $s['rimborso']->id => 10000, (int) $s['pulizia']->id => 20000]);

    // Dal capitolo il budget torna indietro, con il totale com'è.
    b53sspSposta($this, $s, (int) $s['capitolo']->id, (int) $s['pulizia']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s))->toBe([(int) $s['capitolo']->id => 0, (int) $s['ascensore']->id => null, (int) $s['rimborso']->id => -10000, (int) $s['pulizia']->id => 30000])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(100000);
});

// ─── Le sorgenti che la pagina offre ────────────────────────────────────────────────────────────────────────────

it('T297.51 la pagina non offre come sorgente le voci che il server rifiuta come sorgente: il capitolo che ripartisce un sottoconto negativo e la spesa con la copia negativa', function (string $scenario, array $attese, string $rifiutata) {
    $s = match ($scenario) {
        'capitolo' => b53sspCapitolo($this),
        'capitolo senza copia' => b53sspCapitolo($this, ''),
        'V2' => b53snV2($this),
    };
    $daProvare = $s[$rifiutata];

    $sorgenti = b53sspSorgenti($this, $s);
    // La voce che la pagina non offre più il server la rifiuta come sorgente: l'una e l'altro leggono lo stesso rilevamento dell'avviso.
    b53sspSposta($this, $s, (int) $daProvare->id, (int) ($s['pulizia'] ?? $s['generali'])->id, '1.00');

    expect($sorgenti)->toBe($attese)
        ->and(b53snErrori())->toHaveKey('source_id');
})->with([
    'il capitolo con la copia' => ['capitolo', ['Pulizia scale'], 'capitolo'],
    'il capitolo con la copia nulla' => ['capitolo senza copia', ['Pulizia scale'], 'capitolo'],
    'la spesa con la copia negativa' => ['V2', ['Affitto antenna', 'Spese generali'], 'rimborso'],
]);

it('controprova — T297.51 la spesa scritta col meno di cui il piano tiene una copia positiva resta fra le sorgenti, e lo spostamento da lei passa con il totale com\'è', function () {
    $s = b53snV2($this);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => 24000]);

    expect(b53sspSorgenti($this, $s))->toBe(['Affitto antenna', 'Rimborso vecchio', 'Spese generali']);
    b53sspSposta($this, $s, (int) $s['rimborso']->id, (int) $s['generali']->id)->assertSessionHasNoErrors();

    expect(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(14000)
        ->and(b53sspCopie($s)[(int) $s['generali']->id])->toBe(130000)
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['pianoRate']['totale_spese'])->toBe(144000);
});

it('T297.57 la pagina dice al modale da quale menu il server ha tolto delle voci di spesa scritte con il meno: le sorgenti solo con l\'avviso', function () {
    $fuori = fn (array $s) => b53snProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']])['spese_negative_fuori_dai_menu'] ?? null;

    // Nel piano, con l'avviso: la voce manca da tutti e due i menu.
    $v2 = b53snV2($this);
    expect($fuori($v2))->toBe(['sorgenti' => true, 'destinazioni' => true]);

    // Fuori dal piano, senza avviso: la voce manca solo dalle destinazioni.
    $s = b53snBase($this);
    $s['piano'] = b52tPiano($this, $s, [], 'Ordinario 2026');
    $vecchia = b52tVoce($this, $s, 'Conguaglio vecchio', 'spesa', '50,00', $s['tabella']);
    b53snRiscrivi($vecchia, -5000);
    expect(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'])->toBeNull()
        ->and($fuori($s))->toBe(['sorgenti' => false, 'destinazioni' => true]);

    // Corretta la voce, non manca niente.
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => 5000]);
    expect($fuori($s))->toBe(['sorgenti' => false, 'destinazioni' => false]);
});

it('controprova — T297.57 il capitolo di entrate con la copia a zero e un sottoconto di entrata negativo: l\'avviso lo nomina e i due menu non lo offrono, come dicono le frasi del modale', function () {
    $s = b53snBase($this);
    $s['proventi'] = b52tVoce($this, $s, 'Proventi', 'entrata', '0,00', $s['tabella'], null, ['isCapitolo' => true, 'tabella_millesimale_id' => null,
        'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null]);
    b52tVoce($this, $s, 'Affitto sala', 'entrata', '600,00', $s['tabella'], $s['proventi']->id, ['isSottoConto' => true]);
    $rimborso = b52tVoce($this, $s, 'Rimborso', 'entrata', '100,00', $s['tabella'], $s['proventi']->id, ['isSottoConto' => true]);
    $s['piano'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['generali']->id, $s['antenna']->id, $s['proventi']->id], 'capitoli_config' => [
        ['id' => $s['generali']->id, 'importo' => '', 'note' => ''], ['id' => $s['antenna']->id, 'importo' => '', 'note' => ''], ['id' => $s['proventi']->id, 'importo' => '700,00', 'note' => ''],
    ]], 'Ordinario 2026');
    b53snRiscrivi($rimborso, -10000);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['proventi']->id)->update(['importo' => 0]);

    // Il capitolo è solo di entrate: la frase sui capitoli con spese ed entrate insieme non lo spiega, la spiegano le frasi sull'importo negativo.
    expect(\App\Services\Gestionale\BudgetMovementService::tipoDelSegno($s['proventi']->fresh(), $s['piano']->fresh()->created_at))->toBe('entrata');
    $avviso = b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'];
    expect($avviso['titolo'])->toBe('Il piano contiene una voce con un importo negativo')
        ->and($avviso['voci'][0])->toStartWith('Il sottoconto «Rimborso», sotto il capitolo «Proventi», ha un importo negativo (meno € 100,00)')
        ->and(b53snProps($this, 'admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']])['spese_negative_fuori_dai_menu'])->toBe(['sorgenti' => true, 'destinazioni' => true])
        ->and(b53sspSorgenti($this, $s))->toBe(['Affitto antenna', 'Spese generali'])
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Proventi')
        ->and(b53sspDestinazioni($this, $s))->not->toContain('Rimborso');

    // Dalla rotta il capitolo si rifiuta da tutte e due le parti, e il rifiuto rimanda all'avviso.
    b53sspSposta($this, $s, (int) $s['antenna']->id, (int) $s['proventi']->id);
    expect(b53snErrori()['destination_id'][0] ?? '')->toEndWith('Spostare budget su «Proventi» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.');
    session()->forget('errors');
    b53sspSposta($this, $s, (int) $s['proventi']->id, (int) $s['antenna']->id);
    expect(b53snErrori()['source_id'][0] ?? '')->toEndWith('Prelevare budget da «Proventi» cambierebbe il totale del piano: l\'avviso della pagina dice che cosa fare.');
});

// ─── L'entrata scritta col meno ─────────────────────────────────────────────────────────────────────────────────

/**
 * È il comportamento di oggi, e c'è dalla 1.9.0: «Sposta spesa» somma lo spostamento alla copia della destinazione, o all'importo della voce se la
 * copia è nulla (32c57e63), e il motore accredita un'entrata per il valore assoluto dell'importo (cfd1255e) e della copia (b3f2caa2); la 1.10.0
 * (f33f2ffd) ha già le due righe. Il controllo della spesa scritta col meno non riguarda le entrate.
 *
 * I conti a mano: «Spese generali» 120000, «Affitto antenna» copia 24000 e «Canone vecchio» −24000 (entrate 24000 + 24000 = 48000, da ripartire
 * 72000). € 100,00 dall'antenna al canone: copie 14000 e −14000, entrate 14000 + 14000 = 28000, da ripartire 92000, cioè 20000 in più, il doppio
 * dello spostamento.
 */
it('T297.53 comportamento di oggi: «Sposta spesa» verso un\'entrata scritta col meno passa e cambia il totale del piano del doppio dello spostamento', function (bool $copiaNulla) {
    $s = b53snBase($this);
    $canone = b52tVoce($this, $s, 'Canone vecchio', 'entrata', '240,00', $s['box']);
    $s['piano'] = b52tPiano($this, $s, [], 'Ordinario 2026');
    b53snRiscrivi($canone, -24000, [$s['piano']]);
    if ($copiaNulla) {
        DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $canone->id)->update(['importo' => null]);
    }
    $prima = b53snPaginaPiano($this, $s, $s['piano'])['pianoRate'];

    expect(b53sspDestinazioni($this, $s))->toContain('Canone vecchio');
    b53sspSposta($this, $s, (int) $s['antenna']->id, (int) $canone->id)->assertSessionHasNoErrors();

    $dopo = b53snPaginaPiano($this, $s, $s['piano'])['pianoRate'];
    expect([$prima['totale_spese'], $prima['totale_entrate'], $prima['totale_da_ripartire']])->toBe([120000, 48000, 72000])
        ->and(b53sspCopie($s))->toBe([(int) $s['generali']->id => 120000, (int) $s['antenna']->id => 14000, (int) $canone->id => -14000])
        ->and([$dopo['totale_spese'], $dopo['totale_entrate'], $dopo['totale_da_ripartire']])->toBe([120000, 28000, 92000])
        ->and(b53snRipartito($s, $s['piano']))->toBe([(int) $s['generali']->id => 120000, (int) $s['antenna']->id => -14000, (int) $canone->id => -14000]);
})->with(['con la copia negativa' => [false], 'con la copia nulla, che legge l\'importo della voce' => [true]]);

// ─── Ciò che resta com'era ──────────────────────────────────────────────────────────────────────────────────────

it('T297.47 controprova: fra due spese positive lo spostamento passa e il totale resta com\'è', function () {
    $s = b53snV2($this);
    $pulizia = b52tVoce($this, $s, 'Pulizia scale', 'spesa', '300,00', $s['tabella']);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $pulizia->id)->assertSessionHasNoErrors();

    $pagina = b53snPaginaPiano($this, $s, $s['piano']);
    expect(b53sspCopie($s)[(int) $s['generali']->id])->toBe(110000)
        ->and(b53sspCopie($s)[(int) $pulizia->id])->toBe(10000)
        ->and($pagina['pianoRate']['totale_spese'])->toBe(144000)
        ->and($pagina['avviso_spesa_negativa'])->not->toBeNull()
        ->and(b53sspDestinazioni($this, $s))->toContain('Pulizia scale', 'Spese generali');
});

it('T297.48 controprova: lo storno di un movimento già scritto passa anche se la voce che riceve è ora una spesa scritta col meno', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['piano'] = b52tPiano($this, $s, [], 'Ordinario 2026');
    b53sspSposta($this, $s, (int) $s['rimborso']->id, (int) $s['generali']->id)->assertSessionHasNoErrors();
    $movimento = BudgetMovement::where('piano_rate_id', $s['piano']->id)->firstOrFail();
    // Dopo lo spostamento la voce è riscritta col meno, e la sua copia con lei (14000 → −14000).
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => -24000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['rimborso']->id)->update(['importo' => -14000]);

    $this->actingAs($this->user)->from('/pagina-del-piano')->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s['c'], $s['piano'], $movimento]))
        ->assertSessionHasNoErrors();

    expect(BudgetMovement::where('piano_rate_id', $s['piano']->id)->where('reverses_movement_id', $movimento->id)->count())->toBe(1)
        ->and(b53sspCopie($s)[(int) $s['generali']->id])->toBe(120000)
        ->and(b53sspCopie($s)[(int) $s['rimborso']->id])->toBe(-4000);
});

it('T297.58 il capitolo che un altro piano approvato tiene con la copia, come destinazione: la frase dice la strada della bozza e non il provento, che si rifiuterebbe; la strada passa dalle rotte', function () {
    $s = b53bvScenario($this, ['impianti' => '300,00'], true, [], true);
    $prima = b53snRipartito($s, $s['b']);

    b53sspSposta($this, $s, (int) $s['generali']->id, (int) $s['impianti']->id);

    expect($prima)->toBe([(int) $s['rimborso']->id => 30000])
        ->and(b53snErrori())->toBe(['destination_id' => ['Il capitolo «Impianti» ha il sottoconto «Rimborso» con un importo negativo (meno € 100,00): '
            .'spostandoci budget, questo piano ripartirebbe la copia del capitolo anche sul sottoconto e lo addebiterebbe in positivo. L\'importo, il padre e il tipo '
            .'del sottoconto «Rimborso» non si cambiano finché il suo capitolo «Impianti» è nel piano «Preventivo B», approvato: riporta prima il piano in bozza dalla '
            .'sua pagina, poi correggi l\'importo nel piano dei conti; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Riportandolo in bozza si '
            .'azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.']]);

    // Senza la bozza il provento si rifiuta: «Preventivo B» non lo accredita. La strada della frase: la bozza, poi il provento senza il segno.
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['impianti']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(b53snRipartito($s, $s['b']))->toBe($prima);
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['b']]), ['approvato' => false])->assertSessionHasNoErrors();
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '100,00', (int) $s['impianti']->id)->assertSessionHasNoErrors();
    expect(b53bvVoce($s['rimborso']))->toBe(['tipo' => 'entrata', 'importo' => 10000, 'parent_id' => (int) $s['impianti']->id]);
});
