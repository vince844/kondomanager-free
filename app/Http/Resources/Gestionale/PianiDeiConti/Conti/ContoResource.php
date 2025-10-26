<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti\Conti;

use App\Helpers\MoneyHelper;
use App\Http\Resources\Gestionale\Tabelle\TabellaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
          return [
            'id'             => $this->id,
            'piano_conto_id' => $this->piano_conto_id,
            'parent_id'      => $this->parent_id,
            'importo'        => MoneyHelper::format($this->importo),
            'nome'           => $this->nome,
            'descrizione'    => $this->descrizione,
            'tipo'           => $this->tipo,
            'note'           => $this->note,
            'sottoconti' => $this->whenLoaded('sottoconti', function () {
                return ContoResource::collection($this->sottoconti);
            }),
                 // Tabelle millesimali associate al conto
         'tabelle_millesimali' => $this->whenLoaded('tabelleMillesimali', function () {
                return $this->tabelleMillesimali->map(function ($tabellaMillesimale) {
                    return [
                        'id' => $tabellaMillesimale->id,
                        'tabella_id' => $tabellaMillesimale->tabella_id,
                        'coefficiente' => (float) $tabellaMillesimale->coefficiente,
                        'tabella' => $tabellaMillesimale->tabella ? [
                            'id' => $tabellaMillesimale->tabella->id,
                            'nome' => $tabellaMillesimale->tabella->nome,
                        ] : null,
                        'ripartizioni' => $tabellaMillesimale->ripartizioni->map(function ($ripartizione) {
                            return [
                                'id' => $ripartizione->id,
                                'soggetto' => $ripartizione->soggetto,
                                'percentuale' => (float) $ripartizione->percentuale,
                            ];
                        }),
                    ];
                });
            }),
        ];
    }
    
}
