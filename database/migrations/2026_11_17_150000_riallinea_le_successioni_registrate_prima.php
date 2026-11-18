<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Subentro\AccessoriAgliEredi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Le successioni con l'arretrato agli eredi registrate con la 1.12.0-beta.10, riallineate alla regola di oggi. Solo dati, nessuna struttura.
 *
 * - **I promemoria degli interessi e delle spese passati agli eredi** (Coda 652): con la beta.10 la quota del defunto si chiudeva, ma il suo promemoria
 *   nel portale restava «da versare» per l'importo di prima, e gli eredi non avevano il loro. Qui seguono le quote, con la regola che da questa versione
 *   vale alla registrazione ({@see AccessoriAgliEredi::riallineaPromemoria()}): quello del defunto si riallinea alla sua quota chiusa, ogni erede ha il
 *   suo; un pagamento segnalato dal defunto e non verificato resta «segnalato».
 * - **L'accordo di rientro del defunto chiuso dalla successione** prende la scelta di serie della decisione 454, «resta in regola fino al decesso», come gli
 *   accordi registrati prima della decisione 450 hanno preso il suo di serie: solo quelli ancora chiusi quel giorno, senza una decadenza registrata e senza
 *   una scelta già scritta. Tocca solo la proposta degli interessi.
 *
 * Le successioni annullate non si toccano. Rieseguibile: la seconda volta i promemoria sono già allineati e gli accordi hanno la scelta. Nel dataset di
 * `UpgradeMigrationsRerunTest`. Tornando indietro non si toglie niente: i promemoria dicono le quote di oggi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subentri') || ! Schema::hasTable('eventi') || ! Schema::hasTable('rate_quote') || ! Schema::hasTable('accordi_rientro')
            || ! Schema::hasColumn('subentri', 'registro') || ! Schema::hasColumn('accordi_rientro', 'chiusura_per_successione')) {
            return;
        }
        Subentro::query()->where('tipo_passaggio', 'successione')->whereNotNull('registro')->orderBy('id')->chunkById(100, function ($blocco) {
            foreach ($blocco as $s) {
                $arretrato = (array) (($s->registro ?? [])['arretrato'] ?? []);
                if (($arretrato['scelta'] ?? null) !== Subentro::ARRETRATO_AGLI_EREDI || (int) $s->anagrafica_uscente_id === 0) {
                    continue;
                }
                $this->promemoria($s, (array) ($arretrato['accessori'] ?? []));
                $this->accordi((array) ($arretrato['accordi'] ?? []));
            }
        });
    }

    /** I promemoria delle rate degli accessori passati agli eredi seguono le quote; con chi ha registrato il passaggio, o il primo utente, come autore dei nuovi. */
    private function promemoria(Subentro $s, array $accessori): void
    {
        if ($accessori === []) {
            return;
        }
        $daRiscrivere = AccessoriAgliEredi::promemoriaDaRiscrivere($accessori);
        $utente = User::find($s->utente_id) ?? User::query()->orderBy('id')->first();
        if ($daRiscrivere['rate'] === [] || $utente === null) {
            return;
        }
        AccessoriAgliEredi::riallineaPromemoria($daRiscrivere['rate'], $daRiscrivere['persone'], (int) $s->anagrafica_uscente_id, $utente);
    }

    /** Gli accordi che il passaggio ha chiuso, ancora chiusi quel giorno e senza decadenza né scelta: il di serie. */
    private function accordi(array $chiusi): void
    {
        foreach ($chiusi as $c) {
            $a = AccordoRientro::find((int) ($c['id'] ?? 0));
            if ($a === null || $a->chiusura_per_successione !== null || $a->decadenza_registrata_il !== null || $a->chiuso_il?->format('Y-m-d') !== ($c['chiuso_il'] ?? null)) {
                continue;
            }
            $a->forceFill(['chiusura_per_successione' => AccordoRientro::SUCCESSIONE_IN_REGOLA])->save();
        }
    }

    public function down(): void
    {
        // Niente: i promemoria dicono le quote di oggi, e la scelta di serie sull'accordo tocca solo la proposta degli interessi.
    }
};
