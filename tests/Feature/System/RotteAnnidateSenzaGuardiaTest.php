<?php

use Illuminate\Support\Facades\Route;

/**
 * # Nessuna rotta annidata **nuova** senza guardia sul condominio
 *
 * Sotto `/gestionale/{condominio}` ci sono rotte che portano un **secondo** modello
 * nell'indirizzo — `{tabella}`, `{esercizio}`, `{immobile}`, `{cassa}`… Il binding implicito di
 * Laravel risolve i due modelli **per id, ciascuno per conto suo**: niente lega il figlio al
 * padre. Chi cambia l'id nell'indirizzo apre la risorsa di un altro condominio.
 *
 * ## Perché una guardia a mano e non `->scopeBindings()`
 *
 * Misurato aprendo la beta.61, e non è il rimedio che sembra. Laravel deriva il nome della
 * relazione da cercare con `Str::plural(Str::camel($childType))`
 * (`Illuminate/Database/Eloquent/Model.php:2510`), cioè una pluralizzazione **inglese** applicata
 * a nomi italiani: per `{tabella}` cerca `Condominio::tabellas()`, per `{esercizio}`
 * `Condominio::esercizios()`. Sulle 26 coppie padre>figlio del gestionale, le relazioni col nome
 * atteso sono **zero su 26**.
 *
 * E il fallimento non è un 404: `resolveChildRouteBindingQuery()` invoca la relazione come primo
 * statement, prima di qualunque query, quindi solleva `BadMethodCallException` — **500 su ogni
 * richiesta, anche legittima**. Due delle nidificazioni più usate (`esercizio > pianoConto`,
 * `esercizio > pianoRate`) non hanno nemmeno la colonna: il legame passa dal pivot
 * `esercizio_gestione`. La voce completa è la coda ㊷ in `docs/roadmap.md`.
 *
 * ## Cosa fa questa guardia, e cosa **non** fa
 *
 * Non giudica se il programma è sicuro: **congela il perimetro**. Alla beta.61 i metodi dietro
 * rotte con un figlio sono 112, di cui 29 con una guardia riconoscibile e 83 senza. I 83 sono
 * scritti qui sotto, uno per uno. Il debito che c'è resta e si paga nella coda ㊷; quello che
 * questo test impedisce è **aggiungerne di nuovo in silenzio**.
 *
 * Il test fallisce in due direzioni, ed è voluto:
 *
 * - una rotta annidata **nuova** senza guardia → rosso, e va guardata o iscritta all'elenco con
 *   una ragione;
 * - un metodo dell'elenco che **ha acquistato** la guardia (o è sparito) → rosso, e va tolto
 *   dall'elenco. Un elenco di eccezioni che nessuno accorcia diventa una lista della spesa.
 *
 * ## Il riconoscitore è **stretto di proposito**
 *
 * Riconosce due sole forme: il confronto `$figlio->{qualcosa}_id === $padre->id` (in `abort_unless`,
 * in un `if`, con o senza cast) e la chiamata a un metodo ausiliario `$this->assicura…()`. Non
 * riconosce le guardie esotiche, e non ci prova: chi ne scrive una diversa la iscrive all'elenco
 * con la ragione, oppure la riscrive in una delle due forme. Un riconoscitore che indovina
 * produce falsi positivi, e un falso positivo qui significa **dichiarare guardato ciò che non lo
 * è** — il difetto che questa guardia esiste per prendere.
 *
 * ⚠️ **Questa riga diceva il falso, ed è stata corretta dalla revisione avversariale della
 * beta.61.** Sosteneva che il riconoscitore non vedesse il controllo anti-IDOR di
 * `FatturaPassivaController@download` — che lega `{documento}` a `{fattura}` per
 * `documentable_id`. Lo vedeva eccome: la prima stesura accettava **qualunque** colonna `_id`, e
 * per questo promuoveva quel metodo fra i guardati mentre serviva il documento di un altro
 * condominio. Ora la Forma 1 pretende `condominio_id` per esteso.
 *
 * ## I punti ciechi, dichiarati e presidiati
 *
 * Il riconoscitore legge il **testo** del metodo, non il suo comportamento: non sa se la guardia
 * è raggiungibile, se il confronto viene usato, né **dove** sta rispetto alla scrittura. Le sette
 * forme che lo ingannano sono elencate e provate in fondo a questo file, in
 * «i punti ciechi dichiarati». Sono lì per due ragioni: perché una limitazione scritta solo in
 * prosa invecchia in silenzio, e perché la più insidiosa — una guardia spostata **sotto** la
 * chiamata che cancella — non fa diventare rosso niente.
 *
 * ## L'autocontrollo chiama la funzione vera
 *
 * È la lezione più cara della beta.60: le tre guardie di quella beta **si potevano svuotare senza
 * che un test diventisse rosso**, perché i loro autocontrolli ricopiavano l'espressione regolare
 * invece di chiamare la funzione che la usa. Qui i due controlli in fondo passano due classi
 * finte a `haGuardiaRiconoscibile()`, che è la stessa funzione usata dal test vero: sostituire
 * l'espressione regolare fa diventare rosso l'autocontrollo.
 */

/**
 * I metodi di controller dietro rotte del gestionale con **almeno un parametro oltre**
 * `{condominio}`, in ordine, senza ripetizioni.
 *
 * @return list<string> `Classe@metodo` (le rotte a singola azione diventano `Classe@__invoke`)
 */
function metodiDietroRotteAnnidate(): array
{
    $metodi = [];

    foreach (Route::getRoutes() as $rotta) {
        $nome = $rotta->getName() ?? '';

        if (! str_starts_with($nome, 'admin.gestionale.')) {
            continue;
        }

        // Una rotta che porta solo `{condominio}` non ha un figlio da legare.
        if (array_diff($rotta->parameterNames(), ['condominio']) === []) {
            continue;
        }

        $azione = $rotta->getActionName();
        $metodi[str_contains($azione, '@') ? $azione : $azione.'@__invoke'] = true;
    }

    $metodi = array_keys($metodi);
    sort($metodi);

    return $metodi;
}

/**
 * Il corpo del metodo contiene una guardia **riconoscibile** che lega il figlio al padre?
 *
 * Si legge il sorgente del metodo — non il file intero — perché una guardia in un altro metodo
 * della stessa classe non protegge questo.
 */
function haGuardiaRiconoscibile(string $classe, string $metodo): bool
{
    if (! class_exists($classe) || ! method_exists($classe, $metodo)) {
        return false;
    }

    $riflesso = new ReflectionMethod($classe, $metodo);
    $file = $riflesso->getFileName();

    if ($file === false) {
        return false;
    }

    $sorgente = implode('', array_slice(
        file($file),
        $riflesso->getStartLine() - 1,
        $riflesso->getEndLine() - $riflesso->getStartLine() + 1,
    ));

    // Forma 1: `$figlio->condominio_id === $condominio->id`, anche con cast e anche negata.
    //
    // ⚠️ **`condominio_id` per esteso, non un `_id` qualsiasi.** La prima stesura accettava
    // qualunque colonna, e per questo contava fra i guardati
    // `FatturaPassivaController@download`, che confronta `$documento->documentable_id` con
    // `$fattura->id`: è una guardia vera, ma lega il figlio al **padre intermedio**, non al
    // condominio dell'indirizzo — e infatti quel metodo serviva il documento di un'altra
    // fattura del condominio sbagliato. Un riconoscitore troppo largo non è indulgente:
    // **dichiara guardato ciò che non lo è**, che è il difetto per cui questa guardia esiste.
    if (preg_match('/\$\w+->condominio_id\s*(===|!==)\s*\(?\s*(int\)\s*)?\$\w+->id/', $sorgente) === 1) {
        return true;
    }

    // Forma 2: la guardia è stata estratta in un metodo ausiliario del controller.
    return preg_match('/\$this->assicura\w+\s*\(/', $sorgente) === 1;
}

/**
 * I metodi dietro rotte annidate che **oggi non hanno** una guardia riconoscibile.
 *
 * Contati alla beta.61: 83 su 112. Non è un elenco di difetti confermati — dentro ci sono metodi
 * che leggono soltanto e metodi che si difendono in altro modo — ma è **il perimetro entro cui il
 * difetto può nascondersi**, e non deve crescere.
 *
 * @var list<string>
 */
const SCOPERTI_NOTI = [
    'App\Http\Controllers\Gestionale\Casse\CassaController@edit',
    'App\Http\Controllers\Gestionale\Casse\CassaController@show',
    'App\Http\Controllers\Gestionale\Contributi\ContributoVersatoController@edit',
    'App\Http\Controllers\Gestionale\Contributi\ContributoVersatoController@update',
    'App\Http\Controllers\Gestionale\Esercizi\EsercizioController@edit',
    'App\Http\Controllers\Gestionale\Esercizi\EsercizioController@show',
    'App\Http\Controllers\Gestionale\Esercizi\EsercizioController@update',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@create',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@destroy',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@edit',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@index',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@show',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@store',
    'App\Http\Controllers\Gestionale\Gestioni\GestioneController@update',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@create',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@destroy',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@edit',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@index',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@show',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@store',
    'App\Http\Controllers\Gestionale\Immobili\Anagrafiche\ImmobileAnagraficaController@update',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@create',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@destroy',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@edit',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@index',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@store',
    'App\Http\Controllers\Gestionale\Immobili\Documenti\ImmobileDocumentoController@update',
    'App\Http\Controllers\Gestionale\Immobili\ImmobileController@edit',
    'App\Http\Controllers\Gestionale\Immobili\ImmobileController@pertinenze',
    'App\Http\Controllers\Gestionale\Immobili\ImmobileController@show',
    'App\Http\Controllers\Gestionale\Immobili\ImmobileController@update',
    'App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController@approva',
    'App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController@approvaSforo',
    'App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController@destroy',
    // ⚠️ `@download` **era contato fra i guardati** dalla prima stesura del riconoscitore:
    // contiene `$documento->documentable_id !== $fattura->id`, che lega il figlio al padre
    // intermedio ma non al condominio dell'indirizzo. Trovato dalla revisione avversariale
    // della beta.61, che lo ha dimostrato servendo il documento di un altro condominio.
    'App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController@download',
    'App\Http\Controllers\Gestionale\Movimenti\FatturaPassivaController@show',
    'App\Http\Controllers\Gestionale\Movimenti\GirocontoController@storno',
    'App\Http\Controllers\Gestionale\Movimenti\RegolazioneImmediataController@storno',
    'App\Http\Controllers\Gestionale\Movimenti\StornoFatturaController@__invoke',
    'App\Http\Controllers\Gestionale\Palazzine\PalazzinaController@destroy',
    'App\Http\Controllers\Gestionale\Palazzine\PalazzinaController@edit',
    'App\Http\Controllers\Gestionale\Palazzine\PalazzinaController@show',
    'App\Http\Controllers\Gestionale\Palazzine\PalazzinaController@update',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\AggiornaTabellaController@__invoke',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\AssociaTabellaController@__invoke',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController@destroy',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController@store',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\ContoController@update',
    'App\Http\Controllers\Gestionale\PianiConti\Conti\DissociaTabellaController@__invoke',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@create',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@destroy',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@edit',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@index',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@show',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@store',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiController@update',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiPrintController@distinta',
    'App\Http\Controllers\Gestionale\PianiConti\PianoContiPrintController@riparto',
    'App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController@destroy',
    'App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController@publishSilent',
    'App\Http\Controllers\Gestionale\PianiRate\EmissioneRateController@store',
    'App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController@print',
    'App\Http\Controllers\Gestionale\PianiRate\EstrattoContoAnagraficaController@show',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@create',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@destroy',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@detachCapitolo',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@fetchSaldiAnalitici',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@index',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@show',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@store',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateController@updateStato',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRateGenerationController@__invoke',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRatePrintController@ripartoCapitoli',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRatePrintController@ripartoTabelle',
    'App\Http\Controllers\Gestionale\PianiRate\PianoRatePrintController@scadenziario',
    'App\Http\Controllers\Gestionale\Saldi\SaldoInizialeController@update',
    'App\Http\Controllers\Gestionale\Scale\ScalaController@destroy',
    'App\Http\Controllers\Gestionale\Scale\ScalaController@edit',
    'App\Http\Controllers\Gestionale\Scale\ScalaController@show',
    'App\Http\Controllers\Gestionale\Scale\ScalaController@update',
    'App\Http\Controllers\Gestionale\Tabelle\TabellaController@edit',
    'App\Http\Controllers\Gestionale\Tabelle\TabellaController@show',
    'App\Http\Controllers\Gestionale\Tabelle\TabellaController@update',
];

it('nessuna rotta annidata nuova senza guardia sul condominio', function () {
    $scoperti = [];

    foreach (metodiDietroRotteAnnidate() as $metodo) {
        [$classe, $nome] = explode('@', $metodo);

        if (! haGuardiaRiconoscibile($classe, $nome)) {
            $scoperti[] = $metodo;
        }
    }

    $nuovi = array_values(array_diff($scoperti, SCOPERTI_NOTI));

    expect($nuovi)->toBe([], sprintf(
        "Rotte annidate NUOVE senza guardia sul condominio:\n  %s\n\n".
        "Il binding implicito risolve il figlio per id senza guardare il condominio dell'indirizzo. ".
        "Aggiungi la guardia — `abort_unless(\$figlio->condominio_id === \$condominio->id, 404)` — ".
        "oppure iscrivi il metodo in SCOPERTI_NOTI con la ragione per cui non serve.",
        implode("\n  ", $nuovi),
    ));
});

it("l'elenco delle scoperte non contiene metodi che nel frattempo si sono guardati", function () {
    $ancoraScoperti = [];

    foreach (metodiDietroRotteAnnidate() as $metodo) {
        [$classe, $nome] = explode('@', $metodo);

        if (! haGuardiaRiconoscibile($classe, $nome)) {
            $ancoraScoperti[] = $metodo;
        }
    }

    $daTogliere = array_values(array_diff(SCOPERTI_NOTI, $ancoraScoperti));

    expect($daTogliere)->toBe([], sprintf(
        "Questi metodi sono in SCOPERTI_NOTI ma oggi hanno la guardia (o non esistono più):\n  %s\n\n".
        "Vanno tolti dall'elenco. Un elenco di eccezioni che nessuno accorcia smette di dire la verità.",
        implode("\n  ", $daTogliere),
    ));
});

it('la pagina delle quote millesimali è guardata: era il caso da cui la coda ㊷ è nata', function () {
    $classe = \App\Http\Controllers\Gestionale\Tabelle\Quote\TabellaQuotaController::class;

    expect(haGuardiaRiconoscibile($classe, 'index'))->toBeTrue()
        ->and(haGuardiaRiconoscibile($classe, 'update'))->toBeTrue();
});

/**
 * Le due classi finte dell'autocontrollo. Servono a provare il **riconoscitore vero**, non una
 * copia della sua espressione regolare: se qualcuno svuota `haGuardiaRiconoscibile()`, questi due
 * controlli diventano rossi.
 */
class SondaControllerGuardato
{
    public function mostra($condominio, $figlio): void
    {
        abort_unless($figlio->condominio_id === $condominio->id, 404);
    }
}

class SondaControllerScoperto
{
    public function mostra($condominio, $figlio): void
    {
        $figlio->load('condominio');
    }
}

it('il riconoscitore vede la guardia dove c è', function () {
    expect(haGuardiaRiconoscibile(SondaControllerGuardato::class, 'mostra'))->toBeTrue();
});

it('il riconoscitore non inventa una guardia dove non c è', function () {
    expect(haGuardiaRiconoscibile(SondaControllerScoperto::class, 'mostra'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| I punti ciechi dichiarati
|--------------------------------------------------------------------------
|
| Il riconoscitore legge il **testo** del metodo, non il suo comportamento. Non sa se la guardia
| è raggiungibile, se il confronto viene usato, né dove sta rispetto alla scrittura.
|
| Questi controlli non provano che la guardia funzioni: provano **quanto è stretta**, e sono qui
| perché la revisione avversariale della beta.61 ha misurato che su nove forme evasive ne
| riconosceva **una sola**. Il docblock in testa diceva «è stretto di proposito» — vero, ma una
| limitazione che vive solo in prosa invecchia in silenzio e nessuno si accorge quando cambia.
|
| ⚠️ Se un giorno una di queste forme smette di ingannare il riconoscitore, il suo controllo
| diventa rosso. È voluto: significa che qualcuno lo ha reso più severo, e va scritto qui.
*/

class SondaGuardiaSottoLaScrittura
{
    public function distruggi($condominio, $tabella): void
    {
        // La guardia c'è, ma **dopo** che il danno è fatto.
        $tabella->quote()->delete();

        abort_unless($tabella->condominio_id === $condominio->id, 404);
    }
}

class SondaGuardiaIrraggiungibile
{
    public function mostra($condominio, $tabella): void
    {
        if (false) {
            abort_unless($tabella->condominio_id === $condominio->id, 404);
        }
    }
}

class SondaGuardiaSoloCommentata
{
    public function mostra($condominio, $tabella): void
    {
        // abort_unless($tabella->condominio_id === $condominio->id, 404);
        $tabella->load('quote');
    }
}

class SondaConfrontoIgnorato
{
    public function mostra($condominio, $tabella): void
    {
        // Il confronto si fa, il risultato si butta.
        $estranea = $tabella->condominio_id !== $condominio->id;
        unset($estranea);
    }
}

class SondaAusiliarioVuoto
{
    private function assicuraQualcosa(): void
    {
        // Non fa niente.
    }

    public function mostra($condominio, $tabella): void
    {
        $this->assicuraQualcosa();
    }
}

it('il riconoscitore non sa DOVE sta la guardia: una messa sotto la cancellazione passa', function () {
    // ⚠️ È il punto cieco più pericoloso dei cinque, perché non serve scrivere codice nuovo per
    // caderci: basta spostare di qualche riga una guardia che c'è già, e nessun test si accende.
    expect(haGuardiaRiconoscibile(SondaGuardiaSottoLaScrittura::class, 'distruggi'))->toBeTrue();
});

it('il riconoscitore non sa se la guardia è raggiungibile', function () {
    expect(haGuardiaRiconoscibile(SondaGuardiaIrraggiungibile::class, 'mostra'))->toBeTrue();
});

it('il riconoscitore non distingue il codice dal commento', function () {
    expect(haGuardiaRiconoscibile(SondaGuardiaSoloCommentata::class, 'mostra'))->toBeTrue();
});

it("il riconoscitore non sa se il confronto viene usato", function () {
    expect(haGuardiaRiconoscibile(SondaConfrontoIgnorato::class, 'mostra'))->toBeTrue();
});

it('il riconoscitore si fida del nome del metodo ausiliario, non di cosa fa', function () {
    expect(haGuardiaRiconoscibile(SondaAusiliarioVuoto::class, 'mostra'))->toBeTrue();
});
