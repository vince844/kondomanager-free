<?php

/**
 * # Cosa NON deve entrare nell'immagine Docker (1.11.0-beta.33)
 *
 * Il 21/09/2026, provando l'immagine della .33, `verifica-persistenza` ha contato «backups: 2
 * file» in un container appena nato: `.dockerignore` escludeva `storage/app/private/*` e
 * `storage/app/public/*` **ma non `storage/app/backups/*`**, e l'immagine `1.11.0-beta.32`
 * pubblicata su ghcr.io conteneva quattro backup del database di sviluppo, in chiaro. È stata
 * ritirata. Questo test pretende che le righe che tengono fuori stato, segreti e file di chi
 * costruisce ci siano, com'è scritto: non può eseguire `docker build`, quindi presidia il file.
 *
 * ## Cosa NON copre
 *
 * La semantica di `.dockerignore` (un pattern che sembra giusto e non lo è): quella si prova
 * costruendo il contesto — `docker buildx build -o type=local … FROM scratch; COPY . /ctx` — e
 * contando i file, com'è stato fatto il 21/09/2026 (0 sotto storage/app, 0 .DS_Store, nessun .env).
 */
it('.dockerignore tiene fuori storage/app intero, i segreti, le cache e i file del Finder', function () {
    $righe = array_map('trim', file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $righe = array_values(array_filter($righe, fn ($r) => ! str_starts_with($r, '#')));

    // `.*` tiene fuori dalla radice ogni nascosto: `.env`, `.git`, le cartelle degli strumenti.
    foreach (['storage/app/*', 'storage/logs/*', 'storage/installed.lock', '.*', 'vendor', 'node_modules', 'bootstrap/cache/*', 'tests', 'docs', '**/.DS_Store'] as $attesa) {
        expect($righe)->toContain($attesa);
    }

    // La forma «solo alcune sottocartelle» è quella che ha lasciato passare i backup: non deve tornare.
    expect(array_filter($righe, fn ($r) => preg_match('#^storage/app/(private|public|backups)/\*$#', $r)))->toBe([]);
});

it('il Dockerfile ricrea la struttura di storage/app che .dockerignore esclude', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    foreach (['storage/app/private', 'storage/app/public', 'storage/framework/views', 'storage/logs'] as $cartella) {
        expect($dockerfile)->toContain($cartella);
    }
});
