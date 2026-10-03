<?php

/**
 * Le quote senza composizione nel conguaglio di un passaggio (1.11.0-beta.41).
 *
 * La 1.7.x generava le quote senza salvare quanta parte di ogni rata è spesa dell'anno e quanta è saldo pregresso
 * (`rate_quote.regole_calcolo` vuoto): il pregresso stava dentro l'importo. Il conguaglio leggeva l'importo intero come
 * spesa, e così in una vendita dava a chi compra anche il pregresso di chi vende. Ora, quando il piano ha assorbito un saldo
 * pregresso di quell'unità o di quella persona (`saldi.piano_rate_id`), su quelle quote il conguaglio si ferma e lo dice:
 * non indovina. Le bozze restano a chi le ha, senza conguaglio. Senza un saldo assorbito la quota è tutta spesa, come prima.
 *
 * Cosa copre: la vendita con le quote senza composizione e un saldo assorbito (frase, nessuna coppia, bozze che restano,
 * cancello); le controprove con la composizione presente, senza saldo, e con il saldo di un'altra unità.
 * Cosa NON copre: la ricostruzione della composizione dai saldi e dal metodo del piano (non fatta: nella 1.7.0 il saldo si
 * sottraeva, poco dopo si sommava, e il segno non si può dedurre); la forma `audit.*` del vecchio formato degli eventi del
 * portale, che sulle quote non è mai stata scritta.
 */

use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Le quote del piano come le scriveva la 1.7.x: senza composizione, il pregresso dentro l'importo. */
function scSenzaComposizione(array $s): void
{
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
}

/** La vendita piena di Ugo a Elsa il 1/5, con la spunta del cancello. */
function scVendita(array $s): array
{
    return ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 9', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Vendita letta'];
}

/** Le frasi delle rate già emesse e del conguaglio, in un testo solo. Vuoto non è ammesso: una controprova su un testo vuoto passa sempre. */
function scFrasi(array $anteprima): string
{
    $testo = implode("\n", array_merge($anteprima['rate']['frasi'] ?? [], $anteprima['rate']['conguaglio']['frasi'] ?? []));
    expect($testo)->not->toBe('');

    return $testo;
}

it('quote senza composizione e un saldo pregresso assorbito dal piano: il conguaglio si ferma e lo dice, nessuna coppia, le bozze restano a chi vende', function () {
    // Ugo ha € 300,00 di pregresso, assorbito nella prima rata: la rata 1 vale € 400,00, le altre € 100,00.
    $s = ruScenario('prima_rata', 30000);
    expect(Saldo::where('anagrafica_id', $s['v']->id)->whereNull('subentro_id')->value('piano_rate_id'))->toBe($s['piano']->id);
    scSenzaComposizione($s);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, scVendita($s));
    $subentro = ruRegistra($this, $s, scVendita($s));

    // Trattate come «tutta spesa», la rata 1 avrebbe passato a Elsa 245/365 dei € 300,00 di Ugo.
    expect(scFrasi($anteprima))
        ->toContain('Piano «Preventivo 2026»: le sue quote sono state generate da una versione che non salvava quanta parte di ogni rata è saldo pregresso, e su questa unità il piano ha assorbito un saldo pregresso: la spesa dell\'anno non si separa dal pregresso — se serve un conguaglio, si scrive con un saldo manuale dal Wallet sulla stessa gestione. Nessun conguaglio proposto su quelle quote.')
        ->toContain('Le 8 quote del piano «Preventivo 2026» non ancora emesse resteranno intestate a Venditore Ugo, senza conguaglio: non dicono quanta parte è saldo pregresso.')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('restano sue, senza conguaglio: non dicono quanta parte è saldo pregresso')
        ->and(ruCoppia($s))->toBe([0, 0])
        ->and(DB::table('rate_quote')->where('anagrafica_id', $s['a']->id)->count())->toBe(0)
        ->and($subentro->annullato())->toBeFalse();
});

it('lo stato vero della 1.7.x: il saldo non ha il piano (la colonna è nata vuota con la beta.32 e la riparazione dei lucchetti non lo ritrova), le quote non hanno la composizione: il conguaglio si ferma lo stesso', function () {
    $s = ruScenario('prima_rata', 30000);
    scSenzaComposizione($s);
    // Come lascia i saldi della 1.7.x la migrazione del 31/07/2026: nessun piano, lucchetto aperto.
    Saldo::where('anagrafica_id', $s['v']->id)->whereNull('subentro_id')->update(['piano_rate_id' => null, 'is_applicato' => false]);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, scVendita($s));
    ruRegistra($this, $s, scVendita($s));

    expect(scFrasi($anteprima))->toContain('le sue quote sono state generate da una versione che non salvava quanta parte di ogni rata è saldo pregresso')
        ->and(ruCoppia($s))->toBe([0, 0])
        ->and(DB::table('rate_quote')->where('anagrafica_id', $s['a']->id)->count())->toBe(0);
});

it('controprova — un saldo senza piano dello stesso esercizio, ma scritto dopo le quote del piano, non c\'entra: la quota è tutta spesa', function () {
    $s = ruScenario('prima_rata', 0);
    scSenzaComposizione($s);
    $saldo = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['v']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id,
        'saldo_iniziale' => 30000, 'origine' => 'manuale', 'is_applicato' => false]);
    DB::table('saldi')->where('id', $saldo->id)->update(['created_at' => now()->addMinute()]);
    ruEmetti($s);

    expect(scFrasi(ruAnteprima($this, $s, scVendita($s))))->not->toContain('non salvava');
});

it('controprova — con la composizione salvata il conguaglio è quello di sempre: le bozze passano a Elsa e la coppia regola le rate emesse, senza il pregresso', function () {
    $s = ruScenario('prima_rata', 30000);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, scVendita($s));
    ruRegistra($this, $s, scVendita($s));

    // Spesa € 1.200,00: a Elsa 245 giorni, 120000 × 245/365 = 80547,95 → 80548, di cui 80000 con le otto bozze; coppia 548.
    expect(scFrasi($anteprima))->not->toContain('non salvava')
        ->and(ruCoppia($s))->toBe([548, -548]);
});

it('controprova — quote senza composizione ma nessun saldo assorbito: la quota è tutta spesa e il conguaglio procede come prima', function () {
    $s = ruScenario('prima_rata', 0);
    scSenzaComposizione($s);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, scVendita($s));
    ruRegistra($this, $s, scVendita($s));

    expect(scFrasi($anteprima))->not->toContain('non salvava')
        ->and(ruCoppia($s))->toBe([548, -548]);
});

it('controprova — il saldo assorbito è di un\'altra unità della stessa persona: su questa la quota è tutta spesa', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $altra = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SC-2', 'nome' => 'Interno 2', 'interno' => '2']);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['v']->id, 'immobile_id' => $altra->id, 'gestione_id' => $s['g']->id,
        'saldo_iniziale' => 30000, 'origine' => 'manuale', 'is_applicato' => true, 'piano_rate_id' => $s['piano']->id]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    scSenzaComposizione($s);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, scVendita($s));

    expect(scFrasi($anteprima))->not->toContain('non salvava');
});
