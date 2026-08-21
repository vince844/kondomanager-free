<?php

use Illuminate\Support\Facades\Route;

/**
 * # Nessuna rotta registrata punta a un metodo che non esiste
 *
 * `Route::resource()` registra **sette** azioni — `index`, `create`, `store`, `show`, `edit`,
 * `update`, `destroy` — indipendentemente da quante il controller ne implementi. Le altre
 * restano registrate e rispondono, a chiunque ci arrivi per URL, con un errore del server:
 *
 *     Call to undefined method App\Http\Controllers\…\CategoriaDocumentoController::show()
 *
 * Non è un 404 e non è un rifiuto: è un **500**. E arriva prima di qualunque codice
 * applicativo, quindi nessuna policy, nessun middleware per azione e nessun `FormRequest` può
 * intercettarlo — il dispatcher invoca il metodo, PHP non lo trova, e solleva.
 *
 * ## Perché questa guardia esiste, cioè: è la terza volta
 *
 * Lo stesso difetto è stato corretto **due volte** prima di oggi, ogni volta nel file in cui era
 * stato visto e mai altrove:
 *
 * - **beta.48** — quattro rotte di `ContoController` (`routes/gestionale.php:183-188`, dove il
 *   commento racconta esattamente questo difetto);
 * - **beta.61** — altre nove, tutte in `routes/gestionale.php`, con l'avviso nel changelog
 *   pubblico a chi avesse un segnalibro.
 *
 * Due giorni dopo la .61 una segnalazione dal forum ne ha portata una **decima**, in un file che
 * nessuno dei due giri aveva guardato (`routes/admin.php`), e la scansione di tutte le rotte
 * registrate ne ha trovate **diciassette**. La regola del progetto è già scritta: *«alla terza
 * volta si chiude la classe, non il caso»* — e questa è una classe che si enumera con un
 * comando, quindi si chiude con un test invece che con un altro giro di correzioni.
 *
 * ⚠️ **`php artisan route:list` non lo dice.** Stampa il nome del metodo senza verificarne
 * l'esistenza: le diciassette rotte comparivano nell'elenco esattamente come le sane. Nessuno
 * strumento standard di Laravel copre questo controllo, ed è la ragione per cui il difetto è
 * sopravvissuto a due correzioni.
 *
 * ## `->only()` e non `->except()`
 *
 * Le correzioni scrivono `->only([...])`, che costringe a dichiarare **cosa esiste**.
 * `->except([...])` fa credere che il resto sia stato voluto: su `routes/admin.php` l'`except(['store'])`
 * delle categorie non era una potatura ragionata, era solo un rattoppo contro la collisione di
 * nome con la rotta di `store` registrata a mano più sotto — e mentre sembrava un elenco pensato
 * teneva in piedi tre rotte fantasma.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non dice che la rotta sia raggiungibile o giusta.** Dice solo che il metodo esiste. Una
 *   rotta che punta a un metodo vuoto, o a una schermata che nessuno voleva, passa di qui: la
 *   domanda «questa rotta serve a qualcosa?» resta di chi la scrive.
 * - **Non guarda i permessi né le guardie sul condominio**: quelle sono di
 *   `RotteAnnidateSenzaGuardiaTest`.
 * - **Non copre le rotte registrate a runtime** da pacchetti che si agganciano fuori dal
 *   bootstrap dei test.
 * - **Non copre i metodi che esistono ma non sono pubblici.** `method_exists()` risponde `true`
 *   anche su un `protected`, dove il dispatcher solleverebbe comunque. È un caso che il
 *   repository oggi non contiene e che si aggiunge il giorno in cui si presenta, invece di
 *   allargare adesso il riconoscitore su un'ipotesi.
 * - **Non copre una classe che risponde davvero con un `__call` scritto a mano**: lì l'assenza del
 *   metodo non prova niente, e la rotta viene saltata di proposito. Vedi `rispondeConCall()`, che
 *   distingue quel caso dal `__call` che si eredita da Laravel — la distinzione che tiene in piedi
 *   tutta la guardia.
 *
 * ## L'autocontrollo chiama la funzione vera
 *
 * È la lezione più cara della beta.60 — tre guardie che si svuotavano senza far diventare rosso
 * niente, perché i loro autocontrolli ricopiavano l'espressione invece di chiamare la funzione.
 * Qui i tre controlli in fondo registrano rotte finte e passano da `rotteSenzaMetodo()`, che è
 * la stessa funzione del test vero: svuotarla fa diventare rosso l'autocontrollo.
 */

/**
 * La classe risponde davvero a un metodo che non dichiara, o eredita solo il `__call` del framework?
 *
 * ⚠️ **La prima stesura scriveva `method_exists($classe, '__call')` e basta, e con quella riga la
 * guardia si poteva spegnere in silenzio su tutte e quattrocento le rotte.** `Illuminate\Routing\Controller`
 * dichiara un `__call()` che si limita a sollevare «Method does not exist» — non risponde a niente —
 * ma `method_exists()` lo vede per ereditarietà. Basterebbe quindi che qualcuno «ripulisse»
 * `App\Http\Controllers\Controller` facendolo estendere quella classe di Laravel, cosa del tutto
 * plausibile, e da quel momento **ogni** rotta verrebbe saltata: il test resterebbe verde con
 * diciassette rotte fantasma dentro. L'ha trovato la revisione avversariale della beta.62,
 * misurandolo con un controller di prova.
 *
 * L'esenzione ha quindi senso solo per un `__call` **scritto nel progetto**, che è l'unico che può
 * davvero rispondere. Oggi nel repository non ce n'è nessuno: la funzione esiste per non
 * riaprire il buco il giorno che ce ne sarà uno.
 */
function rispondeConCall(string $classe): bool
{
    if (! method_exists($classe, '__call')) {
        return false;
    }

    $dichiarante = (new ReflectionMethod($classe, '__call'))->getDeclaringClass()->getName();

    return $dichiarante !== \Illuminate\Routing\Controller::class;
}

/**
 * Le rotte registrate il cui bersaglio **non esiste**: classe assente o metodo assente.
 *
 * Le rotte servite da una closure non hanno un bersaglio da verificare e vengono saltate. Le
 * rotte a singola azione (`Route::get(…, ControllerInvocabile::class)`) hanno per bersaglio
 * `__invoke`, ed è quello che si cerca.
 *
 * @return list<string> `nome | verbo uri → Classe@metodo` in ordine, per un messaggio leggibile
 */
function rotteSenzaMetodo(): array
{
    $fantasma = [];

    foreach (Route::getRoutes() as $rotta) {
        $azione = $rotta->getActionName();

        // Una closure non ha un bersaglio: `getActionName()` restituisce la stringa `Closure`.
        if ($azione === 'Closure') {
            continue;
        }

        [$classe, $metodo] = str_contains($azione, '@')
            ? explode('@', $azione, 2)
            : [$azione, '__invoke'];

        if (! class_exists($classe)) {
            $fantasma[] = sprintf(
                '%s | %s %s → %s  [CLASSE ASSENTE]',
                $rotta->getName() ?: '(senza nome)',
                implode('|', $rotta->methods()),
                $rotta->uri(),
                $azione,
            );

            continue;
        }

        if (method_exists($classe, $metodo) || rispondeConCall($classe)) {
            continue;
        }

        $fantasma[] = sprintf(
            '%s | %s %s → %s  [METODO ASSENTE]',
            $rotta->getName() ?: '(senza nome)',
            implode('|', $rotta->methods()),
            $rotta->uri(),
            $azione,
        );
    }

    sort($fantasma);

    return $fantasma;
}

it('nessuna rotta registrata punta a un metodo inesistente', function () {
    $fantasma = rotteSenzaMetodo();

    expect($fantasma)->toBe([], sprintf(
        "%d rotte puntano a codice che non esiste e rispondono 500 a chiunque ci arrivi per URL.\n".
        "Si potano con `->only([...])` sulla resource che le genera — vedi `routes/gestionale.php:183`\n".
        "per la forma del commento che spiega perché quelle azioni non sono mai state volute.\n\n%s\n",
        count($fantasma),
        implode("\n", $fantasma),
    ));
});

/*
|--------------------------------------------------------------------------
| L'autocontrollo: le sonde passano dalla funzione vera
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **Estende la base vera del progetto, e non è un dettaglio.** Se un giorno
 * `App\Http\Controllers\Controller` cambiasse genitore ereditando un `__call`, questa sonda lo
 * erediterebbe con lui e il test «la guardia morde» diventerebbe **rosso** — che è esattamente
 * l'avviso che serve. Con una sonda scollegata dal progetto, quel cambiamento passerebbe
 * inosservato e la guardia si spegnerebbe restando verde.
 */
class SondaControllerIncompleto extends \App\Http\Controllers\Controller
{
    public function index(): string
    {
        return 'ci sono';
    }
}

class SondaControllerInvocabile
{
    public function __invoke(): string
    {
        return 'ci sono';
    }
}

it('la guardia morde: una rotta verso un metodo che non esiste viene trovata', function () {
    Route::get('sonda-rotta-fantasma/{id}', [SondaControllerIncompleto::class, 'show'])
        ->name('sonda.fantasma');

    expect(rotteSenzaMetodo())->toContain(
        'sonda.fantasma | GET|HEAD sonda-rotta-fantasma/{id} → '.SondaControllerIncompleto::class.'@show  [METODO ASSENTE]'
    );
});

it('una rotta verso un metodo che esiste non viene segnalata', function () {
    Route::get('sonda-rotta-sana', [SondaControllerIncompleto::class, 'index'])
        ->name('sonda.sana');

    expect(implode("\n", rotteSenzaMetodo()))->not->toContain('sonda.sana');
});

it('le closure e i controller a singola azione non producono falsi allarmi', function () {
    Route::get('sonda-closure', fn () => 'ci sono')->name('sonda.closure');
    Route::get('sonda-invocabile', SondaControllerInvocabile::class)->name('sonda.invocabile');

    $trovate = implode("\n", rotteSenzaMetodo());

    expect($trovate)->not->toContain('sonda.closure')
        ->and($trovate)->not->toContain('sonda.invocabile');
});
