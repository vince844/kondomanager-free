<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Printer, Info, CheckCircle, Image as ImageIcon } from 'lucide-vue-next';

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
              <Printer class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Impaginazione Stampe PDF</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Scopri come formattare correttamente il footer e la firma per ottenere documenti PDF perfetti.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
          
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Info class="w-5 h-5 text-blue-500" /> Formattazione della Nota Legale (Footer)
            </h3>
            <p class="mb-3">
              Il testo che inserisci nel campo "Nota Legale" verrà stampato in calce (a piè di pagina) in tutti i documenti generati dal sistema, accompagnato dal numero di pagina corrente.
            </p>
            <ul class="space-y-3 list-disc pl-5 mb-4">
              <li>
                <strong>Andare a capo:</strong> Puoi premere semplicemente il tasto <code>Invio</code> sulla tastiera. Il motore PDF rispetterà automaticamente gli a capo per creare testi su più righe.
              </li>
              <li>
                <strong>Spazi multipli:</strong> Se hai bisogno di distanziare visivamente due elementi sulla stessa riga (es. Partita IVA e numero di Polizza), puoi digitare più spazi consecutivi tramite la barra spaziatrice. Il sistema li preserverà senza comprimerli.
              </li>
            </ul>
            <div class="bg-slate-900 text-emerald-400 p-4 rounded-xl font-mono text-[11px] overflow-x-auto shadow-inner mb-2 leading-relaxed">
              Amministratore Professionista L. 4/2013<br>
              P.IVA 01234567890&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Polizza RC 987654321
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2 mt-6">
              <ImageIcon class="w-5 h-5 text-emerald-500" /> Ottimizzazione della Firma
            </h3>
            <p class="mb-3">
              L'immagine della firma viene inserita automaticamente in fondo all'ultima pagina dei riparti preventivi e consuntivi. Segui queste indicazioni per un risultato professionale:
            </p>
            <div class="grid gap-3 mb-4">
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Dimensioni ideali (~400x150 px)</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Proporzioni rettangolari. Evita di caricare foto quadrate ad alta risoluzione (es. foto dal cellulare non ritagliate), poiché verrebbero ridimensionate in modo imprevedibile sfalsando il layout.
                  </p>
                </div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Sfondo trasparente (PNG)</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Per integrarsi alla perfezione sul foglio, anche qualora lo sfondo del documento non fosse perfettamente bianco, salva l'immagine in formato PNG rimuovendo lo sfondo bianco.
                  </p>
                </div>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Ritaglia i margini morti</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400">
                    Assicurati di ritagliare l'immagine in modo che l'inchiostro della firma tocchi quasi i bordi del file. Margini bianchi eccessivi intorno al tratto faranno sembrare la firma molto piccola nel documento stampato.
                  </p>
                </div>
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
