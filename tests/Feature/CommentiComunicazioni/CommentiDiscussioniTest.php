<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Commenti alle comunicazioni: chi legge che cosa (Coda 559, decisioni 261.1 e 268.1, cambiate dalla 458)
 *
 * Una comunicazione a palazzi interi, uno o più, ha una discussione sola (decisione 458): chi sta in Aurora legge anche i
 * commenti di Borea, e chi ha unità in tutti e due la vede una volta. Una comunicazione a persone, o privata, ha una
 * discussione per persona con lo studio: nessuno vede quella di un altro, né scopre chi altro l'ha ricevuta. Lo studio le vede
 * tutte. Le props della scheda non portano niente delle discussioni che l'utente non vede: né i commenti, né i nomi.
 */

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

test('a palazzi interi: una discussione sola, che vedono tutti i destinatari (chi sta in due palazzi una volta) e lo studio, senza titolo', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);

    expect(ccmChiaviInScheda($this, $this->mario, $c))->toBe([ccmChiaveUnica()])
        ->and(ccmChiaviInScheda($this, $this->lucia, $c))->toBe([ccmChiaveUnica()])
        ->and(ccmChiaviInScheda($this, $this->paolo, $c))->toBe([ccmChiaveUnica()])
        ->and(ccmChiaviInScheda($this, $this->admin, $c, 'admin'))->toBe([ccmChiaveUnica()]);

    $discussione = ccmDiscussione(ccmScheda($this, $this->paolo, $c), ccmChiaveUnica());
    expect($discussione['titolo'])->toBe('');
});

test('a palazzi interi: chi sta in Aurora legge anche i commenti di Borea, senza il loro condominio', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin);
    ccmCommentoSalvato($c, $this->mario, 'Grazie, ci sarò lunedì', $this->aurora);
    ccmCommentoSalvato($c, $this->lucia, 'Il portone di Borea resta aperto durante i lavori?', $this->borea);

    $props = ccmScheda($this, $this->mario, $c);
    $testo = json_encode($props, JSON_UNESCAPED_UNICODE);

    expect(collect(ccmDiscussione($props, ccmChiaveUnica())['commenti'])->pluck('corpo')->all())
        ->toBe(['Grazie, ci sarò lunedì', 'Il portone di Borea resta aperto durante i lavori?'])
        ->and($testo)->toContain('Lucia Verdi')
        ->and($testo)->not->toContain('Condominio Borea');
});

test('a persone: ognuno vede solo la sua discussione con lo studio, e lo studio una per persona', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['subject' => 'Il suo sollecito'], [$this->mario, $this->lucia]);
    ccmCommentoSalvato($c, $this->lucia, 'Ho già pagato a ottobre', $this->borea, $this->lucia);
    ccmCommentoSalvato($c, $this->admin, 'Ci mandi la ricevuta, grazie', $this->borea, $this->lucia);

    expect(ccmChiaviInScheda($this, $this->mario, $c))->toBe([ccmChiavePersona($this->mario)])
        ->and(ccmChiaviInScheda($this, $this->lucia, $c))->toBe([ccmChiavePersona($this->lucia)]);

    $props = ccmScheda($this, $this->mario, $c);
    $testo = json_encode($props, JSON_UNESCAPED_UNICODE);
    expect(ccmDiscussione($props, ccmChiavePersona($this->mario))['titolo'])->toBe('Con lo studio')
        ->and($testo)->not->toContain('Ho già pagato a ottobre')
        ->and($testo)->not->toContain('Lucia Verdi');

    $studio = ccmScheda($this, $this->admin, $c, 'admin');
    expect(collect($studio['discussioni'])->pluck('chiave')->sort()->values()->all())
        ->toBe(collect([ccmChiavePersona($this->mario), ccmChiavePersona($this->lucia)])->sort()->values()->all())
        ->and(ccmDiscussione($studio, ccmChiavePersona($this->lucia))['titolo'])->toBe('Lucia Verdi')
        ->and(collect(ccmDiscussione($studio, ccmChiavePersona($this->lucia))['commenti'])->pluck('corpo')->all())
        ->toBe(['Ho già pagato a ottobre', 'Ci mandi la ricevuta, grazie']);
});

test('privata, scritta dal portale: la discussione è del suo autore con lo studio; il vicino non apre la scheda', function () {
    $c = ccmComunicazione([$this->aurora], $this->mario, ['is_private' => true, 'subject' => 'Infiltrazione in cantina']);

    expect(ccmChiaviInScheda($this, $this->mario, $c))->toBe([ccmChiavePersona($this->mario)])
        ->and(ccmScheda($this, $this->paolo, $c))->toBeNull()
        ->and(ccmChiaviInScheda($this, $this->admin, $c, 'admin'))->toBe([ccmChiavePersona($this->mario)]);
});

test('un commento in attesa lo vedono il suo autore e chi lo può approvare, non gli altri della discussione', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);
    ccmCommentoSalvato($c, $this->mario, 'Si può spostare a martedì?', $this->aurora, null, 'in_attesa');

    $corpi = fn ($user, $porta = 'user') => collect(ccmDiscussione(ccmScheda($this, $user, $c, $porta), ccmChiaveUnica())['commenti'])->pluck('corpo')->all();

    expect($corpi($this->mario))->toBe(['Si può spostare a martedì?'])
        ->and($corpi($this->paolo))->toBe([])
        ->and($corpi($this->admin, 'admin'))->toBe(['Si può spostare a martedì?']);
});

test('chi non ha il permesso di vedere i commenti apre la scheda senza discussioni', function () {
    $ruolo = ccmRuolo('lettore senza commenti', ['Visualizza comunicazioni']);
    $this->paolo->syncRoles([$ruolo]);
    $c = ccmComunicazione([$this->aurora], $this->admin);
    ccmCommentoSalvato($c, $this->mario, 'Grazie', $this->aurora);

    $props = ccmScheda($this, $this->paolo->fresh(), $c);
    expect($props)->not->toBeNull()
        ->and($props['discussioni'])->toBe([])
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Grazie');
});

test('ogni commento porta le azioni che le regole gli lasciano fare', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    $suo = ccmCommentoSalvato($c, $this->mario, 'Il mio', $this->aurora);
    $altrui = ccmCommentoSalvato($c, $this->paolo, 'Del vicino', $this->aurora);
    $attesa = ccmCommentoSalvato($c, $this->paolo, 'In attesa', $this->aurora, null, 'in_attesa');

    $azioni = function ($user, int $id, string $porta = 'user') use ($c) {
        $commento = collect(ccmDiscussione(ccmScheda($this, $user, $c, $porta), ccmChiaveUnica())['commenti'])->firstWhere('id', $id);
        expect($commento)->not->toBeNull("Il commento {$id} non è nella scheda di {$user->name}");

        return $commento['azioni'];
    };

    expect($azioni($this->mario, $suo))->toMatchArray(['modifica' => true, 'elimina' => true, 'approva' => false, 'nascondi' => false])
        ->and($azioni($this->mario, $altrui))->toMatchArray(['modifica' => false, 'elimina' => false, 'approva' => false, 'nascondi' => false])
        ->and($azioni($this->collaboratrice, $altrui, 'admin'))->toMatchArray(['modifica' => false, 'elimina' => false, 'approva' => false, 'nascondi' => false])
        ->and($azioni($this->admin, $attesa, 'admin'))->toMatchArray(['approva' => true, 'elimina' => true]);
});

test('nella scheda della segnalazione i commenti portano le azioni, e chi modera lo dice la regola, non il pannello', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario);
    $commento = ccmCommentoSalvato($s, $this->mario, 'Ancora rotto', $this->aurora);

    $props = fn ($user) => json_decode(json_encode($this->actingAs($user)->get(route('admin.segnalazioni.show', $s))->viewData('page')['props']), true);

    $collaboratrice = $props($this->collaboratrice);
    expect($collaboratrice['commenti_config']['can_moderate'])->toBeFalse()
        ->and(collect($collaboratrice['segnalazione']['commenti'])->firstWhere('id', $commento)['azioni'])
        ->toMatchArray(['modifica' => false, 'elimina' => false, 'approva' => false, 'nascondi' => false]);

    $admin = $props($this->admin);
    expect($admin['commenti_config']['can_moderate'])->toBeTrue()
        ->and(collect($admin['segnalazione']['commenti'])->firstWhere('id', $commento)['azioni'])
        ->toMatchArray(['modifica' => true, 'elimina' => true, 'nascondi' => true]);
});
