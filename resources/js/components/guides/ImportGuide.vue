<script setup lang="ts">
/**
 * La guida in-app dell'importatore.
 *
 * Risponde alle domande che un amministratore si fa **prima** di caricare l'archivio di venti
 * condomìni in un software che non conosce: cosa devo esportare, cosa entra e cosa no, quando
 * qualcosa viene scritto davvero, e cosa succede se sbaglio. Sono le stesse a cui rispondono i
 * documenti di progetto, dette qui a chi le sta pensando davanti allo schermo.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import {
  DownloadCloud, FileSpreadsheet, Lock, Scale, Users, TriangleAlert,
  ClipboardCheck, CircleHelp, Undo2, PencilRuler,
} from 'lucide-vue-next';

defineProps<{ open: boolean }>();

defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <DownloadCloud class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: importazione dati</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Cosa esportare dal vecchio gestionale — o cosa fare se non esporta niente di
            utilizzabile — cosa entra e cosa no, e in quale momento preciso qualcosa viene
            scritto davvero.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — La promessa che regge tutto -->
          <section>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/30">
              <h3 class="mb-2 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
                <Lock class="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                Niente viene scritto finché non confermi
              </h3>
              <p>
                Le prime tre schermate <strong>leggono e basta</strong>. Puoi caricare i file,
                guardare cosa ho capito, tornare indietro e ricaricare quante volte vuoi: in
                archivio non entra nulla. La scrittura comincia solo quando premi
                «Importa N record» nella schermata di conferma.
              </p>
            </div>
          </section>

          <!-- 2 — Le due strade -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <CircleHelp class="h-5 w-5" /> Da dove puoi arrivare
            </h3>
            <p class="mb-3">
              Oggi Kondomanager sa leggere <strong>due cose</strong>. Sono due strade, non due
              qualità: quello che entra in archivio è identico, cambia solo da dove arriva.
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
              <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <p class="mb-1 font-semibold text-slate-900 dark:text-white">1 · Da Danea Domustudio</p>
                <p>
                  Tre stampe esportate in Excel. È la strada più completa: porta anche i saldi con
                  il totale su cui verificarli, e la struttura dei capitoli di spesa.
                </p>
              </div>
              <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <p class="mb-1 font-semibold text-slate-900 dark:text-white">2 · Con il modello Kondomanager</p>
                <p>
                  Un file che ti do io, da compilare a mano. È la strada per chi arriva da un
                  gestionale diverso, o da un foglio di calcolo tenuto negli anni.
                </p>
              </div>
            </div>
            <p class="mt-3 text-slate-600 dark:text-slate-400">
              Da <strong>altri gestionali</strong> non leggo ancora niente di specifico: se il loro
              export somiglia a quello di Danea può funzionare lo stesso — provalo, le prime tre
              schermate non scrivono niente — altrimenti la strada è il modello.
            </p>
          </section>

          <!-- 2-bis — Cosa esportare da Danea -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <FileSpreadsheet class="h-5 w-5" /> Strada 1 · cosa esportare da Danea
            </h3>
            <p class="mb-3">
              Servono tre stampe, esportate in Excel. Caricale
              <strong>tutte insieme</strong>: al riconoscimento ci penso io.
            </p>
            <ul class="space-y-2">
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Elenco unità</strong> — porta insieme persone, unità, chi possiede cosa e
                i ruoli. È l'unico file che li ha tutti e quattro: senza, non c'è importazione.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Anagrafica + millesimi</strong> — le tabelle millesimali, con i decimali
                che hanno davvero.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Consuntivo ripartizioni per unità / anagrafica</strong> dell'ultimo
                esercizio chiuso — i saldi di apertura. È anche il file che porta il
                <strong>totale su cui verificarli</strong>.
              </li>
            </ul>
            <p class="mt-3 text-slate-600 dark:text-slate-400">
              <!-- Il limite non si scrive qui: dipende dal server e la schermata lo dichiara. Un
                   numero scritto a mano in una guida è la stessa bugia di un numero scritto a mano
                   in una schermata, solo più difficile da trovare. -->
              Accetto <strong>.xls, .xlsx e .csv</strong>. Il limite di dimensione per file lo trovi
              scritto sulla schermata di caricamento: dipende dal tuo server. Se il tuo
              gestionale esporta in un altro formato, aprilo in Excel e salvalo come .xls.
            </p>
          </section>

          <!-- 3 — La quadratura -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <Scale class="h-5 w-5" /> Perché i saldi non entrano se non quadrano
            </h3>
            <p class="mb-3">
              Prima di scrivere una sola riga confronto la somma dei saldi con il
              <strong>totale scritto dentro il tuo riparto</strong>. Quel numero non te l'ho
              chiesto: l'ho letto nel file che hai appena caricato.
            </p>
            <p class="mb-3">
              Se lo scarto non è zero significa che qualcosa non è stato letto, o è stato letto
              due volte, o il file è stato modificato dopo l'esportazione. In tutti e tre i casi
              i saldi <strong>non devono entrare</strong>: un saldo sbagliato non si nota subito,
              si trascina in ogni riparto successivo e finisce in un sollecito.
            </p>
            <p class="rounded-lg border border-slate-200 p-3 text-slate-600 dark:border-slate-800 dark:text-slate-400">
              <strong>Con il modello compilato a mano questo controllo non c'è</strong>, e non è una
              dimenticanza: il totale lo scriveresti tu, cioè la stessa mano che ha scritto le
              righe, quindi non verificherebbe niente. Lì la schermata dice «niente da quadrare» e
              ti chiede di confrontare la somma con l'ultimo rendiconto approvato prima di emettere
              il primo piano rate.
            </p>
          </section>

          <!-- 3-bis — Il modello compilabile a mano -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <PencilRuler class="h-5 w-5" /> Strada 2 · il modello da compilare a mano
            </h3>
            <p class="mb-3">
              Dalla schermata di caricamento, riquadro <strong>«Compilo a mano»</strong>, scarichi
              un file Excel già pronto: lo compili, lo ricarichi lì stesso e prosegue come
              qualunque altra importazione — stessa verifica riga per riga, stessa anteprima prima
              di scrivere.
            </p>
            <p class="mb-3">
              Un file solo, con una copertina e quattro elenchi: <strong>le unità</strong>,
              <strong>le persone</strong> con chi possiede cosa, <strong>le tabelle
              millesimali</strong> e <strong>i saldi di apertura</strong>. Ogni foglio spiega in
              testa cosa va scritto: leggi quelle due righe gialle, rispondono quasi a tutto.
            </p>
            <ul class="space-y-2">
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>La sigla dell'unità la scegli tu</strong> — «B1/1», «int. 3», «016» — ma va
                ripetuta uguale negli altri fogli: è ciò che li tiene insieme. Uno spazio di
                troppo non fa perdere la riga, te lo segnalo e la collego lo stesso.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Un foglio lasciato in bianco non blocca gli altri.</strong> Se i saldi non
                li hai, o le tabelle le metti dopo, il resto entra lo stesso e ti dico cosa è
                rimasto fuori.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Il preventivo di spesa non te lo chiedo.</strong> Nel modello entra solo
                ciò che non posso ricostruire da solo; i capitoli li decidi tu dopo, dal piano dei
                conti, ed è una schermata fatta apposta.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Nei saldi il segno conta:</strong> positivo = l'unità deve al condominio,
                negativo = è in credito. Lascia vuota la colonna «persona» quando il debito segue
                la casa e non chi ci abitava (art. 63 disp. att. c.c.).
              </li>
            </ul>
            <p class="mt-3 text-slate-600 dark:text-slate-400">
              Puoi anche <strong>mescolare le due strade</strong>: esporti quello che riesci e
              scrivi a mano il resto. L'unica cosa che non posso fare è ricevere lo stesso dato da
              due file diversi — in quel caso te lo dico e scegli tu quale togliere.
            </p>
          </section>

          <!-- 4 — Le decisioni -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <Users class="h-5 w-5" /> Le cose che non posso decidere io
            </h3>
            <p class="mb-3">
              Alcune scelte le chiedo a te, e le chiedo <strong>prima</strong> di scrivere:
            </p>
            <ul class="space-y-2">
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>Chi esiste già in archivio.</strong> «Unisci» aggiorna quello che hai con
                i dati del file; «lascia com'è» tiene il tuo e ci collega comunque unità, tabelle
                e saldi. Sul solo nome ci si sbaglia: due «Rossi Mario» esistono davvero.
              </li>
              <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                <strong>I nomi doppi in una cella sola</strong> («ROSSI M. / BIANCHI L.»): il
                tracciato di Danea non ha un campo per la comproprietà. Puoi dividerli in due
                persone o lasciarli come sono. Se dividi, il loro <strong>saldo resta in solido
                sull'unità</strong>: il file non dice in che proporzione spezzarlo, e inventarla
                sarebbe decidere su denaro altrui.
              </li>
            </ul>
            <p class="mt-3 text-slate-600 dark:text-slate-400">
              Le decisioni prese restano sul lotto: se chiudi la scheda e torni domani, le ritrovi.
            </p>
          </section>

          <!-- 5 — Errori vs avvisi -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <TriangleAlert class="h-5 w-5" /> Errori, decisioni, avvisi: tre cose diverse
            </h3>
            <div class="space-y-2">
              <div class="rounded-lg border border-destructive/40 bg-destructive/5 p-3">
                <strong>Errore</strong> — la riga non può entrare così com'è. Si corregge nel file
                e si ricarica.
              </div>
              <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-900/60 dark:bg-amber-950/30">
                <strong>Da decidere</strong> — serve una tua scelta. Non blocca la verifica: si
                risponde nella schermata di conferma.
              </div>
              <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/50">
                <strong>Avviso</strong> — la riga entra, ma c'è qualcosa che è meglio sapere. Un
                codice fiscale mancante non può bloccare un'importazione.
              </div>
            </div>
          </section>

          <!-- 6 — Dopo -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <ClipboardCheck class="h-5 w-5" /> Cosa succede dopo
            </h3>
            <p class="mb-3">
              Gli avvisi non restano nella schermata di esito: diventano la lista
              <strong>«Da controllare dopo l'importazione»</strong>, dentro il condominio, con un
              richiamo sul cruscotto. Non è una lista da spuntare a mano — la maggior parte delle
              voci <strong>si chiude da sola</strong> quando sistemi la cosa, e si riapre se il
              problema torna.
            </p>
            <p>
              Il rapporto completo si scarica in PDF: è il documento che allegi al verbale o
              conservi come prova di cosa è entrato, quando, e con quale scarto sui saldi.
            </p>
          </section>

          <!-- 7 — Cosa non entra -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <CircleHelp class="h-5 w-5" /> Cosa non entra, e va detto
            </h3>
            <ul class="ml-4 list-disc space-y-1">
              <li>
                <strong>La prima nota e le rate versate degli esercizi chiusi.</strong> Di quei
                file leggo solo la testata, per riconoscere condominio ed esercizio: l'archivio
                storico non c'è ancora.
              </li>
              <li>
                <strong>Le pratiche e le attività.</strong> Kondomanager non le gestisce: se
                carichi quel file te lo dico invece di lasciarlo fra i «non riconosciuti».
              </li>
              <li>
                <strong>Il collegamento fra tabelle e capitoli di spesa.</strong> L'export non
                dice quale spesa vada su quale tabella, e indovinarlo significherebbe decidere
                come si dividono i soldi. Le tabelle entrano scollegate e te lo segnalo.
              </li>
              <li>
                <strong>Il preventivo di spesa, se compili il modello a mano.</strong> Non te lo
                chiedo apposta: è l'unica cosa che stai per decidere tu, e c'è una schermata fatta
                per quello. Dai file di Danea invece i capitoli entrano, perché la stampa che li
                porta ce l'hai già. In entrambi i casi gli <strong>importi entrano a zero</strong>:
                un consuntivo è la fotografia dell'anno scorso, non il budget di quest'anno.
              </li>
            </ul>
          </section>

          <!-- 8 — Tornare indietro -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <Undo2 class="h-5 w-5" /> Se cambi idea
            </h3>
            <p>
              Un'importazione lasciata a metà si <strong>scarta</strong> dalla schermata
              d'ingresso: chiude la sessione e cancella i file caricati. Le trovi tutte lì, non
              solo l'ultima.
            </p>
            <p class="mt-2">
              Se qualcosa era già entrato in archivio, quello <strong>resta</strong>: toglierlo
              sarebbe l'annullamento, che non ha una scadenza ma una condizione — finché nessuna
              operazione ha usato quei dati — e <strong>non è ancora costruito</strong>. Per
              disfare adesso: le voci si tolgono dalle schermate del condominio, e il condominio
              intero si elimina dalla sua scheda, portando via tutto ciò che è entrato con quella
              importazione.
            </p>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
