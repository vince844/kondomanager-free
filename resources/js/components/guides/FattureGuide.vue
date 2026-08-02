<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import {
  FileText, ChevronRight, Info, Ban, RotateCcw, TriangleAlert,
  ShieldCheck, Coins,
} from 'lucide-vue-next';

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
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <FileText class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: fatture passive</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Cosa entra in questo elenco e cosa no, come si legge una riga, e la domanda che arriva prima o poi a tutti: ho sbagliato una fattura, la elimino o la storno?
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — Cosa c'è qui -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Cosa entra in questo elenco</h3>
            <p class="mb-3">
              Le <strong>fatture dei fornitori</strong> e le <strong>note di credito</strong>. Ogni riga registrata qui produce una scrittura in partita doppia e alimenta lo scadenzario dei pagamenti.
            </p>
            <div class="p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Una Regolazione immediata non compare qui, ed è corretto.</strong> Non genera una fattura: è un movimento diretto, e si trova nel <strong>Libro Giornale</strong>. Il pulsante per crearla sta però in questa barra, quindi è facile registrarne una e poi non ritrovarla — per questo l'avviso in cima alla pagina le conta e ci porta direttamente.
              </div>
            </div>
          </section>

          <!-- 2 — Come si legge una riga -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Come si legge una riga</h3>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Pagamento</strong> — <em>Da pagare</em>, <em>Parziale</em>, <em>Pagata</em> o <em>Stornata</em>. Non lo imposti a mano: lo calcola il sistema dai pagamenti realmente registrati.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Approvazione</strong> — <em>Da approvare</em>, <em>Approvata</em>, <em>Contestata</em> o <em>Sforo motivato</em>.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Il badge <span class="inline-flex items-center bg-cyan-50 text-cyan-600 border border-cyan-200 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded">Ritenuta</span> segnala una fattura <strong>soggetta a ritenuta d'acconto</strong>: dalla cassa esce il netto, la ritenuta resta a debito verso l'Erario finché non la versi con l'F24.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>La graffetta indica un <strong>documento allegato</strong>: ci si clicca sopra per scaricare il PDF.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 3 — Sforo e ratifica -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ShieldCheck class="w-4 h-4 inline-block -mt-0.5 text-orange-500" /> Sforo di budget e ratifica
            </h3>
            <p class="mb-3">
              Se una fattura supera il preventivo del suo capitolo, il sistema non la rifiuta: chiede una <strong>motivazione scritta</strong> e la registra come <em>Sforo motivato</em>. La spesa è contabilizzata, ma resta segnata come da ratificare.
            </p>
            <div class="p-4 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex gap-3 text-[13px]">
              <TriangleAlert class="w-5 h-5 text-amber-600 shrink-0" />
              <div>
                <strong>Registrare non è deliberare.</strong> Uno sforo va portato in assemblea (art. 1135 c.c.): finché non lo si ratifica dal menu della riga («Ratifica assembleare»), la fattura resta nel contatore <em>Da ratificare</em> in cima alla pagina. La motivazione che hai scritto finisce nel rendiconto come nota, quindi vale la pena scriverla per chi la leggerà, non per chiudere il dialogo.
              </div>
            </div>
          </section>

          <!-- 4 — Eliminare o stornare -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ho sbagliato: elimino o storno?</h3>
            <p class="mb-3">
              La regola è una sola, e non è burocratica: <strong>si elimina ciò che non è ancora uscito dallo studio, si storna ciò che è già arrivato a qualcun altro.</strong>
            </p>
            <div class="space-y-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-red-600">
                  <FileText class="w-4 h-4" />
                  <h4 class="font-bold">Elimina</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Cancella la fattura e la sua scrittura, come se non fosse mai esistita. Va bene per l'errore di battitura scoperto subito, prima che qualcosa poggi su quel numero.
                </p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-amber-600">
                  <RotateCcw class="w-4 h-4" />
                  <h4 class="font-bold">Storna</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Genera una <strong>nota di credito</strong> che annulla la fattura lasciandone traccia. È la strada obbligata quando la fattura è già stata pagata, è finita in un piano rate emesso, o appartiene a un esercizio chiuso.
                </p>
              </div>
            </div>
            <div class="mt-4 p-4 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900 flex gap-3 text-[13px]">
              <RotateCcw class="w-5 h-5 text-emerald-600 shrink-0" />
              <div>
                <strong>Lo storno è reversibile, e quasi nessuno lo sa.</strong> La nota di credito che genera è a sua volta una fattura aperta, quindi eliminabile: eliminandola, la fattura originale torna allo stato calcolato dai pagamenti reali. Se hai stornato per sbaglio, non sei in un vicolo cieco.
              </div>
            </div>
          </section>

          <!-- 5 — Il divieto spiegato -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Ban class="w-4 h-4 inline-block -mt-0.5 text-slate-500" /> «Elimina — non consentito»
            </h3>
            <p class="mb-3">
              Quando la voce è grigia, passa il mouse sopra: il <strong>motivo</strong> e il <strong>rimedio</strong> sono nel suggerimento. Sono sette situazioni diverse, e per ciascuna c'è una strada:
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Copertura dal fondo confermata</strong> → storna prima il giroconto di conferma, dalla pagina Giroconti.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>È in un piano rate con rate emesse o incassate</strong> → annulla le emissioni di quel piano, oppure storna.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>È in un piano rate approvato</strong> → riporta il piano in bozza.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Pagata o parzialmente pagata</strong> → storna prima il pagamento, da Pagamenti fornitori.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Collegata a più scritture</strong>, <strong>esercizio chiuso</strong>, o <strong>già stornata</strong> → si usa lo Storno.</span>
                </li>
              </ul>
            </div>
            <p class="mt-3 text-[13px] text-slate-500 dark:text-slate-400">
              Il motivo che leggi è esattamente quello che applicherebbe il sistema se provassi: non è una previsione, è la stessa regola.
            </p>
          </section>

          <!-- 6 — Coperture -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Coins class="w-4 h-4 inline-block -mt-0.5 text-emerald-500" /> Coperture da fondo
            </h3>
            <p>
              Una fattura può essere coperta, in tutto o in parte, da un fondo già accantonato. Finché la copertura è <strong>in attesa</strong> è solo un'indicazione. Quando la <strong>confermi</strong>, nasce un giroconto vero nel Libro Giornale: da quel momento il fondo è stato consumato, e la fattura non si elimina più senza prima stornare quel giroconto — altrimenti resterebbe un movimento senza più il motivo che lo giustificava.
            </p>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
