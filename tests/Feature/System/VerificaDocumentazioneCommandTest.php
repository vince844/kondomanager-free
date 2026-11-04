<?php

/**
 * Il comando che misura lo stato di `docs/`.
 *
 * ## Perché questo file esiste
 *
 * Il comando è nato per essere lanciato di rado — in apertura di beta — e un comando che si lancia
 * di rado è quello che nessuno si accorge che ha smesso di funzionare. In più ha una regola di
 * giudizio che si può sbagliare in silenzio: distinguere un riferimento **certamente** rotto da uno
 * **ambiguo**. La prima versione sceglieva il file omonimo più lungo e dichiarava «risolve»,
 * facendo passare per sano un riferimento rotto: è la trappola della beta.52, misurare una cosa e
 * riportarla come se ne avesse misurata un'altra.
 *
 * ## Su un clone pubblico
 *
 * `docs/` è nel `.gitignore` salvo dieci file: su un clone pubblico la roadmap e gli altri documenti
 * di lavoro non ci sono. I test che la nominano (il riferimento rotto, l'età fra le serie, l'indice
 * delle voci di coda) o fallirebbero, o — l'ultimo — passerebbero misurando il vuoto: hanno il loro
 * `skip` motivato, che scatta solo quando la roadmap non c'è. Gli altri misurano i documenti che ci
 * sono, o un progetto di prova costruito dal test, e girano ovunque.
 *
 * ## I progetti di prova (Coda 458)
 *
 * Il comando guarda anche le sottocartelle di `docs/` e decide per cartella che cosa controllare.
 * Quelle regole non si provano sui documenti veri: un test che dipende da ciò che i documenti
 * contengono oggi diventa rosso quando qualcuno li corregge, ed è così che «distingue l'ambiguo dal
 * rotto» era verde solo finché nella roadmap restava un riferimento ambiguo. Qui ogni test
 * costruisce un progetto piccolo in una cartella temporanea — `docs/` e un po' di codice — e ci punta
 * l'applicazione con `setBasePath`.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il marciume semantico — un riferimento che risolve mentre il contenuto di quelle righe
 * è cambiato — perché il comando **non lo vede e non promette di vederlo**. Non copre la
 * formattazione dell'output oltre alle parole che contano.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

afterEach(function () {
    if (isset($this->basePathOriginale)) {
        app()->setBasePath($this->basePathOriginale);
    }

    if (isset($this->cartellaDiProva)) {
        File::deleteDirectory($this->cartellaDiProva);
    }
});

/** L'intestazione di stato, com'è nei documenti veri. */
const INTESTAZIONE_DI_PROVA = "<!-- verifica-documentazione -->\n> **Stato:** documento di prova.\n<!-- /verifica-documentazione -->\n";

/** Un file di `$righe` righe: il comando misura la lunghezza, il contenuto non conta. */
function fileDiRighe(int $righe): string
{
    return implode("\n", array_fill(0, $righe, '// riga'))."\n";
}

/**
 * Un progetto di prova in una cartella temporanea, e l'applicazione che ci punta.
 *
 * La radice sta dentro una cartella tutta del test, così niente di ciò che c'è sulla macchina di chi
 * lancia la suite finisce nel progetto di prova.
 *
 * @param  array<string, string>  $file  percorso relativo alla radice → contenuto
 */
function progettoDiProvaPerLaDocumentazione($test, array $file): void
{
    $test->cartellaDiProva = sys_get_temp_dir().'/km-verifica-doc-'.uniqid();
    $radice = $test->cartellaDiProva.'/kondomanager-free';

    foreach ($file as $percorso => $contenuto) {
        File::ensureDirectoryExists(dirname($radice.'/'.$percorso));
        file_put_contents($radice.'/'.$percorso, $contenuto);
    }

    File::ensureDirectoryExists($radice.'/docs');
    $test->basePathOriginale = base_path();
    app()->setBasePath($radice);
}

it('conta i documenti e dichiara la versione in sviluppo', function () {
    // ⚠️ L'età si misura **in beta**, quindi questi test parlano solo dentro un ciclo di beta.
    // Fissare qui la versione li rende indipendenti da dove si trova il progetto: senza, il giorno
    // del rilascio stabile diventano rossi tutti insieme — che è esattamente quello che è successo
    // il 26/08/2026, alzando la versione a `1.10.0`. Il comando era corretto: `betaCorrente()`
    // torna `null` su una stabile di proposito. Erano i test ad assumere di girare sempre in beta.
    config(['app.version' => '1.10.0-beta.50']);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('documenti')
        ->and($uscita)->toContain('versione in sviluppo');
});

it('un riferimento a un file inesistente è un falso certo', function () {
    Artisan::call('kondomanager:verifica-documentazione', ['--documento' => 'roadmap']);
    $uscita = Artisan::output();

    // Il documento vero non deve avere riferimenti a file spariti: se questo test si accende,
    // qualcuno ha rinominato o cancellato un file che la roadmap cita.
    expect($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno');
})->skip(fn () => ! file_exists(base_path('docs/roadmap.md')), 'docs/ è gitignorato: la roadmap non è su questa macchina');

it('Coda 458 — distingue l\'ambiguo dal rotto e dall\'assente su un documento di prova, invece di scegliere per conto suo', function () {
    // Due file con lo stesso nome, di 10 e di 3 righe. Il riferimento alla riga 2 sta in tutti e
    // due: risolve. Alla riga 5 sta nel lungo e non nel corto: ambiguo, perché il documento parla di
    // uno solo dei due e quale lo sa chi l'ha scritto. Alla riga 50 non sta in nessuno: falso certo.
    // Un nome che non esiste in nessun posto è un file assente.
    progettoDiProvaPerLaDocumentazione($this, [
        'app/Lungo/Doppio.php' => fileDiRighe(10),
        'app/Corto/Doppio.php' => fileDiRighe(3),
        'docs/prova.md' => INTESTAZIONE_DI_PROVA."\nSta in tutti: `Doppio.php:2`. Ambiguo: `Doppio.php:5`. Rotto: `Doppio.php:50`. Assente: `Sparito.php:1`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti ambigui: il nome del file non basta a trovarlo (1)')
        ->and($uscita)->toContain('prova.md → Doppio.php:5 (2 file con questo nome, il più corto ne ha 3)')
        ->and($uscita)->toContain('Si risolve scrivendo il percorso, non indovinando.')
        ->and($uscita)->toContain('Riferimenti file:riga che non risolvono (2)')
        ->and($uscita)->toContain('prova.md → Doppio.php:50 (il file più lungo con questo nome ha 10 righe)')
        ->and($uscita)->toContain('prova.md → Sparito.php:1 (file assente)')
        ->and($uscita)->not->toContain('Doppio.php:2');
});

it('Coda 458 — entra nelle sottocartelle: un documento senza intestazione in verbali/ è segnalato col suo percorso', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/primo.md' => INTESTAZIONE_DI_PROVA,
        'docs/verbali/1.12/senza.md' => "# Un verbale senza intestazione di stato\n",
        'docs/verbali/1.12/con.md' => INTESTAZIONE_DI_PROVA,
        'docs/archivio/vecchio.md' => "# Un documento archiviato senza intestazione\n",
        'docs/.nascosta/senza.md' => "# Una cartella nascosta, com'è .git: non è documentazione\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('4 documenti')
        ->and($uscita)->toContain('Senza intestazione di stato (2)')
        ->and($uscita)->toContain('· verbali/1.12/senza.md')
        ->and($uscita)->toContain('· archivio/vecchio.md')
        ->and($uscita)->not->toContain('· verbali/1.12/con.md')
        ->and($uscita)->not->toContain('· primo.md')
        ->and($uscita)->not->toContain('nascosta');
});

it('Coda 458 — in verbali/ e in archivio/ i riferimenti file:riga non si controllano, al primo livello sì', function () {
    // Un verbale racconta il codice com'era quel giorno: il file citato può essere stato spostato o
    // accorciato da allora, ed è storia, non un guasto. Lo stesso riferimento in un documento vivo
    // dice una cosa falsa oggi.
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nCita `Sparito.php:1`.\n",
        'docs/strategia/analisi.md' => INTESTAZIONE_DI_PROVA."\nCita `Sparito.php:1`.\n",
        'docs/verbali/1.11/storia.md' => INTESTAZIONE_DI_PROVA."\nCita `Sparito.php:1`.\n",
        'docs/archivio/chiuso.md' => INTESTAZIONE_DI_PROVA."\nCita `Sparito.php:1`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('· vivo.md → Sparito.php:1 (file assente)')
        ->and($uscita)->toContain('· strategia/analisi.md → Sparito.php:1 (file assente)')
        ->and($uscita)->not->toContain('storia.md →')
        ->and($uscita)->not->toContain('chiuso.md →');
});

it('Coda 458 — le copie in riferimenti/ non rendono ambiguo il nome di un file del progetto né di un documento', function () {
    // I lavori delle beta lasciano in `riferimenti/` le copie del codice com'era e dei documenti
    // com'erano: un file con lo stesso nome, più corto. Se entrano nell'indice dei nomi, un documento
    // che cita il file vero diventa «ambiguo» senza esserlo. Qui il codice (`Servizio.php`) e un
    // verbale (`piano_esecutivo.md`, che si cita per nome, perché sta in una sottocartella); una
    // cartella nascosta (il repository dei documenti) non è un posto dove cercare.
    progettoDiProvaPerLaDocumentazione($this, [
        'app/Servizio.php' => fileDiRighe(10),
        'docs/riferimenti/lavoro/Servizio.php' => fileDiRighe(3),
        'docs/verbali/1.12/piano_esecutivo.md' => INTESTAZIONE_DI_PROVA.str_repeat("riga\n", 10),
        'docs/riferimenti/lavoro/piano_esecutivo.md' => str_repeat("riga\n", 3),
        'docs/.copia-nascosta/Servizio.php' => fileDiRighe(3),
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nLa logica sta in `Servizio.php:8` e il verbale in `piano_esecutivo.md:8`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti ambigui: il nome del file non basta a trovarlo: nessuno')
        ->and($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno');
});

it('Coda 458 — un percorso scritto a partire da docs/ si risolve anche da un documento di una sottocartella', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/riferimenti/lavoro/note.md' => str_repeat("riga\n", 5),
        'docs/strategia/analisi.md' => INTESTAZIONE_DI_PROVA."\nLa nota è `riferimenti/lavoro/note.md:4`, ma `riferimenti/lavoro/note.md:40` non esiste.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('· strategia/analisi.md → riferimenti/lavoro/note.md:40 (il file più lungo con questo nome ha 5 righe)')
        ->and($uscita)->not->toContain('note.md:4 ')
        ->and($uscita)->not->toContain('file assente');
});

it('Coda 458 — un file che sta in docs/ fuori da riferimenti/ si trova per nome, qualunque sia l\'estensione', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/cla/firme/firma.json' => fileDiRighe(5),
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nLa firma è in `firma.json:4`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno');
});

it('Coda 458 — un percorso scritto con le parentesi graffe delle lingue si risolve nella cartella delle lingue', function () {
    // I documenti scrivono `lang/{en,es,pt}/policies.php:55`: per il comando il riferimento è il
    // nome, `policies.php`, e il nome sta in `lang/`. Senza `lang` nell'indice risolveva solo grazie
    // a una copia lasciata in `riferimenti/`.
    progettoDiProvaPerLaDocumentazione($this, [
        'lang/it/policies.php' => fileDiRighe(60),
        'lang/en/policies.php' => fileDiRighe(60),
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nLa frase è in `lang/{en,it}/policies.php:55`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno')
        ->and($uscita)->toContain('Riferimenti ambigui: il nome del file non basta a trovarlo: nessuno');
});

it('Coda 458 — un link fra documenti si risolve rispetto al file che lo contiene', function () {
    // Da `verbali/1.12/v.md`, `roadmap.md` è `verbali/1.12/roadmap.md`, che non c'è: il link è rotto
    // anche se `docs/roadmap.md` esiste. Su GitHub quel link non porta da nessuna parte.
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/roadmap.md' => INTESTAZIONE_DI_PROVA,
        'docs/verbali/1.12/altro.md' => INTESTAZIONE_DI_PROVA,
        'docs/verbali/1.12/v.md' => INTESTAZIONE_DI_PROVA
            ."\n[giusto, in alto](../../roadmap.md) · [giusto, accanto](altro.md#sezione) · [rotto](roadmap.md)\n",
        'docs/indice.md' => INTESTAZIONE_DI_PROVA."\n[giusto, in basso](verbali/1.12/v.md) · [rotto](altro.md)\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Link fra documenti che non risolvono (2)')
        ->and($uscita)->toContain('· verbali/1.12/v.md → roadmap.md')
        ->and($uscita)->toContain('· indice.md → altro.md')
        ->and($uscita)->not->toContain('../../roadmap.md')
        ->and($uscita)->not->toContain('altro.md#sezione')
        ->and($uscita)->not->toContain('verbali/1.12/v.md → verbali');
});

it('Coda 458 — i riferimenti file:riga dei documenti di kondocloud/ non si giudicano: citano anche Coolify e le librerie', function () {
    // Un documento di kondocloud/ cita il codice di un repository a parte, ma anche quello di Coolify
    // (`ApplicationDeploymentJob.php:4168`) e delle librerie: un `file:riga` non dice di quale
    // repository parla, e «file assente» sarebbe un falso certo. L'intestazione e i link, invece,
    // si controllano come altrove.
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/kondocloud/piano.md' => INTESTAZIONE_DI_PROVA."\nIl lavoro è in `ApplicationDeploymentJob.php:4168`, e [la guida](che_non_c_e.md) lo racconta.\n",
        'docs/kondocloud/senza.md' => "# Senza intestazione\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno')
        ->and($uscita)->not->toContain('file assente')
        ->and($uscita)->toContain('· kondocloud/senza.md')
        ->and($uscita)->toContain('· kondocloud/piano.md → che_non_c_e.md');
});

it('Coda 458 — due corse nello stesso processo: la seconda vede un file aggiunto dopo la prima, e il nome diventa ambiguo', function () {
    // Il comando è lo stesso oggetto per tutte le chiamate di `Artisan::call` dell'applicazione: l'indice dei nomi non deve passare dall'una
    // all'altra, o la seconda corsa misura l'albero com'era alla prima.
    progettoDiProvaPerLaDocumentazione($this, [
        'app/Servizio.php' => fileDiRighe(10),
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nLa logica sta in `Servizio.php:8`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $prima = Artisan::output();
    expect($prima)->toContain('Riferimenti ambigui: il nome del file non basta a trovarlo: nessuno')
        ->and($prima)->toContain('Riferimenti file:riga che non risolvono: nessuno');

    // Dopo la prima corsa arriva un secondo file con lo stesso nome, più corto: il riferimento alla riga 8 sta in uno solo dei due.
    File::ensureDirectoryExists(base_path('app/Corto'));
    file_put_contents(base_path('app/Corto/Servizio.php'), fileDiRighe(3));

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti ambigui: il nome del file non basta a trovarlo (1)')
        ->and($uscita)->toContain('vivo.md → Servizio.php:8 (2 file con questo nome, il più corto ne ha 3)');
});

it('Coda 458 — due corse nello stesso processo: la seconda non cade su un file tolto dopo la prima, lo dice assente', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'app/Servizio.php' => fileDiRighe(10),
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA."\nLa logica sta in `Servizio.php:8`.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    expect(Artisan::output())->toContain('Riferimenti file:riga che non risolvono: nessuno');

    File::delete(base_path('app/Servizio.php'));

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Riferimenti file:riga che non risolvono (1)')
        ->and($uscita)->toContain('· vivo.md → Servizio.php:8 (file assente)');
});

it('Coda 458 — il changelog e il testo del CLA non descrivono codice: l\'intestazione non si chiede, a un documento qualunque sì', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/changelog.md' => "# Changelog\n",
        'docs/cla/v1.0/testo.md' => "# Contributor License Agreement\n",
        'docs/cla/LEGGIMI.md' => "# Come funziona il CLA\n",
        'docs/altro/changelog.md' => "# Un changelog che non è quello del prodotto\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Senza intestazione di stato (2)')
        ->and($uscita)->toContain('· cla/LEGGIMI.md')
        ->and($uscita)->toContain('· altro/changelog.md')
        ->and($uscita)->not->toContain('· changelog.md')
        ->and($uscita)->not->toContain('· cla/v1.0/testo.md');
});

it('Coda 458 — l\'età non conta i verbali, l\'archivio e il materiale grezzo: sono storia', function () {
    config(['app.version' => '1.12.0-beta.5']);

    $storia = "<!-- verifica-documentazione -->\n> **Stato:** verificato su 1.11.0-beta.60.\n<!-- /verifica-documentazione -->\n";
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/vivo.md' => "<!-- verifica-documentazione -->\n> **Stato:** verificato su 1.12.0-beta.1.\n<!-- /verifica-documentazione -->\n",
        'docs/design/mockup.md' => $storia,
        'docs/verbali/1.11/beta60.md' => $storia,
        'docs/archivio/vecchio.md' => $storia,
        'docs/riferimenti/lavoro/note.md' => $storia,
    ]);

    Artisan::call('kondomanager:verifica-documentazione', ['--eta' => 1]);
    $uscita = Artisan::output();

    // Beta 5 meno beta 1 = 4; un documento che cita solo la serie precedente vale 0, quindi 5.
    expect($uscita)->toContain('4 beta fa  vivo.md')
        ->and($uscita)->toContain('5 beta fa  design/mockup.md')
        ->and($uscita)->not->toContain('verbali/1.11/beta60.md')
        ->and($uscita)->not->toContain('archivio/vecchio.md')
        ->and($uscita)->not->toContain('riferimenti/lavoro/note.md');
});

it('Coda 458 — il materiale grezzo di riferimenti/ non chiede intestazione né riferimenti, ma i suoi link si guardano', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA,
        'docs/riferimenti/forum/post.md' => "Un messaggio copiato dal forum. Cita `Sparito.php:1`, e [una pagina](che_non_c_e.md).\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Senza intestazione di stato: nessuno')
        ->and($uscita)->toContain('Riferimenti file:riga che non risolvono: nessuno')
        ->and($uscita)->toContain('· riferimenti/forum/post.md → che_non_c_e.md');
});

it('Coda 458 — --documento limita anche per percorso, non solo per nome del file', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/vivo.md' => "# Senza intestazione\n",
        'docs/verbali/1.12/v.md' => "# Senza intestazione\n",
        'docs/verbali/1.11/v.md' => "# Senza intestazione\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione', ['--documento' => 'verbali/1.12']);
    $uscita = Artisan::output();

    expect($uscita)->toContain('1 documenti')
        ->and($uscita)->toContain('· verbali/1.12/v.md')
        ->and($uscita)->not->toContain('· verbali/1.11/v.md')
        ->and($uscita)->not->toContain('· vivo.md');
});

it('Coda 458 — dichiara per cartella che cosa guarda, così chi legge sa che cosa non è stato controllato', function () {
    progettoDiProvaPerLaDocumentazione($this, [
        'docs/vivo.md' => INTESTAZIONE_DI_PROVA,
        'docs/verbali/1.12/v.md' => INTESTAZIONE_DI_PROVA,
        'docs/kondocloud/piano.md' => INTESTAZIONE_DI_PROVA,
        'docs/riferimenti/forum/post.md' => "Un messaggio.\n",
    ]);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('Che cosa si guarda, per cartella')
        ->and($uscita)->toMatch('#primo livello\s+1\s+intestazione, età, riferimenti, link#')
        ->and($uscita)->toMatch('#verbali/\s+1\s+intestazione, link#')
        ->and($uscita)->toMatch('#kondocloud/\s+1\s+intestazione, età, link#')
        ->and($uscita)->toMatch('#riferimenti/\s+1\s+link#');
});

it('mostra l\'età in beta, che è la cosa che nessuno calcola leggendo', function () {
    config(['app.version' => '1.10.0-beta.50']);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    expect($uscita)->toContain('beta fa');
});

it('l\'età non mescola le serie: i numeri di beta ripartono da uno', function () {
    // ⚠️ Reperto della revisione della beta.56. L'età si calcolava sul numero più alto trovato,
    // qualunque serie fosse: all'apertura della 1.11 ogni documento verificato nella 1.10 avrebbe
    // avuto un'età **negativa** — «-37 beta fa» — e il comando esiste proprio per dire l'età.
    config(['app.version' => '1.11.0-beta.3']);

    Artisan::call('kondomanager:verifica-documentazione', ['--eta' => 1]);
    $uscita = Artisan::output();

    expect($uscita)->not->toMatch('#-\d+ beta fa#');

    // E la roadmap, verificata dentro la 1.10, dev'essere fra i vecchi: non fra i freschi.
    expect($uscita)->toContain('roadmap.md');
})->skip(fn () => ! file_exists(base_path('docs/roadmap.md')), 'docs/ è gitignorato: la roadmap non è su questa macchina');

it('il filtro sull\'età non mostra niente quando nessun documento la supera', function () {
    config(['app.version' => '1.10.0-beta.50']);

    Artisan::call('kondomanager:verifica-documentazione', ['--eta' => 999]);
    $uscita = Artisan::output();

    expect($uscita)->toContain('Nessun documento più vecchio di 999 beta.');
});

it('pretende che ogni voce di coda aperta stia nell\'indice della roadmap', function () {
    // L'indice ha le parole chiave scritte a mano — è il suo valore — ma la completezza la
    // verifica il comando: una lista mantenuta a memoria invecchia da sola.
    Artisan::call('kondomanager:verifica-documentazione');

    expect(Artisan::output())->toContain('Voci di coda che l\'indice della roadmap non elenca: nessuno');
})->skip(fn () => ! file_exists(base_path('docs/roadmap.md')), 'docs/ è gitignorato: la roadmap non è su questa macchina');

it('non chiede al changelog un\'intestazione di stato', function () {
    // Il changelog non descrive codice: descrive cosa è cambiato, e ogni voce porta la sua
    // versione. Segnalarlo era un rilievo che nessuno avrebbe mai chiuso — e un elenco con dentro
    // una voce eterna è un elenco che si impara a saltare.
    Artisan::call('kondomanager:verifica-documentazione');

    expect(Artisan::output())->not->toContain('· changelog.md');
});

it('non modifica niente: è una diagnosi', function () {
    // Tutti i `.md` di `docs/`, sottocartelle comprese: il comando ora li legge tutti.
    $tutti = fn () => collect(File::allFiles(base_path('docs')))
        ->filter(fn ($f) => $f->getExtension() === 'md' && ! str_contains($f->getPathname(), '/.git/'))
        ->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())]);

    $prima = $tutti();

    Artisan::call('kondomanager:verifica-documentazione');

    expect($tutti()->all())->toBe($prima->all());
});

/**
 * ★ Il caso che nessuno aveva mai eseguito: la suite su una versione **stabile**.
 *
 * Scoperto il 26/08/2026 alzando la versione da `1.10.0-beta.77` a `1.10.0` per il rilascio: tre
 * test di questo file sono diventati rossi insieme. Il comando si comportava bene — l'età in beta
 * non è calcolabile quando non c'è un numero di beta, e `betaCorrente()` torna `null` apposta — ma
 * nessun test lo diceva, quindi il rosso sembrava un guasto invece di un comportamento previsto.
 *
 * In settantasette beta la suite non era mai girata con una versione non-beta in `config/app.php`,
 * e quel giorno arriva **una volta per release**: cioè il giorno peggiore per scoprire una cosa così.
 */
it('su una versione stabile non parla di beta, invece di rompersi', function () {
    config(['app.version' => '1.10.0']);

    Artisan::call('kondomanager:verifica-documentazione');
    $uscita = Artisan::output();

    // Continua a fare il suo mestiere: conta i documenti e ne verifica i riferimenti.
    expect($uscita)->toContain('documenti')
        // Ma non inventa un'età che non ha modo di calcolare.
        ->and($uscita)->not->toContain('versione in sviluppo')
        ->and($uscita)->not->toContain('beta fa');
});
