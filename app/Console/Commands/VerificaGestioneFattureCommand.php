<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Models\Condominio;
use App\Services\Gestionale\FattureToccateDallaModifica;
use Illuminate\Console\Command;

/**
 * Coda 687 (decisione 507): le fatture che una modifica ha già spostato su un'altra gestione, o spogliato di «Fuori preventivo», in tutti i condomini o in uno.
 * **Sola lettura** di serie, come gli altri `kondomanager:verifica-*`: elenca con la correzione proposta. Con `--correggi` la fa, solo sulle fatture che si
 * possono correggere senza riscrivere un pagamento o un esercizio chiuso; le altre restano elencate con il motivo. La stessa correzione si fa dall'elenco
 * delle fatture, fattura per fattura.
 */
class VerificaGestioneFattureCommand extends Command
{
    protected $signature = 'kondomanager:verifica-gestione-fatture
                            {--condominio= : ID del condominio (omesso = tutti)}
                            {--fattura=* : solo queste fatture (ID)}
                            {--correggi : fa la correzione proposta, sulle fatture che si possono correggere}';

    protected $description = 'Elenca le fatture che una modifica ha spostato su un\'altra gestione o spogliato di «Fuori preventivo»; con --correggi le riporta come erano.';

    public function handle(FattureToccateDallaModifica $controllo): int
    {
        $condomini = $this->option('condominio') ? Condominio::where('id', $this->option('condominio'))->get() : Condominio::orderBy('id')->get();
        $solo = array_values(array_filter(array_map('intval', (array) $this->option('fattura')))) ?: null;
        $correggi = (bool) $this->option('correggi');
        $this->info($correggi ? 'Fatture toccate da una modifica — con la correzione proposta, solo dove si può.'
            : 'Fatture toccate da una modifica — sola lettura, niente viene modificato.');

        $totale = 0;
        foreach ($condomini as $c) {
            $elenco = $controllo->perCondominio((int) $c->id, $solo);
            if ($elenco['spostate'] === [] && $elenco['spogliate'] === []) {
                continue;
            }
            $totale += count($elenco['spostate']) + count($elenco['spogliate']);
            $this->line('');
            $this->line("<options=bold>#{$c->id} — {$c->nome}</>");
            if ($elenco['spostate'] !== []) {
                $this->line('  Sulla gestione sbagliata:');
                $this->table(['Fattura', 'Fornitore', 'Totale', 'Gestione oggi', 'Gestione delle voci', 'Correzione'], array_map(fn ($f) => [
                    "#{$f['id']} n. {$f['numero_documento']} del {$f['data_documento']}", $f['fornitore'], $f['totale_testo'], $f['gestione_attuale'],
                    $f['gestione_delle_voci'] ?? '—', $f['correggibile'] ? $f['proposta'] : 'A mano: ' . $f['motivo'],
                ], $elenco['spostate']));
            }
            if ($elenco['spogliate'] !== []) {
                $this->line('  Senza il segno «Fuori preventivo»:');
                $this->table(['Fattura', 'Fornitore', 'Totale', 'Voce', 'Correzione'], array_map(fn ($f) => [
                    "#{$f['id']} n. {$f['numero_documento']} del {$f['data_documento']}", $f['fornitore'], $f['totale_testo'], implode(', ', $f['voci']), $f['proposta'],
                ], $elenco['spogliate']));
            }
            if ($correggi) {
                $fatto = $controllo->correggi((int) $c->id, $solo);
                $this->line("  Corrette: {$fatto['spostate']} riportate sulla gestione delle voci, {$fatto['spogliate']} con il segno «Fuori preventivo» rimesso.");
            }
        }

        $this->line('');
        if ($totale === 0) {
            $this->info('Nessuna fattura da correggere.');
        } elseif (! $correggi) {
            $this->warn("{$totale} fatture da controllare. Per la correzione proposta: dall'elenco delle fatture, fattura per fattura, o con --correggi.");
        }

        return self::SUCCESS;
    }
}
