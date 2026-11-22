<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Gestionale\Immobile\UpdateImmobileRequest;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * L'unità (`ImmobiliEdit.vue`, `ImmobileController::update`): i tredici dati, la palazzina, la scala, la tipologia e la pertinenza come sono oggi (la
 * richiesta rimette sempre l'interno, la superficie, i vani e i codici catastali, che senza la loro chiave si svuoterebbero).
 *
 * Chi può: la pagina dell'unità non ha una policy (basta entrare nel pannello); il ripristino chiede «Modifica condomini», il permesso di modificare le
 * schede del condominio: per un ripristino la regola più stretta.
 */
final class ModuloImmobile extends ModuloDellaScheda
{
    public const CAMPI = ['nome', 'descrizione', 'interno', 'piano', 'superficie', 'numero_vani', 'comune_catasto', 'codice_catasto', 'sezione_catasto',
        'foglio_catasto', 'particella_catasto', 'subalterno_catasto', 'note', 'palazzina_id', 'scala_id', 'tipologia_id', 'pertinenza_di_immobile_id',
        'pertinenza_di_esterna'];

    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Immobile::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.gestionale.immobili.update';
    }

    public function parametri(object $cosa): array
    {
        /** @var Immobile $cosa */
        return ['condominio' => Condominio::query()->findOrFail($cosa->condominio_id), 'immobile' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateImmobileRequest::class;
    }

    public function puoModificare(User $utente, object $cosa): bool
    {
        return Gate::forUser($utente)->allows('update', Condominio::class);
    }

    public function modulo(object $cosa): array
    {
        /** @var Immobile $cosa */
        $modulo = [];
        foreach (self::CAMPI as $campo) {
            $modulo[$campo] = $cosa->getRawOriginal($campo);
        }

        return $modulo;
    }
}
