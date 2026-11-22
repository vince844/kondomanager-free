<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Schede;

use App\Models\CartaStampa;
use Illuminate\Database\Eloquent\Model;

/** Una carta dello studio (`CarteDelloStudio.vue`): dello studio, nella «Storia» delle impostazioni delle stampe (P22). */
final class CartaStampaNelRegistro extends SchedaNelRegistro
{
    public const MODELLO = CartaStampa::class;

    public const AREA = 'impostazioni';

    public const TIPO = 'carta_stampa';

    public const PAROLA = 'Carta';

    public const ETICHETTE = [
        'nome' => 'Nome della carta',
        'aspetto' => 'Aspetto',
        'mostra_logo' => 'Con il logo dello studio, accanto al nome',
        'colore' => 'Un colore per i titoli e le righe',
        'margine_alto' => 'Margine alto (mm)',
        'margine_basso' => 'Margine basso (mm)',
        'margine_sinistro' => 'Margine sinistro (mm)',
        'margine_destro' => 'Margine destro (mm)',
        'predefinita' => 'La carta predefinita',
    ];

    public const FORMATI = [
        'aspetto' => ['scelta', ['campi' => 'A campi', 'carta' => 'Carta intestata']],
        'mostra_logo' => 'si_no',
        'margine_alto' => 'numero',
        'margine_basso' => 'numero',
        'margine_sinistro' => 'numero',
        'margine_destro' => 'numero',
        'predefinita' => 'si_no',
    ];

    /** Il file caricato, il suo tipo e le sue pagine. */
    public const DEL_PROGRAMMA = ['carta_path', 'carta_tipo', 'carta_pagine'];

    public function nome(Model $modello): string
    {
        return 'Carta «' . $modello->getAttribute('nome') . '»';
    }

    public function condomini(Model $modello): array
    {
        return [];
    }

    public function scheda(Model $modello): array
    {
        return ['impostazioni_stampe', null];
    }
}
