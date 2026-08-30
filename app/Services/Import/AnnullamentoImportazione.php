<?php

namespace App\Services\Import;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use Illuminate\Support\Facades\DB;

/**
 * Annullare un'importazione: prima la **condizione**, poi l'azione.
 *
 * Progetto completo, con le misure che lo sostengono, in `docs/annullamento_importazione.md`.
 * Qui sta ciò che il codice fa; là sta il perché, che in prosa invecchia più lentamente.
 *
 * ## Perché la domanda non è «quale pulsante»
 *
 * Un'importazione che ha creato unità, persone e saldi diventa irreversibile nel momento in cui
 * qualcuno ci lavora sopra. Progettare prima l'azione porta a un comando che a volte distrugge
 * dati validi, e questo servizio è costruito al contrario: `verdetto()` risponde *se* si può, e
 * `esegui()` si rifiuta di girare quando la risposta è no.
 *
 * ## I due regimi, e perché `import_batches.condominio_id` non li distingue
 *
 * ⚠️ È la trappola misurata durante la beta.5 e registrata nella Coda 96. `condominio_id` sul lotto
 * è valorizzato in **entrambi** i rami di `LivelloCondominio`: quando il condominio viene creato
 * (`:109`) e quando viene scelto o unito (`:165`). Una colonna che sembra dire «il condominio di
 * questo lotto» dice in realtà «il condominio in cui questo lotto ha scritto» — e un annullamento
 * che si fidasse di lei cancellerebbe **un condominio preesistente**, con dentro tutto il lavoro
 * di chi lo usava.
 *
 * Il discriminante esiste già, in un'altra colonna: l'`azione` dell'item di livello `condominio`.
 *
 * - **Regime A** — `azione = creato`: il condominio è nato da questa importazione, e si cancella
 *   lui. La cascata porta via anche ciò che **nessuno ha registrato** — la gestione ordinaria e il
 *   piano dei conti predefinito, che `CondominioService` costruisce e che nessun livello annota.
 * - **Regime B** — il condominio preesisteva: cancellarlo è il difetto, non la soluzione.
 *
 * ⛔ **Il regime B non è implementato, di proposito.** Non è una dimenticanza: disfarlo vuol dire
 * cancellare entità una per una, e il registro da solo non basta (la titolarità non ha un model e
 * quindi non ha un riferimento; gli effetti collaterali non sono annotati; il riferimento polimorfo
 * non ha vincolo e sopravvive all'entità). Rifiutare con una spiegazione è la risposta onesta
 * finché quel lavoro non è fatto — e non distrugge niente.
 *
 * ## Cosa questo servizio NON fa
 *
 * - **Non disfa le unioni.** Un record `unito` esisteva prima del lotto, e i suoi valori precedenti
 *   non sono salvati da nessuna parte: «l'archivio torna esattamente com'era» è impossibile da
 *   promettere, non difficile. Nel regime A la questione non si pone — il condominio era nuovo,
 *   quindi non c'erano record suoi da unire.
 * - **Non tocca il rapporto**, che è la ricevuta di un'operazione avvenuta e resta leggibile.
 */
final class AnnullamentoImportazione
{
    /**
     * I segni che qualcuno ha lavorato sopra l'importazione.
     *
     * ⚠️ **Elenco esplicito e non dedotto dallo schema**, per la stessa ragione per cui i fornitori
     * dimostrativi si cancellano per nome e non con un `LIKE '%Demo%'`: una regola che si scopre da
     * sola cambia comportamento il giorno che qualcuno aggiunge una tabella, e lo fa in silenzio.
     * Aggiungerne una qui è una riga; scoprire perché un annullamento ha cancellato troppo, no.
     *
     * Sono anche, uno per uno, i vincoli `RESTRICT` che fermerebbero comunque la cancellazione a
     * livello di database — con la differenza che qui l'amministratore legge **cosa** lo impedisce
     * invece di un errore SQL.
     *
     * @var array<string, string> tabella => come si chiama davanti a una persona
     */
    private const SEGNI_DI_LAVORO = [
        // Contabilità
        'casse' => 'casse',
        'fatture_passive' => 'fatture',
        'pagamenti_fornitori' => 'pagamenti a fornitori',
        'deleghe_f24' => 'deleghe F24',
        'scritture_contabili' => 'scritture contabili',
        'piani_rate' => 'piani rate',
        'contributi_versati' => 'contributi già versati',
        'crediti_residui' => 'crediti residui',

        // ⚠️ **Il lavoro non è solo contabile, e la prima stesura se n'era dimenticata.**
        //
        // Trovato dalla revisione avversariale del 30/08/2026, con una domanda sola: *cos'altro
        // scende a cascata quando cancello il condominio?* La risposta è **ventuno tabelle**, e la
        // lista ne sorvegliava sei. Le altre sparivano in silenzio — documenti caricati,
        // comunicazioni in bacheca, eventi in agenda, segnalazioni, commenti, e i **contributi
        // già versati**, che sono soldi — mentre la schermata diceva serenamente «nessuno ci ha
        // ancora lavorato sopra: si può disfare per intero».
        //
        // ⚠️ È esattamente il difetto che questa funzione era stata progettata per non fare, e la
        // scheda lo aveva pure scritto: *«progettare prima l'azione porta a un comando che a volte
        // distrugge dati validi»*. Il predicato era giusto e il suo **elenco** era corto.
        'condominio_documento' => 'documenti',
        'comunicazione_condominio' => 'comunicazioni in bacheca',
        'condominio_evento' => 'eventi in agenda',
        'segnalazioni' => 'segnalazioni',
        'commenti' => 'commenti',
    ];

    public function verdetto(ImportBatch $lotto): VerdettoAnnullamento
    {
        // ⚠️ **`parziale` si annulla eccome, ed è il caso in cui serve di più.** La prima stesura
        // pretendeva `completato` e rispondeva a tutto il resto «non è entrato niente in archivio».
        // Su un lotto `parziale` quella frase è **falsa**, e in modo visibile: la stessa schermata,
        // sei righe più su, dice «quello che era già entrato resta». Misurato a video il
        // 30/08/2026 su un'importazione Danea fermatasi ai saldi con **61 record già scritti**.
        //
        // Ed è il caso che conta: un'importazione che si interrompe a metà è precisamente quella
        // che uno vuole disfare. Il predicato non cambia — ha creato il condominio? ci ha lavorato
        // qualcuno? — quindi non c'era ragione di escluderla se non che nessuno l'aveva pensata.
        //
        // Resta fuori `in_corso`: lì non è entrato niente davvero, e la strada è lo scarto.
        if (! in_array($lotto->stato, [ImportBatch::STATO_COMPLETATO, ImportBatch::STATO_PARZIALE], true)) {
            return VerdettoAnnullamento::no(
                'Questa importazione non ha ancora scritto niente.',
                aiuto: 'Un caricamento che non è mai stato confermato si scarta, non si annulla: '
                    .'in archivio non è entrato nulla da disfare.',
            );
        }

        $item = $this->itemDelCondominio($lotto);

        if ($item === null) {
            return VerdettoAnnullamento::no(
                'Questa importazione è entrata in un condominio che esisteva già.',
                aiuto: 'L\'annullamento automatico sa disfare solo le importazioni che hanno creato '
                    .'il condominio: qui cancellare il condominio vorrebbe dire cancellare anche '
                    .'tutto quello che ci avevi già dentro. Le voci importate si tolgono una per una '
                    .'dalle schermate del condominio.',
            );
        }

        $condominio = Condominio::find($item->importabile_id);

        if ($condominio === null) {
            // ⚠️ **L'item c'è e l'entità no**, e sono due cose diverse che vanno dette diverse. Il
            // riferimento polimorfo non ha un vincolo, quindi il registro sopravvive a ciò che
            // descrive: misurato il 30/08/2026 su quattro lotti veri, cancellati i condomìni erano
            // rimaste 272 righe su 272, penzolanti e senza un errore.
            //
            // Senza questo ramo qui sopra rispondeva «è entrata in un condominio che esisteva già»,
            // cioè la frase di un caso completamente diverso — e a chi legge sarebbe sembrato un
            // rifiuto ingiusto invece di una constatazione.
            return VerdettoAnnullamento::no(
                'Il condominio di questa importazione non c\'è più.',
                aiuto: 'Qualcuno lo ha già eliminato, o l\'importazione è già stata annullata. '
                    .'Non c\'è niente da disfare: questa pagina resta come ricevuta di quello che '
                    .'era successo.',
            );
        }

        $impedimenti = $this->impedimenti($condominio);

        if ($impedimenti !== []) {
            return VerdettoAnnullamento::no(
                'Su questo condominio è già stato registrato altro.',
                // ⚠️ **Qui c'era «e in contabilità si storna, non si cancella».** Tolta il
                // 30/08/2026 su osservazione di Vincenzo: è una **politica**, e nessuno l'ha
                // decisa. Veniva dal §13.2 di `import_migrazione_dati.md`, che è un progetto — e
                // un progetto non è una decisione presa. Il messaggio dice cosa il programma fa
                // (si ferma, e cosa lo ferma) e non cosa l'amministratore dovrebbe fare del suo
                // lavoro: quella parte si decide, e finché non è decisa non si scrive a schermo.
                aiuto: 'L\'annullamento toglierebbe anche il lavoro fatto dopo l\'importazione, '
                    .'quindi mi fermo. Qui sotto trovi cosa c\'è di nuovo: da lì decidi tu come '
                    .'procedere.',
                impedimenti: $impedimenti,
            );
        }

        return VerdettoAnnullamento::si(
            $condominio,
            $this->conteggi($lotto),
        );
    }

    /**
     * Disfa l'importazione. **Non decide**: chiede a `verdetto()` e si ferma se la risposta è no.
     *
     * ⚠️ La guardia è qui e non solo nel controller, perché è l'unica che non si può scavalcare
     * arrivando da un'altra porta — ed è la lezione della beta.47: *il gate dei prerequisiti
     * appartiene all'orchestratore, non al passo.*
     */
    public function esegui(ImportBatch $lotto): VerdettoAnnullamento
    {
        // ⚠️ **Il verdetto si ricalcola DENTRO la transazione, e non è pedanteria.**
        //
        // La prima stesura lo calcolava fuori e poi apriva la transazione per cancellare. Fra i due
        // momenti c'è una finestra: se qualcuno registra una cassa proprio lì in mezzo — o se
        // l'amministratore ha due schede aperte e preme due volte — il `delete()` incontra un
        // vincolo `RESTRICT` e l'amministratore riceve **una pagina 500** invece della frase che
        // gli spiega cosa lo impedisce. Un rifiuto pronunciato male vale quanto un rifiuto non
        // pronunciato (beta.49), e qui sarebbe peggio: un errore su un'operazione distruttiva
        // lascia chi legge senza sapere se ha cancellato qualcosa.
        //
        // Trovato dalla revisione avversariale del 30/08/2026, con la domanda «cosa succede se due
        // persone lo fanno insieme».
        return DB::transaction(function () use ($lotto) {
            $verdetto = $this->verdetto($lotto);

            if (! $verdetto->possibile) {
                return $verdetto;
            }

            $condominio = $verdetto->condominio;

            // ⚠️ **Catturate adesso, non dopo.** `anagrafica_condominio` scende a cascata insieme
            // al condominio: cercare queste persone dopo il `delete()` non troverebbe più niente.
            // È lo stesso difetto che nella beta.71 aveva lasciato 184 anagrafiche orfane su 215.
            $anagrafiche = DB::table('anagrafica_condominio')
                ->where('condominio_id', $condominio->id)
                ->pluck('anagrafica_id');

            // Il condominio, e con lui la cascata: immobili, esercizi, gestioni, piano dei conti,
            // tabelle, saldi, titolarità. Verificato che non incontri vincoli `RESTRICT`: quelli
            // stanno su casse, pagamenti e deleghe, che `impedimenti()` ha già escluso.
            $condominio->delete();

            // Le persone restano se appartengono ancora a qualcun altro o se sono un utente del
            // programma. ⚠️ Senza questa potatura la **seconda** importazione dello stesso file non
            // sarebbe un ritentativo: troverebbe le persone «già presenti» e chiederebbe decisioni
            // di deduplica che nessuno ha mai posto (beta.47).
            $superstiti = DB::table('anagrafica_condominio')
                ->whereIn('anagrafica_id', $anagrafiche)
                ->distinct()
                ->pluck('anagrafica_id');

            Anagrafica::whereIn('id', $anagrafiche)
                ->whereNotIn('id', $superstiti->isEmpty() ? [0] : $superstiti)
                ->whereNull('user_id')
                ->delete();

            // ⚠️ Lo stato è lo stesso che usa lo scarto, e non è una svista: i due si distinguono
            // da `completato_at`, che solo un'importazione arrivata in fondo ha valorizzato. Un
            // lotto con entrambe le date è stato completato **e poi** disfatto; uno con la sola
            // `annullato_at` è stato abbandonato per strada. Nessuna migrazione per dire una cosa
            // che le colonne già dicono.
            $lotto->update([
                'stato' => ImportBatch::STATO_ANNULLATO,
                'annullato_at' => now(),
            ]);

            return $verdetto;
        });
    }

    /**
     * L'item di livello `condominio` **creato** da questo lotto, o `null` se il condominio
     * preesisteva.
     *
     * È qui che vive la distinzione fra i due regimi, ed è una riga sola: si guarda l'`azione`,
     * non `import_batches.condominio_id`.
     *
     * ⚠️ **Restituisce l'item e non il condominio**, di proposito: «l'item non c'è» e «l'item c'è
     * ma l'entità è sparita» sono due risposte diverse, e collassarle in un `null` faceva dire al
     * verdetto la frase di un altro caso.
     */
    private function itemDelCondominio(ImportBatch $lotto): ?ImportBatchItem
    {
        return $lotto->items()
            ->where('livello', 'condominio')
            ->where('azione', ImportBatchItem::AZIONE_CREATO)
            ->where('importabile_type', Condominio::class)
            ->first();
    }

    /**
     * Cosa è stato registrato su questo condominio dopo l'importazione.
     *
     * @return array<string, int> etichetta => quante
     */
    private function impedimenti(Condominio $condominio): array
    {
        $trovati = [];

        foreach (self::SEGNI_DI_LAVORO as $tabella => $etichetta) {
            $quanti = DB::table($tabella)->where('condominio_id', $condominio->id)->count();

            if ($quanti > 0) {
                $trovati[$etichetta] = $quanti;
            }
        }

        return $trovati;
    }

    /**
     * Quante cose spariscono, per livello — **risolvendo i riferimenti, non contando le righe**.
     *
     * ⚠️ La differenza non è teorica: contare gli item darebbe un numero vero e una risposta falsa,
     * perché il registro sopravvive alle entità (vedi `condominioCreatoDa()`). È lo stesso difetto
     * che oggi ha la schermata dell'esito, che dichiara «117 record creati» leggendo
     * `itemsCreati()->count()` anche quando in archivio non c'è più niente.
     *
     * @return array<string, int>
     */
    private function conteggi(ImportBatch $lotto): array
    {
        $items = $lotto->items()->where('azione', ImportBatchItem::AZIONE_CREATO)->get();

        // ⚠️ **Una query per tipo, non una per riga.** La prima stesura risolveva ogni item da sé:
        // misurato dalla revisione avversariale del 30/08/2026, **61 item costavano 73 query** — e
        // `esito()` calcola il verdetto a ogni caricamento della pagina, quindi il lotto Danea vero
        // (117 item) ne pagava centotrenta per essere guardato.
        //
        // Le chiavi si raccolgono per tipo e si chiede una volta sola quali esistono ancora.
        $vivi = [];

        foreach ($items->whereNotNull('importabile_type')->groupBy('importabile_type') as $tipo => $delTipo) {
            if (! class_exists($tipo)) {
                continue;
            }

            $vivi[$tipo] = $tipo::query()
                ->whereIn((new $tipo)->getKeyName(), $delTipo->pluck('importabile_id'))
                ->pluck((new $tipo)->getKeyName())
                ->flip();
        }

        $per = [];

        foreach ($items as $item) {
            $tipo = $item->importabile_type;

            // La titolarità non ha un'entità dietro — vive nella pivot `anagrafica_immobile`, che
            // non ha un model — quindi si conta come riga: è l'unico livello per cui l'item *è* il
            // fatto, invece di puntarci.
            if ($tipo === null) {
                $per[$item->livello] = ($per[$item->livello] ?? 0) + 1;

                continue;
            }

            if (! isset($vivi[$tipo]) || ! isset($vivi[$tipo][$item->importabile_id])) {
                continue;
            }

            $per[$item->livello] = ($per[$item->livello] ?? 0) + 1;
        }

        return $per;
    }

}
