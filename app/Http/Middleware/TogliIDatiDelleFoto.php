<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Middleware;

use App\Support\Allegati\FotoSenzaDati;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decisione 535: la posizione si toglie dalle foto in ogni caricamento del programma, non solo negli allegati di segnalazioni e comunicazioni (531).
 *
 * Un punto solo, all'ingresso: ogni file caricato nella richiesta che è una foto JPG, PNG o WebP (il tipo letto dal contenuto, non dal nome) passa da
 * `FotoSenzaDati` e il file temporaneo del caricamento si riscrive pulito, prima che la richiesta arrivi alla validazione e al controller. Così ogni
 * parte che salva un file caricato (l'archivio dei documenti, i documenti delle fatture, le prove delle lettere, i verbali, gli accordi, la carta dello
 * studio, il logo e la firma delle stampe, gli allegati) salva già i byte puliti, e il peso che legge con `getSize()` è quello del file salvato, senza
 * che ognuna debba ricordarsene. Resta il verso della foto; i pixel non cambiano (l'immagine non si decodifica: la memoria è quella del file).
 *
 * Una foto che non si sa pulire (non ha la forma del suo tipo: un JPG tagliato) si rifiuta con la frase, sotto il campo che l'ha portata (la chiave del
 * campo, come la scrive la validazione: `file`, `allegati.1`), e la richiesta non va avanti. I file che non sono foto (PDF, XML, `.eml`, `.p7m`, i fogli
 * di calcolo, i backup) restano come sono: le immagini dentro di loro non sono di questa regola.
 */
final class TogliIDatiDelleFoto
{
    /**
     * Le richieste che riscrivono già la foto senza i dati, con i loro controlli e le loro frasi: il logo e la firma delle stampe (`ImmagineDiStampa::
     * riscritta()`, ridisegnati in PNG) e la carta dello studio (`CartaIntestata::senzaDatiDellaFoto()` al salvataggio). Qui passerebbero prima dei loro
     * rifiuti (la misura, la forma A4, il file troppo grande) e li cambierebbero.
     */
    private const GIA_RISCRITTE = ['impostazioni.stampe.store', 'impostazioni.stampe.carte.store', 'impostazioni.stampe.carte.update'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::GIA_RISCRITTE)) {
            return $next($request);
        }

        $errori = [];
        foreach (Arr::dot($request->allFiles()) as $campo => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $tipo = (string) $file->getMimeType();
            if (! FotoSenzaDati::eUnaFoto($tipo)) {
                continue;
            }
            $percorso = $file->getPathname();
            $byte = @file_get_contents($percorso);
            if ($byte === false) {
                continue;
            }
            $pulita = FotoSenzaDati::pulisci($byte, $tipo);
            if ($pulita === null) {
                $errori[(string) $campo] = FotoSenzaDati::frase($file->getClientOriginalName());

                continue;
            }
            if ($pulita !== $byte) {
                file_put_contents($percorso, $pulita);
                // Il peso del file lo leggono la validazione (`max`) e chi salva (`getSize()`): senza, PHP darebbe quello di prima.
                clearstatcache(true, $percorso);
            }
        }

        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }

        return $next($request);
    }
}
