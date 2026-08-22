<?php

/**
 * # Ogni rotta annidata cerca il figlio **dentro** il condominio dell'indirizzo
 *
 * ## Il difetto, e la sua misura
 *
 * Sotto `/gestionale/{condominio}` ci sono rotte che portano un **secondo** modello nell'indirizzo:
 * `{tabella}`, `{esercizio}`, `{immobile}`, `{cassa}`, `{pianoRate}`… Il binding implicito di
 * Laravel li risolve **per id, ciascuno per conto suo**: niente lega il figlio al padre. Cambiando
 * un numero nell'indirizzo si arriva alla risorsa di un altro condominio.
 *
 * Fino alla beta.65 l'unica difesa era una guardia scritta a mano nel controller, presente in 29
 * metodi su 112 — il perimetro è congelato in `RotteAnnidateSenzaGuardiaTest`. La beta.66 accende
 * il vincolo sulla rotta, che è il posto giusto: **non dipende da chi scrive il prossimo
 * controller**.
 *
 * ## Perché servivano la mappa e le tre relazioni nuove
 *
 * `->scopeBindings()` chiede al modello padre una relazione verso il figlio, e **il nome se lo
 * inventa**: `Str::plural(Str::camel($childType))`, cioè una pluralizzazione **inglese**. Su nomi
 * italiani sbaglia sempre — per `{tabella}` cerca `Condominio::tabellas()` — e il fallimento non è
 * un 404 ma `BadMethodCallException`, cioè **500 anche sulle richieste legittime**. Da qui il trait
 * `RisolveIFigliDelleRotte`, che dichiara la mappa in chiaro.
 *
 * Tre coppie non avevano nemmeno una relazione da nominare, e sono state costruite nella beta.66:
 * `Esercizio::pianiConti()` e `Esercizio::pianiRate()` (due salti, attraverso il pivot
 * `esercizio_gestione`) e `Condominio::conti()` (un `hasManyThrough`, perché `conti` pende da
 * `piano_conto_id` e non ha `condominio_id`).
 *
 * ## Cosa presidia questo file, in tre punti
 *
 * 1. **La copertura**: quante rotte hanno il vincolo, e che le eccezioni siano solo quelle scritte.
 * 2. **La mappa**: che ogni voce punti a una relazione **che esiste** e porti **al modello giusto**.
 *    È il controllo che serve di più, perché il trait ricade in silenzio sulla derivazione inglese:
 *    rinominare una relazione non romperebbe niente in compilazione e trasformerebbe una rotta
 *    protetta in un 500.
 * 3. **Il comportamento**: che il figlio di un altro condominio venga davvero rifiutato, e che il
 *    proprio venga davvero servito. Senza il secondo, si soddisfa il primo rompendo tutto.
 *
 * ## Cosa NON copre
 *
 * - **Non sostituisce le guardie a mano** dei controller, che restano: se un giorno una rotta
 *   dovesse uscire dal vincolo, la difesa in profondità è l'unica cosa che resta accesa.
 * - **Non copre le rotte fuori da `/gestionale/{condominio}`**: là il perimetro non è il condominio.
 * - **Non prova tutte e venti le coppie via HTTP**, ma le tre nuove più due vecchie di controllo.
 *   Il punto 2 copre le altre a livello di risoluzione, che è dove sta il meccanismo.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Le rotte del gestionale che **non** hanno il vincolo, con la ragione.
 *
 * ⚠️ Questo elenco si accorcia, non si allunga. Una rotta nuova qui dentro va giustificata come
 * quella che c'è: non «il test era rosso», ma *quale* coppia padre>figlio non è esprimibile con una
 * relazione Eloquent, e *perché*.
 *
 * @return array<string, string>
 */
function rotteSenzaVincoloEPerche(): array
{
    return [
        'admin/gestionale/{condominio}/esercizi/{esercizio}/voci/{conto}/movimenti' =>
            "Da {esercizio} a {conto} ci sono tre salti — il pivot esercizio_gestione, poi\n".
            "piani_conti.gestione_id, poi conti.piano_conto_id — e nessuna relazione Eloquent li fa\n".
            "tutti e tre. La guardia resta quella a mano in MovimentiPerVoceController.",
    ];
}

/** Tutte le rotte sotto `/gestionale/{condominio}`. */
function rotteDelGestionale(): \Illuminate\Support\Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'gestionale/{condominio}'));
}

function haIlVincolo(\Illuminate\Routing\Route $r): bool
{
    return ($r->getAction()['scope_bindings'] ?? false) === true;
}

it('le sole rotte senza vincolo sono quelle dichiarate, con la loro ragione', function () {
    $senza = rotteDelGestionale()
        ->reject(fn ($r) => haIlVincolo($r))
        ->map(fn ($r) => $r->uri())
        ->unique()
        ->values()
        ->all();

    $dichiarate = array_keys(rotteSenzaVincoloEPerche());

    sort($senza);
    sort($dichiarate);

    expect($senza)->toBe($dichiarate,
        "Le rotte del gestionale senza `scopeBindings()` non sono quelle dichiarate.\n\n".
        "Se ne hai **aggiunta** una: una rotta annidata senza vincolo risolve il figlio per sola\n".
        "chiave, e chi cambia un numero nell'indirizzo apre la risorsa di un altro condominio.\n".
        "Prima di iscriverla qui, guarda se la coppia è mappabile in `RisolveIFigliDelleRotte`:\n".
        "nella beta.66 tre coppie che sembravano impossibili si sono rivelate esprimibili, due con\n".
        "un `belongsToMany` sul pivot e una con un `hasManyThrough`.\n\n".
        "Se ne hai **tolta** una dall'elenco perché ora è vincolata: togli anche la voce qui sopra.\n\n".
        "Senza vincolo oggi: ".implode(', ', $senza)."\n".
        "Dichiarate:       ".implode(', ', $dichiarate)
    );
});

it('il vincolo copre quasi tutto il gestionale, e la cifra è quella dichiarata', function () {
    $tutte = rotteDelGestionale()->count();
    $con = rotteDelGestionale()->filter(fn ($r) => haIlVincolo($r))->count();

    // ⚠️ Non è un test di vanità: la cifra è quella scritta nel changelog e nella coda ㊷. Se
    // scende senza che nessuno se ne accorga, la beta.66 si sta smontando da sola.
    expect($tutte)->toBeGreaterThan(100, 'Le rotte del gestionale sono crollate: il filtro non trova più niente?');
    expect($con / $tutte)->toBeGreaterThan(0.98,
        "Il vincolo copre {$con} rotte su {$tutte}. Alla chiusura della beta.66 erano 159 su 160.\n".
        'Una rotta che lo perde va capita, non arrotondata.'
    );
});

/**
 * Tutte le voci delle mappe, appiattite: `[classe del padre, tipo del figlio, nome della relazione]`.
 *
 * Si legge la mappa **dal modello**, con la reflection, e non da un elenco ricopiato qui: un elenco
 * ricopiato invecchia senza dirlo, ed è esattamente il modo in cui una guardia diventa verde per
 * sempre.
 *
 * @return list<array{0:string,1:string,2:string}>
 */
function tutteLeCoppieMappate(): array
{
    $modelli = [
        Condominio::class,
        \App\Models\Immobile::class,
        Esercizio::class,
        PianoConto::class,
        PianoRate::class,
        \App\Models\Gestionale\Conto::class,
        \App\Models\Gestionale\FatturaPassiva::class,
    ];

    $coppie = [];

    foreach ($modelli as $classe) {
        $istanza = new $classe;
        $metodo = new ReflectionMethod($classe, 'relazioniDeiFigliNelleRotte');
        $metodo->setAccessible(true);

        foreach ($metodo->invoke($istanza) as $figlio => $relazione) {
            $coppie[] = [$classe, $figlio, $relazione];
        }
    }

    return $coppie;
}

it('le mappe non sono vuote: si stanno leggendo davvero', function () {
    // Se la reflection smettesse di trovare il metodo, `tutteLeCoppieMappate()` tornerebbe vuoto e
    // il test qui sotto passerebbe su zero casi. È la lezione della beta.61.
    expect(count(tutteLeCoppieMappate()))->toBeGreaterThan(18);
});

/**
 * La classe che i controller si aspettano per un parametro di rotta.
 *
 * Si legge dalla **firma dei metodi** dietro le rotte del gestionale: è l'unica fonte che non
 * invecchia da sola, perché è la stessa che Laravel usa per decidere cosa iniettare. `null` quando
 * nessun controller tipizza quel parametro — capita per i parametri che i controller prendono come
 * id grezzo, e lì non c'è niente da confrontare.
 */
function classeAttesaPerIlParametro(string $parametro): ?string
{
    foreach (rotteDelGestionale() as $r) {
        $azione = $r->getActionName();

        if (! str_contains($azione, '@')) {
            continue;
        }

        [$classe, $metodo] = explode('@', $azione);

        if (! class_exists($classe) || ! method_exists($classe, $metodo)) {
            continue;
        }

        foreach ((new ReflectionMethod($classe, $metodo))->getParameters() as $p) {
            if ($p->getName() !== $parametro) {
                continue;
            }

            $tipo = Illuminate\Support\Reflector::getParameterClassName($p);

            if ($tipo !== null && is_subclass_of($tipo, Illuminate\Database\Eloquent\Model::class)) {
                return $tipo;
            }
        }
    }

    return null;
}

it('ogni voce della mappa punta a una relazione che esiste e porta al modello giusto', function () {
    foreach (tutteLeCoppieMappate() as [$classe, $figlio, $relazione]) {
        $istanza = new $classe;

        expect(method_exists($istanza, $relazione))->toBeTrue(
            "`{$classe}` mappa `{$figlio}` sulla relazione `{$relazione}()`, che **non esiste**.\n\n".
            "⚠️ Questo non fa fallire niente in compilazione, e in esecuzione **non dà un errore\n".
            "chiaro**: `RisolveIFigliDelleRotte` ricade in silenzio sulla derivazione automatica di\n".
            "Laravel, che pluralizza all'inglese e su `{$figlio}` cercherebbe qualcosa come\n".
            "`".Illuminate\Support\Str::plural(Illuminate\Support\Str::camel($figlio))."()`. Il\n".
            "risultato è `BadMethodCallException`, cioè **500 su richieste legittime**.\n\n".
            'Probabile causa: la relazione è stata rinominata e la mappa no.'
        );

        $r = $istanza->{$relazione}();

        expect($r)->toBeInstanceOf(Relation::class,
            "`{$classe}::{$relazione}()` non restituisce una relazione Eloquent: lo scoped binding\n".
            'ci chiama sopra `->where()`, quindi qualunque altra cosa esplode a richiesta.'
        );

        // Il modello a cui si arriva deve essere **quello che i controller si aspettano** per quel
        // parametro. Una mappa che punta alla relazione sbagliata cercherebbe il figlio nel posto
        // sbagliato: non un errore, un **buco**, perché la richiesta risponderebbe 200 su una
        // risorsa altrui.
        //
        // ⚠️ La classe attesa si legge dalla **firma dei controller**, non dal nome del parametro:
        // `{scrittura}` sta per `ScritturaContabile`, e dedurre `Scrittura` da `scrittura` darebbe
        // un rilievo falso. Il nome che conta è quello che Laravel dovrà iniettare.
        $atteso = classeAttesaPerIlParametro($figlio);

        if ($atteso !== null) {
            expect(get_class($r->getRelated()))->toBe($atteso,
                "`{$classe}` mappa `{$figlio}` su `{$relazione}()`, che però porta a ".
                get_class($r->getRelated())." e non a {$atteso} — che è la classe con cui i\n".
                'controller tipizzano quel parametro.'
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| Il comportamento: il figlio di un altro condominio non si apre
|--------------------------------------------------------------------------
*/

function amministratoreDiProva(): User
{
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    foreach (Permission::cases() as $p) {
        \Spatie\Permission\Models\Permission::findOrCreate($p->value, 'web');
    }
    $ruolo->syncPermissions(Permission::cases());

    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($ruolo);

    return $u;
}

/** Un condominio con dentro un esercizio, una gestione attaccata, un piano conti e un piano rate. */
function condominioCompleto(): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    DB::table('esercizio_gestione')->insert([
        'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $pianoConto = PianoConto::factory()->create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $condominio->id,
        'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1,
    ]);

    $tabellaId = DB::table('tabelle')->insertGetId([
        'condominio_id' => $condominio->id, 'nome' => 'Proprietà',
    ]);

    return compact('condominio', 'esercizio', 'gestione', 'pianoConto', 'pianoRate', 'tabellaId');
}

/**
 * Le rotte provate a video, con il figlio da mettere nell'indirizzo.
 *
 * Le tre coppie **nuove** della beta.66 più due vecchie di controllo: se la mappa si rompesse in
 * blocco, le due vecchie lo direbbero prima delle nuove.
 *
 * @return array<string, array{0:string, 1:string}>
 */
dataset('rotte annidate', [
    'esercizio > pianoConto (nuova)' => ['admin.gestionale.esercizi.piani-conti.show', 'pianoConto'],
    'esercizio > pianoRate (nuova)'  => ['admin.gestionale.esercizi.piani-rate.show', 'pianoRate'],
    'condominio > esercizio'         => ['admin.gestionale.esercizi.gestioni.index', 'esercizio'],
    'condominio > tabella'           => ['admin.gestionale.tabelle.quote.index', 'tabellaId'],
]);

it('⚠️ il figlio di un altro condominio risponde 404', function (string $rotta, string $chiave) {
    $mio = condominioCompleto();
    $altrui = condominioCompleto();

    if (! Route::has($rotta)) {
        $this->markTestSkipped("La rotta `{$rotta}` non esiste più: il caso va riscritto, non tolto.");
    }

    $parametri = ['condominio' => $mio['condominio']->id];

    if (str_contains($rotta, 'piani-conti') || str_contains($rotta, 'piani-rate')) {
        $parametri['esercizio'] = $mio['esercizio']->id;
    }

    $figlio = $altrui[$chiave];
    $parametri[$chiave === 'tabellaId' ? 'tabella' : $chiave] = is_object($figlio) ? $figlio->id : $figlio;

    $this->actingAs(amministratoreDiProva())
        ->get(route($rotta, $parametri))
        ->assertNotFound(
            "`{$rotta}` ha servito il figlio di un **altro** condominio.\n\n".
            "È l'abuso che la beta.66 chiude: basta cambiare un numero nell'indirizzo. Il vincolo\n".
            "sulla rotta (`scopeBindings()`) e la voce nella mappa di `RisolveIFigliDelleRotte`\n".
            'devono esserci tutti e due — uno solo dei due non protegge niente.'
        );
})->with('rotte annidate');

it('⚠️ e il proprio figlio si risolve, invece', function (string $rotta, string $chiave) {
    // ⚠️ **La controprova, e senza di lei il test sopra non vale niente**: si soddisferebbe anche
    // rompendo la rotta per tutti. È il modo in cui accendere lo scoping *distrugge invece di
    // proteggere* — 404 o 500 anche a chi ha diritto di entrare, che è il difetto per cui questa
    // funzione di Laravel era rimasta spenta fino alla beta.65.
    //
    // ⚠️ **Si prova la risoluzione, non la pagina.** Chiamare la rotta a video farebbe fallire
    // questo controllo per ragioni che non c'entrano — la pagina del piano rate, per esempio, usa
    // `JSON_UNQUOTE` che SQLite non ha, e i test girano su SQLite mentre il prodotto gira su MySQL.
    // Un test che diventa rosso per l'ambiente insegna a ignorarlo.
    $mio = condominioCompleto();
    $altrui = condominioCompleto();

    [$padre, $tipoFiglio] = coordinateDellaCoppia($rotta, $chiave, $mio);

    $figlio = $chiave === 'tabellaId' ? $mio[$chiave] : $mio[$chiave]->id;
    $estraneo = $chiave === 'tabellaId' ? $altrui[$chiave] : $altrui[$chiave]->id;

    expect($padre->resolveChildRouteBinding($tipoFiglio, $figlio, null))->not->toBeNull(
        "La risoluzione di `{$tipoFiglio}` dentro il proprio padre torna **null**: il vincolo sta
".
        "rifiutando richieste legittime, e a video sarebbe un 404 a chi ha diritto di entrare.
".
        "Causa quasi certa: la relazione mappata in `RisolveIFigliDelleRotte` non contiene davvero
".
        'quel figlio. Per `pianoConto` e `pianoRate` il legame passa dal pivot `esercizio_gestione`.'
    );

    expect($padre->resolveChildRouteBinding($tipoFiglio, $estraneo, null))->toBeNull(
        "La risoluzione di `{$tipoFiglio}` accetta il figlio di un **altro** padre."
    );
})->with('rotte annidate');

/**
 * Il padre da cui parte la risoluzione, e il nome del figlio da cercarci dentro.
 *
 * Per le rotte annidate due volte il padre non è il condominio ma l'esercizio: lo scoped binding
 * lega ogni parametro a **quello che lo precede**, non alla radice.
 *
 * @return array{0:\Illuminate\Database\Eloquent\Model, 1:string}
 */
function coordinateDellaCoppia(string $rotta, string $chiave, array $contesto): array
{
    if (str_contains($rotta, 'piani-conti') || str_contains($rotta, 'piani-rate')) {
        return [$contesto['esercizio'], $chiave];
    }

    return [$contesto['condominio'], $chiave === 'tabellaId' ? 'tabella' : $chiave];
}
