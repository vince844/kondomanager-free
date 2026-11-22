<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Documento;
use App\Models\RegistroModifica;
use App\Services\Assemblee\Convocazione\ProvaDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §4.4 del progetto della tappa 2): i controlli della consegna.
 *
 * - Per la raccomandata e il fax il giorno della consegna non viene prima della spedizione registrata.
 * - Gli esiti dipendono dal mezzo: per la PEC «Consegnata» o «Non consegnata»; a mano «Consegnata» o «Rifiutata»; per la raccomandata tutti.
 * - «Allega la prova» è una riga del registro delle modifiche con il nome e l'impronta del file; anche l'esito tiene nel registro la sua prova con
 *   l'impronta, e la prova della convocazione stampa quella dell'esito e dice se il file di oggi ha un'impronta diversa.
 *
 * Che cosa non copre: la ricevuta PEC letta dal file (`RicevutaPecSullaRigaTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function ccStampateESpedita($t, string $chi, string $giorno): array
{
    $s = ConvocazioneDiViaRoma12::nuovo($t);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $invio = ConvocazioneDiViaRoma12::invio($a, $s, $chi);
    ConvocazioneDiViaRoma12::oggi($giorno . ' 10:00');
    $t->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$invio->id], 'canale' => 'raccomandata', 'data' => $giorno])
        ->assertSessionHasNoErrors();

    return [$s, $a, $invio->refresh()];
}

it('la consegna di una raccomandata non può essere prima della sua spedizione', function () {
    [$s, $a, $marco] = ccStampateESpedita($this, 'marco', '2026-12-03');
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');

    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $marco]), ['esito' => 'consegnata', 'giorno' => '2026-12-01'])
        ->assertSessionHasErrors(['giorno' => 'La consegna non può essere prima della spedizione, del 03/12/2026.']);
    expect($marco->refresh()->esito)->toBeNull();
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $marco]), ['esito' => 'consegnata', 'giorno' => '2026-12-05'])
        ->assertSessionHasNoErrors();
});

it('gli esiti dipendono dal mezzo: «In giacenza» non vale per una PEC, «Non consegnata» non vale a mano', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $rossi = ConvocazioneDiViaRoma12::invio($a, $s, 'rossi');
    $anna = ConvocazioneDiViaRoma12::invio($a, $s, 'anna');

    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $rossi]), ['esito' => 'in_giacenza', 'giorno' => '2026-11-26'])
        ->assertSessionHasErrors(['esito' => 'Per una PEC l\'esito è «Consegnata» o «Non consegnata».']);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $anna]), ['esito' => 'non_consegnata', 'giorno' => '2026-11-26'])
        ->assertSessionHasErrors(['esito' => 'Per una consegna a mano l\'esito è «Consegnata» o «Rifiutata».']);
    expect($rossi->refresh()->ricevuta_il)->toBeNull()->and($anna->refresh()->esito)->toBeNull();
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $anna]), ['esito' => 'rifiutata', 'giorno' => '2026-11-26'])
        ->assertSessionHasNoErrors();
});

it('«Allega la prova» è una riga del registro con l\'impronta; la stampa mostra la prova dell\'esito e dice se il file è cambiato', function () {
    [$s, $a, $carla] = ccStampateESpedita($this, 'carla', '2026-12-02');
    ConvocazioneDiViaRoma12::oggi('2026-12-08 10:00');
    $giacenza = UploadedFile::fake()->createWithContent('avviso-giacenza.pdf', '%PDF-1.4 avviso di giacenza del 07/12');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-07',
        'prova' => $giacenza])->assertSessionHasNoErrors();
    $rigaDellEsito = RegistroModifica::query()->orderByDesc('id')->first();
    $documentoDellEsito = Documento::query()->where('name', 'avviso-giacenza.pdf')->first();
    expect($rigaDellEsito->lotto['prova'])->toBe(['documento_id' => $documentoDellEsito->id, 'nome' => 'avviso-giacenza.pdf',
        'impronta' => hash('sha256', '%PDF-1.4 avviso di giacenza del 07/12')]);

    $prima = ViaRoma12::ultimaRiga();
    $this->post(route('admin.gestionale.assemblee.invii.prova', [$s->c, $a, $carla]), [
        'prova' => UploadedFile::fake()->createWithContent('altra-cosa.pdf', '%PDF-1.4 un altro file')])->assertSessionHasNoErrors();
    $righe = ConvocazioneDiViaRoma12::righeDelRegistro($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toBe('Prova allegata alla raccomandata di Neri Carla: altra-cosa.pdf, impronta ' . mb_substr(hash('sha256', '%PDF-1.4 un altro file'), 0, 4) . '…')
        ->and($righe[0]->lotto['prova']['impronta'])->toBe(hash('sha256', '%PDF-1.4 un altro file'));
    // Nel «Diario» la prova allegata c'è una volta sola: la riga del registro, non anche il fatto della riga dell'invio.
    $diario = collect(\App\Services\Assemblee\DiarioDellAssemblea::righe($a->refresh())['righe'])->filter(fn ($r) => str_contains((string) $r['frase'], 'altra-cosa.pdf'));
    expect($diario)->toHaveCount(1);

    $riga = collect(app(ProvaDellaConvocazione::class)->dati($a->refresh())['righe'])->first(fn ($r) => str_starts_with($r['persona'], 'Neri Carla'));
    expect($riga['prova'])->toBe('avviso-giacenza.pdf · ' . mb_substr(hash('sha256', '%PDF-1.4 avviso di giacenza del 07/12'), 0, 12))
        ->and($riga['registro'])->toStartWith('n. ' . $rigaDellEsito->id . ' · ');

    // Il file sul disco sostituito: la stampa lo dice.
    Storage::disk(app(ArchivioDocumenti::class)->nomeDisco())->put($documentoDellEsito->path, '%PDF-1.4 sostituito');
    $riga = collect(app(ProvaDellaConvocazione::class)->dati($a)['righe'])->first(fn ($r) => str_starts_with($r['persona'], 'Neri Carla'));
    expect($riga['prova'])->toEndWith(' · il file di oggi ha un\'impronta diversa da quella registrata');
});
