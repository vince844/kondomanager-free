<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti\Conti;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContoResource extends JsonResource
{
    /**
     * Mappa precalcolata dal BudgetCoverageService.
     * Popolata dal PianoContiController::show() prima di chiamare la collection.
     * Formato: [conto_id => importo_pianificato_centesimi]
     */
    public static array $coverageMap = [];

    public function toArray(Request $request): array
    {
        // ---------------------------------------------------------
        // 1. IMPEGNATO — letto dalla mappa precalcolata dal Service
        //
        // Il BudgetCoverageService ha già gestito:
        //   - Fondi diretti fissi
        //   - NULL = "A Saldo" (copre l'intero preventivo residuo)
        //   - Push-down dal padre distribuito equamente tra i figli con deficit
        //   - Spostamenti in entrata (generano over oltre il preventivo)
        // ---------------------------------------------------------
        $impegnato = (int) (self::$coverageMap[$this->id] ?? 0);

        // ---------------------------------------------------------
        // 2. DETTAGLIO VISIVO per la tabella nel pannello dettaglio
        //
        // Il Service non produce il formato riga-per-riga atteso dal
        // frontend, quindi lo costruiamo qui — ma NON ricalcoliamo
        // l'impegnato totale: usiamo solo quello della mappa.
        // ---------------------------------------------------------
        $fondiDiretti      = $this->pianiRate()->withPivot(['importo', 'note'])->get();
        $dettaglioPiani    = [];
        $hasCoperturaNULL  = false;
        $totaleNonSpostati = 0;

        // Passo 1: identifica NULL e somma fissi non-spostamento
        foreach ($fondiDiretti as $piano) {
            $nota          = $piano->pivot->note ?? '';
            $isSpostamento = str_contains(strtolower($nota), 'sposta spesa') ||
                             str_contains(strtolower($nota), 'spostamento');

            if (is_null($piano->pivot->importo)) {
                $hasCoperturaNULL = true;
            } elseif (!$isSpostamento) {
                $totaleNonSpostati += (int) $piano->pivot->importo;
            }
        }

        // Passo 2: costruisci le righe visive
        foreach ($fondiDiretti as $piano) {
            $nota          = $piano->pivot->note ?? '';
            $isSpostamento = str_contains(strtolower($nota), 'sposta spesa') ||
                             str_contains(strtolower($nota), 'spostamento');

            if (is_null($piano->pivot->importo)) {
                // NULL = "A Saldo": mostra quanto copre visivamente
                $valore = max(0, $this->importo - $totaleNonSpostati);

                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'      => $piano->nome,
                        'importo'    => $valore,
                        'fonte'      => 'diretta',
                        'is_shifted' => false,
                        'is_auto'    => true,   // badge "A Saldo"
                        'note'       => $nota,
                    ];
                }
            } else {
                $valore = (int) $piano->pivot->importo;

                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'      => $piano->nome,
                        'importo'    => $valore,
                        'fonte'      => 'diretta',
                        'is_shifted' => $isSpostamento,
                        'is_auto'    => false,
                        'note'       => $nota,
                    ];
                }
            }
        }

        // Passo 3: aggiunge riga push-down visiva se necessario
        // La quota push-down = impegnato (dal Service) - totale righe dirette visive
        $totaleDirettoVisivo  = array_sum(array_column($dettaglioPiani, 'importo'));
        $quotaIndirettaVisiva = $impegnato - $totaleDirettoVisivo;

        if (!$hasCoperturaNULL && $quotaIndirettaVisiva > 0 && $this->parent_id && $this->parent) {
            $fondiPadre = $this->parent->pianiRate()->withPivot('importo')->get();

            foreach ($fondiPadre as $pianoPadre) {
                if (!is_null($pianoPadre->pivot->importo) && (int) $pianoPadre->pivot->importo > 0) {
                    $dettaglioPiani[] = [
                        'piano'      => $pianoPadre->nome,
                        'importo'    => $quotaIndirettaVisiva,
                        'fonte'      => 'indiretta',
                        'is_shifted' => false,
                        'is_auto'    => false,
                        'note'       => '',
                    ];
                    break;
                }
            }
        }

        // ---------------------------------------------------------
        // 3. STATO COPERTURA
        // ---------------------------------------------------------
        $percentualeCopertura = 0;
        $statoCopertura       = 'empty';

        if ($this->importo > 0) {
            $percentualeCopertura = round(($impegnato / $this->importo) * 100, 1);

            if ($impegnato === 0) {
                $statoCopertura = 'empty';
            } elseif ($impegnato < $this->importo) {
                $statoCopertura = 'partial';
            } elseif ($impegnato === $this->importo) {
                $statoCopertura = 'full';
            } else {
                $diff           = abs($impegnato - $this->importo);
                $statoCopertura = ($diff <= 100) ? 'full' : 'over';
            }
        }

        // ---------------------------------------------------------
        // 4. RETURN
        // ---------------------------------------------------------
        return [
            'id'             => $this->id,
            'piano_conto_id' => $this->piano_conto_id,
            'parent_id'      => $this->parent_id,
            'importo'        => MoneyHelper::format($this->importo),
            'importo_raw'    => $this->importo,
            'nome'           => $this->nome,
            'descrizione'    => $this->descrizione,
            'tipo'           => $this->tipo,
            'note'           => $this->note,
            'codice'         => $this->codice,

            'default_fornitore_id' => $this->default_fornitore_id,
            'fornitore_nome'       => $this->fornitore ? $this->fornitore->ragione_sociale : null,
            'tipo_spesa'           => $this->tipo_spesa,

            'impegnato'             => $impegnato,
            'percentuale_copertura' => $percentualeCopertura,
            'stato_copertura'       => $statoCopertura,
            'piani_collegati'       => $this->pianiRate->pluck('nome'),
            'dettaglio_copertura'   => $dettaglioPiani,

            'has_rate_emesse' => $this->has_rate_emesse,

            'sottoconti' => $this->whenLoaded('sottoconti', function () {
                return ContoResource::collection($this->sottoconti);
            }),

            'tabelle_millesimali' => $this->whenLoaded('tabelleMillesimali', function () {
                return $this->tabelleMillesimali->map(function ($tm) {
                    return [
                        'id'           => $tm->id,
                        'tabella_id'   => $tm->tabella_id,
                        'coefficiente' => (float) $tm->coefficiente,
                        'tabella'      => $tm->tabella ? [
                            'id'   => $tm->tabella->id,
                            'nome' => $tm->tabella->nome,
                        ] : null,
                        'ripartizioni' => $tm->ripartizioni->map(function ($r) {
                            return [
                                'id'          => $r->id,
                                'soggetto'    => $r->soggetto,
                                'percentuale' => (float) $r->percentuale,
                            ];
                        }),
                    ];
                });
            }),
        ];
    }
}