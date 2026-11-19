<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Services\Gestionale\VociDiEntrataNelMastro;
use Illuminate\Console\Command;

/**
 * Coda 680 (decisione 493 (15)): le voci di entrata con un mastro di costo, in tutti i condomini o in uno, con le scritture che le hanno usate. **Sola
 * lettura** di serie, come gli altri `kondomanager:verifica-*`; con `--correggi` sposta il mastro delle voci a quello delle entrate, per le scritture che
 * verranno, e non tocca le righe già scritte: lo dice. La stessa correzione si fa dalla pagina del piano dei conti, voce per voce.
 */
class VerificaVociDiEntrataCommand extends Command
{
    protected $signature = 'kondomanager:verifica-voci-di-entrata
                            {--condominio= : ID del condominio (omesso = tutti)}
                            {--correggi : sposta le voci al mastro delle entrate, per le scritture che verranno}';

    protected $description = 'Elenca le voci di entrata con un mastro di costo e le scritture che le hanno usate; con --correggi le sposta al mastro delle entrate per il futuro.';

    public function handle(VociDiEntrataNelMastro $voci): int
    {
        $condomini = $this->option('condominio') ? Condominio::where('id', $this->option('condominio'))->get() : Condominio::orderBy('id')->get();
        $correggi = (bool) $this->option('correggi');
        $this->info($correggi ? 'Voci di entrata nel mastro dei costi — con la correzione: solo il mastro delle voci, le scritture già fatte restano.'
            : 'Voci di entrata nel mastro dei costi — sola lettura, niente viene modificato.');
        $totale = 0;
        foreach ($condomini as $c) {
            $elenco = $voci->perCondominio((int) $c->id);
            if ($elenco === []) {
                continue;
            }
            $totale += count($elenco);
            $this->line('');
            $this->line("<options=bold>#{$c->id} — {$c->nome}</>");
            $this->table(['Voce', 'Gestione', 'Mastro', 'Scritture', 'Importo'], array_map(fn ($v) => [$v['nome'], $v['gestione'] ?? '—', $v['mastro'], count($v['scritture']),
                MoneyHelper::format(abs($v['totale']))], $elenco));
            if ($correggi) {
                $spostate = $voci->correggi((int) $c->id);
                $this->line("  Spostate al mastro «" . VociDiEntrataNelMastro::NOME . "»: {$spostate}. Le scritture già fatte restano sul mastro di prima.");
            }
        }
        $this->line('');
        if ($totale === 0) {
            $this->info('Nessuna voce di entrata nel mastro dei costi.');
        } elseif (! $correggi) {
            $this->warn("{$totale} voci di entrata nel mastro dei costi. Per spostarle, per le scritture che verranno: dalla pagina del piano dei conti, o con --correggi.");
        }

        return self::SUCCESS;
    }
}
