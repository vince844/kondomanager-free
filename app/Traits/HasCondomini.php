<?php

namespace App\Traits;

use App\Models\Condominio;

/**
 * Trait HasCondomini
 * 
 * Provides a reusable method for retrieving condomini collections.
 * This trait can be used across multiple controllers to maintain
 * consistent condominio data retrieval.
 *
 * @package App\Traits
 */
trait HasCondomini
{
    /**
     * Retrieve all condomini ordered by name
     *
     * Fetches a lightweight collection of condomini with only essential fields
     * (id and name) for use in dropdowns, navigation, or listings. The results
     * are ordered alphabetically by name for consistent presentation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Condominio> 
     *         Eloquent collection of Condominio models containing only:
     *         - id: The primary key identifier
     *         - nome: The name of the condominio
     *
     * @throws \Illuminate\Database\QueryException If database connection fails
     *
     * @example
     * // Basic usage
     * $condomini = $this->getCondomini();
     * 
     * // Usage in Inertia response
     * return Inertia::render('Page', [
     *     'condomini' => $this->getCondomini()
     * ]);
     *
     * @since 1.7
     * @uses \App\Models\Condominio::orderBy()
     * @uses \Illuminate\Database\Eloquent\Builder::get()
     */
    public function getCondomini()
    {
        return Condominio::orderBy('nome')->get(['id', 'nome']);
    }
}
