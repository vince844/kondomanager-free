<?php

namespace App\Services\Gestionale\Addebiti;

use App\Enums\RuoloAnagraficaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A chi si può addebitare una somma su un'unità, e chi il programma propone. La scelta è dell'amministratore: il programma propone e spiega perché.
 *
 * Un addebito va a chi è stato titolare di un diritto reale sull'unità (proprietario, nudo proprietario, usufruttuario), oggi o prima: mai a un
 * inquilino, che verso il condominio non è debitore delle spese dell'unità, e mai a chi con l'unità non ha avuto niente.
 *
 * La proposta. Per una quota rimasta senza destinatario in alcuni giorni dell'esercizio: chi era titolare prima di quei giorni (chi vende resta
 * obbligato con chi compra finché il titolo non arriva all'amministratore, art. 63 c. 5 disp. att. c.c.), e accanto chi è stato titolare dopo; per
 * una straordinaria chi era titolare il giorno della delibera; altrimenti chi è titolare oggi. Il rogito o un accordo fra le parti possono dire
 * altro: per questo è una proposta.
 */
final class PersoneDellAddebito
{
    /**
     * Le righe di titolarità di diritto reale dell'unità, di ieri e di oggi, con il nome della persona.
     *
     * @return Collection<int, object{anagrafica_id: int, nome: string, tipologia: string, data_inizio: ?string, data_fine: ?string}>
     */
    public function titolari(int $immobileId): Collection
    {
        $ruoli = array_map(fn (RuoloAnagraficaImmobile $r) => $r->value, RuoloAnagraficaImmobile::titolariDiDirittoReale());

        return DB::table('anagrafica_immobile as ai')->join('anagrafiche as a', 'a.id', '=', 'ai.anagrafica_id')
            ->where('ai.immobile_id', $immobileId)->whereIn('ai.tipologia', $ruoli)
            // Come il riparto (`RisolutoreTitolari::vincolaQuery`): una riga spenta non è un titolare.
            ->where('ai.attivo', true)
            ->orderBy('ai.data_inizio')->orderBy('ai.id')
            ->get(['ai.anagrafica_id', 'a.nome', 'ai.tipologia', 'ai.data_inizio', 'ai.data_fine'])
            ->map(function ($r) {
                $r->anagrafica_id = (int) $r->anagrafica_id;
                $r->data_inizio = $r->data_inizio === null ? null : substr((string) $r->data_inizio, 0, 10);
                $r->data_fine = $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10);

                return $r;
            });
    }

    /** La persona è, o è stata, titolare di un diritto reale sull'unità. */
    public function puoRicevere(int $anagraficaId, int $immobileId): bool
    {
        return $this->titolari($immobileId)->contains(fn ($r) => $r->anagrafica_id === $anagraficaId);
    }

    /**
     * Le persone a cui l'addebito può andare, la proposta per prima, ognuna una volta, con il perché.
     *
     * @param string|null $dal  il primo giorno del periodo in cui l'unità può essere rimasta senza titolare (l'esercizio della quota)
     * @param string|null $al   l'ultimo
     * @param string|null $delibera il giorno della delibera, per una straordinaria
     * @return list<array{anagrafica_id: int, nome: string, perche: string}>
     */
    public function proposte(int $immobileId, ?string $dal, ?string $al, ?string $delibera, string $oggi): array
    {
        $titolari = $this->titolari($immobileId);
        $inCorso = fn ($r, string $giorno) => ($r->data_inizio === null || $r->data_inizio <= $giorno) && ($r->data_fine === null || $r->data_fine >= $giorno);
        $data = fn (string $g) => CarbonImmutable::parse($g)->format('d/m/Y');

        $esito = [];
        $aggiungi = function ($r, string $perche) use (&$esito) {
            if ($r !== null && ! isset($esito[$r->anagrafica_id])) {
                $esito[$r->anagrafica_id] = ['anagrafica_id' => $r->anagrafica_id, 'nome' => (string) $r->nome, 'perche' => $perche];
            }
        };

        if ($delibera !== null) {
            foreach ($titolari->filter(fn ($r) => $inCorso($r, $delibera)) as $r) {
                $aggiungi($r, 'titolare il giorno della delibera, ' . $data($delibera));
            }
        } elseif ($dal !== null && $al !== null && ($vuoto = $this->primoGiornoSenzaTitolare($titolari, $dal, $al)) !== null) {
            $prima = $titolari->filter(fn ($r) => $r->data_fine !== null && $r->data_fine < $vuoto)->sortBy('data_fine')->last();
            $dopo = $titolari->filter(fn ($r) => $r->data_inizio !== null && $r->data_inizio > $vuoto)->sortBy('data_inizio')->first();
            $aggiungi($prima, 'titolare fino al ' . ($prima?->data_fine === null ? '' : $data($prima->data_fine)) . ', prima dei giorni senza titolare');
            $aggiungi($dopo, 'titolare dal ' . ($dopo?->data_inizio === null ? '' : $data($dopo->data_inizio)) . ', dopo i giorni senza titolare');
        }
        foreach ($titolari->filter(fn ($r) => $inCorso($r, $oggi)) as $r) {
            $aggiungi($r, 'titolare oggi');
        }
        foreach ($titolari as $r) {
            $aggiungi($r, 'è stato titolare' . ($r->data_inizio === null ? '' : ' dal ' . $data($r->data_inizio)) . ($r->data_fine === null ? '' : ' al ' . $data($r->data_fine)));
        }

        return array_values($esito);
    }

    /** Il primo giorno fra `$dal` e `$al` in cui nessuna riga di diritto reale è in corso; null se l'unità ha sempre un titolare. */
    private function primoGiornoSenzaTitolare(Collection $titolari, string $dal, string $al): ?string
    {
        $candidati = [$dal];
        foreach ($titolari as $r) {
            if ($r->data_fine !== null) {
                $candidati[] = CarbonImmutable::parse($r->data_fine)->addDay()->format('Y-m-d');
            }
        }
        sort($candidati);
        foreach ($candidati as $giorno) {
            if ($giorno < $dal || $giorno > $al) {
                continue;
            }
            $coperto = $titolari->contains(fn ($r) => ($r->data_inizio === null || $r->data_inizio <= $giorno) && ($r->data_fine === null || $r->data_fine >= $giorno));
            if (! $coperto) {
                return $giorno;
            }
        }

        return null;
    }
}
