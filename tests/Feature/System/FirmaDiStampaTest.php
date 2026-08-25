<?php

/**
 * La firma di stampa: due difetti sulla stessa schermata, trovati aprendo la beta.60.
 *
 * ## 1. A video compariva la chiave di lingua, non il testo
 *
 * `impostazioniStampe.vue` leggeva `impostazioni.label.print_signature_help`, che esisteva **solo in
 * spagnolo** — e lì per giunta col testo scritto in inglese. In italiano, inglese e portoghese la
 * chiave si chiama `print_admin_signature_help`, ed è quella che la schermata legge da oggi.
 *
 * ⚠️ **Il ripiego scritto lì accanto non scatta**, ed è la parte che rende il difetto invisibile a
 * chi legge il codice: `trans()` su una chiave mancante restituisce **la chiave stessa**, che in
 * JavaScript è una stringa non vuota, cioè `truthy`. Quindi `trans(...) || 'Usa un'immagine…'`
 * prende sempre il primo ramo, e l'amministratore italiano legge
 * `impostazioni.label.print_signature_help` stampato in pagina.
 *
 * ## 2. Una firma vettoriale era sempre respinta
 *
 * La regola era `'nullable|image|mimes:jpeg,png,jpg,svg|max:2048'`, e le due regole sono in **AND**.
 * Su Laravel 13 `validateImage()` accetta `jpg, jpeg, png, gif, bmp, webp` e aggiunge `svg` **solo**
 * se le si passa `allow_svg`. Quindi `mimes` dichiarava l'SVG ammesso e `image` lo buttava fuori,
 * con un messaggio che non spiegava niente.
 *
 * **Deciso il 19/08/2026: si toglie `svg` da `mimes`, non si aggiunge `allow_svg`.** Il
 * comportamento reale non cambia per nessuno — l'SVG è sempre stato respinto e a database non
 * esiste nessuna firma caricata — mentre abilitarlo sarebbe una **capacità nuova** (mPDF renderebbe
 * un XML caricato dall'utente dentro ogni stampa) decisa di sfuggita, chiudendo un difetto di forma.
 * Se un giorno la si vuole, è una voce sua.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la resa della firma nel PDF, né il ritaglio a 180×80 punti del template.
 */

use App\Support\LimiteCaricamento;

it('il testo d\'aiuto della firma esiste in tutte le lingue che il prodotto offre', function (string $lingua) {
    $testi = require lang_path($lingua.'/impostazioni.php');

    $aiuto = $testi['label']['print_admin_signature_help'] ?? null;

    expect($aiuto)->not->toBeNull("manca `print_admin_signature_help` in lang/{$lingua}/impostazioni.php: "
        .'la schermata legge quella chiave e senza di lei stampa a video la chiave grezza, '
        .'perché `trans()` su una chiave mancante restituisce la chiave e in JavaScript è truthy')
        ->and(trim($aiuto))->not->toBe('');
})->with(['it', 'en', 'es', 'pt']);

it('la schermata legge una chiave che esiste, non una che esiste solo in una lingua', function () {
    $vue = file_get_contents(resource_path('js/pages/impostazioni/impostazioniStampe.vue'));

    preg_match_all("/trans\('impostazioni\.([a-z_.]+)'\)/", $vue, $trovate);

    $italiano = require lang_path('it/impostazioni.php');

    foreach ($trovate[1] as $chiave) {
        expect(data_get($italiano, $chiave))->not->toBeNull(
            "la schermata delle stampe legge `impostazioni.{$chiave}`, che in italiano non esiste"
        );
    }
});

it('la regola della firma non dichiara ammesso un formato che scarta subito dopo', function () {
    $regole = (new \App\Http\Requests\Settings\UpdatePrintSettingsRequest())->rules();
    $firma = is_array($regole['firma_stampe']) ? implode('|', $regole['firma_stampe']) : $regole['firma_stampe'];

    // `image` e `mimes` sono in AND: se `mimes` nomina un formato che `image` non ammette, quel
    // formato è dichiarato e irraggiungibile.
    $ammessiDaImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

    preg_match('/mimes:([a-z0-9,]+)/', $firma, $m);
    $dichiarati = explode(',', $m[1] ?? '');

    $bugiardi = array_values(array_diff($dichiarati, $ammessiDaImage));

    expect($bugiardi)->toBe([],
        'la regola dichiara ammessi formati che `image` respinge: '
        .'o si toglie il formato da `mimes`, o si passa il parametro ad `image` — ma dichiararlo e '
        .'respingerlo è la forma peggiore, perché l\'utente non capisce perché il file non passa');
});

it('anche la firma legge il limite del server, col tetto suo di 2 MB', function () {
    // ⚠️ Il valore da solo non prova niente: su un server generoso `regolaMax(2.0)` vale 2048, cioè
    // esattamente il numero che c'era scritto a mano. Quello che prova la regressione è che il
    // **sorgente** chiami la classe, e che il valore corrisponda al tetto giusto.
    $sorgente = file_get_contents(app_path('Http/Requests/Settings/UpdatePrintSettingsRequest.php'));

    expect($sorgente)->toContain('LimiteCaricamento::regolaMax(2.0)');

    $regole = (new \App\Http\Requests\Settings\UpdatePrintSettingsRequest())->rules();
    $firma = is_array($regole['firma_stampe']) ? implode('|', $regole['firma_stampe']) : $regole['firma_stampe'];

    expect($firma)->toContain('max:'.LimiteCaricamento::regolaMax(2.0));
});
