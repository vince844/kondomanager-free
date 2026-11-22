<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Settings\UpdatePrintSettingsRequest;
use App\Models\User;
use App\Settings\PrintSettings;
use Illuminate\Support\Facades\Gate;

/**
 * Le impostazioni delle stampe (`impostazioniStampe.vue`, `ImpostazioniStampeController::store`): i dati dello studio e la nota legale, gli unici delle
 * impostazioni dello studio che «Ripristina» rimette (`ImpostazioniNelRegistro::RIPRISTINABILI`; quelli protetti da una conferma della password no, P3).
 * Nel modulo i dati com'è oggi, senza la firma e il logo (i file non si toccano); la scrittura è della sola proprietà, poi il salvataggio, come la pagina.
 */
final class ModuloImpostazioniStampe extends ModuloDellaScheda
{
    public const CAMPI = ['nota_legale_stampe', 'studio_nome', 'studio_indirizzo', 'studio_email', 'studio_pec', 'studio_telefono', 'studio_comune',
        'studio_partita_iva', 'studio_codice_fiscale', 'studio_sito'];

    public function cosa(?int $id): ?object
    {
        return app(PrintSettings::class);
    }

    public function rotta(): string
    {
        return 'impostazioni.stampe.store';
    }

    public function metodo(): string
    {
        return 'POST';
    }

    public function parametri(object $cosa): array
    {
        return [];
    }

    public function richiesta(): string
    {
        return UpdatePrintSettingsRequest::class;
    }

    public function puoModificare(User $utente, object $cosa): bool
    {
        return Gate::forUser($utente)->allows('manage', $cosa);
    }

    public function modulo(object $cosa): array
    {
        $modulo = [];
        foreach (self::CAMPI as $campo) {
            $modulo[$campo] = $cosa->{$campo};
        }

        return $modulo;
    }

    public function scrivi(object $cosa, string $campo, mixed $valore, User $utente): void
    {
        /** @var PrintSettings $cosa */
        $cosa->{$campo} = $valore;
        $cosa->save();
    }
}
