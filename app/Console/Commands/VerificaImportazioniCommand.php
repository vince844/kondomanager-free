<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Immobile;
use App\Services\Subentro\GuardieTitolarita;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coda 599, 1.12.0-beta.14: le unità entrate da un'importazione con le quote di un ruolo oltre 100 in un giorno, in tutti i condomini o in uno.
 *
 * Fino alla beta.13 l'importazione scriveva 100 a ogni persona senza quota: due comproprietari dello stesso interno (Danea la quota non la dà mai)
 * entravano a 100 e 100. Il riparto non ne soffre (il programma divide per la somma), ma la scheda dell'unità mostrava 200 e rifiutava ogni correzione.
 * Dalla beta.14 l'importazione divide in parti uguali, e la scheda accetta una correzione che abbassa la somma anche se resta sopra 100.
 *
 * **Solo lettura, sempre**: le quote sono diritti delle persone, e chi possiede quanto lo sa l'amministratore, dall'atto. Il comando dice dove guardare e
 * come correggere dalla scheda; non cambia niente. La somma si guarda giorno per giorno come la guardia delle quote (`GuardieTitolarita`): un venditore
 * chiuso il 30/04 e un acquirente dal 01/05 non sono un 200.
 *
 * Le unità sono quelle che un lotto d'importazione ha creato o trovato (`import_batch_items`); quelle scritte a mano con le quote oltre 100 le elenca
 * `kondomanager:verifica-titolarita`.
 */
class VerificaImportazioniCommand extends Command
{
    protected $signature = 'kondomanager:verifica-importazioni
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le unità entrate da un\'importazione con le quote di un ruolo oltre 100 (sola lettura, non cambia nulla).';

    public function handle(): int
    {
        $this->info('Unità entrate da un\'importazione con le quote di un ruolo oltre 100 — sola lettura, niente viene modificato.');

        $unita = DB::table('import_batch_items as ib')
            ->join('immobili as i', 'i.id', '=', 'ib.importabile_id')
            ->join('condomini as c', 'c.id', '=', 'i.condominio_id')
            ->where('ib.importabile_type', Immobile::class)
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('i.condominio_id', $id))
            ->distinct()
            ->orderBy('i.condominio_id')->orderBy('i.id')
            ->get(['i.id', 'i.nome', 'i.interno', 'i.condominio_id', 'c.nome as condominio']);

        $titolarita = DB::table('anagrafica_immobile as ai')
            ->leftJoin('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->whereIn('ai.immobile_id', $unita->pluck('id')->all())
            ->where('ai.attivo', true)
            ->orderBy('ai.id')
            ->get(['ai.id', 'ai.immobile_id', 'ai.anagrafica_id', 'ai.tipologia', 'ai.quota', 'ai.data_inizio', 'ai.data_fine', 'ai.attivo', 'a.nome as persona'])
            ->groupBy('immobile_id');

        $righe = [];
        $perCondominio = [];
        foreach ($unita as $u) {
            $dellUnita = $titolarita->get($u->id, collect());
            foreach ($dellUnita->groupBy('tipologia') as $ruolo => $delRuolo) {
                $sforo = $this->primoGiornoOltreCento($delRuolo, (string) $ruolo);
                if ($sforo === null) {
                    continue;
                }
                $righe[] = [
                    $u->condominio_id.' — '.$u->condominio,
                    trim(($u->nome ?? 'Unità').($u->interno ? ' (int. '.$u->interno.')' : '')),
                    mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $ruolo)?->label() ?? (string) $ruolo),
                    $delRuolo->map(fn ($r) => ($r->persona ?? '—').' '.$this->quota((float) $r->quota))->implode(', '),
                    CarbonImmutable::parse($sforo['giorno'])->format('d/m/Y'),
                    $this->quota($sforo['somma']),
                ];
                $perCondominio[$u->condominio_id.' — '.$u->condominio][$u->id] = true;
            }
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessuna unità entrata da un\'importazione ha le quote di un ruolo oltre 100.');

            return self::SUCCESS;
        }

        $this->table(['Condominio', 'Unità', 'Ruolo', 'Persone e quote', 'Dal giorno', 'Somma'], $righe);
        foreach ($perCondominio as $condominio => $diQuesto) {
            $this->line("Condominio {$condominio}: ".count($diQuesto).' unità da guardare.');
        }
        $this->line('');
        $this->warn(array_sum(array_map('count', $perCondominio)).' unità con le quote di un ruolo oltre 100. Niente è stato cambiato: chi possiede '
            .'quanto lo dice l\'atto, e la quota la scrivi tu.');
        $this->line('Apri la scheda dell\'unità e correggi ogni persona con «Modifica associazione»: una correzione che abbassa la somma passa anche se '
            .'resta sopra 100, quindi si scende un passo alla volta (il primo da 100 a 50, poi il secondo da 100 a 50). Finché non le correggi, il riparto '
            .'divide per la somma: due persone a 100 e 100 pagano metà ciascuna.');

        return self::SUCCESS;
    }

    /**
     * Il primo giorno in cui le righe di un ruolo sommano oltre 100, con la guardia delle quote: una riga «nuova» a zero che copre tutto il tempo, così la
     * guardia somma solo le righe che ci sono, giorno per giorno.
     *
     * @return array{giorno: string, somma: float}|null
     */
    private function primoGiornoOltreCento($righe, string $ruolo): ?array
    {
        return GuardieTitolarita::sforoQuotePerGiorno($righe->values(), [
            'tipologia' => $ruolo, 'quota' => 0, 'data_inizio' => '1900-01-01', 'data_fine' => null,
        ]);
    }

    private function quota(float $quota): string
    {
        return rtrim(rtrim(number_format($quota, 2, ',', '.'), '0'), ',').' %';
    }
}
