<?php

namespace App\Services\Riparto;

use App\Enums\RuoloAnagraficaImmobile;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Support\Collection;

/**
 * L'unità mista — Coda 170, decisione 31 (1.11.0-beta.41).
 *
 * Un'unità in parte in piena proprietà e in parte in nuda proprietà più usufrutto: Bice proprietaria piena al 50 %, e
 * sull'altra metà Ugo usufruttuario al 50 % ed Elsa nuda proprietaria al 50 %. Fino alla beta.40 il motore divideva
 * ogni spesa sulle sole quote del ruolo che trovava: con la voce sul «Proprietario» trovava solo Bice, e il suo 50 %
 * diventava il 100 % dell'unità. Lo stesso nell'addebito diretto, nel ripiego sui giorni scoperti e nei saldi pregressi.
 *
 * **La regola (31.1).** Fra il proprietario pieno e l'altra parte decidono le quote registrate: ogni comproprietario
 * contribuisce secondo la sua quota, ed è la legge, che il programma applica. Fra usufruttuario e nudo proprietario
 * della stessa parte decide la voce, cioè l'amministratore. In pratica: il ruolo che paga si unisce al suo gemello
 * ({@see RuoloAnagraficaImmobile::gemelloNellUnita()}) e la spesa si divide sulle quote di tutti e due.
 *
 * **Quando vale, e perché le due condizioni.**
 *
 * 1. **Ruolo e gemello sono in vigore insieme in almeno un giorno.** Se uno finisce quando l'altro comincia (Ugo
 *    proprietario fino al 30/04, Elsa nuda proprietaria dal 1/05) non è un'unità mista: è un passaggio, e i giorni
 *    li divide già la cascata sui giorni scoperti (decisione 22). Toccarlo cambierebbe un caso che oggi è giusto.
 * 2. **In ogni tratto in cui qualcuno dell'insieme è in vigore, le quote fanno 100.** Con quote che non tornano — il
 *    nudo proprietario scritto al 30, o tutti scritti al 100 — il dato è incompleto o contraddittorio, e non si
 *    indovina: resta la regola della Coda 58 (chi è registrato sul ruolo paga la quota dell'unità), che è ciò che fa
 *    il motore quando questa classe risponde «nessuno».
 *
 * Restituisce solo **chi aggiungere**: chi lo usa unisce le righe e lascia al suo calcolo (quote, giorni, ripiego) il
 * resto, così un'unità che non è mista passa per lo stesso identico codice di prima.
 */
final class UnitaMista
{
    /**
     * Le quote sono `decimal` a due cifre: un terzo per tre si scrive 33,33 e fa 99,99. Si confronta in centesimi di
     * punto — in virgola mobile 33,33 × 3 è 99,98999… e lo scarto da 100 supera 0,01 (rilievo D3 della Fase 1-bis della
     * beta.41). Restano fuori i sesti (16,67 × 6 = 100,02) e i settimi: la regola della Coda 58, detto nel test.
     */
    private const TOLLERANZA_CENTESIMI = 1;

    /** @param iterable<int|float|string> $quote */
    private static function fannoCento(iterable $quote): bool
    {
        $centesimi = 0;
        foreach ($quote as $q) {
            $centesimi += (int) round((float) $q * 100);
        }

        return abs($centesimi - 10000) <= self::TOLLERANZA_CENTESIMI;
    }

    public function __construct(private readonly RisolutoreTitolari $titolari)
    {
    }

    /**
     * Le righe del ruolo gemello da unire al ruolo che paga, o una collection vuota se l'unità non è mista o se le quote
     * non tornano.
     *
     * Le righe sono quelle della pivot `anagrafica_immobile` (pivot Eloquent o `stdClass`): si leggono `tipologia`,
     * `quota`, `attivo`, `data_inizio`, `data_fine`, `id`, `immobile_id`.
     *
     * @param Collection<int, object> $righeUnita tutte le righe dell'unità, anche fuori periodo (servono a D7, il predecessore)
     * @param Collection<int, object> $righeRuolo le righe del ruolo risolto che partecipano
     * @param PeriodoCompetenza|InsiemePeriodi|null $periodo nullo nel calcolo atemporale: tutte le righe attive valgono sempre
     * @return Collection<int, object>
     */
    public function righeGemelle(Collection $righeUnita, Collection $righeRuolo, string $ruoloRisolto, PeriodoCompetenza|InsiemePeriodi|null $periodo): Collection
    {
        $gemello = RuoloAnagraficaImmobile::gemelloNellUnita($ruoloRisolto);
        $righeRuolo = $righeRuolo->filter(fn ($r) => (float) ($r->quota ?? 0) > 0.0)->values();
        if ($gemello === null || $righeRuolo->isEmpty()) {
            return collect();
        }

        $attive = $righeUnita->filter(fn ($r) => (bool) ($r->attivo ?? false) && (float) ($r->quota ?? 0) > 0.0)->values();
        $righeGemello = $attive->filter(fn ($r) => ($r->tipologia ?? null) === $gemello->value)->values();
        if ($righeGemello->isEmpty()) {
            return collect();
        }

        if ($periodo === null) {
            // Atemporale: ogni riga attiva vale sempre, quindi ruolo e gemello sono in vigore insieme per costruzione.
            return self::fannoCento($righeRuolo->concat($righeGemello)->map(fn ($r) => $r->quota)) ? $righeGemello : collect();
        }

        $insieme = $periodo instanceof InsiemePeriodi ? $periodo : InsiemePeriodi::uno($periodo);

        // Il tratto in cui ogni riga vale nel periodo (D7: la data d'inizio conta solo con un predecessore della stessa
        // tipologia, quindi la coppia per il predecessore è quella della riga, non l'insieme).
        $tratti = [];
        foreach ([[$ruoloRisolto, $righeRuolo], [$gemello->value, $righeGemello]] as [$ruolo, $righe]) {
            $stessaCoppia = $attive->filter(fn ($r) => ($r->tipologia ?? null) === $ruolo)->values();
            foreach ($righe as $riga) {
                $tratto = $this->titolari->trattoEffettivo($riga, $insieme, $stessaCoppia);
                if ($tratto !== null) {
                    $tratti[] = ['dal' => $tratto->dal, 'al' => $tratto->al, 'quota' => (float) $riga->quota, 'ruolo' => $ruolo, 'riga' => $riga];
                }
            }
        }

        $gemelleInVigore = collect($tratti)->where('ruolo', $gemello->value)->pluck('riga')->values();
        if ($gemelleInVigore->isEmpty()) {
            return collect();
        }

        // Si scorrono i tratti elementari: fra due confini consecutivi le righe in vigore non cambiano.
        $confini = [];
        foreach ($tratti as $t) {
            $confini[$t['dal']->toDateString()] = $t['dal'];
            $confini[$t['al']->addDay()->toDateString()] = $t['al']->addDay();
        }
        ksort($confini);
        $confini = array_values($confini);

        $mista = false;
        for ($i = 0; $i < count($confini) - 1; $i++) {
            $giorno = $confini[$i];
            $inVigore = array_filter($tratti, fn ($t) => $t['dal']->lte($giorno) && $t['al']->gte($giorno));
            if ($inVigore === []) {
                continue;
            }
            if (! self::fannoCento(array_column($inVigore, 'quota'))) {
                return collect();
            }
            if (count(array_unique(array_column($inVigore, 'ruolo'))) === 2) {
                $mista = true;
            }
        }

        return $mista ? $gemelleInVigore : collect();
    }
}
