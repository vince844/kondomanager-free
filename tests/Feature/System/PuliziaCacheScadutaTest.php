<?php

/**
 * La pulizia settimanale della tabella `cache` (1.11.0-beta.32): con `CACHE_STORE=database`
 * una riga scaduta sparisce solo se qualcuno la rilegge, e in un container senza Redis la cache
 * sta lì. La voce dello scheduler esiste, gira una volta a settimana, e cancella solo le righe
 * scadute.
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function vocePuliziaCache(): Event
{
    $voci = collect(app(Schedule::class)->events())
        ->filter(fn ($evento) => $evento->description === 'pulizia-cache-scaduta');

    expect($voci)->toHaveCount(1);

    return $voci->first();
}

it('è programmata una volta a settimana', function () {
    expect(vocePuliziaCache()->expression)->toBe('30 3 * * 0');
});

it('cancella solo le righe scadute', function () {
    DB::table('cache')->insert([
        ['key' => 'scaduta', 'value' => 's:1:"x";', 'expiration' => now()->subDay()->getTimestamp()],
        ['key' => 'viva', 'value' => 's:1:"x";', 'expiration' => now()->addDay()->getTimestamp()],
    ]);

    $voce = vocePuliziaCache();
    $voce->run(app());

    expect(DB::table('cache')->pluck('key')->all())->toBe(['viva']);
});
