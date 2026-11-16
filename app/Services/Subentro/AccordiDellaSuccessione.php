<?php

namespace App\Services\Subentro;

use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use Carbon\CarbonImmutable;

/**
 * L'accordo di rientro del defunto nella successione con l'arretrato agli eredi. Il debito che l'accordo copre passa agli eredi con l'arretrato, per
 * quota: l'accordo del defunto si chiude «per la successione», il giorno del passaggio (non prima del giorno dell'accordo), e con ogni erede si può
 * registrare un accordo nuovo. Si chiudono gli accordi non chiusi del defunto con una rata coperta su un'unità del passaggio. Il registro del passaggio
 * li tiene, e l'annullamento del passaggio li riapre, se nessuno li ha toccati dopo.
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
     * Chiude gli accordi del defunto e restituisce quello che il registro del passaggio tiene: per ognuno l'id e il giorno della chiusura.
     *
     * @param list<int> $immobileIds
     * @return list<array{id:int, chiuso_il:string}>
     */
    public function chiudi(int $condominioId, int $defuntoId, array $immobileIds, CarbonImmutable $decorrenza, ?int $utenteId): array
    {
        $nome = (string) (Anagrafica::find($defuntoId)?->nome ?? '');
        $chiusi = [];
        foreach (self::daChiudere($condominioId, $defuntoId, $immobileIds) as $a) {
            $giorno = max($decorrenza->format('Y-m-d'), $a->data_accordo->format('Y-m-d'));
            $a->update(['chiuso_il' => $giorno, 'nota_chiusura' => self::nota($nome, $decorrenza), 'chiuso_da' => $utenteId]);
            $chiusi[] = ['id' => (int) $a->id, 'chiuso_il' => $giorno];
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
            $a->update(['chiuso_il' => null, 'nota_chiusura' => null, 'chiuso_da' => null]);
        }
    }
}
