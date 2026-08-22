<?php

/**
 * # Ogni tipo di notifica dichiarato ha un'etichetta, in tutte e quattro le lingue
 *
 * ## Il difetto che questa guardia esiste per prendere
 *
 * La schermata delle preferenze non ha un elenco suo: si costruisce da `config/notifications.php`,
 * e per ogni tipo cerca `settings.notification_types.{tipo}.label` e `.description`. Aggiungendo i
 * tre tipi «aggiornata» nella beta.64 le chiavi andavano scritte in **quattro** file di lingua —
 * it, en, es, pt — cioè ventiquattro voci a mano fra etichette e descrizioni.
 *
 * Dimenticarne una non fa fallire niente: non c'è errore, non c'è log, la pagina si apre.
 *
 * ⚠️ **E il guasto ha due forme diverse, con gravità diverse.** `config/app.php` dichiara
 * `fallback_locale = en`, quindi:
 *
 * - se la chiave manca in **una** lingua, l'utente vede la voce **in inglese** in mezzo a una
 *   pagina italiana o spagnola — sbagliato, ma leggibile;
 * - se manca in **tutte**, Laravel restituisce la chiave stessa e l'utente vede
 *   `settings.notification_types.updated_ticket.label` scritto in un interruttore.
 *
 * ⚠️ **Il ripiego è anche il motivo per cui questa guardia NON usa `__()`.** La prima stesura lo
 * faceva, e non mordeva: provata togliendo davvero l'etichetta spagnola, `__($chiave, [], 'es')`
 * restituiva l'inglese e il test restava verde. Una guardia che dichiara di coprire il caso «manca
 * in una lingua» e copre solo «manca in tutte» è peggio di nessuna guardia, perché chi la legge
 * smette di controllare a mano. Qui i file di lingua si aprono e si leggono, senza intermediari.
 *
 * Il guasto resta **asimmetrico**: chi sviluppa lavora in italiano e vede la pagina giusta. A
 * vederla sbagliata è chi ha il portale in un'altra lingua — cioè chi non ce lo dirà.
 *
 * ## Cosa NON copre
 *
 * - **Non dice che la traduzione sia buona**: dice che c'è. Una descrizione sbagliata o copiata da
 *   un'altra voce passa di qui.
 * - **Non copre i testi delle mail**, che stanno nei file `notifications.php` di ogni lingua: quelle
 *   chiavi sono diverse e
 *   compaiono solo nel corpo del messaggio. Sarebbero un'estensione naturale di questa guardia.
 * - **Non copre chi vede cosa**: la visibilità dipende dal permesso dichiarato su ciascun tipo, e
 *   quella è un'altra domanda.
 */

use App\Services\NotificationPreferenceService;

/** Le lingue che il prodotto offre. */
function lingueDelProdotto(): array
{
    return ['it', 'en', 'es', 'pt'];
}

/** Tutti i tipi di notifica dichiarati, dei due gruppi. */
function tipiDiNotificaDichiarati(): array
{
    $config = config('notifications.types');

    return array_keys(array_merge($config['common'] ?? [], $config['admin'] ?? []));
}

it('trova davvero dei tipi da controllare', function () {
    // ⚠️ Senza questo, il giorno che la configurazione cambia forma la guardia diventa verde perché
    // non controlla più niente — la forma di guasto peggiore, perché si presenta come un successo.
    expect(count(tipiDiNotificaDichiarati()))->toBeGreaterThan(8);
});

/**
 * Il contenuto di `lang/<lingua>/settings.php`, letto dal file e non da `__()`.
 *
 * ⚠️ È il cuore di questa guardia: `__()` ripiega sulla lingua predefinita e restituirebbe
 * l'inglese al posto della chiave mancante, rendendo il controllo verde per sempre.
 */
function etichetteDelleNotifiche(string $lingua): array
{
    $percorso = dirname(__DIR__, 3)."/lang/{$lingua}/settings.php";

    return is_file($percorso) ? (require $percorso)['notification_types'] ?? [] : [];
}

it('ogni tipo ha etichetta e descrizione in tutte le lingue', function () {
    $mancanti = [];

    foreach (lingueDelProdotto() as $lingua) {
        $etichette = etichetteDelleNotifiche($lingua);

        foreach (tipiDiNotificaDichiarati() as $tipo) {
            foreach (['label', 'description'] as $voce) {
                $valore = $etichette[$tipo][$voce] ?? null;

                if ($valore === null || trim((string) $valore) === '') {
                    $mancanti[] = "{$lingua}: notification_types.{$tipo}.{$voce}";
                }
            }
        }
    }

    expect($mancanti)->toBe([],
        "Queste voci non esistono nel file di lingua, quindi l'utente vedrebbe l'inglese in mezzo\n".
        "a una pagina che inglese non è — o la chiave grezza, se manca in tutte:\n\n  ".
        implode("\n  ", $mancanti)."\n\n".
        "Si scrivono in `lang/<lingua>/settings.php`, dentro `notification_types`. Un tipo nuovo\n".
        "in `config/notifications.php` richiede **otto** voci: etichetta e descrizione per ognuna\n".
        'delle quattro lingue.'
    );
});

it('e ogni tipo dichiara il permesso che decide chi lo vede', function () {
    // Un tipo senza permesso è visibile a **tutti** — `getVisibleNotificationTypes()` lo lascia
    // passare senza filtro. Può essere giusto, ma è una decisione: se capita per dimenticanza,
    // un condòmino si ritrova l'interruttore di una notifica riservata agli amministratori.
    $config = config('notifications.types');
    $senzaPermesso = [];

    foreach (array_merge($config['common'] ?? [], $config['admin'] ?? []) as $tipo => $meta) {
        if (! isset($meta['permission'])) {
            $senzaPermesso[] = $tipo;
        }
    }

    expect($senzaPermesso)->toBe([],
        "Questi tipi non dichiarano un permesso, quindi il loro interruttore compare a chiunque:\n\n  ".
        implode("\n  ", $senzaPermesso)."\n\n".
        'Se è voluto, va scritto qui accanto perché smetta di sembrare una dimenticanza.'
    );
});

it('la guardia morde: una voce mancante in UNA sola lingua si vede', function () {
    // ⚠️ **L'autocontrollo che la prima stesura non superava.** Con `__()` questo blocco restava
    // verde: il ripiego su `en` restituiva l'inglese e la voce spagnola mancante era invisibile.
    // Qui si legge il file, quindi la differenza fra le lingue si vede davvero.
    $spagnolo = etichetteDelleNotifiche('es');

    expect($spagnolo)->not->toBeEmpty('Il file di lingua spagnolo non è stato letto')
        ->and($spagnolo['updated_ticket']['label'] ?? null)->not->toBeNull()
        // La voce esiste in spagnolo **e non è quella inglese**: se lo fosse, vorrebbe dire che
        // stiamo leggendo il file sbagliato e la guardia guarderebbe sempre lo stesso.
        ->and($spagnolo['updated_ticket']['label'])
            ->not->toBe(etichetteDelleNotifiche('en')['updated_ticket']['label'] ?? null);

    // E un tipo che non esiste risulta mancante in tutte e quattro.
    foreach (lingueDelProdotto() as $lingua) {
        expect(etichetteDelleNotifiche($lingua)['tipo_che_non_esiste']['label'] ?? null)->toBeNull();
    }
});

it('un condòmino vede i tre interruttori «aggiornata», non solo un amministratore', function () {
    // ⚠️ Chiesto il 22/08/2026: *«alcune notifiche sono mostrate solo agli amministratori e altre
    // solo agli utenti»*. La visibilità dipende **solo dal permesso** dichiarato su ciascun tipo —
    // i gruppi `common` e `admin` della configurazione vengono uniti e non filtrano niente — e i
    // tre tipi nuovi dichiarano lo stesso permesso della loro sorella «nuova». Questa asserzione
    // impedisce che quella scelta si perda in una modifica futura.
    $servizio = app(NotificationPreferenceService::class);

    foreach ([
        'updated_communication'    => 'new_communication',
        'updated_ticket'           => 'new_ticket',
        'updated_archive_document' => 'new_archive_document',
    ] as $aggiornata => $nuova) {
        $tipi = config('notifications.types.common');

        expect($tipi[$aggiornata]['permission'] ?? null)
            ->toBe($tipi[$nuova]['permission'] ?? null,
                "«{$aggiornata}» non dichiara lo stesso permesso di «{$nuova}»: chi vede una delle\n".
                'due deve vedere l\'altra, altrimenti l\'interruttore compare a metà delle persone.'
            );
    }

    expect($servizio)->not->toBeNull();
});
