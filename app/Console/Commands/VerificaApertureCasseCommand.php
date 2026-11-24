<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Enums\TipoMovimentoContabile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coda 546 (decisione 685 (5)): le casse create dopo il primo esercizio la cui apertura è stata scritta nel primo esercizio, in tutti i condomini o in uno.
 *
 * Coda 754 (1): anche i conti di liquidità con un saldo e nessuna cassa. Fino alla 1.12.0 una cassa con la sola apertura si eliminava lasciando l'apertura
 * a giornale sul conto archiviato: ricreata con il saldo giusto, la liquidità contava tutti e due e «Liquidità e cassa» restava rosso. Da ora l'eliminazione
 * storna l'apertura (`CassaController::destroy`); quelle eliminate prima le trova questo elenco, con il saldo e le aperture ancora valide.
 *
 * **Solo lettura, sempre**: elenca e non sposta niente. Le casse portate dall'importazione hanno la data di creazione del giorno dell'importazione e
 * l'apertura giusta nel primo esercizio, e uno spostamento automatico sposterebbe anche loro; chi amministra guarda l'elenco e decide cassa per cassa. Per
 * i conti senza cassa nessuno storno automatico: quale dei saldi è quello vero lo sa l'amministratore.
 */
class VerificaApertureCasseCommand extends Command
{
    protected $signature = 'kondomanager:verifica-aperture-casse
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le casse create dopo il primo esercizio con l\'apertura scritta nel primo esercizio, e i conti di liquidità con un saldo e senza cassa (sola lettura, non sposta nulla).';

    public function handle(): int
    {
        $this->info('Aperture di cassa nel primo esercizio, per casse create dopo — sola lettura, niente viene modificato.');

        $righe = [];
        $condomini = DB::table('esercizi')->when($this->option('condominio'), fn ($q, $id) => $q->where('condominio_id', $id))->distinct()->orderBy('condominio_id')->pluck('condominio_id');

        foreach ($condomini as $condominioId) {
            $primo = DB::table('esercizi')->where('condominio_id', $condominioId)->orderBy('data_inizio')->first();
            if (! $primo) {
                continue;
            }

            $aperture = DB::table('casse as c')
                ->join('righe_scritture as rs', 'rs.conto_contabile_id', '=', 'c.conto_contabile_id')
                ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
                ->where('c.condominio_id', $condominioId)
                ->where('sc.tipo_movimento', TipoMovimentoContabile::APERTURA->value)
                ->whereNull('sc.deleted_at')
                // Coda 754 (1): un'apertura annullata, e il suo storno, non sono un'apertura della cassa.
                ->whereNull('sc.scrittura_padre_id')
                ->whereRaw("COALESCE(sc.stato, '') <> 'annullata'")
                ->where('sc.esercizio_id', $primo->id)
                ->select('c.id', 'c.nome', 'c.created_at', 'sc.data_registrazione', 'rs.importo', 'rs.tipo_riga')
                ->get();

            foreach ($aperture as $a) {
                $creataIl = substr((string) $a->created_at, 0, 10);
                // Creata dopo la fine del primo esercizio: nel primo esercizio la cassa non c'era.
                if ($creataIl <= substr((string) $primo->data_fine, 0, 10)) {
                    continue;
                }
                $righe[] = [
                    $condominioId, $a->nome, $primo->nome, substr((string) $a->data_registrazione, 0, 10), $creataIl,
                    ($a->tipo_riga === 'dare' ? '' : '-').number_format($a->importo / 100, 2, ',', '.'),
                ];
            }
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessuna cassa con l\'apertura nel primo esercizio e creata dopo.');
        } else {
            $this->table(['Condominio', 'Cassa', 'Esercizio dell\'apertura', 'Apertura al', 'Cassa creata il', 'Importo (€)'], $righe);
            $this->warn(count($righe).' casse. Niente è stato spostato: le casse portate dall\'importazione hanno l\'apertura giusta nel primo esercizio. Se una di queste non lo è, la si corregge a mano.');
        }

        $this->contiSenzaCassa();

        return self::SUCCESS;
    }

    /**
     * Coda 754 (1): i conti di liquidità con un saldo e nessuna cassa, con il saldo a oggi (tutte le scritture) e le aperture ancora valide. Un conto con
     * l'apertura e il suo storno è a zero e non compare.
     */
    private function contiSenzaCassa(): void
    {
        $saldo = "SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END)";
        $conti = DB::table('conti_contabili as cc')
            ->join('righe_scritture as rs', 'rs.conto_contabile_id', '=', 'cc.id')
            ->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')
            ->leftJoin('condomini as co', 'co.id', '=', 'cc.condominio_id')
            ->where('cc.categoria', 'liquidita')
            ->whereNull('cc.deleted_at')
            ->whereNull('sc.deleted_at')
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('cc.condominio_id', $id))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('casse as c')->whereColumn('c.conto_contabile_id', 'cc.id'))
            ->groupBy('cc.id', 'cc.condominio_id', 'co.nome', 'cc.codice', 'cc.nome')
            ->havingRaw("{$saldo} <> 0")
            ->orderBy('cc.condominio_id')->orderBy('cc.codice')
            ->select('cc.id', 'cc.condominio_id', 'co.nome as condominio', 'cc.codice', 'cc.nome', DB::raw("{$saldo} as saldo"),
                DB::raw("SUM(CASE WHEN sc.tipo_movimento = '".TipoMovimentoContabile::APERTURA->value."' AND sc.scrittura_padre_id IS NULL "
                    ."AND COALESCE(sc.stato, '') <> 'annullata' AND rs.conto_contabile_id = cc.id THEN 1 ELSE 0 END) as aperture"))
            ->get();

        $this->line('');
        $this->info('Conti di liquidità con un saldo e senza cassa — sola lettura, niente viene modificato.');
        if ($conti->isEmpty()) {
            $this->info('Nessun conto di liquidità con un saldo e senza cassa.');

            return;
        }

        $this->table(['Condominio', 'Conto', 'Saldo (€)', 'Aperture a giornale'], $conti->map(fn ($c) => [
            "#{$c->condominio_id} ".($c->condominio ?? ''), "{$c->codice} {$c->nome}", number_format(((int) $c->saldo) / 100, 2, ',', '.'), (int) $c->aperture,
        ])->all());
        $this->warn($conti->count().' conti di liquidità hanno un saldo e nessuna cassa. Niente è stato modificato. Di solito è il conto di una cassa '
            .'eliminata con la sua apertura ancora a giornale, da una versione che non la stornava: lo Stato patrimoniale lo conta nella liquidità e il '
            .'controllo «Liquidità e cassa» resta rosso. Se quell\'apertura era sbagliata, lo storno si scrive a mano, uguale e contrario (in dare «Fondo '
            .'Passate Gestioni», in avere il conto), con la data dell\'apertura; se la cassa è stata ricreata, prima si guarda quale dei due saldi è quello '
            .'vero. Lo decide l\'amministratore.');
    }
}
