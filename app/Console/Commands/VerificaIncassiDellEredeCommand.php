<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gli incassi di un erede con un credito usato e una parte sul debito ereditato, scritti prima della correzione del piano del credito
 * (`StoreIncassoRateAction::pianoDelCredito`, che ora toglie dal denaro la parte sul debito ereditato), in tutti i condomini o in uno.
 *
 * Prima, un erede con un credito che pagava insieme una sua rata e il debito ereditato si vedeva prelevare il credito e riaccreditarlo tutto dalla
 * compensazione (la scrittura `storno_credito` figlia dell'incasso): il credito restava com'era e la sua rata restava aperta della cifra del credito,
 * e quella rata va nelle rate scadute, nei solleciti e negli interessi. Il comando li trova: incassi `incasso_rata` non annullati con almeno una riga
 * dell'erede sul debito ereditato (la riga sulla rata del defunto con il legame all'erede, `VersamentoDegliEredi::registraDellErede`) e una
 * compensazione figlia che ha prelevato credito senza metterne niente su nessun'altra quota; per ognuno le rate del pagante pagate con quell'incasso e
 * ancora aperte.
 *
 * **Solo lettura, sempre**: elenca e non corregge niente. Il rimedio lo decide l'amministratore: stornare l'incasso e registrarlo di nuovo con «Usa credito».
 */
class VerificaIncassiDellEredeCommand extends Command
{
    protected $signature = 'kondomanager:verifica-incassi-dell-erede
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca gli incassi di un erede con il credito prelevato e riaccreditato tutto, e la rata rimasta aperta della cifra del credito (sola lettura, non corregge nulla).';

    public function handle(): int
    {
        $this->info('Incassi dell\'erede con il credito e il debito ereditato insieme — sola lettura, niente viene modificato.');

        $incassi = ScritturaContabile::query()
            ->where('tipo_movimento', 'incasso_rata')
            ->where('stato', '!=', 'annullata')
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('condominio_id', $id))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('righe_scritture')->whereColumn('righe_scritture.scrittura_id', 'scritture_contabili.id')
                ->where('righe_scritture.riferimento_type', Anagrafica::class)->whereNotNull('righe_scritture.riferimento_id'))
            ->with('condominio:id,nome')
            ->orderBy('condominio_id')->orderBy('data_competenza')->orderBy('id')
            ->get();

        $righe = [];
        foreach ($incassi as $incasso) {
            $erede = (int) DB::table('righe_scritture')->where('scrittura_id', $incasso->id)->where('riferimento_type', Anagrafica::class)->value('riferimento_id');
            $credito = 0;
            foreach (ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'storno_credito')->where('stato', '!=', 'annullata')->pluck('id') as $stornoId) {
                $credito += self::creditoTornatoTutto((int) $stornoId);
            }
            if ($credito <= 0) {
                continue;
            }
            $aperte = self::rateAperteDelPagante((int) $incasso->id, $erede);
            if ($aperte === []) {
                continue;
            }
            $righe[] = [
                sprintf('%s (#%d)', $incasso->condominio?->nome ?? '—', (int) $incasso->condominio_id),
                $incasso->data_competenza?->format('d/m/Y') ?? '—',
                (string) (Anagrafica::find($erede)?->nome ?? '—'),
                implode('; ', $aperte),
                MoneyHelper::format($credito, false),
            ];
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessun incasso dell\'erede con il credito prelevato e riaccreditato tutto.');

            return self::SUCCESS;
        }

        $this->table(['Condominio', 'Data', 'Pagante', 'Rata rimasta aperta', 'Credito non usato (€)'], $righe);
        $this->line('');
        $this->warn('Niente è stato corretto. Per ognuno: storna l\'incasso e registralo di nuovo con «Usa credito». Finché resta così, la rata aperta entra nelle rate scadute, nei solleciti e negli interessi.');

        return self::SUCCESS;
    }

    /**
     * Il credito che una compensazione ha prelevato e riaccreditato per intero: la somma dei prelievi (le righe della pivot sotto zero) se nessuna
     * quota senza prelievo ha ricevuto qualcosa da lei; zero altrimenti, o se non ha prelevato niente.
     */
    private static function creditoTornatoTutto(int $stornoId): int
    {
        $pivot = DB::table('quota_scrittura')->where('scrittura_contabile_id', $stornoId)->get(['rate_quota_id', 'importo_pagato']);
        $prelevate = $pivot->filter(fn ($p) => (int) $p->importo_pagato < 0)->pluck('rate_quota_id')->map(fn ($id) => (int) $id)->unique()->all();
        $prelievo = (int) abs($pivot->filter(fn ($p) => (int) $p->importo_pagato < 0)->sum('importo_pagato'));
        $usato = (int) $pivot->filter(fn ($p) => (int) $p->importo_pagato > 0 && ! in_array((int) $p->rate_quota_id, $prelevate, true))->sum('importo_pagato');
        $netto = (int) $pivot->sum('importo_pagato');

        return $prelievo > 0 && $usato === 0 && $netto === 0 ? $prelievo : 0;
    }

    /**
     * Le rate del pagante pagate con l'incasso e ancora aperte: «rata n. 2 (Preventivo 2026), € 50,00 da pagare».
     *
     * @return list<string>
     */
    private static function rateAperteDelPagante(int $incassoId, int $pagante): array
    {
        $ids = DB::table('quota_scrittura')->where('scrittura_contabile_id', $incassoId)->where('importo_pagato', '>', 0)->pluck('rate_quota_id')->all();

        return RataQuote::with('rata.pianoRate:id,nome')->whereIn('id', $ids ?: [0])->where('anagrafica_id', $pagante)->orderBy('id')->get()
            ->filter(fn (RataQuote $q) => (int) $q->importo - (int) $q->importo_pagato > 0)
            ->map(fn (RataQuote $q) => sprintf('rata n. %s (%s), %s da pagare', $q->rata?->numero_rata ?? '?', $q->rata?->pianoRate?->nome ?? '—',
                MoneyHelper::format((int) $q->importo - (int) $q->importo_pagato)))
            ->values()->all();
    }
}
