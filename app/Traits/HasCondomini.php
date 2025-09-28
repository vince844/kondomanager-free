<?php

namespace App\Traits;

use App\Models\Condominio;

trait HasCondomini
{
    /**
     * Restituisce tutti i condomini ordinati per nome
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCondomini()
    {
        return Condominio::orderBy('nome')->get(['id', 'nome']);
    }
}
