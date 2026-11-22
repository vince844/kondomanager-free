<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use Carbon\CarbonImmutable;

/**
 * R11 del progetto della tappa 2: le date dell'avviso. La seconda convocazione in un giorno di calendario **diverso e successivo** alla prima, **non oltre
 * dieci giorni**: art. 1136 c. 3 c.c. («in un giorno successivo … non oltre dieci giorni») e la frase dell'art. 66 disp. att. «non può tenersi nel medesimo
 * giorno solare della prima» (legge §1 e §3; la regola sta sul testo, non sulla Cass. 22685/2014 a fonte sola). Le date di prosecuzione vengono dopo la data
 * che proseguono (art. 66 c. 5), e seguono la seconda convocazione: senza la seconda una prosecuzione è un errore.
 *
 * Le ore sono «come si leggono» a Roma: si confrontano i giorni di calendario scritti, senza fusi. La tabella {@see CASI} la usa anche il controllo della
 * pagina mentre si scrive (lo stesso caso, la stessa frase).
 */
final class ControlloDelleDate
{
    public const FRASE_MANCA_LA_PRIMA = 'Manca la prima convocazione.';

    public const FRASE_STESSO_GIORNO = 'La seconda convocazione non può tenersi nello stesso giorno della prima: scegli un giorno dopo.';

    public const FRASE_PRIMA_DELLA_PRIMA = 'La seconda convocazione viene dopo la prima: scegli un giorno dopo.';

    public const FRASE_OLTRE_DIECI = 'La seconda convocazione va fissata non oltre dieci giorni dalla prima.';

    public const FRASE_SOLO_LA_PRIMA = 'Solo la prima convocazione: se quel giorno non si raggiunge il numero, l\'assemblea va convocata di nuovo.';

    public const FRASE_PROSECUZIONE = 'Una data di prosecuzione deve venire dopo la data precedente: scegli un giorno dopo.';

    public const FRASE_PROSECUZIONE_SENZA_GIORNO = 'Manca il giorno di una data di prosecuzione: scrivilo o togli la riga.';

    public const FRASE_DOPPIA = 'Due date hanno la stessa convocazione e lo stesso numero.';

    public const FRASE_PROSECUZIONE_SENZA_SECONDA = 'Una data di prosecuzione segue la seconda convocazione: scrivi prima la seconda, o togli la riga.';

    /**
     * I casi, uguali per il server e per la pagina: [che cosa, le sedute, valide, la frase].
     *
     * @var list<array{0: string, 1: list<array{convocazione: string, numero?: int, fissata_il: string|null}>, 2: bool, 3: string}>
     */
    public const CASI = [
        ['un giorno dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-15 18:30']],
            true, 'Un giorno diverso, un giorno dopo la prima: va bene.'],
        ['tre giorni dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-17 18:30']],
            true, 'Un giorno diverso, 3 giorni dopo la prima: va bene.'],
        ['dieci giorni dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-24 18:30']],
            true, 'Un giorno diverso, 10 giorni dopo la prima: va bene.'],
        ['lo stesso giorno, più tardi', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-14 18:30']],
            false, self::FRASE_STESSO_GIORNO],
        ['il giorno prima', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-13 18:30']],
            false, self::FRASE_PRIMA_DELLA_PRIMA],
        ['undici giorni dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-25 18:30']],
            false, self::FRASE_OLTRE_DIECI],
        ['a cavallo dell\'anno', [['convocazione' => 'prima', 'fissata_il' => '2026-12-31 20:00'], ['convocazione' => 'seconda', 'fissata_il' => '2027-01-01 10:00']],
            true, 'Un giorno diverso, un giorno dopo la prima: va bene.'],
        ['solo la prima', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00']], true, self::FRASE_SOLO_LA_PRIMA],
        ['senza la prima', [['convocazione' => 'seconda', 'fissata_il' => '2026-12-15 18:30']], false, self::FRASE_MANCA_LA_PRIMA],
        ['una prosecuzione dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-15 18:30'],
            ['convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-16 18:30']], true, 'Un giorno diverso, un giorno dopo la prima: va bene.'],
        ['una prosecuzione prima', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-15 18:30'],
            ['convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-15 10:00']], false, self::FRASE_PROSECUZIONE],
        ['due prime', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'prima', 'fissata_il' => '2026-12-14 09:00']],
            false, self::FRASE_DOPPIA],
        // La pagina manda anche la seconda ancora vuota: non è una prosecuzione, è «solo la prima».
        ['la seconda ancora vuota', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => null]],
            true, self::FRASE_SOLO_LA_PRIMA],
        ['una prosecuzione senza giorno', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'], ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => '2026-12-15 18:30'],
            ['convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => null]], false, self::FRASE_PROSECUZIONE_SENZA_GIORNO],
        // Una prosecuzione senza la seconda convocazione non ha da dove proseguire: prima della prima, o sedici giorni dopo, è sempre un errore.
        ['una prosecuzione senza la seconda, prima della prima', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'fissata_il' => null], ['convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-10 18:30']],
            false, self::FRASE_PROSECUZIONE_SENZA_SECONDA],
        ['una prosecuzione senza la seconda, sedici giorni dopo', [['convocazione' => 'prima', 'fissata_il' => '2026-12-14 08:00'],
            ['convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-30 18:30']], false, self::FRASE_PROSECUZIONE_SENZA_SECONDA],
    ];

    /**
     * @param list<array{convocazione: string, numero?: int|string|null, fissata_il: string|\DateTimeInterface}> $sedute
     * @return array{valide: bool, frase: string}
     */
    public static function controlla(array $sedute): array
    {
        $visti = [];
        $per = ['prima' => [], 'seconda' => []];
        foreach ($sedute as $s) {
            $conv = (string) ($s['convocazione'] ?? '');
            $numero = (int) ($s['numero'] ?? 1) ?: 1;
            $chiave = $conv . ':' . $numero;
            if (isset($visti[$chiave])) {
                return ['valide' => false, 'frase' => self::FRASE_DOPPIA];
            }
            $visti[$chiave] = true;
            $istante = self::istante($s['fissata_il'] ?? null);
            // La seconda convocazione ancora senza giorno non c'è: non è una prosecuzione senza giorno.
            if (isset($per[$conv]) && ! ($conv === 'seconda' && $numero === 1 && $istante === null)) {
                $per[$conv][$numero] = $istante;
            }
        }
        if (($per['prima'][1] ?? null) === null) {
            return ['valide' => false, 'frase' => self::FRASE_MANCA_LA_PRIMA];
        }
        // Le prosecuzioni seguono la seconda convocazione: senza la seconda non c'è da dove proseguire.
        if (($per['seconda'][1] ?? null) === null && array_filter(array_keys($per['seconda']), fn ($n) => $n > 1) !== []) {
            return ['valide' => false, 'frase' => self::FRASE_PROSECUZIONE_SENZA_SECONDA];
        }
        foreach ($per as $date) {
            ksort($date);
            $prima = null;
            foreach ($date as $istante) {
                if ($istante === null) {
                    return ['valide' => false, 'frase' => self::FRASE_PROSECUZIONE_SENZA_GIORNO];
                }
                if ($prima !== null && $istante <= $prima) {
                    return ['valide' => false, 'frase' => self::FRASE_PROSECUZIONE];
                }
                $prima = $istante;
            }
        }
        $primaConvocazione = $per['prima'][1];
        $seconda = $per['seconda'][1] ?? null;
        if ($seconda === null) {
            return ['valide' => true, 'frase' => self::FRASE_SOLO_LA_PRIMA];
        }
        $giorni = (int) CarbonImmutable::parse($primaConvocazione->format('Y-m-d'))->diffInDays(CarbonImmutable::parse($seconda->format('Y-m-d')), false);
        if ($giorni === 0) {
            return ['valide' => false, 'frase' => self::FRASE_STESSO_GIORNO];
        }
        if ($giorni < 0) {
            return ['valide' => false, 'frase' => self::FRASE_PRIMA_DELLA_PRIMA];
        }
        if ($giorni > 10) {
            return ['valide' => false, 'frase' => self::FRASE_OLTRE_DIECI];
        }

        return ['valide' => true, 'frase' => 'Un giorno diverso, ' . ($giorni === 1 ? 'un giorno' : $giorni . ' giorni') . ' dopo la prima: va bene.'];
    }

    /** Il giorno e l'ora come sono scritti, senza fuso (le ore «come si leggono»). */
    private static function istante(mixed $valore): ?CarbonImmutable
    {
        if ($valore instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($valore->format('Y-m-d H:i:s'));
        }
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse(str_replace('T', ' ', $valore));
        } catch (\Throwable) {
            return null;
        }
    }
}
