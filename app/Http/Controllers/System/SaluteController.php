<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\StatoDiSalute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * `GET /up`: 200 se l'installazione è chiusa, il database risponde e non ci sono migrazioni da
 * applicare; 503 altrimenti. È la rotta che Docker (HEALTHCHECK) e chi orchestra i container
 * interrogano: registrata fuori dal gruppo `web` di proposito, così una richiesta ogni pochi
 * secondi non apre una sessione né passa dai middleware dell'interfaccia.
 *
 * È raggiungibile senza credenziali, quindi a chi arriva da internet dice solo lo stato. Il
 * dettaglio (installata, database, migrazioni) lo vede chi chiama dalla macchina stessa o da una
 * rete privata: l'HEALTHCHECK, l'orchestratore, chi amministra il server.
 */
class SaluteController extends Controller
{
    private const RETI_PRIVATE = [
        '127.0.0.0/8', '::1/128',
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7',
    ];

    public function __invoke(Request $request, StatoDiSalute $stato): JsonResponse
    {
        $esito = $stato->rileva();
        $corpo = ['stato' => $esito['sana'] ? 'ok' : 'non_disponibile'];

        if (IpUtils::checkIp((string) $request->ip(), self::RETI_PRIVATE)) {
            $corpo += $esito;
        }

        return response()->json($corpo, $esito['sana'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
