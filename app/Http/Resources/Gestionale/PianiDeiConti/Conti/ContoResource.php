<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti\Conti;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContoResource extends JsonResource
{
    public static array $coverageMap = [];
    public static array $extraPianiNames = [];
    public static array $pianiCoinvoltiMap = [];
    public static array $pianiStraordinariMap = [];
    
    // Nuove mappe aggiunte per il widget
    public static array $addebitiMap = []; 
    public static array $strategieSforoMap = []; 
    public static array $budgetOriginaliMap = []; 

    public function toArray(Request $request): array
    {
        $impegnato = (int) (self::$coverageMap[$this->id] ?? 0);

        $fondiDiretti      = $this->pianiRate()->withPivot(['importo', 'note'])->get();
        $dettaglioPiani    = [];
        $hasCoperturaNULL  = false;
        $totaleNonSpostati = 0;

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

        foreach ($fondiDiretti as $piano) {
            $nota          = $piano->pivot->note ?? '';
            $isSpostamento = str_contains(strtolower($nota), 'sposta spesa') ||
                             str_contains(strtolower($nota), 'spostamento');
            
            $statoPiano = $piano->stato instanceof \App\Enums\StatoPianoRate 
                ? $piano->stato->value 
                : $piano->stato;

            if (is_null($piano->pivot->importo)) {
                $valore = max(0, $this->importo - $totaleNonSpostati);
                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'         => $piano->nome,
                        'piano_rate_id' => $piano->id, 
                        'stato'         => $statoPiano,
                        'importo'       => $valore,
                        'fonte'         => 'diretta',
                        'is_shifted'    => false,
                        'is_auto'       => true,
                        'note'          => $nota,
                    ];
                }
            } else {
                $valore = (int) $piano->pivot->importo;
                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'         => $piano->nome,
                        'piano_rate_id' => $piano->id, 
                        'stato'         => $statoPiano,
                        'importo'       => $valore,
                        'fonte'         => 'diretta',
                        'is_shifted'    => $isSpostamento,
                        'is_auto'       => false,
                        'note'          => $nota,
                    ];
                }
            }
        }

        $totaleDirettoVisivo  = array_sum(array_column($dettaglioPiani, 'importo'));
        $quotaIndirettaVisiva = 0;
        $quotaPushDownTarget  = $impegnato - $totaleDirettoVisivo;

        if (!$hasCoperturaNULL && $quotaPushDownTarget > 0 && $this->parent_id && $this->parent) {
            $fondiPadre = $this->parent->pianiRate()->withPivot('importo')->get();
            foreach ($fondiPadre as $pianoPadre) {
                if (!is_null($pianoPadre->pivot->importo) && (int) $pianoPadre->pivot->importo > 0) {
                    $quotaIndirettaVisiva = $quotaPushDownTarget;
                    $dettaglioPiani[] = [
                        'piano'         => $pianoPadre->nome,
                        'piano_rate_id' => $pianoPadre->id, 
                        'stato'         => $pianoPadre->stato instanceof \App\Enums\StatoPianoRate ? $pianoPadre->stato->value : $pianoPadre->stato,
                        'importo'       => $quotaIndirettaVisiva,
                        'fonte'         => 'indiretta',
                        'is_shifted'    => false,
                        'is_auto'       => false,
                        'note'          => '',
                    ];
                    break;
                }
            }
        }

        $mancanteStraordinario = $impegnato - $totaleDirettoVisivo - $quotaIndirettaVisiva;
 
        if ($mancanteStraordinario > 0) {
            $pianiStr = self::$pianiStraordinariMap[$this->id] ?? [];
        
            if (!empty($pianiStr)) {
                // Caso ideale: abbiamo la granularità piano-per-piano
                foreach ($pianiStr as $pianoStr) {
                    $dettaglioPiani[] = [
                        'piano'         => $pianoStr['nome'],
                        'piano_rate_id' => $pianoStr['id'],      
                        'stato'         => $pianoStr['stato'],
                        'importo'       => $pianoStr['importo'],
                        'fonte'         => 'diretta',
                        'is_shifted'    => false,
                        'is_auto'       => false,
                        'note'          => 'Copertura da piano rate straordinario.',
                    ];
                }
            } else {
                // Fallback: nessun piano straordinario tracciabile per questo conto
                // (es. vecchi dati senza pivot importo_collegato)
                $nomeRiga = !empty(self::$extraPianiNames)
                    ? implode(', ', self::$extraPianiNames)
                    : 'Piano rate straordinario';
        
                $dettaglioPiani[] = [
                    'piano'         => $nomeRiga,
                    'piano_rate_id' => null,         
                    'stato'         => 'approvato',
                    'importo'       => $mancanteStraordinario,
                    'fonte'         => 'diretta',
                    'is_shifted'    => false,
                    'is_auto'       => false,
                    'note'          => 'Copertura generata dal piano rate straordinario.',
                ];
            }
        }

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

        // Check 1: piani ordinari via piano_rate_capitoli (logica esistente)
        $hasHardLock = $this->has_rate_emesse;

        // Check 2: piani straordinari via piano_rate_fatture (nuovo)
        if (!$hasHardLock) {
            $pianiStr = self::$pianiStraordinariMap[$this->id] ?? [];
            foreach ($pianiStr as $pianoStr) {
                if (in_array(strtolower($pianoStr['stato']), ['approvato', 'emesso', 'chiuso'])) {
                    $hasHardLock = true;
                    break;
                }
            }
        }

        $pianiCollegati = $this->pianiRate->pluck('nome')->toArray();
        if ($mancanteStraordinario > 0) {
            $pianiCollegati[] = 'Piano Rate Straordinario';
        }

        return [
            'id'                    => $this->id,
            'piano_conto_id'        => $this->piano_conto_id,
            'parent_id'             => $this->parent_id,
            'importo'               => MoneyHelper::format($this->importo),
            'importo_raw'           => $this->importo,
            // Valori aggiunti per il widget Audit:
            'budget_originale_raw'  => self::$budgetOriginaliMap[$this->id] ?? $this->importo,
            'addebiti_personali'    => self::$addebitiMap[$this->id] ?? [],
            'strategie_sforo'       => self::$strategieSforoMap[$this->id] ?? [],
            // ------------------------------------
            'nome'                  => $this->nome,
            'descrizione'           => $this->descrizione,
            'tipo'                  => $this->tipo,
            'note'                  => $this->note,
            'is_tecnico'            => (bool) $this->is_tecnico,
            'codice'                => $this->codice,
            'default_fornitore_id'  => $this->default_fornitore_id,
            'fornitore_nome'        => $this->fornitore ? $this->fornitore->ragione_sociale : null,
            'tipo_spesa'            => $this->tipo_spesa,
            'impegnato'             => $impegnato,
            'percentuale_copertura' => $percentualeCopertura,
            'stato_copertura'       => $statoCopertura,
            'piani_collegati'       => $pianiCollegati,
            'dettaglio_copertura'   => $dettaglioPiani,
            'has_rate_emesse'       => $hasHardLock,
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