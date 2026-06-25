<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { AlertTriangle, BookOpen, ChevronRight, Info } from 'lucide-vue-next';

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
              <BookOpen class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Ruoli e Usufrutto</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Comprendi a fondo il meccanismo di calcolo a cascata, le differenze tra le quote e la corretta impostazione delle tabelle millesimali per le unità in usufrutto.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
          
          <!-- Sezione 1 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I tre Ruoli in Kondomanager</h3>
            <p class="mb-3">
              In Kondomanager ci sono tre ruoli che puoi assegnare a un'anagrafica su un'unità: <strong class="text-indigo-600 dark:text-indigo-400">Proprietario, Inquilino, Usufruttuario</strong>.
              Quando su un appartamento esiste un usufrutto, inserisci due anagrafiche: una come Proprietario (che nella realtà giuridica è il <em>nudo proprietario</em>) e una come Usufruttuario.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Chi gode del bene</strong> (usufruttuario o inquilino se c'è) paga le <strong>spese ordinarie e i consumi</strong> (art. 1004 c.c.).</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Chi detiene il capitale</strong> (il nudo proprietario) paga le spese <strong>straordinarie</strong> (art. 1005 c.c.).</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- Sezione 2 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Due percentuali diverse: non confonderle</h3>
            <p class="mb-4">
              Nel sistema entrano in gioco due tipi di percentuale con scopi completamente diversi. Tenere questa distinzione chiara è la chiave per capire tutto il resto.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-900/50">
                <h4 class="font-bold text-blue-900 dark:text-blue-300 mb-2">1. Quota di Competenza %</h4>
                <p class="text-blue-800 dark:text-blue-200/80 leading-relaxed text-[13px]">
                  La imposti quando associ l'anagrafica all'immobile. Risponde alla domanda: <em>"Se più persone condividono lo stesso ruolo su questa unità, come si dividono tra loro la spesa?"</em> Esempio: due comproprietari al 50%. Non decide chi paga, ma come si distribuisce il totale destinato a quel ruolo.
                </p>
              </div>
              <div class="bg-amber-50 dark:bg-amber-900/20 p-4 rounded-xl border border-amber-100 dark:border-amber-900/50">
                <h4 class="font-bold text-amber-900 dark:text-amber-300 mb-2">2. Coefficiente di Tabella</h4>
                <p class="text-amber-800 dark:text-amber-200/80 leading-relaxed text-[13px]">
                  Lo imposti sulla tabella millesimale collegata alla spesa. Risponde alla domanda: <em>"Quale ruolo deve sostenere questa quota millesimale?"</em> "Inquilino 100%" significa che tutta la spesa va a chi ha il ruolo Inquilino. È qui che opera la cascata.
                </p>
              </div>
            </div>
          </section>

          <!-- Sezione 3 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              La Cascata di Risoluzione
            </h3>
            <p class="mb-4">
              La cascata si attiva <strong>solo quando il ruolo indicato nella tabella è assente sull'immobile</strong>. Se il ruolo è presente, il coefficiente vale esattamente come impostato, senza cascata.
            </p>
            <div class="space-y-4">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Spese Ordinarie e Consumi</h4>
                <div class="flex items-center gap-2 text-indigo-600 dark:text-indigo-400 font-semibold mb-2 bg-indigo-50 dark:bg-indigo-900/30 w-fit px-3 py-1 rounded-md">
                  <span>Inquilino</span>
                  <span>→</span>
                  <span>Usufruttuario</span>
                  <span>→</span>
                  <span>Proprietario</span>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Impostando sulla tabella <strong>Inquilino 100%</strong>, il sistema cerca prima l'inquilino; se non c'è, addebita all'usufruttuario; se non c'è, al proprietario. Con questa singola impostazione, la tabella funziona correttamente su qualsiasi composizione dell'unità (piena proprietà, in affitto o in usufrutto).
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Spese Straordinarie</h4>
                <div class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-semibold mb-2 bg-emerald-50 dark:bg-emerald-900/30 w-fit px-3 py-1 rounded-md">
                  <span>Proprietario (Nudo proprietario)</span>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Impostando sulla tabella <strong>Proprietario 100%</strong>, la quota va sempre a lui, indipendentemente dalla presenza di inquilini o usufruttuari. Terminazione diretta, nessuna cascata.
                </p>
              </div>
            </div>

            <div class="mt-4 p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Accordi derogatori (es. 70/30 pattuito per atto):</strong> la cascata segue il default normativo. Se vuoi applicare un accordo personalizzato (es. 70% Usufruttuario, 30% Proprietario), imposta esplicitamente questi due coefficienti sulla tabella.
              </div>
            </div>
          </section>

          <!-- Sezione 4 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Unità senza anagrafiche (Scoperti)</h3>
            <p class="mb-3">
              Se la cascata si esaurisce senza trovare nessun soggetto valido a cui addebitare, quella quota <strong>non viene spalmata in silenzio sugli altri condòmini</strong>. 
            </p>
            <p class="mb-3">
              Il sistema intercetta le quote come <em>"scoperti"</em> e mostra un riquadro di allerta. Se decidi di procedere forzatamente, l'importo della quota orfana rimane fuori dal totale del riparto, garantendo che gli altri condòmini paghino solo i propri millesimi reali.
            </p>
            <div class="p-4 rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800/50 flex gap-3">
              <AlertTriangle class="w-5 h-5 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-red-800 dark:text-red-200/90 leading-relaxed">
                <strong>Nota operativa:</strong> la motivazione viene salvata sul piano rate ed è visibile nel dettaglio. L'importo scoperto non viene però registrato come quota contabile — non avendo un soggetto a cui intestarlo, il sistema lo documenta ma non lo contabilizza. Se in seguito l'anagrafica viene censita, non bisogna creare finte fatture per recuperare i soldi: il sistema assegnerà la quota al condomino in automatico a fine anno in sede di <strong>conguaglio</strong>, oppure potrai recuperarla tramite un addebito manuale.
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
