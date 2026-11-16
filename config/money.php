<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

return [
    /*
     |--------------------------------------------------------------------------
     | Laravel money
     |--------------------------------------------------------------------------
     */
    'locale' => config('app.locale', 'it_IT'),
    'defaultCurrency' => config('app.currency', 'EUR'),
    'defaultFormatter' => null,
    'defaultSerializer' => null,
    'isoCurrenciesPath' => is_dir(__DIR__.'/../vendor')
        ? __DIR__.'/../vendor/moneyphp/money/resources/currency.php'
        : __DIR__.'/../../../moneyphp/money/resources/currency.php',
    'currencies' => [
        'iso' => 'all',
        'bitcoin' => 'all',
        'custom' => [
            // 'MY1' => 2,
            // 'MY2' => 3
        ],
    ],

];
