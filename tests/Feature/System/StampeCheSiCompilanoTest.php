<?php

use Illuminate\Support\Facades\Blade;

/**
 * # Ogni stampa PDF si compila: nessun template muore in fase di parsing
 *
 * ## Il difetto che questa guardia esiste per prendere
 *
 * Nella beta.63 un commento è stato scritto **dentro** un blocco `@php … @endphp` con la sintassi
 * Blade `{{-- … --}}`. Sembra innocuo e non lo è: `BladeCompiler::compileString()` chiama
 * `storeUncompiledBlocks()` **prima** di `compileComments()`, e `storePhpBlocks()` estrae il
 * contenuto di `@php` come blocco grezzo. Il commento Blade non viene quindi mai compilato e
 * finisce **verbatim** nel PHP generato:
 *
 *     syntax error, unexpected token "**", expecting "->"
 *
 * Risultato: la stampa del riparto per tabelle rispondeva **500 su ogni piano rate di ogni
 * condominio** — non un caso limite, il documento che va in assemblea. Ed è passata indenne da
 * `npx vite build`, dalla suite intera (1595 test verdi) e dalla verifica a video, perché nessuna
 * delle tre apre un PDF.
 *
 * ⚠️ **La beta.44 aveva già scritto la lezione gemella:** *«`npx vite build` non è un controllo di
 * correttezza del template»*, a proposito di un `<Transition>` malformato che compilava in
 * silenzio. Qui vale per Blade, e con conseguenze peggiori: là il difetto stava in una schermata,
 * qui in un documento contabile.
 *
 * ## Perché un test e non attenzione
 *
 * Perché il difetto è **invisibile a tutto ciò che il progetto lancia abitualmente**. Un template
 * PDF non ha una rotta esercitata dai test, non passa da Vite, e la verifica a video guarda le
 * schermate. L'unico modo di accorgersene è aprire il PDF — cioè fare a mano, ogni volta, una cosa
 * che una riga di codice fa in mezzo secondo.
 *
 * ## Cosa questo test NON copre
 *
 * - **Non dice che il PDF sia giusto**: dice che il template si compila e che il PHP che ne esce è
 *   sintatticamente valido. Un totale sbagliato, una colonna vuota o un'impaginazione su due
 *   pagine passano di qui — quelli sono di `ConcordanzaMotoreStampaTest` e della verifica a video.
 * - **Non esegue il template**: non passa nessuna variabile, quindi un errore che si manifesta solo
 *   a runtime (una proprietà su `null`, un indice mancante) non viene visto.
 * - **Non copre le altre view**: il perimetro è `resources/views/pdf/`, cioè i documenti. Le view
 *   di posta e le altre hanno rotte e test propri.
 */

/**
 * I template dei documenti PDF, uno per uno.
 *
 * @return list<string> percorsi relativi alla radice del progetto
 */
function stampePdf(): array
{
    // ⚠️ **Percorso calcolato da `__DIR__`, non da `resource_path()`.** Il dataset di Pest è
    // valutato in fase di *raccolta* dei test, cioè **prima** che l'applicazione sia avviata: gli
    // helper di Laravel lì non rispondono ancora, e la prima stesura otteneva un elenco vuoto —
    // cioè una guardia che non guardava niente e passava.
    $radice = dirname(__DIR__, 3).'/resources/views/pdf';

    if (! is_dir($radice)) {
        return [];
    }

    $trovate = [];
    $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS));

    foreach ($iteratore as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $trovate[] = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname());
        }
    }

    sort($trovate);

    return $trovate;
}

/**
 * Il PHP che Blade genera da questo template è sintatticamente valido?
 *
 * Si compila e si passa a `php -l`, che è l'unico giudice che conta: è lo stesso parser che
 * incontrerà la richiesta vera.
 */
function ilTemplateSiCompila(string $percorsoRelativo): array
{
    $compilato = Blade::compileString(file_get_contents(dirname(__DIR__, 3).'/'.$percorsoRelativo));

    $tmp = tempnam(sys_get_temp_dir(), 'blade').'.php';
    file_put_contents($tmp, $compilato);

    $uscita = (string) shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    return [
        'valido' => str_contains($uscita, 'No syntax errors detected'),
        'uscita' => trim($uscita),
    ];
}

it('trova davvero dei template da controllare', function () {
    // ⚠️ Senza questo, il giorno che la cartella cambia nome la guardia diventa verde perché non
    // guarda più niente — la forma di guasto peggiore, perché si presenta come un successo.
    expect(stampePdf())->not->toBeEmpty();
});

it('ogni stampa PDF produce PHP sintatticamente valido', function (string $template) {
    $esito = ilTemplateSiCompila($template);

    expect($esito['valido'])->toBeTrue(
        "Il template «{$template}» non compila: la stampa risponderebbe 500.\n\n".
        "Causa più frequente: un commento Blade `{{-- … --}}` scritto DENTRO un blocco `@php … @endphp`.\n".
        "Lì i commenti si scrivono in PHP (`/* … */`), perché il compilatore estrae il blocco `@php`\n".
        "prima di compilare i commenti e quello Blade finisce verbatim nel PHP generato.\n\n".
        $esito['uscita']
    );
})->with(stampePdf());

it('la guardia morde: un commento Blade dentro @php non compila', function () {
    // L'autocontrollo passa dalla **stessa funzione** del test vero — è la lezione della beta.60:
    // una guardia provata su una copia prova la copia. Qui si compila la forma esatta del difetto
    // che ha rotto `riparto_tabelle.blade.php`, e si verifica che il parser la rifiuti.
    $rotto = <<<'BLADE'
    @php
        {{-- ⚠️ **commento Blade dentro @php** --}}
        $x = 1;
    @endphp
    BLADE;

    $compilato = Blade::compileString($rotto);
    $tmp = tempnam(sys_get_temp_dir(), 'blade').'.php';
    file_put_contents($tmp, $compilato);
    $uscita = (string) shell_exec('php -l '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    expect($uscita)->not->toContain('No syntax errors detected');
});
