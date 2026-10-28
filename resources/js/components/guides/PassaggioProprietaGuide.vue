<script setup lang="ts">
/**
 * Guida di «Registra passaggio»: che cosa fa il programma quando cambia il titolare di un'unità, e
 * che cosa **non** decide al posto dell'amministratore.
 *
 * Testi dal §2 e dal §6 di `docs/pertinenze_vendita_locazione.md` e dalle decisioni 11–20 del
 * progetto sul subentro. Ogni sentenza citata è verificata su fonte (17–18/09/2026): la 9148/2008
 * **non** è il criterio della delibera, la 24654/2010 sì; l'attestazione dell'art. 1130 n. 9 la
 * chiede il condòmino e non ha valore liberatorio (Cass. 7260/2024).
 *
 * Coda 383: sei schede (le basi, la vendita, la locazione, l'usufrutto, la successione, ciò che viene
 * dopo), e nella locazione tre parti (l'inizio, la fine, il prospetto degli oneri accessori); ogni
 * parte apre con un riquadro «In breve», le scelte del modulo stanno in due riquadri affiancati e i
 * casi particolari in sezioni chiuse. Le frasi sono quelle di prima, spostate: schede e sezioni
 * chiuse restano nel DOM (`unmount-on-hide` spento), così la ricerca del browser e i test le leggono
 * tutte.
 */
import { ref, watch } from 'vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import {
  AlertTriangle, ArrowRightLeft, BookOpen, CalendarDays, Circle, Home, Info, KeyRound, KeySquare, Landmark, Printer,
  Scale, ScrollText, ShieldCheck, Undo2, Users,
} from 'lucide-vue-next';

type Scheda = 'basi' | 'vendita' | 'locazione' | 'usufrutto' | 'successione' | 'dopo';
type Parte = 'inizio' | 'fine' | 'prospetto';

const props = defineProps<{ open: boolean; tipo?: string }>();
defineEmits(['update:open']);

// La guida si apre sulla scheda del tipo di passaggio scelto nella pagina; l'inizio e la fine della
// locazione stanno nella stessa scheda, ognuno nella sua parte. Senza tipo, sulle basi.
const iniziale = (): Scheda => {
  if (props.tipo === 'inizio_locazione' || props.tipo === 'fine_locazione') return 'locazione';
  return props.tipo === 'vendita' || props.tipo === 'usufrutto' || props.tipo === 'successione' ? props.tipo : 'basi';
};
const parteIniziale = (): Parte => (props.tipo === 'fine_locazione' ? 'fine' : 'inizio');
const scheda = ref<Scheda>(iniziale());
const parte = ref<Parte>(parteIniziale());
watch(() => props.open, (aperta) => {
  if (aperta) {
    scheda.value = iniziale();
    parte.value = parteIniziale();
  }
});

// I rimandi fra le schede («vedi «Vendita»») cambiano scheda, e parte nella locazione, e riportano in cima.
const cima = ref<HTMLElement | null>(null);
function vaiA(s: Scheda, p?: Parte) {
  scheda.value = s;
  if (p) parte.value = p;
  cima.value?.scrollIntoView?.({ block: 'start' });
}
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div ref="cima" class="px-6 py-8">
        <SheetHeader class="mb-6">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <BookOpen class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registrare un passaggio</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Vendita, donazione, locazione, usufrutto, successione: come il programma conserva la storia dell'unità e cosa mostra prima di scrivere.
          </SheetDescription>
        </SheetHeader>

        <Tabs v-model="scheda" :unmount-on-hide="false" class="w-full">
          <TabsList class="mb-6 grid h-auto w-full grid-cols-3 gap-1 sm:flex">
            <TabsTrigger value="basi" class="px-1.5 text-[13px] sm:flex-auto">Le basi</TabsTrigger>
            <TabsTrigger value="vendita" class="px-1.5 text-[13px] sm:flex-auto">Vendita</TabsTrigger>
            <TabsTrigger value="locazione" class="px-1.5 text-[13px] sm:flex-auto">Locazione</TabsTrigger>
            <TabsTrigger value="usufrutto" class="px-1.5 text-[13px] sm:flex-auto">Usufrutto</TabsTrigger>
            <TabsTrigger value="successione" class="px-1.5 text-[13px] sm:flex-auto">Successione</TabsTrigger>
            <TabsTrigger value="dopo" class="px-1.5 text-[13px] sm:flex-auto">Dopo</TabsTrigger>
          </TabsList>

          <TabsContent value="basi" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── LE BASI: valgono per ogni passaggio ───────────────────────────────── -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
              <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
              <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                <li>«Registra passaggio» chiude chi esce al giorno prima dell'atto e apre chi entra da quella data: si scrive una data sola.</li>
                <li>Le rate già emesse non si toccano: dove serve un conguaglio, si propone come righe di saldo che sommano zero.</li>
                <li>Prima di scrivere, il pannello «Cosa cambierà» mostra che cosa succede, e si aggiorna mentre compili.</li>
                <li>Se il passaggio tocca rate già emesse o cambia il destinatario di un piano già generato, la conferma chiede una spunta e una nota.</li>
              </ul>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <ArrowRightLeft class="h-4 w-4 text-indigo-500" /> Chi esce, chi entra
              </h3>
              <p class="mb-3">
                «Registra passaggio» <strong>chiude</strong> il periodo di chi esce al giorno prima della data dell'atto e <strong>apre</strong> quello di chi entra da quella data. La riga di chi esce non sparisce: resta nello storico dell'unità, con le sue date, perché il riparto e l'estratto conto l'hanno già raccontata.
              </p>
              <p>
                È diverso da «Modifica associazione», che corregge un dato sbagliato sulla stessa persona, e da «Dissocia», che cancella una riga scritta per errore. Una riga con una storia — un periodo chiuso, un passaggio registrato — non si cancella e non si sovrascrive con un'altra persona: il programma lo rifiuta e rimanda qui.
              </p>
            </section>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <CalendarDays class="h-4 w-4 text-indigo-500" /> Da quando
              </h3>
              <p class="mb-3">
                Si scrive <strong>una data sola</strong>, quella dell'atto. Il giorno prima lo calcola il programma: chiedere due date è l'errore che produce buchi e sovrapposizioni. Sotto il campo compare la frase che dice esattamente chi risulterà titolare fino a quando e chi da quando.
              </p>
              <p class="mb-3">
                La data non ha un valore predefinito. «Oggi» è il giorno in cui si compila, quasi mai il giorno del rogito: il programma non tira a indovinare una data che decide chi paga.
              </p>
              <p class="text-[13px] text-slate-500 dark:text-slate-400">
                Per lo straordinario conta la data della delibera che ha approvato lavori e prezzo, non quella del passaggio (Cass. 24654/2010; art. 63 disp. att. c.c.): l'obbligazione è sorta con la delibera, e resta di chi era titolare allora. Per l'ordinario la spesa matura nel tempo e si divide in proporzione ai giorni.
              </p>
            </section>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Scale class="h-4 w-4 text-indigo-500" /> Cosa cambia nei conti
              </h3>
              <p class="mb-3">
                Il pannello «Cosa cambierà» è calcolato dal server su quello che hai scritto e si aggiorna mentre compili; sugli schermi larghi resta in vista accanto al modulo e scorre al suo interno, con il titolo sempre visibile. Quattro blocchi: l'anagrafica, le rate già emesse e il conguaglio, chi resta obbligato, cosa non cambia.
              </p>
              <ul class="list-disc pl-5 space-y-2">
                <li><strong>Le rate già emesse non si toccano.</strong> Sono crediti già iscritti verso persone nominate. Il conguaglio si propone come righe di saldo di segno opposto che sommano zero, una coppia per ogni persona che entra (alla fine di una locazione senza un nuovo inquilino, per ogni persona che paga al posto dell'inquilino): di solito credito a chi esce e debito a chi entra, o viceversa.</li>
                <li><strong>La morosità resta di chi l'ha maturata.</strong> Il conguaglio si calcola sulla competenza, non sui pagamenti.</li>
                <li><strong>Millesimi, tabelle e pertinenze non cambiano.</strong> Il collegamento di una pertinenza è descrittivo e non sposta importi.</li>
              </ul>
            </section>
            <section>
              <h4 class="mb-2 font-semibold text-slate-900 dark:text-slate-100">Il piano si ricalcola ancora?</h4>
              <p class="mb-3">Da questa domanda dipende quasi tutto il resto della guida.</p>
              <div class="mb-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/20">
                  <p class="mb-1 font-semibold text-emerald-900 dark:text-emerald-200">Si ricalcola ancora</p>
                  <p class="text-[13px]">Niente a giornale, nessun movimento (un incasso, un credito usato o un rimborso) e nessun passaggio che l'abbia preso nel suo conguaglio. Il passaggio lo lascia al ricalcolo: ricalcolato, le rate passano da sé, salvo le voci che il passaggio non ha potuto spostare.</p>
                </div>
                <div class="rounded-lg border border-slate-300 bg-slate-50 p-3 dark:border-slate-600 dark:bg-slate-800/50">
                  <p class="mb-1 font-semibold text-slate-900 dark:text-slate-100">Non si ricalcola più</p>
                  <p class="text-[13px]">Ha una quota a giornale, un movimento su una sua quota, o un passaggio non annullato, anche su un'altra unità, l'ha già preso nel suo conguaglio. Il passaggio non lo ricalcola: le rate emesse restano come sono, e il pannello «Cosa cambierà» dice che cosa passa e che cosa no.</p>
                </div>
              </div>
            </section>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <ShieldCheck class="h-4 w-4 text-indigo-500" /> La conferma
              </h3>
              <p>
                Se il passaggio tocca rate già emesse o cambia il destinatario di un piano già generato, la conferma chiede una spunta e una nota di almeno dieci caratteri, che restano registrate con l'operazione. Se non tocca nulla, il pulsante è attivo subito.
              </p>
            </section>
            <div class="flex items-start gap-3 rounded-lg p-4 bg-amber-50 dark:bg-amber-900/20">
              <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
              <div class="space-y-2 text-[13px] text-amber-900 dark:text-amber-200">
                <p>
                  <strong>Il passaggio porta tutta la quota di chi esce.</strong> Se l'atto trasferisce solo una parte della quota, il passaggio non si registra da qui: si registra a mano.
                </p>
              </div>
            </div>
            <div>
              <p class="mb-1 text-[13px] font-semibold text-slate-500 dark:text-slate-400">Casi particolari</p>
              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v1" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il piano che si ricalcola ancora, dopo il passaggio</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se il piano si ricalcola ancora (niente a giornale, nessun movimento e nessun passaggio che l'abbia preso nel suo conguaglio), il passaggio lo lascia al ricalcolo, e ricalcolato le rate passano da sé, salvo le voci che il passaggio non ha potuto spostare (vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('vendita')">«Vendita»</button>, «Con riserva d'usufrutto»). Dopo una vendita o un usufrutto il programma chiede di ricalcolarlo prima di emettere, di registrare un incasso o un rimborso; lo stesso se il passaggio è stato annullato dopo il ricalcolo, perché le quote avrebbero ancora la titolarità di un passaggio che non c'è più (vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('dopo')">«Dopo»</button>, «Ho registrato un passaggio sbagliato»); dopo un cambio d'inquilino no, e un piano emesso senza ricalcolo lascia le quote all'inquilino di prima.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v2" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le quote che il passaggio non tocca</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Le quote che restano a chi le ha — per legge o per la scelta fatta nel modulo, come l'ordinaria che resta all'usufruttuario e la straordinaria che resta al nudo proprietario, o perché a chi entra non ne va nessuna parte, come quelle di soli saldi pregressi o di una spesa tutta di chi vende — il modulo le elenca sopra il pulsante, senza chiedere la spunta: il passaggio non le tocca.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v3" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Solo una parte della quota: come si registra a mano</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Chiudi la riga di chi vende al giorno prima dell'atto da «Modifica associazione», poi da «Associa soggetto» riapri chi vende alla quota che tiene e apri chi compra alla sua, tutte e due dal giorno dell'atto. Non c'è conguaglio automatico. Con le date attaccate il riparto conta le righe nuove dal giorno dell'atto; con un buco fra le date valgono da sempre, e un piano generato o ricalcolato dopo le addebita anche per i mesi prima dell'atto.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v4" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il conguaglio segue ogni quota lungo i passaggi</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Quando sulla stessa unità i passaggi si susseguono — chi vende e poi ricompra, chi compra e rivende, un usufrutto che finisce e una nuda proprietà che passa di mano — il programma non guarda il ruolo che una persona ha oggi, ma da quale quota viene ogni rata già emessa e dove quella quota è arrivata. Chi entra paga solo i giorni della quota che gli arriva, anche se chi esce ha avuto o tiene altre quote sulla stessa unità; le rate di una parte che chi esce ha già ceduto con un passaggio precedente, o che non gli è mai arrivata, restano a chi le ha ricevute, e il pannello lo dice.
                    </p>
                    <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
                      <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                      <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                        <p>
                          Un esempio, su una spesa di €&nbsp;1.200,00 l'anno: Ugo vende a Bice il 1° marzo, Bice rivende a Ugo il 1° maggio, Ugo vende a Carlo il 1° luglio, con le rate emesse fra un passaggio e l'altro. Il conguaglio di ogni passaggio si calcola, e sommando rate e conguagli ognuno paga i suoi giorni da titolare: Ugo €&nbsp;394,52 (120 giorni), Bice €&nbsp;200,55 (61 giorni), Carlo €&nbsp;604,93 (184 giorni).
                        </p>
                        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                          <table class="w-full text-[12px] tabular-nums">
                            <thead class="bg-slate-50 text-left text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                              <tr>
                                <th class="px-3 py-2 font-semibold">Chi</th>
                                <th class="px-3 py-2 font-semibold">Giorni da titolare</th>
                                <th class="px-3 py-2 font-semibold">Rate e conguagli</th>
                              </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                              <tr>
                                <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">Ugo</td>
                                <td class="px-3 py-2 align-top ">120</td>
                                <td class="px-3 py-2 align-top ">€&nbsp;394,52</td>
                              </tr>
                              <tr>
                                <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">Bice</td>
                                <td class="px-3 py-2 align-top ">61</td>
                                <td class="px-3 py-2 align-top ">€&nbsp;200,55</td>
                              </tr>
                              <tr>
                                <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">Carlo</td>
                                <td class="px-3 py-2 align-top ">184</td>
                                <td class="px-3 py-2 align-top ">€&nbsp;604,93</td>
                              </tr>
                              <tr>
                                <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">Insieme</td>
                                <td class="px-3 py-2 align-top ">365</td>
                                <td class="px-3 py-2 align-top ">€&nbsp;1.200,00</td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v5" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando il conguaglio si ferma</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se non sa separare con certezza la parte che passa, il conguaglio non propone cifre e lo dice nel pannello («le quote sono passate per più strade»), con la ragione e il passaggio in causa: dove il dato manca il programma non tira a indovinare. Succede:
                    </p>
                    <ul class="list-disc pl-5 space-y-1.5">
                      <li>quando, in una catena di passaggi, il piano non dice da quale riga di titolarità viene una quota, o la riga è stata cambiata a mano dopo la generazione, senza un passaggio che dica come. I piani generati o ricalcolati prima della 1.11.0-beta.43 non portano il dato: il programma lo ricava dove la riga è una sola, e si ferma dove ce n'è più d'una (chi ha venduto e ricomprato prima della generazione). Un piano senza dettaglio del riparto si ferma anche su un'unità mista la cui titolarità è cambiata dopo la generazione;</li>
                      <li>quando un passaggio registrato prima della 1.11.0-beta.43 ha preso il piano senza scrivere nessuna coppia di conguaglio su questa unità: con le versioni precedenti il conguaglio poteva essersi fermato e le parti averlo regolato a mano, e il programma non può saperlo. Dove la quota attraversa quel passaggio cambiando persona e la catena è ambigua, il conguaglio si ferma e dice quale passaggio; un passaggio che ha scritto la sua coppia sull'unità non ferma niente;</li>
                      <li>quando una nuda proprietà è nata da una somma dopo la generazione del piano ed è tornata piena solo in parte: chi era già nudo proprietario compra un'altra nuda, o riceve una riserva d'usufrutto, a piano già generato; poi un'estinzione fa tornare piena una parte soltanto, e dopo c'è un altro passaggio. Le rate emesse stanno sulla nuda sommata, e il programma non sa quali sono della parte tornata piena e quali di quella rimasta nuda: se una quota di una persona arriva su quella nuda proprietà, si ferma il conguaglio di tutto il gruppo di quote di quella persona sul piano e sull'unità, anche quando la persona è chi esce. Non si ferma ciò che la legge del passaggio esclude per intero: l'ordinaria nella vendita con riserva d'usufrutto, se si è scelta la legge, e la straordinaria nella costituzione e nell'estinzione dell'usufrutto. Se la somma è precedente alla generazione del piano — il caso comune dei genitori che hanno donato con riserva anni prima — il conguaglio si calcola;</li>
                      <li>con le quote della 1.7.x, quando il piano ha assorbito un saldo pregresso.</li>
                    </ul>
                    <p>
                      In ognuno di questi casi, se serve un conguaglio, si scrive dal Wallet con saldi legati al passaggio, sulla stessa gestione: non occupano il posto di un riporto e si tolgono se annulli il passaggio.
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
          </TabsContent>

          <TabsContent value="vendita" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── VENDITA O DONAZIONE ──────────────────────────────────────────────── -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
              <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
              <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                <li>Vendita o donazione: chi compra o riceve è titolare dal giorno dell'atto, chi vende o dona fino al giorno prima.</li>
                <li>Se il piano non si ricalcola più, le rate in bozza che scadono dal giorno dell'atto passano a chi compra, salvo i casi detti qui sotto, e il conguaglio sistema il resto; se si ricalcola ancora, va ricalcolato prima di emettere.</li>
                <li>Chi compra risponde in solido per l'esercizio in corso e per quello precedente; chi vende resta obbligato anche per i contributi maturati dopo, finché il condominio non riceve copia autentica del titolo.</li>
                <li>Con la riserva d'usufrutto chi vende resta usufruttuario, e il modulo chiede chi paga l'ordinaria dal giorno dell'atto.</li>
              </ul>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <CalendarDays class="h-4 w-4 text-indigo-500" /> Le rate in bozza
              </h3>
              <p class="mb-3">
                <strong>Nella vendita le rate in bozza passano a chi entra.</strong> Se il piano ha già una quota a giornale, o un incasso, un credito usato o un rimborso su una sua quota, o se un passaggio non annullato, anche su un'altra unità, l'ha già preso nel suo conguaglio, non si ricalcola più, e il passaggio lo prende nel suo conguaglio: le rate in bozza che scadono dal giorno del passaggio passano a chi entra — cambia l'intestatario, non l'importo — e il conguaglio è la sua parte dell'intero piano meno quelle rate (se le rate coprono più dei suoi giorni, si rovescia).
              </p>
              <p class="mb-3">
                Restano a chi esce, e si conguagliano, le bozze già scadute o pagate e quelle di una straordinaria che è sua; il saldo pregresso dentro una bozza resta suo in una quota a parte.
              </p>
              <p>
                Il pannello dice quali rate passano e quali no, e perché.
              </p>
            </section>
            <div>

              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v6" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi vende tiene un'altra parte dell'unità</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se chi vende tiene un'altra parte dell'unità — per esempio è proprietario pieno di metà e usufruttuario dell'altra metà, e vende la metà piena — passa solo la spesa della quota che esce: le bozze, che comprendono anche la parte che resta, restano a chi vende, e la parte che passa si conguaglia.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v7" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le rate le paga chi vende, per accordo fra le parti</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se invece il piano si ricalcola ancora e le parti si sono accordate che le sue rate le paga chi vende, il piano si ricalcola comunque per giorni: verso il condominio le rate dal giorno dell'atto sono di chi compra, e i pagamenti di chi vende si registrano con «Versato da», che resta scritto su entrambi gli estratti conto.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v8" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">La vendita della nuda proprietà</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Nella vendita della nuda proprietà l'ordinaria dei piani generati prima dell'usufrutto è dell'usufruttuario (art. 1004 c.c.) e non passa a chi compra la nuda proprietà, salvo le voci che sono del nudo proprietario perché alla costituzione o alla riserva hai scelto «come dice ogni voce».
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <ShieldCheck class="h-4 w-4 text-indigo-500" /> Chi resta obbligato
              </h3>
              <p class="mb-3">
                Chi acquista risponde in solido con chi vende per i contributi dell'esercizio in corso e di quello precedente (art. 63 co. 4 disp. att. c.c.). Il programma <strong>non intesta nulla</strong> all'acquirente per questo, salvo i saldi intestati all'unità, che si addebitano quando si genera il piano: la nota resta nel pannello e nella situazione debitoria dell'unità. Chi paga in forza della solidarietà ha regresso verso il venditore per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021).
              </p>
              <div class="flex items-start gap-3 rounded-lg p-4 border border-indigo-200 bg-white dark:border-indigo-900/60 dark:bg-slate-900">
                <ShieldCheck class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
                <div class="space-y-2 text-[13px] text-slate-700 dark:text-slate-300">
                  <p>
                    Finché il condominio non riceve <strong>copia autentica del titolo</strong>, chi vende resta obbligato per i contributi maturati dopo (art. 63 co. 5). La casella «Ho ricevuto copia autentica del titolo», con la sua data, è l'unico campo del modulo con un effetto giuridico diretto. Registra pure il passaggio anche senza: l'anagrafe si aggiorna sulla comunicazione scritta del condòmino (art. 1130 n. 6 c.c.).
                  </p>
                </div>
              </div>
            </section>
            <div>

              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v9" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">La cifra «non pagati» e il conguaglio</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      La cifra «non pagati» è al netto della coppia del conguaglio, che sta nella stessa frase: se la coppia ha già messo a debito di chi compra una parte di quelle rate, la nota non la conta due volte (con la coppia assorbita da un secondo piano la nota torna alla cifra lorda, che non sottostima mai il debito).
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v10" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">L'attestazione dello stato dei pagamenti</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      L'attestazione dello stato dei pagamenti e delle liti in corso (art. 1130 n. 9 c.c.) la chiede il condòmino che vende; il notaio la pretende nella prassi, ma non ha valore liberatorio verso il condominio (Cass. 7260/2024).
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Landmark class="h-4 w-4 text-indigo-500" /> Con riserva d'usufrutto
              </h3>
              <p class="mb-3">
                <strong>Nella vendita o donazione con riserva d'usufrutto chi vende resta usufruttuario</strong>, sulla stessa quota, e il modulo chiede chi paga l'ordinaria dal giorno dell'atto. Si dichiara spuntando «Chi vende o dona resta usufruttuario»: il programma non la deduce dal ruolo scelto.
              </p>
              <div class="space-y-2">
                <p class="font-semibold text-slate-900 dark:text-slate-100">Chi paga l'ordinaria dal giorno dell'atto</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="mb-1.5 flex items-start gap-2">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span class="flex flex-col"><span class="font-semibold text-slate-900 dark:text-slate-100">All'usufruttuario</span><span class="text-[11px] font-medium text-indigo-700 dark:text-indigo-300">Proposta di legge, art. 1004 c.c.</span></span>
                    </p>
                    <div class="space-y-2 text-[13px]">
                      <p>
                        La proposta è la legge: l'usufruttuario (art. 1004 c.c.). Allora nelle rate già emesse e nelle bozze dei piani che hanno già emesso l'ordinaria resta a chi vende e non si conguaglia, e le voci ordinarie sul «Proprietario» — anche quelle senza coefficienti — passano sull'«Usufruttuario», così un piano generato o ricalcolato dopo l'atto fa lo stesso, salvo la spesa addebitata direttamente all'unità, che non ha una voce e va al nudo proprietario.
                      </p>
                      <p>
                        Le voci sono elencate tutte spuntate: togli la spunta a quelle che devono andare al nudo proprietario.
                      </p>
                    </div>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="mb-1.5 flex items-start gap-2">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span class="flex flex-col"><span class="font-semibold text-slate-900 dark:text-slate-100">Come dice ogni voce</span></span>
                    </p>
                    <div class="space-y-2 text-[13px]">
                      <p>
                        Con «come dice ogni voce» le voci non si toccano e il conguaglio le segue una per una: le voci sul «Proprietario», e l'addebito diretto all'unità, passano a chi compra la nuda proprietà per i giorni dopo l'atto.
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </section>
            <div>
              <p class="mb-1 text-[13px] font-semibold text-slate-500 dark:text-slate-400">Casi particolari della riserva</p>
              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v11" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le voci di entrata</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Fra le voci ci sono anche le entrate, con l'etichetta «Entrata»: per un affitto o un canone la ragione è l'art. 984 c.c., non l'art. 1004, e se il titolo dell'usufrutto dice altro vale fra le parti (guida «Ruoli e usufrutto»).
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v12" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Un piano approvato blocca le voci</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Non si spostano le voci di una gestione che ha un piano approvato: quelle che il piano elenca hanno la ripartizione bloccata, e un piano senza capitoli le comprende tutte. Se quel piano non si ricalcola più (ha una quota a giornale, un incasso, un credito usato o un rimborso su una sua quota, o è già stato preso nel conguaglio di un passaggio), per quel piano non serve spostarle: l'ordinaria è già dell'usufruttuario anche sulle quote ancora in bozza (nella costituzione gliela dà il conguaglio, nella riserva resta a chi vende e non si conguaglia). Se si ricalcola ancora, la scelta non lo raggiunge: non c'è conguaglio, e il piano generato o ricalcolato dà l'ordinaria di quelle voci al nudo proprietario. Per applicarla, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio, poi riapprovalo e ricalcolalo. Il pannello dice quale caso è, con il nome del piano; una voce bloccata da più piani di casi diversi ha una frase sua, senza rimedio.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v13" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le voci nuove, lo storico e le altre unità</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Le voci create dopo partono dal «Proprietario». La scelta resta scritta nello storico del passaggio. Una voce vale per tutta la tabella, e il modulo elenca le altre unità in usufrutto che lo spostamento tocca; dove non c'è usufrutto paga il proprietario, come prima.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v14" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi vende ha solo una quota</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se chi vende ha solo una quota e sull'unità resta un altro proprietario pieno, il programma divide l'unità secondo le quote registrate (guida «Ruoli e usufrutto»); i saldi pregressi intestati all'unità, per ora, no.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v15" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le straordinarie</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Le straordinarie seguono la competenza — la data della delibera, o quella dichiarata sulla fattura — come in ogni vendita, e dal giorno dell'atto sono del nudo proprietario (art. 1005 c.c.).
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v16" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi resta obbligato con la riserva</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Nella <strong>vendita o donazione con riserva d'usufrutto</strong>, dal giorno dell'atto nudo proprietario e usufruttuario rispondono in solido verso il condominio (art. 67 ult. co. disp. att. c.c.), e la copia autentica non libera chi vende, che resta usufruttuario. Se chi compra la nuda proprietà risponda anche dell'arretrato di chi vende (art. 63 co. 4) la giurisprudenza non l'ha chiarito: decide l'amministratore.
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
              <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
              <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                <p>Sull'unità c'è stata una fine locazione con «Scrivi il conguaglio»? La vendita o la donazione può fermarsi sulla parte dei giorni dopo l'uscita, tranne con la riserva d'usufrutto: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('locazione', 'fine')">«Locazione»</button>, «Fine», «Le fermate». Più passaggi sulla stessa unità, o un conguaglio che non propone cifre: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('basi')">«Le basi»</button>.</p>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="locazione" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── LOCAZIONE: l'inizio, la fine e il prospetto, in tre parti ──────── -->
            <Tabs v-model="parte" :unmount-on-hide="false" class="w-full">
              <TabsList class="mb-5 grid h-auto w-full grid-cols-3 gap-1 bg-indigo-50/70 dark:bg-indigo-950/30">
                <TabsTrigger value="inizio" class="text-[13px]">Inizio</TabsTrigger>
                <TabsTrigger value="fine" class="text-[13px]">Fine</TabsTrigger>
                <TabsTrigger value="prospetto" class="text-[13px]">Prospetto</TabsTrigger>
              </TabsList>

              <TabsContent value="inizio" class="space-y-6">
                <!-- ── INIZIO LOCAZIONE ─────────────────────────────────────────────────── -->
                <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                  <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
                  <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                    <li>La locazione si aggiunge: il proprietario resta, e verso il condominio risponde lui.</li>
                    <li>Si spuntano le pertinenze che il contratto comprende.</li>
                    <li>Su un piano che non si ricalcola più, la parte del nuovo inquilino resta nelle quote di chi le paga, e il nuovo inquilino la rimborsa con il prospetto degli oneri accessori.</li>
                    <li>La somma delle quote per inquilino non può superare 100.</li>
                  </ul>
                </div>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <KeyRound class="h-4 w-4 text-indigo-500" /> Chi entra
                  </h3>
                  <p class="mb-3">
                    La locazione <strong>si aggiunge</strong>, non sostituisce: il proprietario resta, l'inquilino entra con il suo ruolo. Verso il condominio continua a rispondere il proprietario; all'inquilino vengono addebitate solo le voci il cui coefficiente lo indica.
                  </p>
                  <p class="mb-3">
                    All'inizio di una locazione la parte del nuovo inquilino dal giorno del contratto resta nelle quote di chi le paga, e il nuovo inquilino la rimborsa con il prospetto degli oneri accessori: il pannello lo dice piano per piano, e unità per unità quando le pertinenze hanno storie diverse.
                  </p>
                  <p>
                    Nei piani generati o ricalcolati dopo il passaggio le voci a carico dell'inquilino vanno a chi le riceve nel riparto, come dice la voce sull'«Inquilino» (guida «Ruoli e usufrutto»), e il pannello lo nomina.
                  </p>
                </section>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <Home class="h-4 w-4 text-indigo-500" /> Le pertinenze
                  </h3>
                  <p>
                    Nella fine e nell'inizio di una locazione c'è anche la scheda delle pertinenze: si spuntano quelle che il contratto comprende (conta il contratto, non il titolo di proprietà), e la data vale insieme per l'unità e per quelle spuntate.
                  </p>
                </section>
                <div class="flex items-start gap-3 rounded-lg p-4 bg-amber-50 dark:bg-amber-900/20">
                  <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                  <div class="space-y-2 text-[13px] text-amber-900 dark:text-amber-200">
                    <p>
                      All'inizio di una locazione la somma delle quote per inquilino sull'unità, e sul box, non può superare 100: un inizio che la supererebbe si rifiuta già nell'anteprima, con lo stesso testo e sullo stesso campo della registrazione.
                    </p>
                  </div>
                </div>
                <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
                  <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                  <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                    <p>Se sull'unità c'è una fermata dopo una fine con «Scrivi il conguaglio», i giorni del nuovo conduttore ne escono: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('locazione', 'fine')">«Fine»</button>, «Le fermate».</p>
                  </div>
                </div>
              </TabsContent>

              <TabsContent value="fine" class="space-y-6">
                <!-- ── FINE LOCAZIONE ───────────────────────────────────────────────────── -->
                <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                  <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
                  <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                    <li>Chi entra al posto di chi esce si sceglie, o si spunta «Nessuno».</li>
                    <li>Su un piano che non si ricalcola più, il pannello «Cosa cambierà» chiede se scrivere il conguaglio dei giorni dopo l'uscita: niente è già scelto.</li>
                    <li>Dove la locazione continua con un coinquilino vale la regola del piano, per le fini registrate dalla 1.11.0-beta.55.</li>
                    <li>Dopo «Scrivi il conguaglio», un passaggio registrato dopo può fermarsi sulla parte dei giorni dopo l'uscita.</li>
                  </ul>
                </div>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <KeySquare class="h-4 w-4 text-indigo-500" /> Chi entra al posto di chi esce
                  </h3>
                  <p class="text-[13px] text-slate-500 dark:text-slate-400">
                    Nel modulo la spunta «Nessuno» dice chi resta («Nessuno — resta inquilino Dora»), al posto di «l'unità resta sfitta».
                  </p>
                </section>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <Scale class="h-4 w-4 text-indigo-500" /> La scelta del conguaglio
                  </h3>
                  <p class="mb-3">
                    <strong>Nella locazione e nell'usufrutto le bozze restano a chi esce</strong> e il conguaglio le comprende (alla fine di una locazione il conguaglio si sceglie: qui sotto).
                  </p>
                  <p class="mb-3">
                    <strong>La locazione su un piano che non si ricalcola più.</strong> Le quote del piano restano a chi le ha, emesse o no. Alla fine di una locazione, con un nuovo inquilino o senza, il pannello «Cosa cambierà» chiede se scrivere il conguaglio, senza niente di già scelto: le due voci prendono il posto della casella «Le parti hanno regolato il conguaglio fra loro», la scelta è una sola per l'appartamento e le pertinenze spuntate, e finché manca «Registra passaggio» resta spento.
                  </p>
                  <div class="space-y-2">

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                        <p class="flex items-start gap-2 text-[13px]">
                          <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                          <span><strong>«Scrivi il conguaglio»</strong>: i giorni dopo l'uscita passano a chi entra o, senza un nuovo inquilino, a chi paga al posto dell'inquilino, con righe di saldo che entrano nel prossimo piano della stessa gestione; dove la locazione continua con un coinquilino la parte dell'inquilino si ridivide con la regola del piano (qui sotto).</span>
                        </p>
                      </div>
                      <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                        <p class="flex items-start gap-2 text-[13px]">
                          <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                          <span><strong>«Non scriverlo»</strong>: nessuna riga in saldi, i giorni dopo l'uscita restano nelle rate di chi esce, e il prospetto degli oneri accessori dice chi glieli rimborsa e quanto; la nota è facoltativa, e non dichiara un accordo fra le parti.</span>
                        </p>
                      </div>
                    </div>
                  </div>
                  <div class="mt-3 space-y-3">
                    <p>
                      Se cambia chi esce, chi entra o chi riceve la coppia, la scelta si azzera e va rifatta.
                    </p>
                    <p class="text-[13px] text-slate-500 dark:text-slate-400">
                      Le pertinenze si spuntano come all'inizio: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('locazione', 'inizio')">«Inizio»</button>. Il conguaglio scritto si annulla dallo storico: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('dopo')">«Dopo»</button>.
                    </p>
                  </div>
                </section>
                <div>

                  <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                    <AccordionItem value="v17" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Giorni in cui nessuno sta al posto dell'inquilino</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Nei giorni dopo l'uscita in cui nessuno sta al posto dell'inquilino, per esempio su un box senza proprietario o in un periodo senza titolare, la coppia non nasce nemmeno con «Scrivi»: quella parte resta nelle rate di chi esce, e il pannello lo dice.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v18" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il cambio d'inquilino con «Non scriverlo», dove il prospetto non arriva</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Nel cambio d'inquilino, con «Non scriverlo», il rimborso di chi entra a chi esce si regola invece fra le parti, e il pannello lo dice, in due casi in cui il prospetto non arriva: su un piano generato da una versione precedente, senza riparto registrato, che il prospetto lascia fuori, e dopo un cambio d'inquilino che le parti avevano già regolato fra loro, oltre il quale il prospetto non va.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                  </Accordion>
                </div>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <Users class="h-4 w-4 text-indigo-500" /> Il coinquilino che resta
                  </h3>
                  <p>
                    <strong>Dove la locazione continua con un coinquilino</strong> (esce uno dei conduttori e l'altro resta) il conguaglio si sceglie come nelle altre fini, e la regola è quella del piano: con «Scrivi il conguaglio» la parte dell'inquilino si ridivide per quota e per giorni fra chi è stato conduttore nell'anno, come in un piano generato dopo l'uscita, e le righe in saldi portano ognuno alla sua parte, salvo chi era già uscito e chi non ha quote del piano né righe, la cui parte il pannello dice con la cifra (di norma chi esce ha un credito e chi resta un debito; chi paga al posto dell'inquilino ha la parte dei giorni che nessun conduttore copre). Con «Non scriverlo» le rate restano come sono, e il prospetto degli oneri accessori dice chi rimborsa chi.
                  </p>
                </section>
                <div>

                  <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                    <AccordionItem value="v19" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi non ha quote del piano né righe in saldi</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Un conduttore entrato con l'inizio di una locazione, o con un cambio d'inquilino e «Non scriverlo», su un piano che non si ricalcola più non ha quote del piano né righe in saldi: la sua parte la regola con il prospetto; chi è entrato con un cambio e «Scrivi il conguaglio» ha le righe del cambio, e i passaggi dopo scrivono anche le sue.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v20" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">I passaggi dopo una fine così</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Dopo una fine così, con l'una o l'altra scelta, i passaggi sulla stessa unità seguono la stessa regola, e ognuno dice la sua differenza del piano: la fine di un altro conduttore scrive la differenza di ognuno, come la prima fine, quando tutti possono avere una riga in saldi; quando qualcuno era già uscito (di norma chi è uscito con la fine di prima), o chi riceve i giorni non ha quote del piano, scrive solo la sua coppia, verso chi riceve i suoi giorni, e la parte che la regola del piano cambierebbe agli altri la dice il pannello, con la persona e la cifra, e chiede la spunta; la strada a mano sono i saldi dal Wallet legati al passaggio (se una di quelle persone non ha né quote del piano né righe in saldi è un'informazione, e si regola con il prospetto). Il cambio d'inquilino dà a chi entra la differenza di chi esce. L'inizio di un nuovo conduttore, e la fine di un conduttore senza quote del piano né righe in saldi (entrato con un inizio, o con un cambio e «Non scriverlo»), non scrivono niente: il pannello dice le cifre del piano, e il prospetto chi rimborsa chi.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v21" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le fini registrate prima della 1.11.0-beta.55, e dove non resta nessun coinquilino</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Una fine con un coinquilino che resta registrata prima della 1.11.0-beta.55, senza la scelta, resta com'era. Se è una fine con la fermata del coinquilino (dalla 1.11.0-beta.50 alla 1.11.0-beta.54) e dopo c'è la fine di un altro conduttore con un coinquilino che resta, il pannello dice la regola del piano a partire dalle rate di allora e che cosa ne resterebbe fra le parti, con le persone e le cifre, ma nei saldi non scrive niente, neanche la coppia di chi esce, e il prospetto degli oneri accessori resta alla regola dei giorni: per avere la regola del piano anche per la fine di prima si annulla dallo storico dell'unità la fine di prima e la si registra di nuovo, prima di quella di dopo (se quella di dopo c'è già, si annulla prima lei: lo storico lo dice); altrimenti la differenza la regolano le parti. Dove non resta nessun coinquilino i passaggi dopo seguono la regola dei giorni.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                  </Accordion>
                </div>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <AlertTriangle class="h-4 w-4 text-indigo-500" /> Le fermate
                  </h3>
                  <p>
                    Dopo una fine con «Scrivi il conguaglio», un passaggio che cambia chi paga al posto dell'inquilino, o la fine di un conduttore che ne libera i giorni, si ferma su quella parte. Succede con una vendita o una donazione (anche se l'atto è datato prima della fine e registrato dopo), con la costituzione o l'estinzione di un usufrutto e con una successione, se ne scrivi il conguaglio e l'arretrato resta a nome del defunto; non succede con la riserva d'usufrutto né con «Non scriverlo». La parte dei giorni dopo l'uscita che la coppia aveva dato a chi vende non passa da sola a chi compra: il programma registra il passaggio e non la sposta, lo dice nel pannello con la cifra e le persone, e chiede la spunta; lo storico del passaggio lo ricorda.
                  </p>
                </section>
                <div>

                  <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                    <AccordionItem value="v22" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando finisce la locazione di un conduttore entrato dopo</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Lo stesso vale quando finisce la locazione di un conduttore entrato dopo, anche se è la stessa persona uscita e poi rientrata: i giorni che copriva tornano a chi paga al posto dell'inquilino da quel giorno, e se questo non è più chi ha preso la coppia (ha venduto, l'usufrutto è passato, il proprietario è morto) la parte non si sposta da sola: il pannello dice la cifra e le persone e chiede la spunta, e lo storico della fine lo ricorda. Se per quei giorni c'era già una fermata, il pannello dice da quanto a quanto sale la parte da regolare (per esempio da €&nbsp;30,58 a €&nbsp;61,15 con la fine di Luca). Conta solo il periodo in cui nessun conduttore era registrato: i giorni di un conduttore li rimborsa lui, con il prospetto.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v23" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">La strada a mano: il Wallet</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          La strada a mano è il Wallet: dal modale «Aggiungi credito» o «Aggiungi debito» si scelgono il passaggio, la persona e la cifra, con righe di segno opposto, una per persona. Un saldo legato a un passaggio non occupa il posto (il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio), quindi si registra anche accanto a un riporto.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v24" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Se chi ha ricevuto quella parte vende a sua volta</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Se chi ha ricevuto quella parte vende a sua volta, la seconda vendita dice la sua parte come la prima, ma senza il Wallet: si regola fra le parti, perché il programma non sa come è stata regolata la prima; e lo storico della prima dice quanto è passato quando la parte rivenduta viene da quella vendita sola, non quando chi rivende ha comprato da più persone o ha nella stessa riga anche una parte avuta con la successione: lì la rivendita dice a carico di chi resta, tutte insieme, e il programma non divide la cifra fra loro, salvo che a comprare sia una delle persone a carico e la parte di ognuna si ricavi dai passaggi registrati: allora la frase dice la cifra per persona, perché una parte dei giorni sta già nella riga di chi compra. Se a comprare è l'unica persona a carico di quei giorni (chi ricompra la sua stessa parte, o il nudo proprietario quando si estingue l'usufrutto che aveva costituito), non nasce una fermata: il passaggio dice quanto resta della fermata di prima, con la spunta se ne resta una parte, e lo storico della prima lo ricorda.
                        </p>
                        <p>
                          Se chi vende ha anche una sua riga della coppia e la riga che vende ha una quota che una fermata gli aveva lasciato nella riga di un altro (la nuda comprata e poi tornata piena con l'estinzione dell'usufrutto), la parte di chi compra si divide: quella della sua riga è a suo carico, con il Wallet; quella della quota resta a carico di chi era a carico di quella fermata, o di chi vende se è già stata regolata, senza il Wallet. Se chi rivende è l'unico erede di chi gli aveva venduto, e nella catena della sua riga non c'è la fermata di un altro passaggio (l'estinzione di un usufrutto che aveva fatto tornare pieno chi gli aveva venduto, una quota che questi aveva comprato da un'altra persona), la seconda vendita è la sua, con il Wallet, come la fermata dell'erede che non ha comprato niente, e lo storico della prima non dice quanto è passato; se c'è, resta a carico anche di chi aveva lasciato quella fermata, senza il Wallet. Se gli eredi sono più d'uno, resta a carico di tutti loro insieme, o di chi rivende se la fermata della prima vendita è già stata regolata, senza il Wallet. Dove la riga di chi vende è stata aperta a mano («Associa soggetto», «Modifica associazione») senza un passaggio registrato prima, nessun passaggio dice nella riga di chi stanno i suoi giorni: se più persone hanno la coppia, la frase non ne nomina nessuna e dice che il programma non lo sa, anche quando a comprare è una di loro e non nasce nessuna fermata; l'erede che vende la parte avuta con la successione la tiene come sua, e dove un passaggio c'è la frase legge quello.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v25" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">La successione di chi aveva ricevuto</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Lo stesso con la successione di chi aveva ricevuto, quando l'erede è l'unica persona a carico: con l'arretrato a nome del defunto e «Scrivi il conguaglio» restano da regolare con chi è morto i suoi giorni fino al decesso; con l'arretrato agli eredi la sua posizione passa all'erede, e non resta niente da regolare; con l'arretrato a nome del defunto e «Non scriverlo» la successione non sposta niente della sua posizione, e la fermata di prima non si riduce.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v26" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando comincia una nuova locazione</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Quando poi comincia una nuova locazione, i giorni del nuovo conduttore escono dalla fermata e il pannello dice quanto resta da regolare; se rientra la stessa persona è solo un'informazione, chi rientra rimborsa con il prospetto, e in un rinnovo il pannello ricorda che si può annullare la fine e registrarla di nuovo con «Non scriverlo». Se il nuovo conduttore entra lo stesso giorno in cui finisce quello che aveva fermato la parte (un cambio d'inquilino in due passi), l'inizio azzera la fermata: il pannello lo dice come informazione, senza una seconda spunta, e ricorda di correggere i saldi legati alla fermata, se li avevi registrati.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v27" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Più fermate sulla stessa persona</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Quando più passaggi si fermano sulla stessa persona (due comproprietari che vendono alla stessa compratrice), il residuo da regolare è uno solo per persona, e la frase nomina le fermate; se a carico ci sono più persone, il residuo è uno per tutte insieme e la parte di ognuna la decide l'amministratore.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v28" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Annullare un inizio o una fine che ha cambiato una fermata</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Annullare un inizio che aveva ridotto una fermata, o la fine di un conduttore che l'aveva fatta risalire, avvisa che la parte torna com'era e che un saldo o un accordo adeguati nel frattempo vanno rivisti.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                  </Accordion>
                </div>
              </TabsContent>

              <TabsContent value="prospetto" class="space-y-6">
                <!-- ── PROSPETTO DEGLI ONERI ACCESSORI ──────────────────────────────────── -->
                <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                  <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
                  <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                    <li>Dalla pagina dei titolari, il pulsante con la stampante: per ogni conduttore le voci a carico dell'inquilino, divise per giorni di conduzione.</li>
                    <li>Accanto a ogni voce dice come è pagata, e chi rimborsa chi.</li>
                    <li>È sul preventivo; il conguaglio definitivo si fa sul rendiconto.</li>
                  </ul>
                </div>
                <section>
                  <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                    <Printer class="h-4 w-4 text-indigo-500" /> Il prospetto degli oneri accessori
                  </h3>
                  <p class="mb-3">Le diciture principali della stampa, e quando compaiono:</p>
                  <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-[12px] table-fixed [&_td:first-child]:w-[45%]">
                      <thead class="bg-slate-50 text-left text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                        <tr>
                          <th class="px-3 py-2 font-semibold">La stampa dice</th>
                          <th class="px-3 py-2 font-semibold">Quando</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr><td colspan="2" class="bg-slate-50/60 px-3 py-1.5 text-[11px] font-semibold text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">Nella colonna «Come», accanto a ogni voce</td></tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«nelle sue rate»</td>
                          <td class="px-3 py-2 align-top ">la voce è già intestata a lui.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«pagata da …»</td>
                          <td class="px-3 py-2 align-top ">l'ha pagata un titolare perché in quei giorni nessun inquilino era registrato: è la parte da rimborsare.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«pagata da … con il conguaglio della fine locazione del …»</td>
                          <td class="px-3 py-2 align-top ">i giorni dopo l'uscita, dopo una fine con «Scrivi il conguaglio».</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«da restituire a …»</td>
                          <td class="px-3 py-2 align-top ">i giorni dopo l'uscita, dopo una fine con «Non scriverlo»: sono rimasti nelle rate di chi è uscito, e la cifra si regola fra le parti.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«con il conguaglio del cambio d'inquilino del …»</td>
                          <td class="px-3 py-2 align-top ">un cambio d'inquilino registrato dopo la generazione del piano gli ha dato quei giorni con il conguaglio.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«regolata fra le parti al cambio d'inquilino del …»</td>
                          <td class="px-3 py-2 align-top ">dopo un cambio d'inquilino che le parti avevano regolato fra loro, se chi era entrato è poi uscito con «Scrivi il conguaglio».</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«pagata da … con il conguaglio del cambio d'inquilino del …»</td>
                          <td class="px-3 py-2 align-top ">per un conduttore entrato dopo un cambio d'inquilino con «Scrivi il conguaglio»: la rimborsa a chi è entrato con il cambio.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«pagata da … con il cambio d'inquilino del … regolato fra le parti»</td>
                          <td class="px-3 py-2 align-top ">come sopra, se le parti avevano regolato quel cambio fra loro e chi era entrato è poi uscito con «Scrivi il conguaglio».</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«con la regola del piano»</td>
                          <td class="px-3 py-2 align-top ">dove la locazione è continuata con un coinquilino: la voce che un passaggio ha cambiato si divide per quota e per giorni sull'intera voce.</td>
                        </tr>
                        <tr><td colspan="2" class="bg-slate-50/60 px-3 py-1.5 text-[11px] font-semibold text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">In fondo, accanto a ogni conduttore</td></tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«con il conguaglio della fine locazione del …»</td>
                          <td class="px-3 py-2 align-top ">dove la locazione è continuata con un coinquilino: ciò che un passaggio ha scritto in saldi.</td>
                        </tr>
                        <tr>
                          <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-slate-100">«a favore di …»</td>
                          <td class="px-3 py-2 align-top ">un totale negativo: un'entrata che nei giorni di un conduttore supera la spesa.</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </section>
                <div>
                  <p class="mb-1 text-[13px] font-semibold text-slate-500 dark:text-slate-400">Nel dettaglio</p>
                  <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                    <AccordionItem value="v29" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le voci e come sono pagate</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Dalla pagina dei titolari, il pulsante con la stampante («Prospetto oneri accessori» sugli schermi larghi) stampa per ogni conduttore le voci che il riparto pone a carico dell'inquilino, divise per giorni di conduzione, e accanto a ogni voce come è pagata: «nelle sue rate», se è già intestata a lui; «con il conguaglio del cambio d'inquilino del …», se un cambio d'inquilino registrato dopo la generazione del piano gli ha dato quei giorni con il conguaglio; «pagata da …», se l'ha pagata un titolare perché in quei giorni nessun inquilino era registrato, ed è la parte da rimborsare.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v30" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Dopo la fine di una locazione</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Dopo la fine di una locazione dice anche che cosa è successo ai giorni dopo l'uscita: con «Scrivi il conguaglio» sono «pagata da … con il conguaglio della fine locazione del …»; con «Non scriverlo» sono rimasti nelle rate di chi è uscito, che ha pagato più dei suoi giorni, e il prospetto indica la cifra «da restituire a …» fra le parti, in coda alle voci e nel blocco dei giorni senza conduttore; dopo un cambio d'inquilino che le parti avevano regolato fra loro la voce dice «regolata fra le parti al cambio d'inquilino del …».
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v31" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Dopo un cambio d'inquilino</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Dopo un cambio d'inquilino con «Scrivi il conguaglio», un conduttore entrato dopo, anche chi era uscito e rientra, rimborsa a chi è entrato con il cambio la sua parte dei giorni che questi ha pagato con il conguaglio, e la sua voce dice «pagata da … con il conguaglio del cambio d'inquilino del …»; non i coinquilini di chi è uscito, né chi ne ha preso il posto, che hanno i loro giorni nelle loro rate. Lo stesso se le parti avevano regolato quel cambio fra loro e chi era entrato è poi uscito con «Scrivi il conguaglio»: la voce dice «pagata da … con il cambio d'inquilino del … regolato fra le parti». Senza quell'uscita le parti hanno regolato fra loro, e non rimborsa nessuno: l'accordo copre i giorni di chi era entrato con il cambio, anche in una voce che comincia dopo. Se chi era uscito aveva quei giorni con il conguaglio di un cambio prima, l'accordo finisce quando chi era entrato esce con la sua fine o con un cambio verso chi era uscito: da lì, anche prima del rientro di chi era uscito o senza rientro, chi entra rimborsa la sua parte a chi ha pagato quei giorni, e se chi era uscito rientra, chi entra nei suoi giorni gli rimborsa la sua parte come senza l'accordo. Se invece chi era entrato con il cambio passa i suoi giorni a un altro, l'accordo prosegue con chi ne prende il posto, e il prospetto non va oltre: chi entra dopo non rimborsa niente, nemmeno dopo l'uscita di chi ne ha preso il posto o il rientro di chi era uscito. Se chi era uscito aveva quei giorni nelle sue rate, restano lì, e chi entra non li rimborsa. L'uscita dopo di chi è rientrato, quando chiede la scelta del conguaglio, si legge come ogni uscita: chi entra dopo rimborsa chi ha pagato quei giorni. Con «Non scriverlo» i giorni sono rimasti a chi è uscito, nelle sue rate o con il conguaglio che aveva, e chi entra li rimborsa a lui; se chi è entrato con quel cambio li aveva ceduti con un cambio prima ed esce poi con «Scrivi il conguaglio», chi entra dopo la sua uscita rimborsa chi ne ha preso la coppia, e lui rimborsa a chi era uscito con «Non scriverlo» tutti i giorni dopo quell'uscita.
                        </p>
                        <p>
                          Una voce che matura tutta dopo un cambio d'inquilino non è di chi è uscito: il prospetto la segue lungo i passaggi, salvo dopo un cambio che le parti hanno regolato fra loro senza un'uscita con «Scrivi il conguaglio» di chi era entrato: lì resta a chi era uscito, nelle sue rate o, se l'aveva ricevuta con il conguaglio di un cambio, con quel conguaglio, fino alla sua prossima uscita con un conguaglio suo. Se l'ha con il conguaglio, chi entra dopo la fine dell'accordo, prima o dopo il suo rientro, gli rimborsa la sua parte; nelle sue rate restano i giorni di chi ha la riga.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v32" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Con un coinquilino che resta</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Dove la locazione è continuata con un coinquilino divide con la regola del piano le voci che un passaggio ha cambiato (le altre restano come nelle rate), per quota e per giorni sull'intera voce, e accanto a ogni conduttore dice ciò che ne ha nelle sue rate, «con il conguaglio della fine locazione del …» ciò che un passaggio ha scritto in saldi e, con il nome e il passaggio, chi rimborsa chi; per le fini registrate prima della 1.11.0-beta.55 dice ancora che chi paga i giorni dopo l'uscita lo decidono le parti, con i saldi del Wallet legati a quella fine.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v33" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">I piani generati dalla 1.10</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Un piano generato dalla 1.10, senza il riparto registrato, il prospetto lo legge con le quote delle rate; se dopo la fine un inquilino prende un altro ruolo sull'unità (compra una quota), la regola del piano non si ricalcola più, e il prospetto lascia fuori quel piano e dice perché, con le cifre della fine.
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                    <AccordionItem value="v34" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                      <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il segno dei totali</AccordionTrigger>
                      <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                        <p>
                          Un totale negativo, cioè un'entrata che nei giorni di un conduttore supera la spesa, si legge «a favore di …» e non con il segno meno; la colonna «Importo» delle righe tiene invece il segno, e il verso lo dice la colonna «Come».
                        </p>
                      </AccordionContent>
                    </AccordionItem>
                  </Accordion>
                </div>
              </TabsContent>

            </Tabs>
          </TabsContent>

          <TabsContent value="usufrutto" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── USUFRUTTO ────────────────────────────────────────────────────────── -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
              <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
              <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                <li>Costituzione: il proprietario pieno diventa nudo proprietario, e il modulo chiede chi paga l'ordinaria dal giorno dell'atto, come nella riserva.</li>
                <li>Le bozze restano a chi esce, e il conguaglio le comprende.</li>
                <li>Estinzione: l'usufruttuario esce e il nudo proprietario torna proprietario pieno; con un altro usufrutto in corso, solo il nudo proprietario dell'usufrutto che finisce, salvo l'accrescimento all'altro usufruttuario.</li>
                <li>Quando i dati non bastano sceglie l'amministratore; quando il programma non sa quale nuda proprietà torna piena, rifiuta l'estinzione e dice perché.</li>
              </ul>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Landmark class="h-4 w-4 text-indigo-500" /> La costituzione
              </h3>
              <p>
                Alla costituzione dell'usufrutto il modulo chiede chi paga l'ordinaria dal giorno dell'atto, come nella riserva (vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('vendita')">«Vendita»</button>).
              </p>
            </section>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Landmark class="h-4 w-4 text-indigo-500" /> Quando finisce un usufrutto
              </h3>
              <p>
                L'estinzione si registra da «Registra passaggio», scegliendo «Usufrutto» e poi «Estinzione»: la riga dell'usufruttuario si chiude al giorno prima dell'atto e il nudo proprietario dell'usufrutto che finisce torna <strong>proprietario pieno</strong> dal giorno dell'atto. Le rate già emesse non si toccano: per le voci che spettavano all'usufruttuario il conguaglio scrive un credito a chi esce e un debito a chi torna proprietario pieno, per i giorni dopo l'atto. La scheda «Chi torna proprietario pieno» e il pannello «Cosa cambierà» dicono chi sono.
              </p>
            </section>
            <div>

              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v35" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Senza un altro usufrutto in corso</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      <strong>Senza un altro usufrutto in corso tutti i nudi proprietari tornano proprietari pieni</strong>, ciascuno per la sua quota. Se chi esce è anche nudo proprietario di una parte — per esempio ha comprato la nuda proprietà mentre era usufruttuario — torna proprietario pieno anche per quella: le sue quote dal giorno dell'atto restano a suo nome, senza un conguaglio con la stessa persona, e il pannello lo dice. Con più nudi proprietari il conguaglio si divide fra loro.
                    </p>
                    <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
                      <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                      <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                        <p>
                          Un esempio, su una voce di €&nbsp;1.200,00 l'anno sull'«Usufruttuario»: Ugo ha l'usufrutto di tutta l'unità e la nuda proprietà di metà, Mara la nuda proprietà dell'altra metà. Finito l'usufrutto il 1° aprile restano 275 giorni, €&nbsp;904,11: €&nbsp;452,06 restano a Ugo e €&nbsp;452,05 passano a Mara, e il credito di Ugo scende a €&nbsp;452,05.
                        </p>
                      </div>
                    </div>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v36" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Con un altro usufrutto in corso</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      <strong>Con un altro usufrutto in corso sull'unità</strong> — per esempio due usufrutti sulle due metà, o due genitori usufruttuari — <strong>torna proprietario pieno solo il nudo proprietario dell'usufrutto che finisce</strong>. Gli altri nudi proprietari restano nudi, sotto l'usufrutto che continua, e chi esce non torna nudo proprietario dell'altra parte: se tutti tornassero proprietari pieni, le quote dell'unità sommerebbero più del 100 %. Il programma lo decide da sé quando i dati lo dicono: se l'usufrutto viene da una costituzione o da una vendita con riserva registrate da qui, lo dice il passaggio da cui è nato l'usufrutto, anche dopo che la nuda proprietà è stata venduta; se i nudi proprietari che possono tornare proprietari pieni valgono insieme proprio la quota dell'usufrutto, tornano loro; se un solo nudo proprietario vale più dell'usufrutto — due genitori donano con riserva al figlio, poi muore uno dei due — torna proprietario pieno solo per la parte dell'usufrutto che finisce e resta nudo proprietario del resto, e la sua riga si divide in due (è la regola di legge quando l'atto non dice altro).
                    </p>
                    <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
                      <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                      <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                        <p>
                          Con l'usufrutto di ciascun genitore al 50 %, su una voce di €&nbsp;1.200,00 sull'«Usufruttuario» il figlio torna proprietario pieno per il 50 % e resta nudo proprietario dell'altro 50 %, sotto l'usufrutto di chi sopravvive; il conguaglio dei 275 giorni dal 1° aprile è di €&nbsp;452,05, a debito del figlio e a credito di chi esce.
                        </p>
                      </div>
                    </div>
                    <p>
                      Lo stesso quando quella nuda proprietà, dopo, è passata a più persone con una successione registrata da qui (anche se poi un erede ha venduto la sua parte): gli eredi l'hanno ricevuta in comune, e la parte dell'usufrutto che finisce torna piena a ciascuno per la sua quota, senza che la scheda chieda niente. Se invece un testamento o una divisione hanno dato a ciascun erede la nuda proprietà di una parte precisa, l'estinzione si registra a mano da «Modifica associazione».
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v37" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando i dati non bastano, sceglie l'amministratore</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Con usufrutti censiti a mano, o con nudi proprietari che valgono più dell'usufrutto che finisce — fuori dal caso degli eredi di una nuda proprietà sola, qui sopra —, la scheda «Chi torna proprietario pieno» chiede quali nudi proprietari tornano proprietari pieni, e non preseleziona niente. Si spuntano i nudi della parte su cui l'usufrutto finisce: insieme devono valere proprio la quota dell'usufrutto, altrimenti il programma rifiuta e lo dice.
                    </p>
                    <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
                      <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
                      <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                        <p>
                          Un esempio: Ugo ed Elsa hanno l'usufrutto di una metà a testa, Mara la nuda proprietà della metà di Ugo e Carlo quella della metà di Elsa. Finisce l'usufrutto di Ugo: si spunta Mara, che torna proprietario pieno, Carlo resta nudo proprietario sotto l'usufrutto di Elsa, e il conguaglio dei 275 giorni dal 1° aprile è di €&nbsp;452,05, a debito di Mara e a credito di Ugo (voce di €&nbsp;1.200,00 sull'«Usufruttuario»).
                        </p>
                      </div>
                    </div>
                    <p>
                      C'è anche la casella «Tutti i nudi proprietari, ciascuno per la sua quota», da usare se la nuda proprietà è in comune fra i nudi proprietari — per esempio una donazione ai due figli con la riserva d'usufrutto per i due genitori: ogni nudo proprietario torna proprietario pieno per la sua parte dell'usufrutto che finisce e resta nudo proprietario del resto (due figli al 50 % sotto due usufrutti del 50 %: ciascuno torna proprietario pieno per il 25 % e resta nudo proprietario per il 25 %), e il conguaglio si divide come dice il pannello. Se nessun insieme di nudi interi vale l'usufrutto (nudi al 75 % e al 25 % sotto due usufrutti del 50 %), le caselle dei nudi non compaiono e resta solo quella per quota.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v38" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando il programma si ferma</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      In alcuni casi il programma non sa quale nuda proprietà torna piena, e rifiuta l'estinzione: il messaggio dice la ragione. Succede:
                    </p>
                    <ul class="list-disc pl-5 space-y-1.5">
                      <li>con un altro usufrutto in corso, quando chi esce ha anche una nuda proprietà che può essere quella della parte su cui l'usufrutto finisce — comprata mentre era usufruttuario, o indicata dal passaggio da cui l'usufrutto è nato —: il programma non sa quale nuda proprietà torna piena;</li>
                      <li>con un altro usufrutto in corso, quando i nudi proprietari che possono tornare proprietari pieni valgono meno dell'usufrutto che finisce: una parte della nuda proprietà può essere di chi esce, o mancare fra le righe in corso;</li>
                      <li>quando la nuda proprietà che tornerebbe piena solo in parte è nata proprio il giorno dell'estinzione: il programma non scrive, nello stesso giorno, la nuda proprietà e il suo consolidamento, cioè la parte che ne torna piena;</li>
                      <li>quando due estinzioni cadono lo stesso giorno sulle stesse righe (due genitori che muoiono lo stesso giorno): la seconda trova la nuda proprietà e la proprietà piena della stessa persona nate lo stesso giorno, e non le può riunire. Qui il programma non indica una strada: le righe si correggono a mano da «Modifica associazione», o si chiede assistenza;</li>
                      <li>quando una pertinenza spuntata ha anch'essa un altro usufrutto: se lì serve una scelta, si toglie la spunta e l'estinzione si registra dalla pertinenza, dove si sceglie; se lì il programma si ferma, si toglie la spunta e la pertinenza si registra a mano da «Modifica associazione».</li>
                    </ul>
                    <p>
                      Nei primi tre casi l'estinzione si registra <strong>a mano</strong>: da «Modifica associazione» si chiude la riga dell'usufrutto al giorno prima dell'atto e si correggono le righe dei nudi proprietari secondo l'atto. Non c'è conguaglio automatico: se serve, si scrive con un saldo manuale dal Wallet sulla stessa gestione. Se invece l'atto prevede che l'usufrutto si accresca all'altro usufruttuario, vale «L'accrescimento», qui sotto.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v39" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">L'accrescimento</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Con un altro usufruttuario in corso, la scheda offre la casella «L'usufrutto si accresce all'altro usufruttuario»: va spuntata quando l'atto lo prevede, o quando l'usufrutto è un legato a più persone insieme (artt. 675 e 678 c.c.). Allora l'usufrutto di chi muore va agli usufruttuari che restano, in proporzione alla loro quota, la nuda proprietà resta nuda e il conguaglio dell'ordinaria va a loro. Senza la spunta, negli atti fra vivi, vale la regola di legge quando l'atto non dice altro: la nuda proprietà si riunisce all'usufrutto che finisce. Nel legato di usufrutto congiunto è la legge stessa a portare all'accrescimento: la casella va spuntata. Se la nuda proprietà dell'unità è di più nudi proprietari, la casella non c'è, e l'accrescimento si registra a mano da «Modifica associazione»: con nude proprietà di origini diverse il programma non sa quale usufrutto stia sopra quale nuda; con gli eredi di una nuda proprietà sola la nuda è in comune, e senza la spunta torna piena a ciascuno per la sua quota, salvo quando chi esce ha anche una nuda proprietà: allora il programma si ferma anche senza la spunta (vedi «Quando il programma si ferma»), e l'estinzione si registra a mano.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v40" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi resta obbligato</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Il blocco «Chi resta obbligato» del pannello dice chi risponde delle spese. Se sull'unità non resta nessun altro titolare, chi torna proprietario pieno risponde di tutte le spese dell'unità (se sono più d'uno, ciascuno per la sua quota). Se ne restano — un altro usufrutto con i suoi nudi proprietari, il proprietario pieno dell'altra metà nell'unità mista — o se la nuda proprietà torna piena solo in parte, chi torna proprietario pieno risponde della parte dell'usufrutto che finisce (se sono più d'uno, ciascuno per la sua parte), e del resto dell'unità rispondono, come prima, i titolari di quella parte, chi torna pieno compreso se ne resta nudo proprietario: dove resta un usufrutto, il nudo proprietario e l'usufruttuario in solido (art. 67 ult. co. disp. att. c.c.). Per i passaggi registrati dalla 1.11.0-beta.43 in poi lo storico del passaggio dice anche chi torna proprietario pieno e per quale parte.
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Il modulo guarda le righe in corso il giorno dell'estinzione, non quelle di oggi: un'estinzione registrata in ritardo si sceglie sui nudi proprietari di allora. Se cambi chi esce o la data, la scelta si azzera e va rifatta.
            </p>
          </TabsContent>

          <TabsContent value="successione" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── SUCCESSIONE ──────────────────────────────────────────────────────── -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
              <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
              <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                <li>Una data sola, quella del decesso: gli eredi entrano nello stesso ruolo del defunto, ognuno con la sua quota.</li>
                <li>Fino a due scelte: dove scrivere l'arretrato del defunto (con il legato resta per forza a suo nome) e, se resta a suo nome e il conguaglio ha righe da proporre, se scriverlo.</li>
                <li>Se il defunto era anche usufruttuario e il suo usufrutto era ancora in corso il giorno del decesso, prima si registra l'estinzione dell'usufrutto, con la stessa data.</li>
                <li>La copia autentica qui non c'entra: si può allegare la dichiarazione di successione o il testamento.</li>
              </ul>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <ScrollText class="h-4 w-4 text-indigo-500" /> Quando muore un proprietario
              </h3>
              <p>
                Si registra da «Registra passaggio» scegliendo «Successione», o dal menu della riga del titolare con «Registra la successione». Vale per il proprietario pieno, il comproprietario e il nudo proprietario; la morte dell'usufruttuario è l'estinzione dell'usufrutto, quella dell'inquilino la fine della locazione.
              </p>
            </section>
            <div class="flex items-start gap-3 rounded-lg p-4 bg-amber-50 dark:bg-amber-900/20">
              <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
              <div class="space-y-2 text-[13px] text-amber-900 dark:text-amber-200">
                <p>
                  <strong>Se il defunto era anche usufruttuario dell'unità</strong> e il suo usufrutto risulta ancora in corso il giorno del decesso, la successione si ferma: l'usufrutto si estingue con la morte (art. 979 c.c.) e non passa agli eredi. Si registra prima «Usufrutto → estinzione» con la stessa data, poi la successione. Vale anche per una pertinenza spuntata.
                </p>
              </div>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Users class="h-4 w-4 text-indigo-500" /> Gli eredi
              </h3>
              <ul class="list-disc pl-5 space-y-2">
                <li><strong>Una data sola, quella del decesso.</strong> Gli eredi sono titolari da quel giorno, nello stesso ruolo del defunto, e il defunto fino al giorno prima. La data in cui lo studio lo sa e quella della dichiarazione di successione non spostano niente: si registra quando gli eredi si fanno avanti, anche mesi dopo.</li>
                <li><strong>Gli eredi, ognuno con la sua quota</strong> dell'unità: insieme fanno la quota del defunto.</li>
                <li><strong>Le rate in bozza</strong> di un piano che non si ricalcola più passano a un erede solo, l'erede di riferimento, che il modulo chiede solo quando serve: cambia l'intestatario, non l'importo. Un piano che si ricalcola ancora, ricalcolato, dà da sé i giorni dopo il decesso agli eredi per quota.</li>
              </ul>
            </section>
            <section class="space-y-4">
              <h3 class="flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white"><Scale class="h-4 w-4 text-indigo-500" /> Le due scelte</h3>
              <p>
                <strong>L'arretrato del defunto</strong> (le rate emesse e non pagate, le sue bozze, i suoi saldi) è la sua posizione verso il condominio: se è un debito, ne risponde ogni erede per la sua quota (art. 754 c.c.); può anche essere un credito, se il defunto aveva versato in più. Dove scriverlo è una scelta dello studio.
              </p>
              <div class="space-y-2">
                <p class="font-semibold text-slate-900 dark:text-slate-100">1. L'arretrato del defunto</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="mb-1.5 flex items-start gap-2">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span class="flex flex-col"><span class="font-semibold text-slate-900 dark:text-slate-100">Agli eredi, per quota</span></span>
                    </p>
                    <div class="space-y-2 text-[13px]">
                      <p>
                        Si può passarlo agli eredi per quota, con righe di saldo sulla stessa gestione: con il conguaglio ogni erede risponde della sua quota di tutto, e la posizione del defunto si chiude.
                      </p>
                    </div>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="mb-1.5 flex items-start gap-2">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span class="flex flex-col"><span class="font-semibold text-slate-900 dark:text-slate-100">A nome del defunto («eredi di …»)</span></span>
                    </p>
                    <div class="space-y-2 text-[13px]">
                      <p>
                        Si può invece lasciarlo a nome del defunto («eredi di …») e chiederlo agli eredi, registrando chi versa con «Versato da».
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              <p>
                <strong>Il conguaglio si sceglie.</strong> Con l'arretrato a nome del defunto, se il conguaglio ha righe da proporre, il pannello «Cosa cambierà» chiede se scriverlo, senza niente di già scelto.
              </p>
              <div class="space-y-2">
                <p class="font-semibold text-slate-900 dark:text-slate-100">2. Il conguaglio, con l'arretrato a nome del defunto</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="flex items-start gap-2 text-[13px]">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span><strong>«Scrivi il conguaglio»</strong>: è quello della vendita, i giorni dopo il decesso sono degli eredi, divisi per quota, con righe di saldo per ciascuno; la parte dell'erede di riferimento scende dell'importo delle rate in bozza che riceve.</span>
                    </p>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-950">
                    <p class="flex items-start gap-2 text-[13px]">
                      <Circle class="mt-0.5 h-4 w-4 shrink-0 text-indigo-500" />
                      <span><strong>«Non scriverlo</strong>: la posizione resta com'è»: nessuna riga in saldi, le rate emesse restano al defunto e le bozze dell'elenco del pannello passano all'erede di riferimento, che le paga tutte (restano al defunto, come nella vendita, quelle già scadute o pagate e quelle di una straordinaria che era sua); non chiede di dichiarare un accordo, e la nota è facoltativa.</span>
                    </p>
                  </div>
                </div>
              </div>
              <p>
                Con l'arretrato agli eredi la scelta non c'è: conguaglio e arretrato fanno un conto solo. Vale anche per il legato.
              </p>
            </section>
            <div>
              <p class="mb-1 text-[13px] font-semibold text-slate-500 dark:text-slate-400">Casi particolari</p>
              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v41" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le quote degli eredi</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      «Dividi in parti uguali» la divide al centesimo di punto. Un erede che era già comproprietario somma la quota ereditata alla sua. Un erede minorenne si registra come gli altri: verso il condominio paga, con i beni dell'erede, chi ne ha la rappresentanza.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v42" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Con l'arretrato agli eredi: le bozze e le rate già emesse</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      In quel caso le rate in bozza dei piani che non si ricalcolano più, anche quelle di prima del decesso, vanno all'erede di riferimento, salvo quelle con un pagamento, e la parte degli altri eredi si regola con le loro righe di saldo, del conguaglio e dell'arretrato, che entrano nel prossimo piano della stessa gestione; le rate già emesse al defunto restano a suo nome e non si incassano, perché il loro importo è già nelle righe degli eredi.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v43" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Le quote prima del decesso e il cancello del piano</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Le quote che un piano ricalcolato dopo dà al defunto per i giorni prima del decesso l'arretrato non le comprende, e il riquadro «Da sapere» lo dice: con l'arretrato agli eredi si passano agli eredi con i saldi dal Wallet legati alla successione; con l'arretrato a nome del defunto restano a suo nome. Quando il piano si genera o si ricalcola, il programma lo ripete nel suo cancello: nomina il defunto con la cifra e la parte di ogni erede per quota, dice la strada (i saldi dal Wallet legati alla successione) e se il piano applica o no i saldi del Wallet, e chiede la spunta, e lo ripete a ogni ricalcolo.
                    </p>
                    <p>
                      Se il piano applica i saldi del Wallet e i saldi, legati alla successione o di prima, sono la parte di ognuno, il cancello dice «i saldi ci sono già … non serve registrare altro»; con saldi non legati di altra cifra dice che il programma non sa se comprendono la parte. Se il piano non applica i saldi non legge quelli non legati: con saldi non legati dice che il programma non sa se comprendono la parte, e che se la comprendono basta creare di nuovo il piano, che li assorbe. I saldi legati alla successione li legge anche lì, e non li chiede di nuovo a chi li ha: il piano creato di nuovo li assorbe. Non li chiede di nuovo nemmeno se un altro piano della stessa gestione, non ancora emesso, li ha già assorbiti: dice in quale piano sono e le due strade, eliminare quel piano, così tornano liberi nel Wallet, o registrare quelli di questo piano dopo aver emesso quell'altro. Se la parte si regola fra le parti, i saldi legati alla successione ancora liberi nel Wallet si tolgono: il prossimo piano della stessa gestione che applica i saldi li assorbirebbe. Con due successioni dello stesso defunto sulla stessa unità (la proprietà e la nuda proprietà) la cifra si divide per ruolo; dove non si può dividere, decide l'amministratore.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v44" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il nudo proprietario</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se il defunto era nudo proprietario, l'usufrutto resta com'è e gli eredi prendono il suo posto: la straordinaria segue la delibera o la competenza dichiarata sulla fattura, l'ordinaria segue la voce. Le voci ordinarie sul «Proprietario» erano a suo carico e passano agli eredi; la legge (art. 1004 c.c.) le darebbe all'usufruttuario, e per i piani che verranno la si segue spostando la voce su «Usufruttuario», che vale per tutta la tabella.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v45" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il legato</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Se il testamento lascia l'unità a chi non eredita il patrimonio, si spunta «L'unità è un legato»: chi riceve l'unità paga dal giorno del decesso, e l'arretrato del defunto resta a suo nome, perché ne rispondono gli eredi.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v46" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Niente copia autentica</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      La casella della vendita libera chi vende (art. 63 co. 5 disp. att. c.c.) e qui non c'entra; al suo posto si può allegare la dichiarazione di successione o il testamento.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v47" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Chi resta obbligato</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Nella <strong>successione</strong>, dei contributi maturati fino al giorno prima del decesso rispondono gli eredi, ognuno in proporzione della sua quota ereditaria (art. 754 c.c.); dal decesso gli eredi rispondono dei contributi dell'unità come comproprietari, divisi fra loro per quota, e in assemblea li rappresenta una persona sola (art. 67 co. 2 disp. att. c.c.).
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v48" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Correggere</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Con l'arretrato agli eredi il conguaglio e l'arretrato fanno un conto solo: non si annulla il solo conguaglio, si annulla il passaggio e lo si registra di nuovo. Con l'arretrato a nome del defunto il conguaglio scritto si annulla come nella vendita, e annullarlo vuol dire che le parti hanno regolato fra loro; se invece non andava scritto, si annulla il passaggio e lo si registra di nuovo con «Non scriverlo».
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <div class="flex items-start gap-3 rounded-lg p-4 bg-slate-50 dark:bg-slate-800/50">
              <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-500" />
              <div class="space-y-2 text-[13px] text-slate-600 dark:text-slate-400">
                <p>La successione di chi aveva ricevuto la parte di una fine con «Scrivi il conguaglio»: vedi <button type="button" class="font-medium text-indigo-700 underline-offset-2 hover:underline dark:text-indigo-300" @click="vaiA('locazione', 'fine')">«Locazione»</button>, «Fine», «Le fermate».</p>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="dopo" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- ── DOPO LA REGISTRAZIONE ────────────────────────────────────────────── -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
              <p class="mb-2 flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200"><Info class="h-4 w-4" /> In breve</p>
              <ul class="list-disc space-y-1.5 pl-5 text-slate-800 dark:text-slate-200">
                <li>Il passaggio resta nello storico dell'unità: il pulsante «Storico» nella pagina dei titolari.</li>
                <li>Si annulla solo l'ultimo passaggio registrato, e solo a rate intatte; altrimenti «perché?» dice che cosa sistemare prima.</li>
                <li>Annullare il solo conguaglio vuol dire che le parti hanno regolato fra loro; con l'arretrato agli eredi non si annulla da solo.</li>
                <li>Il piano preso dal conguaglio resta com'è.</li>
              </ul>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Scale class="h-4 w-4 text-indigo-500" /> Il piano preso dal conguaglio resta com'è
              </h3>
              <p>
                Non si ricalcola, non si elimina e non perde una voce. Lo stesso per il piano tenuto fermo da una successione o da una fine locazione con il conguaglio non scritto: il messaggio lo dice.
              </p>
            </section>
            <div>

              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v49" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Annullare il conguaglio e ricalcolare il piano</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Annullare il conguaglio dallo storico vuol dire che le parti hanno regolato fra loro, e il piano resta fermo (un'eccezione per i passaggi registrati prima della 1.11.0-beta.42: se il passaggio aveva preso il piano solo per rate segnate «emesse» senza scritture, annullato il conguaglio il piano non è più fermo, e va ricalcolato prima di emettere o di registrare un incasso); per ricalcolarlo si tolgono prima le sue quote a giornale e i movimenti, se ne ha, poi si annulla il passaggio dallo storico della sua unità, lo si registra di nuovo e si ricalcola (con un passaggio registrato prima della versione 1.11.0-beta.42 l'ordine lo dicono i messaggi). Se le parti avevano già regolato fra loro, il messaggio lo dice, con la cifra quando la rinuncia o l'annullamento del conguaglio sono fatti dalla versione 1.11.0-beta.42 in poi: ricalcolando, il condominio addebita a chi entra i suoi giorni, e quell'accordo va rifatto. Un movimento tolto si registra di nuovo dopo il ricalcolo.
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <section>
              <h3 class="mb-3 flex items-center gap-2 text-base font-bold text-slate-900 dark:text-white">
                <Undo2 class="h-4 w-4 text-indigo-500" /> Ho registrato un passaggio sbagliato
              </h3>
              <ol class="space-y-2">
                <li class="flex items-start gap-3">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">1</span>
                  <span>Si annulla dallo storico dell'unità: nella pagina dei titolari il pulsante «Storico» apre «Chi ha avuto questa unità», e sotto il passaggio c'è «Annulla il passaggio…».</span>
                </li>
                <li class="flex items-start gap-3">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">2</span>
                  <span>Prima di confermare il programma dice che cosa torna come prima — le righe di titolarità scritte dal passaggio, le quote delle rate passate a chi era entrato — e, se c'è, che il conguaglio si toglie dai saldi della gestione; e dice che cosa resta da fare, per esempio ricalcolare di nuovo un piano generato o ricalcolato dopo il passaggio.</span>
                </li>
                <li class="flex items-start gap-3">
                  <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">3</span>
                  <span>Serve una nota di almeno dieci caratteri, che resta nello storico.</span>
                </li>
              </ol>
              <div class="mt-3 space-y-3">
                <p>
                  Le pertinenze passate insieme all'unità tornano con lei, nella stessa operazione: nello storico della pertinenza quel passaggio non compare.
                </p>
                <p>
                  Si annulla <strong>solo l'ultimo passaggio registrato</strong> sull'unità, anche se ha una data precedente a un altro, e solo <strong>a rate intatte</strong>. Altrimenti, al posto del comando compare «Annulla il passaggio — non consentito: perché?», e con un clic si legge che cosa sistemare prima.
                </p>
                <p>
                  Il passaggio annullato resta nello storico, con la data, chi l'ha annullato e la nota; nessun conto lo legge più.
                </p>
              </div>
            </section>
            <div>
              <p class="mb-1 text-[13px] font-semibold text-slate-500 dark:text-slate-400">Casi particolari</p>
              <Accordion type="multiple" :unmount-on-hide="false" class="rounded-lg border border-slate-200 px-3 dark:border-slate-700">
                <AccordionItem value="v50" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Quando l'annullamento non è consentito</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Succede quando:
                    </p>
                    <ul class="list-disc pl-5 space-y-1.5">
                      <li>sull'unità, o su una pertinenza passata con lei, c'è un passaggio registrato dopo, anche se ha una data precedente: si annulla prima quello, perché può aver letto ciò che questo ha scritto;</li>
                      <li>una rata passata a chi era entrato è stata emessa; oppure sulla quota dell'unità in quella rata c'è un pagamento, anche solo sulla parte del saldo pregresso rimasta a chi era uscito; oppure chi era entrato o chi era uscito ha segnalato dal portale un pagamento di quella rata, non ancora verificato;</li>
                      <li>in un piano generato o ricalcolato dopo il passaggio, una quota che l'annullamento cambierebbe — di chi era entrato, di chi era uscito o di chi ha preso i giorni rimasti scoperti — è stata emessa o pagata, o ne è stato segnalato il pagamento dal portale e non è ancora verificato;</li>
                      <li>il conguaglio è già stato assorbito da un piano;</li>
                      <li>una riga di titolarità aperta, chiusa o cambiata dal passaggio è stata corretta a mano dopo;</li>
                      <li>una riga associata o modificata a mano dopo il passaggio si scontra con quella che tornerebbe: la stessa persona, nello stesso ruolo, due volte nello stesso periodo, oppure quote dello stesso ruolo oltre 100 nello stesso giorno. Il «perché?» dice come sistemarla.</li>
                    </ul>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v51" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il conguaglio di una fine locazione, le fermate e i saldi legati</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Il conguaglio scritto alla fine di una locazione si annulla dallo storico come quello di una vendita, e vuol dire che le parti hanno regolato fra loro; se invece non andava scritto, si annulla il passaggio e lo si registra di nuovo con «Non scriverlo». Se un passaggio registrato dopo si è fermato su quel conguaglio, per esempio una vendita, annullare il conguaglio non è vietato: prima di confermare il programma dice la fermata e quanto sommano oggi i saldi del Wallet legati alla vendita, la cui parte senza il conguaglio è zero, e lo storico dice che la fermata non vale più. Annullare la vendita toglie i saldi del Wallet legati a lei; se un piano ne ha già assorbito uno, la vendita non si annulla finché quel piano non è eliminato (il lucchetto si riapre). I saldi scritti a mano senza legarli al passaggio restano: l'annullamento li nomina, e si tolgono dal Wallet.
                    </p>
                  </AccordionContent>
                </AccordionItem>
                <AccordionItem value="v52" class="border-slate-200 last:border-b-0 dark:border-slate-700">
                  <AccordionTrigger class="py-3 text-left text-[13px] font-semibold text-slate-800 dark:text-slate-200">Il piano dopo l'annullamento</AccordionTrigger>
                  <AccordionContent class="space-y-3 text-[13px] text-slate-700 dark:text-slate-300">
                    <p>
                      Dopo l'annullamento di una vendita, di un usufrutto o di una successione, un piano rate generato o ricalcolato mentre il passaggio c'era ha ancora le quote con la titolarità di quel passaggio: va ricalcolato prima di emettere, di incassare o di rimborsare, e il programma lo rifiuta finché non lo fai, dicendo quale passaggio è stato annullato. Se il piano ha un movimento (un incasso, un credito usato o rimborsato) il ricalcolo si rifiuta come sempre, e il messaggio dice i passi: annulla il movimento, ricalcola, registralo di nuovo. Un nuovo passaggio sulla stessa unità il cui conguaglio prenderebbe quel piano (nella successione e nella locazione, con «Scrivi») non si registra: il pannello «Cosa cambierà» mette sopra il conguaglio un riquadro ambra («Ricalcola prima il piano …») e lascia la via d'uscita, la casella «Le parti hanno regolato il conguaglio fra loro» o «Non scriverlo». Non conta se il piano non è stato ricalcolato dopo il passaggio, o se è stato ricalcolato dopo l'annullamento. La locazione resta fuori.
                    </p>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Un passaggio registrato prima della 1.11.0-beta.37 non si annulla: il programma non teneva il registro di ciò che scriveva. Si corregge a mano, chiudendo la riga con una data di fine e registrando da «Associa soggetto» la titolarità giusta.
            </p>
          </TabsContent>

        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
