<?php

use Illuminate\Support\Facades\Route;

/**
 * # Nessuna schermata chiama un nome di rotta che non esiste
 *
 * Il gemello lato interfaccia di `RotteSenzaMetodoTest`. Quello guarda le rotte che il server
 * registra verso il vuoto; questo guarda gli indirizzi che il **browser** costruisce verso il
 * vuoto. Il presupposto sbagliato è lo stesso, e vale la pena scriverlo una volta:
 *
 * > **un indirizzo che si scrive non è un indirizzo che risponde.**
 *
 * `route('x')` di Ziggy su un nome sconosciuto **solleva un'eccezione JavaScript**. Dove la
 * chiamata sta in un `href` del template, la pagina non si disegna; dove sta dentro una funzione
 * — la paginazione, un ordinamento, un pulsante — la pagina si disegna benissimo e muore al
 * primo clic. È il secondo caso quello che sopravvive, perché niente lo esercita.
 *
 * ## Perché esiste: è la terza volta
 *
 * La beta.54 trovò che piani rate e piani dei conti chiamavano una rotta inesistente e che ogni
 * cambio di pagina moriva in un errore JavaScript, e ne scrisse la lezione: *«una funzione che
 * nessuno ha mai esercitato non è funzionante finché non si dimostra il contrario»*. Le due
 * tabelle furono corrette. Alla beta.62 la stessa cosa era viva sull'elenco delle **gestioni**
 * (`gestionale.gestioni.index`: le gestioni stanno annidate sotto l'esercizio e quel nome non è
 * mai esistito), più due nomi nudi senza prefisso di ruolo.
 *
 * Correggere il caso l'ha già fatto la beta.54. Questa volta si chiude la classe.
 *
 * ## La regola, e perché non è «il nome deve esistere»
 *
 * `generateRoute('x')` antepone il prefisso del **ruolo di chi guarda**: `admin.x` per
 * l'amministratore, `user.x` per il condòmino. Un nome che esiste sotto `admin` e non sotto
 * `user` è normalissimo — tutto il gestionale è così — e pretendere entrambi produrrebbe
 * **271 segnalazioni** su un repository sano, cioè una guardia che si impara a saltare (la
 * lezione della beta.60: *una guardia che grida troppo si spegne, ed è peggio di una che non
 * c'è*). Il difetto certo è il nome che non esiste sotto **nessuno** dei due prefissi: lì non c'è
 * ruolo che tenga, la chiamata solleva per chiunque.
 *
 * Con questa regola le segnalazioni su un repository sano sono **zero**, e le tre che c'erano
 * quando la guardia è stata scritta erano tutte e tre vere.
 *
 * ## La seconda regola: sotto `user/` il prefisso non è una possibilità, è una certezza
 *
 * ⚠️ **La regola larga da sola ha un punto cieco, e l'ha trovato la revisione avversariale della
 * beta.62 con una vittima viva.** Un file che sta sotto `resources/js/pages/documenti/user/` è
 * servito **solo al condòmino**: lì `generateRoute()` antepone sempre `user.`, e un nome che
 * esiste solo come `admin.` è rotto al 100%, non «forse». La regola larga lo scusava perché il
 * nome esisteva — sotto l'altro prefisso.
 *
 * La vittima: `pages/documenti/user/DocumentiEdit.vue` chiamava `generateRoute('categorie.store')`
 * dentro il pannello «crea nuova categoria». `user.categorie.store` non esiste — il condòmino le
 * categorie non le crea — quindi Ziggy sollevava, l'eccezione finiva in un `catch` e diventava un
 * `console.error`: si premeva «Salva» e non succedeva niente, senza un messaggio. Il pannello è
 * stato **tolto** nella beta.62, non riparato.
 *
 * Costo misurato della regola stretta su tutto l'albero: **un solo rilievo**, esattamente quello.
 * È una guardia usabile, non una che grida.
 *
 * ## Cosa questo test NON copre
 *
 * - **I nomi costruiti a pezzi.** `route(\`gestionale.\${sezione}.index\`)` non è leggibile da
 *   qui, e non ci si prova: un riconoscitore che indovina produce falsi positivi, e un falso
 *   positivo qui significa dichiarare rotto ciò che funziona.
 * - **I parametri.** Un nome giusto chiamato senza i parametri che la rotta pretende — è
 *   esattamente il difetto delle gestioni, che oltre al nome sbagliato non passava `esercizio` —
 *   passa di qui. Ziggy se ne accorge a runtime, questa guardia no.
 * - **`generatePath()`**, che costruisce percorsi letterali invece di nomi: non c'è un elenco
 *   contro cui verificarli. Due indirizzi inesistenti costruiti così sono noti e stanno in
 *   roadmap.
 * - **I commenti in coda a una riga di codice.** Il riconoscitore toglie i blocchi `/* *​/` e le
 *   righe che *cominciano* con `//`, non un `//` a metà riga. Una chiamata commentata così
 *   verrebbe contata: è il punto cieco dichiarato, e si chiude spostando il commento a riga sua.
 *
 * ## L'autocontrollo chiama la funzione vera
 *
 * I due controlli in fondo passano un sorgente finto a `nomiDiRottaInesistenti()`, che è la
 * stessa funzione del test vero: svuotarla, o allentarne l'espressione, fa diventare rosso
 * l'autocontrollo. È la lezione della beta.60, dove tre guardie si potevano svuotare senza che
 * niente diventasse rosso perché i loro autocontrolli ricopiavano l'espressione invece di
 * chiamare la funzione.
 */

/**
 * Tutti i nomi di rotta registrati, come insieme.
 *
 * @return array<string, true>
 */
function nomiDiRottaRegistrati(): array
{
    $noti = [];

    foreach (Route::getRoutes() as $rotta) {
        if ($nome = $rotta->getName()) {
            $noti[$nome] = true;
        }
    }

    return $noti;
}

/**
 * Toglie i commenti, perché un riconoscitore che non li distingue dal codice segnala rimandi che
 * nessuno esegue.
 *
 * ⚠️ Non è pignoleria: la prima misura di questa guardia dava **tre** rilievi e uno era una
 * chiamata commentata in `incassi/DataTableRowActions.vue`, lasciata lì come promemoria di una
 * stampa da costruire. Contarla avrebbe fatto correggere un commento.
 */
function sorgenteSenzaCommenti(string $sorgente): string
{
    // Blocchi `/* … */`, compresi i docblock.
    $sorgente = preg_replace('#/\*[\s\S]*?\*/#', '', $sorgente) ?? $sorgente;

    // Righe che **cominciano** con `//`. Non si tocca un `//` a metà riga: distinguerlo da
    // `https://` richiederebbe un analizzatore vero, e il rischio di tagliare via del codice
    // è peggiore del rilievo di troppo.
    return preg_replace('/^[ \t]*\/\/.*$/m', '', $sorgente) ?? $sorgente;
}

/**
 * I rimandi a nomi di rotta che non esistono sotto **nessun** prefisso di ruolo.
 *
 * @param  array<string, true>  $noti
 * @return list<string>
 */
function nomiDiRottaInesistenti(string $sorgente, array $noti, string $etichetta = 'sonda'): array
{
    $sorgente = sorgenteSenzaCommenti($sorgente);
    $mancanti = [];

    $riga = function (string $s, int $posizione): int {
        return substr_count(substr($s, 0, $posizione), "\n") + 1;
    };

    // Un file che sta sotto `user/` è servito **solo al condòmino**, quindi `generateRoute()` gli
    // antepone sempre `user.`: lì il nome deve esistere con quel prefisso, non «con almeno uno dei
    // due». La regola larga da sola lasciava passare una vittima viva — vedi il docblock.
    $soloCondomino = str_contains($etichetta, '/user/');

    if (preg_match_all("/generateRoute\(\s*'([^']+)'/", $sorgente, $trovati, PREG_OFFSET_CAPTURE)) {
        foreach ($trovati[1] as $t) {
            if ($soloCondomino) {
                if (! isset($noti['user.'.$t[0]])) {
                    $mancanti[] = sprintf("%s:%d  generateRoute('%s') — pagina del condòmino, e user.%s non esiste", $etichetta, $riga($sorgente, $t[1]), $t[0], $t[0]);
                }

                continue;
            }

            if (! isset($noti['admin.'.$t[0]]) && ! isset($noti['user.'.$t[0]])) {
                $mancanti[] = sprintf("%s:%d  generateRoute('%s') — né admin. né user.", $etichetta, $riga($sorgente, $t[1]), $t[0]);
            }
        }
    }

    // `route('x')` letterale. La lettera prima esclude `generateRoute(`, che ha già il suo ramo;
    // le chiamate annidate `route(generateRoute('x'))` non combaciano, perché qui dopo la
    // parentesi si pretende un apice.
    //
    // ⚠️ **Qui il nome si pretende esatto**, senza la tolleranza sui prefissi del ramo sopra: una
    // chiamata letterale non passa da `generateRoute()` e non guadagna nessun prefisso, quindi
    // `route('dashboard')` solleva anche se `admin.dashboard` esiste. La prima stesura ammetteva
    // i prefissi anche qui e l'autocontrollo lo ha preso subito: era una guardia più larga del
    // difetto, cioè cieca proprio sul caso che aveva appena trovato in `AppSidebar.vue`.
    if (preg_match_all("/(?<![a-zA-Z])route\(\s*'([^']+)'/", $sorgente, $trovati, PREG_OFFSET_CAPTURE)) {
        foreach ($trovati[1] as $t) {
            if (! isset($noti[$t[0]])) {
                $mancanti[] = sprintf("%s:%d  route('%s')", $etichetta, $riga($sorgente, $t[1]), $t[0]);
            }
        }
    }

    return $mancanti;
}

it('nessuna schermata chiama un nome di rotta che non esiste', function () {
    $noti = nomiDiRottaRegistrati();
    $mancanti = [];

    $base = resource_path('js');
    $file = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($file as $f) {
        if (! $f->isFile() || ! preg_match('/\.(vue|ts)$/', $f->getFilename())) {
            continue;
        }

        // I file di test montano componenti con rotte finte: hanno il loro `globalThis.route`.
        if (str_contains($f->getFilename(), '.test.')) {
            continue;
        }

        $etichetta = str_replace(base_path().'/', '', $f->getPathname());
        $mancanti = array_merge($mancanti, nomiDiRottaInesistenti(file_get_contents($f->getPathname()), $noti, $etichetta));
    }

    sort($mancanti);

    expect($mancanti)->toBe([], sprintf(
        "%d rimandi a nomi di rotta che non esistono sotto nessun prefisso di ruolo.\n".
        "Ziggy solleva: in un `href` la pagina non si disegna, dentro una funzione muore al primo clic.\n\n%s\n",
        count($mancanti),
        implode("\n", $mancanti),
    ));
});

it('la guardia morde: un nome che non esiste sotto nessun prefisso viene trovato', function () {
    $noti = nomiDiRottaRegistrati();

    $sonda = <<<'JS'
    const a = route(generateRoute('gestionale.gestioni.index'), { condominio: 1 });
    const b = route('dashboard');
    JS;

    expect(nomiDiRottaInesistenti($sonda, $noti))->toHaveCount(2);
});

it('un nome che esiste anche sotto un solo prefisso non viene segnalato', function () {
    // È la regola che tiene la guardia utilizzabile: tutto il gestionale esiste solo sotto
    // `admin.`, e pretendere anche `user.` darebbe 271 rilievi su un repository sano.
    $noti = nomiDiRottaRegistrati();

    $sonda = "const a = route(generateRoute('gestionale.immobili.index'), { condominio: 1 });";

    expect(nomiDiRottaInesistenti($sonda, $noti))->toBe([]);
});

it('il riconoscitore non conta le chiamate commentate', function () {
    // Senza questo, la prima misura avrebbe fatto «correggere» un promemoria lasciato in un
    // commento — cioè avrebbe fatto perdere tempo esattamente come una guardia che grida troppo.
    $noti = nomiDiRottaRegistrati();

    $sonda = <<<'JS'
    // window.open(route(generateRoute('gestionale.incassi.stampa'), { id: 1 }), '_blank');
    /* const vecchio = route('rotta.sparita.da.tempo'); */
    JS;

    expect(nomiDiRottaInesistenti($sonda, $noti))->toBe([]);
});

it('sotto `user/` un nome che esiste solo come admin. viene segnalato', function () {
    // È il caso che la regola larga scusava: `admin.categorie.store` esiste, `user.categorie.store`
    // no, e la pagina è del condòmino. Senza questo controllo la seconda regola si può togliere
    // senza che niente diventi rosso.
    $noti = nomiDiRottaRegistrati();

    $sonda = "const r = route(generateRoute('categorie.store'));";

    expect(nomiDiRottaInesistenti($sonda, $noti, 'resources/js/pages/documenti/user/Sonda.vue'))
        ->toHaveCount(1)
        ->and(nomiDiRottaInesistenti($sonda, $noti, 'resources/js/pages/documenti/Sonda.vue'))
        ->toBe([]);
});
