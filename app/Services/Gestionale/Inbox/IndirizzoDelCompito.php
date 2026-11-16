<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

/**
 * Il collegamento di un compito (`meta.action_url`) senza l'indirizzo del sito con cui è stato scritto. I compiti nuovi salvano il percorso (con la query e
 * l'ancora, senza lo schema, l'host e la cartella del sito); quelli salvati prima hanno l'indirizzo intero del momento («https://kondomanager-free.test/…»,
 * o «http://localhost/…» per un compito nato da un comando), e alla lettura se ne tiene solo il percorso. Il database non si riscrive.
 *
 * I compiti delle quote non assegnate e dell'eccedenza del già versato erano salvati con «/gestionale/…», senza «/admin»: una pagina che non c'è. Alla
 * lettura diventano «/admin/gestionale/…».
 *
 * Il percorso resta sempre del sito: il browser legge «\» come «/» e toglie le tabulazioni e gli a capo, e «/\altro.example» o «/<tab>/altro.example»
 * diventerebbero «//altro.example», un altro sito. Qui si tolgono anche loro, e il percorso comincia con una barra sola.
 */
final class IndirizzoDelCompito
{
    /** Il percorso da salvare, o da cui comporre il collegamento: dall'indirizzo intero restano il percorso, la query e l'ancora. Null se vuoto. */
    public static function percorso(?string $indirizzo): ?string
    {
        // Le tabulazioni e gli a capo il browser li toglie da tutto l'indirizzo: si tolgono prima di leggerlo.
        $indirizzo = str_replace(["\t", "\n", "\r"], '', trim((string) $indirizzo));
        if ($indirizzo === '') {
            return null;
        }
        $parti = parse_url($indirizzo);
        if ($parti === false) {
            return null;
        }
        // Una barra sola davanti, e «\» letta come «/»: «/\altro.example» non porta a un altro sito.
        $percorso = '/' . ltrim(str_replace('\\', '/', (string) ($parti['path'] ?? '')), '/');
        if (isset($parti['host'])) {
            // La cartella del sito di oggi, se il sito non sta nella radice: il percorso salvato non la ripete.
            $cartella = rtrim(self::cartellaDelSito(), '/');
            if ($cartella !== '' && str_starts_with($percorso, $cartella . '/')) {
                $percorso = substr($percorso, strlen($cartella));
            }
        }
        if (str_starts_with($percorso, '/gestionale/')) {
            $percorso = '/admin' . $percorso;
        }

        return $percorso
            . (isset($parti['query']) && $parti['query'] !== '' ? '?' . $parti['query'] : '')
            . (isset($parti['fragment']) && $parti['fragment'] !== '' ? '#' . $parti['fragment'] : '');
    }

    /** Il collegamento da mostrare: il percorso, con la cartella del sito di oggi davanti se il sito non sta nella radice. */
    public static function perIlLink(?string $salvato): ?string
    {
        $percorso = self::percorso($salvato);

        return $percorso === null ? null : rtrim(self::cartellaDelSito(), '/') . $percorso;
    }

    /** La cartella del sito nella richiesta di oggi («» quando il sito sta nella radice, e da un comando). */
    private static function cartellaDelSito(): string
    {
        return (string) request()->getBaseUrl();
    }
}
