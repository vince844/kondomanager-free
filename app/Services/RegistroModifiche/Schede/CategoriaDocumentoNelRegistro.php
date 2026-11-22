<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\CategoriaDocumento;
use Illuminate\Database\Eloquent\Model;

/** Una categoria dei documenti: dello studio; compare nella «Storia» dei documenti (P22). */
final class CategoriaDocumentoNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = CategoriaDocumento::class;

    public const AREA = 'documenti';

    public const TIPO = 'categoria_documento';

    public const PAROLA = 'Categoria dei documenti';

    public const ETICHETTE = [
        'name' => 'Nome',
        'description' => 'Descrizione',
    ];

    /** La chiave fissa che il codice cerca («fatture»). */
    public const DEL_PROGRAMMA = ['slug'];

    public function nome(Model $modello): string
    {
        return 'Categoria dei documenti «' . $modello->getAttribute('name') . '»';
    }

    public function condomini(Model $modello): array
    {
        return [];
    }

    public function scheda(Model $modello): array
    {
        return ['documenti', null];
    }
}
