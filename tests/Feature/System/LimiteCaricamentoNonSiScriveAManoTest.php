<?php

/**
 * Nessuna richiesta valida un caricamento con un numero scritto a mano.
 *
 * ## Perché questo test esiste, e perché è un test e non un commento
 *
 * La beta.58 ha corretto il limite di caricamento **dove era stato segnalato** — i documenti di
 * un'unità — e la misura di chiusura ha trovato altre **sei** porte identiche. La beta.60 le chiude
 * tutte. Ma chiudere sei difetti non impedisce al settimo di nascere: la prossima richiesta di
 * caricamento sarà scritta copiando una di queste, e `max:20480` è esattamente il genere di riga
 * che si copia senza rileggerla.
 *
 * Il documento di processo lo dice dalla beta.49: *«Alla terza volta si chiude la classe, non il
 * caso»* e *«il presidio non è un commento: è un test»*.
 *
 * ## ⚠️ La prima stesura era cieca, e la revisione l'ha dimostrato in una riga
 *
 * Confrontava «parla di file» e «tetto numerico» **sulla stessa riga fisica del sorgente**. Ma la
 * forma normale di Laravel è un array con una regola per riga, e lì le due cose non stanno mai
 * insieme: messa alla prova con cinque forme evasive ne riconosceva **zero su cinque** — compresa
 * quella che la beta.60 stessa ha scritto in `StoreFatturaRequest`, dove `'file'` sta a una riga e
 * `'max:'` quattro righe più giù. La guardia scritta per impedire alla settima porta di nascere non
 * avrebbe visto la settima porta.
 *
 * ## Perché guarda il sorgente e non le regole risolte
 *
 * La strada che sembrava giusta — istanziare la richiesta e leggere `rules()` — è stata provata e
 * **scartata per due ragioni misurate**:
 *
 * 1. `rules()` restituisce `max:20480` tanto se il numero è letterale quanto se viene da
 *    `regolaMax()`. La differenza che ci interessa **esiste solo nel sorgente**: una cifra attaccata
 *    ai due punti contro un apice.
 * 2. **Sei richieste su 88 non si istanziano** fuori da una richiesta HTTP vera — leggono
 *    `$this->route(...)` o l'utente — e fra queste ci sono proprio le due della fattura passiva che
 *    la beta.60 ha corretto. Una guardia che le salta è cieca dove serve.
 *
 * Quindi si legge il sorgente, ma **normalizzato**: via i commenti (che citano `max:20480` apposta
 * per spiegare il difetto), spazi collassati (così l'impaginazione non conta) e **tagliato per
 * campo**, perché `'name' => 'string|max:255'` convive con la regola sul file in quasi ogni
 * richiesta del progetto e non deve far scattare niente.
 */

use App\Support\LimiteCaricamento;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\File as RegolaFile;

/**
 * Ogni classe sotto `app/Http/Requests`, come nome pienamente qualificato.
 *
 * @return list<class-string>
 */
function richiesteDelProgetto(): array
{
    $classi = [];

    foreach (File::allFiles(app_path('Http/Requests')) as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }

        $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], ltrim(str_replace(app_path(), '', $f->getPathname()), '/'));

        if (class_exists($classe)) {
            $classi[] = $classe;
        }
    }

    sort($classi);

    return $classi;
}

/** I pezzi di una regola, qualunque forma abbia: stringa con le barre, array annidati, oggetti. */
function pezziDellaRegola(mixed $regola): array
{
    if (is_string($regola)) {
        return explode('|', $regola);
    }

    if (is_array($regola)) {
        return array_merge(...array_map('pezziDellaRegola', $regola)) ?: [];
    }

    return [$regola];
}

/** Questa regola riguarda un file? Si guarda come la vede Laravel, non come è impaginata. */
function regolaDiFile(mixed $regola): bool
{
    foreach (pezziDellaRegola($regola) as $pezzo) {
        if ($pezzo instanceof RegolaFile) {
            return true;
        }

        if (! is_string($pezzo)) {
            continue;
        }

        if ($pezzo === 'file' || $pezzo === 'image'
            || str_starts_with($pezzo, 'image:')
            || str_starts_with($pezzo, 'mimes:')
            || str_starts_with($pezzo, 'mimetypes:')) {
            return true;
        }
    }

    return false;
}

/**
 * Il sorgente di una classe, senza commenti e con gli spazi collassati.
 *
 * ⚠️ Togliere i commenti non è pulizia: i docblock di queste stesse richieste **citano**
 * `max:20480` per spiegare il difetto, e senza questo passo la guardia segnalerebbe la spiegazione.
 * Collassare gli spazi è ciò che la rende immune all'impaginazione.
 */
function sorgenteAppiattito(string $classe): string
{
    $percorso = (new ReflectionClass($classe))->getFileName();
    $senzaCommenti = '';

    foreach (token_get_all(file_get_contents($percorso)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $senzaCommenti .= is_array($t) ? $t[1] : $t;
    }

    return preg_replace('/\s+/', ' ', $senzaCommenti);
}

/**
 * Il pezzo di sorgente che definisce un campo, dal suo `'campo' =>` fino al campo successivo.
 *
 * È il taglio che dà la granularità giusta: un `'max:255'` su una stringa accanto a una regola su
 * file non deve far scattare niente.
 */
function definizioneDelCampo(string $sorgente, string $campo): string
{
    if (! preg_match("/'".preg_quote($campo, '/')."'\s*=>\s*/", $sorgente, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $da = $m[0][1] + strlen($m[0][0]);
    $resto = substr($sorgente, $da);

    // Fino alla prossima chiave dell'array, che è la forma `'qualcosa' =>`.
    return preg_split("/'[a-z_0-9.*]+'\s*=>/i", $resto)[0] ?? $resto;
}

/**
 * Il corpo del metodo `rules()`, dal sorgente appiattito.
 *
 * Serve a non guardare `messages()` e `attributes()`, dove una chiave come `'file.max'` esiste per
 * ragioni legittime e non è una regola.
 */
function corpoDelleRegole(string $sorgente): string
{
    if (! preg_match('/function rules\s*\([^)]*\)\s*:?\s*\w*\s*\{/', $sorgente, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $resto = substr($sorgente, $m[0][1] + strlen($m[0][0]));

    // Fino alla dichiarazione del metodo successivo, che è il confine più affidabile senza contare
    // le graffe (dentro le regole ce ne sono, per esempio nelle chiusure di `Rule::…`).
    return preg_split('/(public|protected|private)\s+function\s/', $resto)[0] ?? $resto;
}

/**
 * I campi dichiarati dentro `rules()`, con la loro definizione.
 *
 * @return array<string, string>
 */
function campiDelleRegole(string $corpo): array
{
    preg_match_all("/'([a-z_0-9.*]+)'\s*=>\s*/i", $corpo, $trovati, PREG_OFFSET_CAPTURE);

    $campi = [];
    $n = count($trovati[0]);

    for ($i = 0; $i < $n; $i++) {
        $da = $trovati[0][$i][1] + strlen($trovati[0][$i][0]);
        $a = $i + 1 < $n ? $trovati[0][$i + 1][1] : strlen($corpo);
        $campi[$trovati[1][$i][0]] = substr($corpo, $da, $a - $da);
    }

    return $campi;
}

/** La definizione di un campo riguarda un file? Si legge dal sorgente, non dalla regola risolta. */
function definizioneDiFile(string $definizione): bool
{
    return preg_match("/'file'|'image'|'image:|\bmimes:|\bmimetypes:|File::types\(|Rule::file\(/", $definizione) === 1;
}

/**
 * Le porte con un tetto scritto a mano.
 *
 * @return list<string>
 */
function porteConNumeroScrittoAMano(): array
{
    $trovate = [];

    foreach (richiesteDelProgetto() as $classe) {
        $corpo = corpoDelleRegole(sorgenteAppiattito($classe));

        if ($corpo === '') {
            continue;
        }

        foreach (campiDelleRegole($corpo) as $campo => $definizione) {
            if (! definizioneDiFile($definizione)) {
                continue;
            }

            // Una cifra **attaccata** ai due punti: `max:20480` sì, `max:'.LimiteCaricamento::…` no.
            if (preg_match('/max:\s*\d+/', $definizione, $m)
                || preg_match('/->max\(\s*\d+/', $definizione, $m)) {
                $trovate[] = class_basename($classe).'::'.$campo.' → '.trim($m[0]);
            }
        }
    }

    sort($trovate);

    return $trovate;
}

it('nessuna richiesta scrive a mano il limite di un caricamento', function () {
    expect(porteConNumeroScrittoAMano())->toBe([],
        'queste porte promettono un numero che non dipende dal server: '
        .'sostituirlo con \App\Support\LimiteCaricamento::regolaMax() — '
        .'passandogli il tetto della porta se ne ha uno diverso da quello predefinito');
});

it('riconosce come regola su file tutte le forme che Laravel accetta', function () {
    // ⚠️ Il primo passo — «dove guardare» — deve reggere le forme che il progetto usa davvero e
    // quelle che userà. La prima stesura ne mancava cinque su cinque.
    $forme = [
        'stringa in linea'       => 'required|file|mimes:pdf|max:20480',
        'array, una per riga'    => ['nullable', 'file', 'mimes:pdf,xml', 'max:10240'],
        'immagine'               => 'nullable|image|mimes:jpeg,png|max:2048',
        'solo mimes'             => ['required', 'mimes:pdf', 'max:5000'],
        'fluente'                => [RegolaFile::types(['pdf'])->max(20480)],
        'image con parametro'    => 'nullable|image:allow_svg|max:2048',
    ];

    foreach ($forme as $nome => $regola) {
        expect(regolaDiFile($regola))->toBeTrue("«{$nome}» non è riconosciuta come regola su un file");
    }

    // E quello che non deve toccare: i `max:` sulle stringhe sono ovunque nel progetto.
    expect(regolaDiFile('required|string|max:255'))->toBeFalse()
        ->and(regolaDiFile(['nullable', 'integer', 'max:12']))->toBeFalse();
});

it('distingue un numero scritto a mano da uno calcolato, che è tutta la questione', function () {
    // ⚠️ È il secondo passo, quello che `rules()` da sola non può fare: la regola risolta vale
    // `max:20480` in tutti e due i casi, e la differenza esiste solo nel sorgente.
    $scrittoAMano = "'file' => 'required|file|mimes:pdf|max:20480',";
    $calcolato = "'file' => 'required|file|mimes:pdf|max:'.LimiteCaricamento::regolaMax(),";
    $calcolatoConTetto = "'file' => ['nullable', 'file', 'max:'.LimiteCaricamento::regolaMax(10.0)],";
    $fluenteAMano = "'file' => [File::types(['pdf'])->max(20480)],";

    $vede = fn (string $s) => preg_match('/max:\s*\d+/', $s) === 1 || preg_match('/->max\(\s*\d+/', $s) === 1;

    expect($vede($scrittoAMano))->toBeTrue()
        ->and($vede($fluenteAMano))->toBeTrue()
        ->and($vede($calcolato))->toBeFalse()
        ->and($vede($calcolatoConTetto))->toBeFalse();
});

it('il taglio per campo impedisce a un max: su una stringa di far scattare l\'allarme', function () {
    // È il falso allarme che una guardia grossolana produrrebbe su quasi ogni richiesta del
    // progetto: `'name' => 'required|string|max:255'` convive con la regola sul file.
    $sorgente = "return [ 'name' => 'required|string|max:255', "
        ."'file' => 'required|file|mimes:pdf|max:'.LimiteCaricamento::regolaMax(), "
        ."'note' => 'nullable|string|max:1000', ];";

    expect(definizioneDelCampo($sorgente, 'file'))->not->toMatch('/max:\s*\d+/')
        ->and(definizioneDelCampo($sorgente, 'name'))->toMatch('/max:\s*\d+/');
});

it('la guardia guarda davvero le porte vere, e non un elenco vuoto', function () {
    // Una guardia che passa perché non trova niente da guardare è indistinguibile da una rotta.
    $porte = [];

    foreach (richiesteDelProgetto() as $classe) {
        foreach (campiDelleRegole(corpoDelleRegole(sorgenteAppiattito($classe))) as $campo => $definizione) {
            if (definizioneDiFile($definizione)) {
                $porte[] = class_basename($classe).'::'.$campo;
            }
        }
    }

    // Nove porte corrette dalla beta.60 più le due dei documenti dell'unità, corrette nella .58.
    expect(count($porte))->toBeGreaterThanOrEqual(11)
        ->and($porte)->toContain('StoreFatturaRequest::file')
        ->and($porte)->toContain('UpdatePrintSettingsRequest::firma_stampe');
});

it('vede la forma che la beta.60 stessa ha introdotto, che la prima stesura non vedeva', function () {
    // ⚠️ È il caso che ha fatto cadere la prima guardia: `'file'` a una riga e `'max:'` quattro
    // righe più giù, con tre righe di commento in mezzo. È la forma vera di `StoreFatturaRequest`.
    $sorgente = <<<'PHP'
    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'file' => ['nullable', 'file', 'mimes:pdf,xml,p7m',
                // un commento in mezzo, come quello vero
                'max:10240'],
            'note' => 'nullable|string|max:1000',
        ];
    }
    public function messages(): array
    {
        return ['file.max' => 'troppo grande'];
    }
PHP;

    $appiattito = preg_replace('/\s+/', ' ', preg_replace('~//[^\n]*~', '', $sorgente));
    $campi = campiDelleRegole(corpoDelleRegole($appiattito));

    expect($campi)->toHaveKey('file')
        ->and(definizioneDiFile($campi['file']))->toBeTrue()
        ->and($campi['file'])->toMatch('/max:\s*\d+/');

    // E il campo stringa accanto non deve confondersi con quello del file.
    expect(definizioneDiFile($campi['nome']))->toBeFalse();
});
