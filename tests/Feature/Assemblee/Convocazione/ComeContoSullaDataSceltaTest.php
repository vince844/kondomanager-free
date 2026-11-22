<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role as Ruolo;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): il passo «Convocazione» senza spazi vuoti, la parte che scrive il server, con l'ordinaria di Via Roma 12 (la prima
 * convocazione lunedì 14/12, l'ultimo giorno mercoledì 09/12, la proposta giovedì 03/12; oggi giovedì 26/11).
 *
 * - «Come conto» si fa sulla data che vale: la proposta, o la data scelta dall'amministratore, con lo stesso calendario. Dice se arriva in tempo e con quanto
 *   margine; arriva tardi, lo dice con il giorno in cui arriverebbe.
 * - Con la data scelta: «Torna alla proposta (gio 03/12)», «Che cosa segue la data che scegli» (il compito, i promemoria per email, le raccomandate
 *   stampate), la riga «Scelta da te il …».
 * - Con la proposta: le righe d'aiuto sotto il campo, il compito e i giorni dei promemoria per email; «Giorni di posta dello studio: 3 lavorativi», con il
 *   collegamento all'impostazione per chi la può cambiare.
 * - Un giorno già passato non si sceglie: il server lo rifiuta con la frase; una data già salvata nel passato si mostra com'è, con la frase.
 * - Il giorno come si legge, a Roma: a mezzanotte e mezza di Roma i giorni dei promemoria già passati non si dicono più.
 *
 * La catena: la data scelta → «Come conto» rifatto su quella data → il compito e i promemoria sulla data nuova.
 *
 * Che cosa non copre: la pagina a video (i vitest di `EntroQuandoSpedire` e della scheda); il promemoria che parte (`PromemoriaDellaConvocazioneTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** «Entro quando spedire» come lo legge la pagina, per l'amministratore. */
function ccdEntro(Beta51Scenario $s, $a): array
{
    test()->actingAs($s->admin);

    return app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['entro'];
}

it('la data scelta → «Come conto» rifatto su quella data → il compito e i promemoria sulla data nuova', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $proposta = ccdEntro($s, $a);
    expect($proposta['oggi'])->toBe('2026-11-26')
        ->and($proposta['conto']['titolo'])->toBe('Come conto giovedì 03/12')
        ->and($proposta['conto']['frase'])->toBe('La raccomandata deve arrivare entro mercoledì 09/12. Lo studio conta tre giorni di posta, dal lunedì al venerdì, dal giorno dopo la spedizione.')
        ->and($proposta['conto']['esito'])->toBe(['in_tempo' => true, 'titolo' => 'Arriva in tempo',
            'frase' => ', l\'ultimo giorno utile. Spedita venerdì 04/12 arriverebbe giovedì 10/12: un giorno dopo.'])
        ->and($proposta['conto']['piede'])->toBe('Conto prudente: spedire un giorno prima non costa niente.')
        ->and($proposta['righe'])->toBe([
            'Puoi scegliere un altro giorno, da oggi in poi: si salva quando lo cambi.',
            'Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email arriva martedì 01/12 e giovedì 03/12.',
        ])
        ->and($proposta['raccomandate'])->toMatchArray(['scelta_da_te' => false, 'torna' => null, 'passata' => null])
        ->and($proposta['segue'])->toBe([]);

    // Martedì 01/12: arriva venerdì 04/12, cinque giorni prima del 09/12; il compito e i promemoria si spostano.
    $this->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-01'])->assertSessionHasNoErrors();
    $scelta = ccdEntro($s, $a);
    expect($scelta['conto']['titolo'])->toBe('Come conto martedì 01/12')
        ->and(array_map(fn ($g) => $g['giorno'] . ' · ' . $g['nota'], $scelta['conto']['giorni']))
        ->toBe(['mar 01 · spedisci · non conta', 'mer 02 · 1° giorno di posta', 'gio 03 · 2° giorno di posta', 'ven 04 · 3° · arriva in tempo'])
        ->and($scelta['conto']['esito'])->toBe(['in_tempo' => true, 'titolo' => 'Arriva in tempo', 'frase' => ', con 5 giorni di margine sul 09/12.'])
        ->and($scelta['raccomandate'])->toMatchArray(['scelta_da_te' => true, 'entro' => '2026-12-01', 'torna' => 'Torna alla proposta (gio 03/12)'])
        ->and($scelta['righe'])->toBe(['Scelta da te il 26/11/2026; la proposta del programma resta giovedì 03/12.'])
        ->and($scelta['segue'])->toBe([
            'Il compito nell\'Action inbox: «Spedire la convocazione entro l\'01/12».',
            'Il promemoria per email: venerdì 27/11 e martedì 01/12.',
            'Le raccomandate stampate: nella tabella dei convocati, «entro l\'01/12».',
        ]);

    // Venerdì 04/12: arriva giovedì 10/12, un giorno dopo il termine; il riquadro lo dice, senza bloccare.
    $this->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-04'])->assertSessionHasNoErrors();
    expect(ccdEntro($s, $a)['conto']['esito'])->toBe(['in_tempo' => false, 'titolo' => 'Arriva tardi',
        'frase' => ': spedita venerdì 04/12 arriverebbe giovedì 10/12, un giorno dopo il termine del 09/12. Per chi la riceve dopo il 09/12 i cinque giorni '
            . 'non ci sono, e la delibera si può impugnare. Se tieni il 04/12, decidi tu.']);

    // Venerdì 11/12: arriverebbe dopo la prima convocazione.
    $this->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-11'])->assertSessionHasNoErrors();
    expect(ccdEntro($s, $a)['conto']['esito']['frase'])->toStartWith(': spedita venerdì 11/12 arriverebbe mercoledì 16/12, dopo la prima convocazione di lunedì 14/12.');
});

it('un giorno già passato non si sceglie: il server lo rifiuta con la frase; una data salvata che passa si mostra com\'è, con la frase', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $rotta = route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]);

    $this->actingAs($s->admin)->patch($rotta, ['giorno' => '2026-11-25'])
        ->assertSessionHasErrors(['giorno' => 'Il giorno è già passato: scegli oggi o un giorno dopo.']);
    expect($a->refresh()->raccomandate_entro_il)->toBeNull();
    // Oggi sì, e tornare alla proposta sempre.
    $this->patch($rotta, ['giorno' => '2026-11-26'])->assertSessionHasNoErrors();
    $this->patch($rotta, ['giorno' => null])->assertSessionHasNoErrors();

    $this->patch($rotta, ['giorno' => '2026-11-27'])->assertSessionHasNoErrors();
    ConvocazioneDiViaRoma12::oggi('2026-11-30 10:00');
    $entro = ccdEntro($s, $a);
    expect($entro['raccomandate'])->toMatchArray(['entro' => '2026-11-27', 'scelta_da_te' => true, 'passata' => 'Il giorno è già passato: scegli oggi o un giorno dopo.'])
        ->and($entro['oggi'])->toBe('2026-11-30');
    // Anche lo stesso giorno di prima, ormai passato, si rifiuta: si sceglie oggi o un giorno dopo, o si torna alla proposta.
    expect(fn () => app(TerminiDellaConvocazione::class)->scegli($a->refresh(), '2026-11-27', $s->admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('i giorni di posta: «3 lavorativi», con «Cambia» solo per chi può cambiare l\'impostazione', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    expect(ccdEntro($s, $a)['giorni_di_posta'])->toBe(['adesso' => '3 lavorativi', 'dove' => null]);
    Ruolo::firstOrCreate(['name' => Role::AMMINISTRATORE->value, 'guard_name' => 'web']);
    $s->admin->assignRole(Role::AMMINISTRATORE->value);
    expect(ccdEntro($s, $a)['giorni_di_posta'])->toBe(['adesso' => '3 lavorativi', 'dove' => route('impostazioni.assemblee')]);
});

it('il promemoria spento o senza server: le righe lo dicono', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $impostazioni = app(MailSettings::class);
    $impostazioni->promemoria_convocazioni = false;
    $impostazioni->save();
    expect(ccdEntro($s, $a)['righe'][1])
        ->toBe('Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email è spento nelle impostazioni della posta.');

    $impostazioni->promemoria_convocazioni = true;
    $impostazioni->save();
    Config::set('mail.default', 'log');
    expect(ccdEntro($s, $a)['righe'][1])
        ->toBe('Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email non parte: il programma non ha un server di posta.');
});

it('il giorno come si legge, a Roma: alle 00:30 di mercoledì 02/12 il promemoria di martedì 01/12 non si dice più', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    // Le 00:30 di Roma di mercoledì 02/12 sono ancora martedì 01/12 sul server.
    ConvocazioneDiViaRoma12::oggi('2026-12-02 00:30');
    expect(ccdEntro($s, $a)['righe'][1])->toBe('Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email arriva giovedì 03/12.')
        ->and(ccdEntro($s, $a)['oggi'])->toBe('2026-12-02');
});

it('il giorno passato come si legge, a Roma: alle 00:30 di venerdì 27/11 (ancora giovedì sul server) giovedì 26/11 è già passato', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    ConvocazioneDiViaRoma12::oggi('2026-11-27 00:30');

    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-11-26'])
        ->assertSessionHasErrors(['giorno' => 'Il giorno è già passato: scegli oggi o un giorno dopo.']);
    $this->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-11-27'])->assertSessionHasNoErrors();
    expect(ccdEntro($s, $a)['oggi'])->toBe('2026-11-27')
        ->and($a->refresh()->raccomandate_entro_il->format('Y-m-d'))->toBe('2026-11-27');
});
