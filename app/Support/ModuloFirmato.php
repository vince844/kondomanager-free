<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * L'indirizzo a cui un modulo aperto da un link firmato manda il suo salvataggio: lo stesso URL,
 * firma compresa, perché il `POST` chiede la stessa prova del `GET` (1.11.0-beta.45, Code 222 e
 * 223).
 *
 * È un percorso relativo, senza schema né host e con una sola barra in testa: il modulo resta
 * sull'origine da cui è stato aperto anche dietro un proxy che non dichiara lo schema giusto. La query è quella grezza della richiesta,
 * perché la firma si verifica proprio su di lei. E il percorso passa da `getBaseUrl()`, che comprende
 * il prefisso dichiarato da un proxy fidato (`X-Forwarded-Prefix`): `getRequestUri()` lo perdeva, e
 * dietro un proxy che toglie un percorso il salvataggio finiva fuori dall'applicazione.
 */
class ModuloFirmato
{
    public static function azione(Request $request): string
    {
        $query = (string) $request->server->get('QUERY_STRING');

        // Una barra sola in testa, sempre: un percorso «//host/…» sarebbe un indirizzo di un altro
        // sito. Oggi lo impedisce già la firma, che copre lo stesso `getBaseUrl()`; questa è la
        // seconda serratura.
        return '/'.ltrim($request->getBaseUrl().$request->getPathInfo(), '/').($query !== '' ? '?'.$query : '');
    }
}
