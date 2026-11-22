<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\Condominio;
use Illuminate\Support\Facades\DB;

/** Ciò che le schede delle sedute, dei punti e delle delibere leggono dalla loro assemblea: il condominio, per la riga del registro del condominio. */
final class AssembleeNelRegistroComune
{
    /** @return array<int, string> */
    public static function condominiDellAssemblea(mixed $assembleaId): array
    {
        if ($assembleaId === null) {
            return [];
        }
        $condominioId = DB::table('assemblee')->where('id', $assembleaId)->value('condominio_id');
        if ($condominioId === null) {
            return [];
        }

        return [(int) $condominioId => (string) (Condominio::query()->whereKey($condominioId)->value('nome') ?? '')];
    }
}
