<?php

/**
 * Cosa resta scoperto: la regola detta con parole che nessun modello prevede (la guardia è per modelli, e in `app/` guarda i commenti di quattro file);
 * le chiavi dei changelog diverse da `features` (la guardia legge solo quella: una voce nuova che dice la regola si rilegge a mano);
 * le frasi che il codice genera in `app/` fuori da `AnteprimaPassaggio.php` («X ha già un saldo su quella gestione e quell'unità, e il programma non ne
 * registra un secondo», vere perché la lista viene da `conUnSaldo`, fissate da sei asserzioni); i documenti in `docs/`. Le stringhe di `AnteprimaPassaggio.php`
 * si guardano (il pannello della successione dice la regola in una frase fissa), tranne quelle generate dalla lista di `conUnSaldo`.
 *
 * La regola del Wallet è detta con la sua eccezione, in ogni testo del prodotto.
 *
 * La clausola del codice conta solo i saldi che un passaggio non ha scritto: `->whereNull('subentro_id')` in `FermataDopoScrivi::conUnSaldo`
 * e, per chi crea un saldo, in `CreateSaldoRequest` (le righe del conguaglio di un passaggio non occupano il posto). Un testo che dice «il
 * Wallet accetta un solo saldo per persona, gestione, unità ed esercizio», o «se nessuno ha già un saldo su quella gestione», senza l'eccezione,
 * fa credere che la strada sia «fra le parti» proprio dove il pannello propone il Wallet: chi ha la riga di un conguaglio può avere accanto il
 * suo saldo manuale. La formula giusta: «un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio».
 *
 * La guardia legge il testo con gli spazi normalizzati e i tag tolti (la frase di una guida va a capo nel sorgente: una ricerca per riga non la
 * vede), e per ogni punto in cui il testo detta la regola chiede l'eccezione lì accanto (100 caratteri prima, 200 dopo), in italiano, in inglese
 * e in portoghese. Guarda i componenti (`resources/js`), le viste, `lang/` e tutti i changelog della 1.11.0 tranne quelli delle versioni 50 e 51.
 *
 * Non guarda: i changelog delle versioni 50 e 51 della 1.11.0, che sono la storia che l'errata corregge, e le voci che cominciano con «ERRATA» o
 * «ERRATUM», che citano la formula vecchia per correggerla. Ne segue che una voce nuova di un changelog che dice la regola (le voci sulle
 * guide e sul selettore dei saldi) porta l'eccezione, o comincia con «ERRATA» («ERRATUM»).
 *
 * I commenti del codice che portano la stessa formula si leggono con i token di PHP, in `FermataDopoScrivi.php`, `AnteprimaPassaggio.php`,
 * `DefuntoNelPiano.php` e `CreateSaldoRequest.php`: dove dicono «ne ha già uno lì», «il Wallet ne accetta uno», «ha già un saldo su quella
 * gestione», «un solo saldo per persona» o «una sola riga per (esercizio, gestione, immobile…)» dicono anche che sono i saldi che un passaggio
 * non ha scritto.
 */

use Illuminate\Support\Facades\File;


/** I modelli: dove un testo detta la regola. */
const B52_WALLET_ANCORE = [
    // italiano
    '/(?:ha|hanno) già,? (?:un saldo (?:su quella gestione|lì)|lì un saldo|su quella gestione)/u',
    '/già[^.]{0,40}su quella gestione/u',
    '/per persona, gestione,? unità/u',
    '/ne (?:tiene|accetta) uno per persona/u',
    '/non (?:accetta|ammette|consente) (?:due|più)[^.]{0,40}saldi/u',
    // inglese
    '/already (?:has|have|hold) a balance on that/iu',
    '/accepts? (?:only )?(?:a|one) (?:single )?(?:balance|row) per person/iu',
    '/per person, management/iu',
    '/(?:does not|doesn\'t) (?:accept|allow) (?:two|more than one)[^.]{0,40}balances/iu',
    // portoghese
    '/(?:tiver já|já tem|já têm) (?:um|uma) saldo nessa/u',
    '/(?:aceita|guarda) (?:um|uma)(?: só| única)? (?:saldo|linha|por pessoa)/u',
    '/por pessoa, gestão/u',
    '/não (?:aceita|admite) (?:dois|mais de um)[^.]{0,40}saldos/u',
];

/** L'eccezione: i saldi scritti da un passaggio non contano. */
const B52_WALLET_ECCEZIONE = '/scritt[oi] da un passaggio|righe dei passaggi|riga di un conguaglio|senza contare|written by a transfer|not counting|without counting|escrit[oa]s? por uma passagem|sem contar/iu';

/** Il testo di un file come lo legge un utente: tag tolti, apostrofi dei file PHP sciolti, spazi normalizzati. */
function b52WalletTesto(string $percorso): string
{
    $t = File::get($percorso);
    if (str_ends_with($percorso, '.json')) {
        $voci = json_decode($t, true)['features'] ?? [];
        $t = implode(' ', array_filter($voci, fn ($v) => is_string($v) && ! preg_match('/^(ERRATA|ERRATUM)\b/u', $v)));
    } elseif (str_ends_with($percorso, '.vue') || str_ends_with($percorso, '.blade.php')) {
        $t = strip_tags($t);
    }

    return preg_replace('/\s+/u', ' ', str_replace("\\'", "'", $t));
}

/** I punti di un testo in cui la regola è detta senza l'eccezione lì accanto (100 caratteri prima, 200 dopo). */
function b52WalletSenzaEccezione(string $testo): array
{
    $trovati = [];
    foreach (B52_WALLET_ANCORE as $ancora) {
        if (! preg_match_all($ancora, $testo, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as [$frammento, $byte]) {
            $da = max(0, $byte - 100);
            // La finestra si taglia ai byte senza spezzare una lettera: un taglio a metà di «é» rende il testo non valido, e la ricerca non trova niente.
            $finestra = mb_strcut($testo, $da, 100 + strlen($frammento) + 200);
            if (! preg_match(B52_WALLET_ECCEZIONE, $finestra)) {
                $trovati[$da] = '…' . trim($finestra) . '…';
            }
        }
    }
    ksort($trovati);

    return array_values($trovati);
}

/** I file da guardare: i testi del prodotto, senza i test, i changelog delle versioni 50 e 51 e le frasi generate dal codice. */
function b52WalletFile(): array
{
    $base = base_path();
    $file = [];
    foreach (['resources/js', 'resources/views', 'lang', 'resources/data/changelogs'] as $cartella) {
        foreach (File::allFiles("$base/$cartella") as $f) {
            $p = $f->getPathname();
            if (! preg_match('/\.(vue|ts|php|json)$/', $p) || str_ends_with($p, '.test.ts') || str_contains($p, 'node_modules')) {
                continue;
            }
            if (preg_match('#/changelogs/(it|en|pt)/1\.11\.0-beta\.(50|51)\.json$#', $p)) {
                continue;
            }
            $file[] = $p;
        }
    }

    return $file;
}

it('ogni testo del prodotto che detta la regola del Wallet dice anche che i saldi scritti da un passaggio non contano', function () {
    $senza = [];
    foreach (b52WalletFile() as $percorso) {
        foreach (b52WalletSenzaEccezione(b52WalletTesto($percorso)) as $punto) {
            $senza[] = str_replace(base_path() . '/', '', $percorso) . ' ' . $punto;
        }
    }

    expect($senza)->toBe([], count($senza) . " punti senza l'eccezione:\n" . implode("\n", $senza));
});

it('controprova — la guardia riconosce la formula senza l\'eccezione in italiano, in inglese e in portoghese, e accetta quella con l\'eccezione', function (string $testo, bool $segnalata) {
    expect(b52WalletSenzaEccezione($testo) !== [])->toBe($segnalata);
})->with([
    'it, accetta una sola riga' => ['il Wallet accetta una sola riga per persona, gestione, unità ed esercizio', true],
    'it, ha già un saldo' => ['solo se nessuna delle due persone ha già un saldo su quella gestione, unità ed esercizio', true],
    'it, ne tiene uno' => ['il Wallet ne tiene uno per persona, gestione, unità ed esercizio', true],
    'it, con l\'eccezione' => ['il Wallet accetta un solo saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio', false],
    'it, non accetta due saldi' => ['Il Wallet non accetta due saldi della stessa persona sulla stessa gestione.', true],
    'it, non accetta più saldi' => ['Il Wallet non ammette più saldi per la stessa persona sulla stessa gestione.', true],
    'it, non accetta due saldi, con l\'eccezione' => ['Il Wallet non accetta due saldi della stessa persona sulla stessa gestione, senza contare quelli scritti da un passaggio.', false],
    'it, il saldo non scritto da un passaggio' => ['se nessuno ha già un saldo su quella gestione che non sia stato scritto da un passaggio', false],
    'en, single row' => ['the Wallet accepts a single row per person, management, unit and year', true],
    'en, already has' => ['neither of the two people already has a balance on that management, unit and year', true],
    'en, does not accept two' => ['the Wallet does not accept two balances for the same person on the same management', true],
    'en, con l\'eccezione' => ['the Wallet accepts a single balance per person, management, unit and year, not counting those written by a transfer', false],
    'pt, uma só linha' => ['a Wallet aceita uma só linha por pessoa, gestão, unidade e exercício', true],
    'pt, já tem' => ['se nenhuma das duas pessoas tiver já um saldo nessa gestão, unidade e exercício', true],
    'pt, guarda um' => ['a Wallet guarda um por pessoa, gestão, unidade e exercício', true],
    'pt, não aceita dois' => ['a Wallet não aceita dois saldos da mesma pessoa na mesma gestão', true],
    'pt, con l\'eccezione' => ['a Wallet aceita uma só linha por pessoa, gestão, unidade e exercício, sem contar as escritas por uma passagem', false],
    'senza la regola' => ['Tutti i soggetti associati hanno già un saldo per questa gestione.', false],
]);

it('controprova — la guardia vede la frase che va a capo nel sorgente di un componente', function () {
    $sorgente = "<p>\n   perché il Wallet accetta una sola riga per persona,\n   gestione, unità ed esercizio; altrimenti la parte si regola fra le parti.\n</p>";
    $file = tempnam(sys_get_temp_dir(), 'b52') . '.vue';
    File::put($file, $sorgente);

    expect(b52WalletSenzaEccezione(b52WalletTesto($file)))->not->toBe([]);
    File::delete($file);
});

it('controprova — i changelog delle versioni 50 e 51 e le voci «ERRATA» non si guardano; gli altri changelog sì', function () {
    $nomi = array_map(fn ($p) => str_replace(base_path() . '/', '', $p), b52WalletFile());

    expect(in_array('resources/data/changelogs/it/1.11.0-beta.50.json', $nomi, true))->toBeFalse()
        ->and(in_array('resources/data/changelogs/en/1.11.0-beta.51.json', $nomi, true))->toBeFalse()
        ->and(in_array('resources/data/changelogs/it/1.11.0-beta.49.json', $nomi, true))->toBeTrue();

    $file = tempnam(sys_get_temp_dir(), 'b52') . '.json';
    File::put($file, json_encode(['features' => ['ERRATA DELLE VERSIONI 50 E 51 — dicevano: il Wallet accetta una sola riga per persona, gestione, unità ed esercizio.']]));
    expect(b52WalletSenzaEccezione(b52WalletTesto($file)))->toBe([]);
    File::delete($file);
});

/** Le stringhe che un file PHP scrive all'utente: i letterali tra apici, con gli apostrofi sciolti e gli spazi normalizzati. */
function b52WalletStringhe(string $percorso): array
{
    $stringhe = [];
    foreach (token_get_all(File::get($percorso)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $stringhe[] = preg_replace('/\s+/u', ' ', str_replace("\\'", "'", substr($token[1], 1, -1)));
        }
    }

    return $stringhe;
}

/**
 * Le frasi che nominano chi ha già un saldo: la lista viene da `conUnSaldo`, che non conta i saldi scritti da un passaggio, e la frase è vera per costruzione
 * (la fissano le asserzioni dei suoi test). Non dicono la regola: dicono di chi la regola vale.
 */
const B52_WALLET_FRASE_DELLA_LISTA = 'il programma non ne registra un secondo';

it('le frasi che AnteprimaPassaggio scrive nel pannello dicono la regola del Wallet con l\'eccezione', function () {
    $senza = [];
    foreach (b52WalletStringhe(base_path('app/Services/Subentro/AnteprimaPassaggio.php')) as $stringa) {
        if (str_contains($stringa, B52_WALLET_FRASE_DELLA_LISTA)) {
            continue;
        }
        foreach (b52WalletSenzaEccezione($stringa) as $punto) {
            $senza[] = $punto;
        }
    }

    expect($senza)->toBe([], count($senza) . " frasi senza l'eccezione:\n" . implode("\n", $senza));
});

it('controprova — la lettura delle stringhe vede la frase della successione, e la guardia segnala «se nessuno ha già un saldo lì» e accetta la formula con l\'eccezione', function () {
    $stringhe = b52WalletStringhe(base_path('app/Services/Subentro/AnteprimaPassaggio.php'));
    // Dalla 1.11.0-beta.53 la frase della successione dice i saldi dal Wallet legati a lei, senza la condizione su chi ha già un saldo.
    expect(array_values(array_filter($stringhe, fn ($t) => str_contains($t, 'i saldi dal Wallet legati a questa successione, sulla stessa gestione'))))->not->toBe([]);
    expect(array_values(array_filter($stringhe, fn ($t) => str_contains($t, "se nessuno ha già, su quella gestione, quell'unità e quell'esercizio, un saldo che non sia stato scritto da un passaggio"))))->toBe([]);

    $vecchia = 'un saldo manuale dal Wallet sulla stessa gestione (una riga a credito di %s e una a debito di ogni erede, per la sua quota) se nessuno ha già un saldo lì, altrimenti la parte si regola fra le parti';
    $giusta = 'un saldo manuale dal Wallet sulla stessa gestione (una riga a credito di %s e una a debito di ogni erede, per la sua quota) se nessuno ha già, su quella gestione, quell\'unità e quell\'esercizio, un saldo che non sia stato scritto da un passaggio, altrimenti la parte si regola fra le parti';
    expect(b52WalletSenzaEccezione($vecchia))->not->toBe([])
        ->and(b52WalletSenzaEccezione($giusta))->toBe([]);
});

/** I commenti di un file PHP come li legge una persona: un paragrafo per ogni gruppo di commenti vicini, con gli spazi normalizzati. */
function b52WalletCommenti(string $percorso): array
{
    $paragrafi = [];
    $corrente = '';
    $chiudi = function () use (&$paragrafi, &$corrente) {
        if (trim($corrente) !== '') {
            $paragrafi[] = preg_replace('/\s+/u', ' ', trim($corrente));
        }
        $corrente = '';
    };
    foreach (token_get_all(File::get($percorso)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $corrente .= ' ' . preg_replace(['#^/\*+#', '#\*+/$#', '#^//#', '#^\s*\*#m'], '', $token[1]);
        } elseif (is_array($token) && $token[0] === T_WHITESPACE) {
            if (substr_count($token[1], "\n") >= 2) {
                $chiudi();
            }
        } else {
            $chiudi();
        }
    }
    $chiudi();

    return $paragrafi;
}

/** I commenti che dicono la regola, per file: i modelli con le parole che ognuno usa. L'eccezione deve stare lì accanto. */
const B52_WALLET_COMMENTI = [
    'app/Services/Subentro/FermataDopoScrivi.php' => ['/ne ha già uno lì/u'],
    'app/Services/Subentro/AnteprimaPassaggio.php' => ['/il Wallet ne accetta uno/u'],
    'app/Services/Subentro/DefuntoNelPiano.php' => ['/ha già un saldo su quella gestione/u', '/il Wallet accetta (?:un|uno|una)\b[^.;]{0,40}per persona/u', '/(?:un solo saldo|una sola riga) per persona/u'],
    'app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php' => ['/(?:un solo saldo|una sola riga) per \(esercizio, gestione, immobile/u'],
];
const B52_WALLET_ECCEZIONE_NEL_COMMENTO = '/che un passaggio non ha scritto|scritt[oi] da un passaggio|righe dei passaggi|riga di un conguaglio|senza contare/iu';

/** I punti di un commento in cui uno dei modelli dice la regola senza l'eccezione lì accanto (100 caratteri prima, 200 dopo). */
function b52WalletCommentoSenzaEccezione(string $commento, array $modelli): array
{
    $senza = [];
    foreach ($modelli as $modello) {
        if (! preg_match_all($modello, $commento, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as [$frammento, $byte]) {
            $da = max(0, $byte - 100);
            // La finestra si taglia ai byte senza spezzare una lettera: un taglio a metà di «é» rende il testo non valido, e la ricerca non trova niente.
            $finestra = mb_strcut($commento, $da, 100 + strlen($frammento) + 200);
            if (! preg_match(B52_WALLET_ECCEZIONE_NEL_COMMENTO, $finestra)) {
                $senza[] = '…' . trim($finestra) . '…';
            }
        }
    }

    return $senza;
}

it('i commenti che dicono la regola del Wallet in FermataDopoScrivi, AnteprimaPassaggio, DefuntoNelPiano e CreateSaldoRequest dicono anche che contano i saldi che un passaggio non ha scritto', function () {
    $senza = [];
    foreach (B52_WALLET_COMMENTI as $file => $modelli) {
        foreach (b52WalletCommenti(base_path($file)) as $commento) {
            foreach (b52WalletCommentoSenzaEccezione($commento, $modelli) as $punto) {
                $senza[] = $file . ' ' . $punto;
            }
        }
    }

    expect($senza)->toBe([], count($senza) . " commenti senza l'eccezione:\n" . implode("\n", $senza));
});

it('controprova — ogni file dei commenti dice la regola almeno una volta: un modello che non trova niente non guarda niente', function (string $file) {
    $trovati = 0;
    foreach (b52WalletCommenti(base_path($file)) as $commento) {
        foreach (B52_WALLET_COMMENTI[$file] as $modello) {
            $trovati += preg_match_all($modello, $commento);
        }
    }

    expect($trovati)->toBeGreaterThan(0, "Nessun commento di $file dice la regola con le parole dei modelli.");
})->with(array_keys(B52_WALLET_COMMENTI));

it('controprova — i modelli dei commenti segnalano la regola senza l\'eccezione e accettano quella con l\'eccezione', function (string $file, string $commento, bool $segnalato) {
    expect(b52WalletCommentoSenzaEccezione($commento, B52_WALLET_COMMENTI[$file]) !== [])->toBe($segnalato);
})->with([
    'ne ha già uno lì, senza' => ['app/Services/Subentro/FermataDopoScrivi.php', 'solo se nessuna delle persone della riga ne ha già uno lì.', true],
    'ne ha già uno lì, con' => ['app/Services/Subentro/FermataDopoScrivi.php', 'solo se nessuna delle persone della riga ne ha già uno lì, fra quelli che un passaggio non ha scritto.', false],
    'il Wallet ne accetta uno, senza' => ['app/Services/Subentro/AnteprimaPassaggio.php', 'un saldo sulla gestione (il Wallet ne accetta uno), e solo se non ce l\'ha chi esce', true],
    'il Wallet ne accetta uno, con' => ['app/Services/Subentro/AnteprimaPassaggio.php', 'un saldo sulla gestione (il Wallet ne accetta uno, fra quelli che un passaggio non ha scritto), e solo se non ce l\'ha chi esce', false],
    'ha già un saldo su quella gestione, senza' => ['app/Services/Subentro/DefuntoNelPiano.php', 'V3 qualcuno ha già un saldo su quella gestione e quell\'unità, e la parte si regola fra le parti', true],
    'ha già un saldo su quella gestione, con' => ['app/Services/Subentro/DefuntoNelPiano.php', 'V3 qualcuno ha già un saldo su quella gestione e quell\'unità, fra quelli che un passaggio non ha scritto, e la parte si regola', false],
    'il Wallet accetta un saldo per persona, senza' => ['app/Services/Subentro/DefuntoNelPiano.php', 'il Wallet accetta un saldo per persona, gestione, unità ed esercizio; con un saldo già lì la riga verrebbe rifiutata', true],
    'il Wallet accetta un solo saldo per persona, senza' => ['app/Services/Subentro/DefuntoNelPiano.php', 'il Wallet accetta un solo saldo per persona, gestione, unità ed esercizio; con un saldo già lì la riga verrebbe rifiutata', true],
    'il Wallet accetta un saldo per persona, con' => ['app/Services/Subentro/DefuntoNelPiano.php', 'il Wallet accetta un saldo per persona, gestione, unità ed esercizio, fra quelli che un passaggio non ha scritto; con un saldo già lì', false],
    'una sola riga per (esercizio…), senza' => ['app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php', 'Personale: una sola riga per (esercizio, gestione, immobile, anagrafica)', true],
    'un solo saldo per (esercizio…), con' => ['app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php', 'Personale: un solo saldo per (esercizio, gestione, immobile, anagrafica), fra quelli che un passaggio non ha scritto', false],
]);

it('controprova — la lettura dei commenti vede il commento che dice la regola con l\'eccezione e la frase che va a capo', function () {
    $base = tempnam(sys_get_temp_dir(), 'b52');
    $file = $base . '.php';
    File::put($file, "<?php\n\$a = 1; // il Wallet accetta un saldo per persona,\n// gestione, unità ed esercizio\n\n\$b = 2; // altro\n\n/** Un docblock\n * su due righe. */\n");

    expect(b52WalletCommenti($file))->toBe(['il Wallet accetta un saldo per persona, gestione, unità ed esercizio', 'altro', 'Un docblock su due righe.']);
    File::delete([$file, $base]);
});
