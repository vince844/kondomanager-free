<?php

/**
 * Chi chiama un metodo di un trait deve **avere** quel trait.
 *
 * ## Il difetto che questo test esiste per prendere
 *
 * Nella beta.54 `GestioneController` è rimasto con l'`use App\Traits\OrdinaElenco;` in testa al
 * file — l'import — ma **senza** la riga `use OrdinaElenco;` dentro la classe, che è quella che
 * innesta davvero il trait. Sono due cose diverse che si scrivono uguali, e PHP non se ne lamenta
 * finché qualcuno non apre la pagina: lì diventa un fatale «Call to undefined method», cioè una
 * schermata bianca sull'elenco delle gestioni.
 *
 * Non l'ha preso nessuno perché non esiste un test che apra quell'elenco, e `php -l` non lo vede:
 * la sintassi è perfetta. L'ha trovato una rilettura, che è un modo troppo fragile per presidiare
 * ventisei controller.
 *
 * ## Perché un test sul codice e non sulle pagine
 *
 * La copertura vera sarebbe una richiesta per ognuno dei ventisei elenchi, con i suoi permessi, il
 * suo condominio e il suo esercizio: righe di preparazione a decine, e comunque una per volta da
 * ricordarsi di scrivere quando nasce l'elenco ventisettesimo. Questo controllo invece **si
 * aggiorna da solo** — cerca i chiamanti, non una lista scritta a mano — e il costo è una lettura
 * di file.
 */

use Illuminate\Support\Facades\File;

/** I metodi che arrivano da un trait, con il trait che li porta. */
dataset('metodi dei trait degli elenchi', [
    'ordina() → OrdinaElenco'          => ['ordina', \App\Traits\OrdinaElenco::class],
    'righePerPagina() → PaginaElenco'  => ['righePerPagina', \App\Traits\PaginaElenco::class],
]);

it('ogni classe che chiama il metodo usa davvero il trait', function (string $metodo, string $trait) {
    $mancanti = [];

    $file = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php');

    foreach ($file as $f) {
        $sorgente = $f->getContents();

        if (! str_contains($sorgente, "\$this->{$metodo}(")) {
            continue;
        }

        // Dal percorso al nome della classe: `app/Http/Controllers/X.php` → `App\Http\Controllers\X`.
        $classe = 'App\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            ltrim(str_replace(app_path(), '', $f->getPathname()), '/')
        );

        if (! class_exists($classe)) {
            continue;
        }

        // ⚠️ `class_uses()` da solo non basta: guarda la classe e non i suoi genitori, e un trait
        // ereditato da una classe base sarebbe un falso allarme.
        if (! in_array($trait, traitUsatiDaTuttaLaGerarchia($classe), true)) {
            $mancanti[] = $classe;
        }
    }

    expect($mancanti)->toBe([],
        "queste classi chiamano \${this}->{$metodo}() senza innestare {$trait}: "
        .'l\'import in testa al file non basta, serve la riga `use` dentro la classe');
})->with('metodi dei trait degli elenchi');

it('ogni elenco che ordina valida anche le due chiavi dell\'ordinamento', function () {
    // ⚠️ Il difetto gemello del precedente, e altrettanto silenzioso. `ordina()` legge la colonna
    // da `$validated`: se `sort` e `direction` non compaiono fra le **regole**, `validate()` non le
    // restituisce, la chiave non esiste e l'ordinamento non viene mai applicato. A video le frecce
    // restano cliccabili e non succede niente — nessun errore, nessuna traccia nei log.
    //
    // Trovato a video il 16/08/2026 su «Elenco condomini»: l'indirizzo diceva `direction=desc` e
    // l'elenco era in ordine crescente. Riguardava anche l'elenco utenti.
    //
    // Gli elenchi con una FormRequest sono al sicuro per costruzione — `regoleOrdinamento()` sta
    // dentro `rules()` — quindi il controllo serve a quelli che validano in linea, che sono proprio
    // quelli dove la dimenticanza è possibile.
    // ⚠️ Il controllo guarda **il metodo che ordina**, non il file. Un controller può validare in
    // linea dentro `store()` e usare una FormRequest in `index()` — è il caso di PianoRateController
    // — e sarebbe un falso allarme che a lungo andare insegna a ignorare il test.
    $mancanti = [];

    foreach (File::allFiles(app_path('Http/Controllers')) as $f) {
        $sorgente = $f->getContents();

        if (! str_contains($sorgente, '$this->ordina(') || str_contains($sorgente, 'regoleOrdinamento')) {
            continue;
        }

        $fine = strpos($sorgente, '$this->ordina(');
        $inizio = strrpos(substr($sorgente, 0, $fine), 'public function ');
        $metodo = substr($sorgente, $inizio, $fine - $inizio);

        if (str_contains($metodo, 'request->validate([')) {
            $mancanti[] = $f->getFilename();
        }
    }

    expect($mancanti)->toBe([],
        'questi controller validano in linea e ordinano, ma non uniscono self::regoleOrdinamento(): '
        .'`sort` non arriva in $validated e l\'ordinamento non si applica, senza dare errore');
});

/** I trait di una classe, dei suoi genitori e dei trait annidati. */
function traitUsatiDaTuttaLaGerarchia(string $classe): array
{
    $trait = [];

    for ($c = $classe; $c !== false; $c = get_parent_class($c)) {
        foreach (class_uses($c) ?: [] as $t) {
            $trait[] = $t;
            foreach (class_uses($t) ?: [] as $annidato) {
                $trait[] = $annidato;
            }
        }
    }

    return array_unique($trait);
}
