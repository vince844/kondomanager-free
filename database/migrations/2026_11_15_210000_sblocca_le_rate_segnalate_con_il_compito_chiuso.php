<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Gestionale\SegnalazioneDelBonifico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Le rate segnalate dal portale rimaste «in verifica» per sempre: prima di questa versione, se l'incasso registrato da «Registra →» andava su altre rate
 * (l'imputazione alle più vecchie), il compito della segnalazione si chiudeva e la rata restava `reported`, e il condòmino non poteva segnalare di nuovo. Una
 * correzione una volta sola, senza gesti dell'amministratore: ogni rata `reported` senza un compito della segnalazione aperto si riallinea alle sue quote
 * (pagata, in parte, o di nuovo da versare) e il portale dice dove è andato il versamento, come fa ora la chiusura del compito
 * ({@see SegnalazioneDelBonifico::sbloccaLeChiuse()}).
 *
 * Rieseguibile: le rate riallineate non sono più `reported`, e una seconda volta non trova niente. Non cambia lo schema. Tornando indietro non si rimettono
 * «in verifica». Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eventi') || ! Schema::hasTable('quota_scrittura')) {
            return;
        }
        app(SegnalazioneDelBonifico::class)->sbloccaLeChiuse();
    }

    public function down(): void
    {
        // Niente: una rata sbloccata non torna «in verifica».
    }
};
