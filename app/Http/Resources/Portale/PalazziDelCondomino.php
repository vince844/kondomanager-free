<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Portale;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * I palazzi di chi guarda il portale (Coda 668). Una comunicazione, un documento o un evento dello studio può andare a più palazzi: al condòmino si
 * mostrano solo i suoi, mai il nome di un altro palazzo dello studio. I suoi palazzi sono quelli della sua anagrafica, gli stessi con cui il portale
 * sceglie che cosa mostrargli (`HandlesUserCondominioData`), letti una volta per richiesta e non riga per riga.
 */
final class PalazziDelCondomino
{
    /**
     * Gli id dei palazzi di chi fa la richiesta; nessuno se non ha un'anagrafica.
     *
     * @return list<int>
     */
    public static function ids(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $chiave = 'portale.palazzi_del_condomino.' . $user->getKey();
        if (! $request->attributes->has($chiave)) {
            $ids = $user->anagrafica?->condomini->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
            $request->attributes->set($chiave, $ids);
        }

        return $request->attributes->get($chiave);
    }

    /**
     * I palazzi di un avviso (la relazione già caricata), ristretti a quelli di chi guarda. A chi l'ha scritto restano tutti: sono i palazzi a cui l'ha
     * mandato, e la sua pagina di modifica li rimanda così come sono (`UpdateUserComunicazioneRequest` ammette i palazzi in cui l'avviso è già, anche
     * quelli da cui l'autore è uscito).
     *
     * @param  Collection<int, \App\Models\Condominio>  $condomini
     * @return Collection<int, \App\Models\Condominio>
     */
    public static function fra(Request $request, Collection $condomini, mixed $autoreId = null): Collection
    {
        if ($autoreId !== null && $request->user() !== null && (int) $autoreId === (int) $request->user()->getKey()) {
            return $condomini->values();
        }

        $suoi = self::ids($request);

        return $condomini->filter(fn ($condominio) => in_array((int) $condominio->id, $suoi, true))->values();
    }
}
