<?php

/**
 * beta.52 — Il messaggio della pagina millesimi, nelle quattro lingue.
 *
 * Segnalazione forum del 15/08/2026: un amministratore con 67 unità immobiliari si ferma a 40 e
 * chiede «perché questo limite così stringente?». Non c'era nessun limite — quel condominio aveva
 * 40 unità inserite, e la pagina dei millesimi le associa soltanto, non le crea. Il difetto era il
 * testo: «hai già raggiunto il numero massimo di righe consentite» si legge come un tetto imposto
 * dal programma, e l'amministratore ne ha concluso che il gestionale non reggesse il suo stabile.
 *
 * ## Perché il presidio sta qui e non nel test del componente
 *
 * `QuoteList.vue` chiama `trans('gestionale.tabelle_quote.max_rows_reached')`, e in ambiente di
 * test `trans()` non ha i messaggi caricati: un'asserzione là verificherebbe l'involucro, non il
 * contenuto. Le quattro traduzioni si leggono da PHP, ed è lì che vivono i tre difetti che
 * contano — un testo che mente sulla causa, una parola che in questo gestionale significa
 * un'altra cosa, e una lingua rimasta in inglese.
 *
 * ⚠️ **Una nota di metodo, perché è costata due correzioni.** La prima stesura di questo file
 * sosteneva che i file di lingua PHP non fossero esposti al frontend, e che `trans()` in un
 * componente avrebbe stampato la chiave grezza all'amministratore. **È falso.** Il plugin
 * `laravel-vue-i18n/vite` genera un bundle a parte — `public/build/assets/php_<locale>-*.js` —
 * che contiene tutte le stringhe dei file PHP. L'errore stava nella verifica: avevo controllato
 * `lang/it.json`, che è il **sorgente** dei testi in stile JSON (40 chiavi, installer e spatie),
 * scambiandolo per il file generato. La prova che avrebbe smontato la tesi era a un clic di
 * distanza: la pagina `settings/two-factor` usa `trans('settings.two_factor.title')` ed è sempre
 * stata in italiano. Da quell'errore era nata anche una segnalazione di difetto inesistente su
 * `useTwoFactorAuth.ts`, poi ritirata.
 */

it('le quattro lingue hanno il segnaposto del conteggio, e nessuna è rimasta in inglese', function () {
    // ⚠️ Lo spagnolo conteneva la frase **in inglese**, come diverse voci di quella sezione.
    // Una traduzione mancante non si vede finché non la legge qualcuno che non capisce l'altra
    // lingua, ed è il motivo per cui questa asserzione sta in un test e non in una nota.
    foreach (['it', 'en', 'pt', 'es'] as $lingua) {
        $testo = __('gestionale.tabelle_quote.max_rows_reached', ['count' => 67], $lingua);

        expect($testo)
            ->toContain('67')
            ->not->toBe('gestionale.tabelle_quote.max_rows_reached');
    }

    expect(__('gestionale.tabelle_quote.max_rows_reached', ['count' => 67], 'es'))
        ->not->toContain('You have already');
});

it('il messaggio dice la causa, e non un tetto che non esiste', function () {
    $testo = mb_strtolower(__('gestionale.tabelle_quote.max_rows_reached', ['count' => 40], 'it'));

    // Le due formule che hanno prodotto la segnalazione.
    expect($testo)
        ->not->toContain('numero massimo')
        ->not->toContain('righe consentite')
        // E deve dire dove si rimedia, non solo che non si può proseguire.
        ->toContain('unità immobiliari');
});

it('il messaggio non usa la parola «anagrafica», che qui significa un\'altra cosa', function () {
    // ⚠️ In KondoManager `Anagrafica` è il **soggetto** — proprietari, inquilini, usufruttuari — ed
    // «Anagrafiche» è l'etichetta della scheda dei soggetti collegati all'unità. Usata per
    // intendere «l'elenco delle unità» produceva la frase «unità presenti in anagrafica», che a
    // chi conosce il gestionale si legge come «unità presenti nell'elenco delle persone».
    // Segnalato da Vincenzo guardando la pagina a video.
    foreach (['it', 'en', 'pt', 'es'] as $lingua) {
        expect(mb_strtolower(__('gestionale.tabelle_quote.max_rows_reached', ['count' => 40], $lingua)))
            ->not->toContain('anagrafica')
            ->not->toContain('anagráfica')
            ->not->toContain('register');
    }
});
