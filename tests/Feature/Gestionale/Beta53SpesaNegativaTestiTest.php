<?php

/**
 * Che cosa dicono il piano dei conti e il selettore della creazione di una voce di spesa scritta con il meno (dato delle versioni fino alla
 * 1.11.0-beta.51), secondo dove sta la voce.
 *
 * Dopo la correzione i totali contano la voce come il calcolo delle quote, in positivo (`Conto::partiDelTipo`): la riga scritta −€ 240,00 e il
 * preventivo che la conta + € 240,00 non tornano fra loro a vista, e la pagina lo dice nominando la voce. Correggere la voce è la strada solo se
 * nessun piano la tiene: con la voce in un piano approvato l'importo non si cambia («Modifica inibita»), e cambiarne il tipo lascia il piano con le
 * rate addebitate e un badge. Quindi, se un piano della gestione addebita la voce, i testi nominano il piano e rimandano alla sua pagina se il suo
 * avviso la nomina (la pagina dice le strade secondo lo stato del piano); la correzione si propone solo per la voce che nessun piano addebita. Se un
 * piano approvato tiene la voce o un capitolo sopra di lei senza addebitarla, la voce non cambia importo, padre né tipo lo stesso: la frase nomina quel
 * piano e dice la strada che passa (il piano riportato in bozza prima, poi la correzione o il provento), provata con la modifica della voce. Con delle
 * rate emesse la bozza vuole prima l'annullamento delle emissioni, e un'emissione con un incasso o un credito usato si annulla solo dopo quel movimento,
 * come quella seguita da un passaggio di titolarità di prima della 1.11.0-beta.42 solo dopo quel passaggio: la frase lo dice, e la strada si prova dalle
 * rotte, fino all'incasso, o al passaggio, registrato di nuovo. Un passaggio di prima della 1.11.0-beta.37 dal programma non si annulla: la frase non
 * propone la bozza, e dice che la voce resta com'è.
 *
 * La pagina del piano dei conti riceve `speseNegative`: una riga per voce di spesa non tecnica con l'importo negativo (anche un sottoconto), con
 * l'id, il nome, l'importo scritto (centesimi, col meno), i piani della gestione che la addebitano (`piani`: id, nome e `avviso`, vero se l'avviso
 * della pagina del piano nomina la voce) e i piani che ne bloccano l'importo (`bloccano`: id, nome, `capitolo`, il nome del capitolo se il piano
 * tiene lui, e `movimenti`, «incasso» e «credito» se sulle quote del piano c'è un incasso o un credito usato o rimborsato, «passaggio» se un passaggio
 * di titolarità di prima della 1.11.0-beta.42, registrato dopo l'emissione, ha preso il piano nel conguaglio, «passaggio_senza_registro» se quel
 * passaggio è di prima della 1.11.0-beta.37) e `terzo_livello`, vero per una voce al terzo livello del piano dei conti o più in basso (dato delle
 * versioni fino alla 1.9.1), la cui modifica si salva solo con un capitolo padre di primo livello: le frasi lo dicono prima della correzione. La frase
 * la compone la pagina (`ContiNewSpesaNegativa.test.ts`).
 *
 * Un piano addebita la voce se il motore la raggiunge: dalla sua riga, o dalla riga di un capitolo sopra di lei, per il ramo che il motore distribuisce
 * (`CalcoloQuoteService::processaConti`). Con la copia nulla il capitolo porta tutti i sottoconti con il loro importo; con una copia la divide fra i
 * sottoconti nati fino alla creazione del piano, se il capitolo non ha tabelle sue; con le sue tabelle la ripartisce lì e i sottoconti non contano. La
 * prova indipendente è il motore stesso, riletto in sola lettura (`b53snRipartito`).
 *
 * Il selettore della creazione non propone mai la voce: è spenta (`disabled`), non è uno sforo, il residuo è zero, `spesa_negativa` è vero e la nota
 * dice la strada (la pagina la mostra come suggerimento del badge «Importo negativo», `PianiRateNewSpesaNegativa.test.ts`). Il budget è quello
 * del calcolo, € 240,00.
 *
 * Fonte dell'atteso: le scelte di Vincenzo sulla voce di spesa negativa (frase, nota, piano nominato) e i conti a mano dello scenario.
 *
 * Cosa resta scoperto: la frase a video (la provano i vitest della pagina); un piano senza voci proprie (dei piani più vecchi), che legge tutte le voci
 * del piano dei conti senza tenerne la copia; la voce in un piano di un'altra gestione; i database diversi da sqlite.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** `speseNegative` della pagina del piano dei conti, in ordine di id. */
function b53snSpeseNegative($test, array $s): ?array
{
    $p = b53snPianoDeiConti($test, $s);
    if (! array_key_exists('speseNegative', $p)) {
        return null;
    }

    return collect(json_decode(json_encode($p['speseNegative']), true))->sortBy('id')->values()->all();
}

// ─── La pagina del piano dei conti ──────────────────────────────────────────────────────────────────────────────

it('T297.15 senza piano la pagina del piano dei conti nomina la voce di spesa negativa, con l\'importo scritto e nessun piano', function () {
    $s = b53snV0($this);

    expect(b53snSpeseNegative($this, $s))->toBe([
        ['id' => $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => false],
    ]);
});

it('T297.15 senza voci di spesa negative la lista c\'è ed è vuota', function () {
    $s = b53snBase($this);

    expect(b53snSpeseNegative($this, $s))->toBe([]);
});

it('T297.15-bis con la voce in un piano la pagina nomina anche il piano, in bozza, approvato o con una rata emessa', function (string $stato) {
    $s = b53snV2($this, $stato);

    // Il piano approvato ne blocca anche l'importo (`bloccano`); la pagina lo legge solo per una voce che nessun piano addebita.
    expect(b53snSpeseNegative($this, $s))->toBe([
        ['id' => $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true]],
            'bloccano' => $stato === 'bozza' ? [] : [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'capitolo' => null, 'movimenti' => []]], 'terzo_livello' => false],
    ]);
})->with(['bozza', 'approvato', 'emessa']);

it('T297.15-ter due voci di spesa negative, una nel piano e una nata dopo: una riga ciascuna, con i piani di ciascuna', function () {
    $s = b53snV2($this);
    $gas = b52tVoce($this, $s, 'Rimborso gas', 'spesa', '100,00', $s['tabella']);
    b53snRiscrivi($gas, -10000);

    expect(b53snSpeseNegative($this, $s))->toBe([
        ['id' => $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true]], 'bloccano' => [], 'terzo_livello' => false],
        ['id' => $gas->id, 'nome' => 'Rimborso gas', 'importo' => -10000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => false],
    ]);
});

it('T297.15-ter la voce addebitata da due piani li nomina tutti e due, e dice quale pagina ha l\'avviso: solo quella del piano con la copia negativa', function () {
    $s = b53snV3($this);

    expect(b53snSpeseNegative($this, $s))->toBe([
        ['id' => $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [
            ['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true], ['id' => $s['secondo']->id, 'nome' => 'Integrativo 2026', 'avviso' => false],
        ], 'bloccano' => [], 'terzo_livello' => false],
    ])
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'])->not->toBeNull()
        ->and(b53snPaginaPiano($this, $s, $s['secondo'])['avviso_spesa_negativa'])->toBeNull();
});

it('T297.15-ter un sottoconto di spesa negativo è nominato come una voce, e un\'entrata scritta col meno no', function () {
    $s = b53snCapitolo($this);
    $antenna = b52tVoce($this, $s, 'Affitto antenna', 'entrata', '240,00', $s['tabella']);
    b53snRiscrivi($antenna, -24000);

    expect(b53snSpeseNegative($this, $s))->toBe([
        ['id' => $s['rimborso']->id, 'nome' => 'Rimborso', 'importo' => -10000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => false],
    ]);
});

// ─── Il selettore della creazione ───────────────────────────────────────────────────────────────────────────────

/** La voce del selettore ridotta ai campi che decide il server per la spesa negativa. */
function b53snVoceDelSelettore($test, array $s, int $id): array
{
    $v = b53snSelettore($test, $s)->get($id) ?? [];

    return ['disabled' => $v['disabled'] ?? null, 'is_sforo' => $v['is_sforo'] ?? null, 'residuo' => (float) ($v['residuo'] ?? -1),
        'importo_totale' => (float) ($v['importo_totale'] ?? 0), 'spesa_negativa' => $v['spesa_negativa'] ?? null, 'note' => $v['note'] ?? null];
}

it('T297.16 senza piano il selettore spegne la voce, la segna come spesa negativa e dice di correggerla; il budget è € 240,00', function () {
    $s = b53snV0($this);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id))->toBe([
        'disabled' => true, 'is_sforo' => false, 'residuo' => 0.0, 'importo_totale' => 240.0, 'spesa_negativa' => true, 'note' => B53SN_NOTA_FUORI_DAI_PIANI,
    ]);
});

it('T297.16 con il piano di prima della correzione il selettore non propone più «Sforo da recuperare»: la voce è spenta e la nota nomina il piano', function () {
    $s = b53snV2($this);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id))->toBe([
        'disabled' => true, 'is_sforo' => false, 'residuo' => 0.0, 'importo_totale' => 240.0, 'spesa_negativa' => true, 'note' => B53SN_NOTA_NEL_PIANO,
    ]);
});

it('T297.16-bis con il piano approvato o con una rata emessa la nota del selettore nomina il piano e non propone la correzione', function (string $stato) {
    $s = b53snV2($this, $stato);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id))->toBe([
        'disabled' => true, 'is_sforo' => false, 'residuo' => 0.0, 'importo_totale' => 240.0, 'spesa_negativa' => true, 'note' => B53SN_NOTA_NEL_PIANO,
    ]);
})->with(['approvato', 'emessa']);

it('T297.16-bis la voce addebitata da due piani: spenta, e la nota nomina i due piani senza proporre la correzione', function () {
    $s = b53snV3($this);

    $voce = b53snVoceDelSelettore($this, $s, $s['rimborso']->id);

    expect([$voce['disabled'], $voce['is_sforo'], $voce['residuo'], $voce['spesa_negativa']])->toBe([true, false, 0.0, true])
        ->and(str_contains((string) $voce['note'], '«Ordinario 2026»'))->toBeTrue('la nota non nomina «Ordinario 2026»: '.$voce['note'])
        ->and(str_contains((string) $voce['note'], '«Integrativo 2026»'))->toBeTrue('la nota non nomina «Integrativo 2026»: '.$voce['note'])
        ->and(str_contains((string) $voce['note'], 'scegli «Entrata»'))->toBeFalse('la nota propone la correzione: '.$voce['note']);
});

it('controprova — le altre voci del selettore restano quelle di prima, senza il segno della spesa negativa', function () {
    $s = b53snV0($this);

    $sel = b53snSelettore($this, $s);

    expect([(float) $sel[$s['generali']->id]['residuo'], $sel[$s['generali']->id]['disabled'], $sel[$s['generali']->id]['note'], $sel[$s['generali']->id]['spesa_negativa'] ?? false])
        ->toBe([1200.0, false, 'Disp: € 1.200,00', false])
        ->and([(float) $sel[$s['antenna']->id]['residuo'], $sel[$s['antenna']->id]['disabled'], $sel[$s['antenna']->id]['note'], $sel[$s['antenna']->id]['spesa_negativa'] ?? false])
        ->toBe([240.0, false, 'Disp: € 240,00', false]);
});

// ─── Il sottoconto di un capitolo che è nel piano ───────────────────────────────────────────────────────────────

/** La nota del selettore per «Rimborso» di `b53snCapitoloNelPiano`, fuori da ogni piano: correggerlo è la strada. */
const B53SN_NOTA_RIMBORSO_FUORI = 'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '
    .'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

/** La stessa nota con il piano che lo addebita dal capitolo e ha l'avviso. */
const B53SN_NOTA_RIMBORSO_NEL_PIANO = 'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) ed è nel piano «Ordinario 2026», che la addebita in positivo: '
    .'la pagina del piano dice che cosa fare.';

it('T297.24 un sottoconto di spesa negativo il cui capitolo è nel piano con la copia nulla: il motore lo addebita, e pagina e selettore nominano il piano', function (string $stato, bool $natoDopo) {
    $s = b53snCapitoloNelPiano($this, '', $stato, natoDopo: $natoDopo);

    // il piano tiene solo il capitolo, e il motore porta il sottoconto con il suo importo, in positivo, nato prima o dopo il piano
    expect(b53snCopie($s, $s['capitolo']))->toBe([$s['piano']->id => null])
        ->and(b53snCopie($s, $s['rimborso']))->toBe([])
        ->and(b53snRipartito($s, $s['piano'])[$s['rimborso']->id] ?? 0)->toBe(10000)
        ->and(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa']['voci'] ?? [])
        ->toBe(['La voce «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebita in positivo, per € 100,00.'])
        ->and(b53snSpeseNegative($this, $s))->toBe([
            ['id' => $s['rimborso']->id, 'nome' => 'Rimborso', 'importo' => -10000, 'piani' => [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true]],
                'bloccano' => $stato === 'bozza' ? [] : [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'capitolo' => 'Manutenzione', 'movimenti' => []]], 'terzo_livello' => false],
        ])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe(B53SN_NOTA_RIMBORSO_NEL_PIANO);
})->with([
    'in bozza' => ['bozza', false],
    'approvato' => ['approvato', false],
    'approvato, il sottoconto nato dopo il piano' => ['approvato', true],
]);

it('T297.24-bis con una copia del capitolo e i sottoconti nati prima del piano il motore divide la copia anche sul sottoconto negativo: il piano si nomina', function () {
    $s = b53snCapitoloNelPiano($this, '700,00', 'approvato');

    // pesi 60000 − 10000 = 50000: «Ascensore» round(70000 × 60000 / 50000) = 84000, «Rimborso» il resto, 70000 − 84000 = −14000, addebitato in positivo
    expect(b53snCopie($s, $s['capitolo']))->toBe([$s['piano']->id => 70000])
        ->and(b53snRipartito($s, $s['piano'])[$s['rimborso']->id] ?? 0)->toBe(14000)
        ->and(b53snSpeseNegative($this, $s)[0]['piani'] ?? null)->toBe([['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'avviso' => true]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe(B53SN_NOTA_RIMBORSO_NEL_PIANO);
});

it('controprova — T297.24 dove il motore non raggiunge il sottoconto il piano non si nomina: la copia sulle tabelle del capitolo, il sottoconto nato dopo, la copia a zero', function (string $copia, bool $tabellaSulCapitolo, bool $natoDopo) {
    $s = b53snCapitoloNelPiano($this, $copia, 'bozza', $tabellaSulCapitolo, $natoDopo);

    expect(b53snRipartito($s, $s['piano'])[$s['rimborso']->id] ?? 0)->toBe(0)
        ->and(b53snSpeseNegative($this, $s))->toBe([['id' => $s['rimborso']->id, 'nome' => 'Rimborso', 'importo' => -10000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => false]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe(B53SN_NOTA_RIMBORSO_FUORI);
})->with([
    'la copia sulle tabelle del capitolo' => ['700,00', true, false],
    'il sottoconto nato dopo il piano' => ['600,00', false, true],
    'la copia del capitolo a zero' => ['0,00', false, false],
]);

// ─── La voce che nessun piano addebita, ma che un piano approvato blocca ────────────────────────────────────────

/**
 * La strada di «Rimborso» quando il suo capitolo è in un piano approvato che non lo addebita: la voce non cambia importo, padre né tipo («Modifica
 * inibita»), quindi la frase nomina il piano e dice la strada che passa, il piano riportato in bozza prima di correggere la voce.
 */
const B53SN_STRADA_BLOCCATA = 'L\'importo, il padre e il tipo della voce non si cambiano finché il suo capitolo «Manutenzione» è nel piano «Ordinario 2026», approvato: '
    .'riporta prima il piano in bozza dalla sua pagina, poi correggi l\'importo nel piano dei conti; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. '
    .'Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

/** La modifica di «Rimborso» dal piano dei conti, come la manda la scheda della voce. */
function b53snModificaRimborso($test, array $s, string $tipo, string $importo)
{
    session()->forget('errors');

    return $test->actingAs($test->user)->from('/piano-dei-conti')->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['rimborso']]), [
        'nome' => 'Rimborso', 'codice' => '', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '', 'parent_id' => $s['capitolo']->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ]);
}

it('T297.27 un sottoconto negativo sotto il capitolo di un piano approvato che non lo addebita: le frasi nominano il piano e non propongono la correzione che il piano rifiuta', function (string $copia, bool $tabellaSulCapitolo, bool $natoDopo) {
    $s = b53snCapitoloNelPiano($this, $copia, 'approvato', $tabellaSulCapitolo, $natoDopo);

    expect(b53snRipartito($s, $s['piano'])[$s['rimborso']->id] ?? 0)->toBe(0)
        ->and(b53snSpeseNegative($this, $s))->toBe([['id' => $s['rimborso']->id, 'nome' => 'Rimborso', 'importo' => -10000, 'piani' => [],
            'bloccano' => [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'capitolo' => 'Manutenzione', 'movimenti' => []]], 'terzo_livello' => false]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_STRADA_BLOCCATA);
    // Il sottoconto nato dopo il piano è anche fra le voci scoperte del cruscotto, con la stessa nota; quello sulle tabelle del capitolo no.
    $orfano = collect(b53snCruscotto($this, $s)['orfani'])->firstWhere('nome', 'Rimborso');
    expect($orfano['nota'] ?? null)->toBe($natoDopo
        ? 'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_STRADA_BLOCCATA
        : null);

    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '100,00', 'note' => '']]]);
    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)
        ->toBe(['La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo. '.B53SN_STRADA_BLOCCATA]);

    // Con il piano approvato il server rifiuta la correzione e il provento, che la frase non propone; la strada della frase, la bozza e poi la
    // correzione o il provento, passa.
    b53snModificaRimborso($this, $s, 'spesa', '100,00');
    expect(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita');
    b53snModificaRimborso($this, $s, 'entrata', '-100,00');
    expect(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(\App\Models\Gestionale\Conto::find($s['rimborso']->id)->tipo)->toBe('spesa');

    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    DB::beginTransaction();
    b53snModificaRimborso($this, $s, 'entrata', '100,00')->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Conto::find($s['rimborso']->id)->tipo)->toBe('entrata');
    DB::rollBack();
    b53snModificaRimborso($this, $s, 'spesa', '100,00')->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);
})->with([
    'la copia sulle tabelle del capitolo' => ['700,00', true, false],
    'il sottoconto nato dopo il piano' => ['600,00', false, true],
]);

it('T297.27 la voce di primo livello con la copia a zero in un piano approvato: la frase dice che il piano tiene la voce', function () {
    $s = b53snBase($this);
    $s['rimborso'] = b52tVoce($this, $s, 'Rimborso vecchio', 'spesa', '240,00', $s['tabella']);
    $s['piano'] = b52tPiano($this, $s, ['capitoli_ids' => [$s['generali']->id, $s['rimborso']->id], 'capitoli_config' => [
        ['id' => $s['generali']->id, 'importo' => '', 'note' => ''], ['id' => $s['rimborso']->id, 'importo' => '0,00', 'note' => ''],
    ], 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova'], 'Ordinario 2026');
    b52tApprova($this, $s, $s['piano']);
    b53snRiscrivi($s['rimborso'], -24000);

    expect(b53snCopie($s, $s['rimborso']))->toBe([$s['piano']->id => 0])
        ->and(b53snSpeseNegative($this, $s))->toBe([['id' => $s['rimborso']->id, 'nome' => 'Rimborso vecchio', 'importo' => -24000, 'piani' => [],
            'bloccano' => [['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'capitolo' => null, 'movimenti' => []]], 'terzo_livello' => false]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. '
            .'L\'importo, il padre e il tipo della voce non si cambiano finché la voce è nel piano «Ordinario 2026», approvato: riporta prima il piano in bozza '
            .'dalla sua pagina, poi correggi l\'importo nel piano dei conti; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Riportandolo in '
            .'bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.');
});

it('controprova — T297.27 con il piano in bozza la frase propone ancora la correzione, e la correzione passa', function () {
    $s = b53snCapitoloNelPiano($this, '700,00', 'bozza', true);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe(B53SN_NOTA_RIMBORSO_FUORI);
    b53snModificaRimborso($this, $s, 'spesa', '100,00')->assertSessionHasNoErrors();
});

// ─── La voce bloccata da un piano approvato con delle rate emesse ───────────────────────────────────────────────

/** La stessa strada di «Rimborso» con un incasso registrato su una rata emessa del piano: l'emissione si annulla solo dopo aver annullato l'incasso. */
const B53SN_STRADA_BLOCCATA_CON_INCASSI = 'L\'importo, il padre e il tipo della voce non si cambiano finché il suo capitolo «Manutenzione» è nel piano «Ordinario 2026», approvato: '
    .'riporta prima il piano in bozza dalla sua pagina, poi correggi l\'importo nel piano dei conti; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. '
    .'Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullati '
    .'gli incassi registrati sulle rate (da registrare di nuovo dopo), poi le emissioni.';

/** Il capitolo «Manutenzione» sulle sue tabelle nel piano approvato, la prima rata emessa e, con `$incasso`, la quota di Ugo pagata per intero. */
function b53snBloccataConRate($test, bool $incasso): array
{
    $s = b53snCapitoloNelPiano($test, '700,00', 'approvato', true);
    b52tEmetti($test, $s, $s['piano'], 1, false);
    if ($incasso) {
        $quota = b52tQuotaId($s, $s['piano'], 'ugo', 'A', 1);
        $importo = (int) DB::table('rate_quote')->where('id', $quota)->value('importo');
        b52tIncasso($test, $s, 'ugo', [$quota => $importo], $importo);
        $s['quota'] = $quota;
        $s['importo_quota'] = $importo;
    }

    return $s;
}

/** L'annullamento dell'emissione della prima rata dalla pagina del piano: il messaggio di sessione. */
function b53snAnnullaEmissione($test, array $s): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], b52tRata($s['piano'], 1)]));

    return b53snFlash();
}

it('T297.52 con una rata emessa senza incassi la frase dice di annullare l\'emissione prima della bozza, e la strada passa dalle rotte', function () {
    $s = b53snBloccataConRate($this, false);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_STRADA_BLOCCATA);

    b53snModificaRimborso($this, $s, 'spesa', '100,00');
    expect(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(b53snAnnullaEmissione($this, $s)['type'] ?? null)->toBe('success')
        ->and($s['piano']->fresh()->haRateEmesse())->toBeFalse();
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaRimborso($this, $s, 'spesa', '100,00')->assertSessionHasNoErrors();

    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);
});

it('T297.52 con un incasso su una rata emessa l\'emissione non si annulla: la frase dice di annullare prima l\'incasso, e la strada passa dalle rotte fino all\'incasso registrato di nuovo', function () {
    $s = b53snBloccataConRate($this, true);
    $testa = 'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo';

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe($testa.', per € 100,00. '.B53SN_STRADA_BLOCCATA_CON_INCASSI)
        ->and(b53snSpeseNegative($this, $s)[0]['bloccano'] ?? null)->toBe([['id' => $s['piano']->id, 'nome' => 'Ordinario 2026', 'capitolo' => 'Manutenzione', 'movimenti' => ['incasso']]]);
    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '100,00', 'note' => '']]]);
    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)->toBe([$testa.'. '.B53SN_STRADA_BLOCCATA_CON_INCASSI]);

    // La strada di prima finisce nel rifiuto dell'annullamento: c'è un incasso sulla rata.
    expect(b53snAnnullaEmissione($this, $s))->toBe(['type' => 'error', 'message' => 'Impossibile annullare: ci sono già incassi registrati, o crediti già usati o rimborsati su questa rata.'])
        ->and($s['piano']->fresh()->haRateEmesse())->toBeTrue();

    // La strada della frase: l'incasso annullato dalla pagina «Incassi rate», poi l'emissione, la bozza e la correzione.
    $incasso = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->latest('id')->value('id');
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    expect(b53snAnnullaEmissione($this, $s)['type'] ?? null)->toBe('success');
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaRimborso($this, $s, 'spesa', '100,00')->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);

    // «Da registrare di nuovo dopo»: il piano si approva, la rata si emette e l'incasso si registra come prima.
    b52tApprova($this, $s, $s['piano']);
    b52tEmetti($this, $s, $s['piano'], 1, false);
    $quota = b52tQuotaId($s, $s['piano'], 'ugo', 'A', 1);
    b52tIncasso($this, $s, 'ugo', [$quota => $s['importo_quota']], $s['importo_quota']);

    expect((int) DB::table('rate_quote')->where('id', $quota)->value('importo_pagato'))->toBe($s['importo_quota']);
});

it('T297.52 con i movimenti su uno solo dei piani che bloccano la voce la frase nomina quello, e dice anche i crediti usati o rimborsati', function () {
    $nota = \App\Services\Riparto\VoceDiSpesaNegativa::nota('Rimborso vecchio', -24000, [], [
        ['id' => 7, 'nome' => 'Ordinario 2026', 'capitolo' => null, 'movimenti' => []],
        ['id' => 9, 'nome' => 'Integrativo 2026', 'capitolo' => null, 'movimenti' => ['incasso', 'credito']],
    ]);

    expect($nota)->toEndWith('Riportandoli in bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullati '
        .'gli incassi registrati e i crediti usati o rimborsati sulle rate di «Integrativo 2026» (da registrare di nuovo dopo), poi le emissioni.');
});

it('T297.52 con un credito usato su una rata emessa la frase nomina anche i crediti, e la strada passa dalle rotte', function () {
    // Il capitolo sulle sue tabelle come in `b53snCapitoloNelPiano`, con il metodo «rata zero» e un saldo a credito di Ugo (−€ 50,00): la quota di Ugo
    // della rata 0 vale −5000; quella della rata 1, 2917, si paga con il credito.
    $s = b53snCapitolo($this);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['importo' => 10000]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $s['capitolo']->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    \App\Models\Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => -5000, 'origine' => 'manuale', 'is_applicato' => false]);
    $s['piano'] = b52tPiano($this, $s, ['metodo_distribuzione' => 'rata_zero', 'capitoli_ids' => [$s['capitolo']->id],
        'capitoli_config' => [['id' => $s['capitolo']->id, 'importo' => '700,00', 'note' => '']]], 'Ordinario 2026');
    b53snRiscrivi($s['rimborso'], -10000);
    b52tApprova($this, $s, $s['piano']);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [b52tRata($s['piano'], 0), b52tRata($s['piano'], 1)], 'data_emissione' => '2026-01-31', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    $credito = b52tQuotaId($s, $s['piano'], 'ugo', 'A', 0);
    $rata1 = b52tQuotaId($s, $s['piano'], 'ugo', 'A', 1);
    expect([(int) DB::table('rate_quote')->where('id', $credito)->value('importo'), (int) DB::table('rate_quote')->where('id', $rata1)->value('importo')])->toBe([-5000, 2917]);
    b52tIncasso($this, $s, 'ugo', [$rata1 => 2917, $credito => -2917], 0);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toEndWith('annotali prima; con delle rate emesse, prima vanno annullati gli incassi registrati '
        .'e i crediti usati o rimborsati sulle rate (da registrare di nuovo dopo), poi le emissioni.')
        ->and(b53snSpeseNegative($this, $s)[0]['bloccano'][0]['movimenti'] ?? null)->toBe(['incasso', 'credito']);

    // Le emissioni rifiutano, finché c'è il credito usato; annullato quel movimento, le emissioni si annullano, poi la bozza e la correzione.
    expect(b53snAnnullaEmissione($this, $s)['type'] ?? null)->toBe('error');
    $movimento = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->latest('id')->value('id');
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $movimento]))->assertSessionHasNoErrors();
    foreach ([1, 0] as $n) {
        session()->forget('message');
        $this->actingAs($this->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], b52tRata($s['piano'], $n)]));
        expect(b53snFlash()['type'] ?? null)->toBe('success');
    }
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaRimborso($this, $s, 'spesa', '100,00')->assertSessionHasNoErrors();

    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);
});

/**
 * La voce «Rimborso» (−10000) con la riga a zero nel «Preventivo 2026» approvato, accanto a «Spese generali»; la rata 1 emessa; poi una vendita
 * dell'unità di Ugo a Elsa registrata dopo l'emissione, resa «di prima della 1.11.0-beta.42» togliendo `piani_presi` dal registro: il piano risulta
 * preso nel conguaglio grazie alle quote già a giornale, e l'annullamento dell'emissione lo rifiuta finché il passaggio c'è
 * (`EmissioneRateController::destroy`, decisione 49). `$dopoLaVendita` riceve lo scenario subito dopo la vendita, prima che il registro cambi.
 */
function b53snPassaggioDiPrima($test, ?callable $dopoLaVendita = null): array
{
    $c = \App\Models\Condominio::factory()->create();
    $e = \App\Models\Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $tabella = \App\Models\Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $generali = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $rimborso = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Rimborso', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    foreach ([$generali, $rimborso] as $voce) {
        $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unita = \App\Models\Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SN-1', 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $banca = \App\Models\Gestionale\ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    foreach ([['10.20', 'Crediti verso condomini', 'attivo', 'crediti_condomini', 'crediti'], ['20.10', 'Anticipi', 'passivo', 'anticipi_condomini', 'debiti'],
        ['20.20', 'Gestione rate', 'passivo', 'gestione_rate', 'debiti'], ['30.10', 'Passate gestioni', 'passivo', 'passate_gestioni', 'debiti']] as [$codice, $nome, $tipo, $ruolo, $categoria]) {
        \App\Models\Gestionale\ContoContabile::create(['condominio_id' => $c->id, 'codice' => $codice, 'nome' => $nome, 'tipo' => $tipo, 'ruolo' => $ruolo, 'categoria' => $categoria]);
    }
    \App\Models\Gestionale\Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
    $ugo = \App\Models\Anagrafica::forceCreate(['nome' => 'Ugo Venditore', 'email' => 'sn-ugo@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SNVENDITORE00001']);
    $elsa = \App\Models\Anagrafica::forceCreate(['nome' => 'Elsa Acquirente', 'email' => 'sn-elsa@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'SNACQUIRENT00001']);
    $ugo->condomini()->syncWithoutDetaching([$c->id]);
    $elsa->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ugo->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $piano = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
    ]);
    DB::table('piano_rate_capitoli')->insert([
        ['piano_rate_id' => $piano->id, 'conto_id' => $generali->id, 'importo' => null, 'created_at' => now(), 'updated_at' => now()],
        ['piano_rate_id' => $piano->id, 'conto_id' => $rimborso->id, 'importo' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$c, $piano]), ['rate_ids' => [$rata1], 'data_emissione' => '2026-01-05', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    $test->travel(1)->minutes();
    $s = ['c' => $c, 'e' => $e, 'g' => $g, 'pc' => $pc, 'tabella' => $tabella, 'generali' => $generali, 'rimborso' => $rimborso, 'piano' => $piano, 'rata1' => $rata1,
        'unita' => $unita, 'ugo' => $ugo, 'elsa' => $elsa];
    b53snVendita($test, $s)->assertSessionHasNoErrors();
    if ($dopoLaVendita !== null) {
        $dopoLaVendita($s);
    }
    $passaggio = \App\Models\Gestionale\Subentro::sole();
    $registro = $passaggio->registro;
    unset($registro['piani_presi']);
    $passaggio->update(['registro' => array_replace($registro, ['versione' => 1])]);
    DB::table('conti')->where('id', $rimborso->id)->update(['importo' => -10000]);
    $s['rimborso'] = $rimborso->fresh();
    $s['passaggio'] = $passaggio->fresh();

    return $s;
}

/** La vendita dell'unità di Ugo a Elsa dalla rotta di «Registra passaggio», dal 1° maggio 2026: la risposta. */
function b53snVendita($test, array $s)
{
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['ugo']->id)->where('immobile_id', $s['unita']->id)->whereNull('data_fine')->value('id');

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $s['elsa']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-01',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, piano da ricalcolare',
    ]);
}

/** Che cosa annullare prima delle emissioni, con un passaggio di prima della 1.11.0-beta.42 registrato dopo l'emissione: la coda della nota. */
const B53SN_PRIMA_I_PASSAGGI = 'prima vanno annullati, ciascuno dallo storico della sua unità, i passaggi di titolarità registrati dopo l\'emissione (da registrare '
    .'di nuovo dopo), poi le emissioni; se dopo un passaggio sono state emesse altre rate, il suo annullamento chiede di annullare prima quelle.';

/** «Annulla passaggio» dallo storico dell'unità: il messaggio del rifiuto (vuoto se non c'è) e se il passaggio è ancora attivo. */
function b53snAnnullaPassaggio($test, array $s): array
{
    session()->forget(['errors', 'message']);
    $r = $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $s['passaggio']]), ['nota_annullamento' => 'Da registrare di nuovo']);

    return ['rifiuto' => $r->status() === 422 ? (string) (json_decode((string) $r->getContent(), true)['message'] ?? '') : '',
        'attivo' => \App\Models\Gestionale\Subentro::query()->whereKey($s['passaggio']->id)->exists()];
}

/** «Annulla emissione» della rata data dalla pagina del piano: il messaggio di sessione. */
function b53snAnnullaLaRata($test, array $s, int $rata): array
{
    session()->forget('message');
    $test->actingAs($test->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], $rata]));

    return b53snFlash() ?? [];
}

it('T297.56 con un passaggio di prima della 1.11.0-beta.42 registrato dopo l\'emissione la frase dice di annullarlo prima delle emissioni, e la strada passa dalle rotte', function () {
    $s = b53snPassaggioDiPrima($this);

    expect(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id]))
        ->toBe([(int) $s['rimborso']->id => [['id' => (int) $s['piano']->id, 'nome' => 'Preventivo 2026', 'capitolo' => null, 'movimenti' => ['passaggio']]]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. L\'importo, il padre e '
            .'il tipo della voce non si cambiano finché la voce è nel piano «Preventivo 2026», approvato: riporta prima il piano in bozza dalla sua pagina, poi '
            .'correggi l\'importo nel piano dei conti; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Riportandolo in bozza si azzerano il '
            .'numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, '.B53SN_PRIMA_I_PASSAGGI);

    // L'emissione non si annulla finché c'è il passaggio; annullato quello, si annulla l'emissione, poi la bozza, la correzione e il passaggio di nuovo.
    session()->forget('message');
    $this->actingAs($this->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], $s['rata1']]));
    expect(b53snFlash()['message'] ?? '')->toStartWith('Un passaggio di titolarità registrato dopo l\'emissione ha preso questo piano nel conguaglio');

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $s['passaggio']]), ['nota_annullamento' => 'Da registrare di nuovo'])
        ->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id])[(int) $s['rimborso']->id][0]['movimenti'])->toBe([]);
    session()->forget('message');
    $this->actingAs($this->user)->from('/pagina-del-piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], $s['rata1']]));
    expect(b53snFlash()['type'] ?? null)->toBe('success');
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', null)->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);

    $this->travel(1)->minutes();
    b53snVendita($this, $s)->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Subentro::query()->whereNull('annullato_il')->count())->toBe(1);
});

it('T297.56 la frase con l\'incasso e il passaggio, e con il passaggio su uno solo dei piani: li nomina tutti e due, e il piano che ha il passaggio', function () {
    $insieme = \App\Services\Riparto\VoceDiSpesaNegativa::nota('Rimborso', -10000, [], [['id' => 7, 'nome' => 'Preventivo 2026', 'capitolo' => null, 'movimenti' => ['incasso', 'passaggio']]]);
    $parziale = \App\Services\Riparto\VoceDiSpesaNegativa::nota('Rimborso', -10000, [], [
        ['id' => 7, 'nome' => 'Preventivo 2026', 'capitolo' => null, 'movimenti' => ['passaggio']],
        ['id' => 9, 'nome' => 'Integrativo 2026', 'capitolo' => null, 'movimenti' => ['credito']],
    ]);

    expect($insieme)->toEndWith('con delle rate emesse, prima vanno annullati gli incassi registrati sulle rate e, ciascuno dallo storico della sua unità, i passaggi '
        .'di titolarità registrati dopo l\'emissione (da registrare di nuovo dopo), poi le emissioni; se dopo un passaggio sono state emesse altre rate, il suo '
        .'annullamento chiede di annullare prima quelle.')
        ->and($parziale)->toEndWith('con delle rate emesse, prima vanno annullati i crediti usati o rimborsati sulle rate di «Integrativo 2026» e, ciascuno dallo storico '
            .'della sua unità, i passaggi di titolarità registrati dopo l\'emissione delle rate di «Preventivo 2026» (da registrare di nuovo dopo), poi le emissioni; '
            .'se dopo un passaggio sono state emesse altre rate, il suo annullamento chiede di annullare prima quelle.');
});

it('controprova — T297.56 un passaggio della 1.11.0-beta.42, con `piani_presi`, non si nomina: l\'emissione si annulla senza annullarlo', function () {
    $s = b53snPassaggioDiPrima($this);
    $registro = $s['passaggio']->registro;
    $s['passaggio']->update(['registro' => array_replace($registro, ['piani_presi' => [(int) $s['piano']->id]])]);

    expect(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id])[(int) $s['rimborso']->id][0]['movimenti'])->toBe([]);
});

it('T297.56 con una rata passata a chi entra ed emessa dopo il passaggio, l\'annullamento del passaggio chiede prima quella: la frase lo dice, e la strada passa dalle rotte', function () {
    $test = $this;
    $s = b53snPassaggioDiPrima($this, function (array $s) use ($test) {
        $test->travel(1)->minutes();
        $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), ['rate_ids' => [b52tRata($s['piano'], 6)], 'data_emissione' => '2026-06-05', 'invia_notifiche' => false])
            ->assertSessionHasNoErrors();
    });
    $rata6 = b52tRata($s['piano'], 6);

    expect(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id])[(int) $s['rimborso']->id][0]['movimenti'])->toBe(['passaggio'])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toEndWith('con delle rate emesse, '.B53SN_PRIMA_I_PASSAGGI);

    // Il passaggio chiede prima l'annullamento della rata 6; poi il passaggio, l'emissione della rata 1, la bozza, la correzione e il passaggio di nuovo.
    $primo = b53snAnnullaPassaggio($this, $s);
    expect($primo['attivo'])->toBeTrue()
        ->and($primo['rifiuto'])->toContain('la rata 6 del piano «Preventivo 2026»')
        ->and(b53snAnnullaLaRata($this, $s, $rata6)['type'] ?? null)->toBe('success')
        ->and(b53snAnnullaPassaggio($this, $s))->toBe(['rifiuto' => '', 'attivo' => false])
        ->and(b53snAnnullaLaRata($this, $s, $s['rata1'])['type'] ?? null)->toBe('success');
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', null)->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);

    $this->travel(1)->minutes();
    b53snVendita($this, $s)->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Subentro::query()->whereNull('annullato_il')->count())->toBe(1);
});

it('T297.56 con la rata segnata «emessa» senza scritture (dati fino alla 1.11.0-beta.41) il passaggio si nomina lo stesso, e la strada passa dalle rotte', function () {
    $s = b53snPassaggioDiPrima($this);
    DB::table('rate_quote')->where('rata_id', $s['rata1'])->update(['scrittura_contabile_id' => null]);

    expect(DB::table('rate')->where('id', $s['rata1'])->value('stato'))->toBe('emessa')
        ->and(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id])[(int) $s['rimborso']->id][0]['movimenti'])->toBe(['passaggio'])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toEndWith('con delle rate emesse, '.B53SN_PRIMA_I_PASSAGGI)
        ->and(b53snAnnullaLaRata($this, $s, $s['rata1'])['message'] ?? '')->toStartWith('Un passaggio di titolarità registrato dopo l\'emissione ha preso questo piano nel conguaglio');

    expect(b53snAnnullaPassaggio($this, $s))->toBe(['rifiuto' => '', 'attivo' => false])
        ->and(b53snAnnullaLaRata($this, $s, $s['rata1'])['type'] ?? null)->toBe('success');
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', null)->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);

    $this->travel(1)->minutes();
    b53snVendita($this, $s)->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Subentro::query()->whereNull('annullato_il')->count())->toBe(1);
});

it('T297.56 con un passaggio di prima della 1.11.0-beta.37, che dal programma non si annulla, la frase non propone né la bozza né il provento e dice che la voce resta com\'è: le due modifiche si rifiutano', function () {
    $s = b53snPassaggioDiPrima($this);
    DB::table('subentri')->where('id', $s['passaggio']->id)->update(['registro' => null]);

    expect(\App\Models\Gestionale\Conto::pianiCheBloccanoLaVoce([(int) $s['rimborso']->id])[(int) $s['rimborso']->id][0]['movimenti'])->toBe(['passaggio_senza_registro'])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. L\'importo, il padre e '
            .'il tipo della voce non si cambiano finché la voce è nel piano «Preventivo 2026», approvato, e un passaggio di titolarità registrato prima della '
            .'1.11.0-beta.37, che dal programma non si annulla, ferma l\'annullamento delle emissioni del piano: la voce resta com\'è.');

    // La strada della bozza è chiusa: il passaggio non si annulla dal programma, l'emissione resta rifiutata e l'importo non si cambia.
    $passaggio = b53snAnnullaPassaggio($this, $s);
    expect($passaggio['attivo'])->toBeTrue()
        ->and($passaggio['rifiuto'])->toContain('non si annulla dal programma')
        ->and(b53snAnnullaLaRata($this, $s, $s['rata1'])['type'] ?? null)->toBe('error');
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', null);
    expect(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita');

    // Il provento, con il tipo cambiato e l'importo com'è, si rifiuta come la correzione: la voce resta com'è.
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', null);
    $voce = \App\Models\Gestionale\Conto::find($s['rimborso']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toEndWith('ferma l\'annullamento delle emissioni del piano: la voce resta com\'è.')
        ->and([$voce->tipo, (int) $voce->importo])->toBe(['spesa', -10000])
        ->and(count(b53snSpeseNegative($this, $s)))->toBe(1);
});

it('T297.56 la frase con il passaggio di prima della 1.11.0-beta.37 su uno solo dei piani: nomina quel piano, e non propone la bozza', function () {
    $nota = \App\Services\Riparto\VoceDiSpesaNegativa::nota('Rimborso', -10000, [], [
        ['id' => 7, 'nome' => 'Preventivo 2026', 'capitolo' => null, 'movimenti' => ['passaggio_senza_registro']],
        ['id' => 9, 'nome' => 'Integrativo 2026', 'capitolo' => null, 'movimenti' => ['incasso']],
    ]);

    expect($nota)->toEndWith('L\'importo, il padre e il tipo della voce non si cambiano finché la voce è nei piani «Preventivo 2026» e «Integrativo 2026», approvati, '
        .'e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che dal programma non si annulla, ferma l\'annullamento delle emissioni di '
        .'«Preventivo 2026»: la voce resta com\'è.')
        ->and($nota)->not->toContain('bozza');
});

// ─── La voce al terzo livello ───────────────────────────────────────────────────────────────────────────────────

/** La strada per la voce sotto un sottoconto che nessun piano blocca: la sua modifica si salva solo con un capitolo padre di primo livello. */
const B53SN_TERZO_LIVELLO = 'La voce è sotto un sottoconto: nel piano dei conti, che non lo prevede, scegli prima come suo capitolo padre una voce di primo livello, '
    .'poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';

it('T297.55 la voce di spesa negativa al terzo livello: le frasi dicono di sceglierle prima un capitolo padre di primo livello, e la strada passa dalla sua modifica', function (string $tipo) {
    $s = b53snTerzoLivello($this);

    expect(b53snSpeseNegative($this, $s))->toBe([['id' => $s['rimborso']->id, 'nome' => 'Rimborso', 'importo' => -10000, 'piani' => [], 'bloccano' => [], 'terzo_livello' => true]])
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_TERZO_LIVELLO);
    // La voce è anche fra le voci scoperte del cruscotto, con la stessa nota.
    expect(collect(b53snCruscotto($this, $s)['orfani'])->firstWhere('nome', 'Rimborso')['nota'] ?? null)
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_TERZO_LIVELLO);

    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => [['id' => $s['rimborso']->id, 'importo' => '100,00', 'note' => '']]]);
    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)
        ->toBe(['La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo. '.B53SN_TERZO_LIVELLO]);

    // La correzione con il padre di adesso il server la rifiuta; con un capitolo padre di primo livello passa.
    b53snModificaVoce($this, $s, $s['rimborso'], $tipo, '100,00', (int) $s['impianti']->id);
    expect(b53snErrori()['parent_id'][0] ?? '')->toStartWith('Questa voce si trova al terzo livello');
    b53snModificaVoce($this, $s, $s['rimborso'], $tipo, '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    $voce = \App\Models\Gestionale\Conto::find($s['rimborso']->id);
    expect([(int) $voce->importo, (int) $voce->parent_id, $voce->tipo])->toBe([10000, (int) $s['capitolo']->id, $tipo])
        ->and(b53snSpeseNegative($this, $s))->toBe([]);
})->with(['la spesa' => ['spesa'], 'il provento' => ['entrata']]);

it('T297.55 la voce al terzo livello in un piano approvato che non la addebita: la frase dice anche del capitolo padre, e la strada passa', function () {
    $s = b53snTerzoLivello($this);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['rimborso']->id, 'importo' => 0, 'created_at' => now(), 'updated_at' => now()]);
    b52tApprova($this, $s, $s['piano']);
    $bloccata = 'La voce è sotto un sottoconto: nel piano dei conti, che non lo prevede, ogni sua modifica chiede come capitolo padre una voce di primo livello. '
        .'L\'importo, il padre e il tipo della voce non si cambiano finché la voce è nel piano «Ordinario 2026», approvato: riporta prima il piano in bozza dalla '
        .'sua pagina, poi scegli il capitolo padre e correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Riportandolo in '
        .'bozza si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

    expect(b53snSpeseNegative($this, $s)[0]['terzo_livello'] ?? null)->toBeTrue()
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.$bloccata);

    // Con il piano approvato si rifiutano il padre com'è, il padre nuovo con il provento e la correzione.
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['impianti']->id);
    expect(b53snErrori()['parent_id'][0] ?? '')->toStartWith('Questa voce si trova al terzo livello');
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '-100,00', (int) $s['capitolo']->id);
    expect(b53snErrori()['tipo'][0] ?? '')->toStartWith('Modifica inibita');
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id);
    expect(b53snErrori()['importo'][0] ?? '')->toStartWith('Modifica inibita')
        ->and(\App\Models\Gestionale\Conto::find($s['rimborso']->id)->tipo)->toBe('spesa');

    // La strada della frase: il piano in bozza, poi il capitolo padre e la correzione, o il provento.
    $this->actingAs($this->user)->from('/pagina-del-piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])
        ->assertSessionHasNoErrors();
    DB::beginTransaction();
    b53snModificaVoce($this, $s, $s['rimborso'], 'entrata', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect(\App\Models\Gestionale\Conto::find($s['rimborso']->id)->tipo)->toBe('entrata');
    DB::rollBack();
    b53snModificaVoce($this, $s, $s['rimborso']->fresh(), 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();
    expect((int) \App\Models\Gestionale\Conto::find($s['rimborso']->id)->importo)->toBe(10000);
});

it('T297.55 la voce di spesa negativa al quarto livello: la frase dice che è sotto un sottoconto, e la strada passa dalla sua modifica', function () {
    $s = b53snTerzoLivello($this);
    $ascensori = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Ascensori', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria',
        'importo' => 0, 'parent_id' => $s['impianti']->id, 'is_capitolo' => true]);
    DB::table('conti')->where('id', $s['rimborso']->id)->update(['parent_id' => $ascensori->id]);
    $s['rimborso'] = $s['rimborso']->fresh();

    expect(b53snSpeseNegative($this, $s)[0]['terzo_livello'] ?? null)->toBeTrue()
        ->and(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])
        ->toBe('La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00. '.B53SN_TERZO_LIVELLO);

    // La strada della frase: con il padre di adesso il server rifiuta, con un capitolo padre di primo livello la correzione passa.
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $ascensori->id);
    expect(b53snErrori()['parent_id'][0] ?? '')->toStartWith('Questa voce si trova al terzo livello');
    b53snModificaVoce($this, $s, $s['rimborso'], 'spesa', '100,00', (int) $s['capitolo']->id)->assertSessionHasNoErrors();

    $voce = \App\Models\Gestionale\Conto::find($s['rimborso']->id);
    expect([(int) $voce->importo, (int) $voce->parent_id])->toBe([10000, (int) $s['capitolo']->id])
        ->and(b53snSpeseNegative($this, $s))->toBe([]);
});

it('controprova — T297.55 il sottoconto di secondo livello resta con la correzione di prima, senza il capitolo padre', function () {
    $s = b53snCapitoloNelPiano($this, '700,00', 'bozza', true);

    expect(b53snVoceDelSelettore($this, $s, $s['rimborso']->id)['note'])->toBe(B53SN_NOTA_RIMBORSO_FUORI)
        ->and(b53snSpeseNegative($this, $s)[0]['terzo_livello'] ?? null)->toBeFalse();
});
