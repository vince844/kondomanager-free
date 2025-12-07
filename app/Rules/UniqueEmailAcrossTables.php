<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniqueEmailAcrossTables implements ValidationRule
{
    public function __construct(
        protected ?int $ignoreId = null,
        protected ?string $ignoreTable = null,
    ) {}

    /**
     * Controlla che l’email (o PEC) non esista già in nessuna delle tabelle/colonne configurate.
     *
     * @param  string  $attribute Nome del campo (es. "email")
     * @param  mixed   $value Valore da validare
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // tabella => colonne da controllare
        $tablesToCheck = [
            'anagrafiche' => ['email', 'pec'],
            'fornitori'   => ['email', 'pec'],
            'condomini'   => ['email'],
        ];

        foreach ($tablesToCheck as $table => $columns) {
            foreach ($columns as $column) {
                $query = DB::table($table)->where($column, $value);

                // Se stiamo aggiornando un record, escludiamo quell’id dalla ricerca
                if ($this->ignoreId !== null && $this->ignoreTable === $table) {
                    $query->where('id', '!=', $this->ignoreId);
                }

                if ($query->exists()) {
                    $fail(__('validation.custom.email.unique_email_across_tables'));
                    return; 
                }
            }
        }
    }
}