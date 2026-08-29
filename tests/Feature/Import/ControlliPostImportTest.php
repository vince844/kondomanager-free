<?php

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Tabella;
use App\Services\Import\Controlli\CatalogoControlli;
use App\Services\Import\Controlli\ControlliPostImport;
use App\Services\Import\Controlli\StatoControllo;
use Illuminate\Support\Facades\DB;

/**
 * «Da controllare dopo l'importazione»: i consigli dell'esito, ritrovabili giorni dopo.
 *
 * Il punto di questi test non è che la lista esista — è che **non menta**. Una checklist che si
 * spunta solo a mano si spunta anche quando non si è fatto niente, e in tre settimane diventa un
 * elenco di cose già fatte che nessuno ripulisce.
 *
 * ## Cosa questi test NON coprono
 *
 * - **La resa a schermo**: si guarda con gli occhi, ed è stata guardata.
 * - **La concorrenza vera** fra due collaboratori che spuntano nello stesso istante: il
 *   `lockForUpdate` c'è, ma su SQLite in memoria non si può provocare la collisione.
 * - **Il cruscotto**: il richiamo dalla dashboard non è ancora costruito.
 */
function lottoConAvviso(array $avvisi, string $livello = 'tabelle'): ImportBatch
{
    $condominio = Condominio::factory()->create();

    return ImportBatch::create([
        'sorgente' => 'danea',
        'stato' => ImportBatch::STATO_COMPLETATO,
        'condominio_id' => $condominio->id,
        'completato_at' => now(),
        'rapporto' => [
            'livelli' => [[
                'livello' => $livello,
                'etichetta' => 'Tabelle millesimali',
                'creati' => 1, 'uniti' => 0, 'saltati' => 0,
                'riuscito' => true, 'gia_a_posto' => false,
                'prerequisiti_mancanti' => [], 'rilievi' => [],
                'avvisi' => $avvisi,
            ]],
            'generato_il' => now()->toIso8601String(),
        ],
    ]);
}

it('non chiede di spuntare a mano ciò che sa ricontrollare da solo', function () {
    // È il cuore della scelta: «le tabelle sono collegate a un capitolo?» è una query, non una
    // domanda da fare all'amministratore. Se gli si chiede la spunta, la spunta arriva anche
    // quando non ha fatto niente — e la lista da quel momento è un elenco di bugie.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'tabelle.non_collegate_ai_capitoli',
        'messaggio' => '1 tabella è entrata senza collegamento a un capitolo di spesa.',
        'rimedio' => 'Il collegamento si fa dal piano dei conti.',
        'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]]);

    $tabella = Tabella::create([
        'condominio_id' => $lotto->condominio_id,
        'nome' => 'Scale', 'tipo' => 'scale', 'quota' => 'millesimi',
        'numero_decimali' => 2, 'attiva' => true,
    ]);

    ImportBatchItem::create([
        'import_batch_id' => $lotto->id,
        'livello' => 'tabelle',
        'azione' => ImportBatchItem::AZIONE_CREATO,
        'importabile_type' => Tabella::class,
        'importabile_id' => $tabella->id,
    ]);

    $voci = (new ControlliPostImport)->perLotto($lotto);

    expect($voci)->toHaveCount(1)
        ->and($voci[0]['verificabile'])->toBeTrue()
        ->and($voci[0]['stato'])->toBe(StatoControllo::Aperto->value)
        // La frase viva dice cosa manca adesso, non cosa diceva il file quel giorno.
        ->and($voci[0]['titolo'])->toContain('ancora senza capitolo')
        ->and($voci[0]['messaggio_originale'])->toContain('è entrata senza collegamento');
});

it('si chiude da sola quando il problema è stato sistemato davvero', function () {
    // Nessun cron, nessun rinfresco: lo stato non è mai scritto, si ricalcola leggendo.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'tabelle.non_collegate_ai_capitoli',
        'messaggio' => '1 tabella è entrata senza collegamento a un capitolo di spesa.',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]]);

    // Nessuna tabella creata da questo lotto: la domanda non ha più oggetto.
    $voci = (new ControlliPostImport)->perLotto($lotto);

    expect($voci[0]['stato'])->toBe(StatoControllo::Risolto->value);
});

it('rifiuta la spunta a mano su una voce che si verifica da sola', function () {
    // Sarebbe un verde falso destinato a sopravvivere al problema — e il rifiuto dice cosa fare
    // invece, altrimenti sarebbe una porta chiusa senza indicazione.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'titolarita.unita_senza_titolare',
        'messaggio' => '4 unità sono rimaste senza nessun titolare.',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]], 'titolarita');

    $utente = utenteGestionale();

    $this->actingAs($utente)
        // La rotta lega {batch} allo uuid (getRouteKeyName()), lo stesso valore che
        // ControlliPostImport::perLotto() manda al client nel campo 'lotto'. Passare
        // $lotto->id qui aggirerebbe esattamente il bug che questo test deve intercettare:
        // ogni pulsante della pagina passava l'uuid e il binding cercava un id intero.
        ->put(route('admin.gestionale.controlli-import.aggiorna', [$lotto->condominio_id, $lotto->uuid]), [
            'chiave' => 'titolarita:titolarita.unita_senza_titolare',
            'azione' => 'spunta',
        ])
        ->assertRedirect();

    expect($lotto->fresh()->rapporto['controlli'] ?? [])->toBe([]);
});

it('accetta «non mi riguarda» su qualunque voce, anche verificabile', function () {
    // La regola «una voce verificabile non è spuntabile» è giusta e irrita: senza una valvola,
    // la prima riga che si incastra diventa una richiesta al supporto. Una riga che non si può
    // chiudere è l'immagine speculare del pulsante che promette e non fa.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'titolarita.unita_senza_titolare',
        'messaggio' => '4 unità sono rimaste senza nessun titolare.',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]], 'titolarita');

    $this->actingAs(utenteGestionale())
        // La rotta lega {batch} allo uuid (getRouteKeyName()), lo stesso valore che
        // ControlliPostImport::perLotto() manda al client nel campo 'lotto'. Passare
        // $lotto->id qui aggirerebbe esattamente il bug che questo test deve intercettare:
        // ogni pulsante della pagina passava l'uuid e il binding cercava un id intero.
        ->put(route('admin.gestionale.controlli-import.aggiorna', [$lotto->condominio_id, $lotto->uuid]), [
            'chiave' => 'titolarita:titolarita.unita_senza_titolare',
            'azione' => 'metti_da_parte',
        ]);

    $voci = (new ControlliPostImport)->perLotto($lotto->fresh());

    expect($voci[0]['stato'])->toBe(StatoControllo::MessoDaParte->value);
});

it('la spunta non tocca mai la registrazione di cosa è successo', function () {
    // `rapporto['livelli']` è la ricevuta di come è andata quell'operazione: si scrive
    // una volta al commit e non si riscrive mai più.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'tabella.parziale',
        'messaggio' => '«Scale» riguarda 6 unità su 11.',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]]);

    $prima = $lotto->rapporto['livelli'];

    $lotto->segna('tabelle:tabella.parziale', StatoControllo::Spuntato->value, 1);

    expect($lotto->fresh()->rapporto['livelli'])->toBe($prima)
        ->and($lotto->fresh()->rapporto['controlli']['tabelle:tabella.parziale']['stato'])
        ->toBe(StatoControllo::Spuntato->value);
});

it('raggruppa gli avvisi che nascono per riga invece di elencarli tutti', function () {
    // Quaranta unità senza codice fiscale sono quaranta rilievi. Senza raggruppamento la lista
    // è inutilizzabile alla prima apertura, e il rumore fa saltare anche le voci che contano.
    $avvisi = [];

    foreach (range(1, 40) as $riga) {
        $avvisi[] = [
            'severita' => 'avviso',
            'codice' => 'unita.interno_dedotto',
            'messaggio' => 'Interno dedotto dal progressivo.',
            'rimedio' => null, 'riga' => $riga, 'colonna' => null, 'chiave_decisione' => null,
        ];
    }

    $voci = (new ControlliPostImport)->perLotto(lottoConAvviso($avvisi, 'unita'));

    expect($voci)->toHaveCount(1)
        ->and($voci[0]['quante'])->toBe(40)
        ->and($voci[0]['righe'])->toHaveCount(40);
});

it('ogni codice del catalogo ha un verificatore oppure un perché scritto', function () {
    // È l'unica difesa contro il decadimento: senza, il prossimo livello aggiunge un avviso, il
    // ramo di default lo assorbe in silenzio e la lista ricomincia a mentire. Qui il codice
    // nuovo non sparisce — fa fallire questo test.
    foreach (CatalogoControlli::voci() as $codice => $voce) {
        expect($voce->verificatore !== null || $voce->perche !== null)
            ->toBeTrue("Il codice «{$codice}» non ha né un verificatore né un perché.");
    }
});

it('ogni codice di avviso che il motore produce è nel catalogo', function () {
    // Il catalogo è l'unico cancello: un codice che non ci passa esce dalla lista senza che se
    // ne accorga nessuno. Si cercano i codici veri nel sorgente, non in un elenco copiato a mano.
    $file = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Services/Import')),
    );

    $sorgente = [];

    foreach ($file as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/Rilievo::avviso\(\s*'([a-z_]+\.[a-z_]+)'/", (string) file_get_contents($f->getPathname()), $m);
        $sorgente = [...$sorgente, ...$m[1]];
    }

    // Senza questa riga il test passerebbe anche se la scansione non trovasse niente — cioè
    // proprio nel caso in cui ha smesso di controllare qualcosa.
    expect(array_unique($sorgente))->toHaveCount(28);

    $catalogo = array_keys(CatalogoControlli::voci());
    $fuori = array_values(array_diff(array_unique($sorgente), $catalogo));

    expect($fuori)->toBe([], 'Codici di avviso fuori dal catalogo: '.implode(', ', $fuori));
});

function utenteGestionale(): App\Models\User
{
    foreach (['Accesso pannello amministratore'] as $nome) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = App\Models\User::factory()->create();
    $u->givePermissionTo('Accesso pannello amministratore');

    return $u;
}

it('il cruscotto mostra il richiamo solo finché c\'è qualcosa di aperto', function () {
    // Senza questo richiamo la lista si raggiunge solo dalla schermata di esito — cioè da una
    // pagina che si vede una volta e non si ritrova più, che è il problema che la lista esiste
    // per risolvere. E deve sparire da solo: un condominio migrato mesi fa non deve portarsi in
    // giro un riquadro che non serve più.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'tabella.parziale',
        'messaggio' => '«Scale» riguarda 6 unità su 11.',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]]);

    $widget = app(App\Services\Dashboard\Widgets\ControlliPostImportWidget::class);

    expect($widget->isVisible($lotto->condominio_id))->toBeTrue()
        ->and($widget->payload($lotto->condominio_id)['totale'])->toBe(1);

    $lotto->segna('tabelle:tabella.parziale', StatoControllo::Spuntato->value, 1);

    // Istanza nuova: la memoizzazione è per richiesta, non un fatto del dominio.
    expect(app(App\Services\Dashboard\Widgets\ControlliPostImportWidget::class)
        ->isVisible($lotto->condominio_id))->toBeFalse();
});

it('un condominio mai importato non paga nessun verificatore', function () {
    // La guardia economica: una query indicizzata e si esce. Senza, ogni cruscotto di ogni
    // condominio pagherebbe sei query per una lista che non ha.
    $condominio = Condominio::factory()->create();
    $widget = app(App\Services\Dashboard\Widgets\ControlliPostImportWidget::class);

    DB::enableQueryLog();
    $visibile = $widget->isVisible($condominio->id);
    $query = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($visibile)->toBeFalse()->and($query)->toBe(1);
});

it('il rapporto si scarica in PDF, con lo stato dei controlli di oggi', function () {
    // Il rapporto viveva solo dentro la sua pagina: chi doveva darne conto al collega o
    // all'assemblea non aveva niente in mano. Ed è il deliverable del servizio di migrazione
    // assistita — senza un file, «l'abbiamo fatta noi» resta una parola.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'tabella.parziale',
        'messaggio' => '«Scale» riguarda 6 unità su 11.',
        'rimedio' => 'Le unità senza valore non vi partecipano.',
        'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]]);

    $risposta = $this->actingAs(utenteRapporto())->get(route('import.rapporto', $lotto->uuid));

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Un PDF vero comincia con «%PDF-»: senza questo controllo passerebbe anche una risposta
    // vuota con l'intestazione giusta.
    expect($risposta->getContent())->toStartWith('%PDF-')
        ->and(strlen($risposta->getContent()))->toBeGreaterThan(1000);
});

it('non produce un PDF per un\'importazione che non è mai arrivata in fondo', function () {
    // Un file vuoto che sembra un rapporto è peggio di un 404: si allega e nessuno lo riapre.
    $lotto = ImportBatch::create(['sorgente' => 'danea', 'stato' => ImportBatch::STATO_IN_CORSO]);

    $this->actingAs(utenteRapporto())->get(route('import.rapporto', $lotto->uuid))->assertNotFound();
});

/**
 * Il rapporto sta dietro il permesso dell'import, non del gestionale: è il documento di quella
 * sessione, e chi non poteva importare non ha una sessione di cui rendere conto.
 */
function utenteRapporto(): App\Models\User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore'] as $nome) {
        Spatie\Permission\Models\Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = App\Models\User::factory()->create();
    $u->givePermissionTo('Crea condomini');

    return $u;
}

it('non considera «a posto» un\'unità che ha solo un inquilino', function () {
    // Il difetto peggiore che questa lista possa avere: un verde falso su un problema che
    // ricompare più tardi, come eccezione, lontano dall'importazione che l'ha causato.
    //
    // Il conteggio guardava ogni riga di `anagrafica_immobile`, inquilino compreso. Ma per il
    // motore di riparto un'unità col solo conduttore è invisibile: `CalcoloQuoteService`
    // esaurisce la cascata e mette il peso fra gli scoperti (nessuno paga quella quota), e
    // `GenerateSaldiAction::risolviTitolari()` solleva SaldoSolidaleSenzaTitolareException,
    // bloccando l'emissione del piano rate.
    //
    // La regola («verso il condominio risponde chi ha un diritto reale, non il conduttore»)
    // esiste dalla beta.43 in RuoloAnagraficaImmobile::titolariDiDirittoReale(): il codice
    // dell'import semplicemente non la usava.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'titolarita.unita_senza_titolare',
        'messaggio' => "1 unità è rimasta senza un titolare di diritto reale.",
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]], 'titolarita');

    $immobile = App\Models\Immobile::create([
        'condominio_id' => $lotto->condominio_id,
        'nome' => 'Unità solo affittata',
        'descrizione' => 'Unità solo affittata',
        'interno' => '1',
    ]);

    ImportBatchItem::create([
        'import_batch_id' => $lotto->id,
        'livello' => 'titolarita',
        'azione' => ImportBatchItem::AZIONE_CREATO,
        'importabile_type' => App\Models\Immobile::class,
        'importabile_id' => $immobile->id,
    ]);

    $inquilino = App\Models\Anagrafica::create(['nome' => 'CONDUTTORE', 'indirizzo' => 'Via Affitto 1']);

    DB::table('anagrafica_immobile')->insert([
        'immobile_id' => $immobile->id,
        'anagrafica_id' => $inquilino->id,
        'tipologia' => App\Enums\RuoloAnagraficaImmobile::INQUILINO->value,
        'quota' => 100.00,
        'data_inizio' => now()->toDateString(),
        'attivo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $voci = (new ControlliPostImport)->perLotto($lotto);

    // Con il vecchio criterio questa voce risultava «risolto»: verde, e il problema restava.
    expect($voci[0]['stato'])->toBe(StatoControllo::Aperto->value)
        ->and($voci[0]['titolo'])->toContain('diritto reale');

    // Assegnare un proprietario la chiude — senza che nessuno debba spuntarla a mano.
    $proprietario = App\Models\Anagrafica::create(['nome' => 'PROPRIETARIO', 'indirizzo' => 'Via Proprieta 1']);

    DB::table('anagrafica_immobile')->insert([
        'immobile_id' => $immobile->id,
        'anagrafica_id' => $proprietario->id,
        'tipologia' => App\Enums\RuoloAnagraficaImmobile::PROPRIETARIO->value,
        'quota' => 100.00,
        'data_inizio' => now()->toDateString(),
        'attivo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new ControlliPostImport)->perLotto($lotto->fresh())[0]['stato'])
        ->toBe(StatoControllo::Risolto->value);
});

it('il PDF distingue «non verificabile» da «non quadrano»', function () {
    // Il riquadro della quadratura aveva due soli rami, e `quadra` è falso sia quando lo
    // scarto non è zero sia quando **non è mai stato calcolato** — cioè quando il riparto non
    // portava la riga «TOTALE COMPLESSIVO», caso previsto e gestito dal parser con un avviso
    // non bloccante. Un file senza totale usciva quindi rosso, con «non quadrano» in
    // grassetto: un documento che accusa al posto di dichiarare un limite, su un foglio che
    // l'amministratore può allegare a un verbale.
    $lotto = lottoConAvviso([[
        'severita' => 'avviso',
        'codice' => 'riparto.totale_assente',
        'messaggio' => 'Il riparto non ha la riga «TOTALE COMPLESSIVO».',
        'rimedio' => null, 'riga' => null, 'colonna' => null, 'chiave_decisione' => null,
    ]], 'saldi');

    // Scarto null = non calcolabile; quadra false, ma per assenza di riferimento, non per errore.
    $rapporto = $lotto->rapporto;
    $rapporto['saldi'] = ['scarto' => null, 'quadra' => false];
    $lotto->update(['rapporto' => $rapporto]);

    $pdf = $this->actingAs(utenteRapporto())->get(route('import.rapporto', $lotto->uuid));

    $pdf->assertOk();

    // mpdf comprime il contenuto, quindi non si può cercare il testo nel PDF: si verifica
    // invece che la vista Blade renda il ramo giusto, che è dove viveva il difetto.
    $html = view('pdf.import.rapporto', [
        'condominio' => $lotto->condominio,
        'lotto' => $lotto,
        'livelli' => $lotto->rapporto['livelli'],
        'saldi' => $lotto->rapporto['saldi'],
        'creati' => 0,
        'controlli' => (new ControlliPostImport)->perLotto($lotto),
        'etichetteStato' => ['aperto' => 'da fare'],
        'virgolette' => fn (string $t) => $t,
    ])->render();

    expect($html)->toContain('non verificabile')
        ->and($html)->not->toContain('non quadrano');
});
