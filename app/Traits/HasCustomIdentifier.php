<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Traits;

use Illuminate\Support\Str;

trait HasCustomIdentifier
{
    /**
     * Generate a custom, human-readable identifier.
     *
     * @param  int    $id
     * @param  string $prefix
     * @return string
     */
    public function generateCustomIdentifier(int $id, string $prefix): string
    {
        $region = strtoupper(Str::random(3)); // Random 3-letter region code

        return sprintf('%s-%s%06d', $prefix, $region, $id);
    }

    /**
     * Boot the model to automatically assign a unique identifier.
     */
    public static function bootHasCustomIdentifier()
    {
        static::created(function ($model) {
            if (empty($model->codice_identificativo)) {
                if (!isset($model->customIdentifierPrefix)) {
                    throw new \Exception('Custom prefix is not defined on the model.');
                }
                // Use the custom prefix specified on the model
                $prefix = $model->customIdentifierPrefix;
                $model->codice_identificativo = $model->generateCustomIdentifier($model->id, $prefix);
                $model->saveQuietly(); // Avoids recursion
            }
        });
    }
}
