<?php

use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/FatturaLifecycleTest.php';

/**
 * **Il deep scan della contabilità, messo alla prova.**
 *
 * ⚠️ **Fino alla 1.11.0-beta.20 questo scanner non aveva nessun test.** È il controllo che
 * l'amministratore lancia per sapere se i conti tengono, e niente provava che vedesse qualcosa: se
 * un giorno avesse smesso di accorgersi delle anomalie avrebbe risposto 🟢 e nessuno l'avrebbe
 * saputo. La beta.19 ha trovato **due presidi che non mordevano** — una guardia inerte e un test
 * simmetrico che restava verde anche cancellando il codice che diceva di provare — e questo non era
 * nemmeno presidiato.
 *
 * I test qui sotto non verificano che i conti siano giusti: verificano che **lo scanner se ne
 * accorga quando non lo sono**. Ogni caso gli mette davanti un'anomalia costruita apposta e pretende
 * che la trovi, più un caso pulito che pretende che **non** la inventi.
 */
function indirizzoDeepScan(int $condominioId): string
{
    return "/admin/test-quadratura/{$condominioId}";
}

beforeEach(function () {
    app()[Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(
        ['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']
    );
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** La bolletta del file 06 dei collaudi, con i riepiloghi che il documento dichiara. */
function registraBollettaConRiepiloghi(array $ctx, string $numero = 'GAS-SCAN')
{
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    return app(FatturaPassivaService::class)->registraFattura([
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => $numero,
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'riepiloghi' => [
            ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 45.74, 'imposta' => 10.06],
        ],
        'righe' => [
            ['descrizione' => 'Trasporto', 'importo_imponibile' => 40.61, 'aliquota_iva' => 22,
                'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Materia gas', 'importo_imponibile' => 6.93, 'aliquota_iva' => 22,
                'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Oneri di sistema', 'importo_imponibile' => -1.80, 'aliquota_iva' => 22,
                'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ], $ctx[0]->id);
}

it('su una contabilità sana non inventa anomalie', function () {
    // ⚠️ Il controesempio, e va per primo: uno scanner che segnala sempre qualcosa è inutile quanto
    // uno che non segnala mai, e i test degli altri casi non lo distinguerebbero.
    $ctx = setupEcosistemaLifecycle();
    registraBollettaConRiepiloghi($ctx);

    $dati = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json();

    expect($dati['status'])->toContain('PERFETTO')
        ->and($dati['anomalie_matematiche'])->toBe([])
        ->and($dati['anomalie_integrita'])->toBe([])
        ->and($dati['confronto_col_documento']['non_quadrano'])->toBe(0);
});

it('vede una scrittura che non quadra', function () {
    // Si sbilancia una scrittura vera modificando una riga a database, come farebbe una corruzione
    // o un difetto: il servizio non lo permetterebbe mai, ed è proprio per questo che lo scanner esiste.
    $ctx = setupEcosistemaLifecycle();
    registraBollettaConRiepiloghi($ctx);

    $riga = DB::table('righe_scritture')
        ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
        ->where('scritture_contabili.condominio_id', $ctx[0]->id)
        ->select('righe_scritture.id', 'righe_scritture.importo')
        ->first();
    DB::table('righe_scritture')->where('id', $riga->id)->update(['importo' => $riga->importo + 1234]);

    $dati = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json();

    expect($dati['status'])->toContain('ANOMALIE')
        ->and($dati['anomalie_matematiche'])->not->toBe([]);
    expect($dati['anomalie_matematiche'][0]['sbilancio'])->toBe('€ 12,34');   // simbolo PRIMA
});

it('vede un disallineamento di mastro fra la riga e la voce di spesa', function () {
    // ⚠️ **Non si prova la riga orfana, e va detto perché.** Lo scanner ha un controllo per la riga
    // «senza `conto_contabile_id`», ma quella colonna è `NOT NULL` a schema: il database rifiuta lo
    // stato con `SQLSTATE[23000]`, quindi il controllo è **irraggiungibile per costruzione**.
    // Resta come difesa contro una corruzione esterna o una migrazione futura che allentasse il
    // vincolo, e va bene — ma un test che lo forzasse con un trucco SQL proverebbe il trucco, non
    // il prodotto. Si prova invece il disallineamento di mastro, che è raggiungibile davvero:
    // basta che qualcuno sposti la voce di spesa su un altro mastro dopo che la scrittura è nata.
    $ctx = setupEcosistemaLifecycle();
    [, , , , $capitolo] = $ctx;
    registraBollettaConRiepiloghi($ctx);

    $altroMastro = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $ctx[0]->id, 'ruolo' => null, 'codice' => 'ALTRO',
        'nome' => 'Un altro mastro', 'tipo' => 'attivo', 'categoria' => 'crediti',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conti')->where('id', $capitolo->id)->update(['conto_contabile_id' => $altroMastro]);

    $dati = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json();

    expect($dati['status'])->toContain('ANOMALIE');
    expect(collect($dati['anomalie_integrita'])->pluck('errore')->implode(' '))
        ->toContain('Disallineamento Mastro');
});

/**
 * ⚠️ **Il quinto controllo: il punto cieco degli altri quattro.**
 *
 * Una fattura registrata per un importo diverso da quello che il fornitore chiede **quadra
 * perfettamente**: i due lati della scrittura sono sbagliati dello stesso ammontare. Prima della
 * beta.20 lo scanner diceva 🟢 su un documento del genere, ed è esattamente la classe di difetto
 * che la beta.19 ha passato la giornata a chiudere.
 */
it('vede una fattura registrata per un importo diverso da quello dichiarato dal fornitore', function () {
    $ctx = setupEcosistemaLifecycle();
    $fattura = registraBollettaConRiepiloghi($ctx, 'GAS-DIVERGE');

    // La fattura è registrata a 55,80 e quadra. Si cambia ciò che il DOCUMENTO dichiara: è la
    // forma in cui il difetto arriva davvero — l'importazione legge un numero e il motore ne
    // registra un altro, senza che nessuna scrittura si sbilanci.
    $dati_extra = $fattura->dati_extra;
    $dati_extra['fiscal']['riepiloghi_dichiarati'] = [
        ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 45.74, 'imposta' => 10.07],
    ];
    FatturaPassiva::whereKey($fattura->id)->update(['dati_extra' => json_encode($dati_extra)]);

    $dati = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json();

    // ⚠️ Le due sezioni che provano il punto cieco: la contabilità è **coerente**...
    expect($dati['anomalie_matematiche'])->toBe([])
        ->and($dati['anomalie_integrita'])->toBe([]);

    // ...e nonostante questo il documento non torna, e adesso lo si vede.
    $confronto = $dati['confronto_col_documento'];
    expect($confronto['fatture_verificate'])->toBe(1)
        ->and($confronto['non_quadrano'])->toBe(1)
        ->and($dati['status'])->toContain('DIVERSO');

    // La forma è quella della decisione D14: un'equazione con i numeri veri, non un badge.
    $controllo = $confronto['controlli'][0];
    expect($controllo['documento'])->toBe('GAS-DIVERGE')
        ->and($controllo['esito'])->toBe('NON quadra')
        ->and($controllo['equazione'])->toContain('€ 55,81')   // dichiarato
        ->and($controllo['equazione'])->toContain('€ 55,80')   // registrato
        ->and($controllo['equazione'])->toContain('≠')
        ->and($controllo['differenza'])->toBe('€ 0,01');
});

it('una fattura che quadra col documento si vede come equazione, non solo come assenza di errori', function () {
    $ctx = setupEcosistemaLifecycle();
    registraBollettaConRiepiloghi($ctx, 'GAS-OK');

    $confronto = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json('confronto_col_documento');

    // D14: ogni riga è una prova verificabile, anche quando l'esito è positivo. Un controllo che si
    // vede solo quando fallisce non permette di sapere se è stato eseguito.
    expect($confronto['controlli'][0]['esito'])->toBe('quadra')
        ->and($confronto['controlli'][0]['equazione'])->toContain('=')
        ->and($confronto['controlli'][0]['differenza'])->toBe('—');
});

it('senza fatture importate lo dice, invece di tacere', function () {
    // Zero controlli non è «tutto a posto»: è «non ho niente da confrontare». Distinguerli è la
    // differenza fra un presidio e un presidio che sembra tale.
    $ctx = setupEcosistemaLifecycle();

    $confronto = $this->actingAs($this->user)->getJson(indirizzoDeepScan($ctx[0]->id))
        ->assertOk()->json('confronto_col_documento');

    expect($confronto['fatture_verificate'])->toBe(0)
        ->and($confronto['controlli'])->toBe([])
        ->and($confronto['nota'])->toContain('Nessuna fattura importata');
});
