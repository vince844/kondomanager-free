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
    public static array $extraPianiNames = [];
    
    // Aggiungiamo la proprietà mancante per evitare errori dal controller
    public static array $pianiCoinvoltiMap = [];

    public function toArray(Request $request): array
    {
        // ---------------------------------------------------------
        // 1. IMPEGNATO — letto dalla mappa precalcolata dal Service
        // ---------------------------------------------------------
        $impegnato = (int) (self::$coverageMap[$this->id] ?? 0);

        // ---------------------------------------------------------
        // 2. DETTAGLIO VISIVO per la tabella nel pannello dettaglio
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
            
            $statoPiano = $piano->stato instanceof \App\Enums\StatoPianoRate 
                ? $piano->stato->value 
                : $piano->stato;

            if (is_null($piano->pivot->importo)) {
                $valore = max(0, $this->importo - $totaleNonSpostati);
                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'      => $piano->nome,
                        'stato'      => $statoPiano,
                        'importo'    => $valore,
                        'fonte'      => 'diretta',
                        'is_shifted' => false,
                        'is_auto'    => true,
                        'note'       => $nota,
                    ];
                }
            } else {
                $valore = (int) $piano->pivot->importo;
                if ($valore > 0) {
                    $dettaglioPiani[] = [
                        'piano'      => $piano->nome,
                        'stato'      => $statoPiano,
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
        $totaleDirettoVisivo  = array_sum(array_column($dettaglioPiani, 'importo'));
        $quotaIndirettaVisiva = 0;
        $quotaPushDownTarget  = $impegnato - $totaleDirettoVisivo;

        if (!$hasCoperturaNULL && $quotaPushDownTarget > 0 && $this->parent_id && $this->parent) {
            $fondiPadre = $this->parent->pianiRate()->withPivot('importo')->get();
            foreach ($fondiPadre as $pianoPadre) {
                if (!is_null($pianoPadre->pivot->importo) && (int) $pianoPadre->pivot->importo > 0) {
                    $quotaIndirettaVisiva = $quotaPushDownTarget;
                    $dettaglioPiani[] = [
                        'piano'      => $pianoPadre->nome,
                        'stato'      => $pianoPadre->stato instanceof \App\Enums\StatoPianoRate ? $pianoPadre->stato->value : $pianoPadre->stato,
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

        // --- INIZIO FIX: INIEZIONE VISIVA PIANI STRAORDINARI ---
        // Se il BudgetCoverageService ha trovato soldi (impegnato) ma non ci sono 
        // righe visive sufficienti dai piani ordinari, il delta è chiaramente lo Straordinario!
        // --- INIZIO FIX: INIEZIONE VISIVA PIANI STRAORDINARI ---
        $mancanteStraordinario = $impegnato - $totaleDirettoVisivo - $quotaIndirettaVisiva;
        
        if ($mancanteStraordinario > 0) {
            // Usiamo il nome reale se disponibile, altrimenti testo fallback
            $nomeRiga = !empty(self::$extraPianiNames) ? implode(', ', self::$extraPianiNames) : 'Piano rate straordinario';
            
            $dettaglioPiani[] = [
                'piano'      => $nomeRiga, 
                'stato'      => 'approvato',
                'importo'    => $mancanteStraordinario,
                'fonte'      => 'diretta',
                'is_shifted' => false,
                'is_auto'    => false,
                'note'       => 'Copertura generata dal piano rate straordinario.',
            ];
        }
        // --- FINE FIX ---

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

        $hasHardLock = $this->pianiRate->contains(function ($piano) {
            $statoPuro = $piano->stato instanceof \App\Enums\StatoPianoRate ? $piano->stato->value : $piano->stato;
            return strtolower(trim((string)$statoPuro)) === 'approvato';
        });

        // Aggiungiamo i piani straordinari alla lista dei nomi collegati
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
            'nome'                  => $this->nome,
            'descrizione'           => $this->descrizione,
            'tipo'                  => $this->tipo,
            'note'                  => $this->note,
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