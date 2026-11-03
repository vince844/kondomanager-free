<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti\Conti;

use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BloccoDellaVoce;
use App\Services\Gestionale\BudgetCoverageService;
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

    /**
     * beta.30 — speso reale per voce (SpesaPerVoceService): fatture registrate,
     * anche non ancora pagate, più le regolazioni immediate. Concetto DIVERSO da
     * `$coverageMap`, che è quanto del preventivo è già stato messo in un piano
     * rate emesso ai condòmini: una voce può essere coperta al 100% e non aver
     * ancora speso nulla, o viceversa.
     */
    public static array $spesoMap = [];

    /**
     * I piani approvati che tengono ciascuna voce (`Conto::pianiCheBloccanoLaVoce`), letti una volta per la pagina: voce => piani. Nullo, la riga li
     * legge da sé. La scheda della voce li usa per l'importo, il padre, il tipo, la tabella e le percentuali, e il pannello delle tabelle per i suoi pulsanti,
     * con la regola del salvataggio.
     *
     * @var array<int, list<array{id: int, nome: string, capitolo: ?string, movimenti: list<string>}>>|null
     */
    public static ?array $bloccoMap = null;

    public function toArray(Request $request): array
    {
        $impegnato = (int) (self::$coverageMap[$this->id] ?? 0);

        // Il fabbisogno della voce e le righe dei piani si leggono con la regola della copertura (`BudgetCoverageService::importoDellaVoce`,
        // il valore assoluto, come il motore), da cui viene `$impegnato`: una copia scritta con il meno (dato delle versioni fino alla
        // 1.11.0-beta.51) copre la voce per il suo valore assoluto. Lette col segno, le righe non spiegavano l'impegnato e il resto finiva nel
        // ripiego «Piano rate straordinario», un piano che non esiste. Il fabbisogno è il maggiore fra il preventivo scritto, in valore
        // assoluto, e l'importo che il controller ha già portato allo speso.
        $preventivoScritto = (int) (self::$budgetOriginaliMap[$this->id] ?? $this->importo);
        $fabbisogno = max(BudgetCoverageService::importoDellaVoce($this->resource, $preventivoScritto), (int) $this->importo);
        $valoreDellaCopia = fn ($importo) => BudgetCoverageService::importoDellaVoce($this->resource, (int) $importo);

        // La copia nulla («lascia vuoto»): il piano usa l'importo della voce, per intero, accanto a qualunque altro piano che la tenga. Così la legge
        // il motore (`CalcoloQuoteService::processaConti`, il ramo senza importo del piano) e così la conta la copertura
        // (`BudgetCoverageService::calcolaCoperturaReale`), da cui viene `$impegnato`; un capitolo a zero vale la somma dei suoi sottoconti. Contata come
        // «il resto del fabbisogno», la riga del piano mancava accanto a un'altra copia e l'impegnato finiva nel ripiego «Piano rate straordinario».
        // Si legge il preventivo scritto: il controller porta allo speso l'importo della voce e dei sottoconti, ma il piano addebita quello scritto.
        $importoDellaCopiaNulla = $valoreDellaCopia($preventivoScritto);
        if ($importoDellaCopiaNulla === 0 && $this->sottoconti->isNotEmpty()) {
            $importoDellaCopiaNulla = (int) $this->sottoconti->sum(fn ($figlio) => BudgetCoverageService::importoDellaVoce(
                $figlio,
                (int) (self::$budgetOriginaliMap[$figlio->id] ?? $figlio->importo)
            ));
        }

        $fondiDiretti      = $this->pianiRate()->withPivot(['importo', 'note'])->get();
        $dettaglioPiani    = [];

        foreach ($fondiDiretti as $piano) {
            $nota          = $piano->pivot->note ?? '';
            $isSpostamento = str_contains(strtolower($nota), 'sposta spesa') ||
                             str_contains(strtolower($nota), 'spostamento');
            
            $statoPiano = $piano->stato instanceof \App\Enums\StatoPianoRate 
                ? $piano->stato->value 
                : $piano->stato;

            if (is_null($piano->pivot->importo)) {
                $valore = $importoDellaCopiaNulla;
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
                $valore = $valoreDellaCopia($piano->pivot->importo);
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

        // La parte che viene dal capitolo: anche quando il capitolo ha la copia nulla (il piano addebita i sottoconti per il loro importo), e anche
        // quando la voce ha una copia nulla sua, che conta solo per sé.
        if ($quotaPushDownTarget > 0 && $this->parent_id && $this->parent) {
            $fondiPadre = $this->parent->pianiRate()->withPivot('importo')->get();
            foreach ($fondiPadre as $pianoPadre) {
                if (is_null($pianoPadre->pivot->importo) || (int) $pianoPadre->pivot->importo > 0) {
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

        if ($fabbisogno > 0) {
            $percentualeCopertura = round(($impegnato / $fabbisogno) * 100, 1);

            if ($impegnato === 0) {
                $statoCopertura = 'empty';
            } elseif ($impegnato < $fabbisogno) {
                $statoCopertura = 'partial';
            } elseif ($impegnato === $fabbisogno) {
                $statoCopertura = 'full';
            } else {
                $diff           = abs($impegnato - $fabbisogno);
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

        // Il blocco della voce con la regola del salvataggio (`ContoController::update`, che vale anche per i piani straordinari approvati che finanziano
        // una spesa fuori preventivo registrata sulla voce): la scheda e il pannello delle tabelle non lasciano scrivere l'importo, il padre, il tipo, la tabella e le
        // percentuali che il salvataggio rifiuterebbe, e dicono la strada della bozza con le stesse parole. `has_rate_emesse` resta per l'albero.
        $tengonoLaVoce = self::$bloccoMap === null
            ? (Conto::pianiCheBloccanoLaVoce([(int) $this->id])[(int) $this->id] ?? [])
            : (self::$bloccoMap[(int) $this->id] ?? []);
        $blocco = $tengonoLaVoce === [] ? null : [
            'piani' => array_map(fn (array $p) => ['id' => $p['id'], 'nome' => $p['nome']], BloccoDellaVoce::piani($tengonoLaVoce)),
            'frase' => BloccoDellaVoce::dellaScheda($tengonoLaVoce),
        ];

        $pianiCollegati = $this->pianiRate->pluck('nome')->toArray();
        if ($mancanteStraordinario > 0) {
            $pianiCollegati[] = 'Piano Rate Straordinario';
        }

        return [
            'id'                    => $this->id,
            'piano_conto_id'        => $this->piano_conto_id,
            'parent_id'             => $this->parent_id,
            // Fatto esplicito e persistito — mai indovinato da importo/parent_id
            // (bug "voce a zero perde la tabella millesimale").
            'is_capitolo'           => (bool) $this->is_capitolo,
            'richiede_gia_versato'  => (bool) $this->richiede_gia_versato,
            // Il fabbisogno, denominatore della copertura: per una voce scritta con il meno è il suo valore assoluto (o lo speso, se è maggiore).
            'importo'               => MoneyHelper::format($fabbisogno),
            'importo_raw'           => $fabbisogno,
            // Valori aggiunti per il widget Audit:
            'budget_originale_raw'  => self::$budgetOriginaliMap[$this->id] ?? $this->importo,
            // Speso reale: si confronta con budget_originale_raw, non con importo_raw
            // (che il controller gonfia allo speso quando lo sforo è già avvenuto).
            'speso_raw'             => (int) (self::$spesoMap[$this->id] ?? 0),
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
            'blocco'                => $blocco,
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