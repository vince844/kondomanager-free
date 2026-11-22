<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Models\Condominio;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `kondomanager:verifica-delibere` — la data della delibera sui piani delle gestioni **straordinarie**,
 * prima di rigenerare con la 1.11.0-beta.31 (B2, decisione 12 del progetto sul subentro).
 *
 * Dalla beta.31 sullo straordinario la competenza è **il giorno della delibera**: chi era titolare quel giorno
 * risponde dell'intera spesa (Cass. 24654/2010). Il motore senza quella data si ferma. Fino alla beta.30 la data
 * nasceva solo dal modal di approvazione, che proponeva «oggi»: in produzione la colonna coincideva col giorno
 * del clic. Questo comando elenca, in sola lettura, i piani da guardare:
 *
 * 1. **delibera assente** — piano su gestione straordinaria, autorizzazione non d'urgenza, `data_delibera_assemblea`
 *    nulla: alla rigenerazione si fermerà. Va scritta dal verbale.
 * 2. **delibera sospetta** — la data coincide col giorno dell'approvazione (`DATE(approvato_il)`): è quasi sempre
 *    il default del vecchio modal, non la data dell'assemblea. Da confermare o correggere.
 * 3. **urgenza senza competenza sulla fattura** — con `tipo_autorizzazione = urgenza` la delibera non c'è per
 *    costruzione e la competenza va dichiarata sulla fattura (`competenza_dal/al`): se manca anche quella il
 *    motore si ferma sulla fattura. Dalla Coda 688 il piano con il giorno dei lavori (`giorno_dei_lavori`) non si
 *    ferma: la fattura senza periodo prende quel giorno, e il piano non si elenca.
 *
 * Fa fede `gestioni.tipo` (la natura, decisione 11), non `piani_rate.tipo`: un piano «ordinario» su una gestione
 * straordinaria chiede la delibera, uno «straordinario» su una gestione ordinaria va pro rata e la data non conta.
 * Come `verifica-titolarita`: sola lettura, `SUCCESS` sempre.
 */
class VerificaDelibereCommand extends Command
{
    protected $signature = 'kondomanager:verifica-delibere
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca i piani su gestioni straordinarie senza data della delibera, con la data uguale al giorno dell\'approvazione, o d\'urgenza con fatture senza competenza. Non modifica nulla.';

    public function handle(): int
    {
        $condomini = $this->option('condominio')
            ? Condominio::where('id', $this->option('condominio'))->get()
            : Condominio::orderBy('id')->get();

        if ($condomini->isEmpty()) {
            $this->warn('Nessun condominio trovato.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->info('La data della delibera che il riparto straordinario legge dalla 1.11 — sola lettura, niente viene modificato.');
        $this->line('');

        $totale = 0;

        foreach ($condomini as $condominio) {
            $assenti  = $this->deliberaAssente($condominio->id);
            $sospette = $this->deliberaSospetta($condominio->id);
            $urgenze  = $this->urgenzaSenzaCompetenza($condominio->id);

            $segnali = $assenti->count() + $sospette->count() + $urgenze->count();
            if ($segnali === 0) {
                continue;
            }
            $totale += $segnali;

            $this->line("<options=bold>#{$condominio->id} — {$condominio->nome}</>");

            if ($assenti->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=red>Delibera assente ({$assenti->count()}):</> alla prossima rigenerazione il piano si ferma e chiede la data.");
                $this->table(
                    ['Piano', 'Gestione', 'Tipo piano', 'Autorizzazione', 'Approvato il'],
                    $assenti->map(fn ($r) => [$r->piano, $r->gestione, $r->tipo, $r->tipo_autorizzazione ?? '—', $r->approvato_il ? substr((string) $r->approvato_il, 0, 10) : '—'])->all()
                );
            }

            if ($sospette->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Delibera uguale al giorno dell'approvazione ({$sospette->count()}):</> quasi sempre il default del vecchio modal, non la data dell'assemblea. Confermala o correggila dal verbale.");
                $this->table(
                    ['Piano', 'Gestione', 'Data delibera', 'Approvato il'],
                    $sospette->map(fn ($r) => [$r->piano, $r->gestione, $r->data_delibera_assemblea, substr((string) $r->approvato_il, 0, 10)])->all()
                );
            }

            if ($urgenze->isNotEmpty()) {
                $this->line('');
                $this->line("  <fg=yellow>Urgenza con fatture senza competenza ({$urgenze->count()}):</> senza delibera la competenza va dichiarata sulla fattura, altrimenti il riparto si ferma lì.");
                $this->table(
                    ['Piano', 'Gestione', 'Fatture senza competenza'],
                    $urgenze->map(fn ($r) => [$r->piano, $r->gestione, $r->fatture])->all()
                );
            }

            $this->line('');
        }

        if ($totale === 0) {
            $this->info('Nessun segnale: ogni piano su una gestione straordinaria ha la sua data della delibera, e nessuna coincide col giorno del clic.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->warn($totale === 1 ? '1 segnale in totale.' : "{$totale} segnali in totale.");
        $this->line('Sullo straordinario la data della delibera decide chi paga: correggila dal piano prima di rigenerare.');
        $this->line('');

        return self::SUCCESS;
    }

    private function pianiStraordinari(int $condominioId)
    {
        return DB::table('piani_rate as p')
            ->join('gestioni as g', 'g.id', '=', 'p.gestione_id')
            ->where('p.condominio_id', $condominioId)
            ->where('g.tipo', 'straordinaria')
            ->select('p.id', 'p.nome as piano', 'g.nome as gestione', 'p.tipo', 'p.tipo_autorizzazione', 'p.data_delibera_assemblea', 'p.approvato_il');
    }

    private function deliberaAssente(int $condominioId): Collection
    {
        return $this->pianiStraordinari($condominioId)
            ->whereNull('p.data_delibera_assemblea')
            ->where(fn ($q) => $q->whereNull('p.tipo_autorizzazione')->orWhere('p.tipo_autorizzazione', '!=', 'urgenza'))
            ->orderBy('p.id')->get();
    }

    /**
     * `DATE()` su entrambi i lati: `data_delibera_assemblea` è una data, `approvato_il` un timestamp (UTC come il vecchio default).
     *
     * R13 del progetto della tappa 2 (§13, rilievo 10): un piano collegato a una delibera **del programma, o di una riunione a mano con il verbale
     * caricato**, non è sospetto, anche se è nato il giorno della riunione con la delibera scelta. Resta sospetto il piano con la data scritta a mano, e
     * quello la cui data è stata raccolta in una riunione a mano senza il verbale: la raccolta non prova niente da sola. Il collegamento conta solo se la
     * delibera dice lo stesso giorno del piano, e un'assemblea preparata nel programma ma tenuta su carta vale come una riunione a mano.
     */
    private function deliberaSospetta(int $condominioId): Collection
    {
        return $this->pianiStraordinari($condominioId)
            ->whereNotNull('p.data_delibera_assemblea')
            ->whereNotNull('p.approvato_il')
            ->whereRaw('DATE(p.data_delibera_assemblea) = DATE(p.approvato_il)')
            ->when(Schema::hasTable('delibere_collegamenti'), fn ($q) => $q->whereNotExists(fn ($c) => $c->select(DB::raw(1))
                ->from('delibere_collegamenti as dc')
                ->join('delibere as d', 'd.id', '=', 'dc.delibera_id')
                ->join('assemblee as a', 'a.id', '=', 'd.assemblea_id')
                ->where('dc.oggetto_tipo', 'piano_rate')
                ->where('dc.ruolo', 'delibera')
                ->whereColumn('dc.oggetto_id', 'p.id')
                // La delibera conta solo se dice lo stesso giorno del piano: un piano emesso che tiene la data del clic resta sospetto anche collegato.
                ->whereRaw('DATE(d.deliberata_il) = DATE(p.data_delibera_assemblea)')
                // «Del programma» è la riunione tenuta nella sala del programma; un'assemblea tenuta fuori dal programma è una riunione di carta
                // registrata a mano, e vale con il verbale caricato come le altre.
                ->where(fn ($w) => $w->where(fn ($x) => $x->where('a.origine', 'programma')->where('a.stato', '!=', 'registrata_a_mano'))
                    ->orWhereNotNull('a.verbale_documento_id'))))
            ->orderBy('p.id')->get();
    }

    private function urgenzaSenzaCompetenza(int $condominioId): Collection
    {
        return $this->pianiStraordinari($condominioId)
            ->where('p.tipo_autorizzazione', 'urgenza')
            ->whereNull('p.giorno_dei_lavori')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('piano_rate_fatture as prf')
                ->join('fatture_passive as f', 'f.id', '=', 'prf.fattura_passiva_id')
                ->whereColumn('prf.piano_rate_id', 'p.id')
                ->where(fn ($w) => $w->whereNull('f.competenza_dal')->orWhereNull('f.competenza_al')))
            ->addSelect(DB::raw('(select group_concat(f2.numero_documento) from piano_rate_fatture prf2 join fatture_passive f2 on f2.id = prf2.fattura_passiva_id where prf2.piano_rate_id = p.id and (f2.competenza_dal is null or f2.competenza_al is null)) as fatture'))
            ->orderBy('p.id')->get();
    }
}
