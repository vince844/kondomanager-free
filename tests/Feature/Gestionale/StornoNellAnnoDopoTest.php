<?php

/**
 * Lo storno nell'anno dopo (Variante B1: l'esercizio dell'originale è chiuso, lo storno va nell'esercizio aperto) di una regolazione immediata e di un
 * incasso da terzi su una voce di una gestione che l'esercizio dello storno non ha, o ha con il legame spento (decisioni 252 e 253, D6). La riga con la
 * voce va senza voce, con una nota che dice la voce di allora: stesso importo, stesso lato, stesso conto. Con la voce di allora la riga starebbe fuori
 * dal riepilogo dell'anno dopo, e il controllo dei costi non tornerebbe; senza voce il riepilogo la conta fra i costi non classificati.
 *
 * Le cifre: la regolazione di € 96,00 il 30/06/2026 su «Spese generali» dell'ordinaria 2026; l'incasso da terzi di € 240,00 il 15/05/2026 su «Affitto
 * antenna». Il 2026 chiuso, il 2027 con un'«Ordinaria 2027» sua; lo storno il 10/02/2027. Centesimi.
 *
 * Lo storno senza voce si assegna come quello di una commissione (decisione 255, D1): nell'elenco delle commissioni e degli storni senza voce del 2027,
 * solo a una voce di un'ordinaria del 2027: di spesa per la regolazione, di entrata per l'incasso da terzi (il riepilogo legge le righe degli incassi
 * da terzi come l'incassato della loro voce); assegnato, entra nel consuntivo della voce e nel riparto della sua gestione. Finché non è assegnato, la
 * fascia della Sintesi e il controllo dei costi lo dicono, con la regola dell'elenco. Le cifre a mano: la regolazione stornata va sulla voce di spesa
 * «Spese generali 2027» come −€ 96,00 (Σdare − Σavere = 0 − 96); l'incasso stornato sulla voce di entrata «Affitto antenna 2027» come incassato
 * −€ 240,00 (Σavere − Σdare = 0 − 240), e le spese meno le entrate del 2027 salgono di € 240,00; i costi del 2027 sono le spese per capitolo, e gli
 * storni senza voce tornano a € 0,00.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Services\Gestionale\CommissioniSenzaVoce;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/**
 * Il 2026 con la regolazione e l'incasso da terzi, il 2026 chiuso, il 2027 aperto con la sua «Ordinaria 2027» (e, se si chiede, l'ordinaria 2026 legata al
 * 2027 con il legame spento). Il giorno è il 10/02/2027. `$altre`: altre regolazioni del 2026 su «Spese generali», [euro, già senza voce], in `$s['altre']`.
 */
function sadScenario($test, bool $legameSpento = false, array $altre = []): array
{
    $test->travelTo(Carbon::parse('2026-06-30 10:00', 'Europe/Rome'));
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-06-30', 'causale' => 'Spese generali pagate', 'importo' => 96.00,
    ])->assertSessionHasNoErrors();
    $s['regolazione'] = ScritturaContabile::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
    $gestore = tap(Anagrafica::forceCreate(['nome' => 'Torri del Nord Spa', 'email' => "torri-sad{$s['seq']}@test.it", 'indirizzo' => 'Via Po 1',
        'codice_fiscale' => 'TORRISAD'.str_pad((string) $s['seq'], 8, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-05-15',
        'importo' => 240.00, 'anagrafica_id' => $gestore->id, 'causale' => 'Affitto antenna 2026',
    ])->assertSessionHasNoErrors();
    $s['incasso'] = ScritturaContabile::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
    $s['altre'] = [];
    foreach ($altre as $i => [$euro, $senzaVoce]) {
        $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
            'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
            'data_operazione' => '2026-06-30', 'causale' => 'Altra regolazione '.$i, 'importo' => $euro,
        ])->assertSessionHasNoErrors();
        $s['altre'][] = $altra = ScritturaContabile::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
        if ($senzaVoce) {
            DB::table('righe_scritture')->where('scrittura_id', $altra->id)->whereNotNull('voce_spesa_id')->update(['voce_spesa_id' => null]);
        }
    }

    $s['e27'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $o27 = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    legaAEsercizio($s['e27'], $o27->id);
    $s['o27'] = $o27;
    if ($legameSpento) {
        DB::table('esercizio_gestione')->insert(['esercizio_id' => $s['e27']->id, 'gestione_id' => $s['g']->id, 'attiva' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $test->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));

    return $s;
}

/** Lo storno dalla rotta della pagina: le sue righe, e il riepilogo e il controllo dei costi del 2027. */
function sadStorna($test, array $s, string $quale): array
{
    $rotta = $quale === 'regolazione'
        ? [route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $s['regolazione']]), ['motivo' => 'Addebito annullato dalla banca']]
        : [route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $s['incasso']]), ['natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore']];
    $test->actingAs($test->user)->post(...$rotta)->assertSessionHasNoErrors();
    $storno = ScritturaContabile::where('scrittura_padre_id', $s[$quale]->id)->firstOrFail();
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e27']->fresh(), '2027-02-10');
    $cr = collect(app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e27']->fresh(), '2027-02-10')['controlli'])->firstWhere('id', 'CR');

    return ['storno' => $storno, 'righe' => DB::table('righe_scritture')->where('scrittura_id', $storno->id)->orderBy('id')->get(),
        'originali' => DB::table('righe_scritture')->where('scrittura_id', $s[$quale]->id)->orderBy('id')->get(), 'r' => $r, 'cr' => $cr];
}

it('lo storno nel 2027 di una regolazione immediata sull\'ordinaria 2026: la riga va senza voce sullo stesso conto, con la voce di allora nella nota; la partita doppia torna, il controllo dei costi del 2027 «quadra», il 2026 chiuso non cambia', function (bool $legameSpento) {
    $s = sadScenario($this, $legameSpento);
    $x = sadStorna($this, $s, 'regolazione');
    $originale = $x['originali']->first(fn ($r) => $r->voce_spesa_id !== null);
    $riga = $x['righe']->first(fn ($r) => (int) $r->conto_contabile_id === (int) $originale->conto_contabile_id);

    expect((int) $x['storno']->esercizio_id)->toBe((int) $s['e27']->id)
        // La partita doppia: DARE cassa € 96,00 = AVERE spesa € 96,00.
        ->and([(int) $x['righe']->where('tipo_riga', 'dare')->sum('importo'), (int) $x['righe']->where('tipo_riga', 'avere')->sum('importo')])->toBe([9600, 9600])
        ->and([$riga->tipo_riga, (int) $riga->importo, $riga->voce_spesa_id])->toBe(['avere', 9600, null])
        ->and($riga->note)->toBe(rtrim('Storno — '.$originale->note).' La riga era sulla voce «Spese generali» della gestione «'.$s['g']->nome
            .'» dell\'esercizio «Esercizio 2026», che l\'esercizio «Esercizio 2027» non ha.')
        // Il 2027: la riga sta fra i costi non classificati, e il conto dei costi torna.
        ->and($x['r']['fuori_capitoli']['non_classificati']['consuntivo'])->toBe(-9600)
        ->and($x['r']['riconciliazione']['costi'])->toBe(-9600)
        ->and($x['r']['riconciliazione']['differenza'])->toBe(0)
        ->and($x['cr']['esito'])->toBe('quadra')
        ->and($x['r']['riconciliazione']['storni_senza_voce'])->toBe(-9600)
        ->and($x['cr']['nota'])->toContain('+ storni di registrazioni di un esercizio chiuso, senza voce, € -96,00')
        // Il 2026 chiuso tiene la regolazione sulla sua voce.
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['spesa']->id] ?? 0))->toBe(9600);
})->with(['l\'ordinaria 2026 che il 2027 non ha' => [false], 'l\'ordinaria 2026 legata al 2027 con il legame spento' => [true]]);

it('lo storno nel 2027 di un incasso da terzi sull\'ordinaria 2026: la riga dell\'entrata va senza voce sullo stesso conto, con la voce di allora nella nota; la partita doppia torna e il controllo dei costi del 2027 «quadra»', function () {
    $s = sadScenario($this);
    $x = sadStorna($this, $s, 'incasso');
    $originale = $x['originali']->first(fn ($r) => $r->voce_spesa_id !== null);
    $riga = $x['righe']->first(fn ($r) => (int) $r->conto_contabile_id === (int) $originale->conto_contabile_id);

    expect((int) $x['storno']->esercizio_id)->toBe((int) $s['e27']->id)
        // DARE entrata € 240,00 = AVERE cassa € 240,00.
        ->and([(int) $x['righe']->where('tipo_riga', 'dare')->sum('importo'), (int) $x['righe']->where('tipo_riga', 'avere')->sum('importo')])->toBe([24000, 24000])
        ->and([$riga->tipo_riga, (int) $riga->importo, $riga->voce_spesa_id])->toBe(['dare', 24000, null])
        ->and($riga->note)->toBe(rtrim('Storno — '.$originale->note).' La riga era sulla voce «Affitto antenna» della gestione «'.$s['g']->nome
            .'» dell\'esercizio «Esercizio 2026», che l\'esercizio «Esercizio 2027» non ha.')
        ->and($x['r']['riconciliazione']['costi'])->toBe(24000)
        ->and($x['r']['riconciliazione']['differenza'])->toBe(0)
        ->and($x['cr']['esito'])->toBe('quadra');
});

it('lo storno nel 2026 ancora aperto tiene la voce (anche con il legame della sua gestione spento dopo), e nel 2027 con l\'ordinaria 2026 ancora legata la tiene anche: la voce c\'è nell\'esercizio dello storno', function () {
    // Lo stesso esercizio: lo storno è il rovescio dell'originale, sulla stessa voce, anche se nel frattempo il legame della gestione è stato spento.
    $s = sadScenario($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'aperto']);
    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    DB::table('esercizio_gestione')->where('esercizio_id', $s['e']->id)->where('gestione_id', $s['g']->id)->update(['attiva' => false]);
    $x = sadStorna($this, $s, 'regolazione');
    expect((int) $x['storno']->esercizio_id)->toBe((int) $s['e']->id)
        ->and($x['righe']->pluck('voce_spesa_id')->filter()->values()->all())->toBe([(int) $s['spesa']->id]);

    // Il 2027 con l'ordinaria 2026 legata e accesa: la voce c'è, e la riga la tiene.
    $t = sadScenario($this);
    legaAEsercizio($t['e27'], $t['g']->id);
    $y = sadStorna($this, $t, 'incasso');
    expect((int) $y['storno']->esercizio_id)->toBe((int) $t['e27']->id)
        ->and($y['righe']->pluck('voce_spesa_id')->filter()->values()->all())->toBe([(int) $t['entrata']->id])
        ->and($y['righe']->pluck('note')->filter(fn ($n) => str_contains((string) $n, 'La riga era sulla voce'))->all())->toBe([])
        // La riga con la voce sta nel suo capitolo: nessuno storno senza voce, e il conto dei costi torna senza contarla due volte.
        ->and($y['r']['riconciliazione']['storni_senza_voce'])->toBe(0)
        ->and($y['r']['riconciliazione']['costi'])->toBe(24000)
        ->and($y['cr']['esito'])->toBe('quadra')
        ->and($y['cr']['nota'])->not->toContain('storni');
});

it('una regolazione scritta senza voce e stornata nello stesso esercizio resta fra i costi non classificati dei due: non è uno storno di un esercizio chiuso, e il controllo dei costi la conta come prima', function () {
    $s = sadScenario($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'aperto']);
    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    // Una regolazione di prima che le regolazioni avessero la voce: la riga di costo senza voce.
    DB::table('righe_scritture')->where('scrittura_id', $s['regolazione']->id)->whereNotNull('voce_spesa_id')->update(['voce_spesa_id' => null]);
    $prima = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-02-10')['riconciliazione'];
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $s['regolazione']]), ['motivo' => 'Addebito annullato dalla banca'])
        ->assertSessionHasNoErrors();
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-02-10')['riconciliazione'];
    $cr = collect(app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-02-10')['controlli'])->firstWhere('id', 'CR');

    // Prima dello storno € 96,00 non classificati; dopo, la regolazione e il suo storno si annullano: € 0,00, e nessuno storno di un esercizio chiuso.
    expect([$prima['non_classificati'], $prima['storni_senza_voce']])->toBe([9600, 0])
        ->and([$r['non_classificati'], $r['storni_senza_voce'], $r['differenza']])->toBe([0, 0, 0])
        ->and($cr['esito'])->toBe('quadra');
});

/** Una voce di una gestione del 2027 (il suo piano dei conti, creato qui), di spesa o di entrata. */
function sadVoceDel2027($test, array $s, Gestione $gestione, string $nome, string $tipo = 'spesa'): Conto
{
    $pc = PianoConto::where('gestione_id', $gestione->id)->first()
        ?? PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano dei conti '.$gestione->nome]);

    return be52Voce($test, array_merge($s, ['e' => $s['e27']]), $pc, $nome, $tipo, '100,00');
}

/** La riga senza voce dello storno (se c'è), la sua voce nell'elenco del 2027, la fascia della Sintesi degli storni e il controllo dei costi. */
function sadElenco(array $s, array $x): array
{
    $riga = $x['righe']->first(fn ($r) => $r->voce_spesa_id === null && (int) $r->conto_contabile_id !== (int) $s['cassa']->conto_contabile_id);
    $elenco = app(CommissioniSenzaVoce::class)->perEsercizio($s['c'], $s['e27']->fresh());
    $fascia = collect(app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e27']->fresh(), '2027-02-10')['fasce'])->firstWhere('tipo', 'storni');
    $cr = collect(app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e27']->fresh(), '2027-02-10')['controlli'])->firstWhere('id', 'CR');

    return ['riga' => $riga, 'elenco' => $elenco, 'voce' => $riga === null ? null : collect($elenco['commissioni'])->firstWhere('id', (int) $riga->id), 'fascia' => $fascia, 'cr' => $cr];
}

/** Un capitolo dell'«Ordinaria 2027» e una voce sotto di lui, dalla rotta del piano dei conti (come il modale). */
function sadVoceSottoCapitolo($test, array $s, string $capitolo, string $tipoCapitolo, string $nome, string $tipo): Conto
{
    $pc = PianoConto::where('gestione_id', $s['o27']->id)->first()
        ?? PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['o27']->id, 'nome' => 'Piano dei conti '.$s['o27']->nome]);
    $crea = fn (array $dati) => $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e27'], $pc]), array_merge([
        'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'importo' => '100,00', 'descrizione' => '', 'note' => '',
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'richiedeGiaVersato' => false,
    ], $dati))->assertSessionHasNoErrors();
    $crea(['nome' => $capitolo, 'tipo' => $tipoCapitolo, 'parent_id' => null, 'isCapitolo' => true, 'isSottoConto' => false]);
    $padre = Conto::where('piano_conto_id', $pc->id)->where('nome', $capitolo)->firstOrFail();
    $crea(['nome' => $nome, 'tipo' => $tipo, 'parent_id' => $padre->id, 'isCapitolo' => false, 'isSottoConto' => true]);

    return Conto::where('piano_conto_id', $pc->id)->where('nome', $nome)->firstOrFail();
}

it('lo storno senza voce nel 2027 di una regolazione immediata e di un incasso da terzi sta nell\'elenco delle commissioni e degli storni senza voce, con la voce di allora; finché non ha una voce, la Sintesi e il controllo dei costi dicono che si assegna', function (string $quale, int $importo, string $voce, string $che, string $tipoVoce) {
    $s = sadScenario($this);
    $x = sadStorna($this, $s, $quale);
    $e = sadElenco($s, $x);
    $euro = '€ '.number_format($importo / 100, 2, ',', '.');

    expect($e['voce'])->not->toBeNull()
        ->and([$e['voce']['natura'], $e['voce']['voce_di_entrata']])->toBe(['registrazione', $tipoVoce === 'entrata'])
        ->and([$e['voce']['assegnabile'], $e['voce']['solo_ordinarie'], $e['voce']['importo']])->toBe([true, true, $importo])
        ->and($e['voce']['motivo'])->toBe("È lo storno di {$che} dell'esercizio «Esercizio 2026», che era sulla voce «{$voce}» della gestione «".$s['g']->nome
            ."»: questo esercizio non ha quella gestione. Si assegna a una voce di {$tipoVoce} di un'ordinaria di questo esercizio, che così entra nel riparto; "
            .'senza voce resta fra i costi non classificati del rendiconto.')
        ->and($e['voce']['righe'][0]['tipo'])->toBe('storno')
        // Le commissioni da assegnare restano le sole commissioni (il riquadro dei pagamenti, la fascia delle commissioni); gli storni hanno il loro numero.
        ->and($e['elenco']['assegnabili'])->toBe(['numero' => 0, 'importo' => 0])
        ->and($e['elenco']['storni_assegnabili'])->toBe(['numero' => 1, 'importo' => $importo])
        ->and([$e['fascia']['importo'], $e['fascia']['numero'], $e['fascia']['da_assegnare']])->toBe([$importo, 1, 1])
        ->and($e['fascia']['frase'])->toBe($euro.' di storni di registrazioni di un esercizio chiuso, senza voce: non stanno in nessun capitolo, e il conguaglio non li troverà.')
        ->and($e['cr']['esito'])->toBe('quadra')
        ->and($e['cr']['nota'])->toBe('Costi dell\'esercizio '.$euro.' = spese per capitolo meno entrate € 0,00 + commissioni senza voce € 0,00 + fatture contestate € 0,00'
            .' + storni di registrazioni di un esercizio chiuso, senza voce, '.$euro.' (fra i costi non classificati, e fuori dal riparto finché non prendono una voce:'
            .' 1 si assegna nell\'elenco delle commissioni e degli storni senza voce).');
})->with([
    'la regolazione di € 96,00' => ['regolazione', -9600, 'Spese generali', 'una regolazione immediata', 'spesa'],
    'l\'incasso da terzi di € 240,00' => ['incasso', 24000, 'Affitto antenna', 'un incasso da terzi', 'entrata'],
]);

it('lo storno senza voce nel 2027 assegnato a una voce dell\'«Ordinaria 2027» (di spesa la regolazione, di entrata l\'incasso da terzi): la riga prende la voce e il suo mastro, la voce e il totale della gestione cambiano, il controllo dei costi quadra senza storni, la fascia non c\'è più, la partita doppia e il 2026 restano', function (string $quale, int $importo, string $tipoVoce, string $nome, string $frase) {
    $s = sadScenario($this);
    $x = sadStorna($this, $s, $quale);
    $riga = sadElenco($s, $x)['riga'];
    $voce = sadVoceDel2027($this, $s, $s['o27'], $nome, $tipoVoce);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $voce->id])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success' && $m['message'] === "Voce «{$nome}» assegnata a 1 storno: {$frase}.");
    $dopo = DB::table('righe_scritture')->where('id', $riga->id)->first();
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e27']->fresh(), '2027-02-10');
    $blocco = collect($r['blocchi'])->first(fn ($b) => (int) $b['gestione']['id'] === (int) $s['o27']->id);
    $e = sadElenco($s, ['righe' => DB::table('righe_scritture')->where('scrittura_id', $x['storno']->id)->get()]);
    $righe = DB::table('righe_scritture')->where('scrittura_id', $x['storno']->id)->get();

    // Stesso lato e stesso importo, sulla voce e sul suo mastro; la traccia dice dov'era la riga.
    expect([(int) $dopo->voce_spesa_id, (int) $dopo->conto_contabile_id, $dopo->tipo_riga, (int) $dopo->importo])
        ->toBe([(int) $voce->id, (int) $voce->conto_contabile_id, $riga->tipo_riga, (int) $riga->importo])
        ->and($dopo->note)->toContain('(prima senza voce, sul conto «')->not->toContain('di sistema')
        // Il giornale della voce: Σdare − Σavere, −€ 96,00 (la regolazione tornata) o +€ 240,00 (l'affitto restituito).
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($s['e27']->fresh())[$voce->id] ?? 0))->toBe($importo);
    if ($tipoVoce === 'spesa') {
        // Il consuntivo della voce di spesa e il totale delle spese della gestione: −€ 96,00.
        expect(collect($blocco['spese'])->firstWhere('voce_id', $voce->id)['consuntivo'])->toBe($importo)
            ->and($blocco['totale_spese']['consuntivo'])->toBe($importo);
    } else {
        // L'incassato della voce di entrata e il totale delle entrate della gestione: −€ 240,00; nessuna spesa scritta sulla voce.
        expect(collect($blocco['entrate'])->firstWhere('voce_id', $voce->id)['incassato'])->toBe(-$importo)
            ->and(collect($blocco['entrate'])->firstWhere('voce_id', $voce->id)['consuntivo'])->toBe(0)
            ->and($blocco['totale_entrate']['incassato'])->toBe(-$importo);
    }
    expect([$r['riconciliazione']['costi'], $r['riconciliazione']['capitoli_netti'], $r['riconciliazione']['storni_senza_voce'], $r['riconciliazione']['non_classificati'], $r['riconciliazione']['differenza']])
        ->toBe([$importo, $importo, 0, 0, 0])
        ->and($e['cr']['esito'])->toBe('quadra')
        ->and($e['cr']['nota'])->not->toContain('storni')
        ->and($e['fascia'])->toBeNull()
        ->and($e['elenco']['commissioni'])->toBe([])
        // La partita doppia dello storno non cambia.
        ->and([(int) $righe->where('tipo_riga', 'dare')->sum('importo'), (int) $righe->where('tipo_riga', 'avere')->sum('importo')])->toBe([abs($importo), abs($importo)])
        // Il 2026 chiuso tiene le sue voci.
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['spesa']->id] ?? 0))->toBe(9600);
})->with([
    'la regolazione di € 96,00, su una voce di spesa' => ['regolazione', -9600, 'spesa', 'Spese generali 2027', 'il consuntivo della voce cambia di € -96,00'],
    'l\'incasso da terzi di € 240,00, su una voce di entrata' => ['incasso', 24000, 'entrata', 'Affitto antenna 2027', 'l\'incassato della voce cambia di € -240,00'],
]);

it('lo storno senza voce nel 2027 non si assegna a una voce che non è un\'ordinaria del 2027 del suo tipo (di spesa la regolazione, di entrata l\'incasso): né alla voce del 2026, né a una dell\'altro tipo, né a una di una straordinaria, né a una di un\'ordinaria spenta; la frase parla dello storno', function (string $quale, string $dove) {
    $s = sadScenario($this);
    $x = sadStorna($this, $s, $quale);
    $riga = sadElenco($s, $x)['riga'];
    $tipo = ($quale === 'regolazione') === ($dove !== 'altro tipo') ? 'spesa' : 'entrata';
    $voce = match ($dove) {
        '2026' => $quale === 'regolazione' ? $s['spesa'] : $s['entrata'],
        'altro tipo' => sadVoceDel2027($this, $s, $s['o27'], 'Voce del 2027', $tipo),
        'straordinaria' => (function () use ($s, $tipo) {
            $tetto = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Rifacimento tetto', 'tipo' => 'straordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
            legaAEsercizio($s['e27'], $tetto->id);

            return sadVoceDel2027($this, $s, $tetto, 'Voce del tetto', $tipo);
        })(),
        // Un'ordinaria del 2027 legata con un legame attivo, ma spenta lei.
        'spenta' => (function () use ($s, $tipo) {
            $spenta = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria bis 2027', 'tipo' => 'ordinaria', 'attiva' => true, 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
            legaAEsercizio($s['e27'], $spenta->id);
            $voce = sadVoceDel2027($this, $s, $spenta, 'Voce dell\'ordinaria spenta', $tipo);
            DB::table('gestioni')->where('id', $spenta->id)->update(['attiva' => false]);

            return $voce;
        })(),
    };

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $voce->id])
        ->assertSessionHasErrors(['voce_id' => $quale === 'regolazione' ? CommissioniSenzaVoce::VOCE_DELLO_STORNO : CommissioniSenzaVoce::VOCE_DELLO_STORNO_DI_UN_INCASSO]);
    expect(DB::table('righe_scritture')->where('id', $riga->id)->value('voce_spesa_id'))->toBeNull();
})->with([
    'la regolazione, la voce dell\'ordinaria 2026' => ['regolazione', '2026'],
    'la regolazione, una voce di entrata dell\'Ordinaria 2027' => ['regolazione', 'altro tipo'],
    'la regolazione, una voce di spesa di una straordinaria del 2027' => ['regolazione', 'straordinaria'],
    'l\'incasso, la voce dell\'ordinaria 2026' => ['incasso', '2026'],
    'l\'incasso, una voce di spesa dell\'Ordinaria 2027' => ['incasso', 'altro tipo'],
    'l\'incasso, una voce di entrata di una straordinaria del 2027' => ['incasso', 'straordinaria'],
    'la regolazione, una voce di spesa di un\'ordinaria del 2027 spenta' => ['regolazione', 'spenta'],
    'l\'incasso, una voce di entrata di un\'ordinaria del 2027 spenta' => ['incasso', 'spenta'],
]);

it('lo storno di un incasso da terzi non va su una voce di entrata sotto un capitolo di spesa (il riepilogo la legge nel capitolo, fra le spese, e l\'incassato non starebbe in nessuna riga): il menu della pagina non la propone e la rotta la rifiuta con la regola; sotto un capitolo di entrata sì, e il controllo dei costi quadra', function () {
    $s = sadScenario($this);
    $riga = sadElenco($s, sadStorna($this, $s, 'incasso'))['riga'];
    $sottoSpesa = sadVoceSottoCapitolo($this, $s, 'Servizi comuni', 'spesa', 'Rimborso antenna', 'entrata');
    $sottoEntrata = sadVoceSottoCapitolo($this, $s, 'Entrate da terzi', 'entrata', 'Affitto antenna 2027', 'entrata');
    $assegna = fn (Conto $voce) => $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $voce->id]);
    $menu = collect($this->actingAs($this->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $s['e27']]))->assertOk()
        ->viewData('page')['props']['voci_di_entrata'])->pluck('id')->all();

    expect($menu)->toContain($sottoEntrata->id)->not->toContain($sottoSpesa->id)
        ->and(CommissioniSenzaVoce::VOCE_DELLO_STORNO_DI_UN_INCASSO)->toBe('La voce scelta non si dà a questo storno: lo storno di un incasso da terzi di un esercizio '
            .'chiuso si assegna a una voce di entrata di un\'ordinaria di questo esercizio, attiva, senza sottovoci, con il suo conto e non sotto un capitolo di '
            .'spesa: l\'entrata torna indietro.');

    $assegna($sottoSpesa)->assertSessionHasErrors(['voce_id' => CommissioniSenzaVoce::VOCE_DELLO_STORNO_DI_UN_INCASSO]);
    expect(DB::table('righe_scritture')->where('id', $riga->id)->value('voce_spesa_id'))->toBeNull();

    $assegna($sottoEntrata)->assertSessionHasNoErrors();
    expect((int) DB::table('righe_scritture')->where('id', $riga->id)->value('voce_spesa_id'))->toBe((int) $sottoEntrata->id)
        ->and(sadElenco($s, ['righe' => collect()])['cr']['esito'])->toBe('quadra');
});

it('lo storno di una regolazione e quello di un incasso da terzi scelti insieme non si assegnano: vanno su voci di tipo diverso, e la frase lo dice', function () {
    $s = sadScenario($this);
    $righe = collect(['regolazione', 'incasso'])->map(fn ($q) => sadElenco($s, sadStorna($this, $s, $q))['riga']->id);
    $sg27 = sadVoceDel2027($this, $s, $s['o27'], 'Spese generali 2027');

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => $righe->all(), 'voce_id' => $sg27->id])->assertSessionHasErrors(['commissioni' => CommissioniSenzaVoce::STORNI_DI_DUE_TIPI]);
    expect(DB::table('righe_scritture')->whereIn('id', $righe->all())->whereNotNull('voce_spesa_id')->count())->toBe(0);
});

it('con uno storno scelto le frasi di errore dell\'assegnazione dicono il vero e non parlano di commissioni: la voce che manca, nessuna riga scelta, «tutte» senza la gestione o in una gestione senza righe da assegnare, una riga che nel frattempo ha preso una voce, due gestioni', function () {
    $s = sadScenario($this);
    $riga = sadElenco($s, sadStorna($this, $s, 'regolazione'))['riga'];
    $sg27 = sadVoceDel2027($this, $s, $s['o27'], 'Spese generali 2027');
    $assegna = fn (array $dati) => $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]), $dati);
    $errore = fn ($risposta, string $campo) => (string) (session('errors')?->first($campo) ?? '');
    $frasi = [];

    $frasi['senza voce'] = $errore($assegna(['commissioni' => [(int) $riga->id]]), 'voce_id');
    $frasi['nessuna riga'] = $errore($assegna(['commissioni' => [], 'voce_id' => $sg27->id]), 'commissioni');
    $frasi['tutte senza gestione'] = $errore($assegna(['tutte' => true, 'voce_id' => $sg27->id]), 'gestione_id');
    // «Tutte» nell'«Ordinaria 2027», che non ha righe da assegnare (lo storno è della gestione della regolazione).
    $frasi['tutte, nessuna'] = $errore($assegna(['tutte' => true, 'gestione_id' => $s['o27']->id, 'voce_id' => $sg27->id]), 'commissioni');

    // Lo storno di un incasso da terzi di un'altra gestione del 2026 (una straordinaria): due gestioni nella scelta.
    $altra = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata 2026', 'tipo' => 'straordinaria', 'attiva' => true, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $incasso = sadStorna($this, $s, 'incasso');
    DB::table('scritture_contabili')->where('id', $incasso['storno']->id)->update(['gestione_id' => $altra->id]);
    $frasi['due gestioni'] = $errore($assegna(['commissioni' => [(int) $riga->id, (int) sadElenco($s, $incasso)['riga']->id], 'voce_id' => $sg27->id]), 'commissioni');

    // Un'altra pagina ha già dato la voce allo storno.
    DB::table('righe_scritture')->where('id', $riga->id)->update(['voce_spesa_id' => $sg27->id]);
    $frasi['già cambiata'] = $errore($assegna(['commissioni' => [(int) $riga->id], 'voce_id' => $sg27->id]), 'commissioni');

    expect($frasi)->toBe([
        'senza voce' => 'Scegli la voce da assegnare alle righe scelte.',
        'nessuna riga' => 'Nessuna riga da assegnare: scegline almeno una.',
        'tutte senza gestione' => 'Per assegnare la voce a tutte le righe, scegli la gestione.',
        'tutte, nessuna' => 'Nessuna riga da assegnare: scegline almeno una.',
        'due gestioni' => 'Le righe scelte sono di gestioni diverse: si assegnano una gestione alla volta, anche con la stessa voce.',
        'già cambiata' => CommissioniSenzaVoce::ERRORE_GIA_CAMBIATE,
    ])->and(CommissioniSenzaVoce::ERRORE_GIA_CAMBIATE)->toBe('Alcune righe scelte non sono più senza voce, o non sono di questo esercizio: ricarica l\'elenco e riprova.');
    foreach ($frasi as $quale => $frase) {
        expect($frase)->not->toContain('commission', $quale);
    }
});

it('lo storno nel 2027 di una regolazione che era già senza voce: l\'elenco lo mostra e non lo offre, e dice perché; la Sintesi e il controllo dei costi dicono che qui non si assegna, senza dire che la voce era di un\'altra gestione', function () {
    $s = sadScenario($this);
    DB::table('righe_scritture')->where('scrittura_id', $s['regolazione']->id)->whereNotNull('voce_spesa_id')->update(['voce_spesa_id' => null]);
    $x = sadStorna($this, $s, 'regolazione');
    $e = sadElenco($s, $x);

    expect([$e['voce']['assegnabile'], $e['voce']['importo']])->toBe([false, -9600])
        ->and($e['voce']['motivo'])->toBe('È lo storno di una regolazione immediata dell\'esercizio «Esercizio 2026», che era già senza voce: lo storno resta senza voce anche lui, fra i costi non classificati del rendiconto.')
        ->and($e['elenco']['storni_assegnabili'])->toBe(['numero' => 0, 'importo' => 0])
        ->and($e['fascia']['da_assegnare'])->toBe(0)
        ->and($e['fascia']['frase'])->toBe('€ -96,00 di storni di registrazioni di un esercizio chiuso, senza voce, che qui non si assegnano: non stanno in nessun capitolo, e il conguaglio non li troverà. L\'elenco dice perché.')
        ->and($e['cr']['esito'])->toBe('quadra')
        ->and($e['cr']['nota'])->not->toContain('la voce era di una gestione')
        ->and($e['cr']['nota'])->toContain('€ -96,00 (fra i costi non classificati, e fuori dal riparto: qui non si assegnano, e l\'elenco delle commissioni e degli storni senza voce dice perché).');

    $sg27 = sadVoceDel2027($this, $s, $s['o27'], 'Spese generali 2027');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $e['riga']->id], 'voce_id' => $sg27->id])->assertSessionHasErrors(['commissioni' => $e['voce']['motivo']]);
    expect(DB::table('righe_scritture')->where('id', $e['riga']->id)->value('voce_spesa_id'))->toBeNull();
});

it('la fascia della Sintesi, il controllo dei costi e la pagina del riepilogo guardano le righe degli storni senza voce, non la loro somma: storni che si annullano si dicono, e con segni diversi la frase ha le righe e la somma senza il segno; il controllo dei costi dice quanti si assegnano e quanti no', function (array $altre, array $quali, int $netto, int $righe, int $daAssegnare, string $fascia, string $storni) {
    $s = sadScenario($this, false, $altre);
    foreach ($quali as $quale) {
        sadStorna($this, $s, $quale);
    }
    foreach ($s['altre'] as $altra) {
        $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $altra]), ['motivo' => 'Addebito annullato dalla banca'])
            ->assertSessionHasNoErrors();
    }
    $e = sadElenco($s, ['righe' => collect()]);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e27']]))->assertOk()->viewData('page')['props'];
    $euro = '€ '.number_format($netto / 100, 2, ',', '.');

    expect([count(array_filter($e['elenco']['commissioni'], fn ($c) => $c['natura'] === 'registrazione')), $e['elenco']['storni_assegnabili']['numero']])->toBe([$righe, $daAssegnare])
        ->and([$e['fascia']['importo'] ?? null, $e['fascia']['numero'] ?? null, $e['fascia']['da_assegnare'] ?? null])->toBe([$netto, $righe, $daAssegnare])
        ->and($e['fascia']['frase'] ?? null)->toBe($fascia)
        ->and($e['cr']['esito'])->toBe('quadra')
        ->and($e['cr']['nota'])->toBe('Costi dell\'esercizio '.$euro.' = spese per capitolo meno entrate € 0,00 + commissioni senza voce € 0,00 + fatture contestate € 0,00'
            .' + storni di registrazioni di un esercizio chiuso, senza voce, '.$euro.' ('.$storni.').')
        ->and($pagina['storni_assegnabili'])->toBe($daAssegnare);
})->with([
    // La regolazione di € 96,00 e un'altra di € 54,00, tutte e due da assegnare: lo stesso segno, la somma dice tutto.
    'due storni dello stesso segno' => [[[54.00, false]], ['regolazione'], -15000, 2, 2,
        '€ -150,00 di storni di registrazioni di un esercizio chiuso, senza voce: non stanno in nessun capitolo, e il conguaglio non li troverà.',
        'fra i costi non classificati, e fuori dal riparto finché non prendono una voce: 2 si assegnano nell\'elenco delle commissioni e degli storni senza voce'],
    // −€ 96,00, −€ 30,00 di una regolazione già senza voce, +€ 240,00 dell'incasso: € 114,00, € 366,00 senza il segno; due si assegnano, uno no.
    'tre storni di segno diverso, uno che non si assegna' => [[[30.00, true]], ['regolazione', 'incasso'], 11400, 3, 2,
        '€ 114,00 di storni di registrazioni di un esercizio chiuso, senza voce (3 righe, € 366,00 senza il segno): non stanno in nessun capitolo, e il conguaglio non li troverà.',
        '3 righe, € 366,00 senza il segno; fra i costi non classificati, e fuori dal riparto finché non prendono una voce: 2 si assegnano e 1 no, e l\'elenco delle '
            .'commissioni e degli storni senza voce dice perché'],
    // −€ 96,00, −€ 144,00 e +€ 240,00: la somma è zero, le righe sono tre e si assegnano tutte.
    'tre storni che si annullano' => [[[144.00, false]], ['regolazione', 'incasso'], 0, 3, 3,
        '€ 0,00 di storni di registrazioni di un esercizio chiuso, senza voce (3 righe, € 480,00 senza il segno): non stanno in nessun capitolo, e il conguaglio non li troverà.',
        '3 righe, € 480,00 senza il segno; fra i costi non classificati, e fuori dal riparto finché non prendono una voce: 3 si assegnano nell\'elenco delle '
            .'commissioni e degli storni senza voce'],
]);

it('la pagina del riepilogo finanziario del 2027 riceve quanti storni senza voce si assegnano, con la regola dell\'elenco: 1 con lo storno della regolazione, 0 con il 2027 chiuso, 0 dopo l\'assegnazione e senza storni', function () {
    $s = sadScenario($this);
    $pagina = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo', [$s['c'], $s['e27']]))->assertOk()->viewData('page')['props'];
    expect($pagina()['storni_assegnabili'])->toBe(0);

    $riga = sadElenco($s, sadStorna($this, $s, 'regolazione'))['riga'];
    $p = $pagina();
    expect($p['storni_assegnabili'])->toBe(1)
        ->and($p['riepilogo']['fuori_capitoli']['non_classificati']['consuntivo'])->toBe(-9600);

    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    expect($pagina()['storni_assegnabili'])->toBe(0);
    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'aperto']);

    $sg27 = sadVoceDel2027($this, $s, $s['o27'], 'Spese generali 2027');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $sg27->id])->assertSessionHasNoErrors();
    expect($pagina()['storni_assegnabili'])->toBe(0);
});

it('con il 2027 chiuso lo storno senza voce di una regolazione si vede e non si assegna: il motivo dice che l\'esercizio è chiuso, non che si assegna', function () {
    $s = sadScenario($this);
    $x = sadStorna($this, $s, 'regolazione');
    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    $e = sadElenco($s, $x);

    expect($e['voce']['assegnabile'])->toBeFalse()
        ->and($e['voce']['motivo'])->toBe('È lo storno di una regolazione immediata dell\'esercizio «Esercizio 2026», che era sulla voce «Spese generali» della gestione «'.$s['g']->nome
            .'»: questo esercizio non ha quella gestione. L\'esercizio è chiuso: lo storno resta senza voce, fra i costi non classificati del rendiconto.')
        ->and($e['elenco']['storni_assegnabili'])->toBe(['numero' => 0, 'importo' => 0]);
});

it('con il 2027 chiuso dopo che la pagina è aperta, «Assegna la voce» sullo storno di una regolazione risponde con la frase della pagina, che dice anche degli storni, e nessuna riga cambia', function () {
    $s = sadScenario($this);
    $riga = sadElenco($s, sadStorna($this, $s, 'regolazione'))['riga'];
    $sg27 = sadVoceDel2027($this, $s, $s['o27'], 'Spese generali 2027');
    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    $frase = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $s['e27']]))->assertOk()
        ->viewData('page')['props']['frase_chiuso'];

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e27']]),
        ['commissioni' => [(int) $riga->id], 'voce_id' => $sg27->id])->assertSessionHasErrors(['commissioni' => $frase]);
    expect($frase)->toBe(CommissioniSenzaVoce::fraseChiuso('Esercizio 2027', conStorni: true))->toContain('Gli storni senza voce restano fra i costi non classificati.')
        ->and(DB::table('righe_scritture')->where('id', $riga->id)->value('voce_spesa_id'))->toBeNull();
});

it('la pagina dell\'elenco del 2027 riceve lo storno con la sua natura, le voci di spesa e, a parte, quelle di entrata; con il 2027 chiuso la frase dice anche degli storni', function () {
    $s = sadScenario($this);
    sadStorna($this, $s, 'incasso');
    $aa27 = sadVoceDel2027($this, $s, $s['o27'], 'Affitto antenna 2027', 'entrata');
    $pagina = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $s['e27']]))->assertOk()->viewData('page')['props'];
    $p = $pagina();

    expect(collect($p['commissioni'])->map(fn ($c) => [$c['natura'], $c['voce_di_entrata']])->all())->toBe([['registrazione', true]])
        ->and(collect($p['voci_di_entrata'])->pluck('id')->all())->toContain($aa27->id)
        ->and(collect($p['voci'])->pluck('id')->all())->not->toContain($aa27->id)
        ->and($p['frase_chiuso'])->toBeNull();

    DB::table('esercizi')->where('id', $s['e27']->id)->update(['stato' => 'chiuso']);
    expect($pagina()['frase_chiuso'])->toBe('L\'esercizio «Esercizio 2027» è chiuso: le commissioni senza voce restano come sono, e il suo rendiconto le mostra in una riga '
        .'loro. Gli storni senza voce restano fra i costi non classificati. La voce si assegna solo negli esercizi aperti.');
});
