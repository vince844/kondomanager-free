<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Services\Import\AnnullamentoImportazione;
use Spatie\Permission\Models\Permission;

/**
 * Il **predicato** dell'annullamento: quando è ancora lecito disfare.
 *
 * Progetto e misure in `docs/annullamento_importazione.md`. Il giro completo — genera, importa,
 * annulla, reimporta — sta in `ImportModelloManualeTest`, dove vivono gli aiutanti che costruiscono
 * il modello: qui c'è la condizione, là c'è la prova che l'archivio torna riusabile.
 *
 * ## Perché quasi tutti i casi sono rifiuti
 *
 * Perché è lì che vive il rischio. Un annullamento che disfa quando può è la parte facile; quello
 * che **si ferma quando non deve disfare** è la funzione. La lezione della beta.45 vale identica:
 * una diagnosi senza cura lascia l'amministratore peggio di prima — quindi ogni rifiuto porta con
 * sé il motivo e cosa fare.
 *
 * ## Cosa questo file NON copre
 *
 * - **Non copre il regime B fino in fondo**: verifica che venga *rifiutato*, non che sappia disfare
 *   un'importazione entrata in un condominio preesistente. Quel lavoro non è stato fatto, ed è una
 *   scelta scritta nel servizio.
 * - **Non copre i rami `unito` e `saltato`**: nel regime A non si presentano, perché il condominio
 *   è nato con l'importazione.
 * - **Non prova la schermata**, solo il servizio e la rotta.
 */
function utenteCheAnnulla(): \App\Models\User
{
    foreach (['Crea condomini', 'Elimina condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = \App\Models\User::factory()->create();
    // ⚠️ **Dal 30/08/2026 serve «Importa dati», non più «Crea condomini».** L'amministratore lo ha
    // per costruzione; qui si concede esplicitamente perché il caso non passa da un ruolo.
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Importa dati', 'guard_name' => 'web']);
    $u->givePermissionTo(['Crea condomini', 'Elimina condomini']);
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u->fresh();
}

/**
 * Un lotto completato che ha **creato** il condominio: il regime A, costruito a mano.
 *
 * ⚠️ Il registro si scrive qui invece di far girare un'importazione vera perché questi casi provano
 * il **predicato**, non il motore: costruire il file, caricarlo e confermarlo per poi guardare una
 * condizione booleana renderebbe il caso lento e fragile su cose che non sta verificando.
 * L'importazione vera la esercita il giro completo, altrove.
 */
function lottoCheHaCreato(Condominio $condominio): ImportBatch
{
    $lotto = ImportBatch::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'condominio_id' => $condominio->id,
        'sorgente' => 'manual',
        'stato' => ImportBatch::STATO_COMPLETATO,
        'completato_at' => now(),
    ]);

    ImportBatchItem::create([
        'import_batch_id' => $lotto->id,
        'livello' => 'condominio',
        'importabile_type' => Condominio::class,
        'importabile_id' => $condominio->id,
        'azione' => ImportBatchItem::AZIONE_CREATO,
    ]);

    return $lotto;
}

it('dice di sì quando il lotto ha creato il condominio e nessuno ci ha ancora lavorato', function () {
    $condominio = Condominio::factory()->create();
    $verdetto = app(AnnullamentoImportazione::class)->verdetto(lottoCheHaCreato($condominio));

    expect($verdetto->possibile)->toBeTrue()
        ->and($verdetto->condominio->id)->toBe($condominio->id);
});

it('⛔ non cancella un condominio che l\'importazione ha soltanto TROVATO', function () {
    // ⚠️ **È la trappola della Coda 96, e il caso più pericoloso di tutta la funzione.**
    // `import_batches.condominio_id` è valorizzato **anche** quando il condominio è stato scelto e
    // non creato: un annullamento che si fidasse di quella colonna cancellerebbe il condominio di
    // qualcun altro, con dentro tutto il suo lavoro. Qui il lotto ce l'ha valorizzato, e l'unica
    // cosa che dice la verità è l'`azione` dell'item.
    $condominio = Condominio::factory()->create(['nome' => 'CONDOMINIO CHE ESISTEVA GIÀ']);

    $lotto = lottoCheHaCreato($condominio);
    $lotto->items()->update(['azione' => ImportBatchItem::AZIONE_UNITO]);

    $verdetto = app(AnnullamentoImportazione::class)->esegui($lotto->fresh());

    expect($verdetto->possibile)->toBeFalse()
        ->and($verdetto->motivo)->toContain('esisteva già')
        // E soprattutto: il condominio è ancora lì.
        ->and(Condominio::whereKey($condominio->id)->exists())->toBeTrue()
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

it('⛔ si ferma quando su quel condominio è già stato registrato altro, e dice cosa', function () {
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    // ⚠️ Il segno di lavoro si scrive con un `insert` diretto, e su una tabella che non trascina
    // altro: una cassa vera pretende un conto contabile, e costruirla passando dai suoi service
    // porterebbe dentro conti e scritture — cioè **altri due impedimenti**, e non si saprebbe più
    // quale dei tre ha fermato l'annullamento. Un caso che prova un predicato deve avere una causa
    // sola.
    \Illuminate\Support\Facades\DB::table('conti_contabili')->insert([
        'condominio_id' => $condominio->id,
        'codice' => '9999',
        'nome' => 'Conto aperto dopo l\'importazione',
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'livello' => 1,
        'attivo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('casse')->insert([
        'condominio_id' => $condominio->id,
        'conto_contabile_id' => \Illuminate\Support\Facades\DB::table('conti_contabili')->max('id'),
        'nome' => 'Banca registrata dopo l\'importazione',
        'tipo' => 'banca',
        'saldo_iniziale' => 0,
        'attiva' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $verdetto = app(AnnullamentoImportazione::class)->esegui($lotto);

    expect($verdetto->possibile)->toBeFalse()
        ->and($verdetto->impedimenti)->toHaveKey('casse')
        ->and($verdetto->impedimenti['casse'])->toBe(1)
        // ⛔ Mai un rifiuto muto: chi legge deve sapere **cosa** lo ferma.
        //
        // ⚠️ Qui l'asserzione era su «storna», perché il messaggio diceva «in contabilità si
        // storna, non si cancella». Tolta il 30/08/2026 su osservazione di Vincenzo: quella era
        // una **politica**, presa da un documento di progetto e mai decisa. Il programma dice cosa
        // fa e cosa lo ferma; cosa farne è dell'amministratore, e finché non è deciso non si
        // scrive a schermo — men che meno lo si fissa in un test.
        ->and($verdetto->aiuto)->toContain('decidi tu')
        ->and(Condominio::whereKey($condominio->id)->exists())->toBeTrue();
});

it('⛔ non annulla un lotto che non ha ancora scritto niente: quello si scarta', function () {
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);
    $lotto->update(['stato' => ImportBatch::STATO_IN_CORSO]);

    $verdetto = app(AnnullamentoImportazione::class)->verdetto($lotto->fresh());

    expect($verdetto->possibile)->toBeFalse()
        ->and($verdetto->aiuto)->toContain('si scarta');
});

it('annulla anche un\'importazione fermatasi a metà, che è il caso in cui serve di più', function () {
    // ⚠️ **Trovato a video il 30/08/2026, e solo a video.** La prima stesura pretendeva
    // `completato` e a un lotto `parziale` rispondeva «non è entrato niente in archivio» — una
    // frase che la stessa schermata smentiva sei righe più sopra, dove diceva «quello che era già
    // entrato resta». Misurato su un'importazione Danea fermatasi ai saldi con **61 record già
    // scritti**: il messaggio mentiva, e mentiva proprio a chi aveva più bisogno di disfare.
    //
    // Il predicato non cambia — ha creato il condominio? ci ha lavorato qualcuno? — quindi non
    // c'era ragione di escludere questo stato, se non che nessuno l'aveva pensato.
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);
    $lotto->update(['stato' => ImportBatch::STATO_PARZIALE]);

    $verdetto = app(AnnullamentoImportazione::class)->esegui($lotto->fresh());

    expect($verdetto->possibile)->toBeTrue()
        ->and(Condominio::whereKey($condominio->id)->exists())->toBeFalse()
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_ANNULLATO);
});

it('conta le cose che spariranno risolvendo i riferimenti, non contando le righe del registro', function () {
    // ⚠️ La differenza non è teorica: il riferimento polimorfo **non ha un vincolo**, quindi il
    // registro sopravvive alle entità. Misurato su quattro lotti veri il 30/08/2026: cancellati i
    // condomìni, 272 righe su 272 erano rimaste penzolanti senza un errore. Un conteggio di righe
    // direbbe all'amministratore un numero vero e una cosa falsa.
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    $viva = Anagrafica::factory()->create();
    foreach ([$viva->id, 999_999] as $id) {
        ImportBatchItem::create([
            'import_batch_id' => $lotto->id,
            'livello' => 'soggetti',
            'importabile_type' => Anagrafica::class,
            'importabile_id' => $id,
            'azione' => ImportBatchItem::AZIONE_CREATO,
        ]);
    }

    $verdetto = app(AnnullamentoImportazione::class)->verdetto($lotto->fresh());

    // Due righe di registro per i soggetti, **una sola** persona che esiste davvero.
    expect($verdetto->conteggi['soggetti'])->toBe(1)
        ->and($verdetto->conteggi['condominio'])->toBe(1);
});

it('la rotta chiede «Elimina condomini»: chi può solo importare non può disfare', function () {
    // ⚠️ Il permesso va **creato** anche se non lo si concede: Spatie solleva un'eccezione su un
    // permesso che non esiste, quindi senza questa riga il caso fallirebbe con un errore invece di
    // dimostrare il 403 — cioè passerebbe per il motivo sbagliato, o rosso per quello sbagliato.
    foreach (['Crea condomini', 'Elimina condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $soloImporta = \App\Models\User::factory()->create();
    $soloImporta->givePermissionTo('Crea condomini');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    $this->actingAs($soloImporta->fresh())
        ->delete(route('import.annulla', $lotto->uuid))
        ->assertForbidden();

    expect(Condominio::whereKey($condominio->id)->exists())->toBeTrue();
});

it('chi ha il permesso disfa davvero, e il lotto resta come traccia', function () {
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    $risposta = $this->actingAs(utenteCheAnnulla())
        ->delete(route('import.annulla', $lotto->uuid));

    $risposta->assertRedirect(route('import.index'));

    // ⛔ **Il messaggio di conferma deve esistere davvero.** Un'operazione distruttiva che riesce
    // in silenzio è indistinguibile da una che non è partita, e l'utente la rifà: è la lezione
    // della beta.49 — *un rifiuto che il server pronuncia e la schermata non mostra vale quanto un
    // rifiuto non pronunciato* — applicata al verso opposto, il successo.
    $risposta->assertSessionHas('message', fn ($m) => str_contains($m['message'] ?? '', 'annullata'));

    expect(Condominio::whereKey($condominio->id)->exists())->toBeFalse()
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_ANNULLATO)
        // ⚠️ **Le due date insieme sono ciò che distingue un annullamento da uno scarto**, senza
        // aggiungere uno stato nuovo: `completato_at` ce l'ha solo un'importazione arrivata in
        // fondo, `annullato_at` solo una disfatta. Un lotto abbandonato ha la seconda e non la prima.
        ->and($lotto->fresh()->completato_at)->not->toBeNull()
        ->and($lotto->fresh()->annullato_at)->not->toBeNull();
});

it('distingue «il condominio non c\'è più» da «il condominio esisteva già»', function () {
    // ⚠️ Sono due rifiuti diversi e vanno detti diversi. Il registro **sopravvive alle entità** —
    // il riferimento polimorfo non ha un vincolo — quindi un lotto può avere il suo item `creato`
    // e puntare a un condominio che qualcuno ha eliminato a mano. La prima stesura del servizio
    // collassava i due casi in un `null` e rispondeva «esisteva già», cioè la frase di un'altra
    // situazione: a chi legge sarebbe sembrato un rifiuto ingiusto invece di una constatazione.
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    $condominio->delete();

    $verdetto = app(AnnullamentoImportazione::class)->verdetto($lotto->fresh());

    expect($verdetto->possibile)->toBeFalse()
        ->and($verdetto->motivo)->toContain('non c\'è più')
        ->and($verdetto->motivo)->not->toContain('esisteva già');
});

it('annullata l\'importazione, la card del gestionale non la racconta più', function () {
    // ⚠️ **Domanda di Vincenzo il 30/08/2026: «se annullo l'importazione deve anche sparire,
    // giusto?».** Sì, e la risposta è verificata invece che dedotta.
    //
    // Nel regime A la domanda quasi non si pone — annullando sparisce il condominio, e con lui la
    // sua dashboard. Ma uno stato in cui il condominio **sopravvive a un lotto annullato** esiste
    // eccome: lo scarto di un'importazione parziale lo produce. Lì la card resterebbe a dire
    // «questo condominio è arrivato da un'importazione» indicando un lotto che è stato buttato.
    //
    // Il presidio c'era già e non era scritto da nessuna parte: `ultimoLotto()` filtra
    // `completato|parziale`, quindi un lotto `annullato` non lo prende. Questo caso lo fissa, così
    // il giorno che qualcuno allarga quel filtro se ne accorge qui.
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);
    $lotto->update(['rapporto' => ['livelli' => []]]);

    $widget = app(\App\Services\Dashboard\Widgets\ControlliPostImportWidget::class);

    expect($widget->isVisible($condominio->id))->toBeTrue();

    $lotto->update(['stato' => ImportBatch::STATO_ANNULLATO, 'annullato_at' => now()]);

    // ⚠️ Un widget nuovo per non leggere la cache interna: la prima chiamata memorizza il lotto
    // per condominio, ed è giusto che lo faccia — ma qui si sta provando il filtro, non la cache.
    expect(app(\App\Services\Dashboard\Widgets\ControlliPostImportWidget::class)->isVisible($condominio->id))
        ->toBeFalse();
});


it('⛔ non cancella in silenzio il lavoro che non è contabile', function () {
    // ⚠️ **Il reperto della revisione avversariale del 30/08/2026, e il più grave della beta.**
    //
    // L'elenco degli impedimenti sorvegliava solo la contabilità — casse, fatture, scritture,
    // piani rate — mentre col condominio scendono a cascata **ventuno** tabelle. Un
    // amministratore che dopo l'importazione avesse caricato un documento, scritto in bacheca,
    // aperto una segnalazione o registrato un contributo si sentiva dire «nessuno ci ha ancora
    // lavorato sopra: si può disfare per intero», e se lo vedeva sparire.
    //
    // ⚠️ **`contributi_versati` è la voce che pesa di più: sono soldi già versati dai condòmini.**
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    expect(app(AnnullamentoImportazione::class)->verdetto($lotto)->possibile)->toBeTrue();

    // Una segnalazione aperta dopo l'importazione: non è contabilità, è lavoro.
    \Illuminate\Support\Facades\DB::table('segnalazioni')->insert([
        'condominio_id' => $condominio->id,
        'subject' => 'Perdita nel garage',
        'description' => 'Aperta dall\'amministratore dopo aver importato il condominio.',
        'created_by' => \App\Models\User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $verdetto = app(AnnullamentoImportazione::class)->esegui($lotto->fresh());

    expect($verdetto->possibile)->toBeFalse()
        ->and($verdetto->impedimenti)->toHaveKey('segnalazioni')
        // E il condominio è ancora lì, con dentro la sua segnalazione.
        ->and(Condominio::whereKey($condominio->id)->exists())->toBeTrue()
        ->and(\Illuminate\Support\Facades\DB::table('segnalazioni')->where('condominio_id', $condominio->id)->count())->toBe(1);
});

it('il verdetto costa lo stesso su un lotto grande e su uno piccolo', function () {
    // ⚠️ **Presidia un N+1 che c'era ed è stato tolto.** Misurato il 30/08/2026 dalla revisione
    // avversariale: **61 item costavano 73 query**, perché il conteggio risolveva ogni riga per
    // conto suo. E il verdetto non si calcola una volta: `esito()` lo rifà a **ogni caricamento**
    // della pagina, quindi il lotto Danea vero — 117 item — ne pagava centotrenta per essere
    // guardato.
    //
    // La soglia non è stretta apposta: non serve fissare il numero esatto, serve che **non cresca
    // con gli item**. Se un giorno tornasse proporzionale, sessanta righe non ci starebbero dentro.
    $condominio = Condominio::factory()->create();
    $lotto = lottoCheHaCreato($condominio);

    foreach (Anagrafica::factory()->count(60)->create() as $a) {
        ImportBatchItem::create([
            'import_batch_id' => $lotto->id, 'livello' => 'soggetti',
            'importabile_type' => Anagrafica::class, 'importabile_id' => $a->id,
            'azione' => ImportBatchItem::AZIONE_CREATO,
        ]);
    }

    $query = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$query) { $query++; });

    $verdetto = app(AnnullamentoImportazione::class)->verdetto($lotto->fresh());

    expect($verdetto->conteggi['soggetti'])->toBe(60)
        ->and($query)->toBeLessThan(25,
            "Il verdetto è costato {$query} query su 61 item: sta risolvendo una riga alla volta."
        );
});
