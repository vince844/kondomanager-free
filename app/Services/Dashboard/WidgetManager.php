<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Dashboard;

use App\Contracts\DashboardWidget;
use Illuminate\Support\Collection;

class WidgetManager
{
    /** @var Collection<string, DashboardWidget> */
    private Collection $widgets;

    public function __construct()
    {
        $this->widgets = collect();
    }

    public function register(DashboardWidget $widget): self
    {
        $this->widgets->put($widget->key(), $widget);
        return $this;
    }

    /**
     * @param array<DashboardWidget> $widgets
     */
    public function registerMany(array $widgets): self
    {
        foreach ($widgets as $widget) {
            $this->register($widget);
        }
        return $this;
    }

    /**
     * @return array<string, array>
     */
    public function getPayloads(int $condominioId): array
    {
        $payloads = [];

        foreach ($this->widgets as $key => $widget) {
            if ($widget->isVisible($condominioId)) {
                $payloads[$key] = $widget->payload($condominioId);
            }
        }

        return $payloads;
    }
}
