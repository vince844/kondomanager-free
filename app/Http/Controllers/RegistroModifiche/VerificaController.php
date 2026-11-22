<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\RegistroModifiche;

use App\Http\Controllers\Controller;
use App\Models\RegistroModifica;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Services\RegistroModifiche\RegistroPerLaPagina;
use App\Services\RegistroModifiche\VerificaDelRegistro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Response;

/**
 * «Verifica l'integrità» (scena 5 della bozza, §3 e §14 del progetto della tappa 1): rilegge la catena delle impronte e dice una delle due frasi della bozza;
 * quando una riga non torna, «Scarica il rapporto» dà il PDF con le righe che non tornano e il motivo. Il registro è uno per tutto il programma: la verifica
 * è della catena intera, dalla pagina di un condominio come da quella dello studio. Solo chi ha «Vede il registro delle modifiche».
 */
class VerificaController extends Controller
{
    /** I motivi con le parole del rapporto. */
    public const MOTIVI = [
        'valori' => 'I valori sono stati cambiati fuori dal programma',
        'impronta' => 'L\'impronta della riga non torna',
        'catena' => 'La riga non è legata a quella prima',
        'mancante' => 'Manca la riga prima di questa',
        'inizio' => 'La catena non comincia dove dovrebbe',
        'oscuramento' => 'Riga segnata oscurata senza un oscuramento che la nomini',
        'fine' => 'L\'ultima riga non è quella che il programma ricorda',
        'aggiunta' => 'Riga aggiunta fuori dal programma: non ha la firma del programma, e non è nella catena',
        'firma' => 'La firma della riga non torna: cambiata o aggiunta fuori dal programma, con le impronte ricalcolate',
    ];

    public function verifica(Request $request): JsonResponse
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));
        $esito = VerificaDelRegistro::verifica();

        return response()->json($esito->toArray() + [
            'titolo' => $esito->integro ? 'Il registro è integro.' : (count($esito->nonTornano) === 1 ? 'Una riga non torna.' : count($esito->nonTornano) . ' righe non tornano.'),
            'integrita' => RegistroPerLaPagina::integrita(VerificaDelRegistro::ultima()),
        ]);
    }

    public function rapporto(Request $request, PdfService $pdf): Response
    {
        Gate::allowIf(AccessoAlRegistro::vedeIlRegistro($request->user()), __('errors.403_message'));
        $esito = VerificaDelRegistro::verifica();
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        $righe = collect($esito->nonTornano)->map(function (array $n) use ($fuso) {
            $riga = $n['riga_id'] === null ? null : RegistroModifica::query()->find($n['riga_id']);

            return [
                'riga_id' => $n['riga_id'],
                'sequenza' => $n['sequenza'],
                'quando' => $riga?->avvenuta_il->copy()->setTimezone($fuso)->format('d/m/Y H:i'),
                'chi' => $riga?->autore_nome,
                'oggetto' => $riga?->oggetto_nome,
                'motivo' => self::MOTIVI[$n['motivo']] ?? $n['motivo'],
            ];
        })->all();

        $mpdf = $pdf->generate('pdf.registro_modifiche.rapporto_verifica', [
            'gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI,
            'titolo' => 'Rapporto della verifica del registro delle modifiche',
            'titolo_stampa' => 'Rapporto della verifica del registro delle modifiche',
            'senza_firma' => true,
            'esito' => $esito,
            'frase' => $esito->frase(),
            'righe' => $righe,
            'verificata_il' => $esito->verificataIl->copy()->setTimezone($fuso)->format('d/m/Y \a\l\l\e H:i'),
        ]);
        $nome = 'rapporto-verifica-registro-' . now($fuso)->format('Y-m-d-His') . '.pdf';

        return response($mpdf->Output($nome, \Mpdf\Output\Destination::STRING_RETURN))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $nome . '"');
    }
}
