<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\Convocazione\UnioneDiPdf;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';
require_once __DIR__ . '/DocumentiDiViaRoma12.php';

/**
 * 1.12.0-beta.13, le catene dei documenti dei punti, ognuna da un capo all'altro con le rotte della pagina:
 *
 * 1. la fattura n. 45 in sforo con il suo PDF → il punto della ratifica che ne parla → la proposta «Dalla fattura» nel passo «Documenti» → «Aggiungi» →
 *    il fascicolo composto ha le pagine della fattura → la PEC della convocazione porta il documento, e la riga dell'invio lo elenca;
 * 2. un PDF nuovo caricato dal punto → si trova nei documenti del condominio, nella categoria «Assemblee», e l'elenco dei documenti lo mostra;
 * 3. «Togli» → il fascicolo composto senza il documento → la riga nel diario dell'assemblea.
 *
 * Che cosa non copre: le pagine a video (le prove della pagina e della finestra).
 */

beforeEach(function () {
    $this->withoutVite();
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** L'ordinaria della convocazione, con il punto 3 della ratifica che parla della fattura. */
function cddAssemblea($s, $fattura)
{
    return ConvocazioneDiViaRoma12::ordinaria($s, [], [
        ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
        ['testo' => 'Approvazione del preventivo 2027 e del piano di riparto', 'materia' => 'preventivo'],
        ['testo' => 'Comunicazione dei lavori urgenti alla colonna dell\'acqua e ratifica della spesa di € 1.800,00', 'materia' => 'ratifica',
            'oggetti' => [['fattura_passiva', $fattura->id, 'origine']]],
        ['testo' => 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00', 'materia' => 'lavori'],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
    ]);
}

/** La visita parziale della pagina, come la fa la pagina quando apre il passo o il cassetto: con la versione degli asset di quella pagina. */
function cddParziale($t, string $url, string $prop): array
{
    $versione = (string) $t->get($url)->assertOk()->viewData('page')['version'];

    return (array) $t->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Inertia-Partial-Component' => 'gestionale/assemblee/Assemblea',
        'X-Inertia-Partial-Data' => $prop])->assertOk()->json('props.' . $prop);
}

it('catena 1: la fattura della ratifica → la proposta «Dalla fattura» → «Aggiungi» → il fascicolo con le sue pagine → la PEC la porta', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura, $doc] = ddpFatturaConIlPdf($s);
    $a = cddAssemblea($s, $fattura);
    $this->actingAs($s->admin);

    // La pagina chiede il passo «Documenti»: la riga del punto 3 propone il PDF della fattura, che non c'è ancora.
    $fascicolo = cddParziale($this, route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'documenti']), 'fascicolo');
    $riga = collect($fascicolo['righe'])->firstWhere('numero', '3');
    expect($riga['proposte'][0]['da'])->toBe('Proposto dalla fattura n. 45')
        ->and($riga['proposte'][0]['documenti'][0]['id'])->toBe($doc->id)
        ->and($riga['documenti'])->toBe([]);
    $senza = UnioneDiPdf::pagine((string) $this->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))->assertOk()->getContent());

    // «Aggiungi» nella riga della proposta.
    $this->post(ddpRotta('documenti.store', $s, $a, 3), ['documenti' => [$doc->id]])->assertSessionHasNoErrors();

    $composto = $this->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))->assertOk()->assertHeader('X-Documenti', '3');
    expect(UnioneDiPdf::pagine((string) $composto->getContent()))->toBe($senza + 2)
        ->and(app(FascicoloDellAssemblea::class)->documenti($a)[0])->toMatchArray(['numero' => 3, 'file' => 'fattura-45.pdf', 'pagine' => 2]);

    $this->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $m = collect(ConvocazioneDiViaRoma12::$pecMandate)->first(fn ($p) => $p['messaggio']->getTo()[0]->getAddress() === 'mario.rossi@pec.it')['messaggio'];
    expect(array_map(fn ($p) => $p->getFilename(), $m->getAttachments()))->toBe(['convocazione-2026-12-15-rossi-mario.pdf', 'punto-3-fattura-45.pdf'])
        ->and(ConvocazioneDiViaRoma12::invio($a, $s, 'rossi')->dati['allegati'][0])->toMatchArray(['punto' => 3, 'file' => 'punto-3-fattura-45.pdf']);
});

it('catena 2: un PDF nuovo caricato dal punto → nei documenti del condominio, nella categoria «Assemblee», e nell\'elenco dei documenti', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = cddAssemblea($s, $fattura);

    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 4), ['file' => UploadedFile::fake()->createWithContent('Tre preventivi del portone.pdf', ddpPdf(3))])
        ->assertSessionHasNoErrors();

    $nuovo = Documento::query()->where('name', 'Tre preventivi del portone.pdf')->firstOrFail();
    $assemblee = CategoriaDocumento::query()->where('slug', 'assemblee')->firstOrFail();
    expect($nuovo->condomini()->pluck('condomini.id')->all())->toBe([$s->c->id])
        ->and($nuovo->categorie()->pluck('categorie_documento.id')->all())->toBe([$assemblee->id])
        ->and(app(ArchivioDocumenti::class)->esiste($nuovo->path))->toBeTrue();
    // L'elenco dei documenti dello studio lo mostra, con la categoria «Assemblee».
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::VIEW_ARCHIVE_DOCUMENTS->value, 'guard_name' => 'web']);
    $s->admin->givePermissionTo(\App\Enums\Permission::VIEW_ARCHIVE_DOCUMENTS->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $elenco = $this->get(route('admin.documenti.index', ['category_id' => [$assemblee->id], 'condominio_id' => [$s->c->id]]))->assertOk()
        ->viewData('page')['props']['documenti'];
    expect(collect($elenco)->pluck('name')->all())->toContain('Tre preventivi del portone.pdf');
    // E la finestra di un altro punto lo trova fra i documenti del condominio.
    $trovati = $this->getJson(ddpRotta('documenti', $s, $a, 5, ['cerca' => 'portone']))->assertOk()->json('condominio.documenti');
    expect($trovati[0])->toMatchArray(['id' => $nuovo->id, 'categorie' => 'Assemblee', 'pagine' => 3, 'gia' => false]);
});

it('catena 3: «Togli» → il fascicolo composto senza il documento → la riga nel diario', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura, $doc] = ddpFatturaConIlPdf($s);
    $a = cddAssemblea($s, $fattura);
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 3), ['documenti' => [$doc->id]])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))->assertOk()->assertHeader('X-Documenti', '3');

    $this->travel(1)->minutes();
    $this->delete(ddpRotta('documenti.destroy', $s, $a, 3, ['documento' => $doc->id]))->assertSessionHasNoErrors();

    $this->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))->assertOk()->assertHeader('X-Documenti', '2');
    expect(app(FascicoloDellAssemblea::class)->documenti($a))->toBe([]);
    $diario = cddParziale($this, route('admin.gestionale.assemblee.show', [$s->c, $a, 'diario_filtro' => 'modifiche']), 'diario')['righe'];
    expect(collect($diario)->pluck('frase')->all())->toContain('Documento tolto dal punto 3: «fattura-45.pdf»')
        ->and(collect($diario)->pluck('frase')->all())->toContain('Documento aggiunto al punto 3: «fattura-45.pdf»');
    // La proposta torna nella riga del punto.
    expect(collect(app(FascicoloDellAssemblea::class)->perLaPagina($a)['righe'])->firstWhere('numero', '3')['proposte'][0]['documenti'][0]['id'])->toBe($doc->id);
});
