<?php

/**
 * # Il condominio dimostrativo si costruisce, si legge e si rimuove
 *
 * ## Perché questo file è più importante del solito
 *
 * Il seeder dimostrativo ha un difetto strutturale che nessun'altra parte del progetto ha: **è
 * l'unica cosa che invecchia in silenzio**. Se una beta cambia un service, il seeder continua a
 * girare e produce un condominio che mostra il programma di sei mesi fa — o peggio, fallisce a metà
 * e nessuno se ne accorge, perché i suoi passi sono protetti da un `try` che avvisa invece di far
 * saltare tutto.
 *
 * ⚠️ **La contromisura è che il seeder passa dai service veri**, non da `DB::table()->insert()`. Ma
 * quella scelta protegge solo se qualcuno controlla che i service non lo respingano: questo file è
 * quel qualcuno. **Non ci sono avvisi accettabili.**
 *
 * ## Cosa questi test hanno già trovato, costruendolo
 *
 * Tutti difetti che un seeder scritto a mano avrebbe prodotto senza accorgersene:
 *
 * - una voce di spesa senza `conto_contabile_id` **non si può fatturare** («Integrità
 *   compromessa»): `ContoController` lo assegna, un `Conto::create()` a mano no;
 * - `FatturaPassivaService` legge `modalita_pagamento` **senza valore di ripiego**, perché la
 *   richiesta vera la garantisce;
 * - un piano rate deve dichiarare **quali voci copre** (pivot `piano_rate_capitoli`), altrimenti il
 *   cruscotto lo dà per disallineato e la demo si apre con un allarme rosso.
 *
 * ## Cosa NON copre
 *
 * Non giudica se il condominio è *interessante*: dice che è **completo e coerente**. Che mostri le
 * cose giuste è una scelta editoriale, ed è scritta nel perimetro in testa al seeder.
 */

use App\Actions\Condominio\CreaCondominioDimostrativoAction;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ⚠️ **I dati di riferimento vanno seminati prima, e la ragione è istruttiva.**
 *
 * Le categorie degli eventi e le tipologie di immobile sono dati che nascono
 * **all'installazione** e che il programma dà per esistenti: registrare una fattura crea un evento,
 * e un evento senza la sua categoria non si scrive. Sul database vero ci sono; qui `RefreshDatabase`
 * li azzera a ogni prova.
 *
 * Non è il seeder dimostrativo a doverli creare — non è compito suo — ma senza di loro questi test
 * proverebbero uno scenario che nessuna installazione reale ha.
 */
beforeEach(function () {
    $this->seed([
        \Database\Seeders\CategoriaEventoSeeder::class,
        \Database\Seeders\TipologieImmobiliSeeder::class,
    ]);

    // ⚠️ **E serve un utente autenticato.** Registrare una fattura crea un'attività in agenda, e
    // quell'attività ha un autore: nel prodotto è l'amministratore che preme il pulsante. Senza,
    // la scrittura fallisce su una chiave esterna — un fallimento che non dice niente sul seeder
    // e tutto sull'ambiente in cui gira.
    // ⚠️ E con i permessi di un amministratore: le rotte di eliminazione passano da `Gate`, e un
    // utente senza ruolo riceve 403 — cioè il test fallirebbe per l'autorizzazione mentre sembra
    // parlare della cancellazione.
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        \Spatie\Permission\Models\Permission::findOrCreate($permesso->value, 'web');
    }
    $ruolo->syncPermissions(\App\Enums\Permission::cases());

    $utente = \App\Models\User::factory()->create();
    $utente->assignRole($ruolo);
    $this->actingAs($utente);
});

function creaDimostrativo(): array
{
    return app(CreaCondominioDimostrativoAction::class)->esegui();
}

it('⚠️ si costruisce per intero, senza un solo avviso', function () {
    $esito = creaDimostrativo();

    expect($esito['avvisi'])->toBe([],
        "Il condominio dimostrativo si è costruito solo in parte:\n\n".
        implode("\n", array_map(fn ($a) => '  '.$a, $esito['avvisi']))."\n\n".
        "⚠️ **Ogni passo del seeder passa da un service vero**, quindi un avviso qui significa che un\n".
        "service ha respinto quello che il seeder gli manda — cioè che il payload non è più quello\n".
        "che il prodotto si aspetta. Non si tratta di aggiustare il seeder finché tace: va guardato\n".
        "**cosa** ha rifiutato, perché è la stessa cosa che rifiuterebbe a un amministratore.\n\n".
        'I passi sono protetti da un `try` apposta: il condominio resta utile, ma la demo è monca.'
    );
});

it('è marcato come dimostrativo, e nessun altro lo è', function () {
    // ⚠️ La marcatura è ciò che rende il condominio **eliminabile** nonostante i movimenti
    // contabili. Se si perde, la demo diventa permanente; se si allarga, si può cancellare la
    // contabilità di un condominio vero.
    $c = creaDimostrativo()['condominio'];

    expect($c->is_demo)->toBeTrue();
    expect(Condominio::where('is_demo', true)->count())->toBe(1);
});

it('contiene il percorso completo dichiarato nel suo perimetro', function () {
    $c = creaDimostrativo()['condominio'];

    // Ogni numero qui è il perimetro scritto in testa al seeder. Se il seeder cresce, questi
    // crescono con lui — e se cala, qualcosa si è rotto in silenzio.
    expect($c->immobili()->count())->toBe(4, 'le unità immobiliari');
    expect($c->tabelle()->count())->toBe(3, 'le tabelle millesimali, di cui due per l\'art. 1126');
    expect($c->casse()->count())->toBe(2, 'il conto corrente e il fondo');
    expect(DB::table('anagrafica_immobile')->whereIn('immobile_id', $c->immobili()->pluck('id'))->count())
        ->toBe(6, 'gli intestatari: proprietari, un inquilino e due comproprietari');

    $piano = PianoRate::where('condominio_id', $c->id)->first();
    expect($piano)->not->toBeNull('il piano rate');
    expect($piano->rate()->count())->toBe(2, 'le rate');

    expect(DB::table('fatture_passive')->where('condominio_id', $c->id)->count())
        ->toBe(5, 'ritenuta, nota di credito, sforo, storno e sopravvenienza');
});

it('⚠️ il cruscotto lo trova allineato: atteso e generato coincidono', function () {
    // È il difetto trovato **guardando a video** e da nessun'altra parte: il seeder generava le
    // rate senza collegare al piano le voci che copre, quindi il cruscotto mostrava un allarme
    // rosso «ricalcola» e le coperture allo 0%. I dati erano tutti giusti presi uno per uno.
    $c = creaDimostrativo()['condominio'];
    $piano = PianoRate::where('condominio_id', $c->id)->firstOrFail();
    $servizio = app(PianoRateQuoteService::class);

    expect($servizio->totaleAttesoCents($piano))->toBe($servizio->totalePuroGeneratoCents($piano),
        "Il piano rate dichiara di coprire un importo diverso da quello che ha generato.\n".
        "Il cruscotto lo segnala in rosso — «hai modificato il preventivo, ricalcola» — e il\n".
        "condominio dimostrativo si apre con un allarme, che è la prima cosa che vede chi sta\n".
        'guardando il programma per la prima volta.'
    );

    expect($servizio->eDisallineato($piano))->toBeFalse();
});

it("⚠️ l'Inbox operativa non è vuota", function () {
    // ⚠️ **Su richiesta di Vincenzo, ed è il difetto più grosso che aveva la demo.** L'Inbox è il
    // posto in cui il programma dice all'amministratore *cosa deve fare oggi*: è la differenza fra
    // un archivio contabile e un assistente. Una demo che la mostra vuota mostra il primo.
    //
    // Le tre attività nascono da cose che nel condominio ci sono davvero — la ritenuta da versare,
    // il piano incassato in parte, il fondo lavori incompleto. Un'attività che non corrisponde a
    // niente sarebbe peggio di nessuna: chi ci clicca sopra arriva su una pagina che non spiega.
    $c = creaDimostrativo()['condominio'];

    $attivita = \App\Models\Evento::query()
        ->whereJsonContains('meta->requires_action', true)
        ->where('is_completed', false)
        ->whereHas('condomini', fn ($q) => $q->where('condomini.id', $c->id))
        ->count();

    expect($attivita)->toBeGreaterThanOrEqual(3,
        "L'Inbox operativa del condominio dimostrativo mostra {$attivita} attività invece di tre.\n".
        'È il filtro esatto del cruscotto: `requires_action` vero, non completata, legata a questo condominio.'
    );
});

it('⚠️ contiene una spesa fuori budget, che resta da ratificare', function () {
    // ⚠️ **È il caso più comune della vita vera di un amministratore**, ed era l'unico pezzo del
    // cruscotto che nel condominio dimostrativo restava spento: la spesa reale supera il
    // preventivo, e l'art. 1135 c.c. vuole una delibera o una motivazione d'urgenza prima che si
    // possa pagare.
    $c = creaDimostrativo()['condominio'];

    $sforo = DB::table('fatture_passive')
        ->where('condominio_id', $c->id)
        ->where('stato_approvazione', 'sforo_motivato')
        ->first();

    expect($sforo)->not->toBeNull('Nessuna fattura in attesa di ratifica: lo sforo non si vede.');
    expect($sforo->stato_pagamento)->toBe('aperta',
        "La fattura in sforo risulta pagata. **Non deve esserlo**: una spesa fuori budget non si\n".
        "paga finché l'assemblea non l'ha ratificata, ed è il blocco che questa demo deve mostrare."
    );
});

it('⚠️ contiene una sopravvenienza, che accende la sezione fuori preventivo', function () {
    // ⚠️ **È un caso diverso dallo sforo, e la differenza conta.** Lo sforo è una voce che
    // esisteva a preventivo e che la spesa reale ha superato; la sopravvenienza è una spesa che a
    // preventivo **non c'era affatto** — un guasto, un danno. Non supera un budget: non ne ha uno,
    // e per pagarla servono rate nuove.
    //
    // Nel piano dei conti accende la sezione «Sopravvenienze e imprevisti (fuori preventivo)», che
    // nel condominio dimostrativo restava spenta.
    $c = creaDimostrativo()['condominio'];

    $righe = DB::table('righe_fattura as rf')
        ->join('fatture_passive as f', 'f.id', '=', 'rf.fattura_passiva_id')
        ->where('f.condominio_id', $c->id)
        ->where('rf.is_sopravvenienza', true)
        ->count();

    expect($righe)->toBeGreaterThanOrEqual(1, 'Nessuna sopravvenienza: la sezione fuori preventivo resta spenta.');

    // La voce viene **creata dal programma**, non dal seeder: sta sotto un capitolo tecnico e non
    // sporca il preventivo deliberato.
    $voce = DB::table('conti as c')
        ->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')
        ->where('pc.condominio_id', $c->id)
        ->where('c.nome', 'Sostituzione vetrata androne')
        ->first();

    expect($voce)->not->toBeNull('La voce della sopravvenienza non è stata creata dal programma.');
    expect((int) $voce->importo)->toBe(0,
        'La voce di una sopravvenienza nasce a zero: non era a preventivo, ed è il punto.'
    );
});

it('⚠️ contiene uno storno: in contabilità non si cancella, si rettifica', function () {
    // ⚠️ È il principio su cui è costruito tutto il gestionale, e finché il condominio
    // dimostrativo non ne mostrava uno chi lo guardava non aveva modo di scoprirlo.
    $c = creaDimostrativo()['condominio'];

    $stornati = DB::table('pagamenti_fornitori')
        ->where('condominio_id', $c->id)
        ->where('stato', 'stornato')
        ->count();

    expect($stornati)->toBeGreaterThanOrEqual(1,
        'Nessun pagamento stornato: manca l\'esempio della rettifica.'
    );

    // La controprova: il pagamento con la nota di credito **non** è quello stornato — è l'esempio
    // della compensazione e deve restare intatto.
    $compensazione = DB::table('pagamenti_fornitori')
        ->where('condominio_id', $c->id)
        ->where('stato', 'confermato')
        ->orderBy('id')
        ->first();

    expect($compensazione)->not->toBeNull('Lo storno ha travolto anche il pagamento con la nota di credito.');
});

it('⚠️ lo scadenzario F24 è già calcolato', function () {
    // ⚠️ **Trovato da Vincenzo guardando la pagina, non da una prova.** Senza questo passo la
    // pagina delle ritenute era vuota, con un pulsante arancione «Aggiorna scadenze»: l'attività in
    // Inbox diceva «versa la ritenuta», si premeva «Risolvi», e si arrivava su una schermata che
    // dichiarava di non avere niente da versare.
    $c = creaDimostrativo()['condominio'];

    $deleghe = DB::table('deleghe_f24')->where('condominio_id', $c->id)->get();

    expect($deleghe)->toHaveCount(1, 'Lo scadenzario delle ritenute non è stato calcolato.');
    expect((int) $deleghe->first()->totale_debito)->toBeGreaterThan(0);
});

it('la nota di credito riduce davvero il bonifico', function () {
    // Non basta che i due documenti esistano: la demo serve a **mostrare** che dalla banca esce
    // meno di quanto si chiude di debito. È la funzione rimessa in piedi nella beta.67.
    $c = creaDimostrativo()['condominio'];

    $fattura = DB::table('fatture_passive')->where('condominio_id', $c->id)->where('tipo_documento', 'fattura')->first();
    $nota    = DB::table('fatture_passive')->where('condominio_id', $c->id)->where('tipo_documento', 'nota_credito')->first();
    // ⚠️ Si cerca **quello legato alla fattura con la nota**, non «il primo»: dalla beta.71 il
    // condominio dimostrativo ha più pagamenti, e uno è stornato apposta.
    $pagamento = DB::table('pagamenti_fornitori')
        ->where('condominio_id', $c->id)
        ->where('stato', 'confermato')
        ->orderBy('id')
        ->first();

    expect((int) $pagamento->importo_netto)->toBeLessThan((int) $fattura->netto_a_pagare,
        'Dalla banca è uscito quanto la fattura: la nota di credito non ha compensato niente.'
    );
    expect((int) $pagamento->importo_netto)
        ->toBe((int) $fattura->netto_a_pagare - abs((int) $nota->netto_a_pagare));
});

/*
|--------------------------------------------------------------------------
| La rimozione: è la metà che rende accettabile la creazione
|--------------------------------------------------------------------------
*/

it('⚠️ si rimuove senza lasciare niente indietro', function () {
    $azione = app(CreaCondominioDimostrativoAction::class);
    $primaCondomini = Condominio::count();

    $c = $azione->esegui()['condominio'];
    $azione->rimuovi($c);

    expect(Condominio::count())->toBe($primaCondomini, 'Il condominio non è stato rimosso.');

    $residui = [];
    foreach (['fatture_passive', 'pagamenti_fornitori', 'scritture_contabili', 'casse',
              'conti_contabili', 'immobili', 'tabelle', 'esercizi', 'gestioni', 'piani_conti',
              'piani_rate', 'saldi'] as $tabella) {
        $n = DB::table($tabella)->where('condominio_id', $c->id)->count();
        if ($n > 0) {
            $residui[$tabella] = $n;
        }
    }

    expect($residui)->toBe([],
        "La rimozione ha lasciato righe orfane:\n\n".
        implode("\n", array_map(fn ($t, $n) => "  {$t}: {$n} righe", array_keys($residui), $residui))."\n\n".
        "⚠️ **L'ordine di cancellazione è derivato dai vincoli del database**, non indovinato: sei\n".
        "vincoli `RESTRICT` o `NO ACTION` fermano la cancellazione se ciò che vi pende non è già\n".
        "sparito. Una tabella nuova che punti a un'entità del condominio va aggiunta a quell'ordine.\n\n".
        'Vedi `CreaCondominioDimostrativoAction::rimuovi()`.'
    );
});

it('⚠️ non usa le factory: Faker non esiste su un\'installazione vera', function () {
    /*
     * ⚠️ **Il difetto che non si vedeva perché in sviluppo Faker c'è.**
     *
     * `fakerphp/faker` è in `require-dev`. Il pacchetto distribuito si costruisce con `--no-dev`,
     * quindi Faker **non c'è**: verificato aprendo `km_v1.10.0-beta.32.zip`, zero occorrenze di
     * `vendor/fakerphp` — mentre `database/factories/` viaggia regolarmente, quindici file. Una
     * `Model::factory()` esiste quindi anche in produzione, ma `$this->faker` è **null** e la
     * prima riga di `definition()` muore.
     *
     * Il pulsante «Crea condominio di esempio» funzionava perciò solo sulle macchine di sviluppo:
     * su un'installazione vera l'utente leggeva «non è stato possibile creare il condominio
     * dimostrativo» e a database restava mezzo condominio. Nessun test poteva accorgersene
     * eseguendo il seeder, perché qui Faker è installato: l'unico controllo possibile è statico.
     */
    foreach ([
        'database/seeders/CondominioDemoSeeder.php',
        'app/Actions/Condominio/CreaCondominioDimostrativoAction.php',
    ] as $file) {
        $sorgente = file_get_contents(base_path($file));

        // Solo le chiamate vere: le occorrenze dentro i commenti spiegano perché non si usano.
        $senzaCommenti = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $sorgente);

        // ⚠️ `toBeFalse`, non `->not->toContain(...)`: `toContain` in Pest è **variadica**, e il
        // messaggio passato come secondo argomento diventa un secondo termine da cercare — la
        // guardia smette di guardare. Scoperto reintroducendo la factory apposta: il test restava
        // verde. Un test che non sa diventare rosso non è una guardia.
        expect(str_contains($senzaCommenti, '::factory('))->toBeFalse(
            "{$file} chiama una factory Eloquent.\n\n"
            ."Le factory dipendono da `fakerphp/faker`, che è in `require-dev` e **non è presente**\n"
            ."nel pacchetto distribuito. Il codice del condominio dimostrativo gira sulla macchina\n"
            ."di un utente, non nella suite: qui va scritto tutto a mano.\n\n"
            .'⚠️ I valori vanno derivati dal progressivo — email, PEC, codice fiscale e numero '
            .'documento hanno un indice UNIQUE, e la seconda demo collide con la prima.');
    }
});

it('⚠️ né lascia indietro ciò che sta dietro un pivot', function () {
    /*
     * ⚠️ **Il test qui sopra non poteva accorgersene, ed è il motivo per cui questo esiste.**
     * Quello scorre le tabelle cercando una colonna `condominio_id`: anagrafiche, eventi e
     * fornitori il condominio non ce l'hanno come colonna — ce l'hanno attraverso un pivot, o
     * non ce l'hanno affatto. Restavano quindi indietro a ogni rimozione senza che niente lo
     * dicesse: misurato il 23/08/2026 sul database di sviluppo, **184 anagrafiche orfane su 221**
     * e un fornitore rimosso su quattro.
     *
     * Non è solo sporcizia. Dalla .71 le sei persone hanno email, PEC e codice fiscale **fissi**,
     * derivati dal progressivo, e quelle colonne sono UNIQUE: una rimozione che le lascia in giro
     * fa morire la **demo successiva** su un errore di integrità. Ecco perché il ciclo completo —
     * crea, rimuovi, ricrea — è parte del test e non un di più.
     */
    $azione = app(CreaCondominioDimostrativoAction::class);

    $primaAnagrafiche = DB::table('anagrafiche')->count();
    $primaFornitori   = DB::table('fornitori')->count();
    $primaEventi      = DB::table('eventi')->count();

    $c = $azione->esegui()['condominio'];
    $azione->rimuovi($c);

    expect(DB::table('anagrafiche')->count())->toBe($primaAnagrafiche,
        'Le persone del condominio dimostrativo sono rimaste nella rubrica. Si cancellano solo '
        ."quelle che non appartengono più a nessun condominio e non hanno un utente collegato:\n"
        .'vedi il passo 9 di `CreaCondominioDimostrativoAction::rimuovi()`.');

    expect(DB::table('fornitori')->count())->toBe($primaFornitori,
        "I fornitori dimostrativi sono **quattro**, non uno: fino alla .71 la rimozione ne\n"
        .'confrontava uno solo e gli altri tre restavano in elenco a ogni giro.');

    expect(DB::table('eventi')->count())->toBe($primaEventi,
        "Gli eventi dell'inbox operativa e dello scadenzario sono rimasti. Il condominio ce l'hanno\n"
        .'attraverso il pivot `condominio_evento`, che scende a cascata: gli id vanno catturati '
        .'**prima** della cancellazione.');

    // Il ciclo completo: la seconda demo deve nascere, non morire su un UNIQUE.
    $seconda = $azione->esegui();

    expect($seconda['avvisi'])->toBe([],
        "La seconda demo ha segnalato problemi che la prima non aveva — quasi sempre una collisione\n"
        ."su email, PEC o codice fiscale lasciati indietro dalla rimozione precedente:\n  - "
        .implode("\n  - ", $seconda['avvisi']));

    $azione->rimuovi($seconda['condominio']);
});

it('⚠️ e rifiuta di toccare un condominio che non è dimostrativo', function () {
    // ⚠️ **La guardia più importante di questo file.** `rimuovi()` cancella movimenti contabili
    // aggirando le protezioni del database: se puntasse a un condominio vero, cancellerebbe la
    // contabilità di qualcuno. Un id sbagliato in una rotta basta.
    $vero = Condominio::factory()->create(['is_demo' => false]);

    expect(fn () => app(CreaCondominioDimostrativoAction::class)->rimuovi($vero))
        ->toThrow(RuntimeException::class);

    expect(Condominio::find($vero->id))->not->toBeNull('Il condominio vero è stato cancellato.');
});

it('⚠️ si elimina anche dal menu «elimina» dei tre puntini', function () {
    // ⚠️ **Due strade per la stessa cosa devono dare lo stesso esito.** La rotta normale di
    // eliminazione fallirebbe sui vincoli del database e mostrerebbe «ha movimenti contabili
    // registrati e non può essere eliminato» — una frase vera per i condomini veri e **falsa
    // proprio per quello che il programma dice di poter rimuovere**.
    //
    // Chi preme «elimina» sui tre puntini non deve sapere che quel condominio ha una porta sua.
    $c = creaDimostrativo()['condominio'];

    $this->delete(route('condomini.destroy', ['condominio' => $c->id]));

    expect(Condominio::find($c->id))->toBeNull(
        "Il condominio dimostrativo non è stato eliminato dalla rotta normale.\n".
        'La sua rimozione deve funzionare da qualunque punto la si chieda.'
    );
});

it('e la rotta normale continua a proteggere i condomini veri', function () {
    // La controprova: la delega vale **solo** per il dimostrativo. Un condominio con movimenti
    // contabili non deve diventare cancellabile perché è stata aperta una porta per la demo.
    $vero = Condominio::factory()->create(['is_demo' => false]);

    $this->delete(route('condomini.destroy', ['condominio' => $vero->id]));

    // Senza movimenti si cancella, ed è giusto: quello che si prova qui è che **non passa** dalla
    // strada del dimostrativo, cioè che `is_demo` è la sola chiave che la apre.
    expect(app(CreaCondominioDimostrativoAction::class)->esisteGia())->toBeNull();
});

it('sa dire se ne esiste già uno', function () {
    $azione = app(CreaCondominioDimostrativoAction::class);

    expect($azione->esisteGia())->toBeNull();

    $c = $azione->esegui()['condominio'];
    expect($azione->esisteGia()?->id)->toBe($c->id);

    $azione->rimuovi($c);
    expect($azione->esisteGia())->toBeNull('Rimosso il dimostrativo, il pulsante deve riaccendersi.');
});
