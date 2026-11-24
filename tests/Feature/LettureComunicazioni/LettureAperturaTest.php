<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: «aperta» (Coda 594, decisione 310, punto 2)
 *
 * Con l'impostazione generale accesa il programma segna, per ogni destinatario, il giorno e l'ora in cui apre la
 * comunicazione **nel portale**. Le regole:
 * - conta solo l'apertura dal portale: lo studio che la apre dal pannello non conta mai;
 * - conta solo chi è destinatario, e chi ha scritto la comunicazione non entra nel conto;
 * - si segna solo la prima apertura: aprirla di nuovo non cambia niente;
 * - con l'impostazione spenta non si registra niente;
 * - una comunicazione non ancora uscita non conta: comincia da quando esce;
 * - una richiesta di precaricamento della pagina (`Purpose: prefetch`) non è un'apertura.
 *
 * Il condòmino lo sa: la scheda del portale riceve `lettura`, con la sua sola apertura, e la pagina glielo scrive
 * sotto la comunicazione.
 */

use Illuminate\Support\Carbon;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    lctPrepara($this);
});

// --- Quando si registra ----------------------------------------------------------------------------------

test('di serie aprire una comunicazione nel portale non registra niente', function () {
    lctApri($this, $this->mario, $this->c)->assertOk();

    expect(lctRighe())->toHaveCount(0);
});

test('con l\'impostazione accesa, il destinatario che la apre nel portale risulta, con il giorno e l\'ora', function () {
    lctImpostazione(true);

    lctApri($this, $this->mario, $this->c)->assertOk();

    $riga = lctRiga($this->c, $this->mario);
    expect($riga)->not->toBeNull('L\'apertura non è stata registrata')
        ->and($riga->aperta_il)->toBe(LCT_ADESSO)
        ->and($riga->presa_visione_il)->toBeNull()
        ->and(lctRighe())->toHaveCount(1);
});

test('aprirla una seconda volta non cambia niente: resta la prima apertura', function () {
    lctImpostazione(true);
    lctApri($this, $this->mario, $this->c)->assertOk();

    $this->travelTo(Carbon::parse('2026-11-13 17:45:00', 'UTC'));
    lctApri($this, $this->mario, $this->c)->assertOk();
    lctApri($this, $this->mario, $this->c)->assertOk();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($this->c, $this->mario)->aperta_il)->toBe(LCT_ADESSO);
});

test('ognuno ha la sua riga, per ogni comunicazione che apre', function () {
    lctImpostazione(true);
    $altra = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Pulizia delle scale']);

    lctApri($this, $this->mario, $this->c)->assertOk();
    lctApri($this, $this->paolo, $this->c)->assertOk();
    lctApri($this, $this->mario, $altra)->assertOk();

    expect(lctRighe())->toHaveCount(3)
        ->and(lctRighe($this->c))->toHaveCount(2)
        ->and(lctRiga($altra, $this->mario))->not->toBeNull()
        ->and(lctRiga($altra, $this->paolo))->toBeNull();
});

test('lo studio che la apre dal pannello non conta mai', function () {
    lctImpostazione(true);

    // L'amministratore l'ha scritta; la collaboratrice ha un'anagrafica nel palazzo ed è fra i destinatari.
    lctApri($this, $this->admin, $this->c, 'admin')->assertOk();
    lctApri($this, $this->collaboratrice, $this->c, 'admin')->assertOk();

    expect(lctRighe())->toHaveCount(0);
});

test('chi ha scritto la comunicazione non entra nel conto, nemmeno dal portale', function () {
    lctImpostazione(true);
    $diMario = ccmComunicazione([$this->aurora], $this->mario, ['subject' => 'Bicicletta nell\'androne']);

    lctApri($this, $this->mario, $diMario)->assertOk();
    lctApri($this, $this->paolo, $diMario)->assertOk();

    expect(lctRiga($diMario, $this->mario))->toBeNull()
        ->and(lctRiga($diMario, $this->paolo))->not->toBeNull()
        ->and(lctRighe())->toHaveCount(1);
});

test('chi non è destinatario non la apre, e non lascia righe', function () {
    lctImpostazione(true);

    // Lucia è del palazzo Borea; la comunicazione è per Aurora.
    expect(lctRifiutato(lctApri($this, $this->lucia, $this->c)))->toBeTrue()
        ->and(lctRighe())->toHaveCount(0);
});

test('una comunicazione indirizzata a delle persone conta solo loro', function () {
    lctImpostazione(true);
    $perPaolo = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Conguaglio dell\'acqua'], [$this->paolo]);

    expect(lctRifiutato(lctApri($this, $this->mario, $perPaolo)))->toBeTrue();
    lctApri($this, $this->paolo, $perPaolo)->assertOk();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($perPaolo, $this->paolo))->not->toBeNull();
});

test('una comunicazione non ancora uscita non conta: comincia da quando esce', function () {
    lctImpostazione(true);
    // Mario la scrive dal portale e resta in attesa di approvazione: la vede solo lui, che l'ha scritta.
    $inAttesa = ccmComunicazione([$this->aurora], $this->mario, ['subject' => 'Proposta per il cortile', 'is_approved' => false]);

    lctApri($this, $this->mario, $inAttesa)->assertOk();
    expect(lctRifiutato(lctApri($this, $this->paolo, $inAttesa)))->toBeTrue()
        ->and(lctRighe())->toHaveCount(0);

    $this->travelTo(Carbon::parse('2026-11-14 08:00:00', 'UTC'));
    $inAttesa->update(['is_approved' => true]);
    lctApri($this, $this->paolo, $inAttesa)->assertOk();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($inAttesa, $this->paolo)->aperta_il)->toBe('2026-11-14 08:00:00');
});

test('una richiesta di precaricamento della pagina non è un\'apertura', function () {
    lctImpostazione(true);

    lctApri($this, $this->mario, $this->c, 'user', ['Purpose' => 'prefetch'])->assertOk();
    expect(lctRighe())->toHaveCount(0);

    lctApri($this, $this->mario, $this->c)->assertOk();
    expect(lctRighe())->toHaveCount(1);
});

test('i collegamenti a una comunicazione non la precaricano', function () {
    // Una pagina precaricata si apre dalla memoria del browser, senza una richiesta al server: l'apertura non si
    // registrerebbe. Oggi nessun collegamento a una comunicazione usa `prefetch`, e deve restare così.
    $pagine = [];
    $cartella = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));
    foreach ($cartella as $file) {
        if (! preg_match('/\.(vue|ts)$/', $file->getFilename()) || str_ends_with($file->getFilename(), '.test.ts')) {
            continue;
        }
        $testo = file_get_contents($file->getPathname());
        if (str_contains($testo, 'comunicazioni.show')) {
            $pagine[] = $file->getPathname();
            expect(stripos($testo, 'prefetch'))->toBeFalse('Un collegamento precaricato in ' . $file->getPathname());
        }
    }

    expect(count($pagine))->toBeGreaterThan(0);
});

test('spenta l\'impostazione non se ne registrano altre, e quelle di prima restano', function () {
    lctImpostazione(true);
    lctApri($this, $this->mario, $this->c)->assertOk();

    lctImpostazione(false);
    lctApri($this, $this->paolo, $this->c)->assertOk();

    expect(lctRighe())->toHaveCount(1)
        ->and(lctRiga($this->c, $this->mario)->aperta_il)->toBe(LCT_ADESSO)
        ->and(lctRiga($this->c, $this->paolo))->toBeNull();
});

// --- Che cosa riceve la scheda del portale ----------------------------------------------------------------

test('la scheda del portale riceve la propria apertura, nel fuso dell\'installazione', function () {
    lctImpostazione(true);

    $lettura = lctLettura($this, $this->mario, $this->c);

    // Le 10:00 UTC sono le 11:00 a Roma. La riga è scritta nella stessa richiesta che apre la pagina.
    expect($lettura)->toBe([
        'apertura' => ['il' => '12/11/2026', 'alle' => '11:00'],
        'puo_confermare' => false,
        'presa_visione' => null,
    ]);
});

test('di serie la scheda del portale non dice niente sull\'apertura', function () {
    expect(lctLettura($this, $this->mario, $this->c))->toBe([
        'apertura' => null,
        'puo_confermare' => false,
        'presa_visione' => null,
    ]);
});

test('un\'apertura registrata prima resta scritta al condòmino anche con l\'impostazione spenta', function () {
    lctScrivi($this->c, $this->mario, '2026-11-10 08:30:00');

    expect(lctLettura($this, $this->mario, $this->c)['apertura'])->toBe(['il' => '10/11/2026', 'alle' => '09:30'])
        ->and(lctLettura($this, $this->paolo, $this->c)['apertura'])->toBeNull();
});

test('chi ha scritto la comunicazione, nel portale, non legge niente sulle letture', function () {
    lctImpostazione(true);
    $diMario = lctChiedi(ccmComunicazione([$this->aurora], $this->mario, ['subject' => 'Bicicletta nell\'androne']));

    expect(lctLettura($this, $this->mario, $diMario))->toBe([
        'apertura' => null,
        'puo_confermare' => false,
        'presa_visione' => null,
    ]);
});

test('la scheda del portale non riceve mai le letture degli altri', function () {
    lctImpostazione(true);
    lctChiedi($this->c);
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');

    $props = lctProps(lctApri($this, $this->mario, $this->c));
    $tutto = json_encode($props, JSON_UNESCAPED_UNICODE);

    expect($props)->not->toHaveKey('letture')
        ->and(lctRisorsa($props['comunicazione']))->not->toHaveKey('letture')
        ->and($tutto)->not->toContain('Paolo Neri')
        ->and($tutto)->not->toContain('Giulia Senzaccount')
        ->and($tutto)->not->toContain('senza_accesso');
});

test('la scheda del pannello non riceve «lettura», che è del portale', function () {
    lctImpostazione(true);

    $props = lctProps(lctApri($this, $this->admin, $this->c, 'admin'));

    expect($props)->not->toHaveKey('lettura')
        ->and($props)->toHaveKey('letture');
});
