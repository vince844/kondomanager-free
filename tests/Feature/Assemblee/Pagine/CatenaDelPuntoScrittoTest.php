<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\Maggioranza;
use App\Models\Assemblee\Assemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): la catena del punto scritto in «Prepara l'assemblea», da un capo all'altro, dalle rotte, con ciò che l'amministratore vede ai
 * due capi.
 *
 * Il punto scritto nella pagina «Prepara l'assemblea» → l'assemblea nasce → la pagina dell'assemblea ha il punto nell'ordine del giorno, al suo posto (dopo il
 * proposto spuntato, prima di «Varie ed eventuali»), con la sua maggioranza → «Stampa le lettere»: l'avviso di convocazione lo stampa, con il suo numero, e
 * la riga dell'invio lo conserva.
 *
 * Che cosa non copre: la finestra del punto a video (`FinestraDelPuntoNellaPagina.test.ts`); l'aspetto del PDF.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
    $this->withoutVite();
});
afterEach(fn () => Carbon::setTestNow());

it('il punto scritto in «Prepara l\'assemblea» nasce con l\'assemblea, sta nell\'ordine del giorno con la sua maggioranza e l\'avviso lo stampa', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $this->actingAs($s->admin);
    $portone = 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00';

    // Un capo: la pagina «Prepara l'assemblea», con «Varie ed eventuali» proposto; il punto scritto parte con le date.
    $pagina = $this->get(route('admin.gestionale.assemblee.create', $s->c))->assertOk()->viewData('page')['props'];
    $proposte = collect($pagina['proposte'])->flatMap(fn ($g) => $g['punti'])->pluck('chiave_fonte')->all();
    expect($proposte)->toContain('varie');
    $r = $this->post(route('admin.gestionale.assemblee.store', $s->c), [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale, Via Roma 12', 'tabella_id' => $s->tabelle['Proprietà']->id,
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
        'proposte' => $proposte,
        'punti' => [['testo' => $portone, 'testo_pubblico' => 'Il portone d\'ingresso', 'materia' => 'lavori', 'maggioranza' => 'meta_del_valore',
            'conduttore' => null, 'ambito' => 'condominio', 'palazzina_id' => null, 'scala_id' => null, 'tabella_id' => null, 'importo' => '14.500,00']],
    ]);
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();
    $r->assertSessionHasNoErrors()->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'documenti']));

    // La pagina dell'assemblea: il punto nell'ordine del giorno, prima di «Varie ed eventuali», con la sua maggioranza; scritto a mano, non un sospeso.
    $ordine = collect($this->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()->viewData('page')['props']['ordine']);
    $punto = $ordine->firstWhere('testo', $portone);
    expect($punto)->not->toBeNull()
        ->and($ordine->last()['testo'])->toBe('Varie ed eventuali')
        ->and($punto['numero'])->toBe($ordine->count() - 1)
        ->and($punto['maggioranza'])->toBe('meta_del_valore')
        ->and($punto['maggioranza_parola'])->toBe(Maggioranza::META_DEL_VALORE->parola())
        ->and($punto['importo'])->toBe(1450000)
        ->and($punto['fonte'])->toBe('a_mano')->and($punto['chiave_fonte'])->toBeNull();

    // L'altro capo: «Stampa le lettere». L'avviso stampa il punto con il suo numero, e la riga dell'invio di ognuno lo conserva.
    $dati = null;
    View::composer('pdf.gestionale.assemblea_convocazione', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $testo = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(view('pdf.gestionale.assemblea_convocazione', $dati)->render()), ENT_QUOTES));
    expect($testo)->toContain($punto['numero'] . '. ' . $portone);

    $invii = RegistroInvio::query()->where('documentabile_id', $a->id)->where('tipo', 'convocazione')->get();
    expect($invii)->not->toBeEmpty();
    foreach ($invii as $invio) {
        expect($invio->dati['punti'])->toContain(['numero' => $punto['numero'], 'testo' => $portone]);
    }
});
