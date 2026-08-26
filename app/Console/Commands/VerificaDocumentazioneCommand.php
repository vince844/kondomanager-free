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
 *    non esiste.
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
                            {--documento= : limita a un documento (nome file, anche parziale)}';

    protected $description = 'Misura lo stato dei documenti in docs/: intestazioni, età in beta, riferimenti file:riga e link rotti. Non modifica nulla.';

    /** Righe `file:riga` citate nei documenti: `path/al/file.php:123` oppure `` `File.vue:12` ``. */
    private const RIFERIMENTO = '#(?<file>[A-Za-z0-9_\-./]+\.(?:php|vue|ts|js|json|md)):(?<riga>\d{1,5})#';

    public function handle(): int
    {
        $cartella = base_path('docs');

        if (! is_dir($cartella)) {
            $this->warn('La cartella docs/ non esiste in questo checkout.');

            return self::SUCCESS;
        }

        $documenti = collect(glob($cartella.'/*.md'))
            ->when($this->option('documento'), fn ($c, $filtro) => $c->filter(
                fn ($p) => Str::contains(basename($p), $filtro)
            ))
            ->values();

        if ($documenti->isEmpty()) {
            $this->warn('Nessun documento da esaminare.');

            return self::SUCCESS;
        }

        $betaCorrente = $this->betaCorrente();
        $sogliaEta = $this->option('eta') !== null ? (int) $this->option('eta') : null;

        $righeTotali = 0;
        $senzaIntestazione = [];
        $riferimentiRotti = [];
        $riferimentiAmbigui = [];
        $linkRotti = [];
        $eta = [];

        foreach ($documenti as $percorso) {
            $testo = file_get_contents($percorso);
            $nome = basename($percorso);
            $righeTotali += substr_count($testo, "\n") + 1;

            // Il changelog non descrive codice: descrive **cosa è cambiato**, e ogni voce porta già
            // la sua versione. Chiederglielo produceva un rilievo che nessuno avrebbe mai chiuso, e
            // un controllo che segnala per sempre la stessa cosa insegna a saltare l'elenco.
            if ($nome !== 'changelog.md' && ! Str::contains($testo, '<!-- verifica-documentazione -->')) {
                $senzaIntestazione[] = $nome;
            }

            if ($betaCorrente !== null) {
                $ultima = $this->ultimaBetaCitata($testo);

                if ($ultima !== null) {
                    $eta[$nome] = $betaCorrente - $ultima;
                }
            }

            [$rotti, $ambigui] = $this->riferimenti($testo);

            foreach ($rotti as $rotto) {
                $riferimentiRotti[] = "{$nome} → {$rotto}";
            }

            foreach ($ambigui as $ambiguo) {
                $riferimentiAmbigui[] = "{$nome} → {$ambiguo}";
            }

            foreach ($this->linkRotti($testo, $cartella) as $rotto) {
                $linkRotti[] = "{$nome} → {$rotto}";
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
     * @return array{0: list<string>, 1: list<string>}
     */
    private function riferimenti(string $testo): array
    {
        if (! preg_match_all(self::RIFERIMENTO, $testo, $trovati, PREG_SET_ORDER)) {
            return [[], []];
        }

        $rotti = [];
        $ambigui = [];

        foreach ($trovati as $t) {
            $candidati = $this->candidati($t['file']);

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
     * @return list<string>
     */
    private function candidati(string $riferimento): array
    {
        $percorso = base_path(ltrim(str_replace('../', '', $riferimento), '/'));

        if (is_file($percorso)) {
            return [$percorso];
        }

        $nome = basename($riferimento);

        // Un documento che cita un altro documento vive in docs/, non nell'albero del codice.
        if (str_ends_with($nome, '.md') && is_file(base_path('docs/'.$nome))) {
            return [base_path('docs/'.$nome)];
        }

        return $this->indiceDeiNomi()[$nome] ?? [];
    }

    /**
     * Nome del file → percorsi che lo portano. Costruito una volta sola: la scansione dell'albero
     * per ognuno dei 411 riferimenti costerebbe minuti.
     *
     * @return array<string, list<string>>
     */
    private function indiceDeiNomi(): array
    {
        static $indice = null;

        if ($indice !== null) {
            return $indice;
        }

        $indice = [];

        foreach (['app', 'resources', 'database', 'routes', 'config', 'tests', 'bootstrap', 'docs'] as $radice) {
            $cartella = base_path($radice);

            if (! is_dir($cartella)) {
                continue;
            }

            $iteratore = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cartella, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iteratore as $file) {
                if ($file->isFile()) {
                    $indice[$file->getFilename()][] = $file->getPathname();
                }
            }
        }

        return $indice;
    }

    /**
     * @return list<string>
     */
    private function linkRotti(string $testo, string $cartella): array
    {
        if (! preg_match_all('#\]\((?!https?://)(?<link>[A-Za-z0-9_\-./]+\.md)(?:\#[^)]*)?\)#', $testo, $trovati, PREG_SET_ORDER)) {
            return [];
        }

        $rotti = [];

        foreach ($trovati as $t) {
            $destinazione = $cartella.'/'.ltrim($t['link'], './');

            if (! is_file($destinazione) && ! is_file($cartella.'/'.$t['link'])) {
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
