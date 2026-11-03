<?php

/**
 * Coda 397 — in portoghese un utente è un «utilizador» e un ruolo, nelle frasi del ruolo dei nuovi iscritti, è un «perfil».
 *
 * La lingua ha una parola sola per «utenti», «utilizador», e una per il ruolo che i nuovi iscritti ricevono, «perfil» (`RuoloDeiNuoviIscrittiTest` la
 * pretende nelle frasi di `ruoli.php`): la finestra di «Elimina», il titolo, la descrizione e il menu di scelta di quel ruolo nelle impostazioni generali, il
 * rifiuto di chi non è amministratore e la colonna del ruolo nella lista degli utenti si leggono tutti con le stesse parole. «Função» resta dove vuol dire
 * «funzionalità» (la scheda «Funções» delle impostazioni, lo strumento del cron), che qui non si guarda.
 *
 * Cosa resta scoperto: le frasi che chiamano il ruolo «papel» (`users.php`, `validation.php`, `gestionale.php`, `impostazioni.php`, `pt.json`), una
 * terza parola della lingua che questo test non vieta; le altre lingue.
 */

/** @return array<string, string> tutte le frasi di un file di lingua, con la chiave a punti */
function vpFrasi(string $file): array
{
    return \Illuminate\Support\Arr::dot(require base_path("lang/pt/{$file}.php"));
}

it('Coda 397 — nessuna frase portoghese chiama «usuário» un utente: la parola della lingua è «utilizador»', function () {
    $trovate = [];
    foreach (glob(base_path('lang/pt/*.php')) as $percorso) {
        $file = basename($percorso, '.php');
        foreach (vpFrasi($file) as $chiave => $frase) {
            if (is_string($frase) && preg_match('/usu[aá]rio/iu', $frase)) {
                $trovate[] = "{$file}.{$chiave}: {$frase}";
            }
        }
    }

    foreach (json_decode(file_get_contents(base_path('lang/pt.json')), true) as $chiave => $frase) {
        if (is_string($frase) && preg_match('/usu[aá]rio/iu', $frase)) {
            $trovate[] = "pt.json {$chiave}: {$frase}";
        }
    }

    expect($trovate)->toBe([], implode("\n", $trovate));
});

it('Coda 397 — le frasi portoghesi del ruolo dei nuovi iscritti lo chiamano «perfil», non «função»', function (string $file, string $chiave) {
    $frase = mb_strtolower(vpFrasi($file)[$chiave]);

    expect($frase)->toContain('perfil')->not->toContain('função');
})->with([
    'la finestra di «Elimina»: il rifiuto' => ['ruoli', 'cannot_delete_registration_role'],
    'la finestra di «Elimina»: il titolo' => ['ruoli', 'registration_role_dialog_title'],
    'impostazioni generali: il titolo' => ['impostazioni', 'dialogs.default_role_title'],
    'impostazioni generali: la descrizione' => ['impostazioni', 'dialogs.default_role_description'],
    'impostazioni generali: il menu di scelta' => ['impostazioni', 'placeholder.select_role'],
    'impostazioni generali: il rifiuto di chi non è amministratore' => ['impostazioni', 'ruolo_registrazione.solo_amministratore'],
    'la regola di validazione: il rifiuto di chi non è amministratore' => ['validation', 'custom.default_user_role.only_admin'],
    'la lista degli utenti: la colonna del ruolo' => ['users', 'table.role'],
]);
