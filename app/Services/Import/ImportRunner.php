<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Services\Import\Livelli\LivelloCapitoli;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Esegue i livelli di un'importazione, nell'ordine, fermandosi al primo che non può passare.
 *
 * ## Le tre regole che questa classe fa rispettare
 *
 * 1. **Il gate viene prima del commit, sempre.** Non è una cortesia del singolo livello: è
 *    l'orchestratore a chiamare `verificaPrerequisiti()` e a rifiutarsi di proseguire. Un
 *    livello che dimenticasse di controllare i propri prerequisiti non potrebbe comunque
 *    scavalcare questo passaggio (§10.6).
 * 2. **Ogni livello è una transazione.** Non l'intero import: il singolo livello. È ciò che
 *    rende possibile la ripresa dopo un'interruzione (§7) senza lasciare dati a metà — il
 *    blocco è la transazione, e il checkpoint avanza solo a blocco chiuso.
 * 3. **Ci si ferma al primo livello che non passa.** Proseguire su un livello dipendente da uno
 *    fallito è esattamente come si arriva a delle fatture in archivio con il piano dei conti
 *    incompleto.
 */
final class ImportRunner
{
    /**
     * L'ordine dei livelli. Non è alfabetico e non è casuale: ogni livello trova già ciò da cui
     * dipende. Oggi sono due; la lista cresce con i livelli del §5.
     *
     * @return list<LivelloImport>
     */
    public static function livelli(): array
    {
        return [
            new LivelloCondominio,
            new LivelloEsercizi,
            // Terzo e non in coda: `esegui()` fa `return` al primo livello che non passa, e
            // metterlo dopo i saldi lo nasconderebbe dietro il livello che si ferma più spesso.
            // Le sue dipendenze — condominio ed esercizi — sono soddisfatte qui.
            new LivelloCapitoli,
            new LivelloSoggetti,
            new LivelloUnita,
            new LivelloTitolarita,
            new LivelloTabelle,
            new LivelloSaldi,
        ];
    }

    /**
     * @param  array<string, string>  $decisioni  chiave duplicato → 'unisci' | 'salta' | 'crea_nuovo'
     * @param  list<string>|null  $soloLivelli  null = importazione guidata completa
     * @return array<string, EsitoCommit>  livello → esito, nell'ordine di esecuzione
     *
     * ## Stato di `$soloLivelli`, dichiarato per non far credere più di quel che c'è
     *
     * **Oggi lo usano solo i test**, per esercitare un livello senza montare l'intero bundle:
     * `ImportController::conferma()` — l'unico chiamante in produzione — non passa mai il terzo
     * argomento. La schermata che permetterebbe all'amministratore di scegliere i livelli
     * (§5, «importare un livello alla volta saltando quelli già in archivio») **non esiste**.
     * Questo docblock la dava per costruita: non lo era, e il §16.2 la colloca in 1.10.1.
     *
     * **Il vincolo da conoscere prima di collegarla a un'interfaccia:** l'insieme passato deve
     * essere *chiuso rispetto alle dipendenze*. `verificaOrdine()` solleva una `RuntimeException`
     * se un livello non trova risolto ciò da cui dipende — quindi `['tabelle']` da solo esplode,
     * mentre `['condominio', 'unita', 'tabelle']` funziona. È un'eccezione da difetto nostro,
     * non un rilievo per l'utente: una schermata che offra la scelta dovrà calcolare la chiusura
     * delle dipendenze prima di chiamare qui, oppure quell'eccezione arriverà a chi non può farci
     * niente.
     *
     * L'ordine resta quello canonico anche su un sottoinsieme, e il gate resta obbligatorio:
     * scegliere i livelli non consente di scavalcare le dipendenze, consente di non toccarle.
     */
    public function esegui(ImportContext $ctx, array $decisioni = [], ?array $soloLivelli = null): array
    {
        $ctx->conDecisioni($decisioni);

        $esiti = [];

        /** @var list<string> $saltati i livelli senza dati, che non fermano la catena */
        $saltati = [];

        foreach (self::livelli() as $livello) {
            if ($soloLivelli !== null && ! in_array($livello->chiave(), $soloLivelli, true)) {
                continue;
            }

            // ⚠️ **La cascata si guarda PRIMA dei prerequisiti del livello, e l'ordine conta.**
            // Un livello le cui dipendenze sono state saltate si lamenterebbe per conto suo —
            // «titolarità: mancano i soggetti» — e quella lamentela è **bloccante**, quindi
            // fermerebbe la catena vanificando il salto. La causa vera sta a monte, e va detta
            // a monte: non è un errore, è la conseguenza di un foglio lasciato in bianco.
            $cascata = $this->dipendenzeSaltate($livello, $saltati);

            if ($cascata !== []) {
                $esiti[$livello->chiave()] = EsitoCommit::bloccatoDaPrerequisiti(new PrerequisitoMancante(
                    $livello->chiave().'.dipendenza_saltata',
                    sprintf(
                        '«%s» non entra perché prima manca %s.',
                        $livello->etichetta(),
                        count($cascata) === 1 ? '«'.$cascata[0].'»' : 'più di un livello: '.implode(', ', $cascata),
                    ),
                    'Non è un errore: è la conseguenza di un file che non hai caricato. Aggiungilo e '
                    .'ricarica, oppure prosegui — questo livello lo puoi completare a mano più tardi.',
                    bloccante: false,
                ));

                $saltati[] = $livello->chiave();

                continue;
            }

            $mancanti = $livello->verificaPrerequisiti($ctx);

            // ⚠️ **Un livello senza dati si salta, non ferma la catena.**
            //
            // Fino alla 1.11.0-beta.5 qualunque prerequisito mancante faceva `return`, e i livelli
            // successivi non venivano nemmeno tentati. Misurato: un lotto senza il foglio delle
            // persone si fermava al **quarto** livello e lasciava fuori unità, tabelle e saldi —
            // che dalle persone non dipendono affatto, e che erano stati compilati.
            //
            // La differenza è fra «scrivere sarebbe incoerente» e «quel file non c'è». Il primo
            // resta un muro; il secondo è una scelta di chi importa, e non deve buttare via il
            // lavoro degli altri fogli.
            if ($mancanti !== []) {
                $esiti[$livello->chiave()] = EsitoCommit::bloccatoDaPrerequisiti(...$mancanti);

                if (array_filter($mancanti, fn (PrerequisitoMancante $p) => $p->bloccante) !== []) {
                    $this->segnaParziale($ctx->batch, $livello);

                    return $esiti;
                }

                $saltati[] = $livello->chiave();

                continue;
            }

            $this->verificaOrdine($livello, $ctx);

            // Una transazione per livello: se salta qui, il livello non è entrato **affatto**,
            // e la ripresa lo ritenta da capo invece di trovarlo a metà.
            $esito = DB::transaction(fn () => $livello->commit($ctx));

            $esiti[$livello->chiave()] = $esito;

            if (! $esito->riuscito()) {
                $this->segnaParziale($ctx->batch, $livello);

                return $esiti;
            }

            $ctx->batch->update(['livello_corrente' => $livello->chiave()]);
        }

        $ctx->batch->update([
            'stato' => ImportBatch::STATO_COMPLETATO,
            'completato_at' => now(),
        ]);

        return $esiti;
    }

    /**
     * Il livello dichiara le sue dipendenze: se una non è stata eseguita prima, è un difetto
     * **nostro**, non un dato sbagliato dell'utente.
     *
     * Un'eccezione e non un rilievo, quindi: i rilievi si mostrano all'amministratore, e a lui
     * di un ordine sbagliato nella nostra lista non si può chiedere niente.
     */
    /**
     * Le dipendenze di questo livello che sono state saltate per mancanza di dati.
     *
     * Serve a distinguere due cose che `verificaOrdine()` confonderebbe: una dipendenza **non
     * ancora eseguita** è un difetto nel nostro ordine dei livelli, e va gridata; una dipendenza
     * **saltata** perché il suo file non c'era è normale, e va detta all'utente con calma.
     *
     * @param  list<string>  $saltati
     * @return list<string> le etichette dei livelli saltati da cui questo dipende
     */
    private function dipendenzeSaltate(LivelloImport $livello, array $saltati): array
    {
        $etichette = [];

        foreach (self::livelli() as $l) {
            if (in_array($l->chiave(), $livello->dipendeDa(), true) && in_array($l->chiave(), $saltati, true)) {
                $etichette[] = $l->etichetta();
            }
        }

        return $etichette;
    }

    private function verificaOrdine(LivelloImport $livello, ImportContext $ctx): void
    {
        foreach ($livello->dipendeDa() as $dipendenza) {
            if (! $ctx->haRisolto($dipendenza)) {
                throw new RuntimeException(sprintf(
                    'Il livello «%s» dipende da «%s», che non è stato ancora eseguito. '
                    .'È un errore nell\'ordine dei livelli di ImportRunner::livelli(), non nei dati.',
                    $livello->chiave(),
                    $dipendenza,
                ));
            }
        }
    }

    private function segnaParziale(ImportBatch $batch, LivelloImport $livello): void
    {
        $batch->update([
            'stato' => ImportBatch::STATO_PARZIALE,
            'livello_corrente' => $livello->chiave(),
        ]);
    }
}
