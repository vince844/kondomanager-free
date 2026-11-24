<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
use App\Support\CodiceDellErrore;
use App\Support\Errori\RegistroDegliErrori;
use RuntimeException;
use Throwable;

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
            // Il registro delle modifiche: una riga per livello (una per transazione, P10), firmata da chi ha avviato l'importazione.
            //
            // ⚠️ **L'errore dentro un livello si ferma qui, fuori dalla transazione** (Coda 598, 1.12.0-beta.14). Fino alla beta.13 l'eccezione
            // annullava il livello e poi risaliva fino a `ImportController::conferma()`: l'amministratore riceveva la pagina «Server Error», il
            // rapporto non si scriveva e il lotto restava «in corso» per sempre, con i livelli di prima già entrati. Ora il livello si ferma come
            // uno bloccato: il rapporto dice quale non è entrato, con il codice dell'errore, e il lotto è «parziale», da riprendere.
            try {
                $esito = \App\Services\RegistroModifiche\RegistroModifiche::comeAutore(
                    \App\Services\RegistroModifiche\GestiDelRegistro::autoreDellImportazione($ctx->batch),
                    fn () => \App\Services\RegistroModifiche\RegistroModifiche::durante(
                        \App\Services\RegistroModifiche\GestiDelRegistro::livelloDellImportazione($ctx->batch, $livello->chiave()),
                        fn () => $livello->commit($ctx)));
            } catch (Throwable $e) {
                $esito = $this->fermatoDaUnErrore($livello, $e, $esiti);
            }

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

    /**
     * Il livello che un errore del programma ha fermato: l'esito di un livello bloccato, con il codice dell'errore (Coda 598).
     *
     * L'errore si scrive come gli altri (`report()`: il file del server e il registro degli errori della tappa 6, con il codice nella stessa riga);
     * all'amministratore arriva il codice, mai il testo dell'eccezione, che può contenere dati. Un errore che il gestore non scrive (un errore HTTP,
     * una validazione) lo scrive `RegistroDegliErrori::registraAMano()`, così il codice del rapporto si trova sempre. Scrivere l'errore non deve
     * diventare un secondo errore: se il registro non si scrive, il codice resta nel rapporto.
     *
     * @param  array<string, EsitoCommit>  $esiti  i livelli già eseguiti, per dire il vero su quello che è entrato prima
     */
    private function fermatoDaUnErrore(LivelloImport $livello, Throwable $e, array $esiti): EsitoCommit
    {
        $codice = CodiceDellErrore::di($e);

        try {
            report($e);
            RegistroDegliErrori::registraAMano($e);
        } catch (Throwable) {
            // Il registro che non si scrive non ferma il rapporto: il codice c'è comunque.
        }

        // La strada per riprovare è quella della ripresa: ripartendo da capo, i livelli già entrati tornano nell'anteprima come «c'è già», e
        // «Lascia com'è» li usa invece di riscriverli. Ripetere la conferma da sola si fermerebbe al primo di loro.
        $primaEntrato = array_filter($esiti, fn (EsitoCommit $esito) => $esito->riuscito()) !== [];

        return EsitoCommit::bloccato(Rilievo::errore(
            'import.errore_inatteso',
            sprintf('«%s» non è entrato: il programma ha incontrato un errore (codice %s).', $livello->etichetta(), $codice),
            ($primaEntrato
                ? 'Quello che c\'era prima è entrato. Da «Importa dati» premi «Riprendi»: l\'anteprima ti mostra quello che è già in archivio; lascialo '
                    .'com\'è e conferma di nuovo.'
                : 'Non è entrato niente. Da «Importa dati» premi «Riprendi» e conferma di nuovo.')
            .' Se l\'errore si ripete, cerca il codice nel registro degli errori, o scrivici con il codice.',
        ));
    }

    private function segnaParziale(ImportBatch $batch, LivelloImport $livello): void
    {
        $batch->update([
            'stato' => ImportBatch::STATO_PARZIALE,
            'livello_corrente' => $livello->chiave(),
        ]);
    }
}
