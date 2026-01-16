<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Validates that an email or PEC is unique across multiple tables.
 * Checks specified columns in multiple database tables to ensure email uniqueness.
 * @since v1.8.0
 */
class UniqueEmailAcrossTables implements ValidationRule
{
    public function __construct(
        protected ?int $ignoreId = null,
        protected ?string $ignoreTable = null,
    ) {}

    /**
     * Check if email exists in any configured table/column.
     *
     * @param string $attribute Field name being validated
     * @param mixed $value Email/PEC value to check
     * @param Closure $fail Callback to fail validation
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tablesToCheck = [
            'anagrafiche' => ['email', 'pec'],
            'fornitori'   => ['email', 'pec'],
            'condomini'   => ['email'],
        ];

        foreach ($tablesToCheck as $table => $columns) {
            foreach ($columns as $column) {
                $query = DB::table($table)->where($column, $value);

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