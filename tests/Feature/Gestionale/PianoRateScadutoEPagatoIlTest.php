<?php

/**
 * La pagina del piano rate con le regole comuni (1.12.0-beta.3): il server manda per (persona, numero rata) e per (unità, rata)
 * `emessa` (una quota a giornale) e `scaduta` (il predicato del giorno), e il browser non ricalcola; la data dell'ultimo
 * pagamento di una quota (`rate_quote.data_pagamento`) dopo lo storno di un versamento è quella dell'ultimo versamento non
 * stornato, non la data della rettifica.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\User;
use App\Services\PianoRateQuoteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('per persona e per unità: `emessa` dalla quota a giornale, `scaduta` dal predicato del giorno (alle 11:00 del 15/12 la rata del 15/12 non lo è)', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));

    $piano = $s['piano']->fresh(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile', 'rate.rateQuote.rata']);
    $perPersona = collect(app(PianoRateQuoteService::class)->quotePerAnagrafica($piano)->first()['rate'])->keyBy('numero');
    $perUnita = collect(app(PianoRateQuoteService::class)->quotePerImmobile($piano)->first()['rate'])->keyBy('numero');

    foreach ([$perPersona, $perUnita] as $rate) {
        expect($rate[1])->toMatchArray(['emessa' => true, 'scaduta' => true])
            ->and($rate[2])->toMatchArray(['emessa' => true, 'scaduta' => false])
            ->and($rate[3])->toMatchArray(['emessa' => true, 'scaduta' => false])
            ->and($rate[4])->toMatchArray(['emessa' => false, 'scaduta' => true])
            ->and($rate[5])->toMatchArray(['emessa' => true, 'scaduta' => false])
            ->and($rate[0]['emessa'])->toBeFalse();
    }
});

it('dopo lo storno del secondo di due versamenti la data dell\'ultimo pagamento resta quella del primo (10/12), non quella della rettifica (15/01)', function () {
    $this->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata', [], 30000, '2026-11-30', 30);
    $this->travelTo('2026-11-01 10:00:00');
    lcdEmetti($this, $s, [1], '2026-11-01');

    $this->travelTo('2026-12-10 10:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-12-10');
    $this->travelTo('2026-12-20 10:00:00');
    $secondo = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-12-20');
    expect(substr((string) RataQuote::find(lcdQuota($s, 'ugo', 1))->data_pagamento, 0, 10))->toBe('2026-12-20');

    $this->travelTo('2027-01-15 11:00:00');
    lcdStorna($this, $s, $secondo);

    $quota = RataQuote::find(lcdQuota($s, 'ugo', 1));
    expect((int) $quota->importo_pagato)->toBe(10000)
        ->and($quota->stato)->toBe('parzialmente_pagata')
        ->and(substr((string) $quota->data_pagamento, 0, 10))->toBe('2026-12-10');
});

it('«Pagato il»: un versamento tornato indietro e poi rifatto; la rata pagata dice la data dell\'ultimo versamento valido (22/12), non quella dello storno (15/01)', function () {
    $this->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata', [], 30000, '2026-11-30', 30);
    $this->travelTo('2026-11-01 10:00:00');
    lcdEmetti($this, $s, [1], '2026-11-01');

    $this->travelTo('2026-12-10 10:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-12-10');
    $this->travelTo('2026-12-20 10:00:00');
    $secondo = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-12-20');
    // Il bonifico del 20/12 torna indietro; Ugo lo rifà con data 22/12, registrato il 20/01.
    $this->travelTo('2027-01-15 11:00:00');
    lcdStorna($this, $s, $secondo);
    $this->travelTo('2027-01-20 10:00:00');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 20000], '2026-12-22');

    $piano = $s['piano']->fresh(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile', 'rate.rateQuote.rata']);
    $rata = collect(app(PianoRateQuoteService::class)->quotePerAnagrafica($piano)->first()['rate'])->keyBy('numero')[1];
    $unita = collect(app(PianoRateQuoteService::class)->quotePerImmobile($piano)->first()['rate'])->keyBy('numero')[1];
    expect($rata)->toMatchArray(['stato' => 'pagata', 'data_pagamento' => '2026-12-22'])
        ->and($unita)->toMatchArray(['stato' => 'pagata', 'data_pagamento' => '2026-12-22']);
});

it('«Pagato il» di una rata con una quota a credito: né la rettifica dello storno di una compensazione né il credito preso per un\'altra rata sono un versamento', function () {
    $this->travelTo('2026-03-01 10:00:00');
    // Ugo ha A (600) e il box G (200): sulla rata 1 la quota di A è 6000 − 7000 = −1000 (credito), quella di G 2000.
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 200], 'B' => ['bice', 200]], 12, 'prima_rata', [['ugo', 'A', -7000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    $quotaA = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->where('immobile_id', $s['immobili']['A']->id)->value('id');
    $quotaG = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 1))->where('immobile_id', $s['immobili']['G']->id)->value('id');

    // Il 10/03 il credito di A e 1000 in contanti pagano G; il 15/03 l'incasso si storna; Ugo ripaga G con un bonifico del 14/03.
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [$quotaA => -1000, $quotaG => 2000], '2026-03-10');
    $this->travelTo('2026-03-15 10:00:00');
    lcdStorna($this, $s, $incasso);
    $this->travelTo('2026-03-16 10:00:00');
    lcdIncassa($this, $s, 'ugo', [$quotaG => 2000], '2026-03-14');
    // Il 20/03 il credito di A paga 1000 della rata 2 del box: la riga negativa sta sulla quota di A della rata 1.
    $quotaG2 = (int) DB::table('rate_quote')->where('rata_id', lcdRata($s, 2))->where('immobile_id', $s['immobili']['G']->id)->value('id');
    $this->travelTo('2026-03-20 10:00:00');
    lcdIncassa($this, $s, 'ugo', [$quotaA => -1000, $quotaG2 => 1000], '2026-03-20');

    $piano = $s['piano']->fresh(['rate.rateQuote.anagrafica', 'rate.rateQuote.immobile', 'rate.rateQuote.rata']);
    $rata = collect(app(PianoRateQuoteService::class)->quotePerAnagrafica($piano)->firstWhere('anagrafica.id', $s['persone']['ugo']->id)['rate'])->keyBy('numero')[1];
    // La rata 1 di Ugo: 6000 − 7000 + 2000 = 1000; pagati 2000 su G e −1000 di credito uscito da A: «pagata», l'ultimo versamento è del 14/03.
    expect($rata)->toMatchArray(['stato' => 'pagata', 'data_pagamento' => '2026-03-14']);
});
