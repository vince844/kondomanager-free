<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Il modulo di modifica di una scheda, come lo manda la sua pagina, per «Ripristina» (§8 e P3 del progetto della tappa 1).
 *
 * Il ripristino ricostruisce **la scheda intera com'è oggi** nella forma del modulo della pagina ({@see modulo()}), sostituisce il solo campo, crea la
 * `FormRequest` di modifica della pagina ({@see richiesta()}) con la rotta della pagina legata alla scheda ({@see rotta()}, {@see parametri()}) e la fa
 * validare per intero; poi scrive **solo quel campo** ({@see scrivi()}), con la scrittura che fa la pagina. Le chiavi che la pagina manda e che hanno
 * effetti oltre al salvataggio (i destinatari da avvisare, l'assegnazione, la programmazione, il file) restano fuori dal modulo: con quelle chiavi assenti
 * la pagina non tocca niente, e il ripristino di un titolo non manda notifiche né cancella file.
 */
abstract class ModuloDellaScheda
{
    /** La cosa da ripristinare, dal tipo e dall'id della riga: il modello, o le impostazioni. */
    abstract public function cosa(?int $id): ?object;

    /** Il nome della rotta di modifica della pagina. */
    abstract public function rotta(): string;

    /** I parametri della rotta, legati ai modelli come li lega Laravel. @return array<string, mixed> */
    abstract public function parametri(object $cosa): array;

    /** La `FormRequest` di modifica della pagina. @return class-string<\Illuminate\Foundation\Http\FormRequest> */
    abstract public function richiesta(): string;

    /** La scheda com'è oggi, nella forma del modulo della pagina (dai valori grezzi, non dalle risorse che li riscrivono). @return array<string, mixed> */
    abstract public function modulo(object $cosa): array;

    /** Il metodo HTTP della rotta di modifica. */
    public function metodo(): string
    {
        return 'PUT';
    }

    /** Chi può modificare la scheda: di serie la policy `update`, come la pagina. */
    public function puoModificare(User $utente, object $cosa): bool
    {
        return Gate::forUser($utente)->allows('update', $cosa);
    }

    /** Il valore grezzo di una colonna, com'è oggi. */
    public function valoreDiOggi(object $cosa, string $campo): mixed
    {
        return $cosa instanceof Model ? $cosa->getRawOriginal($campo) : ($cosa->{$campo} ?? null);
    }

    /** Il valore di prima nella forma del modulo (una data «2019-01-01», non «2019-01-01 00:00:00»). */
    public function valoreDelModulo(string $campo, mixed $grezzo): mixed
    {
        if (is_string($grezzo) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $grezzo)) {
            return substr($grezzo, 0, 10);
        }

        return $grezzo;
    }

    /** Una regola della pagina che non sta nella `FormRequest` (il controller che la applica): la frase, o `null`. */
    public function nonAmmesso(object $cosa, string $campo, mixed $valore): ?string
    {
        return null;
    }

    /** Scrive il solo campo, come la pagina: di serie l'aggiornamento del modello con il valore validato. */
    public function scrivi(object $cosa, string $campo, mixed $valore, User $utente): void
    {
        /** @var Model $cosa */
        $cosa->update([$campo => $valore]);
    }
}
