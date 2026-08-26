<?php

/**
 * Il progetto dichiara **una sola** licenza, e la dichiara uguale ovunque.
 *
 * ⚠️ **Non è un controllo cosmetico.** Una licenza dichiarata in due modi diversi non è un refuso:
 * è un'ambiguità legale che qualcuno può sfruttare. MIT e AGPL-3.0 sono l'una l'opposto dell'altra
 * — MIT consente di redistribuire il codice **chiuso**, AGPL impone la disponibilità del sorgente
 * anche a chi lo usa attraverso la rete. Su quella imposizione poggia il modello open-core del
 * progetto: *il motore contabile resta AGPL per sempre*. Se un manifest dice MIT, chiunque ha un
 * appiglio per sostenere di averlo preso sotto MIT.
 *
 * **Il caso reale che ha fatto scrivere questa guardia.** Il 26/08/2026, il giorno dopo il rilascio
 * della 1.10.0, rileggendo i post di lancio per verificarne le affermazioni prima di pubblicarle:
 *
 * | Fonte | Dichiarava |
 * | :--- | :--- |
 * | `composer.json` | **MIT** |
 * | `LICENSE`, `NOTICE`, i tre README, sei schermate dell'applicazione, il sito | **AGPL-3.0** |
 *
 * Una riga sola, quasi certamente ereditata dallo scheletro di Laravel — che nasce MIT — e mai
 * toccata da allora. Insieme alla licenza era rimasta **tutta l'identità del pacchetto**: il nome
 * era `laravel/vue-starter-kit` e la descrizione *«The skeleton application for the Laravel
 * framework»*. Per quanto ne sapeva il manifest, questo repository era ancora lo starter kit.
 *
 * **Perché una guardia e non una correzione e basta.** Perché la correzione dura dieci secondi e
 * il difetto è durato più di un anno senza che nessuno lo vedesse. Nessuno legge `composer.json`
 * per verificarne la licenza: si legge il `LICENSE`, che era giusto. Un difetto che nessuno guarda
 * non si corregge una volta — si mette sotto un test.
 *
 * **La fonte di verità è `NOTICE`**, perché è l'unico file che dichiara l'identificativo SPDX per
 * intero (`AGPL-3.0-or-later`) invece della forma abbreviata che si usa parlando agli umani. Il
 * `LICENSE` è il testo integrale della licenza e non dichiara la variante *or-later*; i README e
 * le schermate scrivono «AGPL-3.0» perché è così che lo si dice a un lettore. Sono registri
 * diversi dello stesso fatto, e il test lo tiene presente: pretende l'identificativo esatto dai
 * manifest, e la sola menzione di AGPL — con l'assenza di MIT — da tutto il resto.
 */

/**
 * ⚠️ **Le asserzioni qui sotto sono di PHPUnit e non di Pest, e non è una preferenza di stile.**
 * `expect($testo)->toContain($ago, $messaggio)` non prende un messaggio: `toContain()` accetta
 * **più aghi**, e il messaggio diventa una seconda stringa da cercare. Nella forma affermativa il
 * test fallisce sempre; nella forma **negata** passa sempre — perché il messaggio, ovviamente, nel
 * file non c'è. Scrivendo questo test in stile Pest tre asserzioni su MIT passavano per quel
 * motivo, cioè non guardavano niente. Dove serve un messaggio si usa `assertX($..., $messaggio)`.
 */

/** L'identificativo SPDX dichiarato in `NOTICE`, che è la fonte di verità del progetto. */
function spdxDichiarato(): string
{
    $notice = @file_get_contents(base_path('NOTICE'));

    if ($notice === false) {
        throw new RuntimeException('Il file NOTICE non esiste: è la fonte di verità della licenza.');
    }

    if (! preg_match('/\b(AGPL-3\.0(?:-or-later|-only)?)\b/', $notice, $m)) {
        throw new RuntimeException(
            "NOTICE non dichiara nessun identificativo SPDX riconoscibile. \n".
            "È il file da cui tutti gli altri copiano: se tace qui, non c'è una fonte di verità."
        );
    }

    return $m[1];
}

/** Il contenuto di un file alla radice del progetto. */
function testoDi(string $percorso): string
{
    $assoluto = base_path($percorso);

    if (! is_file($assoluto)) {
        throw new RuntimeException("Il file $percorso non esiste.");
    }

    return file_get_contents($assoluto);
}

it('il NOTICE dichiara un identificativo SPDX riconoscibile', function () {
    $this->assertSame('AGPL-3.0-or-later', spdxDichiarato());
});

it('composer.json dichiara esattamente la licenza del NOTICE', function () {
    $composer = json_decode(testoDi('composer.json'), true);

    $this->assertIsArray($composer, 'composer.json non è JSON valido.');

    $this->assertSame(
        spdxDichiarato(),
        $composer['license'] ?? null,
        'composer.json dichiara una licenza diversa da quella del NOTICE. È il manifest che leggono '.
        'gli scanner di conformità e il comando `composer licenses`: una divergenza qui è '.
        "un'ambiguità legale, non un refuso."
    );
});

it('package.json dichiara esattamente la licenza del NOTICE', function () {
    $package = json_decode(testoDi('package.json'), true);

    $this->assertIsArray($package, 'package.json non è JSON valido.');

    $this->assertSame(
        spdxDichiarato(),
        $package['license'] ?? null,
        "package.json non dichiara la licenza, o ne dichiara un'altra. Il pacchetto è `private` e ".
        'non finisce su npm, ma il campo è il posto dove un lettore la cerca, e tacere è come '.
        "dichiarare «non lo so»."
    );
});

it('il file LICENSE è davvero il testo della AGPL versione 3', function () {
    $licenza = testoDi('LICENSE');

    $this->assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', $licenza);
    $this->assertStringContainsString('Version 3', $licenza);

    $this->assertStringNotContainsString(
        'Permission is hereby granted, free of charge',
        $licenza,
        'Il file LICENSE contiene il testo della licenza MIT. '.
        'È il documento che GitHub legge per dichiarare la licenza del repository.'
    );
});

it('i tre README dichiarano AGPL e nessuno nomina MIT', function (string $file) {
    $testo = testoDi($file);

    $this->assertStringContainsString(
        'AGPL',
        $testo,
        "$file non nomina AGPL da nessuna parte: è il primo posto dove un visitatore guarda."
    );

    $this->assertDoesNotMatchRegularExpression(
        '/\bMIT\b/',
        $testo,
        "$file nomina MIT. Se è una citazione legittima — la licenza di una dipendenza — va ".
        "riscritta in modo che non si legga come la licenza di questo progetto."
    );
})->with(['README.md', 'README.en.md', 'README.pt-br.md']);

it('le schermate pubbliche dichiarano la stessa licenza', function () {
    $schermate = array_merge(
        glob(base_path('resources/js/pages/Welcome.vue')),
        glob(base_path('resources/js/pages/auth/*.vue'))
    );

    $dichiarano = array_filter(
        $schermate,
        fn (string $f) => str_contains(file_get_contents($f), 'AGPL-3.0')
    );

    // Al 26/08/2026 sono sei: Welcome, Login, Register, ForgotPassword, ResetPassword, VerifyEmail.
    // Il numero può crescere; quello che non deve succedere è che una di queste perda il piè di
    // pagina o scriva MIT.
    $this->assertGreaterThanOrEqual(
        6,
        count($dichiarano),
        'Meno di sei schermate pubbliche dichiarano AGPL-3.0. Se una è stata riscritta perdendo il '.
        'piè di pagina, va rimesso: sono le pagine che si vedono prima di entrare.'
    );

    foreach ($schermate as $file) {
        $this->assertStringNotContainsString(
            '(MIT)',
            file_get_contents($file),
            basename($file).' dichiara MIT a video.'
        );
    }
});

it('nessun file di primo livello dichiara MIT', function () {
    $sospetti = [];

    foreach (['app', 'config', 'database', 'routes', 'resources/js', 'resources/views'] as $cartella) {
        $iteratore = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($cartella), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iteratore as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $testo = file_get_contents($file->getPathname());

            if (preg_match('/SPDX-License-Identifier:\s*MIT|"license"\s*:\s*"MIT"|\bMIT License\b/', $testo)) {
                $sospetti[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    $this->assertSame(
        [],
        $sospetti,
        'Questi file dichiarano MIT: '.implode(', ', $sospetti).". Un'intestazione MIT dentro un ".
        'progetto AGPL è un\'ambiguità che si può far valere.'
    );
});

it('composer.json non si presenta più come lo scheletro di Laravel', function () {
    $composer = json_decode(testoDi('composer.json'), true);

    $this->assertStringStartsNotWith(
        'laravel/',
        $composer['name'] ?? '',
        'Il pacchetto si dichiara ancora dentro lo spazio-nome `laravel/`. Non è solo estetica: è '.
        "lo spazio-nome di qualcun altro, e su Packagist non sarebbe rivendicabile."
    );

    $this->assertStringContainsString('kondomanager', $composer['name'] ?? '');

    $this->assertStringNotContainsString(
        'skeleton',
        strtolower($composer['description'] ?? ''),
        'La descrizione è ancora quella dello scheletro di Laravel.'
    );

    $this->assertNotNull(
        $composer['homepage'] ?? null,
        'Manca `homepage`: è il campo che dice dove vive il progetto a chi legge solo il manifest.'
    );
});
