<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Settings2, BookOpen, ChevronRight, FolderTree, Calculator, Printer, Layers } from 'lucide-vue-next';

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
            <div class="p-2 bg-emerald-100 text-emerald-700 rounded-lg dark:bg-emerald-900 dark:text-emerald-300">
              <Layers class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Operazioni e Struttura</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Scopri come strutturare il tuo piano dei conti, configurare i preventivi ed associare correttamente le tabelle millesimali alle spese.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
          
          <!-- Struttura ad Albero -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <FolderTree class="w-5 h-5 text-indigo-500" /> Struttura ad Albero
            </h3>
            <p class="mb-3">
              Kondomanager ti permette di organizzare le spese gerarchicamente, offrendoti precisione nei bilanci e nei riparti.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-3">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Mastri e Sottoconti:</strong> Clicca su "+ Aggiungi Voce". Per creare un "sottoconto" che dipende da una voce principale, ti basterà selezionare la voce superiore nel campo <em>Capitolo Padre</em> del modulo di creazione.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Preventivo vs Sopravvenienze:</strong> L'elenco è diviso in due blocchi. Il primo blocco (icona lucchetto) raccoglie tutte le spese ordinarie del <em>preventivo deliberato</em>. Il secondo blocco (icona warning arancione) raggruppa eventuali <em>spese fuori preventivo</em>, emerse durante la gestione.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- Preventivo -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Calculator class="w-5 h-5 text-amber-500" /> Gestione del Preventivo
            </h3>
            <p class="mb-4 text-[13px] leading-relaxed">
              Puoi assegnare un importo preventivato a ogni singola voce spesa. 
              Il budget inserito nei sottoconti "risale" e si somma automaticamente sui Mastri padre corrispondenti. Puoi modificare in ogni momento il preventivo selezionando la voce e cliccando sull'icona della matita nel pannello "Dettagli voce selezionata" sulla destra.
            </p>
          </section>

          <!-- Tabelle Millesimali -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Settings2 class="w-5 h-5 text-emerald-500" /> Associazione Tabelle
            </h3>
            <p class="mb-3 text-[13px] leading-relaxed">
              L'associazione della Tabella Millesimale alla voce di spesa è il motore di tutto il gestionale. Senza quest'associazione il sistema non saprà come ripartire la spesa.
            </p>
            <div class="space-y-4">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Come si associa?</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Seleziona la voce foglia desiderata dall'albero di sinistra. Nel pannello di destra, clicca su "Associa Tabella". Potrai scegliere la tabella e assegnarle un coefficiente (Quota %).
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">La Regola del Residuo (100%)</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Una spesa non deve necessariamente essere coperta da una sola tabella. Puoi associare alla spesa "Manutenzione" la Tabella Scala A al 50% e la Tabella Scala B all'altro 50%. Il sistema ti guiderà calcolando sempre il "Residuo Disponibile".
                </p>
              </div>
            </div>
            
            <div class="mt-4 p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 flex gap-3 text-[13px]">
              <BookOpen class="w-5 h-5 text-blue-500 shrink-0" />
              <div>
                <strong>Ripartizione Ruoli (Cascata):</strong> Durante l'associazione della tabella ti verrà chiesto quali quote assegnare a Inquilino, Usufruttuario o Proprietario. 
                Se hai dubbi su come funziona la logica di fallback, consulta l'altra guida dedicata a <em>Ruoli e Usufrutto</em> presente nel menu Guide.
              </div>
            </div>
          </section>

          <!-- Stampe -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Printer class="w-5 h-5 text-indigo-500" /> Stampe Disponibili
            </h3>
            <p class="text-[13px] leading-relaxed mb-2">
              Tramite il menu a tendina "Stampe" (in alto a destra), puoi esportare due preziosi documenti:
            </p>
            <ul class="list-disc list-inside space-y-1 text-[13px] text-slate-600 dark:text-slate-400 pl-2">
              <li><strong>Distinta Base:</strong> il riepilogo complessivo del tuo albero dei conti.</li>
              <li><strong>Ripartizione Spese:</strong> un documento che proietta come verranno suddivisi i preventivi inseriti per singola unità, fondamentale per presentare le delibere in assemblea.</li>
            </ul>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
