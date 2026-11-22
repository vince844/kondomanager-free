<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Traits;

use App\Services\RegistroModifiche\CatalogoDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Services\RegistroModifiche\RigheDelTratto;
use App\Services\RegistroModifiche\Schede\SchedaNelRegistro;

/**
 * Il tratto delle schede del registro delle modifiche (Coda 679, §4 del progetto): negli eventi `created`, `updated`, `deleted` (e `restored` dove c'è
 * `SoftDeletes`) scrive una riga con i campi cambiati, con le parole della pagina ({@see CatalogoDelRegistro}, una dichiarazione per modello).
 *
 * **La stessa transazione**: `save()` e `delete()` passano da {@see RegistroModifiche::nellaTransazione()} (un metodo del tratto vince su quello
 * ereditato): una transazione loro se non ce n'è una aperta, quella di chi chiama se c'è già, senza un savepoint per salvataggio. Se la riga non va, la
 * modifica non va; se la modifica non va, la riga non c'è.
 *
 * Non scrivono righe: `saveQuietly()`, `withoutEvents()` e le scritture in blocco su query (quelle le guarda la guardia della suite).
 */
trait RegistraLeModifiche
{
    public static function bootRegistraLeModifiche(): void
    {
        static::created(fn ($modello) => RigheDelTratto::creata($modello));
        static::updated(fn ($modello) => RigheDelTratto::modificata($modello));
        static::deleting(fn ($modello) => RigheDelTratto::inEliminazione($modello));
        static::deleted(fn ($modello) => RigheDelTratto::eliminata($modello));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($modello) => RigheDelTratto::ripristinata($modello));
        }
    }

    public function save(array $options = []): bool
    {
        return RegistroModifiche::nellaTransazione(fn () => parent::save($options));
    }

    public function delete(): ?bool
    {
        return RegistroModifiche::nellaTransazione(fn () => parent::delete());
    }

    /** La dichiarazione di questa scheda nel registro. */
    public static function descrizioneDelRegistro(): ?SchedaNelRegistro
    {
        return CatalogoDelRegistro::descrizionePer(static::class);
    }
}
