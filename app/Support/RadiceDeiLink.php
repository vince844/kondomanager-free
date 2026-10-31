<?php

namespace App\Support;

/**
 * La radice dei link del reset della password e della verifica dell'indirizzo, due mail che si
 * compongono durante la richiesta che le chiede.
 *
 * La radice è l'indirizzo configurato dell'installazione (`APP_URL`, senza la barra finale, con la sua
 * sottocartella), qualunque sia l'host della richiesta. Quando `APP_URL` è il valore di serie (vuoto,
 * senza `http://` o `https://` davanti, o con host `localhost`, `127.0.0.1` o `[::1]`) la radice è
 * quella della richiesta, con lo schema che le dà `AppServiceProvider`.
 *
 * Il percorso arriva relativo, con la sua query (`route(..., false)`, `URL::temporarySignedRoute(...,
 * absolute: false)`), e la radice configurata gli si mette davanti come stringa: lo schema e l'host del
 * link sono quelli di `APP_URL`, non quelli che il generatore degli URL prende dalla richiesta.
 */
final class RadiceDeiLink
{
    /** Gli host di un `APP_URL` lasciato al valore di serie o puntato sulla macchina stessa. */
    private const HOST_DI_SERIE = ['localhost', '127.0.0.1', '[::1]'];

    /** L'indirizzo intero di un percorso relativo (`/reset-password/…?email=…`). */
    public static function url(string $percorso): string
    {
        $configurata = self::configurata();

        if ($configurata === null) {
            return url($percorso);
        }

        return $configurata.'/'.ltrim($percorso, '/');
    }

    /** `APP_URL` senza la barra finale, o `null` quando è il valore di serie. */
    public static function configurata(): ?string
    {
        $indirizzo = rtrim(trim((string) config('app.url')), '/');
        $schema = strtolower((string) parse_url($indirizzo, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($indirizzo, PHP_URL_HOST));

        if (! in_array($schema, ['http', 'https'], true) || $host === '' || in_array($host, self::HOST_DI_SERIE, true)) {
            return null;
        }

        return $indirizzo;
    }
}
