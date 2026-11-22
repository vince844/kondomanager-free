<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\RendicontoVersione;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\Convocazione\RendicontoPerLAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\VersioniDelRendiconto;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§3 P16 del progetto della tappa 2): «Ferma per l'assemblea» collegato. Dalla rotta della Sintesi con «Per quale assemblea» la versione fermata
 * entra nel punto del rendiconto dell'assemblea in preparazione, con la riga «Rendiconto 2026, versione 1, allegato al punto 1»; il fascicolo la legge; la
 * riga di stato dice in quale assemblea e se la convocazione è partita; **una versione spedita non si elimina**; un'assemblea di un altro condominio dà 404.
 *
 * Che cosa non copre: «Registra l'approvazione» (resta com'è fino alla tappa 5); il contenuto del fascicolo del rendiconto (è dei suoi test).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('fermata per l\'ordinaria del 15/12: la versione nel punto del rendiconto, la riga del registro, il fascicolo; spedita, non si elimina', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'rendiconto', 'esercizio_id' => $s->e->id],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    $this->actingAs($s->admin);
    expect(RendicontoPerLAssemblea::assemblee($s->e))->toBe([['id' => $a->id, 'punto_id' => $a->punti()->first()->id,
        'nome' => 'Assemblea ordinaria del 15/12/2026, punto 1']]);
    $prima = ViaRoma12::ultimaRiga();

    $this->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s->c, $s->e]), ['parti' => ['riepilogo'], 'assemblea_id' => $a->id])
        ->assertSessionHasNoErrors()->assertRedirect();
    $v = RendicontoVersione::query()->where('esercizio_id', $s->e->id)->firstOrFail();
    expect($a->punti()->first()->oggetti()->where('oggetto_tipo', 'rendiconto_versione')->value('oggetto_id'))->toBe($v->id)
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->pluck('riepilogo')->all())->toContain('Rendiconto 2026, versione 1, allegato al punto 1')
        ->and(app(FascicoloDellAssemblea::class)->versioneDelRendiconto($a->punti()->first())?->id)->toBe($v->id)
        ->and(RendicontoPerLAssemblea::inAssemblea($v))->toBe(['frase' => 'In assemblea il 15/12, punto 1 · convocazione non ancora spedita', 'spedita' => false]);

    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect(RendicontoPerLAssemblea::inAssemblea($v)['spedita'])->toBeTrue()
        ->and(app(VersioniDelRendiconto::class)->elimina($v))->toStartWith('La versione 1 è allegata alla convocazione già spedita')
        ->and(RendicontoVersione::query()->whereKey($v->id)->exists())->toBeTrue();
});

it('un\'assemblea di un altro condominio per «Per quale assemblea»: 404, e la versione non nasce', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $diCorso = ViaRoma12::ordinaria($altro, [['testo' => 'Approvazione del rendiconto', 'materia' => 'rendiconto']]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s->c, $s->e]), ['parti' => ['riepilogo'], 'assemblea_id' => $diCorso->id])
        ->assertNotFound();
    expect(RendicontoVersione::query()->where('esercizio_id', $s->e->id)->count())->toBe(0);
});

it('la nota legge l\'ordine del giorno: «Da decidere in assemblea» stampa i punti dell\'assemblea con il punto del rendiconto; senza, le righe della nota', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $stampa = ['parti' => [['chiave' => 'questioni', 'titolo' => 'Questioni', 'fatti' => [], 'testo' => '', 'da_decidere' => ['Il portone.']]], 'vuota' => false, 'spiegati' => 0];
    expect(\App\Services\Gestionale\NotaSinteticaDelRendiconto::conLOrdineDelGiorno($stampa, $s->e)['parti'][0]['da_decidere'])->toBe(['Il portone.']);

    ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'rendiconto', 'esercizio_id' => $s->e->id],
        ['testo' => 'Sostituzione del portone d\'ingresso', 'materia' => 'lavori'], ['testo' => 'Varie ed eventuali', 'materia' => 'varie']]);
    expect(\App\Services\Gestionale\NotaSinteticaDelRendiconto::conLOrdineDelGiorno($stampa, $s->e)['parti'][0]['da_decidere'])
        ->toBe(['1. Approvazione del rendiconto 2026.', '2. Sostituzione del portone d\'ingresso.', '3. Varie ed eventuali.'])
        ->and(\App\Services\Gestionale\NotaSinteticaDelRendiconto::conLOrdineDelGiorno(['parti' => [], 'vuota' => true, 'spiegati' => 0], $s->e)['parti'][0]['chiave'])
        ->toBe('questioni');
});

it('«Per quale assemblea» con un\'assemblea senza il punto del rendiconto, o senza «Gestisce le assemblee»: la versione non nasce', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $senzaPunto = ViaRoma12::ordinaria($s, [['testo' => 'Sostituzione del portone d\'ingresso', 'materia' => 'lavori']]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s->c, $s->e]), ['parti' => ['riepilogo'], 'assemblea_id' => $senzaPunto->id])
        ->assertSessionHasErrors(['assemblea_id' => 'Questa assemblea non ha, in preparazione, il punto del rendiconto di questo esercizio.']);
    expect(RendicontoVersione::query()->where('esercizio_id', $s->e->id)->count())->toBe(0);

    $conPunto = ViaRoma12::ordinaria($s, [['testo' => 'Approvazione del rendiconto 2026', 'materia' => 'rendiconto', 'esercizio_id' => $s->e->id]]);
    $s->admin->revokePermissionTo(\App\Enums\Permission::MANAGE_ASSEMBLEE->value);
    $s->admin->roles->each(fn ($r) => $r->revokePermissionTo(\App\Enums\Permission::MANAGE_ASSEMBLEE->value));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->actingAs($s->admin->refresh())->post(route('admin.gestionale.esercizi.rendiconto.versioni.store', [$s->c, $s->e]), ['parti' => ['riepilogo'], 'assemblea_id' => $conPunto->id])
        ->assertSessionHasErrors(['assemblea_id' => 'Per legare la versione a un\'assemblea serve «Gestisce le assemblee».']);
    expect(RendicontoVersione::query()->where('esercizio_id', $s->e->id)->count())->toBe(0);
});
