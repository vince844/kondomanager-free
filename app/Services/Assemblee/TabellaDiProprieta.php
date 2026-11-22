<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * R14 del progetto della tappa 2: la tabella di proprietà generale, una **scelta dell'amministratore** per il condominio, la palazzina e la scala
 * (`tabella_proprieta_id`). Nessuna proposta salvata da sola: `tabelle.tipo` è `standard` di serie e non distingue niente. Il controllo **avvisa e non
 * blocca**: «26 unità su 26 · 1.000,00 millesimi», e «l'Int. 14 non ha una quota», «la somma è 998,40», «l'Int. 21 non ha un titolare».
 *
 * I millesimi sono `quote_tabella.valore`, un decimale a cinque cifre (non denaro): si sommano in millesimi di millesimo, interi.
 */
final class TabellaDiProprieta
{
    /** La tabella scelta per il perimetro, o `null`. */
    public static function di(Condominio|Palazzina|Scala $perimetro): ?Tabella
    {
        $id = $perimetro->getAttribute('tabella_proprieta_id');

        return $id === null ? null : Tabella::query()->find($id);
    }

    /**
     * Sceglie (o toglie, con `null`) la tabella di proprietà del perimetro. La tabella deve essere del condominio del perimetro: un id di un altro condominio
     * è un 404 (R18). Il cambio è una riga del registro della scheda («Tabella di proprietà generale: nessuna → A — Proprietà»).
     */
    public static function scegli(Condominio|Palazzina|Scala $perimetro, ?Tabella $tabella): void
    {
        $condominioId = $perimetro instanceof Condominio ? (int) $perimetro->id : (int) $perimetro->getAttribute('condominio_id');
        abort_if($tabella !== null && (int) $tabella->condominio_id !== $condominioId, 404);
        $perimetro->forceFill(['tabella_proprieta_id' => $tabella?->id])->save();
    }

    /**
     * Il controllo della tabella sul perimetro.
     *
     * @return array{frase: string, avvisi: list<string>, unita: int, con_quota: int, somma: string, somma_millesimi: float}
     */
    public static function controllo(Tabella $tabella, Condominio|Palazzina|Scala $perimetro): array
    {
        $unita = self::unitaDelPerimetro($perimetro);
        $quote = DB::table('quote_tabella')->where('tabella_id', $tabella->id)->whereIn('immobile_id', $unita->keys())
            ->pluck('valore', 'immobile_id');
        $avvisi = [];
        $conQuota = 0;
        $somma = 0;
        foreach ($unita as $id => $etichetta) {
            $valore = $quote[$id] ?? null;
            if ($valore === null || (float) $valore == 0.0) {
                $avvisi[] = 'l\'' . $etichetta . ' non ha una quota';
                continue;
            }
            $conQuota++;
            $somma += (int) round(((float) $valore) * 100000);
        }
        $millesimi = $somma / 100000;
        $testoSomma = number_format($millesimi, 2, ',', '.');
        if ($unita->isNotEmpty() && abs($somma - 1000 * 100000) > 1) {
            $avvisi[] = 'la somma è ' . $testoSomma;
        }
        $titolari = DB::table('anagrafica_immobile')->whereIn('immobile_id', $unita->keys())->where('attivo', true)
            ->whereIn('tipologia', array_map(fn ($r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale()))
            ->where(fn ($q) => $q->whereNull('data_fine')->orWhere('data_fine', '>=', DateHelper::oggiUtente()))
            ->pluck('immobile_id')->map(fn ($id) => (int) $id)->unique()->flip();
        foreach ($unita as $id => $etichetta) {
            if (! $titolari->has($id)) {
                $avvisi[] = 'l\'' . $etichetta . ' non ha un titolare';
            }
        }

        return [
            'frase' => $conQuota . ' unità su ' . $unita->count() . ' · ' . $testoSomma . ' millesimi',
            'avvisi' => $avvisi,
            'unita' => $unita->count(),
            'con_quota' => $conQuota,
            'somma' => $testoSomma,
            'somma_millesimi' => $millesimi,
        ];
    }

    /** Le unità del perimetro con la loro etichetta («Int. 14»). @return \Illuminate\Support\Collection<int, string> */
    private static function unitaDelPerimetro(Model $perimetro)
    {
        $query = Immobile::query();
        if ($perimetro instanceof Condominio) {
            $query->where('condominio_id', $perimetro->id);
        } elseif ($perimetro instanceof Palazzina) {
            $query->where('palazzina_id', $perimetro->id);
        } else {
            $query->where('scala_id', $perimetro->id);
        }

        return $query->orderBy('id')->get()->mapWithKeys(fn (Immobile $i) => [(int) $i->id => self::etichetta($i)]);
    }

    private static function etichetta(Immobile $immobile): string
    {
        $interno = trim((string) $immobile->interno);

        return $interno !== '' ? 'Int. ' . $interno : $immobile->etichetta;
    }
}
