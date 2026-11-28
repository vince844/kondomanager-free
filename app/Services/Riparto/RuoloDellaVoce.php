<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Riparto;

use App\Enums\RuoloAnagraficaImmobile;
use Illuminate\Support\Facades\DB;

/**
 * Il ruolo che una voce chiede, per l'addebito diretto a una sola unità scritto su quella voce: l'addebito segue la voce, e chi l'ha
 * scelta per l'unità in usufrutto (il passaggio che l'ha spostata sull'«Usufruttuario» o l'ha tenuta sul «Proprietario») decide anche lui.
 *
 * - **«Proprietario»**: la voce chiede il solo «Proprietario» (l'«Usufruttuario» e l'«Inquilino» a zero) in tutte le sue tabelle. Una tabella
 *   senza percentuali scritte vale «Proprietario 100 %», come la legge il motore (`CalcoloQuoteService::distribuisciSuTabelle`).
 * - **«Usufruttuario»**: la voce chiede il solo «Usufruttuario» (il «Proprietario» e l'«Inquilino» a zero).
 * - **null**: la riga non ha una voce, la voce non ha tabelle, divide fra «Proprietario» e «Usufruttuario» o chiede anche l'«Inquilino»: la voce
 *   non dice a chi va, e decide la regola dell'unità (il passaggio che ha fatto nascere l'usufrutto, altrimenti l'ordine di sempre).
 *
 * La legge le percentuali di oggi: il motore le legge così quando genera, e il conguaglio di un passaggio le rilegge per la riga congelata
 * dell'addebito, che non porta il ruolo richiesto.
 *
 * La voce di una riga di fattura assegnata a un'unità è `conto_id` se c'è (le righe scritte prima della 1.9.0), altrimenti `conto_addebito_id`, la voce
 * scelta nel modulo e tenuta fuori dal preventivo comune (`FatturaPassivaService`). La riga di riparto dell'addebito diretto porta `conto_id` vuoto
 * come la sua riga di fattura: la voce si rilegge da quella (`dellaRigaDiRiparto`).
 */
final class RuoloDellaVoce
{
    /**
     * Il ruolo della voce di una riga di riparto dell'addebito diretto: la sua voce, altrimenti la voce dell'addebito della riga di fattura da cui
     * viene (`riga_fattura_id`). Una riga senza nessuna delle due: null.
     */
    public static function dellaRigaDiRiparto(object $riga): ?RuoloAnagraficaImmobile
    {
        return self::di(self::idDellaRigaDiRiparto($riga));
    }

    /** L'id della voce di una riga di riparto dell'addebito diretto, come `dellaRigaDiRiparto` (null se non ne ha). */
    public static function idDellaRigaDiRiparto(object $riga): ?int
    {
        if (isset($riga->conto_id) && (int) $riga->conto_id > 0) {
            return (int) $riga->conto_id;
        }
        if (! isset($riga->riga_fattura_id) || (int) $riga->riga_fattura_id <= 0) {
            return null;
        }
        $voce = DB::table('righe_fattura')->where('id', (int) $riga->riga_fattura_id)->value('conto_addebito_id');

        return $voce === null ? null : (int) $voce;
    }

    public static function di(?int $contoId): ?RuoloAnagraficaImmobile
    {
        if ($contoId === null || $contoId <= 0) {
            return null;
        }
        $tabelle = DB::table('conto_tabella_millesimale')->where('conto_id', $contoId)->pluck('id');
        if ($tabelle->isEmpty()) {
            return null;
        }
        $ripartizioni = DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', $tabelle->all())
            ->get(['conto_tabella_millesimale_id', 'soggetto', 'percentuale'])->groupBy('conto_tabella_millesimale_id');
        $somme = ['proprietario' => 0.0, 'usufruttuario' => 0.0, 'inquilino' => 0.0];
        foreach ($tabelle as $id) {
            $righe = $ripartizioni[$id] ?? collect();
            if ($righe->isEmpty()) {
                $somme['proprietario'] += 100.0;

                continue;
            }
            foreach ($righe as $r) {
                $somme[(string) $r->soggetto] = ($somme[(string) $r->soggetto] ?? 0.0) + max(0.0, (float) $r->percentuale);
            }
        }
        if ($somme['inquilino'] > 0.0) {
            return null;
        }

        return match (true) {
            $somme['proprietario'] > 0.0 && $somme['usufruttuario'] <= 0.0 => RuoloAnagraficaImmobile::PROPRIETARIO,
            $somme['usufruttuario'] > 0.0 && $somme['proprietario'] <= 0.0 => RuoloAnagraficaImmobile::USUFRUTTUARIO,
            default => null,
        };
    }
}
