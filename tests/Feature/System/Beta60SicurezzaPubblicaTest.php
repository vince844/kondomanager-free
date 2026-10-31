<?php

/**
 * La politica di sicurezza del repository pubblico, e i punti da cui si raggiunge.
 *
 * Chi trova una vulnerabilità guarda in tre posti: la scheda Security del repository (che mostra il
 * `SECURITY.md` in radice), il README e il modulo delle issue. Tutti devono dire la stessa cosa: si
 * segnala in privato, non in una issue (le issue sono pubbliche), e ognuno lo dice una volta sola. Il
 * file non promette un numero di versione per le correzioni: dice che escono «in una nuova versione».
 *
 * `SECURITY.md` ha anche il paragrafo per chi vuole scrivere la correzione (avviso privato con fork
 * temporaneo e Contributor License Agreement) e non indica indirizzi email: il canale è quello di GitHub.
 * Gli impegni che il file prende (la prima risposta entro 7 giorni, i 90 giorni, la versione che riceve le
 * correzioni) e i titoli delle due lingue sono fissati qui: alla prossima versione stabile la tabella si
 * cambia nel file e in questo test insieme.
 *
 * I tre README e la procedura di aggiornamento manuale: il titolo non fissa una coppia di versioni, la
 * frase sull'installatore dice che cosa fa il file caricato, al presente, e i nomi del passo e del
 * pulsante sono quelli che l'installatore mostra. I tre README dichiarano la stessa versione nel badge,
 * raggiungono la guida per chi contribuisce con il nome vero del file e nominano il Contributor License
 * Agreement dove chiedono di contribuire.
 */

/** Il testo di un file del repository, per percorso assoluto. */
function testoPubblico(string $percorso): string
{
    return file_get_contents(dirname(__DIR__, 3).'/'.$percorso);
}

/** Quante righe del testo nominano il file `SECURITY.md`: un rimando è una riga, due sono un doppione. */
function righePubblicheCheRimandanoASicurezza(string $testo): int
{
    return preg_match_all('/^.*SECURITY\.md.*$/m', $testo);
}

it('il SECURITY.md in radice dice come segnalare in privato, nelle due lingue, senza promettere un numero di versione', function () {
    $testo = testoPubblico('SECURITY.md');

    expect($testo)->toContain('## Italiano')
        ->and($testo)->toContain('## English')
        ->and($testo)->toContain('Report a vulnerability')
        ->and($testo)->toContain('Le correzioni di sicurezza escono in una nuova versione')
        ->and($testo)->toContain('Security fixes are released in a new version')
        ->and($testo)->not->toMatch('/(per esempio|for example) 1\.\d+\.\d+/')
        ->and($testo)->not->toMatch('/versione correttiva|patch (version|release)/');
});

it('il SECURITY.md prende gli stessi impegni nelle due lingue: la prima risposta entro 7 giorni, i 90 giorni, la versione che riceve le correzioni, e un titolo per ciò che non si fa', function () {
    $testo = testoPubblico('SECURITY.md');
    $inizioInglese = strpos($testo, '## English');
    $italiano = substr($testo, 0, $inizioInglese);
    $inglese = substr($testo, $inizioInglese);

    expect($italiano)->toContain('**entro 7 giorni**')
        ->and($inglese)->toContain('**within 7 days**')
        ->and($italiano)->toContain('**90 giorni**')
        ->and($inglese)->toContain('**90 days**')
        ->and($italiano)->toContain('| 1.11.x (ultima stabile) | ✅ |')
        ->and($inglese)->toContain('| 1.11.x (latest stable) | ✅ |')
        ->and($italiano)->toContain("| 1.10.x e precedenti | ❌ aggiornare all'ultima stabile |")
        ->and($inglese)->toContain('| 1.10.x and earlier | ❌ upgrade to the latest stable |')
        // I due elenchi sono ciò che chi segnala non deve fare: il titolo lo dice nelle due lingue.
        ->and($italiano)->toMatch('/^### Che cosa non fare$/m')
        ->and($inglese)->toMatch('/^### What not to do$/m')
        ->and($testo)->not->toContain('Out of scope');
});

it('il SECURITY.md spiega come contribuire con la correzione, nella sua lingua, con il Contributor License Agreement e senza indirizzi email', function () {
    $testo = testoPubblico('SECURITY.md');
    $inizioInglese = strpos($testo, '## English');

    expect($testo)->toMatch('/^### Contribuire con la correzione$/m')
        ->and($testo)->toMatch('/^### Contributing a fix$/m')
        ->and(strpos($testo, '### Contribuire con la correzione'))->toBeLessThan($inizioInglese)
        ->and(strpos($testo, '### Contributing a fix'))->toBeGreaterThan($inizioInglese)
        ->and($testo)->toContain('avviso di sicurezza privato con un fork privato temporaneo')
        ->and($testo)->toContain('private security advisory with a temporary private fork')
        ->and(substr_count($testo, 'https://cla-assistant.io/vince844/kondomanager-free'))->toBe(2)
        ->and($testo)->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z.]{2,}/');
});

it('i tre README mandano le vulnerabilità al canale privato con un solo rimando a SECURITY.md', function (string $readme, string $voce, string $frase) {
    $testo = testoPubblico($readme);

    expect($testo)->toContain("**{$voce}:** {$frase}")
        ->and($testo)->toMatch('#\]\((https://github\.com/vince844/kondomanager-free/blob/main/)?SECURITY\.md\)#')
        ->and(righePubblicheCheRimandanoASicurezza($testo))->toBe(1);
})->with([
    'italiano' => ['README.md', 'Vulnerabilità di sicurezza', 'Non aprire una issue pubblica'],
    'inglese' => ['README.en.md', 'Security vulnerabilities', 'Do not open a public issue'],
    'portoghese' => ['README.pt-br.md', 'Vulnerabilidades de segurança', 'Não abra uma issue pública'],
]);

it('CONTRIBUTING manda le vulnerabilità al canale privato una volta sola e chiede il Contributor License Agreement', function () {
    $testo = testoPubblico('CONTRIBUTING.md');

    expect(righePubblicheCheRimandanoASicurezza($testo))->toBe(1)
        ->and($testo)->toContain('do not report security vulnerabilities in public issues')
        ->and($testo)->toMatch('/^## Contributor License Agreement$/m')
        ->and($testo)->toContain('https://cla-assistant.io/vince844/kondomanager-free');
});

it('il modulo delle segnalazioni manda le vulnerabilità al canale privato', function () {
    expect(testoPubblico('.github/ISSUE_TEMPLATE/bug_report.md'))->toContain('kondomanager-free/security/policy');
});

it('i tre README raggiungono la guida per chi contribuisce con il nome vero del file e nominano il Contributor License Agreement', function (string $readme) {
    $testo = testoPubblico($readme);

    expect($testo)->toContain('kondomanager-free/blob/main/CONTRIBUTING.md)')
        ->and($testo)->not->toContain('kondomanager-free/blob/main/CONTRIBUTING)')
        ->and($testo)->toContain('https://cla-assistant.io/vince844/kondomanager-free');
})->with(['README.md', 'README.en.md', 'README.pt-br.md']);

it('i tre README dichiarano la stessa versione nel badge', function () {
    $versioni = array_map(function (string $readme) {
        preg_match('#img\.shields\.io/badge/Version-([0-9][0-9A-Za-z.]*)-blue#', testoPubblico($readme), $m);

        return $m[1] ?? null;
    }, ['README.md', 'README.en.md', 'README.pt-br.md']);

    expect($versioni[0])->not->toBeNull()
        ->and($versioni)->each->toBe($versioni[0]);
});

it('la procedura di aggiornamento manuale dei README non fissa le versioni nel titolo e non promette il futuro', function (string $readme) {
    $testo = testoPubblico($readme);

    expect($testo)->not->toMatch('/^### 3\..*1\.9\.1/m')
        ->and($testo)->not->toMatch('/auto-eliminer|will self-delete|eliminar-se-á/')
        ->and($testo)->toContain('410 Gone');
})->with(['README.md', 'README.en.md', 'README.pt-br.md']);

it('il README portoghese dice in quale lingua l\'installatore mostra ciascun nome del pulsante: «Update now» in inglese, «Aggiorna ora» in italiano', function () {
    expect(testoPubblico('README.pt-br.md'))->toContain('6. Clique em **"Update now"** (em inglês; em italiano, **"Aggiorna ora"**) e siga os passos guiados.');
});

it('la procedura di aggiornamento manuale dei README dice di estrarre il file scaricato, nomina il pulsante dell\'installatore e dice che fare se l\'aggiornamento si interrompe', function (string $readme, string $estrai, string $pulsante, string $interruzione) {
    $testo = testoPubblico($readme);

    expect($testo)->toContain($estrai)
        ->and($testo)->toContain($pulsante)
        ->and($testo)->not->toMatch('/Aggiorna adesso|Atualizar agora/')
        ->and($testo)->toContain($interruzione);
})->with([
    'italiano' => ['README.md', "3. Estrai e carica il file `index.php` nella root del tuo server\n", '**"Aggiorna ora"**', "Se l'aggiornamento si interrompe con un errore, il file `index.php` può restare sul server: eliminalo a mano."],
    'inglese' => ['README.en.md', "3. Extract and upload the `index.php` file to the root of your server\n", '**"Update now"**', 'If the update stops with an error, the `index.php` file may stay on the server: delete it by hand.'],
    'portoghese' => ['README.pt-br.md', "3. Extraia e carregue o ficheiro `index.php` na raiz do seu servidor\n", '**"Update now"**', 'Se a atualização for interrompida por um erro, o ficheiro `index.php` pode ficar no servidor: elimine-o manualmente.'],
]);
