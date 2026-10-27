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
 * dettaglio (installata, database, migrazioni, versione) lo vede chi chiama dalla macchina stessa o
 * da una rete privata: l'HEALTHCHECK, l'orchestratore, chi amministra il server.
 *
 * Il dettaglio va solo a chi chiama direttamente, senza intestazioni di inoltro. Una richiesta che le
 * porta è passata da un proxy, e l'indirizzo che si vede può essere quello del proxy: dietro un nginx
 * sulla stessa macchina, senza `TRUSTED_PROXIES`, è 127.0.0.1 per chiunque arrivi da internet. Le
 * chiamate di chi orchestra (HEALTHCHECK su 127.0.0.1, l'orchestratore sulla rete dei container) non
 * passano da un proxy e non le portano (Coda 268).
 */
class SaluteController extends Controller
{
    private const RETI_PRIVATE = [
        '127.0.0.0/8', '::1/128',
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7',
    ];

    /** Le intestazioni che un proxy aggiunge: quelle che `TrustProxies` legge, `Forwarded` e `X-Real-IP`. */
    private const INTESTAZIONI_DI_INOLTRO = [
        'Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Port', 'X-Forwarded-Proto',
        'X-Forwarded-Prefix', 'X-Real-IP',
    ];

    public function __invoke(Request $request, StatoDiSalute $stato): JsonResponse
    {
        $esito = $stato->rileva();
        $corpo = ['stato' => $esito['sana'] ? 'ok' : 'non_disponibile'];

        if ($this->chiamataDiretta($request) && IpUtils::checkIp((string) $request->ip(), self::RETI_PRIVATE)) {
            // La versione la legge chi orchestra le istanze, anche da una risposta 503 (Coda 268).
            $corpo += $esito + ['versione' => (string) config('app.version')];
        }

        return response()->json($corpo, $esito['sana'] ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    private function chiamataDiretta(Request $request): bool
    {
        foreach (self::INTESTAZIONI_DI_INOLTRO as $intestazione) {
            if ($request->headers->has($intestazione)) {
                return false;
            }
        }

        return true;
    }
}
