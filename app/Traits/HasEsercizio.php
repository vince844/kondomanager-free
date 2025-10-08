<?php
// app/Traits/HasEsercizio.php

namespace App\Traits;

use App\Models\Condominio;

trait HasEsercizio
{
    /**
     * Recupera l'esercizio corrente per il condominio
     */
    protected function getEsercizioCorrente(Condominio $condominio)
    {
        return $condominio->esercizi()
            ->where('stato', 'aperto')
            ->first();
    }
}