<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

/**
 * Il valore è l'id di un condominio a cui l'utente autenticato appartiene (tramite la sua
 * anagrafica), non un qualunque condominio esistente.
 *
 * Estratta durante il giro di sicurezza della 1.11.0-beta.39 (Coda 184): la stessa domanda, con la
 * stessa forma, era ripetuta nelle richieste lato condòmino di segnalazioni, eventi, documenti e
 * comunicazioni, e tre di loro validavano il condominio scelto solo con `exists:condomini,id` — un
 * condòmino poteva indicarne uno che non è il suo. Un futuro modulo che chiede la stessa cosa la
 * trova qui, invece di riscriverla con una variante leggermente diversa.
 *
 * Accetta **solo un id singolo** (intero, o stringa di sole cifre). Un array non passa mai: con un
 * array `whereKey()` diventa un `whereIn`, e bastava un solo condominio dell'utente fra quelli
 * indicati per dire sì; nel frattempo `attach()`/`sync()` leggono un elemento-array come «chiave =
 * id da collegare, valore = colonne del pivot», e `[altrui => ['id' => mio]]` agganciava il
 * condominio altrui dopo aver fatto controllare il proprio. Le richieste che la usano mettono
 * comunque `integer` davanti; questa guardia la rende sicura anche da sola.
 *
 * @since v1.11.0-beta.39
 */
class CondominioDellUtente implements ValidationRule, ValidatorAwareRule
{
    protected Validator $validator;

    /**
     * @param  array<int, int>  $giaCollegati  Solo per le **modifiche**: i condomìni a cui il record
     *     è già agganciato. Il record può restare dove è anche se l'autore non ne è più membro
     *     (altrimenti chi è stato staccato da un palazzo non salverebbe più nemmeno una correzione
     *     del titolo, stesso caso già risolto per le segnalazioni); non può entrare in un palazzo
     *     nuovo che non sia dell'utente.
     */
    public function __construct(private array $giaCollegati = [])
    {
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (static::eUnId($value) && in_array((int) $value, $this->giaCollegati, true)) {
            return;
        }

        if (static::appartiene(Auth::user(), $value)) {
            return;
        }

        // Stesso messaggio del vincolo `exists` incorporato, così il condòmino vede lo stesso testo
        // che vedrebbe se il condominio non esistesse affatto: la distinzione fra «non esiste» e
        // «non è il tuo» non è un'informazione da dargli.
        $fail('validation.exists')->translate([
            'attribute' => $this->validator->getDisplayableAttribute($attribute),
        ]);
    }

    /**
     * La stessa domanda, per chi ha già uno User in mano e non passa da una regola di validazione
     * (per esempio una Policy). `null` in ingresso, un valore che non è un id singolo, o un utente
     * senza anagrafica, danno sempre no.
     */
    public static function appartiene(?User $user, mixed $condominioId): bool
    {
        if (! static::eUnId($condominioId)) {
            return false;
        }

        return $user?->anagrafica?->condomini()->whereKey((int) $condominioId)->exists() ?? false;
    }

    private static function eUnId(mixed $valore): bool
    {
        return is_int($valore) || (is_string($valore) && ctype_digit($valore));
    }
}
