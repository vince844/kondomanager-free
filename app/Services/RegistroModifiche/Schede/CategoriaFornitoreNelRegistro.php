<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\CategoriaFornitore;
use Illuminate\Database\Eloquent\Model;

/** Una categoria dei fornitori: è dello studio (nessun condominio). */
final class CategoriaFornitoreNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = CategoriaFornitore::class;

    public const AREA = 'persone';

    public const TIPO = 'categoria_fornitore';

    public const PAROLA = 'Categoria dei fornitori';

    public const ETICHETTE = [
        'name' => 'Nome',
        'description' => 'Descrizione',
    ];

    public function nome(Model $modello): string
    {
        return 'Categoria dei fornitori «' . $modello->getAttribute('name') . '»';
    }

    public function condomini(Model $modello): array
    {
        return [];
    }
}
