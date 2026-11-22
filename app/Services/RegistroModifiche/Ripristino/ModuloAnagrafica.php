<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Anagrafica\UpdateAnagraficaRequest;
use App\Models\Anagrafica;

/**
 * La persona (`AnagraficheEdit.vue`, `AnagraficaController::update`): i quattordici dati e i condomini. I condomini vanno nel modulo come sono oggi:
 * senza, il controller non salva (la chiave manca), con `null` staccherebbe la persona da tutti i condomini. La scadenza del documento ormai passata è
 * rifiutata dalla regola della pagina (`after:today` quando cambia), e la finestra lo dice (P3).
 */
final class ModuloAnagrafica extends ModuloDellaScheda
{
    public const CAMPI = ['nome', 'indirizzo', 'email', 'email_secondaria', 'pec', 'codice_fiscale', 'tipologia_documento', 'numero_documento',
        'scadenza_documento', 'luogo_nascita', 'data_nascita', 'telefono', 'cellulare', 'note'];

    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Anagrafica::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.anagrafiche.update';
    }

    public function parametri(object $cosa): array
    {
        return ['anagrafica' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateAnagraficaRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Anagrafica $cosa */
        $modulo = [];
        foreach (self::CAMPI as $campo) {
            $modulo[$campo] = $this->valoreDelModulo($campo, $cosa->getRawOriginal($campo));
        }
        $modulo['condomini'] = $cosa->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all();

        return $modulo;
    }
}
