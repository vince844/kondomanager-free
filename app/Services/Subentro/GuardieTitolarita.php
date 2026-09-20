<?php

namespace App\Services\Subentro;

use App\Enums\RuoloAnagraficaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Le due guardie sulle righe di `anagrafica_immobile`, riscritte per il tempo (B2, S5; invarianti 11 e 12
 * del progetto `docs/subentro_e_competenza_temporale.md`). Funzioni pure sulle righe già caricate: le
 * chiamano le FormRequest di «Associa»/«Modifica» (via `ValidatesImmobileAnagraficaPivot`) **e**
 * `RegistraSubentroAction`, che prima di S5 non attraversava nessuna guardia.
 *
 * - **Guardia 1** (inv. 12): la stessa persona può avere due periodi sulla stessa unità — vende e
 *   ricompra — purché **non sovrapposti** con lo stesso ruolo. Prima vietava qualunque seconda riga.
 * - **Guardia 2** (inv. 11): la somma delle quote per ruolo è ≤ 100 **in ogni giorno**, non su tutte le
 *   righe di sempre: un venditore chiuso al 30/04 e un acquirente al 100 % dal 01/05 sommano 200 su una
 *   query piatta e 100 in ogni giorno reale. La somma è una funzione a gradini che sale solo a una
 *   `data_inizio`: basta valutarla a ogni `data_inizio` delle righe della coppia e della riga nuova.
 *
 * «In corso il giorno d» qui è `data_inizio ≤ d ≤ data_fine` — la regola di `TitolaritaImmobile::inCorsoIl()`,
 * **più severa** di D7 (dove `data_inizio` filtra solo con un predecessore chiuso). È voluto: la guardia
 * protegge il dato che si sta scrivendo, e una `data_inizio` scritta oggi è una decorrenza. Non
 * «correggerla» a D7: riammetterebbe il 200 %.
 *
 * Le righe si leggono come oggetti con `id`, `anagrafica_id`, `tipologia`, `quota`, `data_inizio`,
 * `data_fine`, `attivo` (pivot Eloquent o `stdClass`); quelle con `attivo` falso non contano.
 */
final class GuardieTitolarita
{
    private const TOLLERANZA = 0.005;

    /**
     * Guardia 1: la riga nuova si sovrappone a una riga della **stessa persona con lo stesso ruolo**?
     * Restituisce la riga che si sovrappone, o `null`.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte)
     * @param array{anagrafica_id: int, tipologia: string, data_inizio: string|CarbonImmutable, data_fine?: string|CarbonImmutable|null} $nuova
     */
    public static function sovrapposizioneStessaPersona(Collection $righe, array $nuova, ?int $escludiRigaId = null): ?object
    {
        [$dal, $al] = self::estremi($nuova);

        foreach ($righe as $r) {
            if ($escludiRigaId !== null && (int) ($r->id ?? 0) === $escludiRigaId) {
                continue;
            }
            if (! self::attiva($r) || (int) $r->anagrafica_id !== (int) $nuova['anagrafica_id'] || (string) $r->tipologia !== (string) $nuova['tipologia']) {
                continue;
            }
            [$rDal, $rAl] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => $r->data_fine ?? null]);
            if (self::siSovrappongono($dal, $al, $rDal, $rAl)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Guardia 2: con la riga nuova, in quale giorno la somma delle quote del ruolo supera 100?
     * Restituisce `['giorno' => 'Y-m-d', 'somma' => float]` per il primo giorno che sfora, o `null`.
     *
     * @param Collection<int, object> $righe le righe dell'unità (tutte)
     * @param array{tipologia: string, quota: float|string, data_inizio: string|CarbonImmutable, data_fine?: string|CarbonImmutable|null} $nuova
     */
    public static function sforoQuotePerGiorno(Collection $righe, array $nuova, ?int $escludiRigaId = null): ?array
    {
        [$dal, $al] = self::estremi($nuova);
        $coppia = $righe
            ->filter(fn ($r) => self::attiva($r) && (string) $r->tipologia === (string) $nuova['tipologia'])
            ->reject(fn ($r) => $escludiRigaId !== null && (int) ($r->id ?? 0) === $escludiRigaId)
            ->values();

        // I giorni da controllare: la decorrenza della riga nuova e ogni data_inizio della coppia che
        // cade nel periodo della riga nuova (fuori dal suo periodo la riga nuova non somma).
        $giorni = [$dal];
        foreach ($coppia as $r) {
            [$rDal] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => null]);
            if ($rDal->gte($dal) && ($al === null || $rDal->lte($al))) {
                $giorni[] = $rDal;
            }
        }

        foreach ($giorni as $g) {
            $somma = (float) $nuova['quota'];
            foreach ($coppia as $r) {
                [$rDal, $rAl] = self::estremi(['data_inizio' => $r->data_inizio ?? null, 'data_fine' => $r->data_fine ?? null]);
                if ($rDal->lte($g) && ($rAl === null || $rAl->gte($g))) {
                    $somma += (float) $r->quota;
                }
            }
            if ($somma > 100 + self::TOLLERANZA) {
                return ['giorno' => $g->toDateString(), 'somma' => round($somma, 2)];
            }
        }

        return null;
    }

    /** Il messaggio della guardia 2, con il ruolo come lo legge l'amministratore e il giorno dello sforo. */
    public static function messaggioSforo(string $tipologia, array $sforo): string
    {
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom($tipologia)?->label() ?? $tipologia);
        $giorno = CarbonImmutable::parse($sforo['giorno'])->locale('it')->translatedFormat('j F Y');
        $somma = rtrim(rtrim(number_format($sforo['somma'], 2, ',', '.'), '0'), ',');

        return "La somma delle quote per {$ruolo} non può superare 100: il {$giorno} farebbe {$somma}.";
    }

    /** Il messaggio della guardia 1. */
    public static function messaggioSovrapposizione(object $riga): string
    {
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $riga->tipologia)?->label() ?? (string) $riga->tipologia);
        [$dal, $al] = self::estremi(['data_inizio' => $riga->data_inizio ?? null, 'data_fine' => $riga->data_fine ?? null]);
        $periodo = $al === null
            ? 'dal ' . $dal->locale('it')->translatedFormat('j F Y') . ', in corso'
            : 'dal ' . $dal->locale('it')->translatedFormat('j F Y') . ' al ' . $al->locale('it')->translatedFormat('j F Y');

        return "Questa persona è già {$ruolo} di questa unità {$periodo}: i due periodi si sovrappongono. Chiudi quello esistente, o registra un passaggio.";
    }

    /** @return array{0: CarbonImmutable, 1: ?CarbonImmutable} */
    private static function estremi(array $riga): array
    {
        $dal = self::giorno($riga['data_inizio'] ?? null) ?? CarbonImmutable::parse('1900-01-01', 'UTC');
        $al = self::giorno($riga['data_fine'] ?? null);

        return [$dal, $al];
    }

    private static function siSovrappongono(CarbonImmutable $aDal, ?CarbonImmutable $aAl, CarbonImmutable $bDal, ?CarbonImmutable $bAl): bool
    {
        $aFine = $aAl ?? CarbonImmutable::parse('9999-12-31', 'UTC');
        $bFine = $bAl ?? CarbonImmutable::parse('9999-12-31', 'UTC');

        return $aDal->lte($bFine) && $bDal->lte($aFine);
    }

    private static function attiva(object $r): bool
    {
        return ! isset($r->attivo) || (bool) $r->attivo;
    }

    private static function giorno(mixed $valore): ?CarbonImmutable
    {
        if ($valore === null || $valore === '') {
            return null;
        }
        $data = $valore instanceof \DateTimeInterface ? $valore->format('Y-m-d') : substr((string) $valore, 0, 10);

        return CarbonImmutable::parse($data, 'UTC')->startOfDay();
    }
}
