<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use App\Helpers\MoneyHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Coda 754 (5), 1.12.0-beta.14: le fatture del debito dell'esercizio precedente scritte dal codice di prima con la scelta di partenza della finestra
 * «Configura nuova voce di spesa» («Intervento d'urgenza»), in tutti i condomini o in uno:
 *
 * - **da ratificare con lo sforo a € 0,00**: la pagina mandava la giustificazione dello sforo con la parte comune delle righe «Fuori preventivo», che la
 *   pregressa non ha, e la fattura restava in attesa della ratifica dell'assemblea (prova G6);
 * - **con una copertura dal fondo da € 0,00**: con l'urgenza e «Usa fondo riserva» nasceva una copertura vuota, e il fondo non copriva niente (prova G5).
 *
 * **Solo lettura, sempre**: elenca e non cambia niente. Una pregressa non si modifica, si storna: che cosa farne lo decide l'amministratore, fattura per
 * fattura. Le pregresse registrate con la delibera e «Usa fondo riserva» non si trovano: la scelta della finestra non era salvata (Coda 754 (4)).
 */
class VerificaPregresseCommand extends Command
{
    protected $signature = 'kondomanager:verifica-pregresse
                            {--condominio= : ID del condominio (omesso = tutti)}';

    protected $description = 'Elenca le fatture pregresse da ratificare con lo sforo a € 0,00 e quelle con una copertura dal fondo da € 0,00 (sola lettura, non cambia nulla).';

    public function handle(): int
    {
        $this->info('Fatture del debito dell\'esercizio precedente scritte con la scelta di partenza della finestra — sola lettura, niente viene modificato.');

        $pregresse = DB::table('fatture_passive as f')
            ->join('condomini as c', 'c.id', '=', 'f.condominio_id')
            ->leftJoin('fornitori as fo', 'fo.id', '=', 'f.fornitore_id')
            ->where('f.is_pregresso', true)
            ->where('f.tipo_documento', 'fattura')
            ->where('f.stato_pagamento', '!=', 'stornata')
            ->when($this->option('condominio'), fn ($q, $id) => $q->where('f.condominio_id', $id))
            ->orderBy('f.condominio_id')->orderBy('f.id')
            ->get(['f.id', 'f.condominio_id', 'c.nome as condominio', 'f.numero_documento', 'f.data_documento', 'f.totale_documento', 'f.stato_approvazione',
                'f.dati_extra', 'fo.ragione_sociale as fornitore']);

        $fondiVuoti = DB::table('fattura_coperture')->whereIn('fattura_passiva_id', $pregresse->pluck('id')->all())
            ->where('tipo_copertura', 'fondo_riserva')->where('importo', 0)
            ->pluck('fattura_passiva_id')->map(fn ($id) => (int) $id)->unique()->all();

        $righe = [];
        $perCondominio = [];
        foreach ($pregresse as $f) {
            $extra = is_string($f->dati_extra) ? (json_decode($f->dati_extra, true) ?? []) : (array) $f->dati_extra;
            $che = [];
            if ($f->stato_approvazione === 'sforo_motivato' && (int) ($extra['override_budget']['importo_sforo'] ?? 0) <= 0) {
                $che[] = 'Da ratificare con lo sforo a € 0,00';
            }
            if (in_array((int) $f->id, $fondiVuoti, true)) {
                $che[] = 'Copertura dal fondo da € 0,00';
            }
            if ($che === []) {
                continue;
            }
            $righe[] = [
                $f->condominio_id.' — '.$f->condominio, 'n. '.$f->numero_documento, (string) ($f->fornitore ?? '—'), substr((string) $f->data_documento, 0, 10),
                MoneyHelper::format(abs((int) $f->totale_documento)), implode('; ', $che),
            ];
            $perCondominio[$f->condominio_id.' — '.$f->condominio] = ($perCondominio[$f->condominio_id.' — '.$f->condominio] ?? 0) + 1;
        }

        $this->line('');
        if ($righe === []) {
            $this->info('Nessuna pregressa da ratificare con lo sforo a € 0,00 né con una copertura dal fondo da € 0,00.');
            $this->fraseDiCioCheNonSiTrova();

            return self::SUCCESS;
        }

        $this->table(['Condominio', 'Fattura', 'Fornitore', 'Data', 'Totale', 'Che cosa'], $righe);
        foreach ($perCondominio as $condominio => $quante) {
            $this->line("Condominio {$condominio}: {$quante} ".($quante === 1 ? 'pregressa' : 'pregresse').'.');
        }
        $this->line('');
        $this->warn(count($righe).' '.(count($righe) === 1 ? 'pregressa' : 'pregresse').'. Niente è stato cambiato: una pregressa non si modifica, si storna, e la '
            .'scelta è tua, fattura per fattura.');
        $this->line('Da ratificare con lo sforo a € 0,00: se l\'incarico era stato deciso dall\'assemblea, la fattura non va ratificata: storna la fattura e '
            .'registrala di nuovo con l\'origine «Delibera assembleare». Se era davvero un intervento d\'urgenza, la ratifica è giusta e la proposta per '
            .'l\'assemblea dice già il totale della fattura.');
        $this->line('Copertura dal fondo da € 0,00: il fondo non copre niente. Se volevi coprire la spesa con il fondo, storna la fattura e registrala di nuovo '
            .'con «Usa fondo riserva»: il programma prepara la copertura, da confermare con il giroconto.');
        $this->fraseDiCioCheNonSiTrova();

        return self::SUCCESS;
    }

    private function fraseDiCioCheNonSiTrova(): void
    {
        $this->line('Le pregresse registrate con la delibera e «Usa fondo riserva» da una versione che non salvava quella scelta non si trovano: la fattura '
            .'sembra una pregressa con l\'attesa del conguaglio. Se ne ricordi una, il fondo non la copre: storna la fattura e registrala di nuovo.');
    }
}
