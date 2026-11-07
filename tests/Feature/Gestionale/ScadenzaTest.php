<?php

/**
 * Il predicato del giorno della scadenza (1.12.0-beta.3): scaduta dal giorno dopo, nel giorno dell'amministratore; i giorni di
 * ritardo; le fasce 1–30, 31–90, oltre 90, con i loro bordi.
 */

use App\Services\Gestionale\Debiti\Scadenza;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

it('il giorno della scadenza non è scaduto; il giorno dopo sì, con un giorno di ritardo', function () {
    expect(Scadenza::scaduta('2026-12-15', '2026-12-15'))->toBeFalse()
        ->and(Scadenza::giorniDiRitardo('2026-12-15', '2026-12-15'))->toBe(0)
        ->and(Scadenza::scaduta('2026-12-15', '2026-12-16'))->toBeTrue()
        ->and(Scadenza::giorniDiRitardo('2026-12-15', '2026-12-16'))->toBe(1)
        ->and(Scadenza::scaduta('2026-12-16', '2026-12-15'))->toBeFalse();
});

it('legge la data come la danno il database e i modelli: stringa con o senza l\'ora, o Carbon', function () {
    expect(Scadenza::scaduta('2026-12-14 00:00:00', '2026-12-15'))->toBeTrue()
        ->and(Scadenza::scaduta(CarbonImmutable::parse('2026-12-15'), '2026-12-15'))->toBeFalse()
        ->and(Scadenza::giorniDiRitardo(Carbon::parse('2026-11-30'), '2026-12-31'))->toBe(31);
});

it('senza un «oggi» dato, oggi è il giorno dell\'amministratore: alle 00:30 del 16/12 a Roma la rata del 15/12 è scaduta', function () {
    $this->travelTo(Carbon::parse('2026-12-16 00:30:00', 'Europe/Rome'));

    expect(now()->toDateString())->toBe('2026-12-15')
        ->and(Scadenza::scaduta('2026-12-15'))->toBeTrue()
        ->and(Scadenza::giorniDiRitardo('2026-12-15'))->toBe(1);
});

it('le fasce, ai bordi', function () {
    expect(Scadenza::fascia(0))->toBeNull()
        ->and(Scadenza::fascia(1))->toBe('1_30')
        ->and(Scadenza::fascia(30))->toBe('1_30')
        ->and(Scadenza::fascia(31))->toBe('31_90')
        ->and(Scadenza::fascia(90))->toBe('31_90')
        ->and(Scadenza::fascia(91))->toBe('oltre_90');
});

it('i giorni di ritardo, ricordati per (scadenza, oggi), sono quelli del calendario ai bordi: anno bisestile, ora legale, fine anno, la stessa coppia due volte', function () {
    $coppie = [
        ['2024-02-28', '2024-03-01'], ['2023-02-28', '2023-03-01'], ['2026-03-28', '2026-03-30'], ['2026-10-24', '2026-10-26'],
        ['2025-12-31', '2026-01-01'], ['2025-01-05', '2026-03-20'], ['2026-03-20', '2026-03-20'], ['2026-03-21', '2026-03-20'],
    ];
    foreach ([1, 2] as $giro) {
        foreach ($coppie as [$scadenza, $oggi]) {
            $atteso = $scadenza < $oggi ? (int) \Carbon\CarbonImmutable::parse($scadenza, 'UTC')->diffInDays(\Carbon\CarbonImmutable::parse($oggi, 'UTC')) : 0;
            expect(Scadenza::giorniDiRitardo($scadenza, $oggi))->toBe($atteso, "{$scadenza} → {$oggi}, giro {$giro}");
        }
    }
    // La stessa scadenza con un altro «oggi» non prende i giorni ricordati per il primo.
    expect(Scadenza::giorniDiRitardo('2026-01-05', '2026-01-06'))->toBe(1)
        ->and(Scadenza::giorniDiRitardo('2026-01-05', '2026-03-20'))->toBe(74)
        ->and(Scadenza::giorniDiRitardo(new \DateTimeImmutable('2026-01-05 13:00:00'), '2026-01-06'))->toBe(1);
});
