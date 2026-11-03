<?php

/**
 * La tabella millesimale dell'imprevisto di una pregressa sta nel condominio dell'indirizzo.
 *
 * Una fattura pregressa con un'eccedenza non coperta crea una voce nel piano dei conti («Debito pregresso …») e la lega alla tabella millesimale che
 * l'amministratore sceglie (`dati_extra.log_legale_sopravvenienza.tabella_millesimale_id`). Con la tabella di un altro condominio la voce nata nel primo
 * ripartisce sulle unità del secondo; con un id che non esiste il database rifiuta il legame e a video arriva il testo della query. Il server rifiuta l'id
 * che non è una tabella del condominio sul campo che lo porta, prima di scrivere: nessuna fattura, nessuna voce nata, nessun legame voce-tabella.
 *
 * Fonte dell'atteso: i conti a mano (una pregressa di € 500,00 senza coperture ha un'eccedenza, quindi crea la voce dell'imprevisto) e la regola del
 * perimetro di ogni id che arriva dal browser.
 *
 * Cosa resta scoperto: le Request delle voci (`CreateContoRequest`, `UpdateContoRequest`) non restringono `tabella_millesimale_id` al condominio, ma il
 * controller rifiuta la tabella di un altro condominio con il rollback (nessuna voce, nessun legame); l'effetto del legame sbagliato sulle quote (il legame è
 * provato, il riparto che ne seguirebbe è letto nel motore); la schermata.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

const BE52_TABELLA_CAMPO = 'dati_extra.log_legale_sopravvenienza.tabella_millesimale_id';
const BE52_TABELLA_TESTO = 'La tabella millesimale scelta non appartiene a questo condominio.';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Una pregressa di € 500,00 senza coperture: l'eccedenza crea la voce dell'imprevisto, legata alla tabella indicata. */
function be52PregressaConTabella(array $s, \App\Models\Fornitore $f, int|string $tabellaId, string $num): array
{
    return be52PayloadPregressa($s, $f, [
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'nome_voce' => 'Debito pregresso prova', 'origine_decisionale' => 'gestione_corrente', 'tipo_ripartizione' => 'millesimale', 'is_ordinario' => true,
            'richiede_copertura' => true, 'tabella_millesimale_id' => $tabellaId, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]],
    ], $num);
}

/** Le voci nate dall'imprevisto e i loro legami con le tabelle. */
function be52VociDellImprevisto(): array
{
    return DB::table('conti')->leftJoin('conto_tabella_millesimale', 'conto_tabella_millesimale.conto_id', '=', 'conti.id')
        ->where('conti.nome', 'Debito pregresso prova')->get(['conti.piano_conto_id', 'conto_tabella_millesimale.tabella_id'])->map(fn ($r) => (array) $r)->all();
}

it('una tabella di un altro condominio si rifiuta sul campo: nessuna fattura, nessuna voce nata, nessun legame', function () {
    $s1 = be52Scenario($this);
    $s2 = be52Scenario($this);
    be52MastriPregresso($s1, $s2);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PregressaConTabella($s1, $f, $s2['tabella']->id, 'PREG-TAB-ALTRUI'));
    $errori = be52Errori();

    expect($errori[BE52_TABELLA_CAMPO][0] ?? null)->toBe(BE52_TABELLA_TESTO)
        ->and(FatturaPassiva::where('numero_documento', 'PREG-TAB-ALTRUI')->count())->toBe(0)
        ->and(be52VociDellImprevisto())->toBe([]);
});

it('un id che non esiste è un errore sul campo e non il testo della query', function () {
    $s1 = be52Scenario($this);
    be52MastriPregresso($s1);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PregressaConTabella($s1, $f, 999999, 'PREG-TAB-NONESISTE'));
    $errori = be52Errori();

    expect($errori[BE52_TABELLA_CAMPO][0] ?? null)->toBe(BE52_TABELLA_TESTO)
        ->and(array_key_exists('error', $errori))->toBeFalse()
        ->and(str_contains(json_encode($errori, JSON_UNESCAPED_UNICODE), 'SQLSTATE'))->toBeFalse('il rifiuto non mostra il testo della query')
        ->and(FatturaPassiva::where('numero_documento', 'PREG-TAB-NONESISTE')->count())->toBe(0);
});

it('controprova — una lista al posto della tabella resta un errore sul campo', function () {
    $s1 = be52Scenario($this);
    be52MastriPregresso($s1);
    $f = be52Fornitore();
    $corpo = be52PregressaConTabella($s1, $f, $s1['tabella']->id, 'PREG-TAB-LISTA');
    $corpo['dati_extra']['log_legale_sopravvenienza']['tabella_millesimale_id'] = [$s1['tabella']->id];

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), $corpo);

    expect($risposta->getStatusCode())->toBe(302)
        ->and(be52Errori()[BE52_TABELLA_CAMPO][0] ?? null)->toBe('La tabella millesimale scelta non è valida.')
        ->and(array_key_exists('error', be52Errori()))->toBeFalse()
        ->and(FatturaPassiva::where('numero_documento', 'PREG-TAB-LISTA')->count())->toBe(0);
});

it('controprova — la tabella del proprio condominio passa: la voce nasce e si lega a quella tabella', function () {
    $s1 = be52Scenario($this);
    be52MastriPregresso($s1);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s1['c']]), be52PregressaConTabella($s1, $f, $s1['tabella']->id, 'PREG-TAB-MIA'));

    $legami = be52VociDellImprevisto();
    expect(be52Errori())->toBe([])
        ->and(FatturaPassiva::where('numero_documento', 'PREG-TAB-MIA')->count())->toBe(1)
        ->and(count($legami))->toBe(1)
        ->and($legami[0]['tabella_id'])->toBe($s1['tabella']->id)
        ->and($legami[0]['piano_conto_id'])->toBe($s1['pc']->id);
});

it('controprova — una voce con la tabella di un altro condominio si rifiuta, in creazione e in modifica, e non lascia né voce né legame', function () {
    $s = be52Scenario($this);
    $altro = be52Condominio();
    $voci = Conto::count();
    $legami = DB::table('conto_tabella_millesimale')->count();

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]),
        be52PayloadVoce($s, ['nome' => 'Nuova', 'tabella_millesimale_id' => $altro['tabella']->id]));
    $dopoCreazione = [Conto::count(), DB::table('conto_tabella_millesimale')->count()];

    be52UpdateVoce($this, $s, $s['spesa'], ['tabella_millesimale_id' => $altro['tabella']->id, 'importo' => '1.200,00']);

    expect($dopoCreazione)->toBe([$voci, $legami])
        ->and(Conto::count())->toBe($voci)
        ->and(DB::table('conto_tabella_millesimale')->count())->toBe($legami)
        ->and(DB::table('conto_tabella_millesimale')->where('tabella_id', $altro['tabella']->id)->count())->toBe(0);
});
