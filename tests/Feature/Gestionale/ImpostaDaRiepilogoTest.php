<?php

use App\Services\Gestionale\FatturaPassivaService;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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

require_once __DIR__.'/FatturaLifecycleTest.php';

/**
 * L'IVA di una fattura elettronica è quella che il documento **dichiara** nei propri
 * `DatiRiepilogo`, non la somma degli arrotondamenti di riga.
 *
 * Il caso è misurato, non ipotetico: il file 06 dei collaudi — una bolletta del gas con tre
 * righe al 22 % (40,61 · 6,93 · −1,80) e un secondo gruppo a 0 %/N2.2 — dichiara un imponibile
 * di 45,74 e un'imposta di **10,06** sul primo gruppo. Arrotondando riga per riga si ottiene
 * 8,93 + 1,52 − 0,40 = **10,05**, e il documento veniva registrato a € 100,14 invece di
 * € 100,15. Dieci file di collaudo su undici tornavano al centesimo; questo no.
 *
 * La regola era già scritta nel progetto e il motore la contraddiceva: il docblock di
 * `FatturaPaFattura::imponibileDichiaratoCents()` dice «è questo il numero da usare per
 * registrare», quello di `sommaRigheCents()` dice «usare questo per registrare è un difetto,
 * non una scorciatoia».
 */

/** Il gruppo al 22 % del file 06, con i suoi tre pesi e la sua imposta dichiarata. */
function riepiloghiDelFile06(): array
{
    return [
        ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 45.74, 'imposta' => 10.06],
    ];
}

function corpoBollettaGas(array $ctx, string $numero, bool $conRiepiloghi): array
{
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $righe = [
        ['descrizione' => 'Spesa per il trasporto e la gestione del contatore',
            'importo_imponibile' => 40.61, 'aliquota_iva' => 22, 'natura' => null,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Spesa per la materia gas naturale',
            'importo_imponibile' => 6.93, 'aliquota_iva' => 22, 'natura' => null,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Spesa per Oneri di sistema',
            'importo_imponibile' => -1.80, 'aliquota_iva' => 22, 'natura' => null,
            'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
    ];

    $corpo = [
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
        'righe' => $righe,
    ];

    if ($conRiepiloghi) {
        $corpo['riepiloghi'] = riepiloghiDelFile06();
    }

    return $corpo;
}

it('registra la bolletta del file 06 con l’imposta che il documento dichiara, non con la somma delle righe', function () {
    $ctx = setupEcosistemaLifecycle();

    $fattura = app(FatturaPassivaService::class)
        ->registraFattura(corpoBollettaGas($ctx, 'GAS-06', true), $ctx[0]->id);

    // 45,74 di imponibile + 10,06 di imposta dichiarata = 55,80.
    // Riga per riga farebbe 10,05, cioè 55,79: è il centesimo della Coda 142.
    expect($fattura->importo_imponibile)->toBe(4574)
        ->and($fattura->importo_iva)->toBe(1006)
        ->and($fattura->totale_documento)->toBe(5580);

    // La somma delle imposte di riga vale ESATTAMENTE l'imposta della testata: se non fosse
    // così, DoubleEntryValidator respingerebbe l'intera fattura.
    expect($fattura->righe->sum('importo_iva'))->toBe(1006);

    // Il centesimo di compensazione va alla riga col resto più grande, e la riga negativa
    // resta intatta.
    $perDescrizione = $fattura->righe->pluck('importo_iva', 'descrizione');
    expect($perDescrizione['Spesa per il trasporto e la gestione del contatore'])->toBe(893)
        ->and($perDescrizione['Spesa per la materia gas naturale'])->toBe(153)
        ->and($perDescrizione['Spesa per Oneri di sistema'])->toBe(-40);
});

it('senza riepiloghi calcola riga per riga come ha sempre fatto', function () {
    $ctx = setupEcosistemaLifecycle();

    $fattura = app(FatturaPassivaService::class)
        ->registraFattura(corpoBollettaGas($ctx, 'GAS-MANO', false), $ctx[0]->id);

    // È la fattura digitata a mano: nessun documento dichiara niente, quindi 10,05.
    expect($fattura->importo_iva)->toBe(1005)
        ->and($fattura->totale_documento)->toBe(5579);
});

it('riaprire e salvare non sgonfia la fattura importata', function () {
    // È il difetto ② della beta.18, nella sua forma nuova: la modifica non riceve il file,
    // quindi senza memoria l'imposta tornerebbe a essere la somma delle righe. Bastava
    // correggere una data.
    $ctx = setupEcosistemaLifecycle();
    $service = app(FatturaPassivaService::class);

    $fattura = $service->registraFattura(corpoBollettaGas($ctx, 'GAS-MOD', true), $ctx[0]->id);
    expect($fattura->totale_documento)->toBe(5580);

    // L'imposta dichiarata è stata conservata, ed è da lì che la modifica la ritrova.
    // ⚠️ Il confronto è sui valori, non sui tipi: il giro in JSON riporta l'aliquota `22.0`
    // come intero `22`. È innocuo solo perché la chiave del gruppo si normalizza con
    // `number_format(..., 2)` — senza quello, `22.0` e `22` sarebbero due gruppi diversi e
    // il ripiego della modifica fallirebbe **in silenzio**, tornando al calcolo di riga.
    $conservati = $fattura->dati_extra['fiscal']['riepiloghi_dichiarati'];
    expect($conservati)->toHaveCount(1)
        ->and((float) $conservati[0]['aliquota_iva'])->toBe(22.0)
        ->and($conservati[0]['natura'])->toBeNull()
        ->and((float) $conservati[0]['imposta'])->toBe(10.06);

    // Si riapre e si salva cambiando SOLO la data di scadenza — il gesto banale.
    $corpo = corpoBollettaGas($ctx, 'GAS-MOD', false);   // il form non rimanda i riepiloghi
    $corpo['data_scadenza'] = now()->addDays(30)->format('Y-m-d');

    $aggiornata = $service->aggiornaFattura($fattura->fresh(), $corpo);

    expect($aggiornata->importo_iva)->toBe(1006, 'la modifica ha ricalcolato invece di ricordare')
        ->and($aggiornata->totale_documento)->toBe(5580);
});

it('lo storno è lo specchio esatto, non una riapprossimazione', function () {
    // Con l'imposta distribuita, una nota di credito che ricalcolasse riga per riga varrebbe
    // 100,14 contro una fattura da 100,15. DoubleEntryValidator non lo vedrebbe: le due
    // scritture quadrano ciascuna per sé, e resterebbe un centesimo di credito verso il
    // fornitore che nessuno chiude.
    $ctx = setupEcosistemaLifecycle();
    $service = app(FatturaPassivaService::class);

    $fattura = $service->registraFattura(corpoBollettaGas($ctx, 'GAS-STORNO', true), $ctx[0]->id);

    // Lo storno replica l'imposta registrata riga per riga, come fa StornoFatturaController.
    $corpoNota = corpoBollettaGas($ctx, 'NC-GAS-STORNO', false);
    $corpoNota['tipo_documento'] = 'nota_credito';
    foreach ($fattura->righe as $i => $riga) {
        $corpoNota['righe'][$i]['importo_iva_dichiarata'] = $riga->importo_iva / 100;
    }

    $nota = $service->registraFattura($corpoNota, $ctx[0]->id);

    // ⚠️ **Le asserzioni sono ASSOLUTE, non relative.** Confrontare solo la nota con la
    // fattura è tautologico: senza la correzione sarebbero sbagliate tutte e due allo stesso
    // modo (1005 contro 1005) e il test passerebbe per la ragione sbagliata. Verificato il
    // 06/09/2026 rimettendo il servizio della beta.18: in quella forma questo test restava
    // verde. Il numero da pretendere è 1006, quello che il documento dichiara.
    expect(abs($nota->importo_iva))->toBe(1006)
        ->and(abs($nota->totale_documento))->toBe(5580)
        ->and(abs($fattura->importo_iva))->toBe(1006)
        ->and($fattura->totale_documento + $nota->totale_documento)->toBe(0);
});

it('una riga descrittiva a zero non riceve centesimi', function () {
    // I file 04 e 09 dei collaudi contengono righe a € 0,00. Una riga con lordo zero non
    // produce scrittura contabile; con un centesimo di IVA la produrrebbe SENZA capitolo, e
    // finirebbe nel ramo «impossibile allocare la riga»: 500 con rollback, fattura persa.
    $ctx = setupEcosistemaLifecycle();
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $corpo = corpoBollettaGas($ctx, 'GAS-ZERO', false);
    $corpo['righe'] = [
        ['descrizione' => 'Riepilogo del periodo', 'importo_imponibile' => 0, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Quota A', 'importo_imponibile' => 33.33, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Quota B', 'importo_imponibile' => 33.33, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Quota C', 'importo_imponibile' => 33.34, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
    ];
    $corpo['riepiloghi'] = [
        ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 100.00, 'imposta' => 22.00],
    ];

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $ctx[0]->id);

    $perDescrizione = $fattura->righe->pluck('importo_iva', 'descrizione');
    expect($perDescrizione['Riepilogo del periodo'])->toBe(0)
        ->and($fattura->righe->sum('importo_iva'))->toBe(2200);
});

/**
 * ⚠️ **Due blocchi `DatiRiepilogo` con la stessa coppia (aliquota, natura) si sovrascrivevano.**
 *
 * Trovato dalla Fase 1-bis della beta.19 (06/09/2026): sei lenti su dieci lo hanno segnalato
 * indipendentemente. `distribuisciImpostaDichiarata()` costruiva la mappa per gruppo con
 * un'**assegnazione** invece di una somma, quindi di due blocchi sulla stessa chiave
 * sopravviveva solo l'ultimo. Idem nel gemello TypeScript.
 *
 * **Non serve stabilire se un file così possa arrivare davvero** — su questo la revisione si è
 * divisa, e la refutazione più forte sostiene che le esigibilità IVA che lo genererebbero non
 * riguardano un condominio. Il motivo per correggere è un altro e sta tutto dentro casa nostra:
 * `FatturaPaFattura::impostaDichiarataCents()` somma **tutti** i blocchi con `array_sum`, ed è
 * quel numero che finisce nell'elenco dei file. Sullo stesso documento l'elenco avrebbe detto
 * € 183,00 e la registrazione € 161,00. Due strade dello stesso codice che si contraddicono
 * sono un difetto a prescindere da chi le percorre.
 *
 * Nessuno degli undici file di collaudo ha coppie duplicate (verificato), quindi questo caso è
 * costruito — come prescrive la regola sulle fixture: un dato malformato si costruisce, non si
 * preleva.
 */
it('somma i riepiloghi che condividono la stessa coppia aliquota/natura, invece di tenere solo l’ultimo', function () {
    $ctx = setupEcosistemaLifecycle();
    [, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    $corpo = [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'DUE-GRUPPI-UGUALI',
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(15)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        // Due blocchi sulla stessa coppia: 22 % senza natura. Insieme dichiarano 33,00 di imposta.
        'riepiloghi' => [
            ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 100.00, 'imposta' => 22.00],
            ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 50.00, 'imposta' => 11.00],
        ],
        'righe' => [
            ['descrizione' => 'Prestazione A', 'importo_imponibile' => 100.00, 'aliquota_iva' => 22,
                'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
            ['descrizione' => 'Prestazione B', 'importo_imponibile' => 50.00, 'aliquota_iva' => 22,
                'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ],
    ];

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $ctx[0]->id);

    // Tenendo solo l'ultimo blocco l'IVA sarebbe 1100 e il totale 16100.
    expect($fattura->importo_iva)->toBe(3300)
        ->and($fattura->totale_documento)->toBe(18300)
        ->and($fattura->righe->sum('importo_iva'))->toBe(3300);

    // E la distribuzione resta proporzionale ai due imponibili: 2/3 e 1/3.
    $perDescrizione = $fattura->righe->pluck('importo_iva', 'descrizione');
    expect($perDescrizione['Prestazione A'])->toBe(2200)
        ->and($perDescrizione['Prestazione B'])->toBe(1100);
});

/**
 * ⚠️ **L'imposta dichiarata descrive un imponibile dichiarato: è una frase sola.**
 *
 * Trovato dalla Fase 1-bis della beta.19 da quattro lenti indipendenti. `riepiloghi[].imponibile`
 * viaggiava, veniva validato, veniva salvato in `dati_extra` — e **non lo leggeva nessuno**. Il
 * servizio prendeva solo `imposta`, e la applicava a righe qualsiasi: bastava che l'amministratore
 * cambiasse un importo dopo l'importazione perché un numero che descriveva righe precise finisse
 * su righe che non erano più quelle.
 *
 * La regola è per **gruppo**, non per documento: toccare una riga al 22 % non deve disturbare il
 * gruppo a 0 %/N2.2.
 */
it('tiene l’imposta dichiarata quando una riga si spezza fra due capitoli', function () {
    // Il gesto legittimo più comune: la stessa spesa addebitata a due voci. La somma del gruppo
    // non cambia, quindi l'imposta del documento vale ancora e il centesimo non si perde.
    $ctx = setupEcosistemaLifecycle();
    [, , , , $capitolo] = $ctx;

    $corpo = corpoBollettaGas($ctx, 'GAS-SPEZZATA', true);
    $corpo['righe'] = [
        ['descrizione' => 'Trasporto — quota A', 'importo_imponibile' => 20.00, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Trasporto — quota B', 'importo_imponibile' => 20.61, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Spesa per la materia gas naturale', 'importo_imponibile' => 6.93, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
        ['descrizione' => 'Spesa per Oneri di sistema', 'importo_imponibile' => -1.80, 'aliquota_iva' => 22,
            'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false],
    ];

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $ctx[0]->id);

    // 20,00 + 20,61 + 6,93 − 1,80 = 45,74: la somma del gruppo è quella dichiarata.
    expect($fattura->importo_imponibile)->toBe(4574)
        ->and($fattura->importo_iva)->toBe(1006)
        ->and($fattura->totale_documento)->toBe(5580);
});

it('rinuncia all’imposta dichiarata appena le righe del gruppo non sommano più al suo imponibile', function () {
    // L'amministratore corregge un importo dopo l'importazione: da quel momento il documento non
    // descrive più quello che si sta registrando, e l'imposta torna a calcolarsi da ciò che si vede.
    $ctx = setupEcosistemaLifecycle();
    [, , , , $capitolo] = $ctx;

    $corpo = corpoBollettaGas($ctx, 'GAS-CORRETTA', true);
    $corpo['righe'][0]['importo_imponibile'] = 50.00;   // era 40,61

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $ctx[0]->id);

    // Il gruppo somma 55,13, non i 45,74 dichiarati: si ricalcola riga per riga.
    // 50,00 → 11,00 · 6,93 → 1,52 · −1,80 → −0,40 = 12,12
    expect($fattura->importo_imponibile)->toBe(5513)
        ->and($fattura->importo_iva)->toBe(1212)
        ->and($fattura->totale_documento)->toBe(6725);
});

it('la rinuncia è per gruppo: toccare il 22 % non disturba il gruppo a zero', function () {
    // ⚠️ È il controesempio che dà senso alla regola: se la rinuncia fosse per documento, un
    // ritocco su una riga al 22 % spegnerebbe anche l'altro gruppo.
    $ctx = setupEcosistemaLifecycle();
    [, , , , $capitolo] = $ctx;

    $corpo = corpoBollettaGas($ctx, 'GAS-DUE-GRUPPI', true);
    $corpo['riepiloghi'] = [
        ['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 45.74, 'imposta' => 10.06],
        ['aliquota_iva' => 10.0, 'natura' => null, 'imponibile' => 100.00, 'imposta' => 10.05],
    ];
    $corpo['righe'][] = ['descrizione' => 'Voce al 10%', 'importo_imponibile' => 60.00, 'aliquota_iva' => 10,
        'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false];
    $corpo['righe'][] = ['descrizione' => 'Altra voce al 10%', 'importo_imponibile' => 40.00, 'aliquota_iva' => 10,
        'natura' => null, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false];
    $corpo['righe'][0]['importo_imponibile'] = 50.00;   // rompe SOLO il gruppo al 22 %

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $ctx[0]->id);

    $perDescrizione = $fattura->righe->pluck('importo_iva', 'descrizione');

    // Il 22 % si ricalcola: 11,00 + 1,52 − 0,40.
    expect($perDescrizione['Spesa per la materia gas naturale'])->toBe(152);
    // Il 10 % tiene l'imposta dichiarata — 10,05 distribuiti 60/40, non 10,00 ricalcolati.
    expect($perDescrizione['Voce al 10%'] + $perDescrizione['Altra voce al 10%'])->toBe(1005);
});
