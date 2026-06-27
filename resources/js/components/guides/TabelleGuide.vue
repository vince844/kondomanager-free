<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { BookOpen, ChevronRight, Info, Table } from 'lucide-vue-next';

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
              <Table class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: tabelle millesimali</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Comprendi come configurare correttamente le tabelle di ripartizione, la differenza tra le varie unità di misura e i comportamenti suggeriti per legge.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
          
          <!-- Sezione 1 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">La filosofia delle tabelle</h3>
            <p class="mb-3">
              In Kondomanager, la tabella millesimale è il cuore pulsante di ogni ripartizione contabile. Ogni spesa viene associata ad una o più tabelle, determinando così come distribuire l'importo tra i vari condòmini.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Kondomanager è progettato per operare in <strong>modalità advisory</strong>: per le tabelle generiche non impone alcun criterio obbligato, lasciando a te il controllo completo.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>L'unica eccezione è l'attivazione di suggerimenti (come la pre-selezione dell'unità "Quote" per la tabella Ascensore) quando vi è una chiara direttiva normativa (Art. 1124 c.c.).</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- Sezione 2 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Scegliere l'unità di misura</h3>
            <p class="mb-4">
              La flessibilità del motore di Kondomanager ti permette di usare varie unità di misura, ognuna con un significato matematico preciso:
            </p>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-900/50">
                <h4 class="font-bold text-blue-900 dark:text-blue-300 mb-2">Millesimi (‰)</h4>
                <p class="text-blue-800 dark:text-blue-200/80 leading-relaxed text-[13px]">
                  Il classico calcolo proporzionale normativo, generalmente con un totale che somma a 1.000. Ideale per le tabelle Generali, Proprietà, Scale e Riscaldamento Termico.
                </p>
              </div>
              <div class="bg-emerald-50 dark:bg-emerald-900/20 p-4 rounded-xl border border-emerald-100 dark:border-emerald-900/50">
                <h4 class="font-bold text-emerald-900 dark:text-emerald-300 mb-2">Quote (q.)</h4>
                <p class="text-emerald-800 dark:text-emerald-200/80 leading-relaxed text-[13px]">
                  Le quote vengono riproporzionate sul totale, qualunque esso sia. Perfetto per la tabella Ascensore a frazioni (es. 1 al PT, 2 al piano 1, totale 12) o ripartizioni in parti uguali (es. 1 per ogni appartamento).
                </p>
              </div>
            </div>
            
            <div class="mt-4 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
               <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-2">Unità di misura per consumo</h4>
               <ul class="space-y-1 text-[13px] text-slate-600 dark:text-slate-400">
                  <li><strong class="text-slate-700 dark:text-slate-300">Metri cubi (mc):</strong> Da utilizzare solitamente per il servizio Acqua, basandosi sulle letture individuali.</li>
                  <li><strong class="text-slate-700 dark:text-slate-300">Kilowatt (kw):</strong> Necessario solo per il Riscaldamento se si possiedono fisicamente i contabilizzatori di calore individuali installati sui radiatori.</li>
                  <li><strong class="text-slate-700 dark:text-slate-300">Persone:</strong> Un'alternativa per Acqua o Ascensore (es. calcolo a forfait per testa).</li>
               </ul>
            </div>
          </section>

          <!-- Sezione 3 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              Le tipologie di tabella
            </h3>
            
            <div class="space-y-4">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Standard</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  La tipologia principale per le ripartizioni classiche (Proprietà Generale, Scale, ecc.). Non ha comportamenti speciali.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Ascensore (Art. 1124)</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  La legge prevede che l'ascensore sia ripartito per il 50% in base ai millesimi di proprietà e per il 50% in base all'altezza del piano. In Kondomanager <strong>non applichiamo questa complessa miscelazione automaticamente</strong>. Devi calcolare le frazioni risultanti (es. col tuo geometra) ed inserirle come "Quote" proporzionali. Ecco perché selezionando "Ascensore", il sistema preseleziona la voce Quote per comodità.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Acqua e Riscaldamento</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Non esiste un default automatico per queste tipologie perché le casistiche in Italia sono eterogenee (metri cubi per l'acqua; millesimi o kwatt per il riscaldamento). Sta all'amministratore selezionare l'unità corretta in base al regolamento contrattuale o assembleare del condominio.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Lastrico Solare / Speciale / Altro</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Ideali per ripartizioni come tetti, corselli box o il classico 1/3 e 2/3. Ti aiutano a mantenere in ordine il piano dei conti e a rendere i preventivi più chiari.
                </p>
              </div>
            </div>
          </section>

          <!-- Sezione 4 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Impostazioni avanzate</h3>
            
            <div class="mt-4 p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Assegnazione a palazzine o scale:</strong> Puoi delimitare l'uso di una tabella (es. Tabella Scale Scala B) solo agli immobili che fanno fisicamente parte di quella scala. Questo aiuta a non commettere errori quando si imputano le spese nel preventivo.
                <br><br>
                <strong>Inserimento massivo:</strong> Selezionando la spunta "Associa tutti gli immobili esistenti" durante la creazione, il sistema dopo il salvataggio ti porterà in una schermata dedicata. Lì vedrai l'elenco completo degli immobili e potrai inserire massivamente tutte le quote e i parametri in un colpo solo, risparmiando tantissimo tempo.
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
