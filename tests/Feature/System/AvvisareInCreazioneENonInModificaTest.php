<?php

/**
 * # Chi avvisa qualcuno alla creazione deve aver deciso cosa fa alla modifica
 *
 * ## La forma che si ripete, e che questa guardia esiste per fermare
 *
 * Aprendo la beta.64 un amministratore ha segnalato che le notifiche partono solo per le
 * comunicazioni nuove e mai per le modifiche. Misurando il perimetro sono risultati **sei
 * controller su sei** in cui `store()` lanciava un evento e `update()` no.
 *
 * Ma non è la prima volta. Il changelog registra già due difetti della stessa identica forma:
 *
 * - *«il riparto manuale arrivava dal form solo alla creazione e non veniva conservato»* — al primo
 *   ricalcolo tornava il pro-quota automatico, con importi diversi e nessun avviso;
 * - *«nessun controllo di capienza conto in modifica pagamento»* — la modale esisteva solo in
 *   creazione, quindi una modifica poteva portare un conto in scoperto in silenzio.
 *
 * Tre volte la stessa cosa. Su questo progetto la regola è che alla terza non si corregge il caso:
 * si costruisce la guardia — è successo per le rotte morte nella beta.62 e per i modelli di stampa
 * nella .63.
 *
 * ## Cosa questa guardia asserisce davvero
 *
 * ⚠️ **Non asserisce che `update()` avvisi.** Sarebbe sbagliato: ci sono casi in cui non avvisare è
 * la scelta giusta, e un test che pretendesse la simmetria costringerebbe a scrivere notifiche
 * inutili per farlo tacere.
 *
 * Asserisce una cosa più modesta e più utile: **che qualcuno abbia deciso**. Ogni controller il cui
 * `store()` avvisa deve comparire in una delle due liste qui sotto — quelli che avvisano anche in
 * modifica, e quelli che di proposito non lo fanno, **con il motivo scritto**. Un controller nuovo
 * che avvisa alla creazione e non è in nessuna delle due fa diventare rosso questo file, e chi lo
 * ha scritto deve scegliere invece di scoprirlo otto mesi dopo da una segnalazione.
 *
 * ## Cosa NON copre
 *
 * - **Non segue le chiamate oltre un livello.** Se `update()` chiama un metodo che ne chiama un
 *   altro che lancia l'evento, questa guardia non lo vede. Un livello copre la forma che il
 *   progetto usa (`$this->avvisaDopoLaModifica(...)`) ed è il compromesso dichiarato: più
 *   profondità vorrebbe dire scrivere un analizzatore, e un analizzatore ha difetti suoi.
 * - **Non dice che l'avviso sia giusto**: dice che c'è. Che vada alle persone giuste è di
 *   `tests/Feature/Notifiche/`.
 * - **Non copre le Action e i Service**: il perimetro sono i controller, che è dove la forma si è
 *   presentata tutte e tre le volte.
 */

/**
 * I controller in cui `update()` avvisa, direttamente o dal suo metodo dedicato.
 *
 * Corretti nella beta.64: chi viene aggiunto alla platea in modifica riceve l'oggetto (per lui è
 * nuovo), e chi c'era già riceve un avviso di modifica se l'amministratore spunta la casella.
 */
const AVVISA_ANCHE_IN_MODIFICA = [
    'Comunicazioni/ComunicazioneController.php',
    'Segnalazioni/SegnalazioneController.php',
    'Documenti/DocumentoController.php',
];

/**
 * I controller in cui `update()` **non** avvisa, e la ragione per cui va bene così.
 *
 * ⚠️ Questa lista non è un condono: è una decisione registrata. Una voce senza motivo scritto è
 * una voce che nessuno ha deciso, e prima o poi torna dal forum.
 */
const NON_AVVISA_DI_PROPOSITO = [
    // Le tre porte dell'area utente: qui il destinatario dell'avviso di creazione è
    // l'**amministratore**, non il condominio. Un condòmino che corregge la propria segnalazione
    // o comunicazione — spesso subito dopo averla inviata, perché si è accorto di un refuso —
    // riempirebbe la casella dell'amministratore di avvisi su cose che ha già in elenco e che
    // deve comunque approvare a mano.
    //
    // Deciso il 21/08/2026, ed è reversibile: se gli amministratori chiederanno di essere
    // avvisati quando un condòmino modifica una richiesta **già approvata** — che è il caso in cui
    // la modifica cambia qualcosa di sostanziale — il meccanismo c'è già ed è la stessa riga usata
    // dalle tre porte qui sopra.
    'Comunicazioni/Utenti/ComunicazioneController.php',
    'Segnalazioni/Utenti/SegnalazioneController.php',
    'Documenti/Utenti/DocumentoController.php',
];

/** Il corpo di un metodo, dalla graffa che lo apre a quella che lo chiude. `null` se non c'è. */
function corpoDelMetodo(string $codice, string $nome): ?string
{
    if (! preg_match('#function\s+'.preg_quote($nome, '#').'\s*\(#', $codice, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $inizio = strpos($codice, '{', $m[0][1]);

    if ($inizio === false) {
        return null;
    }

    $profondita = 0;

    for ($i = $inizio, $n = strlen($codice); $i < $n; $i++) {
        if ($codice[$i] === '{') {
            $profondita++;
        } elseif ($codice[$i] === '}') {
            $profondita--;

            if ($profondita === 0) {
                return substr($codice, $inizio, $i - $inizio);
            }
        }
    }

    return substr($codice, $inizio);
}

/** Quel pezzo di codice lancia un evento? */
function lanciaUnEvento(string $corpo): bool
{
    return (bool) preg_match('#::dispatch\s*\(|event\s*\(\s*new\s#', $corpo);
}

/**
 * I controller il cui `store()` avvisa qualcuno.
 *
 * @return array<string, string> percorso relativo => corpo del file
 */
function controllerCheAvvisanoInCreazione(): array
{
    $radice = dirname(__DIR__, 3).'/app/Http/Controllers';
    $trovati = [];

    $iteratore = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($radice, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iteratore as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $codice = file_get_contents($file->getPathname());

        $store = corpoDelMetodo($codice, 'store');
        $update = corpoDelMetodo($codice, 'update');

        // Serve che esistano tutti e due: un controller senza `update()` non ha un'asimmetria.
        if ($store === null || $update === null || ! lanciaUnEvento($store)) {
            continue;
        }

        $trovati[str_replace($radice.'/', '', $file->getPathname())] = $codice;
    }

    ksort($trovati);

    return $trovati;
}

/** `update()` avvisa, direttamente o chiamando un metodo dello stesso controller che avvisa? */
function laModificaAvvisa(string $codice): bool
{
    $update = corpoDelMetodo($codice, 'update') ?? '';

    if (lanciaUnEvento($update)) {
        return true;
    }

    // Un livello di indirezione: `$this->avvisaDopoLaModifica(...)` e simili.
    if (! preg_match_all('#\$this->(\w+)\s*\(#', $update, $m)) {
        return false;
    }

    foreach (array_unique($m[1]) as $metodo) {
        $corpo = corpoDelMetodo($codice, $metodo);

        if ($corpo !== null && lanciaUnEvento($corpo)) {
            return true;
        }
    }

    return false;
}

it('trova davvero dei controller da controllare', function () {
    // ⚠️ Senza questo, il giorno che i controller cambiano posto o forma la guardia diventa verde
    // perché non trova più niente — la forma di guasto peggiore, perché si presenta come successo.
    expect(count(controllerCheAvvisanoInCreazione()))->toBeGreaterThan(3);
});

it('ogni controller che avvisa alla creazione ha deciso cosa fare alla modifica', function () {
    $classificati = array_merge(AVVISA_ANCHE_IN_MODIFICA, NON_AVVISA_DI_PROPOSITO);
    $nonClassificati = array_diff(array_keys(controllerCheAvvisanoInCreazione()), $classificati);

    expect($nonClassificati)->toBe([],
        "Questi controller lanciano un evento in `store()` e non compaiono in nessuna delle due\n".
        "liste di ".basename(__FILE__).":\n\n  ".implode("\n  ", $nonClassificati)."\n\n".
        "Non è un rimprovero automatico: è una decisione da prendere adesso invece che scoprirla\n".
        "fra otto mesi da una segnalazione sul forum. Le strade sono due.\n\n".
        "1. **`update()` deve avvisare** — chi viene aggiunto alla platea in modifica non ha mai\n".
        "   ricevuto niente, ed è il difetto che la beta.64 ha corretto sulle altre tre porte.\n".
        "   Il meccanismo esiste: `DestinatariNotifica` + `DestinatariDaAvvisare`, e il metodo\n".
        "   `avvisaDopoLaModifica()` di `ComunicazioneController` è il modello da copiare.\n".
        "   Poi si aggiunge il file a AVVISA_ANCHE_IN_MODIFICA.\n\n".
        "2. **Non deve avvisare, e c'è una ragione** — si aggiunge a NON_AVVISA_DI_PROPOSITO\n".
        "   **scrivendo il motivo**. Una voce senza motivo è una voce che nessuno ha deciso."
    );
});

it('i controller dichiarati «avvisano anche in modifica» lo fanno davvero', function () {
    $tutti = controllerCheAvvisanoInCreazione();
    $bugiardi = [];

    foreach (AVVISA_ANCHE_IN_MODIFICA as $file) {
        if (! isset($tutti[$file])) {
            continue; // se ne occupa il test sulle voci scadute
        }

        if (! laModificaAvvisa($tutti[$file])) {
            $bugiardi[] = $file;
        }
    }

    expect($bugiardi)->toBe([],
        "Questi controller sono dichiarati fra quelli che avvisano anche in modifica, ma il loro\n".
        "`update()` non lancia nessun evento — né direttamente né chiamando un metodo che lo fa:\n\n  ".
        implode("\n  ", $bugiardi)."\n\n".
        "O l'avviso è stato tolto, o è stato spostato più in profondità di quanto questa guardia\n".
        "sappia seguire (un livello). In tutti e due i casi va guardato a mano."
    );
});

it('le due liste non contengono voci scadute', function () {
    // Un file che non avvisa più alla creazione, o che è stato rinominato, resta nella lista e la
    // fa diventare una dichiarazione falsa: la guardia continuerebbe a sembrare completa mentre
    // presidia un perimetro che non esiste più. È la stessa forma di marciume che questo progetto
    // ha già pagato con i riferimenti `file:riga` nei documenti.
    $vivi = array_keys(controllerCheAvvisanoInCreazione());
    $scadute = array_diff(array_merge(AVVISA_ANCHE_IN_MODIFICA, NON_AVVISA_DI_PROPOSITO), $vivi);

    expect($scadute)->toBe([],
        "Queste voci sono elencate in ".basename(__FILE__)." ma i file corrispondenti non lanciano\n".
        "più nessun evento in `store()`, o non esistono più:\n\n  ".implode("\n  ", $scadute)."\n\n".
        'Vanno tolte: una lista che nomina cose che non ci sono non presidia niente.'
    );
});

it('la guardia morde: un controller che avvisa solo in creazione viene visto', function () {
    // L'autocontrollo passa dalle **stesse funzioni** dei test veri — è la lezione della beta.60:
    // una guardia provata su una copia prova la copia.
    $finto = <<<'PHP'
    <?php
    class FintoController {
        public function store() { NotifyQualcuno::dispatch($cosa); }
        public function update() { $cosa->save(); }
    }
    PHP;

    expect(lanciaUnEvento(corpoDelMetodo($finto, 'store')))->toBeTrue()
        ->and(laModificaAvvisa($finto))->toBeFalse();

    // E la controprova: con l'indirezione che il progetto usa davvero, deve vederlo.
    $corretto = <<<'PHP'
    <?php
    class FintoController {
        public function store() { NotifyQualcuno::dispatch($cosa); }
        public function update() { $this->avvisaDopoLaModifica($cosa); }
        private function avvisaDopoLaModifica($cosa) { DestinatariDaAvvisare::dispatch($cosa, [], 'nuovo'); }
    }
    PHP;

    expect(laModificaAvvisa($corretto))->toBeTrue();
});
