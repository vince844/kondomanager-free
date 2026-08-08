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
  ClipboardCheck, CircleHelp, Undo2,
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
            Cosa esportare dal vecchio gestionale, cosa entra e cosa no, e in quale momento
            preciso qualcosa viene scritto davvero.
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

          <!-- 2 — Cosa esportare -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <FileSpreadsheet class="h-5 w-5" /> Cosa esportare dal vecchio gestionale
            </h3>
            <p class="mb-3">
              Da Danea Domustudio servono tre stampe, esportate in Excel. Caricale
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
              Accetto <strong>.xls, .xlsx e .csv</strong>, fino a 25 MB per file. Se il tuo
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
            <p>
              Se lo scarto non è zero significa che qualcosa non è stato letto, o è stato letto
              due volte, o il file è stato modificato dopo l'esportazione. In tutti e tre i casi
              i saldi <strong>non devono entrare</strong>: un saldo sbagliato non si nota subito,
              si trascina in ogni riparto successivo e finisce in un sollecito.
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
                storico arriva con la 1.10.1.
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
            </ul>
          </section>

          <!-- 8 — Tornare indietro -->
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white">
              <Undo2 class="h-5 w-5" /> Se cambi idea
            </h3>
            <p>
              Un'importazione lasciata a metà si <strong>scarta</strong> dalla schermata
              d'ingresso: chiude la sessione e cancella i file caricati. Se qualcosa era già
              entrato in archivio, quello resta — toglierlo è l'annullamento, che non ha una
              scadenza ma una condizione (finché nessuna operazione ha usato quei dati) e arriva
              con la 1.10.1.
            </p>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
