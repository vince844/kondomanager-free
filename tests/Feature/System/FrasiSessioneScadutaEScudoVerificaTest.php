<?php

/**
 * Coda 404 e Coda 258 — due frasi nuove, nelle quattro lingue del prodotto.
 *
 * - La finestra della password (`auth.confirm_password_dialog.session_ended`): con la sessione finita (401 o 419) riprovare
 *   non può riuscire, e la frase dice di ricaricare la pagina invece di dire «Riprova» come `error_generic`.
 * - Il titolo dello scudo della verifica nell'elenco utenti (`users.table.verified_label`, `unverified_label`): dove lo scudo
 *   non si clicca dice lo stato e basta; dove si clicca restano `verified_tooltip` e `unverified_tooltip`, che invitano al clic.
 *
 * Che la pagina scelga la frase giusta lo provano i vitest (`FinestraConfermaPassword.test.ts`, `columns.test.ts`), che non
 * caricano le traduzioni: qui si prova che le chiavi esistono e dicono ciò che devono.
 */

const LINGUE_DELLE_FRASI_NUOVE = ['it', 'en', 'pt', 'es'];

it('Coda 404 — la frase della sessione finita esiste nelle quattro lingue e dice di ricaricare la pagina', function (string $lingua, string $ricarica) {
    $finestra = require lang_path("{$lingua}/auth.php");
    $frase = $finestra['confirm_password_dialog']['session_ended'] ?? null;

    expect($frase)->toBeString()->not->toBe('');
    expect(mb_strtolower($frase))->toContain($ricarica);
    // Non è la frase generica che invita a riprovare.
    expect($frase)->not->toBe($finestra['confirm_password_dialog']['error_generic']);
    expect(mb_strtolower($frase))->not->toContain('riprova')->not->toContain('try again');
})->with([
    'italiano' => ['it', 'ricarica la pagina'],
    'inglese' => ['en', 'reload the page'],
    'portoghese' => ['pt', 'recarregue a página'],
    'spagnolo' => ['es', 'recarga la página'],
]);

it('Coda 258 — il titolo dello scudo non cliccabile esiste nelle quattro lingue e non invita al clic', function (string $lingua) {
    $tabella = (require lang_path("{$lingua}/users.php"))['table'];

    foreach (['verified_label', 'unverified_label'] as $chiave) {
        expect($tabella[$chiave] ?? null)->toBeString()->not->toBe('');
        expect(mb_strtolower($tabella[$chiave]))->not->toMatch('/cli(c|ck|que)/u');
    }

    expect($tabella['verified_label'])->not->toBe($tabella['unverified_label']);
})->with(LINGUE_DELLE_FRASI_NUOVE);

it('Coda 258 — le frasi dello scudo cliccabile continuano a invitare al clic', function (string $lingua) {
    $tabella = (require lang_path("{$lingua}/users.php"))['table'];

    expect(mb_strtolower($tabella['verified_tooltip']))->toMatch('/cli(c|ck|que)/u');
    expect(mb_strtolower($tabella['unverified_tooltip']))->toMatch('/cli(c|ck|que)/u');
})->with(LINGUE_DELLE_FRASI_NUOVE);
