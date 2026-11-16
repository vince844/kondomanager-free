<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Models\Esercizio;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La cassa all'inizio dell'esercizio: il riporto del registro di contabilità e la disponibilità iniziale del prospetto per cassa, che sono lo stesso fatto
 * e hanno una regola sola.
 *
 * LA REGOLA. Per ogni cassa: la colonna `saldo_iniziale` (l'apertura non ancora a giornale) più le righe di quella cassa nelle scritture degli esercizi
 * del condominio che cominciano prima di questo. Se l'esercizio ha la sua scrittura di apertura (tipo `apertura`) su quella cassa, il riporto è quella, e
 * gli esercizi prima non si sommano: così l'apertura che la chiusura dell'esercizio scriverà non si conta due volte. L'apertura non è mai un'entrata.
 *
 * PER ESERCIZIO, NON PER DATA. Ogni movimento si conta una volta: un incasso del 28/12 scritto nell'esercizio dopo entra nel registro dell'anno dopo e non
 * nel riporto; un F24 di gennaio scritto nell'esercizio prima è già nel riporto. La situazione patrimoniale resta per data: è lì che si legge che cosa
 * c'era sul conto il 31/12, e la differenza fra le due letture la dice il controllo dell'anno a cavallo.
 *
 * DUE FORME, LO STESSO TOTALE. Per cassa (`perConto`, il prospetto per cassa): ogni cassa, fondi compresi, sul suo conto contabile. Per cassa reale con le
 * sue partizioni (`perCassaReale`, il registro): banca e contanti, senza la riga di un accantonamento verso un fondo, che il registro non conta perché il
 * fondo è una partizione della stessa banca (la banca del registro ha il fondo dentro). Con un conto in banca solo, anche il denaro dei fondi che non è
 * passato dalla banca (un fondo aperto con un saldo suo) è nella banca del registro, come nella scheda «Cassa»; le casse virtuali restano fuori.
 */
final class RiportoDiCassa
{
    /** Le casse di liquidità reale. */
    public const CASSE_REALI = ['banca', 'contanti'];

    /** Le casse che sono partizioni dell'unico conto reale, non denaro proprio. */
    public const CASSE_PARTIZIONE = ['fondo', 'virtuale'];

    /**
     * La forma del prospetto per cassa: per conto contabile delle casse del condominio, le sole righe del giornale (la colonna `saldo_iniziale` la somma
     * chi legge, cassa per cassa, come fa già il prospetto).
     *
     * @return array<int,int>
     */
    public function perConto(Esercizio $esercizio): array
    {
        $conti = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->whereNotNull('conto_contabile_id')
            ->pluck('conto_contabile_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($conti === []) {
            return [];
        }

        return $this->delRiporto($esercizio, $this->righe()->whereIn('rs.conto_contabile_id', $conti))
            ->groupBy('rs.conto_contabile_id')
            ->select('rs.conto_contabile_id', DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as netto"))
            ->pluck('netto', 'conto_contabile_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * La forma del registro: per ogni cassa reale con un riporto, la colonna più le righe con quella cassa (`rs.cassa_id`, il perimetro del registro),
     * senza le righe degli accantonamenti verso una partizione; più, con un conto in banca solo, il denaro dei fondi che non è passato da quella cassa
     * e c'era il primo giorno (il saldo iniziale di un fondo, un fondo accantonato da una banca poi chiusa: {@see FondiNelConto::partiFuoriDalRegistro()}),
     * così il registro conta come la scheda «Cassa». `parti` le dice tutte, per cassa, anche quelle nate nell'esercizio.
     *
     * @return array{totale:int, per_cassa: list<array{cassa_id:int, cassa:string, riporto:int, da_apertura:bool, parti_iniziali:list<array<string,mixed>>}>, parti: array<int, list<array<string,mixed>>>}
     */
    public function perCassaReale(Esercizio $esercizio): array
    {
        $casse = DB::table('casse')->where('condominio_id', $esercizio->condominio_id)->whereIn('tipo', self::CASSE_REALI)
            ->orderBy('id')->get(['id', 'nome', 'conto_contabile_id', 'saldo_iniziale']);
        if ($casse->isEmpty()) {
            return ['totale' => 0, 'per_cassa' => [], 'parti' => []];
        }
        $parti = app(FondiNelConto::class)->partiFuoriDalRegistro($esercizio);

        $somme = self::senzaAccantonamenti($this->delRiporto($esercizio, $this->righe()->whereIn('rs.cassa_id', $casse->pluck('id')->all())))
            ->groupBy('rs.cassa_id')
            ->select('rs.cassa_id', DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as netto"))
            ->pluck('netto', 'cassa_id');
        $conApertura = $this->contiConApertura($esercizio);

        $perCassa = [];
        foreach ($casse as $c) {
            $iniziali = array_values(array_filter($parti[(int) $c->id] ?? [], fn ($p) => $p['quando'] === 'inizio'));
            $riporto = (int) $c->saldo_iniziale + (int) ($somme[$c->id] ?? 0) + (int) array_sum(array_column($iniziali, 'importo'));
            if ($riporto !== 0) {
                $perCassa[] = ['cassa_id' => (int) $c->id, 'cassa' => (string) $c->nome, 'riporto' => $riporto,
                    'da_apertura' => in_array((int) $c->conto_contabile_id, $conApertura, true), 'parti_iniziali' => FondiNelConto::unite($iniziali)];
            }
        }

        return ['totale' => (int) array_sum(array_column($perCassa, 'riporto')), 'per_cassa' => $perCassa, 'parti' => $parti];
    }

    /**
     * Le righe del riporto di alcuni conti, registrazione per registrazione, con lo stesso perimetro di {@see perConto()}: la scheda «Cassa» ne legge
     * le partizioni, per dire da quale banca viene il loro riporto quando i conti in banca sono più d'uno.
     *
     * @param  list<int>  $conti
     * @return list<array{scrittura_id:int, conto:int, netto:int}>
     */
    public function perScrittura(Esercizio $esercizio, array $conti): array
    {
        if ($conti === []) {
            return [];
        }

        return $this->delRiporto($esercizio, $this->righe()->whereIn('rs.conto_contabile_id', $conti))
            ->groupBy('rs.scrittura_id', 'rs.conto_contabile_id')
            ->select('rs.scrittura_id', 'rs.conto_contabile_id', DB::raw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as netto"))
            ->get()->map(fn ($r) => ['scrittura_id' => (int) $r->scrittura_id, 'conto' => (int) $r->conto_contabile_id, 'netto' => (int) $r->netto])->all();
    }

    /**
     * Toglie le righe di cassa reale di una scrittura che ha una riga sorella su una partizione (l'accantonamento banca→fondo): non sono né entrate né
     * uscite, è la banca che parla con sé stessa (D15 di `docs/registri_contabili.md`). Il registro di contabilità usa lo stesso filtro.
     */
    public static function senzaAccantonamenti(Builder $query): Builder
    {
        return $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('righe_scritture as rs2')
                ->join('casse as ca2', 'ca2.id', '=', 'rs2.cassa_id')
                ->whereColumn('rs2.scrittura_id', 'rs.scrittura_id')
                ->whereColumn('rs2.id', '!=', 'rs.id')
                ->whereIn('ca2.tipo', self::CASSE_PARTIZIONE);
        });
    }

    private function righe(): Builder
    {
        return DB::table('righe_scritture as rs')
            ->join('scritture_contabili as sc', fn ($j) => $j->on('rs.scrittura_id', '=', 'sc.id')->whereNull('sc.deleted_at'));
    }

    /**
     * Le scritture che fanno il riporto: per i conti con un'apertura in questo esercizio, quell'apertura; per gli altri, gli esercizi che cominciano prima.
     */
    private function delRiporto(Esercizio $esercizio, Builder $query): Builder
    {
        $precedenti = DB::table('esercizi')->where('condominio_id', $esercizio->condominio_id)
            ->where('data_inizio', '<', $esercizio->data_inizio->format('Y-m-d'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $conApertura = $this->contiConApertura($esercizio);

        return $query->where(function ($q) use ($esercizio, $precedenti, $conApertura) {
            $q->where(fn ($a) => $a->where('sc.esercizio_id', $esercizio->id)->where('sc.tipo_movimento', 'apertura')->whereIn('rs.conto_contabile_id', $conApertura === [] ? [0] : $conApertura))
                ->orWhere(fn ($b) => $b->whereIn('sc.esercizio_id', $precedenti === [] ? [0] : $precedenti)->whereNotIn('rs.conto_contabile_id', $conApertura === [] ? [0] : $conApertura));
        });
    }

    /** @return list<int> i conti contabili con una riga in una scrittura di apertura di questo esercizio. */
    private function contiConApertura(Esercizio $esercizio): array
    {
        return $this->righe()->where('sc.esercizio_id', $esercizio->id)->where('sc.tipo_movimento', 'apertura')
            ->distinct()->pluck('rs.conto_contabile_id')->map(fn ($id) => (int) $id)->all();
    }
}
