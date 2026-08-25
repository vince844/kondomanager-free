<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Zap, Info, AlertTriangle } from 'lucide-vue-next';

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
            <div class="p-2 bg-amber-100 text-amber-700 rounded-lg dark:bg-amber-900 dark:text-amber-300">
              <Zap class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Regolazione immediata</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            La prima nota diretta per i fatti che nascono e si estinguono nello stesso momento:
            quando usarla, quando invece serve una fattura, e come si corregge un errore.
          </SheetDescription>
        </SheetHeader>

        <Tabs default-value="cosa" class="w-full">
          <TabsList class="grid w-full grid-cols-3 mb-6">
            <TabsTrigger value="cosa">A cosa serve</TabsTrigger>
            <TabsTrigger value="quando-no">Quando NO</TabsTrigger>
            <TabsTrigger value="correzioni">Correzioni</TabsTrigger>
          </TabsList>

          <!-- TAB: A cosa serve -->
          <TabsContent value="cosa" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Costo → banca, in un'unica scrittura</h3>
              <p class="mb-3">
                Bolli, commissioni bancarie e postali, addebiti automatici, piccole spese già pagate:
                fatti amministrativi che <strong>nascono e si estinguono nello stesso momento</strong>.
                Aprire per ognuno una partita fornitore significherebbe inventare un fornitore fittizio
                "BANCA" e sporcare il libro giornale con documenti che non esistono.
              </p>
              <p class="mb-3">
                Qui registri il fatto com'è: <strong>DARE sul capitolo di spesa, AVERE sulla cassa</strong>,
                protocollo RIM, causale. Niente scadenziario, niente stato di pagamento da inseguire —
                il denaro è già uscito.
              </p>
              <ul class="space-y-2 list-disc pl-5">
                <li>Il <strong>fornitore è facoltativo</strong> ed è solo un'etichetta per la reportistica:
                    non movimenta il mastro Debiti verso Fornitori.</li>
                <li>Il capitolo mostra il suo residuo e il movimento pesa sul budget come ogni spesa.</li>
                <li>I <strong>fondi non compaiono</strong> fra le casse: sono partizioni contabili del conto
                    corrente, non casse da cui esce denaro — un'uscita reale parte da banca o contanti.</li>
                <li>Con più operazioni in fila, <strong>"Registra e nuova"</strong> salva e riapre il modulo
                    vuoto, senza passare dal dettaglio della scrittura.</li>
              </ul>
            </section>
            <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
              <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                Il pannello "Anteprima scrittura" mostra le due righe DARE/AVERE come compariranno nel
                libro giornale, prima di registrare. Se l'anteprima non ti convince, non è ancora successo niente.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Quando NO -->
          <TabsContent value="quando-no" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I casi in cui serve la fattura, e il sistema te lo dirà</h3>
              <p class="mb-3">
                La regolazione immediata <strong>affianca</strong> il ciclo fattura → debito → pagamento,
                non lo sostituisce. Il sistema rifiuta la registrazione — spiegando il perché — quando il
                fatto richiede la struttura del debito:
              </p>
              <ul class="space-y-3 list-disc pl-5">
                <li>
                  <strong>Fornitore soggetto a ritenuta d'acconto</strong> — il condominio è sostituto
                  d'imposta: il corrispettivo va spezzato fra netto al fornitore e ritenuta all'Erario,
                  e questo esiste solo nel flusso fattura. Il modulo ti avvisa già alla selezione del
                  fornitore, prima del click.
                </li>
                <li>
                  <strong>Debito da tracciare nel tempo</strong> — se c'è una scadenza futura, un documento
                  da pagare più avanti, un'esposizione verso il fornitore: quella è una fattura passiva,
                  con il suo scadenziario.
                </li>
                <li>
                  <strong>Documenti fiscali veri</strong> — una fattura con numero e data del fornitore
                  merita il suo posto nel ciclo passivo, anche se l'hai già pagata: registrala come
                  fattura, così protocollo e documento restano allineati.
                </li>
              </ul>
            </section>
            <div class="p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
              <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                Regola pratica: se esiste un pezzo di carta con scritto "fattura", usa la registrazione
                fattura. Se è una riga dell'estratto conto senza documento, sei nel posto giusto.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Correzioni -->
          <TabsContent value="correzioni" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Sbagliato importo o capitolo? Si storna</h3>
              <p class="mb-3">
                Il libro giornale non si cancella e non si riscrive: la correzione è lo <strong>storno</strong> —
                una scrittura inversa con protocollo STO che neutralizza l'originale e libera il budget del
                capitolo. Lo trovi nel dettaglio della scrittura, con motivazione obbligatoria che resta agli atti.
              </p>
              <ul class="space-y-2 list-disc pl-5">
                <li>Lo storno è <strong>sempre ammesso</strong>, anche a distanza di tempo.</li>
                <li>Se l'esercizio dell'originale è stato chiuso nel frattempo, lo storno si appoggia
                    all'esercizio aperto, con la provenienza indicata in causale.</li>
                <li>Dopo lo storno, registri semplicemente il movimento giusto: due fatti veri nel giornale
                    raccontano più di una riga riscritta.</li>
              </ul>
            </section>
          </TabsContent>
        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
