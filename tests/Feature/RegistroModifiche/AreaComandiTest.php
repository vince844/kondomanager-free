<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\ContoContabileCategoria;
use App\Enums\ContoContabileTipo;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Carbon;

uses(WithConsoleEvents::class);

/**
 * 1.12.0-beta.13 (Coda 679, decisione 548, P11, §7 e la scena 2 della bozza): ciò che il programma fa senza una persona è firmato «Il programma», con
 * l'origine. Un comando lanciato da terminale dice «comando lanciato da terminale: …» e scrive una riga sola per condominio; un lavoro del pianificatore
 * dice «lavoro pianificato: …»; un condominio senza niente da correggere non scrive niente.
 *
 * Che cosa non copre: i due riallineamenti (crediti e fondi) dalla rotta della pagina e dal terminale (lo stesso gesto, dentro `esegui()`; lo provano le
 * righe aggiunte a `RiallineaCreditiTest`); il pianificatore lanciato dal cron vero (si lancia `schedule:run` nel processo del test).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->c = Condominio::factory()->create(['nome' => 'Via Roma 12']);
});

it('la correzione lanciata da terminale scrive una riga «Il programma, comando lanciato da terminale: …» per condominio; senza niente da correggere, niente', function () {
    $vuoto = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $g = Gestione::factory()->create(['condominio_id' => $this->c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria']);
    $pc = PianoConto::query()->create(['condominio_id' => $this->c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $costi = ContoContabile::query()->create(['condominio_id' => $this->c->id, 'codice' => '6001', 'nome' => 'Spese generali',
        'tipo' => ContoContabileTipo::COSTO->value, 'categoria' => ContoContabileCategoria::COSTI->value, 'attivo' => true, 'livello' => 1]);
    $voce = Conto::query()->create(['piano_conto_id' => $pc->id, 'nome' => 'Affitto del lastrico', 'tipo' => 'entrata', 'importo' => 50000,
        'conto_contabile_id' => $costi->id]);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->artisan('kondomanager:verifica-voci-di-entrata', ['--correggi' => true])->assertExitCode(0);

    $righe = RegistroModifica::query()->where('id', '>', $prima)->get();
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->autore_tipo)->toBe('programma')
        ->and($righe[0]->autore_nome)->toBe('Il programma')
        ->and($righe[0]->origine)->toBe('comando')
        ->and($righe[0]->origine_dettaglio)->toBe('comando lanciato da terminale: verifica delle voci di entrata')
        ->and($righe[0]->riepilogo)->toBe('Voci di entrata spostate sul mastro «Entrate e proventi»: 1 voce')
        ->and($righe[0]->condomini())->toBe([$this->c->id => 'Via Roma 12'])
        ->and($voce->fresh()->conto_contabile_id)->not->toBe($costi->id)
        ->and(RegistroModifica::query()->where('id', '>', $prima)->get()->filter(fn ($r) => isset($r->condomini()[$vuoto->id])))->toHaveCount(0);
});

it('la pubblicazione programmata, dal pianificatore, è firmata «Il programma, lavoro pianificato» con chi l\'aveva programmata', function () {
    $andrea = User::factory()->create(['name' => 'Andrea Costa']);
    $this->travelTo(Carbon::parse('2026-12-09 08:00:30', 'Europe/Rome'));
    $comunicazione = Comunicazione::factory()->create(['subject' => 'Lavori al portone', 'is_published' => false, 'is_approved' => true,
        'programmata_per' => Carbon::parse('2026-12-09 08:00:00', 'Europe/Rome')->utc(), 'programmata_da' => $andrea->id, 'created_by' => $andrea->id]);
    $comunicazione->condomini()->attach($this->c->id);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->artisan('schedule:run')->assertExitCode(0);

    $riga = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'comunicazione')->firstOrFail();
    expect($riga->autore_nome)->toBe('Il programma')
        ->and($riga->origine)->toBe('pianificato')
        ->and($riga->origine_dettaglio)->toBe('lavoro pianificato: pubblicazione delle comunicazioni programmate')
        ->and($riga->oggetto_nome)->toBe('Comunicazione «Lavori al portone»')
        ->and($riga->riepilogo)->toBe('Pubblicata in bacheca. Programmata da Andrea Costa per il 09/12/2026 alle 08:00')
        ->and($riga->condomini())->toBe([$this->c->id => 'Via Roma 12'])
        ->and($comunicazione->fresh()->is_published)->toBeTruthy();
});
