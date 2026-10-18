<?php

/**
 * Il rifiuto della voce di entrata nelle fatture scatta solo dove il capitolo conta, e non rivela una voce di un altro condominio.
 *
 * Il capitolo di una fattura è una voce di spesa: su un'entrata la fattura crea uno sforo e l'integrativa accredita ai condòmini. Il rifiuto riguarda
 * le righe che hanno un capitolo e un importo: una riga con l'unità (il servizio scarta il capitolo), una riga a € 0,00 (contenuto del documento, non una
 * spesa), una riga di sopravvenienza e la nota di credito (il documento che disfa una fattura sbagliata) restano com'erano; una riga negativa su
 * un'entrata si rifiuta come una positiva (lo sconto di una fattura è legittimo, ma non su un provento). Lo storno non guarda il tipo: una fattura già
 * scritta su un'entrata si annulla, e lo speso della voce torna a zero.
 *
 * Una voce di un altro condominio che è anche un'entrata prende un solo rifiuto, quello del perimetro, che non nomina la voce altrui: il tipo si controlla
 * solo sulle voci del condominio.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (fattura di € 100,00, riga a € 0,00, sconto di € 50,00) e le regole del capitolo di una fattura.
 *
 * Cosa resta scoperto: la modifica di una pregressa (non si modifica); la schermata (`FatturaRegisterEditVoceEntrata.test.ts`); l'importazione da XML,
 * che passa dalle stesse Request; il cambio di tipo di una voce con fatture già scritte (1.11.1).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

const BE52_ESENZIONI_TESTO_ENTRATA = '«Affitto antenna» è una voce di entrata: il capitolo di una fattura è una voce di spesa. Scegli un\'altra voce.';
const BE52_ESENZIONI_TESTO_PERIMETRO = 'La voce scelta non appartiene a questo condominio.';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function be52SpesoDellaVoce(array $s, int $contoId): int
{
    return (int) (app(SpesaPerVoceService::class)->perEsercizio($s['e'])[$contoId] ?? 0);
}

function be52Modifica($test, array $s, FatturaPassiva $ft, array $righe)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $ft]), be52PayloadModifica($ft, $s, $righe));
}

// ─── Modifica: dove il rifiuto non scatta, e dove sì ────────────────────────────────────────────────────────────

it('controprova — in modifica una riga con l\'unità e il capitolo di un\'entrata non si rifiuta: il servizio scarta il capitolo', function () {
    $s = be52Scenario($this);
    $ft = be52FatturaDalServizio($s, be52Fornitore(), $s['spesa']->id, 100.00, 'FT-UNITA');

    be52Modifica($this, $s, $ft, [be52Riga($s['entrata']->id, 100.00, ['immobile_id' => $s['unita']['A']->id])]);

    expect(be52Errori())->toBe([])
        ->and(be52SpesoDellaVoce($s, $s['entrata']->id))->toBe(0);
});

it('controprova — in modifica una riga a € 0,00 su un\'entrata non si rifiuta', function () {
    $s = be52Scenario($this);
    $ft = be52FatturaDalServizio($s, be52Fornitore(), $s['spesa']->id, 100.00, 'FT-ZERO');

    be52Modifica($this, $s, $ft, [be52Riga($s['spesa']->id, 100.00), be52Riga($s['entrata']->id, 0.00, ['descrizione' => 'Protocollo 10000'])]);

    expect(be52Errori())->toBe([]);
});

it('in modifica una riga negativa su un\'entrata si rifiuta come una positiva, sulla riga che la porta', function () {
    $s = be52Scenario($this);
    $ft = be52FatturaDalServizio($s, be52Fornitore(), $s['spesa']->id, 100.00, 'FT-SCONTO');
    $righe = DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->orderBy('id')->pluck('conto_id')->all();

    be52Modifica($this, $s, $ft, [be52Riga($s['spesa']->id, 100.00), be52Riga($s['entrata']->id, -50.00, ['descrizione' => 'Sconto'])]);
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['righe.1.conto_id'])
        ->and($errori['righe.1.conto_id'][0])->toBe(BE52_ESENZIONI_TESTO_ENTRATA)
        ->and(DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->orderBy('id')->pluck('conto_id')->all())->toBe($righe)
        ->and(be52SpesoDellaVoce($s, $s['entrata']->id))->toBe(0);
});

// ─── Registrazione: la riga di sopravvenienza ───────────────────────────────────────────────────────────────────

it('controprova — in registrazione una riga di sopravvenienza con il conto di un\'entrata non si rifiuta: il servizio scarta il capitolo e usa la voce nata dall\'imprevisto', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['spesa']->id, 100.00, [
        'righe' => [be52Riga($s['entrata']->id, 50.00, ['descrizione' => 'Imprevisto', 'is_sopravvenienza' => true])],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'nome_voce' => 'Imprevisto di prova', 'origine_decisionale' => 'gestione_corrente', 'tipo_ripartizione' => 'millesimale', 'is_ordinario' => true,
            'richiede_copertura' => false, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]],
    ], 'FT-SOPRAV'));

    expect(be52Errori())->toBe([])
        ->and(FatturaPassiva::where('numero_documento', 'FT-SOPRAV')->count())->toBe(1)
        ->and(be52SpesoDellaVoce($s, $s['entrata']->id))->toBe(0);
});

// ─── Una voce di un altro condominio ────────────────────────────────────────────────────────────────────────────

it('una voce di entrata di un altro condominio prende il solo rifiuto del perimetro, in registrazione e in modifica, e non è nominata', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $voceAltrui = be52Voce($this, $altro, $altro['pc'], 'Proventi del vicino', 'entrata', '100,00');
    $f = be52Fornitore();
    $ft = be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-PERIMETRO');

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $voceAltrui->id, 80.00, [], 'FT-ALTRUI'));
    $registrazione = be52Errori();
    be52Modifica($this, $s, $ft, [be52Riga($voceAltrui->id, 100.00)]);
    $modifica = be52Errori();

    expect($registrazione)->toBe(['righe.0.conto_id' => [BE52_ESENZIONI_TESTO_PERIMETRO]])
        ->and($modifica)->toBe(['righe.0.conto_id' => [BE52_ESENZIONI_TESTO_PERIMETRO]])
        ->and(str_contains(json_encode([$registrazione, $modifica], JSON_UNESCAPED_UNICODE), 'Proventi del vicino'))->toBeFalse('nessun rifiuto nomina la voce di un altro condominio')
        ->and(FatturaPassiva::where('numero_documento', 'FT-ALTRUI')->count())->toBe(0);
});

// ─── Lo storno ──────────────────────────────────────────────────────────────────────────────────────────────────

it('controprova — lo storno di una fattura già scritta su un\'entrata riesce e lo speso della voce torna a zero', function () {
    $s = be52Scenario($this);
    $ft = be52FatturaDalServizio($s, be52Fornitore(), $s['entrata']->id, 300.00, 'FT-VECCHIA');
    expect(be52SpesoDellaVoce($s, $s['entrata']->id))->toBe(30000);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $ft]));

    expect(be52Errori())->toBe([])
        ->and(be52SpesoDellaVoce($s, $s['entrata']->id))->toBe(0)
        ->and(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe(1);
});
