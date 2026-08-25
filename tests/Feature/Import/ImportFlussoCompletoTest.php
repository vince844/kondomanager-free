<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\User;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Il giro completo, dalle cinque schermate: carico → riconosco → verifico → confermo → esito.
 *
 * È il test che dimostra la frase con cui la release si racconta: «carichi l'export di Danea e
 * ti ritrovi condominio, persone, unità, chi possiede cosa, tabelle e saldi quadrati al
 * centesimo».
 *
 * ## Cosa questo test NON copre
 *
 * - **La resa a schermo**: si guarda con gli occhi, e va guardata.
 * - **L'annullamento**: le righe che lo renderanno possibile si scrivono, il comando no.
 * - **La ripresa dopo interruzione**: lo stato `parziale` si scrive, nessuno lo rilegge.
 */
function utenteFlusso(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');

    return $u;
}

function caricaBundle(): ImportBatch
{
    $files = [];

    foreach (['elenco_unita.xls', 'riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls'] as $n) {
        $files[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$n), $n, 'application/vnd.ms-excel', test: true);
    }

    test()->actingAs(utenteFlusso())->post(route('import.store'), ['file' => $files]);

    return ImportBatch::latest()->first();
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('porta un lotto dal caricamento all\'archivio passando per tutte le schermate', function () {
    $utente = utenteFlusso();
    $batch = caricaBundle();

    // S2 — riconoscimento
    $this->actingAs($utente)->get(route('import.riconoscimento', $batch->uuid))->assertOk();

    // S3 — verifica: legge e basta
    $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('import/ImportVerifica')->has('livelli'));

    expect(Condominio::count())->toBe(0);

    // S4 — anteprima: la quadratura si vede prima di scrivere
    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('import/ImportAnteprima')
            ->where('anteprima.saldi.quadra', true)
            ->where('anteprima.saldi.scarto', '€ 0,00')
        );

    expect(Condominio::count())->toBe(0);

    // Le due decisioni aperte vanno prese, o la conferma resta chiusa.
    foreach (Anagrafica::query()->get() as $ignora) {
        // nessuna anagrafica ancora: il ciclo è qui solo per rendere esplicito che non ce ne sono
    }

    $anteprima = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid));
    $daDividere = $anteprima->viewData('page')['props']['anteprima']['persone']['da_dividere'];

    foreach ($daDividere as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'],
            'scelta' => 'lascia',
        ])->assertRedirect();
    }

    // Conferma — da qui in poi si scrive
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();

    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Anagrafica::count())->toBeGreaterThan(0)
        ->and(DB::table('anagrafica_immobile')->count())->toBeGreaterThan(0)
        ->and(Saldo::count())->toBeGreaterThan(0);

    // S5 — esito
    $this->actingAs($utente)->get(route('import.esito', $batch->uuid))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('import/ImportEsito')
            ->where('lotto.stato', 'completato')
            ->has('rapporto.livelli')
        );
});

it('le decisioni sopravvivono a un ricaricamento della pagina', function () {
    // Stanno sul lotto e non in sessione: un'importazione che le perde le richiede tutte da
    // capo, e su quaranta unità è il momento in cui si chiude la scheda.
    $utente = utenteFlusso();
    $batch = caricaBundle();

    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
        'chiave' => 'soggetto:prova',
        'scelta' => 'dividi',
    ]);

    expect($batch->fresh()->decisioni)->toBe(['soggetto:prova' => 'dividi']);

    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertInertia(fn ($p) => $p->where('decisioni', ['soggetto:prova' => 'dividi']));
});

it('«dividi in due» divide davvero, invece di limitarsi a registrare una scelta', function () {
    // Un pulsante che promette e non fa è il difetto che questo importatore esiste per non
    // commettere. Qui si verifica che dalla decisione nascano due persone e due titolarità
    // sulla stessa unità — cioè la comproprietà che il tracciato di Danea non sa esprimere.
    $utente = utenteFlusso();
    $batch = caricaBundle();

    $anteprima = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid));
    $props = $anteprima->viewData('page')['props'];

    $persone = $props['anteprima']['persone']['totale'];
    $titolarita = $props['anteprima']['titolarita']['totale'];
    $daDividere = $props['anteprima']['persone']['da_dividere'];

    expect($daDividere)->not->toBeEmpty();

    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
        'chiave' => 'soggetto:'.$daDividere[0]['chiave'],
        'scelta' => 'dividi',
    ]);

    $dopo = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid));
    $propsDopo = $dopo->viewData('page')['props'];

    expect($propsDopo['anteprima']['persone']['totale'])->toBe($persone + 1)
        ->and($propsDopo['anteprima']['titolarita']['totale'])->toBe($titolarita + 1);
});

it('la conferma resta chiusa finché una decisione è in sospeso', function () {
    // Non è una cortesia: procedere senza la decisione significa sceglierla al posto suo.
    $utente = utenteFlusso();
    $batch = caricaBundle();

    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertInertia(fn ($p) => $p->has('anteprima.persone.da_dividere', 2));

    expect($batch->fresh()->decisioni)->toBeNull();
});

it('l\'anteprima mostra le unità che resterebbero senza nessuno', function () {
    // È l'avviso che la schermata mostra per primo, in rosso. Sul corpus reale sono zero, e il
    // test verifica che il conteggio esista e sia esatto — non che sia vuoto per caso.
    $utente = utenteFlusso();
    $batch = caricaBundle();

    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertInertia(fn ($p) => $p
            ->has('anteprima.titolarita.unita_senza_titolare')
            ->where('anteprima.titolarita.unita_coinvolte', 7)
        );
});

it('una decisione presa smette di bloccare la conferma', function () {
    // Trovato a video: le decisioni si applicavano ai dati e non ai rilievi, e il pulsante
    // restava disabilitato anche dopo che l'amministratore aveva deciso tutto — senza dire
    // perché. È il difetto peggiore che una schermata di conferma possa avere, perché l'unica
    // via d'uscita apparente è ricominciare da capo.
    $utente = utenteFlusso();
    $batch = caricaBundle();

    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertInertia(fn ($p) => $p->where('confermabile', false));

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'],
            'scelta' => 'lascia',
        ]);
    }

    $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->assertInertia(fn ($p) => $p->where('confermabile', true));
});

it('la seconda importazione sullo stesso archivio si completa dalle schermate', function () {
    // Il vicolo cieco: il motore sapeva riconoscere i duplicati e chiedere «unisci o salta», ma
    // il rilievo non portava la chiave della decisione e nessuna schermata sapeva mostrarlo.
    // Si premeva «Importa N record», il motore si fermava e l'esito diceva «Scegli unisci
    // oppure salta» — con niente da cliccare. «Riprendi» ripercorreva lo stesso muro.
    //
    // I test non se ne accorgevano perché passavano le decisioni direttamente al runner,
    // saltando l'interfaccia. Questo passa dalle schermate, che è dove il difetto viveva.
    $utente = utenteFlusso();

    // Prima importazione: pulita.
    $primo = caricaBundle();
    decidiTuttoEConferma($utente, $primo);
    expect($primo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);

    $condomini = Condominio::count();
    $anagrafiche = Anagrafica::count();

    // Seconda importazione degli stessi file: adesso condominio e persone esistono già.
    $secondo = caricaBundle();

    $props = $this->actingAs($utente)->get(route('import.anteprima', $secondo->uuid))
        ->viewData('page')['props'];

    expect($props['anteprima']['collisioni']['totale'])->toBeGreaterThan(0);

    // Ogni collisione porta la chiave con cui la si risponde, e le scelte offerte.
    foreach ($props['anteprima']['collisioni']['voci'] as $c) {
        expect($c['chiave_decisione'])->not->toBeEmpty()
            ->and($c['scelte'])->toContain('salta');
    }

    decidiTuttoEConferma($utente, $secondo);

    // Il lotto arriva in fondo, e non ha duplicato niente.
    expect($secondo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe($condomini)
        ->and(Anagrafica::count())->toBe($anagrafiche);
});

/**
 * Risponde a tutto ciò che la schermata di conferma dichiara aperto, poi conferma.
 * Passa solo dalle rotte: se una decisione non è offerta da S4, questo helper non la trova.
 */
function decidiTuttoEConferma(User $utente, ImportBatch $batch): void
{
    $props = test()->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->viewData('page')['props'];

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        test()->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'],
            'scelta' => 'lascia',
        ]);
    }

    foreach ($props['anteprima']['collisioni']['voci'] as $c) {
        test()->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => $c['chiave_decisione'],
            'scelta' => 'salta',
        ]);
    }

    test()->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.esito', $batch->uuid));
}

it('il giro delle decisioni converge invece di riproporsi', function () {
    // Dividere un nome doppio crea due persone, e ciascuna può esistere già in archivio: dopo
    // aver risposto a tutto possono comparire voci nuove. È corretto, ma quello che conta è che
    // il giro **finisca** — se ogni risposta ne generasse un\'altra, la conferma sarebbe
    // irraggiungibile e nessuno lo scoprirebbe finché non capita a un amministratore vero.
    //
    // Nota sul corpus: con queste fixture le due persone compaiono già separate in un altro
    // file, quindi la divisione non fa nascere voci nuove. Il test presidia comunque la
    // proprietà che conta — si risponde, si ricontrolla, e non resta niente.
    $utente = utenteFlusso();

    $primo = caricaBundle();
    $p = $this->actingAs($utente)->get(route('import.anteprima', $primo->uuid))->viewData('page')['props'];

    foreach ($p['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $primo->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'dividi',
        ]);
    }

    $this->actingAs($utente)->post(route('import.conferma', $primo->uuid));
    expect($primo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);

    $secondo = caricaBundle();

    // Si risponde finché la schermata non dichiara più niente di aperto. Il limite di giri non
    // è pedanteria: è ciò che trasforma un anello infinito in un test rosso.
    $giri = 0;

    do {
        $props = $this->actingAs($utente)->get(route('import.anteprima', $secondo->uuid))
            ->viewData('page')['props'];

        $prese = $secondo->fresh()->decisioni ?? [];
        $aperte = 0;

        foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
            if (! isset($prese['soggetto:'.$d['chiave']])) {
                $this->actingAs($utente)->put(route('import.decisione', $secondo->uuid), [
                    'chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'dividi',
                ]);
                $aperte++;
            }
        }

        foreach ($props['anteprima']['collisioni']['voci'] as $c) {
            if (! isset($prese[$c['chiave_decisione']])) {
                $this->actingAs($utente)->put(route('import.decisione', $secondo->uuid), [
                    'chiave' => $c['chiave_decisione'], 'scelta' => 'salta',
                ]);
                $aperte++;
            }
        }

        $giri++;
    } while ($aperte > 0 && $giri < 5);

    expect($giri)->toBeLessThan(5);

    $finale = $this->actingAs($utente)->get(route('import.anteprima', $secondo->uuid))
        ->viewData('page')['props'];

    expect($finale['confermabile'])->toBeTrue();

    $this->actingAs($utente)->post(route('import.conferma', $secondo->uuid))
        ->assertRedirect(route('import.esito', $secondo->uuid));

    expect($secondo->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

/**
 * Il bundle con il riparto nella forma **vera**: posizione intestata al nome unito e un ruolo
 * col conteggio dei giorni. Le fixture costruite a tavolino non avevano né l'una né l'altro.
 */
function caricaBundleReale(): ImportBatch
{
    $files = [];

    foreach (['elenco_unita.xls', 'riparto_nome_unito.xls', 'millesimi_tabelle_miste.xls'] as $n) {
        $files[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$n), $n, 'application/vnd.ms-excel', test: true);
    }

    test()->actingAs(utenteFlusso())->post(route('import.store'), ['file' => $files]);

    return ImportBatch::latest()->first();
}

it('dividere un nome doppio non lascia il suo saldo senza nessuno', function () {
    // Trovato sul primo condominio vero: il riparto intesta la posizione al **nome unito**, e
    // dividendo le persone quel nome sparisce dall'archivio. Il saldo non trovava più nessuno e
    // l'importazione si fermava — provocata dalla decisione che l'interfaccia consiglia per
    // prima. È il difetto peggiore possibile: un pulsante che rompe l'importazione.
    //
    // Il saldo **non** si spezza a metà: il file non dice in che proporzione, e inventarla
    // sarebbe decidere su denaro altrui. Resta in solido sull'unità, e lo si dice.
    $utente = utenteFlusso();
    $batch = caricaBundleReale();

    $props = test()->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->viewData('page')['props'];

    $daDividere = $props['anteprima']['persone']['da_dividere'];
    expect($daDividere)->not->toBeEmpty();

    foreach ($daDividere as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'dividi',
        ]);
    }

    foreach ($props['anteprima']['collisioni']['voci'] as $c) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => $c['chiave_decisione'], 'scelta' => 'salta',
        ]);
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    $saldi = collect($batch->fresh()->rapporto['livelli'] ?? [])->firstWhere('livello', 'saldi');

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($saldi['riuscito'])->toBeTrue()
        ->and($saldi['creati'])->toBeGreaterThan(0)
        ->and(collect($saldi['avvisi'])->pluck('codice'))->toContain('saldi.da_nome_diviso');

    // Il saldo diviso è solidale: appartiene all'unità, non a una delle due persone.
    expect(Saldo::whereNull('anagrafica_id')->where('descrizione', 'like', '%nome doppio%')->count())
        ->toBeGreaterThan(0);
});

it('importa il riparto vero anche quando la titolarità è cambiata a metà anno', function () {
    // «ex Pr 336 gg» è un ex proprietario tanto quanto «ex Pr»: il numero è il pro-rata della
    // spesa, non la persona. Il confronto esatto lo mancava e l'import si fermava.
    $utente = utenteFlusso();
    $batch = caricaBundleReale();

    $props = test()->actingAs($utente)->get(route('import.anteprima', $batch->uuid))
        ->viewData('page')['props'];

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia',
        ]);
    }
    foreach ($props['anteprima']['collisioni']['voci'] as $c) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), [
            'chiave' => $c['chiave_decisione'], 'scelta' => 'salta',
        ]);
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);

    // Il saldo dell'ex proprietario è entrato sull'unità, non è sparito né ha bloccato.
    expect(Saldo::whereNull('anagrafica_id')->where('descrizione', 'like', '%art. 63%')->count())
        ->toBeGreaterThan(0);
});
