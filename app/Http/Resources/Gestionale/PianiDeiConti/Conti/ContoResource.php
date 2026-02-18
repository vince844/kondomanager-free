<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti\Conti;

use App\Helpers\MoneyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ---------------------------------------------------------
        // 1. CALCOLO FONDI DIRETTI (Standard + Spostamenti + Jolly)
        // ---------------------------------------------------------
        $fondiDiretti = $this->pianiRate()
            ->withPivot(['importo', 'note'])
            ->get();

        $totaleDiretto    = 0;
        $dettaglioPiani   = [];
        $hasCoperturaNULL = false;
        
        // Calcolo i fondi "fissi" per determinare il valore dei Jolly
        $totaleImpegnatoFisso = 0;
        foreach ($fondiDiretti as $piano) {
            $nota = strtolower($piano->pivot->note ?? '');
            $isSpostamento = str_contains($nota, 'sposta spesa') || str_contains($nota, 'spostamento');

            if (is_null($piano->pivot->importo)) {
                $hasCoperturaNULL = true;
            } elseif (!$isSpostamento) {
                // Sommiamo solo se è un importo fisso "originale" (non derivante da spostamento)
                $totaleImpegnatoFisso += (int) $piano->pivot->importo;
            }
        }

        // Costruzione Dettaglio
        foreach ($fondiDiretti as $piano) {
            $nota          = $piano->pivot->note ?? '';
            $isSpostamento = str_contains(strtolower($nota), 'sposta spesa') || 
                             str_contains(strtolower($nota), 'spostamento');
            $valore        = 0;

            if (is_null($piano->pivot->importo)) {
                // LOGICA JOLLY (NULL): Copre il preventivo meno i fissi.
                // Se ho spostamenti (es. +100), questi si sommano sopra, creando l'OVER corretto.
                $valore = max(0, $this->importo - $totaleImpegnatoFisso);
                $isAuto = true;
            } else {
                $valore = (int) $piano->pivot->importo;
                $isAuto = false;
            }

            if ($valore > 0) {
                $totaleDiretto += $valore;
                
                $dettaglioPiani[] = [
                    'piano'      => $piano->nome,
                    'importo'    => $valore,
                    'fonte'      => 'diretta',
                    'is_shifted' => $isSpostamento,
                    'is_auto'    => $isAuto,
                    'note'       => $nota
                ];
            }
        }

        // ---------------------------------------------------------
        // 2. SMART PUSH-DOWN (Divisione Equa Senza Discriminazione)
        // ---------------------------------------------------------
        
        // Calcolo deficit residuo
        $mioResiduo = max(0, $this->importo - $totaleDiretto);
        $totaleIndiretto = 0;

        // Procedo solo se non ho già un Jolly (NULL) che mi copre tutto
        if (!$hasCoperturaNULL && $this->parent_id && $this->parent && $mioResiduo > 0) {

            $fondiPadre = $this->parent->pianiRate()->withPivot('importo')->get();

            foreach ($fondiPadre as $pianoPadre) {
                
                $valorePadreDisponibile = (int) ($pianoPadre->pivot->importo ?? 0);

                if ($valorePadreDisponibile > 0) {

                    // A. CONTEGGIO FRATELLI CON DEFICIT
                    // Qui sta la correzione: Contiamo TUTTI i fratelli che hanno un preventivo > fondi fissi.
                    // RIMOSSO il check "hasNull". Se ti mancano soldi fissi, hai diritto a una quota.
                    
                    $fratelli = $this->parent->sottoconti()->with('pianiRate')->get();
                    $numeroFigliConDeficit = 0;

                    foreach ($fratelli as $fratello) {
                        $impegnatoFissoFratello = 0;
                        foreach ($fratello->pianiRate as $pr) {
                            $impegnatoFissoFratello += (int) ($pr->pivot->importo ?? 0);
                        }
                        
                        // CORREZIONE APPLICATA: Nessun check su NULL. Solo matematica.
                        if ($fratello->importo > $impegnatoFissoFratello) {
                            $numeroFigliConDeficit++;
                        }
                    }

                    // B. CALCOLO QUOTA EQUA
                    // Esempio: 200€ diviso 2 figli = 100€ a testa
                    $quotaEqua = ($numeroFigliConDeficit > 0)
                        ? (int) floor($valorePadreDisponibile / $numeroFigliConDeficit)
                        : 0;

                    // C. PRELIEVO
                    // Prendo il minimo tra (Quello che mi serve) e (La mia fetta equa)
                    $quotaMia = min($mioResiduo, $quotaEqua);

                    if ($quotaMia > 0) {
                        $totaleIndiretto += $quotaMia;
                        $mioResiduo -= $quotaMia;

                        // Unisco al dettaglio se il piano esiste già (casi rari) o aggiungo nuova riga
                        $key = array_search($pianoPadre->nome, array_column($dettaglioPiani, 'piano'));
                        if ($key !== false) {
                            $dettaglioPiani[$key]['importo'] += $quotaMia;
                            $dettaglioPiani[$key]['fonte'] = 'mista'; 
                        } else {
                            $dettaglioPiani[] = [
                                'piano'   => $pianoPadre->nome,
                                'importo' => $quotaMia,
                                'fonte'   => 'indiretta'
                            ];
                        }
                    }
                }
            }
        }

        $impegnato = $totaleDiretto + $totaleIndiretto;

        // ---------------------------------------------------------
        // 3. STATO COPERTURA
        // ---------------------------------------------------------
        $percentualeCopertura = 0;
        $statoCopertura = 'empty';

        if ($this->importo > 0) {
            $percentualeCopertura = round(($impegnato / $this->importo) * 100, 1);
            if ($impegnato == 0) $statoCopertura = 'empty';
            elseif ($impegnato < $this->importo) $statoCopertura = 'partial';
            elseif ($impegnato == $this->importo) $statoCopertura = 'full';
            else {
                 $diff = abs($impegnato - $this->importo);
                 $statoCopertura = ($diff <= 100) ? 'full' : 'over';
            }
        }

        return [
            'id' => $this->id,
            'piano_conto_id' => $this->piano_conto_id,
            'parent_id' => $this->parent_id,
            'importo' => MoneyHelper::format($this->importo),
            'importo_raw' => $this->importo,
            'nome' => $this->nome,
            'descrizione' => $this->descrizione,
            'tipo' => $this->tipo,
            'note' => $this->note,
            'codice' => $this->codice,
            'default_fornitore_id' => $this->default_fornitore_id,
            'fornitore_nome' => $this->fornitore ? $this->fornitore->ragione_sociale : null,
            'tipo_spesa' => $this->tipo_spesa,
            'impegnato' => $impegnato,
            'percentuale_copertura' => $percentualeCopertura,
            'stato_copertura' => $statoCopertura,
            'piani_collegati' => $this->pianiRate->pluck('nome'),
            'dettaglio_copertura' => $dettaglioPiani,
            'has_rate_emesse' => $this->has_rate_emesse,
            'sottoconti' => $this->whenLoaded('sottoconti', fn() => ContoResource::collection($this->sottoconti)),
            'tabelle_millesimali' => $this->whenLoaded('tabelleMillesimali', fn() => $this->tabelleMillesimali->map(fn($t) => [
                'id' => $t->id,
                'tabella_id' => $t->tabella_id,
                'coefficiente' => (float) $t->coefficiente,
                'tabella' => $t->tabella ? ['id' => $t->tabella->id, 'nome' => $t->tabella->nome] : null,
                'ripartizioni' => $t->ripartizioni->map(fn($r) => ['id' => $r->id, 'soggetto' => $r->soggetto, 'percentuale' => (float) $r->percentuale]),
            ])),
        ];
    }   
}