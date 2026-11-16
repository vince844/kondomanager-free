<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Accessori;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Support\Facades\DB;

/**
 * Gli interessi e le spese addebitati alle persone che una lettera chiede: quello che scrive con `[interessi]` e `[spese]`. Si leggono dalle righe della
 * lettera, con la cifra che la lettera chiede per ognuna: le due cifre stanno sempre nel `[totale]` della stessa lettera. Un addebito che non è fra le
 * righe (non ancora scaduto, o in un accordo che lo tiene fuori) non c'è; una cifra calcolata e non registrata nemmeno. Gli interessi di mora da una
 * parte, le altre spese dall'altra.
 */
final class AccessoriDellePersone
{
    /**
     * @param array<int, list<array{rata_id: int, immobile_id: ?int, importo: int}>> $righe per persona, le righe della lettera con la cifra chiesta
     * @return array<int, array{interessi: int, spese: int}> per persona; chi non ne ha non c'è
     */
    public static function nelleRighe(int $condominioId, array $righe): array
    {
        $rateIds = collect($righe)->flatten(1)->pluck('rata_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($rateIds === []) {
            return [];
        }
        $tipi = RataQuote::whereIn('anagrafica_id', array_keys($righe))->where('tipo', RataQuote::TIPO_ACCESSORIO)->whereIn('rata_id', $rateIds)
            ->whereIn('rata_id', DB::table('rate')->whereIn('piano_rate_id', PianoRate::degliAddebiti()->where('condominio_id', $condominioId)->select('id'))->select('id'))
            ->get(['id', 'anagrafica_id', 'rata_id', 'immobile_id', 'regole_calcolo'])
            ->mapWithKeys(fn (RataQuote $q) => [self::chiave((int) $q->anagrafica_id, (int) $q->rata_id, $q->immobile_id)
                => (AddebitiIndividuali::dati($q)['tipo'] ?? null) === 'interessi' ? 'interessi' : 'spese']);
        $esito = [];
        foreach ($righe as $persona => $lista) {
            foreach ($lista as $r) {
                $tipo = $tipi[self::chiave((int) $persona, (int) $r['rata_id'], $r['immobile_id'])] ?? null;
                if ($tipo === null || (int) $r['importo'] <= 0) {
                    continue;
                }
                $esito[(int) $persona] ??= ['interessi' => 0, 'spese' => 0];
                $esito[(int) $persona][$tipo] += (int) $r['importo'];
            }
        }

        return $esito;
    }

    private static function chiave(int $persona, int $rataId, $immobileId): string
    {
        return $persona . '|' . $rataId . '|' . ($immobileId === null ? 0 : (int) $immobileId);
    }
}
