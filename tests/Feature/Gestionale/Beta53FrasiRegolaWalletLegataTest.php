<?php

/**
 * Cosa resta scoperto: la regola detta con parole che nessun modello prevede (i modelli sono quelli di `Beta52FrasiRegolaWalletTest`, e in `app/`
 * si guardano le stringhe di `AnteprimaPassaggio.php` e i commenti di quattro file); le chiavi dei changelog diverse da `features`; i changelog
 * fino alla 1.11.0-beta.52 compresa (la storia, scritta prima del legame); i documenti in `docs/`.
 *
 * La regola del Wallet è detta con tutte e due le eccezioni, in ogni testo del prodotto.
 *
 * Il posto del Wallet (un saldo per persona, gestione, unità ed esercizio) non conta né le righe scritte da un passaggio (`subentro_id`) né
 * quelle che l'amministratore lega a un passaggio dal Wallet (`passaggio_id`). Un testo che dice la regola con la sola prima eccezione fa
 * credere che accanto a un riporto non si possa scrivere la riga della strada a mano, che invece si scrive legata al passaggio. La formula:
 * «un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio».
 *
 * La guardia legge il testo come `Beta52FrasiRegolaWalletTest` (spazi normalizzati, tag tolti, 100 caratteri prima e 200 dopo il punto in
 * cui il testo detta la regola) e chiede lì accanto la seconda eccezione, in italiano, in inglese e in portoghese. Non guarda i changelog
 * fino alla 1.11.0-beta.52 compresa né le voci che cominciano con «ERRATA» o «ERRATUM».
 */

use Illuminate\Support\Facades\File;

/** I modelli: dove un testo detta la regola (gli stessi della guardia della prima eccezione). */
const SL53W_ANCORE = [
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

/** La seconda eccezione: i saldi legati a un passaggio non contano. */
const SL53W_ECCEZIONE = '/legat[oaie] a un passaggio|legat[oaie] al passaggio|linked to a transfer|ligad[oa]s? a uma passagem/iu';

/** Il testo di un file come lo legge un utente: tag tolti, apostrofi dei file PHP sciolti, spazi normalizzati; dei changelog la sola chiave `features`. */
function sl53wTesto(string $percorso): string
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

/** I punti di un testo in cui la regola è detta senza la seconda eccezione lì accanto (100 caratteri prima, 200 dopo). */
function sl53wSenzaEccezione(string $testo, array $ancore = SL53W_ANCORE, string $eccezione = SL53W_ECCEZIONE): array
{
    $trovati = [];
    foreach ($ancore as $ancora) {
        if (! preg_match_all($ancora, $testo, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as [$frammento, $byte]) {
            $da = max(0, $byte - 100);
            // La finestra si taglia ai byte senza spezzare una lettera: un taglio a metà di «é» rende il testo non valido, e la ricerca non trova niente.
            $finestra = mb_strcut($testo, $da, 100 + strlen($frammento) + 200);
            if (! preg_match($eccezione, $finestra)) {
                $trovati[$da] = '…' . trim($finestra) . '…';
            }
        }
    }
    ksort($trovati);

    return array_values($trovati);
}

/** I changelog scritti prima del legame: fino alla 1.11.0-beta.52 compresa (e tutte le versioni prima della 1.11.0). */
function sl53wChangelogDiPrima(string $percorso): bool
{
    if (! preg_match('#/changelogs/(?:it|en|pt)/(\d+)\.(\d+)\.(\d+)(?:-beta\.(\d+))?\.json$#', $percorso, $m)) {
        return false;
    }
    $versione = sprintf('%d.%d.%d', $m[1], $m[2], $m[3]);
    if (version_compare($versione, '1.11.0', '<')) {
        return true;
    }

    return $versione === '1.11.0' && isset($m[4]) && $m[4] !== '' && (int) $m[4] <= 52;
}

/** I file da guardare: i testi del prodotto, senza i test e senza i changelog di prima del legame. */
function sl53wFile(): array
{
    $base = base_path();
    $file = [];
    foreach (['resources/js', 'resources/views', 'lang', 'resources/data/changelogs'] as $cartella) {
        foreach (File::allFiles("$base/$cartella") as $f) {
            $p = $f->getPathname();
            if (! preg_match('/\.(vue|ts|php|json)$/', $p) || str_ends_with($p, '.test.ts') || str_contains($p, 'node_modules') || sl53wChangelogDiPrima($p)) {
                continue;
            }
            $file[] = $p;
        }
    }

    return $file;
}

it('ogni testo del prodotto che detta la regola del Wallet dice anche che i saldi legati a un passaggio non contano', function () {
    $senza = [];
    foreach (sl53wFile() as $percorso) {
        foreach (sl53wSenzaEccezione(sl53wTesto($percorso)) as $punto) {
            $senza[] = str_replace(base_path() . '/', '', $percorso) . ' ' . $punto;
        }
    }

    expect($senza)->toBe([], count($senza) . " punti senza la seconda eccezione:\n" . implode("\n", $senza));
});

it('controprova — la guardia segnala la formula con la sola prima eccezione e accetta quella con tutte e due, in italiano, in inglese e in portoghese', function (string $testo, bool $segnalata) {
    expect(sl53wSenzaEccezione($testo) !== [])->toBe($segnalata);
})->with([
    'it, solo la prima' => ['il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio', true],
    'it, tutte e due' => ['il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio', false],
    'it, ha già un saldo, solo la prima' => ['se nessuno ha già un saldo su quella gestione che non sia stato scritto da un passaggio', true],
    'it, legati al passaggio' => ['un saldo per persona, gestione, unità ed esercizio: le righe legate al passaggio non occupano il posto', false],
    'en, solo la prima' => ['the Wallet accepts a single balance per person, management, unit and year, not counting those written by a transfer', true],
    'en, tutte e due' => ['the Wallet accepts a single balance per person, management, unit and year, not counting those written by a transfer or linked to a transfer', false],
    'pt, solo la prima' => ['a Wallet aceita uma só linha por pessoa, gestão, unidade e exercício, sem contar as escritas por uma passagem', true],
    'pt, tutte e due' => ['a Wallet aceita uma só linha por pessoa, gestão, unidade e exercício, sem contar as escritas por uma passagem nem as ligadas a uma passagem', false],
    'senza la regola' => ['Tutti i soggetti associati hanno già un saldo per questa gestione.', false],
]);

it('controprova — i changelog fino alla 1.11.0-beta.52 compresa non si guardano, quelli dopo sì, e le voci «ERRATA» non si guardano', function () {
    expect(sl53wChangelogDiPrima('/x/resources/data/changelogs/it/1.11.0-beta.52.json'))->toBeTrue()
        ->and(sl53wChangelogDiPrima('/x/resources/data/changelogs/en/1.11.0-beta.9.json'))->toBeTrue()
        ->and(sl53wChangelogDiPrima('/x/resources/data/changelogs/pt/1.10.0.json'))->toBeTrue()
        ->and(sl53wChangelogDiPrima('/x/resources/data/changelogs/it/1.11.0-beta.53.json'))->toBeFalse()
        ->and(sl53wChangelogDiPrima('/x/resources/data/changelogs/it/1.11.0.json'))->toBeFalse();

    $base = tempnam(sys_get_temp_dir(), 'sl53w');
    $file = $base . '.json';
    File::put($file, json_encode(['features' => ['ERRATA — diceva: il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio.']]));
    expect(sl53wSenzaEccezione(sl53wTesto($file)))->toBe([]);
    File::delete([$file, $base]);
});

/** Le stringhe che un file PHP scrive all'utente: i letterali tra apici, con gli apostrofi sciolti e gli spazi normalizzati. */
function sl53wStringhe(string $percorso): array
{
    $stringhe = [];
    foreach (token_get_all(File::get($percorso)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $stringhe[] = preg_replace('/\s+/u', ' ', str_replace("\\'", "'", substr($token[1], 1, -1)));
        }
    }

    return $stringhe;
}

it('le frasi che AnteprimaPassaggio scrive nel pannello e la strada del saldo di AvvisoSpeseNegative dicono la regola con tutte e due le eccezioni', function () {
    $senza = [];
    foreach (['app/Services/Subentro/AnteprimaPassaggio.php', 'app/Services/Riparto/AvvisoSpeseNegative.php'] as $file) {
        foreach (sl53wStringhe(base_path($file)) as $stringa) {
            foreach (sl53wSenzaEccezione($stringa) as $punto) {
                $senza[] = $file . ' ' . $punto;
            }
        }
    }

    expect($senza)->toBe([], count($senza) . " frasi senza la seconda eccezione:\n" . implode("\n", $senza));
});

/** I commenti di un file PHP come li legge una persona: un paragrafo per ogni gruppo di commenti vicini, con gli spazi normalizzati. */
function sl53wCommenti(string $percorso): array
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

/** I commenti che dicono la regola, per file: i modelli con le parole che ognuno usa (gli stessi della guardia della prima eccezione). */
const SL53W_COMMENTI = [
    'app/Services/Subentro/FermataDopoScrivi.php' => ['/ne ha già uno lì/u'],
    'app/Services/Subentro/AnteprimaPassaggio.php' => ['/il Wallet ne accetta uno/u'],
    'app/Services/Subentro/DefuntoNelPiano.php' => ['/ha già un saldo su quella gestione/u', '/il Wallet accetta (?:un|uno|una)\b[^.;]{0,40}per persona/u', '/(?:un solo saldo|una sola riga) per persona/u'],
    'app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php' => ['/(?:un solo saldo|una sola riga) per \(esercizio, gestione, immobile/u'],
];

it('i commenti che dicono la regola del Wallet in FermataDopoScrivi, AnteprimaPassaggio, DefuntoNelPiano e CreateSaldoRequest dicono anche che i saldi legati a un passaggio non contano', function () {
    $senza = [];
    foreach (SL53W_COMMENTI as $file => $modelli) {
        foreach (sl53wCommenti(base_path($file)) as $commento) {
            foreach (sl53wSenzaEccezione($commento, $modelli) as $punto) {
                $senza[] = $file . ' ' . $punto;
            }
        }
    }

    expect($senza)->toBe([], count($senza) . " commenti senza la seconda eccezione:\n" . implode("\n", $senza));
});

it('controprova — i modelli dei commenti segnalano la regola con la sola prima eccezione e accettano quella con tutte e due', function (string $file, string $commento, bool $segnalato) {
    expect(sl53wSenzaEccezione($commento, SL53W_COMMENTI[$file]) !== [])->toBe($segnalato);
})->with([
    'ne ha già uno lì, solo la prima' => ['app/Services/Subentro/FermataDopoScrivi.php', 'solo se nessuna delle persone della riga ne ha già uno lì, fra quelli che un passaggio non ha scritto.', true],
    'ne ha già uno lì, tutte e due' => ['app/Services/Subentro/FermataDopoScrivi.php', 'solo se nessuna delle persone della riga ne ha già uno lì, senza contare quelli scritti da un passaggio né quelli legati a un passaggio.', false],
    'un solo saldo per (esercizio…), solo la prima' => ['app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php', 'Personale: un solo saldo per (esercizio, gestione, immobile, anagrafica), fra quelli che un passaggio non ha scritto', true],
    'un solo saldo per (esercizio…), tutte e due' => ['app/Http/Requests/Gestionale/Saldi/CreateSaldoRequest.php', 'Personale: un solo saldo per (esercizio, gestione, immobile, anagrafica), senza contare quelli scritti da un passaggio né quelli legati a un passaggio', false],
]);

it('controprova — ogni file dei commenti dice la regola almeno una volta: un modello che non trova niente non guarda niente', function (string $file) {
    $trovati = 0;
    foreach (sl53wCommenti(base_path($file)) as $commento) {
        foreach (SL53W_COMMENTI[$file] as $modello) {
            $trovati += preg_match_all($modello, $commento);
        }
    }

    expect($trovati)->toBeGreaterThan(0, "Nessun commento di $file dice la regola con le parole dei modelli.");
// Coda 245 (1.11.0-beta.55): in AnteprimaPassaggio la regola la diceva solo il commento della fermata del coinquilino, che con la regola del piano
// non c'è più (il pannello non indica più il Wallet per quella fine). Il file resta fra quelli guardati qui sopra: un commento nuovo con la
// regola deve avere l'eccezione; la controprova «almeno una volta» vale per gli altri.
})->with(array_values(array_diff(array_keys(SL53W_COMMENTI), ['app/Services/Subentro/AnteprimaPassaggio.php'])));
