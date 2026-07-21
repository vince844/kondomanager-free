<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Repeat2, Info, AlertTriangle, ShieldCheck } from 'lucide-vue-next';

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
            <div class="p-2 bg-violet-100 text-violet-700 rounded-lg dark:bg-violet-900 dark:text-violet-300">
              <Repeat2 class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Giroconti e fondi</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Come funzionano gli spostamenti di liquidità fra le casse, la conferma delle coperture
            dal fondo e lo storno — con la partita doppia spiegata senza giri di parole.
          </SheetDescription>
        </SheetHeader>

        <Tabs default-value="concetti" class="w-full">
          <TabsList class="grid w-full grid-cols-4 mb-6">
            <TabsTrigger value="concetti">Concetti</TabsTrigger>
            <TabsTrigger value="operazioni">Operazioni</TabsTrigger>
            <TabsTrigger value="coperture">Coperture</TabsTrigger>
            <TabsTrigger value="riallineamento">Riallineamento</TabsTrigger>
          </TabsList>

          <!-- TAB: Concetti -->
          <TabsContent value="concetti" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il fondo non è un secondo conto corrente</h3>
              <p class="mb-3">
                Il condominio ha <strong>un solo conto corrente reale</strong>. I fondi (riserva, lavori, TFR…)
                sono <strong>ripartizioni contabili</strong> di quel conto: etichette che dicono "questa parte
                del saldo è accantonata e non va spesa per la gestione ordinaria". Il denaro non si muove mai
                dal c/c — cambia solo la sua destinazione.
              </p>
              <p>
                Un <strong>giroconto</strong> è l'atto che sposta la destinazione: una scrittura in partita
                doppia a due righe, con protocollo <strong>GIR</strong>, che compare nel libro giornale come
                ogni altro movimento. La cassa di <strong>destinazione</strong> va in DARE (aumenta), quella di
                <strong>origine</strong> in AVERE (diminuisce). La liquidità complessiva resta identica.
              </p>
            </section>
            <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
              <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                Per questo in banca vedrai sempre il saldo pieno del c/c, mentre qui il gestionale ti dice
                <strong>quanto di quel saldo è davvero spendibile</strong> e quanto è accantonato. È lo stesso
                numero che usa il semaforo di tesoreria per la liquidità disponibile.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Operazioni -->
          <TabsContent value="operazioni" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I tre movimenti possibili</h3>
              <ul class="space-y-3 list-disc pl-5">
                <li>
                  <strong>Accantonamento (banca → fondo)</strong> — metti da parte: il saldo spendibile scende,
                  il fondo sale. Es. l'accantonamento mensile deliberato per il fondo lavori.
                </li>
                <li>
                  <strong>Utilizzo (fondo → banca)</strong> — liberi liquidità accantonata riportandola alla
                  gestione. È anche l'atto che <strong>conferma una copertura</strong> (vedi scheda Coperture).
                </li>
                <li>
                  <strong>Ridestinazione (fondo → fondo)</strong> — sposti un accantonamento da un fondo a un
                  altro, ad esempio dopo una delibera che cambia la destinazione.
                </li>
              </ul>
            </section>
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">Le regole che il sistema fa rispettare</h3>
              <ul class="space-y-2 list-disc pl-5">
                <li><strong>Capienza</strong>: la cassa di origine non può mai andare sotto zero. Nessuna eccezione.</li>
                <li><strong>Fondi vincolati</strong> (lavori, TFR, morosità): per farli uscire dalla loro destinazione
                    serve la <strong>deroga assembleare</strong>, attivabile dalla scheda della cassa con motivazione.
                    L'ingresso in un fondo vincolato è invece sempre libero.</li>
                <li><strong>Mai fondo ↔ contanti</strong>: la liquidità del fondo vive sul c/c. Un prelievo in
                    contanti passa da fondo → banca → contanti, in due movimenti espliciti.</li>
                <li><strong>Data non futura</strong> ed <strong>esercizio aperto</strong>, come ogni scrittura.</li>
              </ul>
            </section>
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">Sbagliato qualcosa? Si storna</h3>
              <p>
                Il giornale non si cancella e non si riscrive: lo <strong>storno</strong> (dal menu azioni della
                riga) crea la scrittura inversa con protocollo <strong>STO</strong>, motivazione obbligatoria, e
                riporta i saldi esattamente com'erano. Lo storno è sempre ammesso — anche se lascia un fondo in
                rosso, perché correggere un errore non può mai essere vietato: il rosso resta visibile finché
                non sistemi anche il movimento a valle.
              </p>
            </section>
          </TabsContent>

          <!-- TAB: Coperture -->
          <TabsContent value="coperture" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il ciclo di vita di una copertura dal fondo</h3>
              <p class="mb-3">
                Quando registri una fattura che sfora il budget e scegli la strategia
                <strong>"Fondo di riserva"</strong>, il fondo <strong>non viene toccato subito</strong>: nasce
                una copertura <em>pianificata</em> — una promessa, tracciata ma senza effetto contabile.
              </p>
              <ol class="space-y-2 list-decimal pl-5 mb-3">
                <li>La fattura mostra un banner <strong class="text-violet-700 dark:text-violet-400">viola</strong>:
                    "Copertura in attesa di conferma", con il pulsante <em>Conferma con giroconto</em>.</li>
                <li>Il pulsante apre questa sezione con il modulo <strong>già precompilato e bloccato</strong>
                    (fondo di origine, importo esatto, causale proposta): scegli solo la banca di destinazione.</li>
                <li>Alla registrazione del GIR, il fondo si decurta, la liquidità torna spendibile e la
                    copertura diventa <em>confermata</em>: la fattura passa al banner
                    <strong class="text-emerald-700 dark:text-emerald-400">verde</strong> con il protocollo del
                    giroconto e il collegamento al pagamento.</li>
                <li>Il pagamento al fornitore esce dalla banca col flusso normale — il denaro è sempre stato lì.</li>
              </ol>
              <p class="mb-3">
                Puoi confermare <strong>prima o dopo</strong> aver pagato: contabilmente funzionano entrambi.
                Le coperture ancora in attesa le trovi anche nel modulo "Nuovo giroconto", nel riquadro scuro in testa.
              </p>
              <p class="text-[13px] text-slate-500 dark:text-slate-400">
                <strong>Da non confondere col riallineamento</strong>: le coperture in attesa riguardano fatture
                <em>di oggi</em> che aspettano il loro giroconto; il riallineamento (scheda successiva) è una
                correzione <em>una tantum</em> delle scritture storiche precedenti alla versione beta.19.
              </p>
            </section>
            <div class="p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
              <ShieldCheck class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                <strong>Finché la conferma è viva, la fattura è protetta</strong>: non si storna, non si elimina,
                non si modifica. Prima si storna il giroconto (la copertura torna in attesa), poi si interviene
                sulla fattura. Così nel giornale non restano mai giroconti orfani di un debito che non esiste più.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Riallineamento -->
          <TabsContent value="riallineamento" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">L'avviso "Scritture storiche da riallineare"</h3>
              <p class="mb-3">
                Prima della versione 1.10.0-beta.19 le coperture dal fondo scrivevano righe contabili direttamente
                sul conto del fondo, con una convenzione di segno poi unificata. Se hai fatture di quel periodo,
                quelle righe oggi <strong>gonfierebbero il saldo del fondo</strong>: l'avviso in cima a questa
                pagina compare solo in quel caso, con l'elenco esatto delle scritture coinvolte.
              </p>
              <ul class="space-y-2 list-disc pl-5 mb-3">
                <li>Il pulsante genera normali <strong>scritture di rettifica</strong> (protocollo RET), collegate
                    alle originali: niente viene modificato o cancellato, il giornale resta intatto.</li>
                <li>Si fa <strong>una volta sola</strong>: a rettifiche create l'avviso sparisce da solo e i saldi
                    dei fondi tornano a riflettere i soli giroconti.</li>
                <li>Se non hai mai registrato sfori coperti dal fondo prima della beta.19, non vedrai mai l'avviso.</li>
              </ul>
            </section>
            <div class="p-4 rounded-lg border border-rose-200 bg-rose-50 dark:bg-rose-900/20 dark:border-rose-800/50 flex gap-3">
              <AlertTriangle class="w-5 h-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-rose-800 dark:text-rose-200/90 leading-relaxed">
                Se dopo l'aggiornamento un fondo mostra un saldo che ti sembra il doppio del dovuto, è quasi
                certamente questo: apri questa pagina ed esegui il riallineamento prima di registrare nuovi
                giroconti su quel fondo.
              </div>
            </div>
          </TabsContent>
        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
