<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Treasury;

final readonly class AzioneSuggerita
{
    public function __construct(
        public string $tipo, // es. 'sollecito', 'giroconto', 'paga_ora'
        public string $label,
        public string $descrizione,
        public int    $impattoCents,
        public string $route, // Nome rotta o URL
        public array  $routeParams = [],
    ) {}

    public function toArray(): array
    {
        return [
            'tipo'         => $this->tipo,
            'label'        => $this->label,
            'descrizione'  => $this->descrizione,
            'impattoCents' => $this->impattoCents,
            'route'        => $this->route,
            'routeParams'  => $this->routeParams,
        ];
    }
}
