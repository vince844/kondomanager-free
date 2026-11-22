<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida dell'elenco dei pagamenti ai fornitori, sul modello di quella dell'elenco delle fatture: che cosa entra nell'elenco, le tre card (due sono anche
 * filtri), come si legge una riga, il menu ⋯, «ho sbagliato: modifico o storno?», le commissioni senza voce. La guida «pagare un fornitore» resta quella
 * della pagina del nuovo pagamento.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { CreditCard, ChevronRight, Info, RotateCcw, TriangleAlert } from 'lucide-vue-next';

defineProps<{
    open: boolean;
}>();

defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
              <CreditCard class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registro dei pagamenti</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Che cosa entra in questo elenco, come si legge una riga, che cosa fai dal menu di un pagamento e la domanda che arriva prima o poi a tutti: ho
            sbagliato un pagamento, lo modifico o lo storno?
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — Cosa c'è qui -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Cosa entra in questo elenco</h3>
            <p class="mb-3">
              I <strong>pagamenti ai fornitori</strong>: ognuno chiude una o più fatture e fa uscire il denaro da un conto o dalla cassa. Ci sono anche gli
              <strong>storni</strong>, cioè le operazioni che annullano un pagamento. Un pagamento nuovo si registra con «Nuovo pagamento», in alto sopra l'elenco.
            </p>
            <div class="p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-sm">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Le tre card in alto.</strong> «Pagato nel mese» dice quanto è uscito verso i fornitori in questo mese. «Con ritenuta d'acconto» e
                «Operazioni stornate» sono anche filtri: un clic mostra solo quei pagamenti, un altro clic li toglie.
              </div>
            </div>
          </section>

          <!-- 2 — Come si legge una riga -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Come si legge una riga</h3>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                  <span><strong>Fornitore</strong> — con il badge «Ritenuta» quando il pagamento trattiene la ritenuta d'acconto, e «Detrazione …» per un
                    bonifico parlante (le detrazioni fiscali dei condòmini).</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                  <span><strong>Addebito</strong> — il conto o la cassa da cui è uscito il denaro.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                  <span><strong>Data &amp; Metodo</strong> — il giorno del pagamento e come è partito (bonifico, contanti, …).</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                  <span><strong>Stato</strong> — <em>Confermato</em>; <em>Originale Stornato</em> per un pagamento annullato; <em>Storno Confermato</em> per
                    l'operazione che lo ha annullato.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                  <span><strong>Importi</strong> — quanto ha chiuso delle fatture e quanto è uscito davvero: con la ritenuta il bonifico è il netto, e le
                    commissioni bancarie si aggiungono all'uscita. Passando sopra la cifra vedi il dettaglio.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 3 — Il menu della riga -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il menu ⋯ di un pagamento</h3>
            <ul class="space-y-2">
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Vedi dettaglio</strong> — il pagamento con le fatture che chiude.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Modifica</strong> — solo per un pagamento non stornato, e mai per l'operazione di storno.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Dettaglio scrittura contabile</strong> — la registrazione in partita doppia del pagamento.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Scarica distinta</strong> — il riepilogo del pagamento da tenere o da mandare.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Storna pagamento</strong> — l'annullamento, con il motivo (qui sotto).</span></li>
            </ul>
          </section>

          <!-- 4 — Ho sbagliato -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <RotateCcw class="w-4 h-4 inline-block -mt-0.5 text-slate-500" /> Ho sbagliato un pagamento: lo modifico o lo storno?
            </h3>
            <p class="mb-3">
              Se il pagamento è solo scritto male (la data, il conto, una cifra) e non è stornato, usa <strong>Modifica</strong>. Se invece non doveva esserci, o
              il bonifico è tornato indietro, usa <strong>Storna pagamento</strong>.
            </p>
            <div class="p-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-800 dark:text-amber-200 flex gap-3">
              <TriangleAlert class="w-5 h-5 shrink-0" />
              <div>
                <strong>Lo storno non cancella niente.</strong> Il pagamento resta, segnato «Originale Stornato», e nasce l'operazione inversa «Storno
                Confermato»: le fatture collegate tornano da pagare e il saldo del conto torna com'era. Il motivo è obbligatorio: per esempio «Bonifico tornato
                indietro per IBAN errato del fornitore».
              </div>
            </div>
          </section>

          <!-- 5 — Le commissioni senza voce -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">La riga arancio delle commissioni</h3>
            <p>
              Se qualche commissione bancaria di questo esercizio è stata registrata senza voce, una riga arancio in alto lo dice: resta fuori dal consuntivo
              delle voci finché non usi <strong>Assegna la voce</strong>.
            </p>
          </section>
        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
