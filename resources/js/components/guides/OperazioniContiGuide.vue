<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Settings2, BookOpen, ChevronRight, FolderTree, Calculator, Printer, Layers, Receipt, ArrowUpDown, AlertTriangle, Wallet } from 'lucide-vue-next';

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
            Come strutturare il piano dei conti, leggere i tre numeri di ogni voce — preventivo, coperto e speso — e associare le tabelle millesimali.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- Struttura ad Albero -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <FolderTree class="w-5 h-5 text-indigo-500" /> Struttura ad albero
            </h3>
            <p class="mb-3">
              Le spese si organizzano gerarchicamente: un <strong>capitolo</strong> raccoglie più <strong>sottoconti</strong>. Questo dà precisione ai bilanci e ai riparti.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-3">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Creare le voci:</strong> clicca su "Aggiungi voce". Per creare un sottoconto dipendente da una voce principale, seleziona quella voce nel campo <em>Capitolo padre</em>.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Due livelli, non tre:</strong> il capitolo raggruppa, il sottoconto porta l'importo e la tabella millesimale. Come <em>capitolo padre</em> si può scegliere solo una voce di primo livello — un sottoconto non può averne altri sotto di sé.
                  <br />
                  Se aggiornando da una versione precedente trovi una voce con un <strong>triangolo ambra</strong>, è finita al terzo livello quando era ancora possibile: finché resta lì, un piano rate generato includendo tutte le voci <strong>non la addebita</strong>. Spostala sotto un capitolo di primo livello, oppure eliminala. Per trovarle tutte:
                  <code class="text-xs">php artisan kondomanager:verifica-struttura-conti</code>.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Due blocchi distinti:</strong> in alto (icona lucchetto) le spese del <em>preventivo deliberato</em>; sotto (icona arancione) le <em>sopravvenienze e imprevisti</em>, cioè le spese nate fuori preventivo durante la gestione. Le seconde non hanno un preventivo per definizione: nella colonna Preventivo mostrano un trattino, non zero.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- I TRE NUMERI -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Calculator class="w-5 h-5 text-amber-500" /> I tre numeri di ogni voce
            </h3>
            <p class="mb-4 text-[13px] leading-relaxed">
              Su ogni riga trovi tre grandezze che rispondono a tre domande diverse. Confonderle è l'errore più comune, perché possono divergere fra loro pur essendo tutte corrette.
            </p>

            <div class="space-y-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Preventivo — «quanto avevo previsto»</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  L'importo deliberato per quella voce, <strong>IVA inclusa</strong>: il consuntivo con cui viene confrontato somma il lordo delle fatture, quindi un preventivo scritto al netto risulterebbe sforato appena registrata la prima spesa. Il budget inserito nei sottoconti risale e si somma sul capitolo padre. Si modifica selezionando la voce e usando "Modifica" nel pannello di destra.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Coperto da piano rate — «quanto ho chiesto ai condòmini»</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  È la barra colorata sotto la voce. Indica la quota di preventivo già inserita in un piano rate (in bozza o approvato). <strong>Non è la spesa sostenuta</strong>: una voce può essere coperta al 100% senza che sia uscito un euro, e viceversa.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Consuntivo — «quanto ho speso davvero»</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Comprende le <strong>fatture registrate</strong> — anche quelle non ancora pagate, perché il costo è di competenza dal momento della registrazione — e le <strong>regolazioni immediate</strong>. Le note di credito scomputano, gli storni azzerano. Restano fuori le fatture contestate e le spese addebitate a singole unità.
                  Un trattino significa che su quella voce non è ancora stato speso nulla.
                </p>
              </div>
            </div>

            <div class="mt-4 p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800 text-[13px]">
              <p class="font-bold text-amber-900 dark:text-amber-200 mb-2">Cosa significa quando divergono</p>
              <ul class="space-y-1.5 text-amber-900/90 dark:text-amber-200/90">
                <li><strong>Consuntivo maggiore del Preventivo</strong> → sforo. L'importo compare in rosso con un triangolo di allarme; passando il mouse leggi di quanto.</li>
                <li><strong>Coperto minore del Preventivo</strong> → una parte del budget non è ancora stata chiesta ai condòmini. Va emesso un piano rate, o quella spesa resterà senza copertura.</li>
                <li><strong>Consuntivo maggiore del Coperto</strong> → hai speso più di quanto hai richiesto: è la situazione che mette sotto pressione la cassa, anche quando il preventivo formalmente regge.</li>
              </ul>
            </div>

            <div class="mt-3 p-4 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 text-[13px]">
              <p class="font-bold text-slate-800 dark:text-slate-200 mb-1">In testata, gli stessi tre numeri per l'intero piano</p>
              <p class="text-slate-600 dark:text-slate-400">
                <strong>Preventivo</strong> è il totale deliberato e non cresce quando si sfora; <strong>Consuntivo</strong> è la spesa complessiva; <strong>Sopravvenienze</strong> raccoglie a parte le voci nate fuori preventivo.
              </p>
            </div>
          </section>

          <!-- DRILL-DOWN -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Receipt class="w-5 h-5 text-emerald-500" /> Da dove arriva il consuntivo
            </h3>
            <p class="mb-3 text-[13px] leading-relaxed">
              Il totale dice <em>quanto</em>; cliccandolo scopri <em>per colpa di cosa</em>. Si apre l'elenco dei movimenti che lo compongono — data, causale, tipo e importo — e da ogni riga raggiungi la scrittura corrispondente nel Libro Giornale.
            </p>
            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm text-[13px] text-slate-600 dark:text-slate-400 space-y-2">
              <p>
                Gli <strong>storni compaiono con importo negativo</strong> invece di essere nascosti: fanno parte della storia che spiega il totale, e senza di loro il conto non tornerebbe.
              </p>
              <p>
                Su voci con moltissimi movimenti l'elenco mostra i più recenti e lo dichiara: il totale in alto resta comunque quello completo della voce. Per l'elenco integrale usa il Libro Giornale.
              </p>
            </div>
          </section>

          <!-- ORDINAMENTO -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <ArrowUpDown class="w-5 h-5 text-indigo-500" /> Ordinare l'elenco
            </h3>
            <p class="mb-3 text-[13px] leading-relaxed">
              Sopra l'elenco, accanto a "Voce di spesa", puoi scegliere se ordinare per <strong>Nome</strong> o per <strong>Codice</strong>. Il selettore compare solo se hai assegnato un codice ad almeno una voce.
            </p>
            <ul class="list-disc list-inside space-y-1.5 text-[13px] text-slate-600 dark:text-slate-400 pl-2">
              <li>L'ordinamento per codice è <strong>naturale</strong>: <code>A.2</code> viene prima di <code>A.10</code>, e <code>999</code> prima di <code>1020</code>.</li>
              <li>Le voci <strong>senza codice finiscono in fondo</strong>, raggruppate e ordinate per nome fra loro.</li>
              <li>I capitoli restano in cima al proprio livello: è la struttura dell'albero, non una preferenza.</li>
              <li>La scelta viene ricordata, ed <strong>è la stessa che userà la stampa</strong> "Distinta base".</li>
            </ul>
          </section>

          <!-- Tabelle Millesimali -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Settings2 class="w-5 h-5 text-emerald-500" /> Associazione tabelle
            </h3>
            <p class="mb-3 text-[13px] leading-relaxed">
              L'associazione della tabella millesimale alla voce di spesa è il motore di tutto il gestionale. Senza, il sistema non sa come ripartire la spesa.
            </p>
            <div class="space-y-4">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Come si associa</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Seleziona la voce foglia dall'albero di sinistra, poi "Aggiungi" nel pannello di destra. Scegli la tabella e assegnale un coefficiente.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">La regola del residuo (100%)</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Una spesa non deve essere coperta da una sola tabella: puoi associare "Manutenzione" alla Tabella Scala A al 50% e alla Scala B al restante 50%. Il sistema mostra sempre il residuo disponibile.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1 flex items-center gap-1.5">
                  <AlertTriangle class="w-4 h-4 text-amber-500" /> Quando la ripartizione è bloccata
                </h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Se la voce è inclusa in un piano rate approvato o con rate già emesse, le tabelle non si possono più modificare: cambiarle renderebbe i riparti già comunicati ai condòmini diversi da quelli ricalcolati. Per intervenire occorre prima annullare il piano rate associato.
                </p>
              </div>
            </div>

            <div class="mt-4 p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 flex gap-3 text-[13px]">
              <BookOpen class="w-5 h-5 text-blue-500 shrink-0" />
              <div>
                <strong>Ripartizione ruoli (cascata):</strong> durante l'associazione ti verrà chiesto quali quote assegnare a inquilino, usufruttuario o proprietario.
                Per la logica di fallback consulta la guida <em>Ruoli e Usufrutto</em> nel menu Guide.
              </div>
            </div>
          </section>

          <!-- SFORI -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Wallet class="w-5 h-5 text-amber-500" /> Quando una voce va in sforo
            </h3>
            <p class="mb-3 text-[13px] leading-relaxed">
              Selezionando la voce, il riquadro "Gestione sfori budget" mostra come hai deciso di rientrare. Ogni strategia porta un'etichetta esplicita:
            </p>
            <ul class="list-disc list-inside space-y-1.5 text-[13px] text-slate-600 dark:text-slate-400 pl-2">
              <li><strong>Coperto da fondo</strong> — l'eccedenza attinge al fondo di riserva.</li>
              <li><strong>A consuntivo</strong> — si sistema col conguaglio di fine gestione.</li>
              <li><strong>Da coprire con rata integrativa</strong> — serve una nuova emissione. Solo in questo caso compare il collegamento <em>"Crea il piano rate"</em>, che apre la creazione con gestione e tipo già impostati: negli altri due una rata non sarebbe la risposta.</li>
            </ul>
          </section>

          <!-- Stampe -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Printer class="w-5 h-5 text-indigo-500" /> Stampe disponibili
            </h3>
            <p class="text-[13px] leading-relaxed mb-2">
              Dal menu "Stampe" in alto a destra:
            </p>
            <ul class="list-disc list-inside space-y-1 text-[13px] text-slate-600 dark:text-slate-400 pl-2">
              <li><strong>Distinta base:</strong> il riepilogo delle voci con i relativi preventivi. Rispetta l'ordinamento scelto a schermo, nome o codice.</li>
              <li><strong>Ripartizione spese:</strong> proietta come i preventivi verranno suddivisi per singola unità — il documento da portare in assemblea per la delibera.</li>
            </ul>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
