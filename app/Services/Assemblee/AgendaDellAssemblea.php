<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\CategoriaEventoEnum;
use App\Enums\VisibilityStatus;
use App\Models\Assemblee\Assemblea;
use App\Models\CategoriaEvento;
use App\Models\Evento;

/**
 * L'agenda dell'assemblea (§4.6 del progetto della tappa 2): una voce per ogni seduta, categoria «Assemblea», con l'assemblea come `eventable`, con
 * `Evento::create` diretto (l'unica strada che porta `eventable` è un compito), **nascosta ai condòmini** e approvata: nel portale non va (decisione 452
 * (4)). L'ora è quella della seduta, «come si legge». Un'assemblea annullata, o una riunione registrata a mano, non ha voci. La potatura di un anno le toglie;
 * l'assemblea resta. Ciò che il programma crea da solo non va nel registro (decisione 547 (2)).
 */
final class AgendaDellAssemblea
{
    /** Quanto dura la voce nell'agenda: due ore, come una riunione. */
    public const ORE = 2;

    public static function sincronizza(Assemblea $assemblea): void
    {
        $voci = Evento::query()->where('eventable_type', $assemblea->getMorphClass())->where('eventable_id', $assemblea->id)->get();
        $daTenere = $assemblea->eAMano() || $assemblea->stato === \App\Enums\Assemblee\StatoAssemblea::ANNULLATA
            || $assemblea->stato === \App\Enums\Assemblee\StatoAssemblea::REGISTRATA_A_MANO ? collect() : $assemblea->sedute()->get();
        $categoria = null;
        $nome = $assemblea->nome();
        $tenute = [];
        foreach ($daTenere as $seduta) {
            $voce = $voci->first(fn (Evento $e) => (int) (($e->meta ?? [])['seduta_id'] ?? 0) === (int) $seduta->id);
            $inizio = \Illuminate\Support\Carbon::parse($seduta->getAttributes()['fissata_il']);
            $titolo = $nome . ' — ' . ($seduta->convocazione?->value === 'seconda' ? 'seconda convocazione' : 'prima convocazione')
                . ($seduta->numero > 1 ? ', prosecuzione' : '');
            $dati = ['title' => $titolo, 'start_time' => $inizio, 'end_time' => $inizio->copy()->addHours(self::ORE),
                'description' => trim(($seduta->luogo ?: $assemblea->luogo) ?? '')];
            if ($voce === null) {
                $categoria ??= CategoriaEvento::firstOrCreate(['name' => CategoriaEventoEnum::ASSEMBLEA->value],
                    ['description' => CategoriaEventoEnum::ASSEMBLEA->description()]);
                $voce = Evento::create($dati + [
                    'created_by' => null, 'category_id' => $categoria->id, 'visibility' => VisibilityStatus::HIDDEN->value, 'is_approved' => true,
                    'meta' => ['seduta_id' => (int) $seduta->id, 'requires_action' => false, 'assemblea_id' => (int) $assemblea->id],
                    'eventable_type' => $assemblea->getMorphClass(), 'eventable_id' => $assemblea->id,
                ]);
                $voce->condomini()->syncWithoutDetaching([(int) $assemblea->condominio_id]);
            } else {
                $voce->update($dati);
            }
            $tenute[] = (int) $voce->id;
        }
        foreach ($voci as $voce) {
            if (! in_array((int) $voce->id, $tenute, true)) {
                $voce->delete();
            }
        }
    }
}
