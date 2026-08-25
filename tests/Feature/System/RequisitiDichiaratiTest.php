<?php

/**
 * I requisiti dichiarati non possono stare **sotto** quelli che il codice pretende davvero.
 *
 * ⚠️ **Questo è un cancello, e un cancello sbagliato fa danno in tutte e due le direzioni.** Se
 * dichiara **più** del vero, respinge installazioni che funzionerebbero. Se dichiara **meno**, e
 * questo è il caso peggiore, lascia entrare qualcuno il cui hosting non regge il programma — e non
 * se ne accorge all'ingresso, se ne accorge la prima volta che serve la cosa che manca.
 *
 * **Il caso reale che ha fatto scrivere questa guardia.** Al 25/08/2026, alla vigilia della 1.10.0,
 * c'erano **tre** liste di requisiti che non si parlavano:
 *
 * | Fonte | PHP | Estensioni |
 * | :--- | :--- | :--- |
 * | `config/installer.php` | 8.4.0 | openssl, pdo, mbstring, tokenizer, xml, ctype, json |
 * | `UpdateService.php` (ripiego) | 8.4.0 | zip, curl, bcmath, xml, fileinfo, posix |
 * | `packages/latest.json` (il cancello vero) | **8.2.0** | come sopra |
 *
 * Il requisito reale era **PHP 8.4.1**: tutto Symfony, su cui poggia Laravel, chiede `>=8.4.1`. Con
 * il manifest a 8.2.0, al rilascio chiunque fosse su 8.2 o 8.3 avrebbe superato il cancello e si
 * sarebbe ritrovato un'installazione che non parte.
 *
 * E **`gd` non c'era in nessuna delle tre**, mentre lo pretende `mpdf` — il motore che genera ogni
 * PDF del programma. Un hosting senza `gd` passava l'installer, l'amministratore configurava tutto,
 * e poi *ogni singola stampa* falliva. Mancavano anche `intl` e, dalla lista dell'updater,
 * `mbstring`, che pretende Laravel stesso.
 *
 * **Perché una guardia e non una correzione e basta.** Le tre liste erano già state allineate una
 * volta, e sono divergute di nuovo appena una dipendenza ha alzato la propria soglia — che è una
 * cosa che succede da sola, senza che nessuno tocchi niente. Una lista scritta a mano contro un
 * numero che cambia per conto suo diverge sempre: l'unica difesa è **ricalcolarla**.
 *
 * Il conto lo fa `composer.lock`, che è la fotografia esatta di ciò che è installato — non
 * `composer.json`, che dichiara le intenzioni.
 */

/**
 * Il minimo che un vincolo di composer accetta.
 *
 * Un vincolo è un'unione di alternative separate da `||`, e il minimo dell'unione è il minimo dei
 * minimi. Dentro un'alternativa il **primo** numero è la sua soglia: `>=8.4.1` vale 8.4.1,
 * `~8.1.0 || ~8.2.0` vale 8.1.0, e `>=7.2 <=8.5.99999` vale 7.2 — non 8.5, che è il limite
 * superiore. Confondere i due è esattamente l'errore che ho fatto misurando a mano, prima di
 * scrivere questa funzione.
 */
function minimoAccettato(string $vincolo): ?array
{
    $minimi = [];

    foreach (preg_split('/\|\|?/', $vincolo) as $alternativa) {
        if (! preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $alternativa, $m)) {
            continue;
        }
        $minimi[] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    if (empty($minimi)) {
        return null;
    }

    usort($minimi, fn ($a, $b) => $a <=> $b);

    return $minimi[0];
}

/** Il pacchetto di runtime più esigente: `[maggiore, minore, patch]` più il nome di chi lo chiede. */
function phpMinimoRichiesto(): array
{
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true);

    $soglia = [0, 0, 0];
    $chi    = '—';

    // Solo `packages`: `packages-dev` non viene installato in produzione, e pest che chiede 8.3
    // non è un motivo per respingere l'hosting di un amministratore.
    foreach ($lock['packages'] ?? [] as $pacchetto) {
        $vincolo = $pacchetto['require']['php'] ?? null;
        if (! $vincolo) {
            continue;
        }

        $min = minimoAccettato($vincolo);
        if ($min && $min > $soglia) {
            $soglia = $min;
            $chi    = $pacchetto['name'].' ('.$vincolo.')';
        }
    }

    return [$soglia, $chi];
}

/**
 * Le estensioni che i pacchetti di runtime pretendono e che un PHP standard **non** garantisce.
 *
 * L'esclusione non è pigrizia: `json`, `pcre`, `date`, `hash` e compagnia sono compilate dentro PHP
 * e non si possono disattivare in una build normale. Metterle nel cancello significherebbe far
 * fallire il controllo su ambienti che le hanno, per il modo in cui vengono riportate.
 */
function estensioniNonGarantite(): array
{
    $sempre = [
        'core', 'ctype', 'date', 'filter', 'hash', 'json', 'libxml', 'pcre', 'phar',
        'reflection', 'session', 'spl', 'standard', 'tokenizer', 'dom', 'simplexml',
        'xml', 'xmlreader', 'xmlwriter', 'zlib', 'openssl', 'iconv',
    ];

    $lock  = json_decode(file_get_contents(base_path('composer.lock')), true);
    $viste = [];

    foreach ($lock['packages'] ?? [] as $pacchetto) {
        foreach (array_keys($pacchetto['require'] ?? []) as $requisito) {
            if (! str_starts_with($requisito, 'ext-')) {
                continue;
            }
            $nome = substr($requisito, 4);
            if (! in_array($nome, $sempre, true)) {
                $viste[$nome] = true;
            }
        }
    }

    ksort($viste);

    return array_keys($viste);
}

// ─────────────────────────────────────────────────────────────────────────────

it('la funzione che legge i vincoli non confonde il minimo col massimo', function () {
    // ★ La controprova della guardia stessa. Un vincolo con un limite superiore alto è la trappola:
    // `>=7.2 <=8.5.99999` significa «da 7.2 in su», non «8.5».
    expect(minimoAccettato('>=8.4.1'))->toBe([8, 4, 1])
        ->and(minimoAccettato('>=7.2 <=8.5.99999'))->toBe([7, 2, 0])
        ->and(minimoAccettato('~8.1.0 || ~8.2.0 || ~8.3.0'))->toBe([8, 1, 0])
        ->and(minimoAccettato('^8.2|^8.3|^8.4|^8.5'))->toBe([8, 2, 0])
        ->and(minimoAccettato('senza numeri'))->toBeNull();
});

it('la guardia sta guardando qualcosa: composer.lock dichiara davvero una soglia', function () {
    // Una guardia che misura il vuoto passa sempre. Se un domani `composer.lock` cambiasse forma,
    // questa riga se ne accorge invece di lasciar passare tutto in silenzio.
    [$soglia, $chi] = phpMinimoRichiesto();

    expect($soglia[0])->toBeGreaterThanOrEqual(8)
        ->and($chi)->not->toBe('—');

    expect(estensioniNonGarantite())->not->toBeEmpty();
});

it('l\'installer non lascia entrare chi sta sotto il PHP che il codice pretende', function () {
    [$soglia, $chi] = phpMinimoRichiesto();
    $richiesto = implode('.', $soglia);
    $dichiarato = config('installer.requirements.php');

    expect(version_compare($dichiarato, $richiesto, '>='))->toBeTrue(
        "config/installer.php dichiara PHP {$dichiarato}, ma {$chi} pretende {$richiesto}. ".
        'Chi installa su una versione in mezzo supera il controllo e si ritrova un programma che non parte.'
    );
});

it('il ripiego dell\'aggiornamento non è più permissivo del vero', function () {
    // ⚠️ Il ripiego di `UpdateService` vale quando il manifest remoto tace, ed è il caso in cui un
    // errore fa più danno: si scrivono i file e poi si scopre che l'ambiente non regge.
    [$soglia, $chi] = phpMinimoRichiesto();
    $richiesto = implode('.', $soglia);

    $sorgente = file_get_contents(base_path('app/Services/UpdateService.php'));

    preg_match_all("/'php'\s*=>\s*'([\d.]+)'|\?\?\s*'([\d.]+)'/", $sorgente, $m, PREG_SET_ORDER);
    $trovati = array_values(array_filter(array_map(fn ($x) => $x[1] ?: ($x[2] ?? ''), $m)));

    expect($trovati)->not->toBeEmpty('Nessuna soglia PHP trovata in UpdateService: il ripiego è sparito o ha cambiato forma.');

    foreach ($trovati as $dichiarato) {
        expect(version_compare($dichiarato, $richiesto, '>='))->toBeTrue(
            "UpdateService ripiega su PHP {$dichiarato}, ma {$chi} pretende {$richiesto}."
        );
    }
});

it('l\'installer chiede tutte le estensioni che un PHP standard non garantisce', function () {
    // ★ È la guardia che avrebbe preso `gd`. Lo pretende mpdf, cioè il motore di **ogni** PDF del
    // programma, e non era in nessuna delle tre liste: l'installazione riusciva e poi ogni stampa
    // falliva.
    $necessarie = estensioniNonGarantite();
    $dichiarate = config('installer.requirements.extensions', []);

    $mancanti = array_values(array_diff($necessarie, $dichiarate));

    expect($mancanti)->toBe([], 'Estensioni pretese da un pacchetto di runtime e non dichiarate: '.implode(', ', $mancanti));
});

it('anche il ripiego dell\'aggiornamento le elenca', function () {
    $necessarie = estensioniNonGarantite();
    $sorgente   = file_get_contents(base_path('app/Services/UpdateService.php'));

    $mancanti = array_values(array_filter(
        $necessarie,
        fn ($ext) => ! str_contains($sorgente, "'{$ext}'")
    ));

    expect($mancanti)->toBe([], 'Estensioni assenti dal ripiego di UpdateService: '.implode(', ', $mancanti));
});

/**
 * ★ **I README sono la quarta lista, e nessuno li guardava.**
 *
 * Le tre liste in codice erano già divergenti; i tre README erano una quarta copia, e stavano ancora
 * a `PHP >= 8.2` con una lista di estensioni senza `gd`, `intl` e `bcmath`. Il README italiano si
 * contraddiceva perfino da solo: una riga diceva già *«da KondoManager 1.10.0 il riferimento è
 * PHP 8.4»* mentre quella dei requisiti, cinquanta righe sopra, ne dichiarava 8.2.
 *
 * Sono la prima cosa che legge chi arriva da GitHub e decide se il programma gira sul suo hosting:
 * una soglia sbagliata lì manda qualcuno a installare qualcosa che non può funzionare, oppure
 * allontana chi invece potrebbe.
 *
 * Segnalati da Vincenzo il 25/08/2026, dopo che la prima stesura di questa guardia li aveva
 * ignorati — cioè la guardia aveva lo stesso punto cieco del difetto che presidiava.
 */
it('i tre README dichiarano la stessa soglia PHP del codice', function () {
    [$soglia, $chi] = phpMinimoRichiesto();
    [$maj, $min] = $soglia;

    foreach (['README.md', 'README.en.md', 'README.pt-br.md'] as $file) {
        $testo = file_get_contents(base_path($file));

        expect($testo)->toMatch('/\*\*PHP\*\* >= '.$maj.'\.'.$min.'/',
            "{$file} non dichiara PHP {$maj}.{$min} nei requisiti minimi, mentre {$chi} lo pretende."
        );
    }
});

it('e nominano le estensioni che un PHP standard non garantisce', function () {
    $necessarie = estensioniNonGarantite();

    foreach (['README.md', 'README.en.md', 'README.pt-br.md'] as $file) {
        $testo = file_get_contents(base_path($file));

        $mancanti = array_values(array_filter(
            $necessarie,
            fn ($ext) => ! str_contains($testo, '`'.$ext.'`')
        ));

        expect($mancanti)->toBe([], "{$file} non nomina: ".implode(', ', $mancanti));
    }
});

/**
 * Il verso opposto: il cancello non deve nemmeno respingere per niente.
 *
 * Una soglia dichiarata **sopra** il necessario chiude fuori installazioni che funzionerebbero, e
 * nessuno se ne accorge — l'amministratore respinto non scrive, cambia programma.
 */
it('e non chiede più del necessario, che respingerebbe installazioni buone', function () {
    [$soglia] = phpMinimoRichiesto();

    // Si tollera di dichiarare la stessa minor version, non una superiore: fra `8.4.1` e `8.4.0`
    // la differenza è legittima (una dipendenza può alzare la patch), fra `8.4` e `8.5` no.
    $dichiarato = config('installer.requirements.php');
    [$maj, $min] = array_map('intval', explode('.', $dichiarato) + [0, 0]);

    expect([$maj, $min])->toBe([$soglia[0], $soglia[1]],
        "config/installer.php dichiara PHP {$dichiarato}: è una minor version più alta del necessario, ".
        'e respingerebbe hosting su cui il programma gira.'
    );
});
