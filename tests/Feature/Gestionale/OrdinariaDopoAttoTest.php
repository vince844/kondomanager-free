<?php

/**
 * # Chi paga l'ordinaria dal giorno dell'atto — decisioni 31.5, 31.6 e 31.7 (1.11.0-beta.41, Coda 171)
 *
 * Alla costituzione e alla riserva d'usufrutto l'amministratore sceglie: «all'usufruttuario (art. 1004 c.c.)», la proposta
 * di legge, già scelta; oppure «al nudo proprietario, come dice la voce».
 *
 * - **Con la legge** il conguaglio resta quello di sempre — nella riserva l'ordinaria resta a chi vende, nella costituzione
 *   passa all'usufruttuario per i giorni — e le voci sul «Proprietario» delle gestioni ordinarie passano all'«Usufruttuario»
 *   per i piani futuri: una regola sola, lo stesso euro alla stessa persona dal piano e dal conguaglio. L'amministratore
 *   può togliere la spunta a una voce (31.6).
 * - **Con la voce** il conguaglio segue la voce conto per conto (29.1), e le voci non si toccano.
 * - **Annullando** il passaggio le voci spostate restano, e l'annullamento lo dice (31.7).
 *
 * Lo scenario è quello di `RiservaUsufruttoTest`: Ugo proprietario al 100 %, una voce «Spese generali» sulla tabella
 * «Proprietà», € 1.200,00 in dodici rate, le prime quattro emesse, l'atto il 1/05/2026 (245 giorni su 365 dopo l'atto).
 *
 * Dalla Fase 1-bis della beta.41 copre anche: le catene con un'estinzione in mezzo (D4, D5), la voce divisa fra due ruoli
 * (D6), la pertinenza che porta la scelta del padre (D7), le frasi del conguaglio con «come dice ogni voce», la richiesta
 * senza la scelta (A3), l'elenco delle voci cambiato fra anteprima e clic (S1), lo storico (A2), l'annullamento con i
 * coefficienti di prima (A6), il cancello della riserva (B1 della .38 e A5) e i passaggi da un altro condominio (S2).
 *
 * **Cosa NON copre.** Le voci candidate cercate sulle pertinenze (D7 prova il conguaglio del box, non una voce che solo il
 * box ha). Un passaggio su una quota sola con la scelta «come la voce» su un'unità che resta mista. Una catena di tre
 * anelli con due scelte «come la voce» diverse senza un'estinzione in mezzo (la combinazione che
 * `ConguaglioPassaggio::vociInsieme()` rende «nessuna voce»: non è raggiungibile dal modulo). Un piano con più gestioni
 * ordinarie aperte insieme. Il modulo nel browser: la spunta è provata con vitest (`vociDaSpostare.test.ts`), il riquadro
 * delle voci cambiate (S1) e la scheda a video, non da qui.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** L'associazione «Spese generali» × «Proprietà» dello scenario. */
function odaAssociazione(array $s): int
{
    return (int) DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
        ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conto_tabella_millesimale.id');
}

/** I coefficienti dell'associazione, come [soggetto => percentuale]. */
function odaCoefficienti(array $s): array
{
    return DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', odaAssociazione($s))->orderBy('soggetto')
        ->pluck('percentuale', 'soggetto')->map(fn ($p) => (float) $p)->all();
}

/** La costituzione dell'usufrutto a favore di Elsa, il 1/05: Ugo resta nudo proprietario. */
function odaCostituzione(array $s, array $extra = []): array
{
    return array_merge(['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta',
        'ordinaria_dopo_atto' => 'usufruttuario'], $extra);
}

/** La coppia di conguaglio di un passaggio: quanto va a debito di chi la riceve. */
function odaDebitoDi(array $s, Anagrafica $persona): int
{
    return (int) Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $persona->id)->sum('saldo_iniziale');
}

// --- L'anteprima --------------------------------------------------------------------------------------------------------

it('31.5 — alla riserva il pannello chiede chi paga l\'ordinaria: la legge è già proposta, e la voce sul «Proprietario» è elencata e spuntata', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];

    expect($ordinaria['applicabile'])->toBeTrue()
        ->and($ordinaria['scelta'])->toBe('usufruttuario')
        ->and($ordinaria['usufruttuario'])->toBe('Venditore Ugo')
        ->and($ordinaria['nudo'])->toBe('Acquirente Elsa')
        ->and(collect($ordinaria['voci'])->map(fn ($v) => [$v['conto'], $v['tabella'], $v['gestione'], $v['percentuale'], $v['spostata']])->all())
        ->toEqual([['Spese generali', 'Proprietà', 'Ordinaria 2026', 100.0, true]])
        ->and(implode("\n", $ordinaria['frasi']))
        // Il piano è in bozza e non c'è niente di emesso: la frase non promette un conguaglio (rilievo T-A1 della revisione della 1-ter).
        ->toContain('Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario (art. 1004 c.c.): per le voci di oggi, nei piani generati o ricalcolati dopo.')
        ->toContain('Per i piani che verranno, questa voce passa dal «Proprietario» all\'«Usufruttuario»: Spese generali (Proprietà, Ordinaria 2026).');
});

it('31.5 — alla costituzione la stessa domanda, con i ruoli rovesciati; non all\'estinzione, non nella vendita, non nella locazione', function () {
    $s = ruScenario('prima_rata', 0);
    $ordinaria = ruAnteprima($this, $s, odaCostituzione($s))['ordinaria'];
    expect($ordinaria['applicabile'])->toBeTrue()
        ->and($ordinaria['usufruttuario'])->toBe('Acquirente Elsa')
        ->and($ordinaria['nudo'])->toBe('Venditore Ugo');

    $vendita = ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 9', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita piena, letta'];
    expect(ruAnteprima($this, $s, $vendita)['ordinaria'])->toBe(['applicabile' => false, 'scelta' => null, 'usufruttuario' => null, 'nudo' => null, 'voci' => [], 'frasi' => [], 'frasi_bloccate' => [], 'frasi_altri_usufrutti' => [], 'impronta' => null, 'ereditata' => null]);

    ruRegistra($this, $s, ruRiserva($s));
    expect(ruAnteprima($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id'),
        'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione, letta'])['ordinaria']['applicabile'])->toBeFalse();
});

it('31.6 — una voce a cui si toglie la spunta resta sul «Proprietario», e il pannello lo dice', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $ordinaria = ruAnteprima($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [odaAssociazione($s)]]))['ordinaria'];

    expect($ordinaria['voci'][0]['spostata'])->toBeFalse()
        ->and(implode("\n", $ordinaria['frasi']))->toContain('Resta sul «Proprietario» la voce Spese generali: nei piani che verranno andrà a Acquirente Elsa, nudo proprietario.')
        ->not->toContain('questa voce passa');
});

it('31.5 — le altre unità in usufrutto della tabella: il pannello le nomina, con l\'importo dell\'ultimo piano che passerebbe dal nudo proprietario all\'usufruttuario', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $tabella = (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    DB::table('quote_tabella')->where('tabella_id', $tabella)->update(['valore' => 500]);
    $altra = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'ODA-2', 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella, 'immobile_id' => $altra->id, 'valore' => 500, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['usufruttuario' => 'Usufruttuaria Nina', 'nuda_proprietario' => 'Nudo Oreste'] as $ruolo => $nome) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "oda-{$ruolo}{$altra->id}@test.it", 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => strtoupper(substr($ruolo, 0, 6)) . 'ODA' . str_pad((string) $altra->id, 7, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $altra->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruPianoInBozza($s);

    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];

    // Metà della voce, € 600,00, l'ultimo piano l'ha data a Oreste nudo proprietario (la voce sul «Proprietario» scende al nudo).
    expect($ordinaria['voci'][0]['altre_unita'])->toBe([['immobile_id' => $altra->id, 'immobile' => 'Interno 2', 'usufruttuari' => 'Usufruttuaria Nina', 'nudi' => 'Nudo Oreste', 'importo' => 60000, 'importo_formattato' => '€ 600,00']])
        ->and(implode("\n", $ordinaria['frasi']))->toContain('Una voce vale per tutta la tabella: cambia chi paga anche su Interno 2 (Usufruttuaria Nina), € 600,00 nell\'ultimo piano, dal nudo proprietario all\'usufruttuario.');
});

// --- La registrazione ---------------------------------------------------------------------------------------------------

it('31.5 e 31.7 — con la legge la voce passa all\'«Usufruttuario» e il registro del passaggio scrive la scelta e i coefficienti di prima e di dopo', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(odaCoefficienti($s))->toBe(['usufruttuario' => 100.0])
        ->and($subentro->registro['ordinaria_dopo_atto'])->toBe('usufruttuario')
        ->and($subentro->registro['voci_spostate'])->toEqual([[
            'id' => odaAssociazione($s), 'conto' => 'Spese generali', 'tabella' => 'Proprietà', 'gestione' => 'Ordinaria 2026',
            'prima' => [['soggetto' => 'proprietario', 'percentuale' => 100.0]], 'dopo' => [['soggetto' => 'usufruttuario', 'percentuale' => 100.0]],
        ]])
        ->and($subentro->ordinariaComeLaVoce())->toBeFalse();
    // Il conguaglio delle rate emesse con la legge (nessuna coppia: l'ordinaria resta a chi vende) lo prova la 31.8, con il
    // piano approvato ed emesso: da approvato, la voce non si sposta più (decisione 31.9).
});

it('31.6 — con la spunta tolta la voce resta com\'era, e il registro non la nomina', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [odaAssociazione($s)]]));

    expect(odaCoefficienti($s))->toBe(['proprietario' => 100.0])
        ->and($subentro->registro['ordinaria_dopo_atto'])->toBe('usufruttuario')
        ->and($subentro->registro['voci_spostate'])->toBe([]);
});

it('31.6 — una voce senza coefficienti (il motore la legge «Proprietario 100 %») passa anche lei, e il registro dice com\'era', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', odaAssociazione($s))->delete();
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(odaCoefficienti($s))->toBe(['usufruttuario' => 100.0])
        ->and($subentro->registro['voci_spostate'][0]['prima'])->toEqual([['soggetto' => 'proprietario', 'percentuale' => 100.0]]);
});

it('31.6 — una voce divisa fra «Proprietario» e «Usufruttuario» somma le due parti sull\'«Usufruttuario»', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 70]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'usufruttuario', 'percentuale' => 30, 'created_at' => now(), 'updated_at' => now()]);
    ruRegistra($this, $s, ruRiserva($s));

    expect(odaCoefficienti($s))->toBe(['usufruttuario' => 100.0]);
});

it('31.5 — «come la voce» nella riserva: le voci non si toccano, e la voce sul «Proprietario» passa a chi compra la nuda proprietà per i giorni dopo l\'atto, anche sulle rate emesse', function (string $soggetto, int $atteso) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    ruEmetti($s);
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));

    expect(odaCoefficienti($s))->toBe([$soggetto => 100.0])
        ->and($subentro->ordinariaComeLaVoce())->toBeTrue()
        ->and($subentro->registro['voci_spostate'])->toBe([])
        ->and(odaDebitoDi($s, $s['a']))->toBe($atteso)
        ->and(odaDebitoDi($s, $s['v']))->toBe(-$atteso);
})->with([
    // € 1.200,00 × 245 / 365 = € 805,48: le quattro rate emesse e le otto bozze di un piano che ha già emesso (decisione 21).
    'voce sul «Proprietario»: passa al nudo proprietario' => ['proprietario', 80548],
    // Sull'«Usufruttuario» la voce resta a chi vende, che è usufruttuario: nessuna coppia.
    'voce sull\'«Usufruttuario»: resta all\'usufruttuario' => ['usufruttuario', 0],
]);

it('31.5 — «come la voce» nella riserva: la bozza ordinaria non passa intera a chi compra, e il pannello dice perché', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $frasi = implode("\n", ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['rate']['frasi']);

    // Non «hai scelto»: la scelta può venire da un passaggio prima (Fase 1-bis della beta.41).
    expect($frasi)->toContain('Comprese le 8 quote del piano «Preventivo 2026» non ancora emesse: l\'ordinaria segue la voce, per la scelta «come dice ogni voce», e con questa scelta le rate in bozza non cambiano intestatario, quindi resteranno intestate a Venditore Ugo e la parte delle voci che passano si conguaglia qui.');
});

it('31.5 — «come la voce» nella costituzione: la voce sul «Proprietario» resta al nudo proprietario; con la legge passa all\'usufruttuario per i giorni, come prima', function (?string $scelta, string $soggetto, int $atteso) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    ruEmetti($s);
    ruRegistra($this, $s, odaCostituzione($s, $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));

    expect(odaDebitoDi($s, $s['a']))->toBe($atteso);
})->with([
    'la legge, voce sul «Proprietario»' => [null, 'proprietario', 80548],
    'come la voce, voce sul «Proprietario»' => ['voce', 'proprietario', 0],
    'come la voce, voce sull\'«Usufruttuario»' => ['voce', 'usufruttuario', 80548],
]);

it('31.5 — «come la voce» su un piano senza dettaglio del riparto (anteriore alla beta.29): le voci si leggono dalla ricostruzione del motore', function (string $soggetto, int $atteso) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    ruEmetti($s);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));

    expect(odaDebitoDi($s, $s['a']))->toBe($atteso);
})->with([
    'voce sul «Proprietario»' => ['proprietario', 80548],
    'voce sull\'«Usufruttuario»' => ['usufruttuario', 0],
]);

it('31.5 — la catena: dopo una riserva «come la voce», la rivendita della nuda proprietà fa passare la voce sul «Proprietario» dal suo giorno; con la legge l\'ordinaria è rimasta a chi vendeva e non passa', function (?string $scelta, int $atteso) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));
    [$carlo, $rivendita] = ruRivendita($s);
    ruRegistra($this, $s, $rivendita);

    // € 1.200,00 × 122 / 365 (dal 1/09 al 31/12) = € 401,10.
    expect(odaDebitoDi($s, $carlo))->toBe($atteso);
})->with([
    'come la voce' => ['voce', 40110],
    'la legge' => [null, 0],
]);

it('31.5 — la richiesta rifiuta una scelta che non esiste', function () {
    $s = ruScenario('prima_rata', 0);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'a_caso']))
        ->assertSessionHasErrors('ordinaria_dopo_atto');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

it('rilievo A3 — senza la scelta sull\'ordinaria, anteprima e registrazione rifiutano la costituzione e la riserva: nessun passaggio, voci com\'erano', function (string $passaggio) {
    $s = ruScenario('prima_rata', 0);
    $dati = $passaggio === 'riserva' ? ruRiserva($s) : odaCostituzione($s);
    unset($dati['ordinaria_dopo_atto']);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)
        ->assertUnprocessable()->assertJsonValidationErrors(['ordinaria_dopo_atto' => 'Manca la scelta su chi paga l\'ordinaria dal giorno dell\'atto: ricarica la pagina e scegli.']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)
        ->assertSessionHasErrors('ordinaria_dopo_atto');

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(odaCoefficienti($s))->toBe(['proprietario' => 100.0]);
})->with(['riserva', 'costituzione']);

it('rilievo A3 — gli altri passaggi non hanno la scelta e non la chiedono: vendita piena ed estinzione si registrano senza', function () {
    $s = ruScenario('prima_rata', 0);
    $vendita = ruRiserva($s, extra: ['sottotipo' => null, 'tipologia' => 'proprietario']);
    unset($vendita['ordinaria_dopo_atto']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $vendita)->assertOk();

    ruRegistra($this, $s, ruRiserva($s));
    expect(ruEstinzione($this, $s)->tipo_passaggio)->toBe('usufrutto');
});

// --- L'annullamento -----------------------------------------------------------------------------------------------------

it('31.7 — annullando il passaggio la voce spostata resta sull\'«Usufruttuario», e l\'annullamento lo dice prima e lo lascia così dopo', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($subentro))
        ->toContain('La voce che questo passaggio ha spostato dal «Proprietario» all\'«Usufruttuario» resta com\'è: Spese generali (Proprietà, Ordinaria 2026; prima Proprietario 100 %). Vale per tutta la tabella, anche per le altre unità in usufrutto; se va riportata com\'era, si cambia dalla pagina della voce.');

    expect(ruAnnulla($this, $s, $subentro)->status())->toBe(302);
    expect(odaCoefficienti($s))->toBe(['usufruttuario' => 100.0]);
});

it('31.7 — con «come la voce» non c\'è niente da dire all\'annullamento sulle voci', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));

    expect(app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($subentro))->toBe([]);
});

// --- Le voci che si possono spostare, e le altre unità ------------------------------------------------------------------

it('31.6 — si elencano solo le voci delle gestioni ordinarie che arrivano al giorno dell\'atto, con un esercizio aperto, su una tabella in cui l\'unità ha millesimi', function () {
    $s = ruScenario('prima_rata', 0);
    // Il consuntivo dell'anno prima, con l'esercizio 2025 ancora aperto: la gestione finisce prima dell'atto.
    ruConsuntivo2025($s);
    // Una gestione ordinaria che arriva oltre l'atto, ma su un esercizio già chiuso.
    $chiuso = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025/26', 'data_inizio' => '2025-07-01', 'data_fine' => '2026-06-30', 'stato' => 'chiuso']);
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2025/26', 'tipo' => 'ordinaria', 'data_inizio' => '2025-07-01', 'data_fine' => '2026-06-30']);
    legaAEsercizio($chiuso, $g->id);
    odaVoce($g, 'Spese generali 2025/26', DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id'));
    // Una tabella in cui l'unità non ha millesimi: i box, di un'altra unità.
    $box = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Box', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $altra = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'box', 'codice_immobile' => 'ODA-B', 'nome' => 'Box 1', 'interno' => 'B1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $box->id, 'immobile_id' => $altra->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->insert(['tabella_id' => $box->id, 'immobile_id' => $s['unita']->id, 'valore' => 0, 'created_at' => now(), 'updated_at' => now()]);
    odaVoce($s['g'], 'Pulizia box', $box->id);

    $voci = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['voci'];

    expect(collect($voci)->pluck('conto')->all())->toBe(['Spese generali']);
});

it('31.5 — le altre unità: solo quelle con un usufruttuario, e l\'importo è la sola parte della voce che oggi va al nudo proprietario', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $tabella = (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    DB::table('quote_tabella')->where('tabella_id', $tabella)->update(['valore' => 500]);
    // La voce per il 70 % sul «Proprietario» e per il 30 % sull'«Usufruttuario».
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 70]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'usufruttuario', 'percentuale' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $altre = [];
    foreach (['Interno 2' => ['usufruttuario' => 'Usufruttuaria Nina', 'nuda_proprietario' => 'Nudo Oreste'], 'Interno 3' => ['nuda_proprietario' => 'Nuda Pia']] as $nome => $titolari) {
        $u = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'ODA-' . $nome, 'nome' => $nome, 'interno' => substr($nome, -1)]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella, 'immobile_id' => $u->id, 'valore' => 250, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($titolari as $ruolo => $persona) {
            $p = Anagrafica::forceCreate(['nome' => $persona, 'email' => "oda-{$ruolo}{$u->id}@test.it", 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => strtoupper(substr($ruolo, 0, 6)) . 'ODA' . str_pad((string) $u->id, 7, '0', STR_PAD_LEFT)]);
            $p->condomini()->syncWithoutDetaching([$s['c']->id]);
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $u->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
        }
        $altre[$nome] = $u;
    }
    DB::table('quote_tabella')->where('tabella_id', $tabella)->where('immobile_id', $s['unita']->id)->update(['valore' => 500]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);

    $voce = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['voci'][0];

    // Interno 2 ha un quarto della tabella, € 300,00: il 70 %, € 210,00, va oggi a Oreste; il 30 % è già di Nina. Interno 3
    // ha solo una nuda proprietaria, e la voce non le cambia niente.
    expect($voce['percentuale'])->toEqual(70.0)
        ->and(collect($voce['altre_unita'])->map(fn ($u) => [$u['immobile'], $u['importo']])->all())->toBe([['Interno 2', 21000]]);
});

// --- «Come la voce»: l'addebito diretto, la straordinaria, le catene -----------------------------------------------------

it('31.5 — «come la voce» nella riserva: l\'addebito diretto all\'unità sta dalla parte del «Proprietario» e passa a chi compra la nuda proprietà; la voce sull\'«Usufruttuario» resta a chi vende', function (?string $scelta, int $atteso) {
    $s = odaPianoDaFatture();
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));

    // € 365,00 di addebito diretto sul 2026: 245 giorni dopo l'atto, € 245,00.
    expect(odaDebitoDi($s, $s['a']))->toBe($atteso);
})->with([
    'come la voce' => ['voce', 24500],
    'la legge' => [null, 0],
]);

it('31.5 — la scelta riguarda l\'ordinaria: la straordinaria della riserva si conguaglia uguale, anche su un piano senza dettaglio del riparto e con la voce sull\'«Usufruttuario»', function (?string $scelta) {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, soggetto: 'usufruttuario');
    ruEmetti($s, '2026-12-31');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    ruRegistra($this, $s, ruRiserva($s, extra: $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));

    // Deliberata il 20/05, dopo l'atto: è tutta di chi compra la nuda proprietà (art. 1005 c.c.).
    expect(odaDebitoDi($s, $s['a']))->toBe(120000);
})->with(['la legge' => [null], 'come la voce' => ['voce']]);

it('31.5 — le quote di chi aveva venduto prima: nella riserva «come la voce» passano anche loro solo per le voci sul «Proprietario», e con la rivendita della nuda proprietà lo stesso', function (string $soggetto, int $allaRiserva, int $allaRivendita) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "oda-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODAZETAVEND' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Ugo vende a Zeta il 1/03, con le rate fino al 30/04 già emesse a Ugo.
    // Dalla .42 (decisione 35) il piano si emette prima della vendita: emesso dopo senza ricalcolo era la forma di U1.
    ruEmetti($s);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $zeta, '2026-03-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Prima vendita, letta']);
    $rigaZeta = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');
    $riserva = ruRegistra($this, $s, ruRiserva($s, extra: ['riga_uscente_id' => $rigaZeta, 'ordinaria_dopo_atto' => 'voce']));
    expect(odaDebitoDi($s, $s['a']))->toBe($allaRiserva);

    [$carlo, $rivendita] = ruRivendita($s);
    ruRegistra($this, $s, $rivendita);
    expect(odaDebitoDi($s, $carlo))->toBe($allaRivendita);
})->with([
    // € 1.200,00 × 245 / 365 = € 805,48 alla riserva; × 122 / 365 = € 401,10 alla rivendita del 1/09.
    'voce sul «Proprietario»: passa' => ['proprietario', 80548, 40110],
    'voce sull\'«Usufruttuario»: resta a Zeta, usufruttuaria' => ['usufruttuario', 0, 0],
]);

it('31.5 — le quote di chi aveva venduto prima, con la voce che non passa: la parte di Zeta resta sua dal giorno in cui l\'ha comprata, nessuna a chi entra', function (bool $senzaRighe, int $atteso) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "oda-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODAZETAVEND' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Dalla .42 (decisione 35) il piano si emette prima della vendita: emesso dopo senza ricalcolo era la forma di U1.
    ruEmetti($s);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $zeta, '2026-03-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Prima vendita, letta']);
    $rigaZeta = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');

    $quote = collect(ruAnteprima($this, $s, ruRiserva($s, extra: ['riga_uscente_id' => $rigaZeta, 'ordinaria_dopo_atto' => 'voce']))['rate']['conguaglio']['quote']);

    // Dal 1/03 al 31/12, 306 giorni su 365 di € 1.200,00: € 1.006,0274. Dalla .42 il piano è emesso prima della vendita a Zeta, che
    // lo prende nel conguaglio: le quote sono due gruppi, le quattro emesse a Ugo e le otto bozze passate a Zeta. Il conto si
    // arrotonda una volta sull'intera gestione, con e senza dettaglio del riparto: € 1.006,03 (DV1, decisione 59, 1.11.0-beta.43).
    // Fino alla .42, senza dettaglio, la ricostruzione arrotondava ogni gruppo per sé (€ 400,00 × 306/365 = € 335,34 e € 800,00
    // × 306/365 = € 670,68): € 1.006,02, un centesimo sotto.
    expect($quote->sum('entrante'))->toBe(0)->and($quote->sum('uscente'))->toBe($atteso);
})->with(['con il dettaglio del riparto' => [false, 100603], 'senza, dalla ricostruzione del motore' => [true, 100603]]);

it('31.5 — la catena, con la voce che a chi esce non era mai arrivata: dopo una riserva «come la voce», nella rivendita della nuda proprietà la voce sull\'«Usufruttuario» non è né di chi vende né di chi compra', function (bool $senzaRighe) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    [, $rivendita] = ruRivendita($s);

    $quote = collect(ruAnteprima($this, $s, $rivendita)['rate']['conguaglio']['quote']);

    // È rimasta a Ugo, usufruttuario: a Elsa non è mai passata, e la sua rivendita non la tocca.
    expect($quote->sum('entrante'))->toBe(0)->and($quote->sum('uscente'))->toBe(0);
})->with(['con il dettaglio del riparto' => [false], 'senza, dalla ricostruzione del motore' => [true]]);

it('31.5 — con la legge la voce spostata non scende più al nudo proprietario: il cancello non avvisa del ricalcolo; con la voce tenuta sul «Proprietario» sì', function (bool $tenuta) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $motivi = implode(' | ', ruAnteprima($this, $s, ruRiserva($s, extra: $tenuta ? ['voci_da_tenere' => [odaAssociazione($s)]] : []))['cancello']['motivi']);

    $tenuta
        ? expect($motivi)->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» (Spese generali) vanno a Acquirente Elsa')
        : expect($motivi)->not->toContain('se lo ricalcoli');
})->with(['spostata' => [false], 'tenuta' => [true]]);

/** Una voce ordinaria sul «Proprietario» in una gestione, sulla tabella data. */
function odaVoce(Gestione $g, string $nome, int $tabellaId): void
{
    $pc = PianoConto::firstOrCreate(['condominio_id' => $g->condominio_id, 'gestione_id' => $g->id], ['nome' => 'PC ' . $g->nome]);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 10000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Lo scenario di `ruScenario` con un piano da fatture sulla gestione ordinaria: € 365,00 sulla voce «Spese generali», che
 * qui sta sull'«Usufruttuario», e € 365,00 addebitati direttamente all'unità; tutte e due con la competenza del 2026.
 */
function odaPianoDaFatture(): array
{
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $conto = Conto::whereHas('pianoConto', fn ($q) => $q->where('gestione_id', $s['g']->id))->firstOrFail();
    $conto->update(['importo' => 0]);
    $s['piano']->update(['tipo' => 'straordinario', 'nome' => 'Spese da finanziare']);
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['voce' => $conto->id, 'addebito' => null] as $chi => $contoId) {
        $fattura = FatturaPassiva::create([
            'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-ODA{$s['unita']->id}-{$chi}",
            'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 36500, 'importo_iva' => 0, 'importo_ritenuta' => 0,
            'totale_documento' => 36500, 'netto_a_pagare' => 36500, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
            'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31',
        ]);
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fattura->id, 'conto_id' => $contoId, 'immobile_id' => $contoId === null ? $s['unita']->id : null,
            'descrizione' => $contoId === null ? 'Riparazione citofono interno 1' : 'Pulizie', 'aliquota_iva' => 0,
            'importo_imponibile' => 36500, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $s['piano']->fatture()->attach($fattura->id, ['importo_collegato' => 36500]);
    }
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Piano di prova da fatture', esercizio: $s['e']);

    return $s;
}

it('31.5 — dopo una costituzione «come la voce», le voci sul «Proprietario» sono rimaste al nudo proprietario: se vende la nuda proprietà, passano a chi compra; con la legge l\'ordinaria era già dell\'usufruttuaria e non passa', function (?string $scelta, string $soggetto, int $allaCostituzione, int $allaVendita) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    ruEmetti($s);
    ruRegistra($this, $s, odaCostituzione($s, $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));
    expect(odaDebitoDi($s, $s['a']))->toBe($allaCostituzione);

    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => "oda-c{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODACOMPRATO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('nuda', $rigaNuda, $carlo, '2026-09-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Vendita della nuda proprietà, letta']);

    // € 1.200,00 × 122 / 365 dal 1/09 = € 401,10.
    expect(odaDebitoDi($s, $carlo))->toBe($allaVendita);
})->with([
    'come la voce, voce sul «Proprietario»' => ['voce', 'proprietario', 0, 40110],
    'come la voce, voce sull\'«Usufruttuario»' => ['voce', 'usufruttuario', 80548, 0],
    'la legge' => [null, 'proprietario', 80548, 0],
]);

// --- Decisione 31.8: le voci bloccate da un piano approvato ---------------------------------------------------------------

it('31.8 — una voce compresa in un piano approvato ha la ripartizione bloccata: si elenca, non si sposta, e il pannello dice perché e il rimedio vero per il piano che la blocca; il conguaglio con la legge vale lo stesso', function (string $come, string $frase) {
    $s = ruScenario('prima_rata', 0);
    odaVoceInUnPiano($s, $come, 'approvato');
    ruEmetti($s);

    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];
    expect($ordinaria['voci'][0]['bloccata'])->toBeTrue()
        ->and($ordinaria['voci'][0]['spostata'])->toBeFalse()
        ->and(implode("\n", $ordinaria['frasi']))
        ->toContain($frase)
        ->not->toContain('come dalla pagina della voce')->not->toContain('questa voce passa')->not->toContain('Resta sul «Proprietario» la voce')
        ->and($ordinaria['frasi_bloccate'])->toBe([$frase]);

    $subentro = ruRegistra($this, $s, ruRiserva($s));
    expect(odaCoefficienti($s))->toBe(['proprietario' => 100.0])
        ->and($subentro->registro['ordinaria_dopo_atto'])->toBe('usufruttuario')
        ->and($subentro->registro['voci_spostate'])->toBe([])
        ->and(odaDebitoDi($s, $s['a']))->toBe(0);
})->with([
    // Il piano ha rate a giornale: spostare la voce non serve. Nella riserva l'ordinaria resta a chi vende e non si conguaglia
    // (seconda revisione della Fase 1-ter, T2-3: «il conguaglio dà» era vero solo per la costituzione).
    'nel piano dalla generazione' => ['capitoli', 'La voce Spese generali è nel piano «Preventivo 2026», che non si ricalcola più: non si sposta, e per quel piano non serve: l\'ordinaria resta a Venditore Ugo, che resta usufruttuario, anche sulle quote ancora in bozza, e non si conguaglia. Resta sul «Proprietario» per i piani che verranno in questa gestione.'],
    // Il preventivo dello scenario è globale (31.9) e a giornale: la voce la bloccano due piani di casi diversi, e la frase li
    // nomina tutti e due senza promettere un rimedio (T2-1, T2-2, M2-5). Il caso (c) da solo è nella prova del rilievo T-B2.
    'nelle fatture di un piano straordinario' => ['fatture', 'La voce Spese generali è bloccata da più piani — «Preventivo 2026», che non si ricalcola più: lì l\'ordinaria resta a Venditore Ugo, che resta usufruttuario, anche sulle quote ancora in bozza, e non si conguaglia; «Spese da finanziare», straordinario, nelle sue fatture. Non si sposta.'],
]);

it('31.8 — il blocco vale solo per i piani approvati: con il piano in bozza la voce si sposta', function (string $come) {
    $s = ruScenario('prima_rata', 0);
    $s['piano']->update(['stato' => 'bozza']);
    odaVoceInUnPiano($s, $come, 'bozza');

    expect(ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['voci'][0])->toMatchArray(['bloccata' => false, 'spostata' => true]);
})->with(['nel piano dalla generazione' => ['capitoli'], 'nelle fatture di un piano straordinario' => ['fatture']]);

// --- Decisione 31.9: il piano «globale», senza capitoli --------------------------------------------------------------------

// Gli stati sono quelli di `Conto::getHasRateEmesseAttribute()`; il modello del piano oggi ne conosce due, bozza e approvato.
it('31.9 — un piano ordinario senza capitoli approvato comprende tutte le voci della gestione: le blocca, e il pannello lo dice senza rimandare alla pagina della voce, che non le blocca', function () {
    // Il piano dello scenario è senza capitoli e approvato.
    $s = ruScenario('prima_rata', 0);

    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];
    expect($ordinaria['voci'][0])->toMatchArray(['bloccata' => true, 'bloccata_da' => 'piano_globale', 'spostata' => false])
        ->and(implode("\n", $ordinaria['frasi']))
        // Il piano non ha niente a giornale: non c'è conguaglio, e il piano ricalcolato seguirebbe la voce rimasta sul «Proprietario».
        ->toContain('La voce Spese generali è nel piano «Preventivo 2026», approvato e ancora senza niente a giornale: non si sposta, non c\'è conguaglio, e generato o ricalcolato il piano darà dal 1 maggio 2026 l\'ordinaria di quella voce a Acquirente Elsa, nudo proprietario: la scelta non lo raggiunge. Per applicarla, prima di registrare il passaggio riporta il piano in bozza dalla sua pagina: con il passaggio la voce si sposterà; poi riapprova il piano e ricalcolalo.')
        ->not->toContain('come dalla pagina della voce')->not->toContain('questa voce passa');

    $subentro = ruRegistra($this, $s, ruRiserva($s));
    expect(odaCoefficienti($s))->toBe(['proprietario' => 100.0])
        ->and($subentro->registro['voci_spostate'])->toBe([]);
});

it('31.9, controprove — non blocca un piano straordinario senza capitoli (le sue voci sono quelle delle fatture, 31.8), né un piano senza capitoli in bozza', function (string $caso) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    if ($caso === 'straordinario') {
        PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Spese da finanziare', 'stato' => 'approvato',
            'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-07-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    }

    expect(ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['voci'][0])->toMatchArray(['bloccata' => false, 'bloccata_da' => null, 'spostata' => true]);
})->with(['straordinario', 'in bozza']);

/**
 * La voce «Spese generali» dello scenario dentro un piano: quello dello scenario, come lo lega la generazione dalla pagina
 * dei piani (`SyncOrphanChaptersAction`), o un piano straordinario da fatture sulla stessa gestione, con una fattura sulla
 * voce. `$stato` è lo stato del piano da fatture.
 */
function odaVoceInUnPiano(array $s, string $come, string $stato): void
{
    $conto = Conto::whereHas('pianoConto', fn ($q) => $q->where('gestione_id', $s['g']->id))->firstOrFail();
    if ($come === 'capitoli') {
        $s['piano']->capitoli()->syncWithoutDetaching([$conto->id => ['importo' => 120000, 'note' => 'Snapshot']]);

        return;
    }
    $piano = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Spese da finanziare', 'stato' => $stato,
        'tipo' => 'straordinario', 'numero_rate' => 1, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-07-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $fattura = FatturaPassiva::create(['condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-ODB{$s['unita']->id}",
        'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 10000, 'netto_a_pagare' => 10000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Pulizie', 'aliquota_iva' => 0,
        'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 10000]);
}

it('31.5 — le frasi delle rate già emesse dicono la scelta: con «come la voce» la riserva e la costituzione non promettono più l\'ordinaria all\'usufruttuario', function (string $passaggio, ?string $scelta, string $frase) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $extra = $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta];
    $dati = $passaggio === 'riserva' ? ruRiserva($s, extra: $extra) : odaCostituzione($s, $extra);

    expect(implode("\n", ruAnteprima($this, $s, $dati)['rate']['frasi']))->toContain($frase);
})->with([
    'riserva, la legge' => ['riserva', null, 'Venditore Ugo resta usufruttuario e continua a dovere la quota ordinaria (art. 1004 c.c.): l\'ordinaria non si conguaglia.'],
    'riserva, come la voce' => ['riserva', 'voce', 'Venditore Ugo resta usufruttuario; l\'ordinaria segue la voce, come hai scelto: le voci sul «Proprietario» passano dal giorno dell\'atto a chi compra la nuda proprietà, le altre restano sue.'],
    'costituzione, la legge' => ['costituzione', null, 'la quota ordinaria divisa in proporzione ai giorni (dal 1 maggio 2026 all\'usufruttuario, art. 1004 c.c.)'],
    'costituzione, come la voce' => ['costituzione', 'voce', 'l\'ordinaria segue la voce, come hai scelto — le voci che non sono sul «Proprietario» divise in proporzione ai giorni (dal 1 maggio 2026 all\'usufruttuario), quelle sul «Proprietario» restano al nudo proprietario —'],
]);

it('31.6 — una voce con coefficienti che non fanno 100 non si propone e non si sposta: va corretta prima nella pagina della voce', function () {
    $s = ruScenario('prima_rata', 0);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', odaAssociazione($s))->update(['percentuale' => 60]);

    expect(ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['voci'])->toBe([]);
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    expect(odaCoefficienti($s))->toBe(['proprietario' => 60.0])->and($subentro->registro['voci_spostate'])->toBe([]);
});

// --- Verifica a video (02/10/2026) -------------------------------------------------------------------------------------------

it('verifica a video — la frase conclusiva promette i piani che verranno solo per le voci che passano davvero; i nomi ripetuti si dicono una volta, con quante sono', function (array $tenere, string $finale) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $tabella = (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    // Due voci con lo stesso nome, come gli imprevisti di uno stesso fornitore.
    odaVoce($s['g'], 'Pulizie', $tabella);
    odaVoce($s['g'], 'Pulizie', $tabella);
    $pulizie = DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
        ->where('conti.nome', 'Pulizie')->pluck('conto_tabella_millesimale.id')->all();
    $ids = ['pulizie' => $pulizie, 'tutte' => [...$pulizie, odaAssociazione($s)], 'nessuna' => []];

    $frasi = ruAnteprima($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => array_merge(...array_map(fn ($k) => $ids[$k], $tenere))]))['ordinaria']['frasi'];

    expect($frasi[0])->toBe('Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario (art. 1004 c.c.)' . $finale . ' Le voci create dopo, e quelle delle gestioni che si apriranno, partono dal «Proprietario»: per darle all\'usufruttuario mettile su «Usufruttuario».');
    if ($tenere === ['pulizie']) {
        expect(implode("\n", $frasi))->toContain('Restano sul «Proprietario» le voci Pulizie (2 voci): nei piani che verranno andranno a Acquirente Elsa, nudo proprietario.');
    }
})->with([
    // Il piano è in bozza e non c'è niente di emesso: niente conguaglio da promettere (rilievo T-A1 della revisione della 1-ter).
    'tutte spostate' => [['nessuna'], ': per le voci di oggi, nei piani generati o ricalcolati dopo.'],
    'alcune spostate' => [['pulizie'], ': per le voci che passano all\'«Usufruttuario», nei piani generati o ricalcolati dopo.'],
    // Rilievo T2-7 della seconda revisione: una frase a sé, non un «ma» dopo i due punti.
    'nessuna spostata' => [['tutte'], '. Su questa unità però non ci sono rate emesse da conguagliare e nessuna voce si sposta: in questo passaggio la scelta non cambia niente.'],
]);

// --- Fase 1-bis della beta.41: le catene con «come dice ogni voce» (rilievi D4, D5, D6, D7) --------------------------------

/** Quanto deve, per unità, chi entra nelle coppie di conguaglio: [immobile_id => centesimi]. */
function odaDebitiPerUnita(Anagrafica $persona): array
{
    return Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $persona->id)->get()->groupBy('immobile_id')
        ->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->sortKeys()->all();
}

it('rilievo D4 — riserva «come dice ogni voce» e poi estinzione: la voce sul «Proprietario» è già passata con la riserva, e l\'estinzione non la fa passare una seconda volta', function (string $soggetto, int $allaRiserva, int $allEstinzione) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    expect(odaDebitoDi($s, $s['a']))->toBe($allaRiserva);

    $estinzione = ruEstinzione($this, $s);
    // Totale di Elsa dopo i due passaggi. Il riferimento è il motore: un piano generato dopo dà a Elsa 245/365 della voce sul
    // «Proprietario» (dal 1/05) e 122/365 di quella sull'«Usufruttuario» (dal 1/09). Prima: € 1.206,58 su € 1.200,00.
    expect(odaDebitoDi($s, $s['a']))->toBe($allaRiserva + $allEstinzione)
        // La scelta della riserva passa al registro dell'estinzione: i passaggi dopo la leggono da lì.
        ->and($estinzione->registro['ordinaria_dopo_atto'] ?? null)->toBe('voce');
})->with([
    'voce sul «Proprietario»' => ['proprietario', 80548, 0],
    'voce sull\'«Usufruttuario»' => ['usufruttuario', 0, 40110],
]);

it('rilievo D4 — la catena riserva «come dice ogni voce», rivendita della nuda proprietà, estinzione: chi ha comprato la nuda proprietà riceve la voce sul «Proprietario» una volta sola', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    [$carlo, $rivendita] = ruRivendita($s);
    ruRegistra($this, $s, $rivendita);
    ruEstinzione($this, $s, '2026-10-01');

    // Dal 1/09 la voce sul «Proprietario» è di Carlo (122/365): l'estinzione del 1/10 non gliela dà di nuovo. Prima: € 601,65.
    expect(odaDebitoDi($s, $carlo))->toBe(40110);
});

it('rilievo D4 — con le quote di chi aveva venduto prima: Ugo vende a Zeta, Zeta riserva «come dice ogni voce» a Elsa, poi l\'usufrutto di Zeta si estingue', function (string $soggetto, int $atteso) {
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto);
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "oda-zd{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODAZETAD4VE' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Dalla .42 (decisione 35) il piano si emette prima della vendita: emesso dopo senza ricalcolo era la forma di U1.
    ruEmetti($s);
    ruRegistra($this, $s, ruPassaggio('vendita', $s['rigaV'], $zeta, '2026-03-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Prima vendita, letta']);
    $rigaZeta = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');
    ruRegistra($this, $s, ruRiserva($s, extra: ['riga_uscente_id' => $rigaZeta, 'ordinaria_dopo_atto' => 'voce']));
    $usufruttoZeta = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $usufruttoZeta, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione, letta']);

    // Elsa: la voce sul «Proprietario» dal 1/05 (245/365) con la riserva; quella sull'«Usufruttuario» dal 1/09 (122/365).
    expect(odaDebitoDi($s, $s['a']))->toBe($atteso);
})->with([
    'voce sul «Proprietario»' => ['proprietario', 80548],
    'voce sull\'«Usufruttuario»' => ['usufruttuario', 40110],
]);

it('rilievo D5 — riserva «come dice ogni voce», estinzione, poi vendita piena: novembre e dicembre passano a chi compra', function (bool $senzaRighe) {
    // La voce sull'«Usufruttuario» resta a Ugo con la riserva, passa a Elsa con l'estinzione del 1/09 (€ 401,10); Elsa vende
    // la piena proprietà a Carlo il 1/11: 61 giorni, 120000 × 61/365 = 20054,79 → € 200,55. Prima: nessuna coppia, perché
    // Ugo era già «visto» attraverso la riserva e l'anello dell'estinzione si saltava.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    ruEstinzione($this, $s);
    expect(odaDebitoDi($s, $s['a']))->toBe(40110);
    [$carlo, $vendita] = ruRivendita($s, '2026-11-01', 'proprietario');
    // La voce è arrivata a Elsa il 1/09, non il 1/05 della riserva: 122 giorni, 61 a Elsa (prima: «dal 1 maggio», e senza
    // dettaglio anche 245 giorni, 184 a Elsa — il denaro era giusto, il testo no).
    expect(implode("\n", ruAnteprima($this, $s, $vendita)['rate']['frasi']))
        ->toContain('è emessa a Venditore Ugo: la sua competenza è passata a Acquirente Elsa dal 1 settembre 2026 con un passaggio precedente, e quella parte (122 giorni) è divisa in proporzione ai giorni: 61 a Acquirente Elsa, 61 a Compratore Carlo.');
    ruRegistra($this, $s, $vendita);

    expect(odaDebitoDi($s, $carlo))->toBe(20055);
})->with(['con il dettaglio del riparto' => [false], 'senza, dalla ricostruzione del motore' => [true]]);

it('rilievo D5 — la stessa catena con una voce metà sul «Proprietario» e metà sull\'«Usufruttuario»: le due parti sono arrivate a Elsa in due giorni, e la frase dice le due date', function (bool $senzaRighe, int $atteso, array $frasi) {
    $s = ruScenario('prima_rata', 0, genera: false);
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 50]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'usufruttuario', 'percentuale' => 50, 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    ruEstinzione($this, $s);
    [$carlo, $vendita] = ruRivendita($s, '2026-11-01', 'proprietario');
    $testo = implode("\n", ruAnteprima($this, $s, $vendita)['rate']['frasi']);
    foreach ($frasi as $frase) {
        expect($testo)->toContain($frase);
    }
    ruRegistra($this, $s, $vendita);

    expect(odaDebitoDi($s, $carlo))->toBe($atteso);
})->with([
    // Con il dettaglio le due righe della voce si dividono ciascuna (60000 × 61/365 = 10027,40 → € 100,27, due volte): un
    // centesimo meno della voce intera (120000 × 61/365 = 20054,79), come per la voce divisa di D6 — riga per riga (29.1).
    'con il dettaglio del riparto' => [false, 20054, [
        'la sua competenza è passata a Acquirente Elsa dal 1 maggio 2026 per le voci sul «Proprietario» e dal 1 settembre 2026 per le altre, con passaggi precedenti, e quella parte è divisa voce per voce',
        '  · Spese generali (parte sul «Proprietario»): € 600,00, competenza 1 gennaio 2026–31 dicembre 2026 — emessa a Venditore Ugo: 120 giorni a Venditore Ugo, 184 a Acquirente Elsa, 61 a Compratore Carlo → € 100,27 a chi entra.',
        '  · Spese generali (parte sugli altri ruoli): € 600,00, competenza 1 gennaio 2026–31 dicembre 2026 — emessa a Venditore Ugo: 243 giorni a Venditore Ugo, 61 a Acquirente Elsa, 61 a Compratore Carlo → € 100,27 a chi entra.',
    ]],
    'senza, dalla ricostruzione del motore' => [true, 20055, [
        'la sua competenza è passata a Acquirente Elsa dal 1 maggio 2026 per le voci sul «Proprietario» e dal 1 settembre 2026 per le altre, con passaggi precedenti; a Compratore Carlo va la parte dal giorno del passaggio (61 giorni).',
    ]],
]);

/** Lo scenario con la voce divisa fra «Proprietario» (10 %) e «Inquilino» (90 %), senza inquilino, e le prime quattro rate emesse. */
function odaVoceDivisa(): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 10]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 90, 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);

    return $s;
}

it('rilievo D6 — una voce divisa fra «Proprietario» (10 %) e «Inquilino» (90 %), senza inquilino: con «come dice ogni voce» ciascuna parte passa o resta per conto suo', function (string $passaggio, int $atteso) {
    $s = odaVoceDivisa();
    ruRegistra($this, $s, $passaggio === 'riserva' ? ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']) : odaCostituzione($s, ['ordinaria_dopo_atto' => 'voce']));

    // Riserva: passa al nudo proprietario la sola parte sul «Proprietario», 12000 × 245/365 = € 80,55 (prima: € 805,48).
    // Costituzione: passa all'usufruttuaria la sola parte sull'«Inquilino», 108000 × 245/365 = € 724,93 (prima: niente).
    expect(odaDebitoDi($s, $s['a']))->toBe($atteso);
})->with([
    'riserva' => ['riserva', 8055],
    'costituzione' => ['costituzione', 72493],
]);

it('rilievo D7 — la pertinenza porta la scelta del passaggio: dopo una costituzione «come dice ogni voce» con il box, la vendita della nuda proprietà di unità e box fa passare la voce sul «Proprietario» su tutte e due', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('quote_tabella')->insert(['tabella_id' => (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id'), 'immobile_id' => $box->id, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);
    ruRegistra($this, $s, odaCostituzione($s, ['ordinaria_dopo_atto' => 'voce', 'pertinenze' => [$box->id]]));
    expect(Subentro::where('immobile_id', $box->id)->sole()->registro['ordinaria_dopo_atto'] ?? null)->toBe('voce');

    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => "oda-cb{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODACARLOBOX' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('nuda', $rigaNuda, $carlo, '2026-09-01', 100, [$box->id]) + ['ho_letto' => true, 'nota_cancello' => 'Vendita della nuda proprietà con il box, letta']);

    // € 600,00 per unità, 122/365 dal 1/09 = € 200,55 sull'unità e € 200,55 sul box. Prima: niente sul box.
    expect(odaDebitiPerUnita($carlo))->toBe([$s['unita']->id => 20055, $box->id => 20055]);
});

// --- Le frasi del conguaglio con «come dice ogni voce» (Fase 1-bis della beta.41) ------------------------------------------

it('frasi — la voce che resta per la scelta lo dice: non «la competenza finisce prima del», che è falso quando la competenza è tutto l\'anno', function (bool $senzaRighe) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s);
    $frasi = implode("\n", ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['rate']['frasi']);

    expect($frasi)->toContain('Sulla gestione Ordinaria 2026: nessun conguaglio — l\'ordinaria segue la voce, per la scelta «come dice ogni voce»: le voci delle quote emesse a Venditore Ugo (€ 1.200,00) non passano a Acquirente Elsa.')
        ->and($frasi)->not->toContain('finisce prima del');
})->with(['con le righe del riparto' => false, 'senza righe (anteriore alla beta.29)' => true]);

it('frasi — con l\'atto dopo la fine della competenza la ragione resta la competenza, anche con «come dice ogni voce»', function (bool $senzaRighe) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    if ($senzaRighe) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    ruEmetti($s);
    $frasi = implode("\n", ruAnteprima($this, $s, ruRiserva($s, '2027-01-15', ['ordinaria_dopo_atto' => 'voce']))['rate']['frasi']);

    expect($frasi)->toContain('Sulla gestione Ordinaria 2026: nessun conguaglio — la competenza delle quote emesse a Venditore Ugo (€ 1.200,00) finisce prima del 15 gennaio 2027.')
        ->and($frasi)->not->toContain('per la scelta «come dice ogni voce»:');
})->with(['con le righe del riparto' => false, 'senza righe (anteriore alla beta.29)' => true]);

it('frasi — una voce divisa fra due ruoli: le due parti hanno un nome diverso, e la parte che resta dice perché', function () {
    $s = odaVoceDivisa();
    $frasi = implode("\n", ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['rate']['frasi']);

    expect($frasi)->toContain('  · Spese generali (parte sul «Proprietario»): € 120,00, competenza 1 gennaio 2026–31 dicembre 2026 — 120 giorni a Venditore Ugo, 245 a Acquirente Elsa → € 80,55 a chi entra.')
        ->and($frasi)->toContain('  · Spese generali (parte sugli altri ruoli): € 1.080,00, competenza 1 gennaio 2026–31 dicembre 2026 — resta a Venditore Ugo per la scelta «come dice ogni voce».');
});

it('frasi — senza righe, con una voce divisa, si dice che per giorni si divide solo la parte delle voci che passano', function () {
    $s = odaVoceDivisa();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    $frasi = implode("\n", ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['rate']['frasi']);

    expect($frasi)->toContain('credito € 80,55 a Venditore Ugo, debito € 80,55 a Acquirente Elsa — la quota ordinaria (€ 1.200,00 su 12 quote) è divisa in proporzione ai giorni di competenza: 120 a Venditore Ugo, 245 a Acquirente Elsa. Per la scelta «come dice ogni voce» si divide solo la parte delle voci che passano, € 120,00 su € 1.200,00; il resto non passa a Acquirente Elsa.');
});

it('frasi — l\'estinzione di un usufrutto nato «come dice ogni voce» dice la scelta del passaggio da cui è nato, non «l\'ordinaria divisa in proporzione ai giorni»', function (?string $scelta, string $frase) {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));
    $rigaUsufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $frasi = implode("\n", ruAnteprima($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsufrutto, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione, letta',
    ])['rate']['frasi']);

    expect($frasi)->toContain($frase);
})->with([
    'nato «come dice ogni voce»' => ['voce', 'l\'ordinaria segue la voce, per la scelta «come dice ogni voce» del passaggio del 1 maggio 2026 — le voci sul «Proprietario» sono già del nudo proprietario, le altre divise in proporzione ai giorni —'],
    'nato con la legge' => [null, 'la quota ordinaria divisa in proporzione ai giorni, la quota straordinaria resta al nudo proprietario (art. 63 disp. att. c.c.; artt. 1004-1005 c.c.).'],
]);

// --- Rilievo S1: l'elenco cambiato fra l'anteprima e il clic ----------------------------------------------------------

it('rilievo S1 — una voce nuova fra l\'anteprima e il clic: con la legge la registrazione si ferma, nessuna voce si sposta', function () {
    $s = ruScenario('prima_rata', 0);
    $impronta = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['impronta'];
    $tabellaId = (int) DB::table('conto_tabella_millesimale')->where('id', odaAssociazione($s))->value('tabella_id');
    odaVoce($s['g'], 'Ascensore', $tabellaId);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['ordinaria_impronta' => $impronta]))
        ->assertSessionHasErrors(['ordinaria_impronta' => 'Le voci da spostare sono cambiate da quando il pannello le ha mostrate: il pannello è stato ricalcolato, ricontrolla l\'elenco e conferma.']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(odaCoefficienti($s))->toBe(['proprietario' => 100.0])
        ->and(DB::table('conto_tabella_ripartizioni')->where('soggetto', 'usufruttuario')->count())->toBe(0);
});

it('rilievo S1 — un piano riportato in bozza fra l\'anteprima e il clic sblocca la voce: la registrazione si ferma', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    odaVoceInUnPiano($s, 'fatture', 'approvato');
    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    expect($anteprima['ordinaria']['voci'][0]['bloccata'])->toBeTrue();
    PianoRate::where('nome', 'Spese da finanziare')->update(['stato' => 'bozza']);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['ordinaria_impronta' => $anteprima['ordinaria']['impronta']]))
        ->assertSessionHasErrors('ordinaria_impronta');
    expect(odaCoefficienti($s))->toBe(['proprietario' => 100.0]);
});

it('rilievo S1 — un coefficiente cambiato fra l\'anteprima e il clic cambia l\'impronta', function () {
    $s = ruScenario('prima_rata', 0);
    $prima = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['impronta'];
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 50]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 50, 'created_at' => now(), 'updated_at' => now()]);

    expect(ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['impronta'])->not->toBe($prima);
});

it('rilievo S1 — con l\'elenco uguale la registrazione passa; con «come dice ogni voce» le voci non si toccano e l\'impronta non ferma niente; senza impronta vale l\'elenco di adesso', function (string $caso) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $impronta = ruAnteprima($this, $s, ruRiserva($s))['ordinaria']['impronta'];
    if ($caso !== 'uguale') {
        odaVoce($s['g'], 'Ascensore', (int) DB::table('conto_tabella_millesimale')->where('id', odaAssociazione($s))->value('tabella_id'));
    }
    $extra = match ($caso) {
        'uguale' => ['ordinaria_impronta' => $impronta],
        'come la voce' => ['ordinaria_dopo_atto' => 'voce', 'ordinaria_impronta' => $impronta],
        'senza impronta' => [],
    };

    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: $extra));
    expect(count($subentro->registro['voci_spostate']))->toBe(match ($caso) { 'uguale' => 1, 'come la voce' => 0, 'senza impronta' => 2 });
})->with(['uguale', 'come la voce', 'senza impronta']);

// --- Rilievo A2: la scelta nello storico --------------------------------------------------------------------------------

/** La voce dello storico per il passaggio dato. */
function odaStorico(array $s, Subentro $subentro): array
{
    return collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
}

it('rilievo A2 — lo storico dice la scelta: con la legge le voci spostate con i coefficienti di prima e di dopo; «come dice ogni voce» con la forma del passaggio', function (string $passaggio, ?string $scelta, string $testo, array $voci) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $extra = $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta];
    $subentro = ruRegistra($this, $s, $passaggio === 'riserva' ? ruRiserva($s, extra: $extra) : odaCostituzione($s, $extra));

    expect(odaStorico($s, $subentro)['ordinaria'])->toBe(['scelta' => $scelta ?? 'usufruttuario', 'voci' => $voci, 'testo' => $testo]);
})->with([
    'riserva, la legge' => ['riserva', null, 'Dal 1 maggio 2026 all\'usufruttuario (art. 1004 c.c.), la proposta di legge: questa voce è passata dal «Proprietario» all\'«Usufruttuario».', ['Spese generali (Proprietà, Ordinaria 2026): prima Proprietario 100 %, dopo Usufruttuario 100 %']],
    'riserva, come la voce' => ['riserva', 'voce', 'Dal 1 maggio 2026 come dice ogni voce, scelta alla registrazione: le voci sul «Proprietario» passano a chi ha comprato la nuda proprietà, le altre restano all\'usufruttuario. Le voci non sono state toccate.', []],
    'costituzione, come la voce' => ['costituzione', 'voce', 'Dal 1 maggio 2026 come dice ogni voce, scelta alla registrazione: le voci sul «Proprietario» restano al nudo proprietario, le altre vanno all\'usufruttuario. Le voci non sono state toccate.', []],
]);

it('rilievo A2 — con la legge e la spunta tolta: «nessuna voce spostata»; la vendita piena non ne parla', function () {
    $s = ruScenario('prima_rata', 0);
    $riserva = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [odaAssociazione($s)]]));
    expect(odaStorico($s, $riserva)['ordinaria']['testo'])->toBe('Dal 1 maggio 2026 all\'usufruttuario (art. 1004 c.c.), la proposta di legge: nessuna voce spostata.');

    $t = ruScenario('prima_rata', 0);
    $vendita = ruRegistra($this, $t, ruRiserva($t, extra: ['sottotipo' => null, 'tipologia' => 'proprietario']));
    expect(odaStorico($t, $vendita)['ordinaria'])->toBeNull();
});

it('rilievo A2 — un passaggio registrato prima della beta.41, senza la chiave nel registro: nessuna riga, non «la legge»', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    $registro = $subentro->registro;
    unset($registro['ordinaria_dopo_atto'], $registro['voci_spostate']);
    DB::table('subentri')->where('id', $subentro->id)->update(['registro' => json_encode($registro)]);

    expect(odaStorico($s, $subentro)['ordinaria'])->toBeNull();
});

it('rilievo A2 — l\'estinzione di un usufrutto nato «come dice ogni voce» lo dice, con il passaggio da cui era nato; la riga della riserva resta dopo l\'estinzione', function () {
    $s = ruScenario('prima_rata', 0);
    $riserva = ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']));
    $estinzione = ruEstinzione($this, $s);

    expect(odaStorico($s, $estinzione)['ordinaria']['testo'])->toBe('Come dice ogni voce, la scelta del passaggio del 1 maggio 2026 da cui era nato l\'usufrutto: nel conguaglio le voci sul «Proprietario» erano già del nudo proprietario.')
        ->and(odaStorico($s, $riserva)['ordinaria']['scelta'])->toBe('voce');
});

it('rilievo A1 — senza voci da spostare la frase non promette i piani dopo; le voci create dopo partono dal «Proprietario», e il pannello lo dice', function () {
    // La voce è già sull'«Usufruttuario»: nessuna voce da spostare.
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];

    expect($ordinaria['voci'])->toBe([])
        ->and($ordinaria['frasi'][0])->toBe('Dal 1 maggio 2026 le spese ordinarie sono di Venditore Ugo, usufruttuario (art. 1004 c.c.). Su questa unità però non ci sono rate emesse da conguagliare e nessuna voce si sposta: in questo passaggio la scelta non cambia niente. Le voci create dopo, e quelle delle gestioni che si apriranno, partono dal «Proprietario»: per darle all\'usufruttuario mettile su «Usufruttuario».');
});

it('rilievo A6 — l\'annullamento dice i coefficienti che la voce divisa aveva prima, non «riportala al Proprietario»', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $ctm = odaAssociazione($s);
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctm)->update(['percentuale' => 70]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'usufruttuario', 'percentuale' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(odaCoefficienti($s))->toBe(['usufruttuario' => 100.0])
        ->and(implode("\n", odaStorico($s, $subentro)['annullabile']['avvisi']))->toContain('Spese generali (Proprietà, Ordinaria 2026; prima Proprietario 70 %, Usufruttuario 30 %)');
});

// --- Il cancello della riserva (decisione 28.5, spostato qui da `RiservaUsufruttoTest` nella Fase 1-bis della beta.41) -----

// Dalla 1.11.0-beta.41 (decisione 31.5) l'avviso consiglia qualcosa solo per le voci che restano sul «Proprietario» senza
// che l'amministratore l'abbia scelto: quelle bloccate da un piano approvato (31.8, 31.9). Con la legge le altre passano
// all'«Usufruttuario»; con «come la voce», o con la spunta tolta, restano per scelta e l'avviso dice solo il fatto (rilievo
// A5). Rilievo T-B2 della revisione della Fase 1-ter: per una voce bloccata «mettila su Usufruttuario» contraddiceva il
// riquadro delle voci bloccate (la pagina della voce la vieta con i capitoli, e il passaggio non la sposta): il rimedio è
// riportare in bozza il piano che la blocca, se è questo e non ha niente a giornale; se la blocca un altro piano, il fatto.
it('rilievo B1 (decisione 28.5) — piano ordinario non emesso che blocca una voce sul «Proprietario»: il cancello dice che il ricalcolo darebbe l\'ordinaria, dal giorno dell\'atto, a chi compra la nuda proprietà, e il rimedio vero (riportare il piano in bozza prima di registrare)', function (string $come) {
    $s = ruScenario('prima_rata', 0);
    if ($come === 'capitoli') {
        odaVoceInUnPiano($s, 'capitoli', 'approvato');
    }

    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    expect($anteprima['ordinaria']['voci'][0]['bloccata'])->toBeTrue()
        ->and($anteprima['ordinaria']['voci'][0]['bloccata_da'])->toBe($come === 'capitoli' ? 'piano' : 'piano_globale')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))
        ->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» (Spese generali) vanno a Acquirente Elsa, nudo proprietario')
        ->toContain('fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.): il piano le blocca, e il passaggio non le sposta. Se devono restare a Venditore Ugo, che resta usufruttuario, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio: con il passaggio si sposteranno su «Usufruttuario»; poi riapprova il piano e ricalcolalo')
        // Il consiglio che la pagina della voce non permette (con i capitoli) e che il passaggio non esegue non c'è più.
        ->not->toContain('metti quelle voci su «Usufruttuario»')
        ->not->toContain('un altro piano approvato le blocca')
        ->not->toContain('il destinatario cambierebbe');
    // Il cancello e il riquadro delle voci bloccate dicono lo stesso rimedio.
    expect(implode("\n", $anteprima['ordinaria']['frasi_bloccate']))->toContain('riporta il piano in bozza dalla sua pagina');
    // La frase degli obbligati rinvia alla guida, come quella della costituzione.
    expect(implode("\n", $anteprima['obbligati']['frasi']))->toContain('Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».');
})->with(['piano con i capitoli (31.8)' => ['capitoli'], 'piano globale (31.9)' => ['globale']]);

it('rilievo T-B2 — la voce è bloccata da un altro piano (le fatture di uno straordinario approvato) e il preventivo, in bozza, si ricalcolerà: il cancello dice il fatto senza consigliare uno spostamento che la pagina della voce vieta', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    odaVoceInUnPiano($s, 'fatture', 'approvato');

    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    expect($anteprima['ordinaria']['voci'][0]['bloccata'])->toBeTrue()
        ->and(implode(' | ', $anteprima['cancello']['motivi']))
        ->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» (Spese generali) vanno a Acquirente Elsa, nudo proprietario, anche se fra le parti l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.): un altro piano approvato le blocca, e il passaggio non le sposta — il riquadro «Chi paga l\'ordinaria dal giorno dell\'atto» dice quale')
        ->not->toContain('metti quelle voci su «Usufruttuario»')
        ->not->toContain('riporta il piano in bozza')
        ->and(implode("\n", $anteprima['ordinaria']['frasi_bloccate']))->toContain('è nelle fatture del piano straordinario «Spese da finanziare», approvato');
});

it('rilievo A5 — con «come dice ogni voce», o con la spunta tolta, il cancello dice il fatto e non consiglia la strada scartata', function (array $extra, string $perche) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    if ($extra === ['tenuta']) {
        $extra = ['voci_da_tenere' => [odaAssociazione($s)]];
    }

    $motivi = implode(' | ', ruAnteprima($this, $s, ruRiserva($s, extra: $extra))['cancello']['motivi']);
    expect($motivi)->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» (Spese generali) vanno a Acquirente Elsa, nudo proprietario, ' . $perche)
        ->not->toContain('fra le parti l\'ordinaria è dell\'usufruttuario')
        ->not->toContain('metti quelle voci su');
})->with([
    'come dice ogni voce' => [['ordinaria_dopo_atto' => 'voce'], 'per la scelta «come dice ogni voce»'],
    'la legge, con la spunta tolta' => [['tenuta'], 'perché hai tolto loro la spunta'],
]);

// --- Rilievo S2: la lente sicurezza, i test negativi «altro condominio» ----------------------------------------------

it('rilievo S2 — registrare dall\'indirizzo di un altro condominio: 404, nessun passaggio, nessuna voce toccata', function () {
    $a = ruScenario('prima_rata', 0);
    $b = ruScenario('prima_rata', 0);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$a['c'], $b['unita']]), ruRiserva($b))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$a['c'], $b['unita']]), ruRiserva($b))->assertNotFound();

    expect(Subentro::count())->toBe(0)
        ->and(odaCoefficienti($a))->toBe(['proprietario' => 100.0])
        ->and(odaCoefficienti($b))->toBe(['proprietario' => 100.0]);
});

it('rilievo S2 — una pertinenza di un altro condominio: errore sulle pertinenze, nessuna voce toccata in nessuno dei due', function () {
    $a = ruScenario('prima_rata', 0);
    $b = ruScenario('prima_rata', 0);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$b['c'], $b['unita']]), ruRiserva($b, extra: ['pertinenze' => [$a['unita']->id]]))
        ->assertSessionHasErrors('pertinenze');

    expect(Subentro::count())->toBe(0)
        ->and(odaCoefficienti($a))->toBe(['proprietario' => 100.0])
        ->and(odaCoefficienti($b))->toBe(['proprietario' => 100.0]);
});

it('rilievo S2 — annullare dall\'indirizzo di un altro condominio: 404, il passaggio resta e le voci spostate pure', function () {
    $a = ruScenario('prima_rata', 0);
    $b = ruScenario('prima_rata', 0);
    ruPianoInBozza($b);
    $subentro = ruRegistra($this, $b, ruRiserva($b));

    foreach ([[$a['c'], $b['unita'], $subentro], [$a['c'], $a['unita'], $subentro]] as $indirizzo) {
        $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', $indirizzo), ['nota_annullamento' => 'Rogito registrato due volte'])->assertNotFound();
    }

    expect($subentro->fresh()->annullato())->toBeFalse()
        ->and(odaCoefficienti($b))->toBe(['usufruttuario' => 100.0])
        ->and(odaCoefficienti($a))->toBe(['proprietario' => 100.0]);
});

// --- Coda 216, sentinella: l'addebito diretto non ha una voce da spostare ----------------------------------------------

it('Coda 216 (sentinella) — l\'addebito diretto di una gestione ordinaria, con la legge: il conguaglio lo lascia all\'usufruttuario, un piano ricalcolato lo dà al nudo proprietario', function () {
    // Trovata nella Fase 2 della beta.41. La spesa addebitata direttamente all'unità non ha una voce, quindi la scelta
    // «all'usufruttuario» non la sposta: il motore la dà al primo titolare di diritto reale (`titolariDiDirittoReale()`),
    // cioè al nudo proprietario dal giorno dell'atto. Lo stesso euro va a due persone, come nella Coda 171 prima della
    // .41. Quando la Coda 216 sarà decisa, questo test cambia.
    $s = odaPianoDaFatture();
    ruRegistra($this, $s, ruRiserva($s));
    expect(odaDebitoDi($s, $s['a']))->toBe(0);

    ruRicalcola($s);
    $addebito = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'ad_personam')
        ->orderBy('titolarita_dal')->get(['anagrafica_id', 'importo', 'ruolo_risolto'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->importo, $r->ruolo_risolto])->all();

    // € 365,00 sul 2026: 120 giorni a Ugo fino al 30/04, € 120,00; 245 a Elsa da nuda proprietaria, € 245,00.
    expect($addebito)->toBe([[$s['v']->id, 12000, 'proprietario'], [$s['a']->id, 24500, 'nuda_proprietario']]);
});

// --- Decisione 33 (1.11.0-beta.42, D-U1): un usufrutto della gestione nato con la scelta opposta ------------------------

/** Ugo costituisce l'usufrutto a Elsa il 1/2 con la scelta data, e l'usufrutto si estingue il 1/5; poi una costituzione a Dora. */
function odaDueUsufrutti($test, string $primaScelta): array
{
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    ruRegistra($test, $s, odaCostituzione($s, ['decorrenza' => '2026-02-01', 'ordinaria_dopo_atto' => $primaScelta]));
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'usufruttuario')->value('id');
    ruRegistra($test, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaElsa, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per rinuncia, letta']);
    $dora = Anagrafica::forceCreate(['nome' => 'Dora Seconda', 'email' => 'oda-dora@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ODADORASECONDA01']);
    $dora->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('immobile_id', $s['unita']->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->value('id');

    return [$s, $dora, $rigaUgo];
}

it('decisione 33 — la costituzione con la legge sposta la voce anche per i giorni di un usufrutto nato «come dice ogni voce», e il pannello lo nomina', function () {
    [$s, $dora, $rigaUgo] = odaDueUsufrutti($this, Subentro::ORDINARIA_COME_LA_VOCE);

    $ordinaria = ruAnteprima($this, $s, odaCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01']))['ordinaria'];

    // Nel rapporto: Elsa paga € 292,60 per gli 89 giorni (1/2–30/4) che la sua scelta dava al nudo proprietario.
    expect($ordinaria['frasi_altri_usufrutti'])->toBe(['L\'usufrutto di Acquirente Elsa su Interno 1, dal 1 febbraio 2026 al 30 aprile 2026, è nato con la scelta «come dice ogni voce»: spostando Spese generali sull\'«Usufruttuario», i piani della gestione «Ordinaria 2026» generati o ricalcolati dopo daranno l\'ordinaria di quella voce a Acquirente Elsa, usufruttuario, anche per i giorni di quell\'usufrutto, e non più al nudo proprietario. Per lasciarla al nudo proprietario, togli la spunta alla voce qui sopra.']);
});

it('decisione 33, l\'altro verso — con «come dice ogni voce» dopo un usufrutto nato con la legge, la voce è già sull\'«Usufruttuario», e il pannello lo dice', function () {
    [$s, $dora, $rigaUgo] = odaDueUsufrutti($this, Subentro::ORDINARIA_ALL_USUFRUTTUARIO);

    $ordinaria = ruAnteprima($this, $s, odaCostituzione($s, ['riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $dora->id, 'decorrenza' => '2026-07-01', 'ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]))['ordinaria'];

    expect($ordinaria['frasi_altri_usufrutti'])->toBe(['La voce Spese generali è già sull\'«Usufruttuario» dall\'usufrutto di Acquirente Elsa su Interno 1, nato il 1 febbraio 2026 con la legge: con «come dice ogni voce» la paga l\'usufruttuario anche qui, perché è il ruolo che la voce dice oggi.']);
});

it('rilievo R6 della Fase 1-bis della .42 — riserva con la legge e la voce bloccata dal piano: l\'emissione si rifiuta e dice la strada (annullare, piano in bozza, registrare di nuovo), e la strada funziona', function () {
    $s = ruScenario('prima_rata', 0);
    $passaggio = ruRegistra($this, $s, ruRiserva($s));
    expect($passaggio->registro['voci_spostate'] ?? [])->toBe([]);

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => DB::table('rate')->where('piano_rate_id', $s['piano']->id)->limit(2)->pluck('id')->all(), 'data_emissione' => '2026-05-10', 'invia_notifiche' => false,
    ]);
    // Verifica a video della .42: la strada in passi numerati, nell'ordine che il test segue qui sotto.
    // Revisione: «a chi si riserva l'usufrutto», senza accordare la frase alla persona, e il primo passo dice dove si annulla.
    expect($r->getSession()->get('message')['message'])->toContain("Se l'ordinaria deve restare a chi si riserva l'usufrutto (Venditore Ugo), come scelto nel passaggio:\n1. Annulla il passaggio dallo storico della sua unità («Passaggi registrati», dall'ultimo).\n2. Riporta il piano in bozza.\n3. Registra di nuovo il passaggio, che sposterà le voci sul «Proprietario» su «Usufruttuario».\n4. Riapprova il piano e ricalcolalo.");

    // La strada: annullare il passaggio, riportare il piano in bozza, registrarlo di nuovo (la voce si sposta e lo dice il registro),
    // riapprovare e ricalcolare. Ugo resta usufruttuario e l'ordinaria resta sua: € 1.200,00 tutto l'anno.
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento' => 'Lo registro di nuovo con il piano in bozza'])->assertRedirect();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])->assertRedirect();
    $di_nuovo = ruRegistra($this, $s, ruRiserva($s));
    expect(collect($di_nuovo->registro['voci_spostate'] ?? [])->pluck('conto')->all())->toBe(['Spese generali']);
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true])->assertRedirect();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Riserva registrata di nuovo con la voce spostata',
    ])->assertSessionHasNoErrors();

    $piano = $s['piano']->fresh();
    expect($piano->passaggiDaSeguire())->toBe([])
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $s['v']->id)->sum('rate_quote.importo'))->toBe(120000)
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->where('rate_quote.anagrafica_id', $s['a']->id)->sum('rate_quote.importo'))->toBe(0);
});
