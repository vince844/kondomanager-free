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
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: ruoli e usufrutto</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Chi paga una voce quando sull'unità ci sono un usufruttuario, un nudo proprietario o un inquilino: come il programma sceglie la persona, e come impostare le voci.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- Sezione 1 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I quattro ruoli</h3>
            <p class="mb-3">
              I ruoli che puoi assegnare a un'anagrafica su un'unità sono quattro: <strong class="text-indigo-600 dark:text-indigo-400">Proprietario, Nudo proprietario, Usufruttuario, Inquilino</strong>.
              Quando su un appartamento esiste un usufrutto, inserisci due anagrafiche: una come <strong>Nudo proprietario</strong> e una come <strong>Usufruttuario</strong>.
            </p>
            <p class="mb-3 text-[13px] text-slate-500 dark:text-slate-400">
              Fino alla versione 1.10.0-beta.43 il ruolo «Nudo proprietario» non esisteva, e la strada era registrarlo come
              «Proprietario». Le unità già inserite così <strong>continuano a funzionare esattamente come prima</strong> — non
              c'è niente da rifare. Con il ruolo giusto il programma sa chi è usufruttuario e chi nudo proprietario: lo usano le
              voci (qui sotto), i saldi pregressi e i passaggi di proprietà.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Fra loro, l'<strong>usufruttuario</strong> deve le spese <strong>ordinarie</strong> (art. 1004 c.c.) e il <strong>nudo proprietario</strong> le <strong>straordinarie</strong> (art. 1005 c.c.).</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Verso il condominio rispondono <strong>in solido</strong> (art. 67 disp. att. c.c.): il condominio può chiedere a ciascuno dei due.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>L'<strong>inquilino</strong> non ha un diritto sull'unità: gli oneri accessori li deve al locatore, secondo il contratto e la legge (art. 9 L. 392/1978).</span>
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
                <h4 class="font-bold text-blue-900 dark:text-blue-300 mb-2">1. Quota di competenza %</h4>
                <p class="text-blue-800 dark:text-blue-200/80 leading-relaxed text-[13px]">
                  La imposti quando associ l'anagrafica all'immobile. Dice quanta parte dell'unità ha la persona in quel ruolo: due comproprietari al 50 % ciascuno. Non decide quale ruolo paga una voce: quello lo dice il coefficiente.
                </p>
              </div>
              <div class="bg-amber-50 dark:bg-amber-900/20 p-4 rounded-xl border border-amber-100 dark:border-amber-900/50">
                <h4 class="font-bold text-amber-900 dark:text-amber-300 mb-2">2. Coefficiente della voce</h4>
                <p class="text-amber-800 dark:text-amber-200/80 leading-relaxed text-[13px]">
                  Lo imposti associando la voce di spesa alla tabella millesimale. Dice quale ruolo sostiene la spesa: «Usufruttuario 100 %» manda all'usufruttuario la quota dell'unità; sull'unità divisa fra piena proprietà e usufrutto, solo la sua parte. Vale per tutte le unità della tabella. È qui che opera la catena.
                </p>
              </div>
            </div>
          </section>

          <!-- Sezione 3 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Chi paga: la catena</h3>
            <p class="mb-4">
              Se sull'unità il ruolo della voce non c'è, il programma passa al ruolo successivo della catena. Le catene sono tre, una per ruolo che puoi mettere su una voce.
            </p>
            <div class="space-y-4">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Voce sul «Proprietario»</h4>
                <div class="flex flex-wrap items-center gap-2 text-emerald-600 dark:text-emerald-400 font-semibold mb-2 bg-emerald-50 dark:bg-emerald-900/30 w-fit px-3 py-1 rounded-md">
                  <span>Proprietario</span><span>→</span><span>Nudo proprietario</span>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Sull'unità in usufrutto la voce va al nudo proprietario, mai all'usufruttuario o all'inquilino. È l'impostazione per le spese straordinarie.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Voce sull'«Usufruttuario»</h4>
                <div class="flex flex-wrap items-center gap-2 text-indigo-600 dark:text-indigo-400 font-semibold mb-2 bg-indigo-50 dark:bg-indigo-900/30 w-fit px-3 py-1 rounded-md">
                  <span>Usufruttuario</span><span>→</span><span>Proprietario</span><span>→</span><span>Nudo proprietario</span>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Sull'unità in usufrutto la voce va all'usufruttuario; dove non c'è usufrutto, al proprietario, come una voce sul «Proprietario». Mai all'inquilino. È l'impostazione per le spese ordinarie che restano alla proprietà.
                </p>
              </div>

              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Voce sull'«Inquilino»</h4>
                <div class="flex flex-wrap items-center gap-2 text-sky-600 dark:text-sky-400 font-semibold mb-2 bg-sky-50 dark:bg-sky-900/30 w-fit px-3 py-1 rounded-md">
                  <span>Inquilino</span><span>→</span><span>Usufruttuario</span><span>→</span><span>Proprietario</span><span>→</span><span>Nudo proprietario</span>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Su un'unità affittata la voce va all'inquilino, tutta: anche la parte che sarebbe del locatore. Usala solo per gli oneri che il contratto mette a carico di chi è in affitto.
                </p>
              </div>
            </div>

            <div class="mt-4 p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                Un ruolo registrato con quota zero conta come assente. E se un ruolo copre solo una parte dell'anno — un inquilino che lascia l'appartamento a giugno — i giorni scoperti passano al ruolo successivo della catena.
              </div>
            </div>
          </section>

          <!-- Sezione 4 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">L'unità divisa fra piena proprietà e usufrutto</h3>
            <p class="mb-3">
              Capita con una vendita con riserva d'usufrutto su una sola quota, o quando muore uno dei due genitori usufruttuari: Bice è proprietaria piena della sua metà; dell'altra metà Ugo è usufruttuario ed Elsa nuda proprietaria.
            </p>
            <p class="mb-3">
              Il programma divide la spesa dell'unità secondo le quote registrate. La metà di Bice resta di Bice con ogni voce, salvo quella sull'«Inquilino» quando l'unità è affittata: lì paga l'inquilino, tutta. L'altra metà va a chi dice la voce: a Elsa, nuda proprietaria, con «Proprietario»; a Ugo, usufruttuario, con «Usufruttuario». Nei giorni in cui l'unità non ha un inquilino, la voce sull'«Inquilino» va a chi gode l'unità: per metà a Bice, proprietaria piena, e per metà a Ugo, usufruttuario; Elsa, nuda proprietaria, non la paga.
            </p>
            <p class="mb-3">
              Quando finisce uno dei due usufrutti, «Registra passaggio» fa tornare proprietario pieno solo il nudo proprietario di quell'usufrutto: l'altro resta nudo proprietario, sotto l'usufrutto che continua. Se la nuda proprietà è una sola — due genitori donano con riserva al figlio, poi muore uno dei due — la riga del figlio si divide in due: proprietario pieno per la parte su cui l'usufrutto finisce (il 50 %), nudo proprietario del resto (l'altro 50 %). Sono due righe della stessa persona con ruoli diversi, e il riparto le legge ciascuna come sopra: la metà piena con ogni voce, quella nuda a chi dice la voce. Dove i dati non dicono quale nuda proprietà torna piena, il programma chiede di scegliere, o rifiuta e l'estinzione si registra a mano: lo spiega la guida di «Registra passaggio».
            </p>
            <div class="p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                Le quote devono tornare in ogni periodo: Bice 50 % ed Elsa 50 % fanno l'unità intera, e così Bice 50 % e Ugo 50 %. Se non tornano, il programma non indovina: divide ogni ruolo fra le sue sole persone, come prima. Succede, per esempio, dopo un'estinzione registrata prima della 1.11.0-beta.43 con un altro usufrutto ancora in corso: tutti i nudi proprietari tornavano proprietari pieni e la somma superava il 100 %. Correggi le quote nella scheda dell'unità. Fanno eccezione, per ora, i saldi pregressi intestati all'unità quando l'unità è diventata così con un passaggio registrato: si ripartiscono come prima. Controllali nell'anteprima del piano e, se serve, usa il riparto manuale.
              </div>
            </div>
          </section>

          <!-- Sezione 5 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Al passaggio: chi paga l'ordinaria dal giorno dell'atto</h3>
            <p class="mb-3">
              Quando registri la costituzione di un usufrutto, o una vendita con riserva d'usufrutto, il programma chiede chi paga le spese ordinarie dal giorno dell'atto, e propone la legge: <strong>l'usufruttuario</strong> (art. 1004 c.c.).
            </p>
            <ul class="mb-3 space-y-2">
              <li class="flex gap-2">
                <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                <span>Con la legge, il conguaglio delle rate già emesse dà l'ordinaria all'usufruttuario, e le voci ordinarie sul «Proprietario» passano sull'«Usufruttuario», così anche i piani generati o ricalcolati dopo fanno lo stesso. La spesa addebitata direttamente a una sola unità no: non ha una voce da spostare, e un piano la dà al nudo proprietario anche se è ordinaria. Le voci sono elencate tutte spuntate: togli la spunta a quelle che devono restare al nudo proprietario. Non si spostano le voci di una gestione che ha un piano approvato: quelle che il piano elenca hanno la ripartizione bloccata, e un piano senza capitoli le comprende tutte. Se quel piano non si ricalcola più (ha una quota a giornale, un incasso, un credito usato o un rimborso su una sua quota, o è già stato preso nel conguaglio di un passaggio), per quel piano non serve spostarle: l'ordinaria è già dell'usufruttuario anche sulle quote ancora in bozza (nella costituzione gliela dà il conguaglio, nella riserva resta a chi vende e non si conguaglia). Se si ricalcola ancora, la scelta non lo raggiunge: non c'è conguaglio, e il piano generato o ricalcolato dà l'ordinaria di quelle voci al nudo proprietario. Per applicarla, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio, poi riapprovalo e ricalcolalo. Il pannello dice quale caso è, con il nome del piano; una voce bloccata da più piani di casi diversi ha una frase sua, senza rimedio. E le voci create dopo, come quelle delle gestioni che si apriranno, partono dal «Proprietario»: per l'ordinaria mettile su «Usufruttuario».</span>
              </li>
              <li class="flex gap-2">
                <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                <span>Con «come dice ogni voce», le voci restano come sono e il conguaglio le segue una per una: quelle sul «Proprietario» al nudo proprietario, le altre all'usufruttuario.</span>
              </li>
            </ul>
            <p class="mb-3">
              Una voce vale per tutta la tabella: spostarla cambia chi paga anche sulle altre unità in usufrutto, e il pannello le elenca, con l'importo dell'ultimo piano. Dove non c'è usufrutto paga il proprietario, come prima.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Se annulli il passaggio, le voci spostate restano dove sono: l'annullamento lo dice, con i coefficienti che avevano prima, e se vanno riportate com'erano si cambiano dalla pagina della voce. La scelta fatta al passaggio, e le voci spostate, si leggono nello storico del passaggio.
            </p>
          </section>

          <!-- Sezione 6 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Gli accordi fra le parti</h3>
            <p class="mb-3">
              Un accordo fra usufruttuario e nudo proprietario — per esempio che l'usufruttuario paghi anche una parte delle straordinarie — vale fra loro, non verso il condominio. Scrivilo nelle <strong>note interne</strong> della riga del titolare, dalla scheda dell'unità.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Non impostarlo sui coefficienti della voce: una voce vale per tutte le unità della tabella, e l'accordo riguarda una sola unità.
            </p>
          </section>

          <!-- Sezione 7 -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Unità senza anagrafiche (scoperti)</h3>
            <p class="mb-3">
              Se la catena si esaurisce senza trovare nessun soggetto valido a cui addebitare, quella quota <strong>non viene spalmata in silenzio sugli altri condòmini</strong>.
            </p>
            <p class="mb-3">
              Il sistema intercetta le quote come <em>"scoperti"</em> e mostra un riquadro di allerta. Se decidi di procedere forzatamente, l'importo della quota orfana rimane fuori dal totale del riparto, garantendo che gli altri condòmini paghino solo i propri millesimi reali.
            </p>
            <div class="p-4 rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800/50 flex gap-3">
              <AlertTriangle class="w-5 h-5 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-red-800 dark:text-red-200/90 leading-relaxed">
                <strong>Nota operativa:</strong> la motivazione viene salvata sul piano rate ed è visibile nel dettaglio. L'importo scoperto non viene però registrato come quota contabile — non avendo un soggetto a cui intestarlo, il sistema lo documenta ma non lo contabilizza. Se in seguito l'anagrafica viene censita, non servono fatture finte: se il piano si ricalcola ancora, ricalcolalo; altrimenti (rate emesse, un movimento su una quota o il conguaglio di un passaggio: il motivo è sul pulsante «Ricalcola») recupera la quota con un addebito manuale.
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
