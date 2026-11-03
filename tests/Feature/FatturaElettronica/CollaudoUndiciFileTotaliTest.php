<?php

use App\Models\Condominio;
use App\Services\FatturaElettronica\FatturaPaParser;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Gestionale/FatturaLifecycleTest.php';

/**
 * **Il collaudo sull'intero fascicolo: undici file veri, dall'upload alla scrittura.**
 *
 * Gli altri test della beta.19 provano il meccanismo su un caso costruito. Questo prova il
 * risultato su **tutti** i file reali del forum, uno per uno, passando dall'endpoint di
 * importazione vero e non da un payload scritto a mano: se un domani una modifica al parser,
 * al controller o al servizio sposta anche un solo centesimo su una sola di queste fatture,
 * qui diventa rosso.
 *
 * La pretesa è la più severa che si possa scrivere su una fattura passiva, ed è una sola:
 * **il totale registrato deve essere uguale all'`ImportoTotaleDocumento` che il fornitore ha
 * scritto nel file.** Non «vicino», non «entro un centesimo» — uguale. È il numero che il
 * fornitore si aspetta di incassare e che l'amministratore ritroverà sull'estratto conto.
 *
 * **Misurato il 06/09/2026, prima di scrivere il test.** Sui rispettivi `DatiRiepilogo`:
 *
 *   file 01 · TD04 nota credito       1.200,00 + 264,00 = 1.464,00
 *   file 02 · allegato base64           180,00 +  18,00 =   198,00
 *   file 03 · ritenuta RT01             135,00 +  13,50 =   148,50
 *   file 04 · righe a zero              129,00 +  12,90 =   141,90
 *   file 05 · forfettario, IVA zero     302,00 +   0,00 =   302,00
 *   file 06 · due riepiloghi             90,09 +  10,06 =   100,15   ← l'unico che cambia
 *   file 07 · ritenuta RT02           1.050,00 + 105,00 = 1.155,00
 *   file 08 · aliquota mista            142,21 +  31,29 =   173,50
 *   file 09 · riga descrittiva a zero   385,00 +  38,50 =   423,50
 *   file 10 · cassa previdenziale     3.360,00 + 739,20 = 4.099,20
 *   file 11 · sconto in riga          4.146,00 + 414,60 = 4.560,60
 *
 * Su dieci di questi l'IVA arrotondata riga per riga coincide con quella dichiarata, e la
 * beta.19 non li tocca: è il controllo di **non regressione**, e vale quanto l'altro. Solo il
 * file 06 divergeva — 10,05 contro 10,06 — e si registrava a € 100,14.
 *
 * ⚠️ **La data del documento viene sostituita con una dell'esercizio di prova.** Sei degli
 * undici file sono datati in un esercizio precedente e prenderebbero la strada del debito
 * pregresso, che ha un pannello suo ed è provata altrove. Qui si misura l'IVA, non il
 * calendario. Allo stesso modo la ritenuta è spenta: sposta il netto da pagare, non il totale
 * del documento, e accenderla confonderebbe due domande in una sola asserzione.
 */
function pagellaCollaudo(): array
{
    return [
        '01-TD04-nota-credito-verso-anno-precedente.xml',
        '02-TD01-allegato-base64.xml',
        '03-TD01-ritenuta-RT01-regime-RF18-terzo-intermediario.xml',
        '04-TD01-ritenuta-righe-a-zero-arrotondamento.xml',
        '05-TD01-forfettario-RF19-bollo-virtuale-N2-2.xml',
        '06-TD01-due-riepiloghi-N2-2-riga-negativa-allegato.xml',
        '07-TD01-ritenuta-RT02-causale-W.xml',
        '08-TD01-allegato-base64-dati-contratto.xml',
        '09-TD01-ritenuta-RT01-riga-descrittiva-a-zero.xml',
        '10-TD01-cassa-previdenziale-TC03-fatture-collegate.xml',
        '11-TD01-sconto-in-riga-allegato.xml',
    ];
}

dataset('undici file di collaudo', pagellaCollaudo());

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = App\Models\User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Porta un file di collaudo dall'upload alla fattura registrata, passando dall'endpoint vero.
 *
 * @return array{0: App\Models\Gestionale\FatturaPassiva, 1: App\DataTransferObjects\FatturaElettronica\FatturaPaFattura, 2: array}
 */
function registraFileDiCollaudo($test, string $nomeFile): array
{
    $xml = file_get_contents(base_path('tests/Fixtures/fatturapa/collaudo_reali/'.$nomeFile));
    $atteso = (new FatturaPaParser)->parse($xml)[0];

    // Il file dichiara un totale: senza quello questi test non avrebbero un metro esterno e
    // finirebbero per confrontare il codice con se stesso.
    expect($atteso->importoTotaleDocumentoCents)
        ->not->toBeNull("il file $nomeFile non dichiara ImportoTotaleDocumento");

    $ctx = setupEcosistemaLifecycle();
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo] = $ctx;

    // L'importatore rifiuta un file intestato a un altro condominio, ed è giusto così: qui il
    // condominio di prova prende il codice fiscale del destinatario vero, invece di modificare
    // il file per farlo passare.
    Condominio::whereKey($condominio->id)->update(['codice_fiscale' => $atteso->cessionarioCodiceFiscale]);

    $risposta = $test->actingAs($test->user)->postJson(
        route('admin.gestionale.fatture.importa-xml', $condominio),
        ['file' => UploadedFile::fake()->createWithContent('fattura.xml', $xml)]
    );

    $risposta->assertOk();
    $dati = $risposta->json();

    // Da qui in poi si ricostruisce ciò che il modulo Vue invia: le righe lette dal file, con
    // un capitolo assegnato, più i riepiloghi dichiarati.
    $corpo = [
        'fornitore_id' => $fornitore->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_documento' => $dati['documento']['tipo_documento'],
        'numero_documento' => $dati['documento']['numero_documento'],
        'data_documento' => '2026-06-01',
        'data_scadenza' => '2026-06-30',
        'modalita_pagamento' => $dati['documento']['modalita_pagamento'] ?? 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'riepiloghi' => $dati['documento']['riepiloghi'],
        'righe' => array_map(fn ($r) => [
            'descrizione' => $r['descrizione'],
            'importo_imponibile' => $r['importo_imponibile'],
            'aliquota_iva' => $r['aliquota_iva'],
            'natura' => $r['natura'],
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ], $dati['righe']),
    ];

    $fattura = app(FatturaPassivaService::class)->registraFattura($corpo, $condominio->id);

    return [$fattura, $atteso, $ctx];
}

it('registra ogni file di collaudo al totale che il documento dichiara', function (string $nomeFile) {
    [$fattura, $atteso] = registraFileDiCollaudo($this, $nomeFile);

    // ① Il numero che conta: quello scritto sul documento.
    expect(abs($fattura->totale_documento))->toBe(
        $atteso->importoTotaleDocumentoCents,
        "$nomeFile: registrata a ".number_format(abs($fattura->totale_documento) / 100, 2)
        .' invece di '.number_format($atteso->importoTotaleDocumentoCents / 100, 2)
    );

    // ② Le sue due metà, prese ciascuna dal riepilogo del fornitore.
    expect(abs($fattura->importo_imponibile))->toBe($atteso->imponibileDichiaratoCents())
        ->and(abs($fattura->importo_iva))->toBe($atteso->impostaDichiarataCents());

    // ③ L'IVA delle righe somma ESATTAMENTE quella di testata. Non è pignoleria: la testata è
    //    una riga della scrittura in partita doppia, e uno scarto qui farebbe respingere
    //    l'intera fattura da DoubleEntryValidator.
    expect($fattura->righe->sum('importo_iva'))->toBe($fattura->importo_iva);

    // ④ Il segno segue il tipo di documento: la nota di credito è un credito.
    $atteso->isNotaCredito()
        ? expect($fattura->totale_documento)->toBeLessThan(0)
        : expect($fattura->totale_documento)->toBeGreaterThan(0);
})->with('undici file di collaudo');

it('riaprire e salvare non sposta di un centesimo nessuno degli undici', function (string $nomeFile) {
    // La strada che si percorre senza pensarci: si riapre una fattura importata per correggere
    // una data e si salva. La modifica **non riceve il file**, quindi non riceve i riepiloghi:
    // senza la memoria in `dati_extra` l'imposta tornerebbe a essere la somma degli
    // arrotondamenti di riga, e la fattura si sgonfierebbe senza che nessuno tocchi un importo.
    [$fattura, $atteso, $ctx] = registraFileDiCollaudo($this, $nomeFile);
    $prima = $fattura->totale_documento;
    $segno = $fattura->tipo_documento === 'nota_credito' ? -1 : 1;

    $corpo = [
        'fornitore_id' => $ctx[3]->id,
        'esercizio_id' => $ctx[1]->id,
        'gestione_id' => $ctx[2]->id,
        'tipo_documento' => $fattura->tipo_documento,
        'numero_documento' => $fattura->numero_documento,
        'data_documento' => '2026-06-01',
        'data_scadenza' => '2026-07-31',   // l'unica cosa che cambia
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        // ⚠️ Nessun `riepiloghi`: è proprio il punto. Il modulo di modifica non li ha.
        //
        // ⚠️ **Le righe viaggiano come MAGNITUDINI, moltiplicate per il segno del documento.**
        // A DB le righe di una nota di credito stanno negative, perché è il servizio ad
        // applicare −1; il modulo invece le mostra e le rimanda positive. Rimandarle negative
        // applicherebbe il segno due volte, e il file 01 — l'unica nota di credito degli undici —
        // tornerebbe positivo. Su una fattura ordinaria il fattore è +1, quindi la riga
        // legittimamente negativa del file 06 resta negativa: è la stessa regola, non un'eccezione.
        'righe' => $fattura->righe->map(fn ($r) => [
            'descrizione' => $r->descrizione,
            'importo_imponibile' => (float) $r->importo_imponibile * $segno / 100,
            'aliquota_iva' => (float) $r->aliquota_iva,
            'natura' => $r->natura,
            'conto_id' => $r->conto_id,
            'is_sopravvenienza' => false,
        ])->toArray(),
    ];

    $aggiornata = app(FatturaPassivaService::class)->aggiornaFattura($fattura->fresh(), $corpo);

    expect($aggiornata->totale_documento)->toBe(
        $prima,
        "$nomeFile: il salvataggio ha ricalcolato invece di ricordare"
    )->and(abs($aggiornata->totale_documento))->toBe($atteso->importoTotaleDocumentoCents)
        ->and($aggiornata->righe->sum('importo_iva'))->toBe($aggiornata->importo_iva);
})->with('undici file di collaudo');

it('lo storno vale esattamente quanto la fattura, su tutti e undici', function (string $nomeFile) {
    // Un centesimo di scarto qui non lo vedrebbe nessuno: le due scritture quadrano ciascuna
    // per sé, e resterebbe un residuo verso il fornitore che nessuna spia accende.
    [$fattura, $atteso, $ctx] = registraFileDiCollaudo($this, $nomeFile);

    // Le righe come le costruisce StornoFatturaController: l'imposta si replica, non si ricalcola.
    $corpoNota = [
        'fornitore_id' => $ctx[3]->id,
        'esercizio_id' => $ctx[1]->id,
        'gestione_id' => $ctx[2]->id,
        // Il controller lo fissa a 'nota_credito' senza guardare l'originale, e va imitato:
        // stornare una nota di credito produce una nota positiva, cioè un ri-addebito, che è
        // l'effetto economico giusto. Vale per il file 01.
        'tipo_documento' => 'nota_credito',
        'numero_documento' => 'STORNO-'.$fattura->numero_documento,
        'data_documento' => '2026-06-02',
        'data_scadenza' => '2026-06-30',
        'modalita_pagamento' => 'bonifico',
        'applica_ritenuta' => false,
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => $fattura->righe->map(fn ($r) => [
            'descrizione' => '[STORNO] '.$r->descrizione,
            // ⚠️ **Niente `abs()`, ed è la ragione per cui questo test esiste.** Lo storno
            // copia le righe così come stanno: raddrizzare la riga negativa del file 06 la
            // farebbe pesare al positivo e la nota di credito verrebbe € 104,55 contro una
            // fattura da € 100,15. Il segno lo mette il moltiplicatore del servizio, una volta sola.
            'importo_imponibile' => (float) $r->importo_imponibile / 100,
            'aliquota_iva' => (float) $r->aliquota_iva,
            'importo_iva_dichiarata' => (float) $r->importo_iva / 100,
            'natura' => $r->natura,
            'conto_id' => $r->conto_id,
            'is_sopravvenienza' => false,
        ])->toArray(),
    ];

    $nota = app(FatturaPassivaService::class)->registraFattura($corpoNota, $ctx[0]->id);

    // ⚠️ **Asserzione ASSOLUTA prima che relativa.** Confrontare solo la nota con la fattura è
    // tautologico: senza la correzione sarebbero sbagliate tutte e due allo stesso modo e il
    // test passerebbe per la ragione sbagliata. Il metro è il numero scritto sul file.
    expect(abs($nota->totale_documento))->toBe(
        $atteso->importoTotaleDocumentoCents,
        "$nomeFile: nota di credito da ".number_format(abs($nota->totale_documento) / 100, 2)
        .' contro una fattura da '.number_format(abs($fattura->totale_documento) / 100, 2)
    );

    // E poi la relazione fra i due: si annullano, senza residuo.
    expect($fattura->totale_documento + $nota->totale_documento)->toBe(0);
})->with('undici file di collaudo');
