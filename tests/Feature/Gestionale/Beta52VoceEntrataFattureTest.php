<?php

/**
 * Le fatture e la regolazione immediata non offrono e non accettano una voce di entrata.
 *
 * Una fattura è un costo: su un'entrata (l'affitto dell'antenna, € 240,00) crea uno sforo, e l'integrativa che lo recupera accredita ai condòmini
 * invece di chiedere (−3000 a testa su una fattura di € 300,00, al posto di +3000); la regolazione immediata scrive DARE «Costi per Servizi» e
 * AVERE cassa, cioè un'uscita di banca. Il server rifiuta la voce di entrata sul campo che la porta (`righe.N.conto_id`, `conto_id`); la nota di
 * credito del fornitore, le righe a zero e le righe con l'unità non cambiano; lo storno non guarda il tipo; il modulo non offre le entrate, e in
 * modifica mostra la voce che la riga ha già (senza uscire dal condominio).
 *
 * Fonte dell'atteso: i conti a mano di ogni test e la riga 248 di `roadmap.md` («non offrire le entrate nelle fatture e nella regolazione
 * e rifiutarle nel server»).
 *
 * Cosa resta scoperto: la riga negativa, la riga di sopravvenienza, lo storno e la voce di un altro condominio (`Beta52VoceEntrataFattureEsenzioniTest`); la
 * schermata (`FatturaRegisterEditVoceEntrata.test.ts`, `RegolazioneImmediataNewVoceEntrata.test.ts`); il cambio di tipo di una voce con fatture (1.11.1).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

const BE52_TESTO_ENTRATA_IN_REGISTRAZIONE = '«Affitto antenna» è una voce di entrata: il capitolo di una fattura è una voce di spesa. Scegli un\'altra voce.';
const BE52_TESTO_ENTRATA_GIA_NELLA_RIGA = 'Questa riga è sulla voce di entrata «Affitto antenna»: il capitolo di una fattura è una voce di spesa. Scegli una voce di spesa per salvare la modifica.';
const BE52_TESTO_ENTRATA_NELLA_REGOLAZIONE = '«Affitto antenna» è una voce di entrata: la regolazione immediata registra un\'uscita di cassa e non si usa per un\'entrata. Scegli una voce di spesa.';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function be52Regolazione(array $s, int $contoId, float $euro = 300.00): array
{
    return ['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $contoId, 'cassa_id' => $s['cassa']->id,
        'data_operazione' => '2026-03-10', 'causale' => 'Prova della regolazione', 'importo' => $euro];
}

// ─── Registrazione ──────────────────────────────────────────────────────────────────────────────────────────────

it('registrare una fattura di € 300,00 su una voce di entrata si rifiuta sul campo della voce', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['entrata']->id, 300.00, [
        'stato_approvazione' => 'sforo_motivato',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => ['motivazione' => 'Prova dello sforo sull\'entrata', 'importo_sforo' => 6000, 'strategia_rientro' => 'rata_integrativa']],
    ], 'FT-ANT-1'));
    $errori = be52Errori();

    expect(FatturaPassiva::count())->toBe(0)
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['entrata']->id)->count())->toBe(0)
        ->and($errori['righe.0.conto_id'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_IN_REGISTRAZIONE);
});

it('controprova — una fattura su una voce di spesa si registra come prima', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['spesa']->id, 100.00, [], 'FT-SP-1'))->assertSessionHasNoErrors();

    expect(FatturaPassiva::count())->toBe(1)
        ->and((int) (app(SpesaPerVoceService::class)->perEsercizio($s['e'])[$s['spesa']->id] ?? 0))->toBe(10000);
});

it('controprova — le righe a zero, le righe con l\'unità e la nota di credito non cambiano', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();

    // una riga a € 0,00 sull'entrata accanto a una riga vera sulla spesa
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['spesa']->id, 100.00, [
        'righe' => [be52Riga($s['spesa']->id, 100.00), be52Riga($s['entrata']->id, 0.00, ['descrizione' => 'Protocollo 10000'])],
    ], 'FT-ZERO'))->assertSessionHasNoErrors();

    // una riga con l'unità: il servizio scarta il capitolo
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['spesa']->id, 50.00, [
        'righe' => [be52Riga($s['entrata']->id, 50.00, ['immobile_id' => $s['unita']['A']->id])],
    ], 'FT-UNITA'))->assertSessionHasNoErrors();

    // la nota di credito del fornitore: è il documento che disfa una fattura sbagliata
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $f, $s['entrata']->id, 100.00, ['tipo_documento' => 'nota_credito'], 'NC-1'))
        ->assertSessionHasNoErrors();

    expect(FatturaPassiva::count())->toBe(3);
});

// ─── Modifica ───────────────────────────────────────────────────────────────────────────────────────────────────

it('portare in modifica una riga su una voce di entrata si rifiuta, e la fattura resta com\'era', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    $ft = be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-MOD-1');
    $scrittureDopo = DB::table('righe_scritture')->count();

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $ft]), be52PayloadModifica($ft, $s, [be52Riga($s['entrata']->id, 100.00)]));
    $errori = be52Errori();

    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->value('conto_id'))->toBe($s['spesa']->id)
        ->and(DB::table('righe_scritture')->count())->toBe($scrittureDopo)
        ->and($errori['righe.0.conto_id'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_IN_REGISTRAZIONE);
});

it('una fattura già scritta su un\'entrata non si salva finché la riga non va su una voce di spesa', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    $ft = be52FatturaDalServizio($s, $f, $s['entrata']->id, 300.00, 'FT-VECCHIA'); // il dato che le versioni precedenti lasciavano

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $ft]), be52PayloadModifica($ft, $s, [be52Riga($s['entrata']->id, 300.00)]));
    $rifiuto = be52Errori();

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $ft->fresh()]), be52PayloadModifica($ft, $s, [be52Riga($s['spesa']->id, 300.00)]));
    $dopo = be52Errori();

    expect($rifiuto['righe.0.conto_id'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_GIA_NELLA_RIGA)
        ->and($dopo)->toBe([])
        ->and(DB::table('righe_fattura')->where('fattura_passiva_id', $ft->id)->value('conto_id'))->toBe($s['spesa']->id)
        ->and((int) (app(SpesaPerVoceService::class)->perEsercizio($s['e'])[$s['entrata']->id] ?? 0))->toBe(0)
        ->and((int) (app(SpesaPerVoceService::class)->perEsercizio($s['e'])[$s['spesa']->id] ?? 0))->toBe(30000);
});

it('controprova — la nota di credito scritta su un\'entrata si modifica ancora', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    $nc = be52FatturaDalServizio($s, $f, $s['entrata']->id, 100.00, 'NC-VECCHIA', ['tipo_documento' => 'nota_credito']);

    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $nc]), be52PayloadModifica($nc, $s, [be52Riga($s['entrata']->id, 100.00)]));

    expect(be52Errori())->toBe([]);
});

// ─── Le liste che il modulo riceve ──────────────────────────────────────────────────────────────────────────────

it('il modulo di registrazione non offre le voci di entrata e non propone quella dell\'ultima fattura', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-1', ['data_documento' => '2026-02-10']);
    be52FatturaDalServizio($s, $f, $s['entrata']->id, 300.00, 'FT-2', ['data_documento' => '2026-04-10']); // la più recente, sull'entrata

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', [$s['c']]));
    $r->assertOk();
    $props = $r->viewData('page')['props'];
    $conti = collect($props['conti'])->mapWithKeys(fn ($c) => [$c['nome'] => $c['tipo'] ?? '(manca)'])->all();
    $ultimo = collect($props['fornitori'])->firstWhere('id', $f->id)['ultimo_conto_id'] ?? null;

    expect($conti)->toBe(['Spese generali' => 'spesa'])
        ->and($ultimo)->toBe($s['spesa']->id);
});

it('il modulo di modifica mostra la voce già usata dalla riga, con il tipo, e non esce dal condominio', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $f = be52Fornitore();
    $vecchia = be52FatturaDalServizio($s, $f, $s['entrata']->id, 300.00, 'FT-VECCHIA');
    $conVarco = be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-VARCO');
    DB::table('righe_fattura')->where('fattura_passiva_id', $conVarco->id)->update(['conto_id' => $altro['spesa']->id]); // il varco di `update` fino alla versione precedente

    $tipi = function (FatturaPassiva $ft) use ($s) {
        $props = test()->actingAs(test()->user)->get(route('admin.gestionale.fatture.edit', [$s['c'], $ft]))->assertOk()->viewData('page')['props'];

        return collect($props['conti'])->mapWithKeys(fn ($c) => [$c['id'] => $c['tipo'] ?? '(manca)'])->sortKeys()->all();
    };

    expect($tipi($vecchia))->toBe([$s['spesa']->id => 'spesa', $s['entrata']->id => 'entrata']);
    $perVarco = $tipi($conVarco);
    expect(array_key_exists($altro['spesa']->id, $perVarco))->toBeFalse()
        ->and(array_key_exists($altro['entrata']->id, $perVarco))->toBeFalse()
        ->and(array_key_exists($s['entrata']->id, $perVarco))->toBeFalse();
});

// ─── Regolazione immediata ──────────────────────────────────────────────────────────────────────────────────────

it('la regolazione immediata di € 300,00 su una voce di entrata si rifiuta e non scrive niente', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52Regolazione($s, $s['entrata']->id));
    $errori = be52Errori();

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'regolazione_immediata')->count())->toBe(0)
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['entrata']->id)->count())->toBe(0)
        ->and($errori['conto_id'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_NELLA_REGOLAZIONE);
});

it('controprova — la regolazione su una voce di spesa scrive DARE sul costo e AVERE sulla cassa', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52Regolazione($s, $s['spesa']->id))->assertSessionHasNoErrors();

    $righe = DB::table('righe_scritture')->where('voce_spesa_id', $s['spesa']->id)->get(['tipo_riga', 'importo']);
    expect($righe->count())->toBe(1)
        ->and($righe[0]->tipo_riga)->toBe('dare')
        ->and((int) $righe[0]->importo)->toBe(30000);
});

it('l\'azione rifiuta la voce di entrata anche chiamata da sola, con lo stesso testo', function () {
    $s = be52Scenario($this);

    $messaggio = null;
    try {
        app(RegistraRegolazioneImmediataAction::class)->execute(be52Regolazione($s, $s['entrata']->id), $s['c'], $s['e']);
    } catch (RegolazioneImmediataNonAmmessaException $e) {
        $messaggio = $e->getMessage();
    }

    expect($messaggio)->toBe(BE52_TESTO_ENTRATA_NELLA_REGOLAZIONE);
});

it('il modulo della regolazione non offre le voci di entrata', function () {
    $s = be52Scenario($this);

    $r = $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', [$s['c']]));
    $r->assertOk();

    expect(collect($r->viewData('page')['props']['capitoli'])->pluck('nome')->all())->toBe(['Spese generali']);
});

it('controprova — una regolazione scritta su una voce che poi diventa un\'entrata si storna ancora', function () {
    $s = be52Scenario($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), be52Regolazione($s, $s['spesa']->id))->assertSessionHasNoErrors();
    $scrittura = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'regolazione_immediata')->firstOrFail();
    DB::table('conti')->where('id', $s['spesa']->id)->update(['tipo' => 'entrata']);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $scrittura]), ['motivo' => 'Registrata sulla voce sbagliata'])->assertSessionHasNoErrors();

    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'storno_regolazione_immediata')->count())->toBe(1);
});
