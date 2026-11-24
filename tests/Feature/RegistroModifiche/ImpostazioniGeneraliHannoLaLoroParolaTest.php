<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Ogni impostazione generale ha la sua parola nel registro delle modifiche.
 *
 * Senza la parola, il registro scrive il nome della proprietà e il formato «testo»: accendere «Registra quando una comunicazione viene aperta nel
 * portale» diventava «Comunicazioni registra aperture: (vuoto) → 1». La guardia confronta le proprietà di `GeneralSettings` con
 * `ImpostazioniNelRegistro::ETICHETTE['general']`, così la prossima impostazione senza parola fa rossa la suite.
 */

use App\Services\RegistroModifiche\ImpostazioniNelRegistro;
use App\Settings\GeneralSettings;

it('ogni impostazione generale ha la sua parola, tranne la versione del programma', function () {
    $proprieta = array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(GeneralSettings::class))->getProperties(ReflectionProperty::IS_PUBLIC));
    $senzaParola = array_values(array_filter($proprieta, fn (string $nome) => ! isset(ImpostazioniNelRegistro::ETICHETTE['general'][$nome])
        && ! in_array("general.{$nome}", ImpostazioniNelRegistro::ESCLUSE, true)));

    expect($senzaParola)->toBe([]);
});

it('«Registra quando una comunicazione viene aperta nel portale» si legge «sì» e «no», non «1» e «(vuoto)»', function () {
    expect(ImpostazioniNelRegistro::ETICHETTE['general']['comunicazioni_registra_aperture'] ?? null)
        ->toBe(['Registra quando una comunicazione viene aperta nel portale', 'si_no']);
});

it('«I condòmini possono avvisare lo studio dalla pagina d\'errore» si legge «sì» e «no» (Coda 788)', function () {
    expect(ImpostazioniNelRegistro::ETICHETTE['general']['errori_avviso_dalla_pagina'] ?? null)
        ->toBe(['I condòmini possono avvisare lo studio dalla pagina d\'errore', 'si_no']);
});
