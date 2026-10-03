<?php

/**
 * B3a (1.11.0-beta.34) — il prospetto degli oneri accessori, per unità e per esercizio.
 *
 * Non è un riparto e non è una richiesta del condominio: verso il condominio risponde il proprietario (D11, Cass.
 * 19650/2006). È il documento con cui il proprietario regola le spese col conduttore — l'«indicazione specifica delle
 * spese con la menzione dei criteri di ripartizione» dell'art. 9 co. 3 L. 392/1978 — e lo fa sui numeri del piano
 * rate, cioè sul **preventivo** (decisione di Vincenzo del 26/09/2026: il consuntivo arriva con il rendiconto 1.12).
 *
 * Il disegno sta in `docs/piano_esecutivo_beta34_b3a.md` («passo (3)»). Tre fatti del codice lo hanno deciso:
 * le voci «inquilino» sono già intestate all'inquilino nelle rate; quando nessun inquilino è registrato nei giorni del
 * piano le paga il proprietario per ripiego, ed è quella la parte da farsi rimborsare; la percentuale inquilino non è
 * congelata, gli importi per ruolo sì.
 *
 * **Cosa resta scoperto**: il piano straordinario (competenza di un giorno alla delibera); due co-inquilini
 * contemporanei a quote diverse; il già versato (`netting`) sulla chiave del conduttore; più piani nello stesso
 * esercizio; un piano senza `esercizio_id` (esercizio dedotto); il contenuto del PDF oltre intestazioni e formato.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Un'unità sola (1000 millesimi su 1000), tre voci del preventivo ordinario 2026:
 * «Spese generali» € 600,00 tutta al proprietario, «Pulizia scale» € 365,00 tutta all'inquilino, «Ascensore» € 730,00
 * metà e metà. Gli inquilini dati sono registrati **prima** della generazione.
 *
 * @param list<array{nome: string, dal: string, al: ?string}> $inquilini
 */
function poScenario(array $inquilini = [], string $statoPiano = 'approvato'): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach ([['Spese generali', 60000, ['proprietario' => 100]], ['Pulizia scale', 36500, ['inquilino' => 100]], ['Ascensore', 73000, ['proprietario' => 50, 'inquilino' => 50]]] as [$nome, $importo, $soggetti]) {
        $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($soggetti as $soggetto => $percentuale) {
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PO-{$seq}", 'nome' => 'Interno 3', 'interno' => '3']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $cf) use ($c, $seq) {
        $a = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . "{$seq}@test.it", 'indirizzo' => 'Via Roma 3', 'codice_fiscale' => $cf . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
        $a->condomini()->syncWithoutDetaching([$c->id]);

        return $a;
    };
    $p = $persona('Paola Proprietaria', 'POPROPRIET00');
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $conduttori = [];
    foreach ($inquilini as $i => $inq) {
        $conduttori[$i] = $persona($inq['nome'], 'POINQUILIN' . $i . '0');
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $conduttori[$i]->id, 'immobile_id' => $unita->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => $inq['dal'], 'data_fine' => $inq['al'], 'created_at' => now(), 'updated_at' => now()]);
    }

    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => $statoPiano,
        'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata',
    ]);
    // Con un inquilino che cambia nell'anno il cancello (2) chiede la presa d'atto, come all'amministratore.
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Cambio inquilino nell\'anno, letto', esercizio: $e);

    return compact('c', 'e', 'g', 'unita', 'p', 'conduttori', 'piano', 'persona');
}

/** Le voci di una sezione, per nome del conto: [importo, modo]. */
function poVoci(array $sezione): array
{
    return collect($sezione['voci'])->mapWithKeys(fn ($v) => [$v['conto'] => [$v['importo'], $v['modo']]])->all();
}

it('due conduttori registrati prima della generazione: ognuno ha la sua sezione con le voci «inquilino» dei suoi giorni, già nelle sue rate, e la quota inquilino si legge dagli importi congelati (100 % e 50 %)', function () {
    $s = poScenario([
        ['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => '2026-04-30'],
        ['nome' => 'Ines Seconda', 'dal' => '2026-05-01', 'al' => null],
    ]);
    // La configurazione di oggi non conta: il prospetto è l'istantanea del riparto deliberato (§3.3 delle pertinenze).
    DB::table('conto_tabella_ripartizioni')->where('soggetto', 'inquilino')->update(['percentuale' => 10]);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);

    expect($p['conduttori'])->toHaveCount(2)
        ->and($p['conduttori'][0]['nome'])->toBe('Ivo Primo')
        ->and($p['conduttori'][0]['periodo'])->toBe(['dal' => '2026-01-01', 'al' => '2026-04-30'])
        // Pulizia 36.500 × 120/365 = 12.000; ascensore, metà inquilino 36.500 × 120/365 = 12.000.
        ->and(poVoci($p['conduttori'][0]))->toBe(['Pulizia scale' => [12000, 'rate'], 'Ascensore' => [12000, 'rate']])
        ->and($p['conduttori'][0]['totale'])->toBe(24000)->and($p['conduttori'][0]['nelle_sue_rate'])->toBe(24000)->and($p['conduttori'][0]['da_rimborsare'])->toBe(0)
        ->and($p['conduttori'][1]['nome'])->toBe('Ines Seconda')
        ->and($p['conduttori'][1]['periodo'])->toBe(['dal' => '2026-05-01', 'al' => '2026-12-31'])
        ->and(poVoci($p['conduttori'][1]))->toBe(['Pulizia scale' => [24500, 'rate'], 'Ascensore' => [24500, 'rate']])
        ->and($p['senza_conduttore']['totale'])->toBe(0)
        ->and($p['totale_inquilino'])->toBe(73000);

    $pulizia = collect($p['conduttori'][0]['voci'])->firstWhere('conto', 'Pulizia scale');
    $ascensore = collect($p['conduttori'][0]['voci'])->firstWhere('conto', 'Ascensore');
    expect($pulizia['quota_inquilino'])->toBe(100)->and($ascensore['quota_inquilino'])->toBe(50)
        ->and($pulizia['giorni'])->toBe(120)
        ->and($pulizia['tabella'])->toBe('Proprietà')
        ->and($pulizia['millesimi'])->toBe(['valore' => 1000.0, 'somma' => 1000.0, 'unita' => 'millesimi']);
    // Le spese generali sono del proprietario: nel prospetto non compaiono.
    expect(collect($p['conduttori'])->flatMap(fn ($c) => $c['voci'])->pluck('conto')->unique()->values()->all())->toBe(['Pulizia scale', 'Ascensore']);
});

it('locazione registrata DOPO la generazione (dal 1° marzo): le voci «inquilino» le ha pagate la proprietaria per ripiego; il prospetto le divide sui giorni di conduzione (306 da rimborsare) e il resto (59 giorni) resta a lei', function () {
    $s = poScenario();
    $inquilina = ($s['persona'])('Irma Nuova', 'POINQUILINX0');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $inquilina->id, 'decorrenza' => '2026-03-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-02-28', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Contratto registrato dopo il preventivo',
    ])->assertSessionHasNoErrors();

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);

    expect($p['conduttori'])->toHaveCount(1)
        ->and($p['conduttori'][0]['nome'])->toBe('Irma Nuova')
        ->and($p['conduttori'][0]['periodo'])->toBe(['dal' => '2026-03-01', 'al' => '2026-12-31'])
        ->and($p['conduttori'][0]['regime'])->toBe('abitativo')
        // 36.500 × 306/365 = 30.600 per voce: le ha pagate Paola, Irma gliele rimborsa.
        ->and(poVoci($p['conduttori'][0]))->toBe(['Pulizia scale' => [30600, 'proprietario'], 'Ascensore' => [30600, 'proprietario']])
        ->and(collect($p['conduttori'][0]['voci'])->pluck('pagato_da')->unique()->all())->toBe(['Paola Proprietaria'])
        ->and($p['conduttori'][0]['nelle_sue_rate'])->toBe(0)->and($p['conduttori'][0]['da_rimborsare'])->toBe(61200)
        // I 59 giorni di gennaio e febbraio: nessun conduttore, restano a chi ha pagato.
        ->and($p['senza_conduttore']['totale'])->toBe(11800)
        ->and(collect($p['senza_conduttore']['voci'])->pluck('giorni')->unique()->all())->toBe([59])
        ->and($p['totale_inquilino'])->toBe(73000)
        // R22: il totale del ruolo inquilino si legge diviso.
        ->and($p['totale_conduttori'])->toBe(61200);
    // L'art. 9 nel regime abitativo: indicazione specifica, giustificativi, due mesi dalla ricezione della richiesta del locatore.
    expect($p['conduttori'][0]['nota_contratto'])->toContain('art. 9 L. 392/1978')->toContain('due mesi dalla ricezione')->toContain('locatore');
});

it('il regime decide la nota: atipica e comodato rinviano al contratto senza l\'art. 9; senza regime registrato la frase è condizionale; e la testa dice cosa il documento non è', function () {
    $s = poScenario([['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => null]]);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $nota = $p['conduttori'][0]['nota_contratto'];
    // Inquilino registrato da «Associa»: nessun regime. Non si tira a indovinare.
    expect($p['conduttori'][0]['regime'])->toBeNull()
        ->and($nota)->toContain('non è registrato')->toContain('se è una locazione abitativa o a uso diverso')
        ->and(implode("\n", $p['intestazione']))->toContain('Non è un riparto')->toContain('preventivo')->toContain('rendiconto')->toContain('per giorni')->toContain('contabilizzazione del calore')
        ->not->toContain('entro due mesi');

    $atipica = app(ProspettoOneriAccessori::class)->notaContratto('atipica');
    $comodato = app(ProspettoOneriAccessori::class)->notaContratto('comodato');
    expect($atipica)->toContain('si regola nel contratto')->not->toContain('due mesi')
        ->and($comodato)->toContain('1808')->not->toContain('due mesi')
        ->and(app(ProspettoOneriAccessori::class)->notaContratto('uso_diverso'))->toContain('art. 27')->toContain('art. 9 L. 392/1978');
});

it('i piani in bozza non entrano e si nominano; un piano della 1.10 senza riparto registrato si legge ricostruito e lo dice', function () {
    $s = poScenario([['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => null]], 'bozza');
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($p['conduttori'])->toBe([])->and($p['piani'])->toBe([])
        ->and($p['piani_esclusi'])->toBe([['nome' => 'Preventivo 2026', 'motivo' => 'in bozza: non ancora approvato']]);

    $s['piano']->update(['stato' => 'approvato']);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $p2 = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($p2['piani'][0]['fonte'])->toBe('ricostruito')
        ->and($p2['conduttori'][0]['totale'])->toBe(73000);
});

it('la stampa chiude la frase del totale diviso con il punto attaccato: «… che restano a chi le ha pagate.» (Fase 5 della beta.34)', function () {
    $s = poScenario();
    $inquilina = ($s['persona'])('Irma Nuova', 'POINQUILINX0');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $inquilina->id, 'decorrenza' => '2026-03-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-02-28', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Contratto registrato dopo il preventivo',
    ])->assertSessionHasNoErrors();

    $html = view('pdf.gestionale.prospetto_oneri_accessori', [
        'condominio' => $s['c'], 'esercizio' => $s['e'], 'immobile' => $s['unita'],
        'prospetto' => app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']),
        'nota_legale_stampe' => '', 'firma_stampe_absolute_path' => null,
    ])->render();
    $testo = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    expect($testo)->toContain('€ 730,00, di cui € 612,00 ai conduttori e € 118,00 che restano a chi le ha pagate.')
        ->not->toContain('pagate .');
});

it('la stampa: PDF inline dalla pagina dell\'unità, esercizio in query; un\'unità o un esercizio di un altro condominio rispondono 404', function () {
    $s = poScenario([['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => null]]);
    $r = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita'], 'esercizio' => $s['e']->id]));
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($r->headers->get('Content-Disposition'))->toStartWith('inline; filename="prospetto-oneri-')
        ->and(substr($r->getContent(), 0, 4))->toBe('%PDF');

    $altro = poScenario();
    $this->actingAs($this->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $altro['unita'], 'esercizio' => $s['e']->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.immobili.prospetto-oneri', [$s['c'], $s['unita'], 'esercizio' => $altro['e']->id]))->assertNotFound();
});

it('la pagina dei titolari offre il prospetto solo se l\'unità ha o ha avuto un inquilino, con gli esercizi del condominio', function () {
    $s = poScenario([['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => '2026-04-30']]);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$s['c'], $s['unita']]))->assertOk()->viewData('page')['props'];
    expect($props['prospettoOneri'])->not->toBeNull()
        ->and(collect($props['prospettoOneri']['esercizi'])->pluck('id')->all())->toBe([$s['e']->id]);

    $senza = poScenario();
    $props2 = $this->actingAs($this->user)->get(route('admin.gestionale.immobili.anagrafiche.index', [$senza['c'], $senza['unita']]))->assertOk()->viewData('page')['props'];
    expect($props2['prospettoOneri'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della beta.34 — i reperti confermati sul prospetto
|--------------------------------------------------------------------------
*/

/** Registra un passaggio di locazione dalla rotta vera. */
function poLocazione($test, array $s, array $dati): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati + [
        'quota' => 100, 'tipologia' => 'inquilino', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Locazione registrata, letto',
    ])->assertSessionHasNoErrors();
}

/** Rigenera il piano dello scenario (dopo aver cambiato la titolarità). */
function poRigenera(array $s): void
{
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), accettaDestinatari: true, notaDestinatari: 'Rigenerato per la prova', esercizio: $s['e']);
}

it('R2 — piano della 1.10 senza riparto registrato e locazione registrata dopo: le voci non si riconducono alle rate, il piano esce e si dice perché (non «nelle sue rate» a chi non ha rate)', function () {
    $s = poScenario();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $irma = ($s['persona'])('Irma Nuova', 'POINQUILINR2');
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $irma->id, 'decorrenza' => '2026-03-01']);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($p['conduttori'])->toBe([])
        ->and($p['piani_esclusi'][0]['motivo'])->toContain('i titolari dell\'unità sono cambiati dopo la generazione');
});

it('R5 — inquilino dal 1/3 al 31/10 già alla generazione: i 120 giorni senza inquilino sono due buchi, il ripiego della proprietaria non si divide su Ivo, che non ha niente da rimborsare', function () {
    $s = poScenario();
    $ivo = ($s['persona'])('Ivo Primo', 'POINQUILINR5');
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $ivo->id, 'decorrenza' => '2026-03-01']);
    poLocazione($this, $s, ['tipo' => 'fine_locazione', 'riga_uscente_id' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $ivo->id)->value('id'), 'decorrenza' => '2026-11-01']);
    poRigenera($s);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    // Ivo: 245 giorni nelle sue rate, 24.500 per voce; il ripiego (12.000 per voce, 120 giorni) resta a Paola.
    expect($p['conduttori'])->toHaveCount(1)
        ->and($p['conduttori'][0]['nelle_sue_rate'])->toBe(49000)->and($p['conduttori'][0]['da_rimborsare'])->toBe(0)
        ->and($p['senza_conduttore']['totale'])->toBe(24000)
        ->and(collect($p['senza_conduttore']['voci'])->pluck('giorni')->unique()->all())->toBe([120]);
});

it('R6 — voce a tratti (riscaldamento 1/1–15/4 + 15/10–31/12) e inquilina registrata dopo dal 1/5: le spettano i giorni della stagione (78 su 183), come se fosse stata registrata prima', function () {
    $conti = fn (array $s) => DB::table('conti')->whereIn('piano_conto_id', DB::table('piani_conti')->where('gestione_id', $s['g']->id)->pluck('id'))->pluck('id', 'nome');
    $prova = function (bool $prima) use ($conti) {
        $s = poScenario();
        $irma = ($s['persona'])('Irma Nuova', $prima ? 'POINQUIR6PRIMA' : 'POINQUIR6DOPO0');
        $c = $conti($s);
        $s['piano']->capitoli()->attach($c->mapWithKeys(fn ($id) => [$id => ['importo' => (int) DB::table('conti')->where('id', $id)->value('importo')]])->all());
        $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $c['Pulizia scale'])->value('id');
        \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 0]);
        \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 1]);
        if ($prima) {
            poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $irma->id, 'decorrenza' => '2026-05-01']);
        }
        poRigenera($s);
        if (! $prima) {
            poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $irma->id, 'decorrenza' => '2026-05-01']);
        }

        return collect(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e'])['conduttori'][0]['voci'])->where('conto', 'Pulizia scale')->values();
    };
    $dopo = $prova(false);
    $prima = $prova(true);
    // 36.500 × 78/183 = 15.557: stessa cifra e stessi giorni, che la locazione sia registrata prima o dopo la generazione.
    expect((int) $dopo->sum('importo'))->toBe(15557)->and((int) $dopo->sum('giorni'))->toBe(78)
        ->and((int) $prima->sum('importo'))->toBe(15557)
        ->and($dopo->first()['competenza'])->toBe([['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-10-15', 'al' => '2026-12-31']]);
});

it('R10 — vendita dopo la generazione e locazione registrata dopo: le voci che l\'inquilino deve rimborsare le hanno pagate Paola fino al 30/4 e Aldo dal 1/5, e il prospetto nomina i due', function () {
    $s = poScenario();
    $aldo = ($s['persona'])('Aldo Compratore', 'POCOMPRAR10');
    $ivo = ($s['persona'])('Ivo Primo', 'POINQUILR10');
    $rigaP = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['p']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaP, 'anagrafica_entrante_id' => $aldo->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-05', 'estremi_titolo' => 'rep. 10', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita, letto',
    ])->assertSessionHasNoErrors();
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $ivo->id, 'decorrenza' => '2026-01-01']);

    $pulizia = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e'])['conduttori'][0]['voci'])->where('conto', 'Pulizia scale');
    expect($pulizia->mapWithKeys(fn ($v) => [$v['pagato_da'] => [$v['importo'], $v['giorni']]])->all())
        ->toBe(['Paola Proprietaria' => [12000, 120], 'Aldo Compratore' => [24500, 245]]);
});

it('beta.38 — vendita con riserva d\'usufrutto: le ordinarie le paga ancora chi vende, ora usufruttuaria, e il prospetto nomina solo lei per tutto l\'anno', function () {
    $s = poScenario();
    $aldo = ($s['persona'])('Aldo Nudo', 'PONUDOPROP38');
    $ivo = ($s['persona'])('Ivo Primo', 'POINQUILR38');
    $rigaP = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['p']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'sottotipo' => 'riserva_usufrutto', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $rigaP, 'anagrafica_entrante_id' => $aldo->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'tipologia' => 'nuda_proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-05', 'estremi_titolo' => 'rep. 38', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Vendita della nuda proprietà con riserva d\'usufrutto, letto',
    ])->assertSessionHasNoErrors();
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $ivo->id, 'decorrenza' => '2026-01-01']);

    // Nella vendita piena (R10) Aldo avrebbe pagato dal 1/5; con la riserva l'ordinaria resta a Paola (art. 1004 c.c.).
    $pulizia = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e'])['conduttori'][0]['voci'])->where('conto', 'Pulizia scale');
    expect($pulizia->mapWithKeys(fn ($v) => [$v['pagato_da'] => [$v['importo'], $v['giorni']]])->all())
        ->toBe(['Paola Proprietaria' => [36500, 365]]);
});

it('beta.38 — riserva d\'usufrutto e poi estinzione dell\'usufrutto: le ordinarie le paga chi vendeva fino al giorno prima dell\'estinzione, e dal giorno dell\'estinzione il nudo proprietario tornato pieno; il prospetto nomina i due, con i loro giorni', function () {
    $s = poScenario();
    $aldo = ($s['persona'])('Aldo Nudo', 'PONUDOPRE38');
    $ivo = ($s['persona'])('Ivo Primo', 'POINQUIE38');
    $rigaP = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['p']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'sottotipo' => 'riserva_usufrutto', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $rigaP, 'anagrafica_entrante_id' => $aldo->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'tipologia' => 'nuda_proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-05', 'estremi_titolo' => 'rep. 38', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Vendita della nuda proprietà con riserva d\'usufrutto, letto',
    ])->assertSessionHasNoErrors();
    $rigaUsu = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['p']->id)->where('tipologia', 'usufruttuario')->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione dell\'usufrutto, letta',
    ])->assertSessionHasNoErrors();
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $ivo->id, 'decorrenza' => '2026-01-01']);

    // La riserva non fa avanzare la catena dei pagatori, l'estinzione sì: 36.500 × 243/365 = 24.300 a Paola (1/1–31/8),
    // 36.500 × 122/365 = 12.200 ad Aldo (1/9–31/12).
    $pulizia = collect(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e'])['conduttori'][0]['voci'])->where('conto', 'Pulizia scale');
    expect($pulizia->mapWithKeys(fn ($v) => [$v['pagato_da'] => [$v['importo'], $v['giorni']]])->all())
        ->toBe(['Paola Proprietaria' => [24300, 243], 'Aldo Nudo' => [12200, 122]]);
});

it('R13 — un piano che non ricorda l\'esercizio: nel prospetto dell\'esercizio a cui la data di creazione lo attribuisce lo si dice in testa, in quello della sua gestione si nomina fra gli esclusi', function () {
    $s = poScenario([['nome' => 'Ivo Primo', 'dal' => '2020-01-01', 'al' => null]]);
    $e25 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e25, $s['g']->id);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null, 'created_at' => '2025-12-10 10:00:00']);

    $p26 = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $p25 = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $e25);
    expect($p26['piani'])->toBe([])->and($p26['piani_esclusi'][0]['motivo'])->toContain('attribuito all\'esercizio «Esercizio 2025» dalla data di creazione')
        ->and(implode("\n", $p25['intestazione']))->toContain('non ricorda l\'esercizio con cui è stato generato');
});

it('R14 — una riga spenta non fa da predecessore: con Anna spenta fino al 28/2 e Bruno associato dal 1/3, il motore dà l\'anno intero a Bruno e il prospetto dice lo stesso periodo', function () {
    $s = poScenario([['nome' => 'Anna Spenta', 'dal' => '2020-01-01', 'al' => '2026-02-28'], ['nome' => 'Bruno Attivo', 'dal' => '2026-03-01', 'al' => null]]);
    DB::table('anagrafica_immobile')->where('anagrafica_id', $s['conduttori'][0]->id)->update(['attivo' => false]);
    poRigenera($s);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($p['conduttori'])->toHaveCount(1)->and($p['conduttori'][0]['nome'])->toBe('Bruno Attivo')
        ->and($p['conduttori'][0]['periodo'])->toBe(['dal' => '2026-01-01', 'al' => '2026-12-31'])
        ->and($p['conduttori'][0]['totale'])->toBe(73000);
});

it('R15 e R19 — il comodatario si chiama per nome, e due contratti con un vuoto in mezzo sono due tratti, non un periodo solo', function () {
    $s = poScenario();
    $carlo = ($s['persona'])('Carlo Comodatario', 'POCOMODATR15');
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => '2026-01-01', 'regime_contratto' => 'comodato']);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $carlo->id)->value('id');
    poLocazione($this, $s, ['tipo' => 'fine_locazione', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-04-01']);
    poLocazione($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => '2026-07-01', 'regime_contratto' => 'comodato']);

    $c = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e'])['conduttori'][0];
    expect($c['ruolo'])->toBe('Comodatario')->and($c['regime'])->toBe('comodato')
        ->and($c['tratti'])->toBe([['dal' => '2026-01-01', 'al' => '2026-03-31'], ['dal' => '2026-07-01', 'al' => '2026-12-31']]);
});

