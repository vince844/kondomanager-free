<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\CategoriaEventoEnum;
use App\Models\Condominio;
use App\Services\Gestionale\F24\PromemoriaDelleRitenute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * I promemoria «F24 Ritenuta» che ci sono già (Coda 718, decisioni 675 e 685 (7)): fino alla 1.12.0-beta.13 ogni pagamento con la
 * ritenuta ne scriveva uno, con il 16 del mese dopo e senza il cumulo, e nessuno lo chiudeva al versamento. Dalla 1.12.0-beta.14 li
 * scrive solo `PromemoriaDelleRitenute`: uno per ogni delega aperta e un «Prepara le deleghe F24» per le ritenute senza delega.
 *
 * Per ogni condominio con almeno un promemoria per pagamento **aperto**, una volta sola, `PromemoriaDelleRitenute::allineaSenzaFermare()` (un errore su un condominio va nel registro dell'applicazione e non ferma l'aggiornamento: quel condominio resta con i promemoria di prima, e il primo pagamento o il primo «Aggiorna scadenze» lo allinea, anche chiudendo i suoi promemoria per pagamento senza legame, che `PromemoriaDelleRitenute` legge dal pagamento): (1) chiude
 * i promemoria per pagamento aperti; (2) scrive un promemoria per ogni delega aperta; (3) scrive il «Prepara le deleghe F24» se ci sono
 * ritenute in nessuna delega viva, con la data del cumulo. È il risultato che darebbe il primo pagamento o il primo «Aggiorna scadenze»
 * dopo l'aggiornamento, subito: la data del «Prepara» è quella giusta, non una data per pagamento (che non è mai più tardi di quella
 * giusta, ma accanto alle parole «con il cumulo si versano entro il …» direbbe una cosa falsa).
 *
 * I condomini con i soli promemoria chiusi dall'amministratore, o senza promemoria, non si toccano: i loro nasceranno al primo
 * pagamento o al primo «Aggiorna scadenze». I promemoria per pagamento già chiusi restano come sono.
 *
 * Il condominio di un promemoria è il suo legame con il condominio (lo scriveva il listener) o, senza, quello del suo pagamento; un
 * promemoria aperto senza legame si chiude qui, dopo l'allineamento del suo condominio, e solo se quell'allineamento è andato a buon fine. Senza la categoria «Scadenze amministrative»
 * (la scrive l'installazione) la migrazione non scrive niente e lo dice nel registro: i promemoria di prima restano aperti.
 *
 * Solo dati, nessuna struttura. Rieseguibile: la seconda volta non trova promemoria per pagamento aperti e non fa niente (e
 * `allinea()` non scrive due volte la stessa cosa). Nel dataset di `UpgradeMigrationsRerunTest`. Tornando indietro i promemoria
 * restano come sono.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eventi') || ! Schema::hasTable('condominio_evento') || ! Schema::hasTable('categorie_evento')
            || ! Schema::hasTable('deleghe_f24') || ! Schema::hasTable('righe_f24') || ! Schema::hasTable('riga_f24_pagamento')
            || ! Schema::hasTable('pagamenti_fornitori') || ! Schema::hasColumns('eventi', ['meta', 'is_completed', 'completed_at'])) {
            return;
        }

        // I promemoria per pagamento aperti, con il condominio del legame o del pagamento.
        $vecchi = DB::table('eventi')
            ->where('meta->type', PromemoriaDelleRitenute::TIPO)
            ->where('is_completed', false)
            ->whereNotNull('meta->context->pagamento_id')
            ->whereNull('meta->context->delega_f24_id')
            ->whereNull('meta->context->prepara_deleghe')
            ->get(['id', 'meta']);
        if ($vecchi->isEmpty()) {
            return;
        }

        $legami = DB::table('condominio_evento')->whereIn('evento_id', $vecchi->pluck('id')->all())->get(['evento_id', 'condominio_id']);
        $legati = $legami->pluck('evento_id')->map(fn ($id) => (int) $id)->unique()->all();
        $senzaLegame = $vecchi->reject(fn ($e) => in_array((int) $e->id, $legati, true));
        $pagamenti = $senzaLegame->mapWithKeys(fn ($e) => [(int) $e->id => (int) (json_decode((string) $e->meta, true)['context']['pagamento_id'] ?? 0)]);
        $condominiDeiPagamenti = $pagamenti->isEmpty() ? collect()
            : DB::table('pagamenti_fornitori')->whereIn('id', $pagamenti->values()->filter()->all())->pluck('condominio_id', 'id');

        $condomini = $legami->pluck('condominio_id')
            ->merge($condominiDeiPagamenti->values())
            ->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();

        if (! DB::table('categorie_evento')->where('name', CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value)->exists()) {
            Log::warning('Migrazione dei promemoria delle ritenute: manca la categoria «Scadenze amministrative», i condomini '
                .$condomini->implode(', ').' restano con i promemoria per pagamento di prima.');

            return;
        }

        $promemoria = app(PromemoriaDelleRitenute::class);
        $falliti = [];
        foreach (Condominio::whereIn('id', $condomini->all())->orderBy('id')->get() as $condominio) {
            if (! $promemoria->allineaSenzaFermare($condominio)) {
                $falliti[] = (int) $condominio->id;
            }
        }

        // Un promemoria per pagamento aperto senza il legame con il condominio l'allineamento non lo vede: si chiude qui, se il suo
        // condominio c'è ancora.
        // Solo nei condomini che si sono allineati: quello fallito resta com'era.
        $daChiudere = $pagamenti->filter(fn ($pagamento) => $pagamento > 0 && isset($condominiDeiPagamenti[$pagamento])
            && ! in_array((int) $condominiDeiPagamenti[$pagamento], $falliti, true))->keys()->all();
        if ($daChiudere !== []) {
            DB::table('eventi')->whereIn('id', $daChiudere)->where('is_completed', false)->update(['is_completed' => true, 'completed_at' => now()]);
        }
    }

    public function down(): void
    {
        // Niente: i promemoria si allineano da soli al primo pagamento o al primo «Aggiorna scadenze».
    }
};
