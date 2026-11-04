<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Misura lo stato del corpus di `docs/` — **sola lettura**, non corregge niente.
 *
 * ## Perché esiste, e cosa NON promette
 *
 * Nasce dalla coda ㉕, aperta il 15/08/2026 dopo cinque guasti di documentazione nella stessa
 * indagine. La diagnosi di allora è la ragione della sua forma: **nessuno dei cinque sarebbe stato
 * preso da un controllo automatico**. Erano marciume semantico — un riferimento che risolve ancora
 * mentre il contenuto di quelle righe è cambiato, una fase già chiusa che la roadmap allocava
 * intera, un'affermazione vera diventata fuorviante senza diventare falsa.
 *
 * Questo comando **non li prende, e non deve pretendere di farlo.** Serve a impedire che il corpus
 * decada mentre nessuno guarda, e soprattutto a rendere visibile una cosa che nessuno calcola
 * leggendo: **l'età**. Un'intestazione che dice «verificato su 1.10.0-beta.32» non allarma nessuno;
 * la stessa informazione scritta «**24 beta fa**» si vede.
 *
 * I guasti veri si prevengono con le tre regole di `flusso_di_lavoro_rilascio.md` — invarianti nei
 * test, riconciliazione changelog↔roadmap alla chiusura, nessun documento generato che entra senza
 * una lettura integrale.
 *
 * ## I quattro segnali
 *
 * 1. **Intestazione di stato assente** — il documento non dichiara se descrive codice che esiste.
 * 2. **Età in beta** — quante versioni fa è stato verificato l'ultima volta.
 * 3. **Riferimenti `file:riga` che non risolvono** — il file non c'è più, o ha meno righe di
 *    quante il documento ne cita. È un falso **certo**, non un sospetto.
 * 4. **Link fra documenti che non risolvono** — `[testo](altro_documento.md)` verso un file che
 *    non esiste, cercato **a partire dalla cartella del documento che lo contiene**.
 *
 * ## Quali documenti, e che cosa si guarda in ognuna delle cartelle (Coda 458)
 *
 * *Fino al 04/11/2026 il comando guardava solo `docs/*.md`, il primo livello: i documenti delle
 * sottocartelle non li controllava nessuno, ed è per questo che il riordino della documentazione
 * aveva lasciato i documenti vivi al primo livello.* Ora li legge tutti, e per ogni cartella sa che
 * cosa ha senso chiedere (`REGOLE_PER_CARTELLA`; l'elenco compare anche in testa all'uscita):
 *
 * - **primo livello, `design/`, `strategia/`, `cla/`, `forum/`** — tutto: intestazione, età,
 *   riferimenti `file:riga` contro questo progetto, link. Non chiedono l'intestazione il changelog
 *   e i testi del CLA (`NON_DESCRIVONO_CODICE`): non descrivono codice, e il CLA è un testo legale
 *   che i contributori hanno firmato com'è.
 * - **`verbali/` e `archivio/`** — intestazione e link, ma né età né riferimenti: sono storia.
 *   Un verbale racconta il codice com'era quel giorno, e un `file:riga` che oggi non risolve dice
 *   che il codice è andato avanti, non che il documento mente; l'età di un verbale della 1.11 è
 *   per definizione quella della 1.11.
 * - **`kondocloud/`** — intestazione, età e link, ma non i riferimenti `file:riga`. Citano il codice
 *   di un repository a parte, e cercati in questo progetto sarebbero centinaia di «file assente», tutti
 *   falsi. Cercati anche in quel repository non migliora: molti non stanno in nessuno dei due, e altri
 *   stanno in un file omonimo troppo corto. Quei documenti citano anche il codice di Coolify
 *   (`ApplicationDeploymentJob.php:4168`) e delle librerie (`FilesystemAdapter.php:333`), e un
 *   `file:riga` non dice di quale repository parla: un `routes/api.php:311` «troppo corto» può essere
 *   il file giusto di un altro progetto. Troppi «falsi certi» sono peggio di un controllo che non
 *   c'è, e quindi qui non si giudicano.
 * - **`riferimenti/`** — solo i link. È materiale grezzo — fotografie del codice com'era, copie di
 *   messaggi, materiale di lavoro delle beta — e un riferimento o un'intestazione lì dentro non
 *   misura niente di vivo. Non entra nemmeno nell'indice dei nomi: i lavori delle beta lasciano a
 *   decine le copie del codice e dei documenti con lo stesso nome dell'originale (tredici
 *   `ProspettoOneriAccessori.php` in `docs/`), e un riferimento al file vero diventava «ambiguo»
 *   senza esserlo.
 *
 * Il resto della struttura — le righe dell'indice `README.md`, i nomi doppi, il registro delle
 * decisioni — lo guarda `docs/strumenti/verifica_struttura.py`. Sui link i due strumenti non sono
 * uguali: quello cerca anche a partire da `docs/`, questo solo a partire dal file che contiene il
 * link, come lo risolve chi apre il documento.
 *
 * ## Su quali file gira davvero — e perché su un clone pubblico ne vede pochissimi
 *
 * *Corretto il 26/08/2026: questo capoverso diceva «`docs/` è escluso da git di proposito (sono
 * documenti interni)», ed era falso da cinque giorni.*
 *
 * `docs/` è un **repository a sé**, clonato dentro la cartella del progetto: origine il repository
 * **privato** `vince844/kondomanager-docs` su GitHub, dal 21/08/2026 (vedi `docs/LEGGIMI_REPOSITORY.md`).
 * È escluso dal repository *del prodotto*, non da git.
 *
 * L'esclusione è una **regola invertita** in `.gitignore` — `docs/*` più quattro negazioni — così un
 * documento nuovo nasce privato senza che nessuno debba ricordarsi di aggiungerlo a una lista. Il
 * repository pubblico ne **traccia quindi 10 file**, e sono le sole guide che servono a chi installa
 * e usa KondoManager: `changelog.md`, `docker_local_dev.*.md`, `synology_nas_install.*.md`,
 * `plesk_cronjob_guide.md`. *(La prima stesura diceva «10 su 62»: 62 è il conteggio dei soli `.md`
 * di primo livello, mentre il repository dei documenti ne traccia 63 e la cartella su disco ne
 * contiene 73 in tutto. Un rapporto con due denominatori diversi non è una misura.)*
 *
 * Il comando lavora sui file che **trova su disco**, non su quelli tracciati. Su un checkout di
 * sviluppo li vede tutti; su un clone del repository pubblico ne vede dieci, e le sue misure —
 * età, riferimenti, link fra documenti — perdono quasi ogni significato. Non è un difetto del
 * comando: è la stessa causa della **Coda 82** in `docs/roadmap.md`, dove due test di sistema
 * leggono documenti che nel repository pubblico non ci sono.
 *
 * In produzione non ha senso, e infatti non compare in nessuno scheduler.
 */
class VerificaDocumentazioneCommand extends Command
{
    protected $signature = 'kondomanager:verifica-documentazione
                            {--eta= : mostra solo i documenti più vecchi di N beta}
                            {--documento= : limita ai documenti il cui percorso, relativo a docs/, contiene questo testo}';

    protected $description = 'Misura lo stato dei documenti in docs/, sottocartelle comprese: intestazioni, età in beta, riferimenti file:riga e link rotti. Non modifica nulla.';

    /** Righe `file:riga` citate nei documenti: `path/al/file.php:123` oppure `` `File.vue:12` ``. */
    private const RIFERIMENTO = '#(?<file>[A-Za-z0-9_\-./]+\.(?:php|vue|ts|js|json|md)):(?<riga>\d{1,5})#';

    private const MARCATORE_DI_STATO = '<!-- verifica-documentazione -->';

    /** Dove si cercano i file che un documento cita con `file:riga`: in questo progetto, o da nessuna parte. */
    private const NEL_PRODOTTO = 'prodotto';

    private const NON_SI_CONTROLLANO = 'nessuno';

    /** I documenti a cui l'intestazione di stato non si chiede: il changelog e i testi delle versioni del CLA. */
    private const NON_DESCRIVONO_CODICE = ['#^changelog\.md$#', '#^cla/v\d+(\.\d+)*/#'];

    /** Quello che si chiede a una cartella di `docs/` che qui sotto non è nominata: il primo livello e le cartelle di lavoro. */
    private const REGOLE_PREDEFINITE = ['intestazione' => true, 'eta' => true, 'riferimenti' => self::NEL_PRODOTTO, 'link' => true];

    /**
     * Che cosa si chiede ai documenti delle cartelle che non sono il primo livello, per nome della
     * prima cartella sotto `docs/`. Le ragioni sono nel commento della classe.
     *
     * @var array<string, array{intestazione: bool, eta: bool, riferimenti: string, link: bool}>
     */
    private const REGOLE_PER_CARTELLA = [
        'verbali' => ['intestazione' => true, 'eta' => false, 'riferimenti' => self::NON_SI_CONTROLLANO, 'link' => true],
        'archivio' => ['intestazione' => true, 'eta' => false, 'riferimenti' => self::NON_SI_CONTROLLANO, 'link' => true],
        'kondocloud' => ['intestazione' => true, 'eta' => true, 'riferimenti' => self::NON_SI_CONTROLLANO, 'link' => true],
        'riferimenti' => ['intestazione' => false, 'eta' => false, 'riferimenti' => self::NON_SI_CONTROLLANO, 'link' => true],
    ];

    /**
     * Le cartelle di codice dove si cercano i file citati per nome. `lang` c'è perché i documenti
     * scrivono `lang/{en,es,pt}/policies.php:55` e il percorso, in quella forma, si riduce al nome:
     * senza `lang` il nome risolveva solo per caso, grazie a una copia in `riferimenti/`.
     */
    private const CARTELLE_DI_CODICE = ['app', 'resources', 'database', 'routes', 'config', 'tests', 'bootstrap', 'lang'];

    /** @var array<string, list<string>>|null L'indice dei nomi, costruito alla prima ricerca. */
    private ?array $indice = null;

    public function handle(): int
    {
        // L'oggetto del comando è lo stesso per tutte le corse dello stesso processo: l'indice dei nomi si ricostruisce a ogni corsa.
        $this->indice = null;

        $cartella = base_path('docs');

        if (! is_dir($cartella)) {
            $this->warn('La cartella docs/ non esiste in questo checkout.');

            return self::SUCCESS;
        }

        $documenti = collect($this->documenti($cartella))
            ->when($this->option('documento'), fn ($c, $filtro) => $c->filter(
                fn ($relativo) => Str::contains($relativo, $filtro)
            ))
            ->values();

        if ($documenti->isEmpty()) {
            $this->warn('Nessun documento da esaminare.');

            return self::SUCCESS;
        }

        $betaCorrente = $this->betaCorrente();
        $sogliaEta = $this->option('eta') !== null ? (int) $this->option('eta') : null;

        $righeTotali = 0;
        $perCartella = [];
        $senzaIntestazione = [];
        $riferimentiRotti = [];
        $riferimentiAmbigui = [];
        $linkRotti = [];
        $eta = [];

        foreach ($documenti as $relativo) {
            $percorso = $cartella.'/'.$relativo;
            $testo = file_get_contents($percorso);
            $regole = $this->regole($relativo);

            $righeTotali += substr_count($testo, "\n") + 1;
            $perCartella[$this->cartellaDi($relativo)] = ($perCartella[$this->cartellaDi($relativo)] ?? 0) + 1;

            if ($regole['intestazione'] && $this->descriveCodice($relativo) && ! Str::contains($testo, self::MARCATORE_DI_STATO)) {
                $senzaIntestazione[] = $relativo;
            }

            if ($regole['eta'] && $betaCorrente !== null) {
                $ultima = $this->ultimaBetaCitata($testo);

                if ($ultima !== null) {
                    $eta[$relativo] = $betaCorrente - $ultima;
                }
            }

            if ($regole['riferimenti'] === self::NEL_PRODOTTO) {
                [$rotti, $ambigui] = $this->riferimenti($testo, dirname($percorso));

                foreach ($rotti as $rotto) {
                    $riferimentiRotti[] = "{$relativo} → {$rotto}";
                }

                foreach ($ambigui as $ambiguo) {
                    $riferimentiAmbigui[] = "{$relativo} → {$ambiguo}";
                }
            }

            if ($regole['link']) {
                foreach ($this->linkRotti($testo, dirname($percorso)) as $rotto) {
                    $linkRotti[] = "{$relativo} → {$rotto}";
                }
            }
        }

        $this->line('');
        $this->info('Stato della documentazione — sola lettura, niente viene modificato.');
        $this->line('');
        $this->line(sprintf(
            '  <options=bold>%d documenti</>, %s righe%s',
            $documenti->count(),
            number_format($righeTotali, 0, ',', '.'),
            $betaCorrente !== null ? "  ·  versione in sviluppo: beta.{$betaCorrente}" : '',
        ));
        $this->line('');
        $this->cheCosaSiGuarda($perCartella);

        $this->sezione(
            'Senza intestazione di stato',
            $senzaIntestazione,
            'Un documento senza intestazione non dice se descrive codice che esiste: va verificato prima di usarlo per decidere.',
        );

        $this->sezione(
            'Riferimenti file:riga che non risolvono',
            $riferimentiRotti,
            'Sono falsi certi: il file non esiste più, o ha meno righe di quante il documento ne cita.',
        );

        $this->sezione(
            'Riferimenti ambigui: il nome del file non basta a trovarlo',
            $riferimentiAmbigui,
            'Il documento cita il solo nome, e in progetto ce ne sono diversi con quel nome: il numero di riga sta in alcuni e non in altri. Si risolve scrivendo il percorso, non indovinando.',
        );

        $this->sezione(
            'Link fra documenti che non risolvono',
            $linkRotti,
            'Il documento rimanda a una pagina che non c\'è.',
        );

        $this->sezione(
            'Voci di coda che l\'indice della roadmap non elenca',
            $this->vociFuoriDallIndice($cartella),
            'L\'indice è la porta d\'ingresso per argomento: una voce che non compare lì esiste solo per chi già sa dov\'è.',
        );

        $this->etaDeiDocumenti($eta, $sogliaEta);

        $segnali = count($senzaIntestazione) + count($riferimentiRotti) + count($riferimentiAmbigui) + count($linkRotti);

        $this->line('');
        $this->line($segnali === 0
            ? '  <fg=green>Nessun difetto meccanico.</> Restano quelli che nessun comando vede: leggi le intestazioni più vecchie.'
            : "  <fg=yellow>{$segnali} cose da guardare.</> L'età non è un difetto: è la misura di quanto tempo è passato.");
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Tutti i `.md` sotto `docs/`, come percorsi relativi a `docs/`: prima il primo livello, poi le
     * sottocartelle, ciascuno in ordine alfabetico. Le cartelle nascoste (`.git`, il repository dei
     * documenti) non si guardano.
     *
     * @return list<string>
     */
    private function documenti(string $cartella): array
    {
        $filtro = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $voce) => ! str_starts_with($voce->getFilename(), '.'),
        );

        $trovati = [];

        foreach (new \RecursiveIteratorIterator($filtro) as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $trovati[] = substr($file->getPathname(), strlen($cartella) + 1);
            }
        }

        usort($trovati, fn (string $a, string $b) => [str_contains($a, '/'), $a] <=> [str_contains($b, '/'), $b]);

        return $trovati;
    }

    /** La prima cartella sotto `docs/`; vuota per un documento del primo livello. */
    private function cartellaDi(string $relativo): string
    {
        return str_contains($relativo, '/') ? Str::before($relativo, '/') : '';
    }

    /**
     * @return array{intestazione: bool, eta: bool, riferimenti: string, link: bool}
     */
    private function regole(string $relativo): array
    {
        return self::REGOLE_PER_CARTELLA[$this->cartellaDi($relativo)] ?? self::REGOLE_PREDEFINITE;
    }

    /**
     * Se all'intestazione di stato ha senso chiedere. Il changelog non descrive codice: descrive
     * **cosa è cambiato**, e ogni voce porta già la sua versione; chiederglielo produceva una segnalazione
     * che nessuno avrebbe mai chiuso, e un controllo che segnala per sempre la stessa cosa insegna a
     * saltare l'elenco. Lo stesso vale per il testo del CLA, che i contributori hanno firmato com'è.
     */
    private function descriveCodice(string $relativo): bool
    {
        foreach (self::NON_DESCRIVONO_CODICE as $modello) {
            if (preg_match($modello, $relativo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dice, per ogni cartella che ha documenti, quanti sono e che cosa se ne controlla: chi legge
     * l'uscita deve poter sapere che cosa **non** è stato guardato, non solo che cosa è risultato
     * sano.
     *
     * @param  array<string, int>  $perCartella
     */
    private function cheCosaSiGuarda(array $perCartella): void
    {
        ksort($perCartella);

        $this->line('  <options=bold>Che cosa si guarda, per cartella</>');

        foreach ($perCartella as $cartella => $quanti) {
            $regole = self::REGOLE_PER_CARTELLA[$cartella] ?? self::REGOLE_PREDEFINITE;

            $controlli = array_filter([
                $regole['intestazione'] ? 'intestazione' : null,
                $regole['eta'] ? 'età' : null,
                $regole['riferimenti'] === self::NEL_PRODOTTO ? 'riferimenti' : null,
                $regole['link'] ? 'link' : null,
            ]);

            $this->line(sprintf(
                '    %-14s %4d  %s',
                $cartella === '' ? 'primo livello' : $cartella.'/',
                $quanti,
                implode(', ', $controlli),
            ));
        }

        $this->line('');
    }

    /**
     * Il numero di beta della versione in sviluppo. `null` se la versione non è una beta —
     * su una stabile il confronto non ha senso e la colonna sparisce invece di mentire.
     */
    private function betaCorrente(): ?int
    {
        return preg_match('#-beta\.(\d+)#', (string) config('app.version'), $m)
            ? (int) $m[1]
            : null;
    }

    /** La serie in sviluppo — `1.10.0` — che è il metro con cui i numeri di beta si confrontano. */
    private function serieCorrente(): string
    {
        return Str::before((string) config('app.version'), '-');
    }

    /**
     * L'ultima beta citata nell'intestazione, che è la data di verifica più recente dichiarata.
     *
     * Si guarda **il numero più alto**, non il primo: le intestazioni accumulano le riletture in
     * ordine di scrittura, e la più recente non è sempre l'ultima nominata.
     *
     * I numeri di beta ripartono da 1 a ogni serie, quindi si contano **solo quelli della serie in
     * sviluppo**. Le intestazioni scrivono in due modi — `1.10.0-beta.41` e `beta.41` — e la forma
     * nuda vale come serie corrente, che è la convenzione con cui è scritta. Un documento che cita
     * solo serie precedenti torna `0`: è più vecchio di tutta la serie in corso, e dirlo con l'età
     * massima è l'unica risposta che non inventa. Senza questa distinzione, all'apertura della 1.11
     * ogni documento della 1.10 avrebbe avuto un'età **negativa**.
     */
    private function ultimaBetaCitata(string $testo): ?int
    {
        $intestazione = Str::before($testo, '<!-- /verifica-documentazione -->');

        if (! preg_match_all('#(?:(?<serie>\d+\.\d+\.\d+)-)?beta\.(?<numero>\d+)#', $intestazione, $trovati, PREG_SET_ORDER)) {
            return null;
        }

        $dellaSerie = [];

        foreach ($trovati as $t) {
            if (($t['serie'] ?? '') === '' || $t['serie'] === $this->serieCorrente()) {
                $dellaSerie[] = (int) $t['numero'];
            }
        }

        return $dellaSerie === [] ? 0 : max($dellaSerie);
    }

    /**
     * I riferimenti `file:riga` che non tengono, divisi in due: quelli **certamente** rotti e
     * quelli **ambigui**. Tenerli insieme costringerebbe a dire «falso certo» di qualcosa che
     * certo non è, e un rapporto che esagera si legge una volta sola.
     *
     * @param  string  $cartellaDelDocumento  la cartella del documento che li cita
     * @return array{0: list<string>, 1: list<string>}
     */
    private function riferimenti(string $testo, string $cartellaDelDocumento): array
    {
        if (! preg_match_all(self::RIFERIMENTO, $testo, $trovati, PREG_SET_ORDER)) {
            return [[], []];
        }

        $rotti = [];
        $ambigui = [];

        foreach ($trovati as $t) {
            $candidati = $this->candidati($t['file'], $cartellaDelDocumento);

            if ($candidati === []) {
                $rotti[] = $t['file'].':'.$t['riga'].' (file assente)';

                continue;
            }

            $riga = (int) $t['riga'];
            $lunghezze = array_map(fn ($f) => count(file($f)), $candidati);

            // Sta in tutti: risolve, e non c'è niente da dire.
            if (min($lunghezze) >= $riga) {
                continue;
            }

            // Non sta in nessuno: falso certo.
            if (max($lunghezze) < $riga) {
                $rotti[] = $t['file'].':'.$t['riga'].' (il file più lungo con questo nome ha '.max($lunghezze).' righe)';

                continue;
            }

            // ⚠️ **Sta in alcuni e non in altri, e questo è il caso onesto da dichiarare.**
            // `DataTableRowActions.vue` esiste in sei cartelle: rispondere «risolve» perché
            // *qualcuno* di quei sei è abbastanza lungo significherebbe rispondere a una domanda
            // diversa da quella che si voleva porre — la trappola della beta.52. Il documento
            // parla di **uno** di quei file, e quale lo sa solo chi l'ha scritto.
            $ambigui[] = sprintf(
                '%s:%d (%d file con questo nome, il più corto ne ha %d)',
                $t['file'],
                $riga,
                count($candidati),
                min($lunghezze),
            );
        }

        return [array_values(array_unique($rotti)), array_values(array_unique($ambigui))];
    }

    /**
     * Trova il file citato da un riferimento.
     *
     * ⚠️ **I documenti citano quasi sempre il solo nome del file** — `GenerateSaldiAction.php:59`,
     * non il percorso completo — perché in una frase il percorso non si legge. Un controllo che
     * risolvesse solo i percorsi dalla radice segnalerebbe **165 riferimenti rotti su 411**, tutti
     * falsi: e un controllo che grida al lupo centosessantacinque volte viene spento al secondo
     * giro, che è peggio di non averlo. Quindi il nome nudo si cerca nell'albero del progetto.
     *
     * Restituisce **tutti** i file che portano quel nome, non il più probabile: quando sono più
     * d'uno — `columns.ts` esiste in dodici cartelle — la risposta onesta non è «risolve» ma
     * «dipende da quale», e chi legge il rapporto deve poterlo sapere. Scegliere il più lungo
     * farebbe passare per sano un riferimento rotto, che è il modo in cui un controllo diventa
     * peggio della sua assenza.
     *
     * Un percorso, invece, si prova prima così com'è dalla radice del progetto, poi dalla cartella
     * `docs/` e dalla cartella del documento che lo cita: un documento di `strategia/` che scrive
     * `riferimenti/lavoro/note.md:12` intende il percorso da `docs/`.
     *
     * @return list<string>
     */
    private function candidati(string $riferimento, string $cartellaDelDocumento): array
    {
        $pulito = ltrim(str_replace('../', '', $riferimento), '/');

        foreach ([base_path($pulito), base_path('docs/'.$pulito), $cartellaDelDocumento.'/'.$riferimento] as $percorso) {
            if (is_file($percorso)) {
                return [$percorso];
            }
        }

        $nome = basename($riferimento);

        // Un documento che cita un altro documento vive in docs/, non nell'albero del codice.
        if (str_ends_with($nome, '.md') && is_file(base_path('docs/'.$nome))) {
            return [base_path('docs/'.$nome)];
        }

        return $this->indiceDeiNomi()[$nome] ?? [];
    }

    /**
     * Nome del file → percorsi che lo portano: le cartelle di codice e `docs/`, **meno
     * `riferimenti/`**.
     *
     * Il materiale grezzo di `riferimenti/` è fatto di fotografie e copie: un nome che esiste come
     * copia non rende «ambiguo» un riferimento al file vero, è l'indice che inventa l'ambiguità
     * (tredici `ProspettoOneriAccessori.php`). Le cartelle nascoste (`.git`) non entrano. Costruito una
     * volta sola per corsa: la scansione dell'albero per ognuno dei riferimenti costerebbe minuti. Sta
     * nell'oggetto e non in una variabile statica: una variabile statica sopravvive fra una corsa e
     * l'altra nello stesso processo, e il secondo progetto misurato (un test, una cartella diversa)
     * vedeva l'indice del primo. Neppure l'oggetto basta: è lo stesso per tutte le chiamate di
     * `Artisan::call` dell'applicazione, e `handle()` lo azzera a ogni corsa.
     *
     * @return array<string, list<string>>
     */
    private function indiceDeiNomi(): array
    {
        if ($this->indice !== null) {
            return $this->indice;
        }

        $indice = [];

        foreach (self::CARTELLE_DI_CODICE as $cartella) {
            $this->aggiungiAllIndice($indice, base_path($cartella));
        }

        $this->aggiungiAllIndice($indice, base_path('docs'), ['riferimenti']);

        return $this->indice = $indice;
    }

    /**
     * @param  array<string, list<string>>  $indice
     * @param  list<string>  $saltate  cartelle, direttamente sotto `$cartella`, che non entrano
     */
    private function aggiungiAllIndice(array &$indice, string $cartella, array $saltate = []): void
    {
        if (! is_dir($cartella)) {
            return;
        }

        $filtro = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $voce) => ! str_starts_with($voce->getFilename(), '.')
                && ! ($voce->isDir() && $voce->getPath() === $cartella && in_array($voce->getFilename(), $saltate, true)),
        );

        foreach (new \RecursiveIteratorIterator($filtro) as $file) {
            if ($file->isFile()) {
                $indice[$file->getFilename()][] = $file->getPathname();
            }
        }
    }

    /**
     * I link `[testo](altro.md)` che non portano a un file, cercati a partire dalla cartella del
     * documento che li contiene: da `verbali/1.12/v.md`, `roadmap.md` è `verbali/1.12/roadmap.md`,
     * non `docs/roadmap.md`. Così si legge un link su GitHub, e così lo legge chi apre il file.
     *
     * @return list<string>
     */
    private function linkRotti(string $testo, string $cartellaDelDocumento): array
    {
        if (! preg_match_all('#\]\((?!https?://)(?<link>[A-Za-z0-9_\-./]+\.md)(?:\#[^)]*)?\)#', $testo, $trovati, PREG_SET_ORDER)) {
            return [];
        }

        $rotti = [];

        foreach ($trovati as $t) {
            if (! is_file($cartellaDelDocumento.'/'.$t['link'])) {
                $rotti[] = $t['link'];
            }
        }

        return array_values(array_unique($rotti));
    }

    /**
     * Le voci di coda **aperte** che l'indice in testa a `roadmap.md` non elenca.
     *
     * ## Perché è il comando a controllarlo
     *
     * L'indice ha le parole chiave scritte a mano, e quello è il suo valore: sono i termini con cui
     * la domanda arriva davvero. Ma una lista mantenuta a mano **invecchia da sola** — qualcuno
     * apre una voce e non la aggiunge, qualcun altro ne chiude una e la riga resta. È la stessa
     * ragione per cui i riquadri di versione del sito sono marcati dentro il file invece che
     * elencati altrove: la verità la dice un comando, non la memoria.
     *
     * Si guardano solo le voci **non chiuse**: una voce chiusa resta nel documento come storia, e
     * pretenderla nell'indice lo riempirebbe di roba che non serve più a entrare.
     *
     * @return list<string>
     */
    private function vociFuoriDallIndice(string $cartella): array
    {
        $roadmap = $cartella.'/roadmap.md';

        if (! is_file($roadmap)) {
            return [];
        }

        $testo = file_get_contents($roadmap);

        if (! preg_match('#<!-- indice-roadmap -->(?<indice>.*?)<!-- /indice-roadmap -->#s', $testo, $m)) {
            return ['roadmap.md → manca del tutto il blocco dell\'indice'];
        }

        $indice = $m['indice'];
        $fuori = [];

        foreach (preg_split('#\n#', $testo) as $riga) {
            if (! str_starts_with($riga, '#### ')) {
                continue;
            }

            // Chiuse e testi superati non vanno nell'indice: sono storia, non porte d'ingresso.
            if (str_contains($riga, '✅') || str_contains($riga, 'SUPERAT')) {
                continue;
            }

            // Il simbolo della coda — ⑲, ㉔ — è la chiave: i titoli cambiano, quello no.
            if (! preg_match('#[\x{2460}-\x{24FF}\x{3251}-\x{32BF}]#u', $riga, $simbolo)) {
                continue;
            }

            if (! str_contains($indice, $simbolo[0])) {
                $fuori[] = 'roadmap.md → coda '.$simbolo[0].' non è nell\'indice';
            }
        }

        return array_values(array_unique($fuori));
    }

    /**
     * @param  array<string, int>  $eta
     */
    private function etaDeiDocumenti(array $eta, ?int $soglia): void
    {
        if ($eta === []) {
            return;
        }

        arsort($eta);

        $mostrati = $soglia === null
            ? array_slice($eta, 0, 10, true)
            : array_filter($eta, fn ($v) => $v >= $soglia);

        if ($mostrati === []) {
            $this->line("  <fg=green>Nessun documento più vecchio di {$soglia} beta.</>");

            return;
        }

        $titolo = $soglia === null
            ? 'I dieci documenti verificati più tempo fa'
            : "Documenti verificati più di {$soglia} beta fa";

        $this->line("  <options=bold>{$titolo}</>");
        $this->line('  <fg=gray>L\'età non è un difetto: è quanto tempo è passato da quando qualcuno ha guardato.</>');
        $this->line('');

        foreach ($mostrati as $nome => $quante) {
            $colore = $quante >= 20 ? 'red' : ($quante >= 10 ? 'yellow' : 'gray');
            $this->line(sprintf('    <fg=%s>%3d beta fa</>  %s', $colore, $quante, $nome));
        }

        $this->line('');
    }

    /**
     * @param  list<string>  $voci
     */
    private function sezione(string $titolo, array $voci, string $perche): void
    {
        if ($voci === []) {
            $this->line("  <fg=green>✓</> {$titolo}: nessuno.");

            return;
        }

        $this->line("  <options=bold>{$titolo}</> (".count($voci).')');
        $this->line("  <fg=gray>{$perche}</>");
        $this->line('');

        foreach ($voci as $voce) {
            $this->line("    <fg=yellow>·</> {$voce}");
        }

        $this->line('');
    }
}
