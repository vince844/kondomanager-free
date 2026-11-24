<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Enums\TipoMovimentoContabile;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coda 754 (9): il già versato dichiarato «ancora in cassa» senza l'accredito sulla sua cassa, in tutti i condomini o in uno.
 *
 * Fino alla 1.12.0 la pagina «Già versato» salvava le righe come «registrata in cassa» anche quando l'accredito non si poteva scrivere (mancava il conto di
 * sistema «Fondo Passate Gestioni»): la risposta diceva «aggiornati», la cassa restava senza quei soldi e la domanda «dove sono questi soldi?» non tornava
 * più. Per ogni voce e cassa con righe «registrata in cassa» guarda se sulla cassa c'è l'accredito di quella voce (tipo `accantonamento`, causale «Già
 * versato — nome della voce», come lo scrive `RegistraContributoInCassaAction`); una voce rinominata dopo il salvataggio compare anche lei, e si controlla
 * sul mastrino della cassa.
 *
 * **Solo lettura, sempre**: elenca e non scrive niente; come sistemare lo dice la frase in fondo, e lo decide l'amministratore.
 */
class VerificaGiaVersatoInCassaCommand extends Command
{
    protected $signature = 'kondomanager:verifica-gia-versato-in-cassa
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca il già versato dichiarato «ancora in cassa» senza l\'accredito sulla cassa (sola lettura, non modifica nulla).';

    public function handle(): int
    {
        $this->info('Già versato «ancora in cassa» senza l\'accredito sulla cassa — sola lettura, niente viene modificato.');

        $gruppi = DB::table('contributi_versati as cv')
            ->join('conti as v', 'v.id', '=', 'cv.target_id')
            ->leftJoin('casse as ca', 'ca.id', '=', 'cv.cassa_id')
            ->leftJoin('condomini as co', 'co.id', '=', 'cv.condominio_id')
            ->where('cv.target_type', Conto::class)
            ->where('cv.liquidita_stato', ContributoVersato::LIQUIDITA_REGISTRATA_IN_CASSA)
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('cv.condominio_id', $id))
            ->groupBy('cv.condominio_id', 'co.nome', 'cv.target_id', 'v.nome', 'cv.cassa_id', 'ca.nome', 'ca.conto_contabile_id')
            ->orderBy('cv.condominio_id')->orderBy('cv.target_id')
            ->select('cv.condominio_id', 'co.nome as condominio', 'cv.target_id', 'v.nome as voce', 'cv.cassa_id', 'ca.nome as cassa', 'ca.conto_contabile_id',
                DB::raw('SUM(cv.importo_cents) as importo'), DB::raw('COUNT(*) as righe'))
            ->get();

        $righe = [];
        foreach ($gruppi as $g) {
            $accreditata = $g->conto_contabile_id !== null && DB::table('scritture_contabili as sc')
                ->join('righe_scritture as rs', 'rs.scrittura_id', '=', 'sc.id')
                ->where('sc.condominio_id', $g->condominio_id)
                ->where('sc.tipo_movimento', TipoMovimentoContabile::ACCANTONAMENTO->value)
                ->whereNull('sc.deleted_at')
                ->where('sc.causale', mb_substr('Già versato — '.$g->voce, 0, 255))
                ->where('rs.conto_contabile_id', $g->conto_contabile_id)
                ->where('rs.tipo_riga', 'dare')
                ->exists();
            if ($accreditata) {
                continue;
            }
            $righe[] = [
                "#{$g->condominio_id} ".($g->condominio ?? ''), $g->voce, $g->cassa ?? 'cassa eliminata', (int) $g->righe,
                number_format(((int) $g->importo) / 100, 2, ',', '.'),
            ];
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessun già versato «ancora in cassa» senza l\'accredito sulla cassa.');

            return self::SUCCESS;
        }

        $this->table(['Condominio', 'Voce', 'Cassa', 'Righe', 'Già versato (€)'], $righe);
        $this->warn(count($righe).' voci. Niente è stato modificato. Il loro già versato risulta «ancora in cassa», ma la cassa non ha l\'accredito: è stato '
            .'salvato senza il conto di sistema «Fondo Passate Gestioni» (2301). Per sistemarle: chi gestisce l\'installazione ripristina il conto; poi, '
            .'nella pagina «Già versato» della voce, l\'amministratore azzera le righe e salva, e le dichiara di nuovo: alla nuova dichiarazione il programma '
            .'chiede dove sono i soldi e accredita la cassa.');

        return self::SUCCESS;
    }
}
