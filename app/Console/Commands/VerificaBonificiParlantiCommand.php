<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Enums\StatoPagamentoFornitore;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Console\Command;

/**
 * Coda 754 (2) (decisione 696): i pagamenti già fatti con il bonifico parlante su fatture registrate con la ritenuta, in tutti i condomini o in uno.
 *
 * Fino alla 1.12.0-beta.13 il pagamento con la spunta «Bonifico Parlante» metteva a zero la sua ritenuta e lasciava la fattura com'era: usciva il netto,
 * e la ritenuta scritta dalla fattura su «Debiti v/Erario» restava lì senza che nessun F24 la versasse (le deleghe leggono la ritenuta dei pagamenti).
 * Dalla beta.14 quel pagamento si ferma; questo comando trova quelli già scritti, con la parte di ritenuta rimasta sull'Erario
 * (`PagamentoFornitoreService::ritenutaDellaQuota`, la stessa regola della ritenuta di un pagamento).
 *
 * **Solo lettura, sempre**: elenca e non corregge niente. Il rimedio (stornare il pagamento, togliere la ritenuta dalla fattura, registrare di nuovo il
 * pagamento, e versare al fornitore la differenza se è uscito il netto) lo decide l'amministratore, pagamento per pagamento.
 */
class VerificaBonificiParlantiCommand extends Command
{
    protected $signature = 'kondomanager:verifica-bonifici-parlanti
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca i pagamenti con il bonifico parlante su fatture registrate con la ritenuta, con la ritenuta rimasta sull\'Erario (sola lettura, non corregge nulla).';

    public function handle(): int
    {
        $this->info('Pagamenti con il bonifico parlante su fatture con la ritenuta — sola lettura, niente viene modificato.');

        $pagamenti = PagamentoFornitore::query()
            ->bonificiParlanti()
            ->where('stato', StatoPagamentoFornitore::CONFERMATO)
            ->whereNull('pagamento_padre_id')
            ->where('importo_ritenuta', '<=', 0)
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('condominio_id', $id))
            ->with(['condominio', 'fornitore', 'scrittura.fatture'])
            ->orderBy('condominio_id')
            ->orderBy('data_pagamento')
            ->orderBy('id')
            ->get();

        $righe = [];
        $perCondominio = [];
        foreach ($pagamenti as $pagamento) {
            foreach ($pagamento->scrittura?->fatture ?? [] as $fattura) {
                /** @var FatturaPassiva $fattura */
                $tipo = $fattura->pivot->tipo instanceof \BackedEnum ? $fattura->pivot->tipo->value : (string) $fattura->pivot->tipo;
                if ($tipo !== 'pagamento' || (int) $fattura->importo_ritenuta <= 0 || (int) $fattura->netto_a_pagare <= 0) {
                    continue;
                }

                $pagato = (int) $fattura->pivot->importo_allocato;
                $rimasta = PagamentoFornitoreService::ritenutaDellaQuota((int) $fattura->importo_ritenuta, (int) $fattura->netto_a_pagare, $pagato);
                $condominio = $pagamento->condominio?->nome ?? '—';
                $righe[] = [
                    "{$condominio} (#{$pagamento->condominio_id})",
                    $pagamento->scrittura?->numero_protocollo ?? "#{$pagamento->id}",
                    $pagamento->data_pagamento?->format('d/m/Y'),
                    $pagamento->fornitore?->ragione_sociale ?? '—',
                    'n. '.($fattura->numero_documento ?: "#{$fattura->id}").($fattura->is_pregresso ? ' (pregressa)' : ''),
                    MoneyHelper::format($pagato, false),
                    MoneyHelper::format($rimasta, false),
                ];

                $chiave = (int) $pagamento->condominio_id;
                $perCondominio[$chiave] ??= ['nome' => $condominio, 'pagamenti' => [], 'ritenuta' => 0];
                $perCondominio[$chiave]['pagamenti'][$pagamento->id] = true;
                $perCondominio[$chiave]['ritenuta'] += $rimasta;
            }
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessun pagamento con il bonifico parlante su una fattura con la ritenuta.');

            return self::SUCCESS;
        }

        $this->table(['Condominio', 'Pagamento', 'Data', 'Fornitore', 'Fattura', 'Pagato dal conto (€)', 'Ritenuta rimasta sull\'Erario (€)'], $righe);

        $this->line('');
        foreach ($perCondominio as $id => $c) {
            $quanti = count($c['pagamenti']);
            $this->line("{$c['nome']} (#{$id}): {$quanti} ".($quanti === 1 ? 'pagamento' : 'pagamenti').', '.MoneyHelper::format($c['ritenuta']).' di ritenuta rimasta sull\'Erario, che nessun F24 versa.');
        }

        $this->line('');
        $this->warn('Niente è stato corretto. Per ognuno, l\'amministratore decide: si storna il pagamento, si toglie la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»; una pregressa si storna e si registra di nuovo con il motivo), si registra di nuovo il pagamento dell\'importo pieno, e se dal conto è uscito il netto si versa al fornitore la differenza.');

        return self::SUCCESS;
    }
}
