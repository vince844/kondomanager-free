<?php

/**
 * # Chi scrive le ripartizioni per ruolo deve averne validato la somma
 *
 * ## La forma che si ripete, e che questa guardia esiste per fermare
 *
 * Le ripartizioni per ruolo dicono quanta parte della quota di un'unità tocca al proprietario,
 * quanta all'inquilino, quanta all'usufruttuario. **Devono sommare a 100**: se sommano a meno, una
 * parte della spesa non è attribuita a nessun ruolo.
 *
 * Fino alla beta.68 quel controllo esisteva in **tre porte su quattro**. Mancava in
 * `ContoController::update()`, e costava questo — misurato con una sonda il 22/08/2026:
 * ripartizioni che dichiarano il **60%** della quota di un'unità facevano addebitare il **100%**,
 * perché la rinormalizzazione finale del motore di riparto assorbe qualunque cosa manchi a monte.
 * Il totale del piano restava perfettamente uguale al preventivo, quindi **nessun controllo
 * contabile aveva niente da segnalare**.
 *
 * ⚠️ **È la quarta volta che una guardia esiste in `store()` e non in `update()`.** Prima di questa:
 * il riparto manuale che arrivava dal form solo alla creazione, la capienza del conto in modifica
 * pagamento, e le notifiche della beta.64. Su questo progetto la regola è che alla terza non si
 * corregge il caso, si costruisce la guardia — ed è già successo per le rotte morte nella .62, per
 * i modelli di stampa nella .63 e per le notifiche nella .64.
 *
 * ⚠️ **La guardia della beta.64 non poteva vedere questo caso**, ed è la ragione per cui ne serve
 * una seconda: `AvvisareInCreazioneENonInModificaTest` presidia l'asimmetria sugli **eventi**
 * (`store()` avvisa, `update()` no). Qui l'asimmetria è sulle **validazioni**. Stessa forma,
 * contenuto diverso: una guardia scritta su una forma sola vede una forma sola.
 *
 * ## Cosa asserisce, e come
 *
 * Trova ogni punto del codice che **scrive** in `conto_tabella_ripartizioni`, risale al metodo che
 * lo contiene, e pretende che dentro quel metodo ci sia una validazione della somma.
 *
 * Le forme riconosciute sono due, e sono quelle che il progetto usa davvero:
 *
 * - `array_sum(array_column($ripartizioni, 'percentuale'))` — la forma di `ContoController` e di
 *   `FatturaPassivaService`;
 * - una variabile `$sommaPercentuali` costruita sommando le tre percentuali — la forma dei due
 *   controller di associazione.
 *
 * ⚠️ **Non pretende che la validazione respinga.** `FatturaPassivaService` non rifiuta: quando la
 * somma non torna **ripiega** su «proprietario 100», che è una strategia diversa e altrettanto
 * chiusa — non lascia mai una base incompleta. Pretendere il rifiuto costringerebbe a riscrivere
 * quel ramo per far tacere un test, che è il contrario del punto.
 *
 * ## Cosa NON copre
 *
 * - **Non copre le scritture fatte fuori da `app/`**: una migrazione o un seeder che inserisse
 *   ripartizioni sbilanciate non verrebbe visto. Il motore però ora regge anche quel caso — la
 *   parte non dichiarata diventa scoperto — quindi la difesa in profondità c'è.
 * - **Non legge il comportamento**, ma il testo del metodo: non sa se la validazione è
 *   raggiungibile né se viene aggirata da un parametro. È lo stesso limite dichiarato di
 *   `RotteAnnidateSenzaGuardiaTest`, e la difesa vera resta il motore.
 */

/** I file di `app/` che scrivono righe di ripartizione per ruolo. */
function fileCheScrivonoRipartizioni(): array
{
    $trovati = [];
    $radice = dirname(__DIR__, 3).'/app';

    $iteratore = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice));

    foreach ($iteratore as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $testo = file_get_contents($file->getPathname());

        if (str_contains($testo, "conto_tabella_ripartizioni')->insert")
            || str_contains($testo, 'ContoTabellaRipartizione::create')) {
            $trovati[] = $file->getPathname();
        }
    }

    sort($trovati);

    return $trovati;
}

/**
 * I metodi che scrivono ripartizioni, e se validano la somma.
 *
 * @return list<array{file:string, metodo:string, valida:bool}>
 */
function metodiCheScrivonoRipartizioni(): array
{
    $esito = [];

    foreach (fileCheScrivonoRipartizioni() as $percorso) {
        $righe = file($percorso);
        $metodoCorrente = null;
        $inizioMetodo = 0;

        foreach ($righe as $n => $riga) {
            if (preg_match('#function\s+(\w+)\s*\(#', $riga, $m)) {
                $metodoCorrente = $m[1];
                $inizioMetodo = $n;
            }

            $scrive = str_contains($riga, "conto_tabella_ripartizioni')->insert")
                || str_contains($riga, 'ContoTabellaRipartizione::create');

            if (! $scrive || $metodoCorrente === null) {
                continue;
            }

            // Il corpo del metodo fino a qui: la validazione deve precedere la scrittura.
            $corpo = implode('', array_slice($righe, $inizioMetodo, $n - $inizioMetodo));

            $valida = str_contains($corpo, "array_sum(array_column(\$ripartizioni, 'percentuale'))")
                || str_contains($corpo, '$sommaPercentuali');

            $chiave = basename($percorso).'::'.$metodoCorrente;

            // Un metodo con più scritture si conta una volta: basta che validi.
            $esito[$chiave] = [
                'file'   => basename($percorso),
                'metodo' => $metodoCorrente,
                'valida' => ($esito[$chiave]['valida'] ?? false) || $valida,
            ];
        }
    }

    return array_values($esito);
}

it('trova davvero i punti che scrivono le ripartizioni, invece di guardare il vuoto', function () {
    // ⚠️ Senza questo, il giorno che la tabella cambia nome o che si passa a un Model la guardia
    // diventa verde perché non riconosce più niente — la forma di guasto peggiore, perché si
    // presenta come un successo. È la lezione della beta.61.
    $metodi = metodiCheScrivonoRipartizioni();

    expect(count($metodi))->toBeGreaterThanOrEqual(4,
        'Riconosciuti solo '.count($metodi)." punti che scrivono le ripartizioni.\n".
        "Alla beta.69 erano cinque, in quattro file: `ContoController` (store e update),\n".
        "`AssociaTabellaController`, `AggiornaTabellaController` e `FatturaPassivaService`.\n".
        'Se sono diventati meno, il riconoscitore va aggiornato — non allentata questa soglia.'
    );
});

it('⚠️ ogni punto che scrive le ripartizioni ne valida la somma', function () {
    $scoperti = array_filter(metodiCheScrivonoRipartizioni(), fn ($m) => ! $m['valida']);

    expect($scoperti)->toBe([],
        "Questi punti scrivono righe di ripartizione per ruolo senza aver validato che le\n".
        "percentuali sommino a 100:\n\n".
        implode("\n", array_map(fn ($m) => "  {$m['file']}::{$m['metodo']}()", $scoperti))."\n\n".
        "⚠️ **Non è un formalismo.** Il motore di riparto rinormalizza i pesi a 1 in fondo alla\n".
        "catena: qualunque cosa manchi a monte viene assorbita, il totale del piano coincide col\n".
        "preventivo e nessun controllo contabile ha niente da segnalare. Ripartizioni che dichiarano\n".
        "il 60% facevano addebitare il 100% — denaro sulla persona sbagliata, con i conti che\n".
        "tornano perfettamente.\n\n".
        "Due forme sono accettate: rifiutare (`ContoController`, i due controller di associazione)\n".
        "oppure ripiegare su «proprietario 100» (`FatturaPassivaService`). Quello che non si può\n".
        'fare è scrivere senza guardare.'
    );
});

it('la guardia morde: un metodo senza validazione viene visto', function () {
    // L'autocontrollo passa dalla **stessa logica** del test vero — è la lezione della beta.60: una
    // guardia provata su una copia prova la copia. Qui si dà in pasto al riconoscitore un testo
    // finto con un buco noto, e si verifica che il buco si veda.
    $finto = <<<'PHP'
    public function conValidazione()
    {
        if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) { throw new Exception(); }
        DB::table('conto_tabella_ripartizioni')->insert([]);
    }

    public function senzaValidazione()
    {
        DB::table('conto_tabella_ripartizioni')->insert([]);
    }
    PHP;

    $righe = explode("\n", $finto);
    $metodo = null; $inizio = 0; $esiti = [];

    foreach ($righe as $n => $riga) {
        if (preg_match('#function\s+(\w+)\s*\(#', $riga, $m)) { $metodo = $m[1]; $inizio = $n; }
        if (! str_contains($riga, "conto_tabella_ripartizioni')->insert") || $metodo === null) continue;
        $corpo = implode("\n", array_slice($righe, $inizio, $n - $inizio));
        $esiti[$metodo] = str_contains($corpo, "array_sum(array_column(\$ripartizioni, 'percentuale'))");
    }

    expect($esiti)->toBe(['conValidazione' => true, 'senzaValidazione' => false]);
});
