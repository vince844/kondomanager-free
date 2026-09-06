<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Http\JsonResponse;

class TestContabilitaController extends Controller
{
    /**
     * Esegue un controllo profondo (Deep Scan) sul Libro Giornale.
     * Verifica quadratura matematica, anomalie di integrità e genera il Bilancio di Verifica per Mastri.
     */
    public function checkQuadratura(Condominio $condominio): JsonResponse
    {
        // Eager Loading spinto per evitare N+1 query: carichiamo anche i mastri associati alle righe
        $scritture = ScritturaContabile::where('condominio_id', $condominio->id)
            ->with(['righe.contoContabile']) 
            ->get();
        
        $anomalieQuadratura = [];
        $anomalieIntegrita  = [];
        $mastriTotali       = []; // Qui immagazzineremo i totali di ogni singolo mastro
        
        $totaleDare = 0;
        $totaleAvere = 0;

        foreach ($scritture as $scrittura) {
            $dareScrittura = 0;
            $avereScrittura = 0;

            foreach ($scrittura->righe as $riga) {
                // 1. Somma DARE/AVERE per la singola scrittura
                if ($riga->tipo_riga === 'dare') {
                    $dareScrittura += $riga->importo;
                } else {
                    $avereScrittura += $riga->importo;
                }

                // 2. AGGREGAZIONE PER MASTRI (Il vero Bilancio di Verifica)
                $mastroId = $riga->conto_contabile_id ?? 'Orfano';
                
                if (!isset($mastriTotali[$mastroId])) {
                    $nomeMastro = $riga->contoContabile 
                        ? "[{$riga->contoContabile->codice}] {$riga->contoContabile->nome}" 
                        : 'SCONOSCIUTO / ORFANO';

                    $mastriTotali[$mastroId] = [
                        'nome'  => $nomeMastro,
                        'dare'  => 0,
                        'avere' => 0,
                    ];
                }

                if ($riga->tipo_riga === 'dare') {
                    $mastriTotali[$mastroId]['dare'] += $riga->importo;
                } else {
                    $mastriTotali[$mastroId]['avere'] += $riga->importo;
                }

                // 3. DEEP CHECKS DI INTEGRITÀ RELAZIONALE
                if (is_null($riga->conto_contabile_id)) {
                    $anomalieIntegrita[] = [
                        'scrittura_id' => $scrittura->id,
                        'riga_id'      => $riga->id,
                        'errore'       => 'Riga orfana: conto_contabile_id mancante',
                    ];
                }

                if ($riga->voce_spesa_id) {
                    $vocePreventivo = Conto::find($riga->voce_spesa_id);
                    if ($vocePreventivo && $vocePreventivo->conto_contabile_id !== $riga->conto_contabile_id) {
                        $anomalieIntegrita[] = [
                            'scrittura_id' => $scrittura->id,
                            'riga_id'      => $riga->id,
                            'errore'       => "Disallineamento Mastro: La riga usa il Mastro {$riga->conto_contabile_id}, ma la Voce di Preventivo ({$vocePreventivo->nome}) richiede il Mastro {$vocePreventivo->conto_contabile_id}",
                        ];
                    }
                }

                if ($riga->cassa_id) {
                    $cassa = Cassa::find($riga->cassa_id);
                    if ($cassa && $cassa->conto_contabile_id !== $riga->conto_contabile_id) {
                        $anomalieIntegrita[] = [
                            'scrittura_id' => $scrittura->id,
                            'riga_id'      => $riga->id,
                            'errore'       => "Disallineamento Finanziario: La riga usa la cassa '{$cassa->nome}', ma il mastro contabile non corrisponde a quello della cassa.",
                        ];
                    }
                }
            }
            
            $totaleDare += $dareScrittura;
            $totaleAvere += $avereScrittura;

            // Controllo quadratura della singola scrittura
            if ($dareScrittura !== $avereScrittura) {
                $anomalieQuadratura[] = [
                    'scrittura_id' => $scrittura->id,
                    'causale'      => $scrittura->causale,
                    'totale_dare'  => '€ '.number_format($dareScrittura / 100, 2, ',', '.'),
                    'totale_avere' => '€ '.number_format($avereScrittura / 100, 2, ',', '.'),
                    'sbilancio'    => '€ '.number_format(abs($dareScrittura - $avereScrittura) / 100, 2, ',', '.'),
                ];
            }
        }

        // Formattiamo il dettaglio dei mastri in modo elegante
        $bilancioDiVerifica = collect($mastriTotali)->map(function ($mastro) {
            $saldo = $mastro['dare'] - $mastro['avere'];
            
            if ($saldo > 0) {
                $natura = 'DARE (Attività/Costi)';
            } elseif ($saldo < 0) {
                $natura = 'AVERE (Passività/Ricavi)';
            } else {
                $natura = 'PAREGGIO (Chiuso)';
            }

            return [
                'mastro'       => $mastro['nome'],
                'totale_dare'  => '€ '.number_format($mastro['dare'] / 100, 2, ',', '.'),
                'totale_avere' => '€ '.number_format($mastro['avere'] / 100, 2, ',', '.'),
                'saldo_finale' => '€ '.number_format(abs($saldo) / 100, 2, ',', '.'),
                'eccedenza'    => $natura,
            ];
        })->values()->all();

        $confrontoDocumento = $this->confrontoColDocumento($condominio);

        // ⚠️ **Una differenza col documento pesa sullo stato, ma si chiama col suo nome.** Non è
        // un'anomalia contabile — i conti quadrano — è un importo registrato diverso da quello che il
        // fornitore chiede. Può essere deliberato: dalla beta.19 il modulo avvisa e non blocca.
        // Tacerla però sarebbe il buco che questo controllo esiste per chiudere.
        // ⚠️ `$isPerfetto` è stato tolto nella Fase 1-bis della beta.20: il `match` qui sotto
        // esprime la stessa politica in modo esaustivo, e tenere una variabile che nessuno legge —
        // col commento che spiega la semantica attaccato a lei — è il modo in cui un commento
        // sopravvive al codice che descriveva.
        $stato = match (true) {
            count($anomalieQuadratura) > 0 || count($anomalieIntegrita) > 0 => '🔴 ANOMALIE RILEVATE',
            $confrontoDocumento['non_quadrano'] > 0 => '🟡 CONTI COERENTI, MA '
                .$confrontoDocumento['non_quadrano'].' DOCUMENTO/I REGISTRATO/I PER UN IMPORTO DIVERSO DA QUELLO DICHIARATO',
            default => '🟢 PERFETTO (Deep Scan Superato)',
        };

        return response()->json([
            'status'               => $stato,
            'condominio'           => $condominio->nome,
            'statistiche_globali' => [
                'totale_scritture'     => $scritture->count(),
                'totale_dare_storico'  => '€ '.number_format($totaleDare / 100, 2, ',', '.'),
                'totale_avere_storico' => '€ '.number_format($totaleAvere / 100, 2, ',', '.'),
            ],
            'bilancio_di_verifica' => $bilancioDiVerifica, // <-- LA NUOVA SEZIONE!
            'anomalie_matematiche' => $anomalieQuadratura,
            'anomalie_integrita'   => $anomalieIntegrita,

            // Il quinto controllo: la contabilità è giusta RISPETTO AL DOCUMENTO, non solo coerente
            // con sé stessa. Vedi il docblock di `confrontoColDocumento()`.
            'confronto_col_documento' => [
                'fatture_verificate' => $confrontoDocumento['verificate'],
                'non_quadrano'       => $confrontoDocumento['non_quadrano'],
                'nota'               => $confrontoDocumento['verificate'] === 0
                    ? 'Nessuna fattura importata da XML conserva i totali dichiarati dal fornitore: '
                      .'il confronto è possibile solo sui documenti registrati dalla 1.11.0-beta.19 in poi.'
                    : 'Una differenza non è per forza un errore: dalla beta.19 il modulo avvisa e non '
                      .'blocca, quindi un importo diverso può essere stato registrato di proposito.',
                'controlli'          => $confrontoDocumento['controlli'],
            ],
        ]);
    }

    /**
     * Il quinto controllo: **la contabilità è giusta rispetto al documento?**
     *
     * ⚠️ **Gli altri quattro controlli hanno un punto cieco, e questo lo chiude.** Quadratura per
     * scrittura, righe orfane e disallineamenti di mastro verificano che i conti siano *coerenti con
     * sé stessi*. Una fattura registrata a € 100,14 invece dei € 100,15 che il fornitore chiede quadra
     * perfettamente: i due lati della scrittura sono sbagliati dello stesso importo, e il deep scan
     * direbbe 🟢. È la stessa frase che ricorre nei test della 1.11.0-beta.19 — *«DoubleEntryValidator
     * non se ne accorge: le due scritture quadrano ciascuna per sé, lo sbilancio è fra i due
     * documenti»* — e fino alla beta.20 questo scanner aveva lo stesso identico buco.
     *
     * Il dato per chiuderlo esiste dalla beta.19: ogni fattura importata da XML conserva in
     * `dati_extra.fiscal.riepiloghi_dichiarati` l'imponibile e l'imposta che il fornitore ha
     * dichiarato nei propri `DatiRiepilogo`. Il confronto è quindi con una fonte **esterna** al nostro
     * calcolo, che è ciò che gli altri quattro controlli non hanno.
     *
     * ## La forma: equazioni, non un badge
     *
     * Decisione **D14** di `docs/registri_contabili.md` (30/08/2026): *«i controlli di quadratura si
     * espongono come equazioni con i numeri veri, non come un badge»* — nove affermazioni verificabili
     * invece di un indicatore unico «tutto ok», perché ogni riga è una prova invece che una
     * conclusione da fidarsi. Qui il controllo è uno solo, ma la forma è quella: ogni fattura porta il
     * proprio numero dichiarato, il proprio numero registrato e il proprio esito.
     *
     * ## Una differenza non è per forza un errore
     *
     * Dalla beta.19 il modulo **avvisa** quando la registrazione non vale quanto il documento, e non
     * blocca: l'amministratore è l'autorità sul proprio documento e un'importazione può aver letto
     * male. Una differenza qui dentro significa quindi «qualcuno ha registrato un importo diverso da
     * quello dichiarato», non «c'è un difetto». Per questo l'elenco si chiama `differenze` e non
     * `anomalie`, e per questo pesa sullo stato globale in modo dichiarato invece che silenzioso.
     *
     * @return array{controlli: array<int, array<string, string>>, verificate: int, non_quadrano: int}
     */
    private function confrontoColDocumento(Condominio $condominio): array
    {
        $fatture = FatturaPassiva::where('condominio_id', $condominio->id)
            ->whereNotNull('dati_extra')
            ->get()
            ->filter(fn (FatturaPassiva $f) => ! empty($f->dati_extra['fiscal']['riepiloghi_dichiarati'] ?? null));

        $controlli = [];
        $nonQuadrano = 0;

        foreach ($fatture as $fattura) {
            // I riepiloghi sono salvati in euro, come li manda l'importatore: si arrotonda per
            // gruppo, esattamente come fa `FatturaPassivaService::distribuisciImpostaDichiarata()`.
            $dichiaratoCents = 0;
            foreach ($fattura->dati_extra['fiscal']['riepiloghi_dichiarati'] as $gruppo) {
                $dichiaratoCents += (int) round(((float) ($gruppo['imponibile'] ?? 0)) * 100)
                    + (int) round(((float) ($gruppo['imposta'] ?? 0)) * 100);
            }

            // Il segno lo porta il tipo di documento: i riepiloghi sono magnitudini anche su una
            // nota di credito, quindi il confronto è fra valori assoluti.
            $registratoCents = abs((int) $fattura->totale_documento);
            $dichiaratoCents = abs($dichiaratoCents);
            $quadra = $registratoCents === $dichiaratoCents;

            if (! $quadra) {
                $nonQuadrano++;
            }

            // ⚠️ Il simbolo va PRIMA dell'importo: `€ 100,15`, mai `100,15 €`. È la convenzione del
            // progetto, e questo file la violava in nove punti — otto preesistenti e uno scritto
            // dalla beta.20. Trovata guardando la risposta a video, non dalla revisione.
            $euro = static fn (int $c): string => '€ '.number_format($c / 100, 2, ',', '.');

            $controlli[] = [
                'documento'  => (string) $fattura->numero_documento,
                'equazione'  => sprintf(
                    'dichiarato dal fornitore %s %s registrato %s',
                    $euro($dichiaratoCents),
                    $quadra ? '=' : '≠',
                    $euro($registratoCents),
                ),
                'differenza' => $quadra ? '—' : $euro(abs($registratoCents - $dichiaratoCents)),
                'esito'      => $quadra ? 'quadra' : 'NON quadra',
            ];
        }

        return [
            'controlli'    => $controlli,
            'verificate'   => $fatture->count(),
            'non_quadrano' => $nonQuadrano,
        ];
    }
}
