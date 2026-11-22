<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Documenti\ArchivioDocumenti;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/*
 * I documenti dei punti di Via Roma 12 per le prove del passo «Documenti» (non è un test): un PDF vero di quante pagine si vuole, un documento
 * dell'archivio del condominio, la fattura n. 45 con il suo PDF, l'assemblea con la ratifica, le rotte e la riga del passo di un punto.
 */

/** Un PDF vero di quante pagine si vuole. */
function ddpPdf(int $pagine = 1, string $testo = 'Documento'): string
{
    $m = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
    for ($i = 1; $i <= $pagine; $i++) {
        if ($i > 1) {
            $m->AddPage();
        }
        $m->WriteHTML('<p>' . $testo . ', pagina ' . $i . '</p>');
    }

    return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/** Un documento dell'archivio del condominio, con la sua categoria. */
function ddpDelCondominio($s, string $nome, int $pagine = 1, string $mime = 'application/pdf', ?string $categoria = null, ?int $condominioId = null): Documento
{
    $pdf = $mime === 'application/pdf';
    $contenuto = $pdf ? ddpPdf($pagine, $nome) : 'non un PDF';
    $d = Documento::create(['name' => $nome, 'description' => $nome, 'path' => app(ArchivioDocumenti::class)->salvaContenuto($contenuto, $pdf ? 'pdf' : 'jpg',
        'documenti/prova'), 'mime_type' => $mime, 'file_size' => strlen($contenuto), 'created_by' => $s->admin->id, 'is_published' => false, 'is_approved' => true]);
    $d->condomini()->attach($condominioId ?? $s->c->id);
    if ($categoria !== null) {
        $d->categorie()->attach(CategoriaDocumento::query()->firstOrCreate(['name' => $categoria], ['description' => $categoria])->id);
    }

    return $d;
}

/** La fattura n. 45 in sforo con il suo PDF di due pagine (e un file che non è un PDF, che non si propone). */
function ddpFatturaConIlPdf($s): array
{
    $fattura = ViaRoma12::fatturaInSforo($s);
    $contenuto = ddpPdf(2, 'Fattura n. 45');
    $doc = $fattura->documenti()->create(['name' => 'fattura-45.pdf', 'description' => 'Fattura passiva n. 45', 'path' => app(ArchivioDocumenti::class)
        ->salvaContenuto($contenuto, 'pdf', 'documenti/' . $s->c->id), 'mime_type' => 'application/pdf', 'file_size' => strlen($contenuto),
        'created_by' => $s->admin->id, 'is_published' => false, 'is_approved' => true]);
    $fattura->documenti()->create(['name' => 'fattura-45.xml', 'description' => 'Fattura passiva n. 45', 'path' => app(ArchivioDocumenti::class)
        ->salvaContenuto('<xml/>', 'xml', 'documenti/' . $s->c->id), 'mime_type' => 'text/xml', 'file_size' => 6, 'created_by' => $s->admin->id,
        'is_published' => false, 'is_approved' => true]);

    return [$fattura, $doc];
}

/** L'assemblea con la ratifica della fattura (punto 1), i lavori del portone (punto 2), il rendiconto (3), il preventivo (4) e le varie. */
function ddpAssemblea($s, $fattura): Assemblea
{
    return ConvocazioneDiViaRoma12::ordinaria($s, [], [
        ['testo' => 'Ratifica della spesa di € 1.800,00 per la colonna dell\'acqua', 'materia' => 'ratifica', 'oggetti' => [['fattura_passiva', $fattura->id, 'origine']]],
        ['testo' => 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00', 'materia' => 'lavori'],
        ['testo' => 'Approvazione del rendiconto 2025 e dello stato di riparto', 'materia' => 'rendiconto'],
        ['testo' => 'Approvazione del preventivo 2027 e del piano di riparto', 'materia' => 'preventivo'],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
    ]);
}

function ddpRotta(string $nome, $s, Assemblea $a, int $numero, array $piu = []): string
{
    return route('admin.gestionale.assemblee.fascicolo.' . $nome, ['condominio' => $s->c->id, 'assemblea' => $a->id,
        'punto' => $a->punti()->where('numero', $numero)->value('id')] + $piu);
}

/** La riga del passo per il punto con quel numero. */
function ddpRiga(Assemblea $a, int $numero): array
{
    return collect(app(FascicoloDellAssemblea::class)->perLaPagina($a)['righe'])->firstWhere('numero', (string) $numero);
}
