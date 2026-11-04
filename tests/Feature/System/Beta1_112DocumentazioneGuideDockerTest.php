<?php

/**
 * Le otto guide Docker e Synology del prodotto (`docs/docker_local_dev.*.md`,
 * `docs/synology_nas_install.*.md`) e i due file di composizione di sviluppo.
 *
 * ## Perché un test per delle guide
 *
 * Sono i soli documenti che il repository pubblico traccia, e chi le segue ci mette dei dati veri:
 * la guida Synology che faceva costruire il codice dai sorgenti con la stack di sviluppo lasciava
 * in rete un MySQL con `root`/`root`, un Supervisor con `admin`/`password`, il debug acceso e un
 * amministratore con una password scritta nella guida; e consigliava `TRUSTED_PROXIES=*` e
 * `chmod -R 777`. Una frase del genere non si cancella una volta sola: torna con la prima
 * correzione fatta a memoria. Qui stanno i controlli che la tengono fuori, **su tutte e quattro le
 * lingue**: le guide sono state riscritte una lingua alla volta, e una lingua dimenticata è il modo
 * in cui un difetto resta in pubblico.
 *
 * Girano su un clone pubblico: le guide e i `docker-compose*.yml` sono tracciati.
 *
 * ## Cosa questo file NON copre
 *
 * Non dice che le guide siano vere: i passi dell'interfaccia di DSM non si verificano dal codice, e
 * la guida Synology lo dichiara in testa. Dice solo che certe cose false e pericolose non ci sono.
 */

use Symfony\Component\Yaml\Yaml;

const LINGUE_DELLE_GUIDE = ['it', 'en', 'es', 'pt'];

/** Il testo di una guida del prodotto, in una lingua. */
function guidaDelProdotto(string $guida, string $lingua): string
{
    $percorso = base_path("docs/{$guida}.{$lingua}.md");

    expect(file_exists($percorso))->toBeTrue("manca {$percorso}");

    return file_get_contents($percorso);
}

/** Il primo blocco ```yaml di una guida, letto come YAML: è il file di composizione che la guida dà da copiare. */
function composeDellaGuida(string $testo): array
{
    preg_match('/```yaml\n(.*?)```/s', $testo, $m);

    expect($m)->not->toBeEmpty('la guida non ha un blocco yaml');

    return Yaml::parse($m[1]);
}

it('Coda 181 — nessuna guida clona o scarica il ramo v1.9.1', function (string $guida, string $lingua) {
    $testo = guidaDelProdotto($guida, $lingua);

    expect($testo)->not->toContain('v1.9.1')
        ->and($testo)->not->toContain('refs/heads/');
})->with(['docker_local_dev', 'synology_nas_install'])->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le guide di sviluppo clonano il ramo predefinito, senza numeri di versione da tenere in pari', function (string $lingua) {
    $testo = guidaDelProdotto('docker_local_dev', $lingua);

    expect($testo)->toContain('git clone https://github.com/vince844/kondomanager-free.git')
        ->and($testo)->not->toMatch('/git clone\s+-b/');
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le guide Synology non pubblicano porte, password di prova né permessi larghi', function (string $lingua) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);

    // L'amministratore di sviluppo, le porte della stack di sviluppo (MySQL, Supervisor, applicazione),
    // il consiglio che apriva il blocco dei tentativi di accesso, i permessi sulla cartella condivisa.
    foreach (['admin@km.com', '3307', '9001', '8889', 'TRUSTED_PROXIES=*', 'MYSQL_ROOT_PASSWORD: root'] as $vietato) {
        expect($testo)->not->toContain($vietato);
    }

    expect($testo)->not->toMatch('/chmod\s+(-R|\+x|[0-7]{3})/')
        ->and($testo)->not->toMatch('/`(root|password)`\s*\/\s*`(root|password)`/i');
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le guide Synology fanno usare l\'immagine di produzione, con volumi che hanno un nome e il database fuori dalla rete', function (string $lingua) {
    $compose = composeDellaGuida(guidaDelProdotto('synology_nas_install', $lingua));

    expect($compose['services']['app']['image'])->toBe('ghcr.io/vince844/kondomanager-core:${KONDOMANAGER_VERSIONE}')
        ->and($compose['services']['app'])->not->toHaveKey('build')
        ->and($compose['services']['db'])->not->toHaveKey('ports');

    // Nessuna cartella del NAS collegata: ogni volume è un nome dichiarato in `volumes:`, mai un percorso.
    foreach ($compose['services'] as $servizio) {
        foreach ($servizio['volumes'] ?? [] as $volume) {
            $origine = explode(':', $volume)[0];

            expect($compose['volumes'])->toHaveKey($origine)
                ->and($origine)->not->toStartWith('/')
                ->and($origine)->not->toStartWith('.');
        }
    }
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le guide Synology dicono in testa che non sono provate su un NAS, e hanno la sezione per togliere la prova', function (string $lingua, string $sezione) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);
    $testa = explode("\n## ", $testo)[0];

    // Una riga di avviso prima della prima sezione, che nomina il NAS; e la sezione che dice come levare tutto.
    expect($testa)->toMatch('/^> ⚠️ .*NAS/mu')
        ->and($testo)->toContain("\n## {$sezione}\n");
})->with([
    'it' => ['it', 'Per togliere la prova'],
    'en' => ['en', 'Removing the trial'],
    'es' => ['es', 'Para quitar la prueba'],
    'pt' => ['pt', 'Para remover o teste'],
]);

it('Coda 181 — le guide di sviluppo dicono in testa che la stack non è per usare KondoManager', function (string $lingua) {
    $testa = explode("\n## ", guidaDelProdotto('docker_local_dev', $lingua))[0];

    // Un solo avviso, prima della prima sezione, che dice le tre cose: le porte sul solo computer,
    // dove si va per usarlo (l'immagine di produzione) e dove per un NAS (la guida nella stessa lingua).
    expect(preg_match('/^> ⚠️ .*$/mu', $testa, $avviso))->toBe(1)
        ->and($avviso[0])->toContain('127.0.0.1')
        ->and($avviso[0])->toContain('installation-docker-produzione.html')
        ->and($avviso[0])->toContain("synology_nas_install.{$lingua}.md");
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le quattro lingue di ogni guida hanno la stessa struttura', function (string $guida) {
    $struttura = [];

    foreach (LINGUE_DELLE_GUIDE as $lingua) {
        $testo = guidaDelProdotto($guida, $lingua);

        $struttura[$lingua] = [
            'titoli' => preg_match_all('/^#{2,3} /m', $testo),
            'blocchi di codice' => preg_match_all('/^\s*```/m', $testo),
            // I link a pagine vere: gli indirizzi di esempio (un dominio, l'IP del NAS) cambiano con la lingua.
            'link' => collect(preg_match_all('#https?://[A-Za-z0-9./_-]+#', $testo, $m) ? $m[0] : [])
                ->filter(fn ($url) => preg_match('#(kondomanager\.com|ghcr\.io|github\.com)#', $url))
                ->unique()->sort()->values()->all(),
        ];
    }

    expect($struttura['en'])->toBe($struttura['it'])
        ->and($struttura['es'])->toBe($struttura['it'])
        ->and($struttura['pt'])->toBe($struttura['it']);
})->with(['docker_local_dev', 'synology_nas_install']);

it('Coda 181 — le porte delle stack di sviluppo si aprono solo sul computer che le avvia', function (string $file) {
    $compose = Yaml::parseFile(base_path($file));

    $porte = collect($compose['services'])
        ->flatMap(fn ($servizio) => $servizio['ports'] ?? [])
        ->values();

    // Senza un indirizzo davanti Docker pubblica su tutte le interfacce: MySQL root/root e Supervisor
    // admin/password risponderebbero a chiunque sia sulla stessa rete.
    expect($porte)->not->toBeEmpty()
        ->and($porte->reject(fn ($porta) => str_starts_with((string) $porta, '127.0.0.1:'))->all())->toBe([]);
})->with(['docker-compose.yml', 'docker-compose-franken.yml']);

it('Coda 181 — le guide Synology fanno controllare, prima del primo passo, che il nome non sia quello del progetto della guida precedente', function (string $lingua) {
    $primaDelPasso1 = explode("\n---\n", guidaDelProdotto('synology_nas_install', $lingua))[0];

    // La guida precedente creava il progetto `kondomanager` nella cartella `docker/kondomanager-free`.
    // Docker riconosce un progetto dal nome: un secondo progetto `kondomanager` è lo stesso progetto,
    // e `up -d` ricrea i contenitori `app` e `db` di quello vecchio. Il controllo va fatto prima di
    // creare qualcosa, con il nome di ripiego.
    expect($primaDelPasso1)->toContain('docker/kondomanager-free')
        ->and($primaDelPasso1)->toContain('`kondomanager-prova`');
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — ogni comando di Compose della guida Synology porta il percorso del file, compreso l\'unico down -v', function (string $lingua) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);

    // `down -v` cancella i volumi del progetto della cartella in cui lo si lancia: dalla cartella di
    // un altro progetto cancellerebbe i volumi di quello. Con `-f` e il percorso, il progetto è questo
    // da qualunque cartella. `docker compose ls` non agisce su un progetto e non lo porta.
    preg_match_all('/sudo docker compose (?!ls\b)(.*?)(?:`|$)/m', $testo, $comandi);

    expect($comandi[1])->not->toBeEmpty()
        ->and(array_values(array_filter($comandi[1], fn ($c) => str_contains($c, 'down -v'))))->toHaveCount(1)
        ->and(array_values(array_filter($comandi[1], fn ($c) => ! str_starts_with($c, '-f /volume1/docker/kondomanager/docker-compose.yml '))))->toBe([]);
})->with(LINGUE_DELLE_GUIDE);

it('Coda 181 — le guide di sviluppo non dicono che, se la compilazione del frontend si ferma, manca soltanto il frontend', function (string $lingua, string $fraseFalsa) {
    $testo = guidaDelProdotto('docker_local_dev', $lingua);

    // Lo script di avvio è `set -e`: un `npm run build` interrotto esce prima di `exec "$@"`, e il
    // container dell'app si ferma senza php-fpm. La frase dice che cosa succede e come si riparte.
    expect($testo)->not->toContain($fraseFalsa)
        ->and(preg_match('/^> .*1[.,]1 GB.*$/mu', $testo, $riga))->toBe(1)
        ->and($riga[0])->toContain('docker compose up -d');
})->with([
    'it' => ['it', 'manca soltanto il frontend aggiornato'],
    'en' => ['en', 'only the updated frontend is missing'],
    'es' => ['es', 'solo falta el frontend actualizado'],
    'pt' => ['pt', 'só falta o frontend atualizado'],
]);

// ─── Le correzioni dei bassi della raccolta (decisione 189.2): le guide Synology e il portoghese ───────────────

it('Coda 181 — le guide Synology dicono di togliere il blocco `ports:` (la riga e quella sotto), non «le righe `ports:`»: il `- "…"` rimasto sotto non si legge', function (string $lingua, string $frase, string $fraseVecchia) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);

    expect($testo)->toContain($frase)
        ->and($testo)->not->toContain($fraseVecchia);
})->with([
    'it' => ['it', 'togli il blocco `ports:` dei servizi `db` e `worker`, cioè la riga `ports:` e quella sotto', 'togli le righe `ports:`'],
    'en' => ['en', 'remove the `ports:` block of the `db` and `worker` services, that is the `ports:` line and the one below it', 'remove the `ports:` lines'],
    'es' => ['es', 'quita el bloque `ports:` de los servicios `db` y `worker`, es decir, la línea `ports:` y la de debajo', 'quita las líneas `ports:`'],
    'pt' => ['pt', 'retire o bloco `ports:` dos serviços `db` e `worker`, isto é, a linha `ports:` e a de baixo', 'retire as linhas `ports:`'],
]);

it('Coda 181 — le guide Synology dicono che senza il `.env` il primo errore è «invalid reference format», e che gli avvisi «variable is not set» stanno nel registro del progetto', function (string $lingua, string $errore, string $registro) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);

    // Con il `.env` mancante, `docker compose create --dry-run` sul compose della guida stampa nove avvisi «variable is not set» (il suo registro) e si ferma su
    // `unable to get image 'ghcr.io/vince844/kondomanager-core:'` con «invalid reference format»: il numero di versione dell'immagine è vuoto.
    expect($testo)->toContain($errore)
        ->and($testo)->toContain($registro);

    // Il paragrafo sotto la voce dice la causa: il nome della variabile e l'immagine senza versione (il nome della variabile sta anche nel `.env` della
    // guida, quindi si guarda il paragrafo e non tutto il testo).
    preg_match('/'.preg_quote($errore, '/')."\n([^\n]+)\n/u", $testo, $paragrafo);
    expect($paragrafo[1] ?? '')->toContain('`KONDOMANAGER_VERSIONE`')
        ->and($paragrafo[1])->toContain('`ghcr.io/vince844/kondomanager-core:`');
})->with([
    'it' => ['it', '**Compare «invalid reference format» e il progetto non parte.**', 'nel registro del *progetto*, non in quello del contenitore `app`'],
    'en' => ['en', '**«invalid reference format» appears and the project does not start.**', 'in the log of the *project*, not in that of the `app` container'],
    'es' => ['es', '**Aparece «invalid reference format» y el proyecto no arranca.**', 'en el registro del *proyecto*, no en el del contenedor `app`'],
    'pt' => ['pt', '**Aparece «invalid reference format» e o projeto não inicia.**', 'no registro do *projeto*, não no do contêiner `app`'],
]);

it('Coda 181 — il compose della guida Synology senza `.env` ha l\'immagine senza versione, e per questo la voce «invalid reference format» nomina `KONDOMANAGER_VERSIONE`', function () {
    $compose = composeDellaGuida(guidaDelProdotto('synology_nas_install', 'it'));

    // Il fatto su cui la voce poggia, letto dal compose: l'immagine prende il suo numero da `${KONDOMANAGER_VERSIONE}`, e senza valore la variabile è vuota.
    expect($compose['services']['app']['image'])->toBe('ghcr.io/vince844/kondomanager-core:${KONDOMANAGER_VERSIONE}');
});

it('Coda 181 — le guide Synology scrivono `127.0.0.1` come destinazione del proxy di DSM, non `localhost`', function (string $lingua, string $destinazione) {
    $testo = guidaDelProdotto('synology_nas_install', $lingua);

    expect($testo)->toContain($destinazione)
        // `localhost` non è più la destinazione: compare solo nella frase che lo esclude.
        ->and(preg_match_all('/`localhost`/', $testo))->toBe(1);
})->with([
    'it' => ['it', 'nome host `127.0.0.1` (non `localhost`: con `"127.0.0.1:8080:80"` la porta risponde solo su quell\'indirizzo)'],
    'en' => ['en', 'host name `127.0.0.1` (not `localhost`: with `"127.0.0.1:8080:80"` the port answers only on that address)'],
    'es' => ['es', 'nombre de host `127.0.0.1` (no `localhost`: con `"127.0.0.1:8080:80"` el puerto responde solo en esa dirección)'],
    'pt' => ['pt', 'nome do host `127.0.0.1` (não `localhost`: com `"127.0.0.1:8080:80"` a porta responde só nesse endereço)'],
]);

it('Coda 181 — le guide in portoghese sono in portoghese del Brasile: nessuna delle forme europee che erano rimaste', function (string $guida) {
    // Le forme europee trovate nelle due guide (il pronome dopo il verbo, «ficheiro», «ecrã», «contentor», «secção», «registada», «já não», «ramo»),
    // cercate come parole intere. Il primo controllo prova che il modello le trova, con le frasi di prima.
    $europeo = '/(?<![\p{L}])(?:ficheiros?|ecrã|contentor(?:es)?|secção|carregamento|registada|conserva-a|põe-no|fazê-lo|dizê-lo|perder-se|repetem-se|já não|ramo predefinido|ramo de uma|cópias de segurança|a sério)(?![\p{L}])/iu';
    $conPrima = ['o git conserva-a', 'e põe-no no ambiente', 'Pode perder-se se', 'os ficheiros no ecrã', 'um contentor', 'já não aponta', 'Secção acrescentada', 'o ramo predefinido', 'num deploy a sério'];

    expect(array_values(array_filter($conPrima, fn ($frase) => preg_match($europeo, $frase) !== 1)))->toBe([]);

    preg_match_all($europeo, guidaDelProdotto($guida, 'pt'), $trovate);
    expect($trovate[0])->toBe([]);
})->with(['docker_local_dev', 'synology_nas_install']);
