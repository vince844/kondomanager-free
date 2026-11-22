<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Condominio\UpdateCondominioRequest;
use App\Models\Condominio;

/** Il condominio (`BuildingsEdit.vue`, `CondominioController::update`): i sedici dati della pagina, dai valori grezzi. */
final class ModuloCondominio extends ModuloDellaScheda
{
    public const CAMPI = ['nome', 'indirizzo', 'email', 'codice_fiscale', 'comune_catasto', 'codice_catasto', 'sezione_catasto', 'foglio_catasto',
        'particella_catasto', 'note', 'comune', 'provincia', 'cap', 'anno_costruzione', 'anno_acquisizione', 'numero_piani'];

    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Condominio::query()->find($id);
    }

    public function rotta(): string
    {
        return 'condomini.update';
    }

    public function parametri(object $cosa): array
    {
        return ['condominio' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateCondominioRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Condominio $cosa */
        $modulo = [];
        foreach (self::CAMPI as $campo) {
            $modulo[$campo] = $cosa->getRawOriginal($campo);
        }

        return $modulo;
    }
}
