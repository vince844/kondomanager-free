<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Subentro;

use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use Carbon\CarbonImmutable;

/**
 * L'accordo di rientro del defunto nella successione con l'arretrato agli eredi. Il debito che l'accordo copre passa agli eredi con l'arretrato, per
 * quota: l'accordo del defunto si chiude «per la successione», il giorno del passaggio (non prima del giorno dell'accordo), e con ogni erede si può
 * registrare un accordo nuovo. Si chiudono gli accordi non chiusi del defunto con una rata coperta su un'unità del passaggio. Il registro del passaggio
 * li tiene, e l'annullamento del passaggio li riapre, se nessuno li ha toccati dopo.
 *
 * Per gli interessi del defunto (decisione 454) l'accordo che reggeva resta in regola fino al decesso, di serie: i giorni dell'accordo restano fuori dalla
 * sua proposta, e dal decesso non ne maturano altri. L'amministratore può scegliere nell'anteprima che la chiusura valga come una decadenza: tornano i giorni
 * dell'accordo. La scelta sta sull'accordo (`chiusura_per_successione`) e nel registro del passaggio.
 */
final class AccordiDellaSuccessione
{
    /**
     * Gli accordi del defunto da chiudere: non chiusi, con una rata coperta su una delle unità.
     *
     * @param list<int> $immobileIds
     * @return \Illuminate\Support\Collection<int, AccordoRientro>
     */
    public static function daChiudere(int $condominioId, int $defuntoId, array $immobileIds): \Illuminate\Support\Collection
    {
        return AccordoRientro::where('condominio_id', $condominioId)->where('anagrafica_id', $defuntoId)->whereNull('chiuso_il')
            ->whereHas('rate', fn ($q) => $q->whereIn('immobile_id', $immobileIds ?: [0]))
            ->orderBy('data_accordo')->orderBy('id')->get();
    }

    /** La nota della chiusura: perché l'accordo è chiuso, e che cosa si può fare. */
    public static function nota(string $defunto, CarbonImmutable $decorrenza): string
    {
        return sprintf('Per la successione di %s, dal %s: il debito che resta passa agli eredi con l\'arretrato, per quota, e con ognuno si può registrare un accordo nuovo.',
            $defunto, $decorrenza->format('d/m/Y'));
    }

    /**
     * Gli accordi del defunto che la successione chiude, per l'anteprima: per ognuno il giorno e se reggeva il giorno prima del decesso, quando la scelta
     * conta. Un accordo che non reggeva più (il calendario finito con rate non versate) non tiene fuori niente comunque.
     *
     * @param list<int> $immobileIds
     * @return list<array{id:int, data_accordo:string, in_regola:bool}>
     */
    public static function perLAnteprima(int $condominioId, int $defuntoId, array $immobileIds, CarbonImmutable $decorrenza): array
    {
        $regola = app(\App\Services\Gestionale\Debiti\AccordiRientro::class);

        return self::daChiudere($condominioId, $defuntoId, $immobileIds)->load(['rate', 'scadenze'])->map(fn (AccordoRientro $a) => [
            'id' => (int) $a->id,
            'data_accordo' => $a->data_accordo->format('Y-m-d'),
            'in_regola' => $regola->reggevaIl($a, max($a->data_accordo->format('Y-m-d'), $decorrenza->subDay()->format('Y-m-d'))),
        ])->values()->all();
    }

    /**
     * Chiude gli accordi del defunto e restituisce quello che il registro del passaggio tiene: per ognuno l'id, il giorno della chiusura e la scelta per gli
     * interessi (`in_regola`, di serie, o `decadenza`).
     *
     * @param list<int> $immobileIds
     * @return list<array{id:int, chiuso_il:string, scelta:string}>
     */
    public function chiudi(int $condominioId, int $defuntoId, array $immobileIds, CarbonImmutable $decorrenza, ?int $utenteId, string $scelta = AccordoRientro::SUCCESSIONE_IN_REGOLA): array
    {
        $scelta = $scelta === AccordoRientro::SUCCESSIONE_DECADENZA ? AccordoRientro::SUCCESSIONE_DECADENZA : AccordoRientro::SUCCESSIONE_IN_REGOLA;
        $nome = (string) (Anagrafica::find($defuntoId)?->nome ?? '');
        $chiusi = [];
        foreach (self::daChiudere($condominioId, $defuntoId, $immobileIds) as $a) {
            $giorno = max($decorrenza->format('Y-m-d'), $a->data_accordo->format('Y-m-d'));
            $a->update(['chiuso_il' => $giorno, 'nota_chiusura' => self::nota($nome, $decorrenza), 'chiuso_da' => $utenteId, 'chiusura_per_successione' => $scelta]);
            $chiusi[] = ['id' => (int) $a->id, 'chiuso_il' => $giorno, 'scelta' => $scelta];
        }

        return $chiusi;
    }

    /**
     * Riapre gli accordi chiusi dal passaggio annullato: solo quelli ancora chiusi quel giorno e senza una decadenza registrata dopo.
     *
     * @param list<array{id:int, chiuso_il:string}> $chiusi
     */
    public static function riapri(array $chiusi): void
    {
        foreach ($chiusi as $c) {
            $a = AccordoRientro::find((int) $c['id']);
            if ($a === null || $a->decadenza_registrata_il !== null || $a->chiuso_il?->format('Y-m-d') !== $c['chiuso_il']) {
                continue;
            }
            $a->update(['chiuso_il' => null, 'nota_chiusura' => null, 'chiuso_da' => null, 'chiusura_per_successione' => null]);
        }
    }
}
