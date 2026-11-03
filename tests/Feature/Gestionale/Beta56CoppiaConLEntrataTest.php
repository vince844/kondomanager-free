<?php

/**
 * Coda 368 — la coppia di un secondo passaggio con il conguaglio scritto, su una quota emessa a un predecessore, con una voce di entrata.
 *
 * La quota di Ines è passata a Sara con il cambio d'inquilino del 1/7 («Scrivi»); la fine di Sara del 1/10 (o il cambio Sara → Pia, o la seconda vendita Zeta → Mario)
 * divide di nuovo quella quota: a chi entra (o a chi paga al posto dell'inquilino) la parte dei giorni dal secondo passaggio, voce per voce, con il segno della voce: la spesa
 * a debito, l'entrata a credito. `ConguaglioPassaggio::scomponiPerConto` confrontava le due parti con `min`/`max` senza il segno, e su un'entrata `min` prendeva il numero
 * più grande in valore assoluto: a chi entra passava tutta l'entrata dei giorni di chi esce (Sara − € 15,12 / Ugo + € 15,12 invece di − € 52,93 / + € 52,93).
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 sulla «Proprietà», 30 % all'inquilino; l'«Affitto antenna» (entrata, 70/30) dalla pagina «Nuova
 * voce»; il piano «Preventivo 2026» dalla pagina, con le due voci (e, se data, la competenza dell'antenna), approvato dalla pagina, generato, con le rate fino al 30/6 a giornale.
 * Ines inquilina al 100 %, Ugo proprietario.
 *
 * I CONTI A MANO (centesimi, anno 2026 di 365 giorni; resti maggiori sul valore assoluto, a pari resto chi esce):
 * - Le parti dell'inquilino: spese 36000, antenna − 15000. Il cambio del 1/7 (184 giorni): spese 18147,95 → 18148, antenna − 7561,64 → − 7562; la coppia 10586.
 * - La fine di Sara del 1/10 (92 giorni dopo): spese 9073,97 → 9074, antenna − 3780,82 → − 3781: 5293. Il programma: antenna min(− 3781, − 7562) = − 7562, la coppia 1512.
 * - L'antenna con la competenza 1/1–31/8 (243 giorni): il cambio − 3827,16 → − 3827 (coppia 14321); dopo il 1/10 l'antenna non ha giorni: 0. La fine 9074; il programma 5247.
 * - L'entrata € 1.000,00 sul 1/6–31/10 (153 giorni): il cambio − 24117,65 → − 24118 (coppia − 5970); dopo il 1/10 − 6078,43 → − 6078; la fine 9074 − 6078 = 2996; il programma − 15044.
 * - Le due vendite, la parte del proprietario (70 %): spese 84000, antenna − 35000; la seconda vendita 21172,60 → 21173 e − 8821,92 → − 8822, la parte di Mario 12351, meno le tre
 *   bozze che passano a lui (3 × 4083 = 12249): 102. Il programma 21173 − 17644 − 12249 = − 8720.
 *
 * - Le famiglie della decisione 56 con un'entrata (il secondo punto della correzione): le due metà della quota di Ugo arrivate a Nora in giorni diversi (l'estinzione il 1/4, la vendita di Bice il
 *   1/6), che Nora vende tutte a Carlo il 1/9: ciascuna 60000 × 122/365 = 20054,79 → − 20055, al più quanto è arrivato, la coppia − 40110; senza il segno − 80383 (lo scenario di
 *   `QuotaCheEsceTest`, «catena — due nudi, uno compra la metà dell'altro e rivende tutto», con gli aiuti di `Support/ScenariPassaggi.php`).
 *
 * Cosa NON copre: la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/Beta53SaldoLegatoScenari.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Lo scenario, tutto dalle pagine: l'«Affitto antenna» dall'importo dato (con `null` nessuna antenna), il piano con le voci e, se data, la competenza dell'antenna, approvato,
 * generato, le rate fino al 30/6 a giornale.
 */
function cleScenario($test, ?string $importo = '500,00', ?array $competenza = null): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 100]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $capitoli = [['id' => $spese->id, 'importo' => '1.200,00', 'note' => '']];
    $competenze = [];
    if ($importo !== null) {
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
            'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => $importo, 'descrizione' => '', 'note' => '',
            'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
            'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $antenna = \App\Models\Gestionale\Conto::where('nome', 'Affitto antenna')->where('piano_conto_id', $pc->id)->firstOrFail();
        $capitoli[] = ['id' => $antenna->id, 'importo' => $importo, 'note' => ''];
        if ($competenza !== null) {
            $competenze[] = ['conto_id' => $antenna->id, 'tratti' => [['dal' => $competenza[0], 'al' => $competenza[1]]]];
        }
    }
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => 'Preventivo 2026', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => $capitoli, 'competenze_capitoli' => $competenze,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->latest('id')->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), [
        'approvato' => true, 'data_delibera_assemblea' => '2025-12-15',
    ])->assertSessionHasNoErrors();
    $s['piano'] = $s['piano']->fresh();
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Il cambio d'inquilino dalla rotta vera, con la quota di chi esce e la scelta, che l'anteprima chiede. */
function cleCambio($test, array &$s, string $esce, string $entra, string $il, string $scelta = 'scrivi'): Subentro
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')] + fclModulo($s, $s['persone'][$entra], $il, [], $esce);
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBeTrue();

    return fclRegistra($test, $s, $dati + ['conguaglio' => $scelta])->fresh();
}

/** La fine di una locazione senza chi entra dalla rotta vera, con la scelta, che l'anteprima chiede: [il passaggio, le frasi del pannello]. */
function cleFine($test, array $s, string $esce, string $il, string $scelta = 'scrivi'): array
{
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')] + fclModulo($s, null, $il, [], $esce);
    $a = fclAnteprima($test, $s, $dati);
    expect((bool) ($a['rate']['scelta_del_conguaglio'] ?? false))->toBeTrue();

    return [fclRegistra($test, $s, $dati + ['conguaglio' => $scelta])->fresh(), fclFrasi($a)];
}

/** La vendita della quota del proprietario dalla rotta vera: [il passaggio, le frasi del pannello]. */
function cleVendita($test, array &$s, string $esce, string $entra, string $il): array
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, $esce, 'proprietario'), 'anagrafica_entrante_id' => $s['persone'][$entra]->id,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []];
    $frasi = fclFrasi(fclAnteprima($test, $s, $dati));

    return [fclRegistra($test, $s, $dati)->fresh(), $frasi];
}

/** Le righe di un passaggio in saldi (la sua coppia), per nome. */
function cleRighe(array $s, Subentro $passaggio): array
{
    $nomi = fclNomi($s);

    return DB::table('saldi')->where('subentro_id', $passaggio->id)->orderBy('id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->mapWithKeys(fn ($r) => [$nomi[(int) $r->anagrafica_id] => (int) $r->saldo_iniziale])->all();
}

/** Tutte le righe dei saldi dell'unità sulla gestione, per persona (le coppie, e i saldi legati scritti dal Wallet). */
function cleWallet(array $s): array
{
    $nomi = fclNomi($s);
    $out = [];
    foreach (DB::table('saldi')->where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']->id)->get(['anagrafica_id', 'saldo_iniziale']) as $r) {
        $out[$nomi[(int) $r->anagrafica_id]] = ($out[$nomi[(int) $r->anagrafica_id]] ?? 0) + (int) $r->saldo_iniziale;
    }
    ksort($out);

    return $out;
}

/** Il saldo del Wallet legato a un passaggio, dal modale (POST di `saldi.store` con `passaggio_id`). */
function clePosta($test, array $s, string $chi, int $cents, Subentro $passaggio): void
{
    $test->actingAs($test->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'passaggio_id' => $passaggio->id,
    ])->assertSessionHasNoErrors();
}

/** La sezione di un conduttore nel prospetto: [totale, con il conguaglio, da rimborsare]; i giorni senza conduttore: [totale, pagata da]. */
function cleProspetto(array $s): array
{
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = [(int) $c['totale'], (int) ($c['con_il_conguaglio'] ?? 0), (int) $c['da_rimborsare']];
    }
    ksort($out);
    $out['senza'] = [(int) $p['senza_conduttore']['totale'], collect($p['senza_conduttore']['voci'])->pluck('pagato_da')->filter()->unique()->values()->all()];

    return $out;
}

it('Coda 368 — il cambio Ines → Sara «Scrivi» il 1/7 e la fine di Sara «Scrivi» il 1/10, con l\'«Affitto antenna» € 500,00: a Ugo passa l\'entrata dei soli giorni dopo il 1/10, la coppia € 52,93', function () {
    // La coppia della fine: spese 9074, antenna − 3781 (i 92 giorni dal 1/10), 5293: Sara − 5293, Ugo + 5293. Il Wallet: Ines − 10586, Sara 10586 − 5293 = 5293, Ugo 5293. Il prospetto, che non legge
    // i saldi, dice le stesse cifre: Sara 9074 − 3781 = 5293 con il conguaglio, i giorni senza conduttore 5293 pagati da Ugo. Era: Sara − 1512, Ugo + 1512 (l'antenna − 7562, tutti i 184 giorni di
    // Sara), € 37,81 dell'entrata a carico di Sara; il pannello diceva € 15,12.
    $s = cleScenario($this);
    expect(fclNetti($s))->toBe(['Ines' => 21000, 'Ugo' => 49000]);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    expect(fclSaldi($s))->toBe(['Ines' => -10586, 'Sara' => 10586]);
    [$fine, $frasi] = cleFine($this, $s, 'Sara', '2026-10-01');

    expect(cleRighe($s, $fine))->toBe(['Sara' => -5293, 'Ugo' => 5293])
        ->and(fclSaldi($s))->toBe(['Ines' => -10586, 'Sara' => 5293, 'Ugo' => 5293])
        ->and($frasi)->toContain('i giorni dopo l\'uscita di Sara (€ 52,93) passano a chi paga al posto dell\'inquilino, Ugo')
        ->and($frasi)->toContain('Sulla gestione Ordinaria 2026: credito € 52,93 a Sara, debito € 52,93 a Ugo')
        ->and($frasi)->not->toContain('€ 15,12')
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and($fine->registro['conguaglio']['periodi'][0]['importo'])->toBe(5293)
        ->and($fine->registro['conguaglio']['periodi'][0]['periodi'])->toBe([['tratti' => [['dal' => '2026-10-01', 'al' => '2026-12-31']], 'importo' => 5293]]);
    expect(cleProspetto($s))->toBe(['Ines' => [10414, 0, 0], 'Sara' => [5293, 5293, 0], 'senza' => [5293, ['Ugo']]]);
});

it('Coda 368 — lo stesso con «Annulla il conguaglio» del cambio prima della fine: la coppia della fine è la stessa, € 52,93', function () {
    // Annullato il conguaglio del cambio (le parti hanno regolato fra loro), la quota emessa a Ines è passata a Sara lo stesso dal 1/7, e la fine di Sara la divide come sopra. Era: Sara − 1512, Ugo + 1512.
    $s = cleScenario($this);
    $cambio = cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    sl53AnnullaConguaglio($this, $s, $cambio)->assertStatus(302);
    expect(fclSaldi($s))->toBe([]);
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01');

    expect(cleRighe($s, $fine))->toBe(['Sara' => -5293, 'Ugo' => 5293])
        ->and(fclSaldi($s))->toBe(['Sara' => -5293, 'Ugo' => 5293]);
});

it('Coda 368 — un\'entrata la cui competenza finisce prima della fine (l\'antenna dal 1/1 al 31/8): nessun conguaglio sull\'antenna, la coppia della fine è delle sole spese, € 90,74', function () {
    // Il cambio del 1/7: antenna − 15000 × 62/243 = − 3827,16 → − 3827, la coppia 18148 − 3827 = 14321. Dopo il 1/10 l'antenna non ha giorni: a Ugo 0, la coppia della fine 9074. Il pannello lo dice
    // voce per voce. Era: l'antenna min(0, − 3827) = − 3827, la coppia 5247 (€ 38,27 di credito a Ugo di un'entrata che non ha giorni dopo il 1/10).
    $s = cleScenario($this, '500,00', ['2026-01-01', '2026-08-31']);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    expect(fclSaldi($s))->toBe(['Ines' => -14321, 'Sara' => 14321]);
    [$fine, $frasi] = cleFine($this, $s, 'Sara', '2026-10-01');

    expect(cleRighe($s, $fine))->toBe(['Sara' => -9074, 'Ugo' => 9074])
        ->and(fclSaldi($s))->toBe(['Ines' => -14321, 'Sara' => 5247, 'Ugo' => 9074])
        ->and($frasi)->toContain('Affitto antenna: € 150,00 di entrata, competenza 1 gennaio 2026–31 agosto 2026 — emessa a Ines, la competenza finisce prima del 1 ottobre 2026: nessun conguaglio su questa voce.')
        ->and($frasi)->not->toContain('€ 38,27');
});

it('Coda 368 — il secondo cambio d\'inquilino Sara → Pia «Scrivi» il 1/10: a Pia passa la parte dell\'entrata dei suoi giorni, la coppia € 52,93, e il prospetto dice lo stesso', function () {
    // La coppia del secondo cambio: 9074 − 3781 = 5293, Sara − 5293, Pia + 5293. Il Wallet: Sara 10586 − 5293 = 5293, Pia 5293; il prospetto, Pia € 52,93 con il conguaglio, come il Wallet. Era: Pia
    // + 1512 nel Wallet e € 52,93 «con il conguaglio» nel prospetto.
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    $cambio = cleCambio($this, $s, 'Sara', 'Pia', '2026-10-01');

    expect(cleRighe($s, $cambio))->toBe(['Sara' => -5293, 'Pia' => 5293])
        ->and(fclSaldi($s))->toBe(['Ines' => -10586, 'Pia' => 5293, 'Sara' => 5293])
        ->and(cleProspetto($s))->toBe(['Ines' => [10414, 0, 0], 'Pia' => [5293, 5293, 0], 'Sara' => [5293, 5293, 0], 'senza' => [0, []]]);
});

it('Coda 368 — due vendite (Ugo → Zeta il 1/7, Zeta → Mario il 1/10), con l\'antenna sulla parte del proprietario: la seconda coppia è Zeta − € 1,02, Mario + € 1,02', function () {
    // La parte del proprietario, 70 %: spese 84000, antenna − 35000. La prima vendita: Zeta 42345 − 17644 = 24701, meno le 6 bozze che passano a lei (6 × 4083 = 24498): 203. La seconda: spese 21173,
    // antenna − 8822 (i 92 giorni dal 1/10), la parte di Mario 12351, meno le 3 bozze che passano a lui (12249): 102. Sui due gruppi di quote (24502 emesse a Ugo, 24498 passate a Zeta) 6176 e 6175.
    // Era: la parte di Mario 21173 − 17644 = 3529, la coppia Zeta + 8720, Mario − 8720 (€ 88,22 di differenza), e il pannello diceva «→ € 17,65».
    $s = cleScenario($this);
    [$prima] = cleVendita($this, $s, 'Ugo', 'Zeta', '2026-07-01');
    expect(cleRighe($s, $prima))->toBe(['Ugo' => -203, 'Zeta' => 203]);
    [$seconda, $frasi] = cleVendita($this, $s, 'Zeta', 'Mario', '2026-10-01');

    expect(cleRighe($s, $seconda))->toBe(['Zeta' => -102, 'Mario' => 102])
        ->and($frasi)->toContain('credito € 1,02 a Zeta, debito € 1,02 a Mario (la parte di Mario sull\'intero piano è € 123,51, di cui € 122,49 con le rate in bozza che passano a suo nome)')
        ->and($frasi)->toContain('92 a Zeta, 92 a Mario → € 61,76 a chi entra')
        ->and($frasi)->toContain('92 a Zeta, 92 a Mario → € 61,75 a chi entra')
        ->and($frasi)->not->toContain('€ 17,65');
});

it('Coda 368 — le guardie: con le sole spese la coppia della fine resta € 90,74, e la fine di Ines senza un cambio prima, con l\'antenna, resta € 52,93', function () {
    // Senza entrate il segno è sempre positivo e il conto non cambia: la fine di Sara 9074. La fine di Ines il 1/10 senza un cambio prima non è una quota di un predecessore: 9074 − 3781 = 5293, come prima.
    $s = cleScenario($this, null);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01');
    expect(cleRighe($s, $fine))->toBe(['Sara' => -9074, 'Ugo' => 9074])
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 9074, 'Ugo' => 9074]);

    $s = cleScenario($this);
    [$fine] = cleFine($this, $s, 'Ines', '2026-10-01');
    expect(cleRighe($s, $fine))->toBe(['Ines' => -5293, 'Ugo' => 5293]);
});

it('Coda 368 — un\'entrata più grande delle spese nei giorni di Sara (l\'antenna € 1.000,00 dal 1/6 al 31/10): la coppia della fine è € 29,96, e il prospetto la dice con un centesimo di differenza', function () {
    // L'entrata, − 30000 sui 153 giorni dal 1/6 al 31/10. Il cambio del 1/7: − 24117,65 → − 24118, la coppia 18148 − 24118 = − 5970 (a credito di Sara). La fine di Sara: spese 9074, l'entrata dei
    // 31 giorni di ottobre − 6078,43 → − 6078, 2996: Sara − 2996, Ugo + 2996. Il prospetto divide invece il pezzo di Sara, − 24118 su 123 giorni, al 1/10: − 18039,48 e − 6078,52 → − 18039 e − 6079;
    // i giorni senza conduttore 9074 − 6079 = 2995. Il centesimo viene dai due arrotondamenti (la coppia sull'anno, il prospetto sul pezzo), non dal segno. Era: la coppia − 15044 (€ 180,40).
    $s = cleScenario($this, '1000,00', ['2026-06-01', '2026-10-31']);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    expect(fclSaldi($s))->toBe(['Ines' => 5970, 'Sara' => -5970]);
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01');

    expect(cleRighe($s, $fine))->toBe(['Sara' => -2996, 'Ugo' => 2996])
        ->and(fclSaldi($s))->toBe(['Ines' => 5970, 'Sara' => -8966, 'Ugo' => 2996])
        ->and(cleProspetto($s)['senza'])->toBe([2995, ['Ugo']])
        ->and(cleProspetto($s)['Sara'][1])->toBe(-8965);
});

it('Coda 368 — chi legge la coppia: «Non scriverlo» e «Annulla il conguaglio» la conservano nel registro con la cifra giusta; la vendita e l\'inizio dopo la fine contano su quella', function () {
    // Con «Non scriverlo» il registro conserva la coppia che si sarebbe scritta (`regolato_fuori`): Sara − 5293, Ugo + 5293 (era − 1512 e + 1512).
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -10586, 'Sara' => 10586])
        ->and(collect($fine->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Sara' => -5293, 'Ugo' => 5293]);

    // «Annulla il conguaglio» della fine scritta: lo stesso, dalle righe che l'annullamento toglie.
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01');
    sl53AnnullaConguaglio($this, $s, $fine)->assertStatus(302);
    expect(collect($fine->fresh()->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Sara' => -5293, 'Ugo' => 5293]);

    // La vendita di Ugo a Zeta il 1/11, dopo la fine «Scrivi»: la fermata è la parte della coppia dei giorni dal 1/11, 5293 × 61/92 = 3509,49 → 3509 (era 1512 × 61/92 → 1003).
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    cleFine($this, $s, 'Sara', '2026-10-01');
    [$vendita, $frasi] = cleVendita($this, $s, 'Ugo', 'Zeta', '2026-11-01');
    expect($frasi)->toContain('La fine locazione di Sara del 1 ottobre 2026 ha scritto il conguaglio: dei giorni dopo la sua uscita Ugo ha preso € 52,93 sulla gestione Ordinaria 2026 (Interno 1).')
        ->and($frasi)->toContain('il programma non la sposta: € 35,09 restano a carico di Ugo')
        ->and(collect($vendita->registro['fermata_dopo_scrivi'])->map(fn ($f) => [(int) $f['coppia'], (int) $f['cifra']])->all())->toBe([[5293, 3509]]);

    // L'inizio di Teo il 1/11, dopo la fine «Scrivi»: la parte di Teo nella coppia ridivisa, 3509 (era 1003).
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    cleFine($this, $s, 'Sara', '2026-10-01');
    fclPersona($s, 'Teo');
    $inizio = fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Teo']->id, 'decorrenza' => '2026-11-01', 'quota' => 100,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []])->fresh();
    expect(collect($inizio->registro['giorni_del_conduttore'])->map(fn ($g) => [(int) $g['cifra'], $g['pagato_da']])->all())->toBe([[3509, ['Ugo']]]);
});

it('Coda 368 — i dati già passati: la coppia scritta da una versione di prima resta com\'è, e due saldi legati al passaggio dal Wallet la portano alla cifra giusta (la fine di Sara e la seconda vendita)', function () {
    // Le righe come le scriveva la versione di prima, riscritte nel database: la fine di Sara Sara − 1512, Ugo + 1512 (il Wallet Sara 9074, Ugo 1512). La correzione non le tocca (nessuna migrazione):
    // i due saldi legati alla fine, Sara − 3781 e Ugo + 3781, portano il Wallet a Sara 5293, Ugo 5293.
    $s = cleScenario($this);
    cleCambio($this, $s, 'Ines', 'Sara', '2026-07-01');
    [$fine] = cleFine($this, $s, 'Sara', '2026-10-01');
    DB::table('saldi')->where('subentro_id', $fine->id)->where('anagrafica_id', $s['persone']['Sara']->id)->update(['saldo_iniziale' => -1512]);
    DB::table('saldi')->where('subentro_id', $fine->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->update(['saldo_iniziale' => 1512]);
    expect(cleWallet($s))->toBe(['Ines' => -10586, 'Sara' => 9074, 'Ugo' => 1512]);
    clePosta($this, $s, 'Sara', -3781, $fine);
    clePosta($this, $s, 'Ugo', 3781, $fine);
    expect(cleWallet($s))->toBe(['Ines' => -10586, 'Sara' => 5293, 'Ugo' => 5293])
        ->and(cleRighe($s, $fine))->toBe(['Sara' => -1512, 'Ugo' => 1512]);

    // La seconda vendita come la scriveva la versione di prima: Zeta + 8720, Mario − 8720. I due saldi legati alla vendita, Zeta − 8822 e Mario + 8822: Zeta − 102 + 203 della prima vendita = 101,
    // Mario 102.
    $s = cleScenario($this);
    cleVendita($this, $s, 'Ugo', 'Zeta', '2026-07-01');
    [$seconda] = cleVendita($this, $s, 'Zeta', 'Mario', '2026-10-01');
    DB::table('saldi')->where('subentro_id', $seconda->id)->where('anagrafica_id', $s['persone']['Zeta']->id)->update(['saldo_iniziale' => 8720]);
    DB::table('saldi')->where('subentro_id', $seconda->id)->where('anagrafica_id', $s['persone']['Mario']->id)->update(['saldo_iniziale' => -8720]);
    clePosta($this, $s, 'Zeta', -8822, $seconda);
    clePosta($this, $s, 'Mario', 8822, $seconda);
    expect(cleWallet($s))->toBe(['Mario' => 102, 'Ugo' => -203, 'Zeta' => 101]);
});

// --- Le famiglie della decisione 56: le parti della stessa riga arrivate a chi esce in giorni diversi, con un'entrata ------------------------------------

/** Una persona del condominio di `ruScenario`, con un codice fiscale suo. */
function cleFamPersona(array $s, string $nome): \App\Models\Anagrafica
{
    static $n = 0;
    $n++;
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . "{$s['unita']->id}fam@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'CLEFAMPERS' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Un passaggio di proprietà come lo fa il modulo: la riga aperta di chi esce con il ruolo del passaggio, l'anteprima, poi la registrazione con la spunta del cancello e l'impronta delle voci. */
function cleFamPassa($test, array $s, string $tipo, \App\Models\Anagrafica $esce, ?\App\Models\Anagrafica $entra, string $il): array
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $esce->id)->where('tipologia', ruRuoloUscente($tipo))
        ->where(fn ($q) => $q->whereNull('data_fine')->orWhereDate('data_fine', '>=', $il))->orderByDesc('id')->value('id');
    $dati = array_merge(ruPassaggio($tipo, $riga, $entra, $il, (float) DB::table('anagrafica_immobile')->where('id', $riga)->value('quota')),
        ['ho_letto' => true, 'nota_cancello' => 'Atto letto']);
    $anteprima = ruAnteprima($test, $s, $dati);
    if (($anteprima['ordinaria']['impronta'] ?? null) !== null) {
        $dati['ordinaria_impronta'] = $anteprima['ordinaria']['impronta'];
    }
    ruRegistra($test, $s, $dati);

    return $anteprima;
}

/** Quanto paga ognuno sull'unità, per nome: le quote del piano più le coppie dei passaggi. */
function cleFamNetti(array $s): array
{
    $nomi = DB::table('anagrafiche')->pluck('nome', 'id')->all();
    $netti = ruPerPersona($s['piano']);
    foreach (\App\Models\Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $x) {
        $netti[(int) $x->anagrafica_id] = ($netti[(int) $x->anagrafica_id] ?? 0) + (int) $x->saldo_iniziale;
    }
    $out = [];
    foreach (array_filter($netti, fn ($n) => $n !== 0) as $id => $n) {
        $out[$nomi[$id]] = $n;
    }
    ksort($out);

    return $out;
}

it('Coda 368 — le famiglie della decisione 56 con un\'entrata: Ugo usufruttuario, Nora e Bice nude al 50 %, l\'usufrutto si estingue il 1/4, Bice vende a Nora il 1/6, Nora vende tutto a Carlo il 1/9: Carlo € 401,10 a credito, non € 803,83', function () {
    // Lo scenario del test «catena — due nudi, uno compra la metà dell'altro e rivende tutto» (`QuotaCheEsceTest`), con la voce sull'«Usufruttuario» di spesa e di entrata (€ 1.200,00: la pagina
    // «Nuova voce» la crea di entrata con la parte dell'usufruttuario; qui il tipo si scrive sul conto dello scenario prima della generazione). La quota è emessa a Ugo; l'estinzione dà a ciascuna nuda la
    // sua metà dal 1/4: 120000 × 275/365 = 90410,96 → 90411, a metà 45206 (Nora, prima a pari resto) e 45205; il 1/6 Bice vende a Nora la sua metà, 45205 × 214/275 = 35177,71 → 35178; il 1/9 Nora
    // vende tutto: le due metà arrivate a Nora in giorni diversi sono due famiglie della stessa riga (decisione 56), e ognuna passa per i giorni dal 1/9, 60000 × 122/365 = 20054,79 → 20055, al più
    // quanto è arrivato: 20055 + 20055 = 40110 (€ 401,10). Con l'entrata, tutto con il segno: la coppia a Carlo − 40110, a suo credito. Senza il segno nelle famiglie si prendeva il numero più grande in
    // valore assoluto: min(− 20055, − 45205) + min(− 20055, − 35178) = − 80383 a Carlo, e Nora − 1. La spesa è la guardia: le stesse cifre con il segno più.
    foreach (['spesa' => 1, 'entrata' => -1] as $tipo => $segno) {
        $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
        DB::table('conti')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->update(['tipo' => $tipo]);
        DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
        $nora = cleFamPersona($s, 'Nuda Nora');
        $bice = cleFamPersona($s, 'Nuda Bice');
        foreach ([$nora, $bice] as $nuda) {
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $nuda->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50, 'attivo' => true,
                'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
        ruEmetti($s, '2026-03-31');
        expect(cleFamNetti($s))->toBe(['Venditore Ugo' => $segno * 120000], $tipo);

        cleFamPassa($this, $s, 'estinzione', $s['v'], null, '2026-04-01');
        $aNora = cleFamPassa($this, $s, 'vendita', $bice, $nora, '2026-06-01');
        $carlo = cleFamPersona($s, 'Compratore Carlo');
        $aCarlo = cleFamPassa($this, $s, 'vendita', $nora, $carlo, '2026-09-01');

        expect(collect($aNora['rate']['conguaglio']['coppie'])->pluck('importo')->all())->toBe([$segno * 35178], $tipo)
            ->and(collect($aCarlo['rate']['conguaglio']['coppie'])->pluck('importo')->all())->toBe([$segno * 40110], $tipo)
            ->and(cleFamNetti($s))->toBe(['Compratore Carlo' => $segno * 40110, 'Nuda Bice' => $segno * 10027, 'Nuda Nora' => $segno * 40274, 'Venditore Ugo' => $segno * 29589], $tipo);
        $frasi = fclFrasi($aCarlo);
        expect($frasi)->toContain($segno > 0 ? 'credito € 401,10 a Nuda Nora, debito € 401,10 a Compratore Carlo' : 'credito € 401,10 a Compratore Carlo, debito € 401,10 a Nuda Nora')
            ->and($frasi)->toContain($segno > 0 ? '→ € 200,55 a chi entra' : '→ € 200,55 a credito di chi entra')
            ->and($frasi)->not->toContain('€ 803,83')
            ->and($frasi)->not->toContain('€ 452,05')
            ->and($frasi)->not->toContain('€ 351,78');
    }
});
