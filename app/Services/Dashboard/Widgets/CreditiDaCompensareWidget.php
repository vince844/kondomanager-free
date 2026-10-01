<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\DashboardWidget;
use App\Services\Gestionale\CreditoService;
use Illuminate\Support\Collection;

class CreditiDaCompensareWidget implements DashboardWidget
{
    private ?Collection $cache = null;

    public function __construct(
        private readonly CreditoService $creditoService
    ) {}

    public function key(): string
    {
        return 'crediti_da_compensare';
    }

    public function isVisible(int $condominioId): bool
    {
        // Nascosto se non c'è nessun credito da segnalare, come suggerito
        // dal contratto (stesso principio del Radar Salute Contabile).
        return $this->crediti($condominioId)->isNotEmpty();
    }

    public function payload(int $condominioId): array
    {
        return $this->crediti($condominioId)
            ->map(function ($c) use ($condominioId) {
                $comp = $c['compensabile'];
                $bersaglio = $comp['rate_coperte'][0] ?? null;

                // Il link porta la rata bersaglio, e **non** `intent_usa_credito`: quel
                // parametro dichiara una richiesta arrivata dal condòmino, e qui a muoversi è
                // l'amministratore di sua iniziativa. Scriverlo accenderebbe un avviso che
                // attribuisce a qualcun altro una decisione che non ha preso.
                $parametri = [
                    'condominio'            => $condominioId,
                    'prefill_anagrafica_id' => $c['anagrafica_id'],
                ];
                if ($bersaglio) {
                    $parametri['prefill_rata_id'] = $bersaglio['rata_id'];
                }

                // «Azionabile» non è «ha un bersaglio»: quando il credito sta su un'altra
                // gestione il consiglio non lo propone — per disegno, serve la scelta
                // dell'amministratore, decisione 30.11 — ma la frase gli dice che si può fare. Se la riga in
                // quel caso non fosse cliccabile, l'inviterebbe a un vicolo cieco.
                $azionabile = $comp['importo_cents'] > 0 || ($comp['debito_altrove_cents'] ?? 0) > 0;

                return [
                    'anagrafica_id'          => $c['anagrafica_id'],
                    'azionabile'             => $azionabile,
                    'nome'                   => $c['nome'],
                    'totale_formatted'       => $c['totale_formatted'],
                    'compensabile_cents'     => $comp['importo_cents'],
                    'compensabile_formatted' => $comp['importo_formatted'],
                    'copre'                  => $comp['frase'],
                    'rata_bersaglio_id'      => $bersaglio['rata_id'] ?? null,
                    'url'                    => route('admin.gestionale.movimenti-rate.create', $parametri),
                    // B2, S6: la seconda uscita della riga. Il rimborso vive nell'estratto conto della persona — è lì
                    // che sta il pulsante «Rimborsa il credito» — e per chi è uscito dal condominio è l'unica strada.
                    'url_estratto_conto'     => route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $condominioId, 'anagrafica' => $c['anagrafica_id']]),
                ];
            })
            ->values()
            ->toArray();
    }


    private function crediti(int $condominioId): Collection
    {
        // Memoizzato: isVisible() e payload() vengono chiamati entrambi sulla
        // stessa istanza per la stessa richiesta (WidgetManager::getPayloads).
        return $this->cache ??= $this->creditoService->perCondominio($condominioId);
    }
}
