<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Rate scadute», nella finestra che si apre di lato: che cosa mostra una riga, le due viste, l'elenco a una data passata, che
 * cosa non è nell'elenco, i suggerimenti delle regole del recupero, gli interessi di mora proposti, le spese per il ritardo, le due date dei sei mesi e
 * le dispense, le lettere e la loro spedizione, l'accordo di rientro con il calendario, l'ordine dell'incasso (art. 1194 c.c.), la stampa. Il testo della
 * norma arriva dalla pagina, che lo riceve dal server. Ogni frase dice ciò che il programma fa oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CalendarClock, ChevronRight, Coins, Handshake, Lightbulb, ListFilter, Mail, Percent, Printer, Receipt, Scale, Wallet } from 'lucide-vue-next';

defineProps<{ open: boolean; testoNorma?: string }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rate-scadute">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-rose-100 text-rose-700 rounded-lg dark:bg-rose-900 dark:text-rose-300">
                            <Wallet class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: rate scadute</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Chi deve ancora pagare, persona per persona: come si legge l'elenco, i suggerimenti, gli interessi e le spese per il ritardo, le date dei
                        sei mesi, le lettere, l'accordo di rientro e la stampa.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa dice una riga</h3>
                        <p class="mb-3">
                            Una riga per persona, con le sue unità. Lo <strong>scaduto</strong> è la somma delle rate emesse, scadute e non ancora pagate, <strong>senza
                            togliere il credito</strong>: il credito della persona si legge nella sua colonna, accanto. Per questo la riga «Rate scadute» della
                            Tesoreria, nella dashboard, può dare una cifra più bassa: lì a ognuno si toglie il suo credito nella stessa gestione.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Le <strong>fasce</strong> dividono lo scaduto per ritardo: da 1 a 30 giorni, da 31 a 90, oltre 90.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Il <strong>ritardo</strong> è quello della rata più vecchia. Il giorno della scadenza la rata non è ancora scaduta: lo è dal giorno dopo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Una rata con dentro un debito degli esercizi precedenti (il <strong>pregresso</strong>) ha l'età della rata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Il pregresso della rata 0 con l'origine in un esercizio precedente, prima del primo esercizio o senza origine non ha un numero di giorni: sta nella fascia oltre 90 giorni e non conta per il ritardo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Con la freccia la riga si apre e mostra le sue rate, ognuna con le due date dei sei mesi.</span></li>
                                <!-- Decisioni 494 (3) e 516: il debito ereditato sugli eredi, finché corrono i suoi interessi. -->
                                <li class="flex gap-2" data-testid="guida-ereditato"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Il <strong>debito ereditato</strong>: dopo una successione con l'arretrato agli eredi, la rata non pagata del defunto è sugli eredi, ognuno per la sua parte, con l'etichetta «ereditato» accanto al nome, da chi e con la data della successione; il defunto non c'è più per quel debito. Resta qui finché i suoi interessi corrono, anche dopo l'emissione della rata nuova che lo porta, e la rata lo dice con la sua scadenza; quando la rata nuova comincia a contare i suoi interessi, il debito è nella rata dell'erede. Il rendiconto invece lo tiene a nome del defunto, come il giornale. Quello che un erede ha versato sul debito ereditato (nella pagina dell'incasso, a nome suo) si toglie dalla sua parte, non da quella degli altri; se un erede muore a sua volta, la riga è del suo erede, e dice da chi l'aveva ereditato il primo defunto. Il defunto torna nell'elenco solo con le spese sue della rata nuova, quando scade.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Le due viste e la data dell'elenco</h3>
                        <p class="mb-3">
                            «Rate scadute» mostra ciò che è già scaduto. «In scadenza nei prossimi N giorni» mostra le rate emesse che scadono da oggi a N giorni e
                            non sono ancora pagate: i giorni si scelgono accanto al pulsante.
                        </p>
                        <p>
                            Con «Elenco al» vedi chi doveva ancora pagare a una data passata: lo scaduto e le fasce si ricalcolano a quella data. Il credito della
                            persona si conosce solo per oggi, e a una data passata la sua colonna non c'è.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa non è nell'elenco</h3>
                        <p>
                            Le rate con la scadenza passata ma non ancora emesse, e i saldi degli esercizi precedenti non ancora in un piano approvato. Non sono
                            ancora stati chiesti: una riga sopra l'elenco dice quanto sono. Le rate si emettono dal piano rate; i saldi si chiedono mettendoli in un
                            piano.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Lightbulb class="w-4 h-4" /> I suggerimenti</h3>
                        <p class="mb-3">
                            Nella vista «Rate scadute», con l'elenco a oggi, un riquadro sopra l'elenco dice che cosa chiedono le regole del recupero del
                            condominio, per le persone dei filtri. Sotto, il tasso degli interessi di mora di oggi e il collegamento alle regole. Sono
                            suggerimenti: non scrivono niente, e scegliere resta tuo.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>La tolleranza.</strong> Se la regola di oggi ha i giorni di tolleranza, il riquadro conta le persone con rate scadute da più di quei giorni e nessuna lettera, partita per email o spedita, dopo la scadenza della più vecchia. Una rata che un accordo di rientro tiene tutta fuori dalle lettere non conta. «Scegli queste persone» le spunta nell'elenco: poi prepari le lettere.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>Gli interessi da proporre.</strong> Il riquadro dice per quante persone ci sono interessi di mora da proporre. «Proponi gli interessi» apre la proposta per loro.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>Gli interessi che si fermano.</strong> Se per un anno manca il tasso legale, il riquadro dice per quante persone gli interessi si fermano, e perché.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Percent class="w-4 h-4" /> Gli interessi di mora</h3>
                        <p class="mb-3">
                            Gli interessi si propongono dal riquadro dei suggerimenti: «Proponi gli interessi» c'è solo lì, e vale per le persone che il
                            suggerimento nomina. Il programma propone, riga per riga, e niente si registra senza il tuo clic.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Una riga per rata e unità. I giorni vanno dal giorno dopo la scadenza, più quelli che le regole del condominio concedono (o dal giorno dopo l'emissione, se la rata è emessa più tardi), alla data della proposta compresa. Un versamento conta dal giorno dopo. «Alla data» cambia la data, e «Rifai la proposta» rifà il calcolo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span><strong>A tratti, con le regole.</strong> Il tasso è quello delle regole del recupero, giorno per giorno: il tasso legale dell'anno, o il tasso scritto con la sua fonte. La riga si divide in tratti quando cambia il capitale (un versamento, uno storno, un credito), quando comincia un anno nuovo o una regola nuova. «I tratti» li mostra, ognuno con i giorni, il capitale, il tasso e la fonte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Gli interessi di ogni rata sono arrotondati al centesimo, rata per rata. Non si contano su interessi e spese già addebitati. I crediti e gli anticipi della persona coprono prima le rate più vecchie.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Le quote degli inquilini non si propongono: la riga della persona le dice, e se vuoi le addebiti al proprietario con «Spese del ritardo». Di una rata coperta da un accordo di rientro in corso restano fuori i giorni dalla data dell'accordo; i giorni prima dell'accordo restano nella proposta, e la riga dice fin dove arriva. Sulla parte di una rata che l'accordo non copre gli interessi continuano, salvo la scelta dell'accordo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Una rata con interessi già registrati riparte dal giorno dopo l'ultimo giorno registrato. Se un versamento registrato dopo, con una data di prima, cambia quei giorni, la riga lo dice: gli interessi registrati restano come sono.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Togli le righe che non vuoi, o «Togli la persona». Per ogni gestione scegli la voce di entrata che riceve il denaro, poi «Registra», con il totale. Se nel frattempo le cifre sono cambiate, niente si scrive e la finestra lo dice.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Registrati, gli interessi diventano una scadenza della persona, una per persona e unità: «Interessi di mora al …», e con un tasso scritto anche il tasso e il suo riferimento. Sono del condominio quando la persona li versa.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Coins class="w-4 h-4" /> Spese del ritardo</h3>
                        <p class="mb-3">
                            «Spese del ritardo» chiede una spesa per il ritardo, pagata dal condominio, alle persone spuntate, o a tutte quelle dei filtri: una
                            scadenza per ognuna, con la stessa cifra. Non c'è nella vista «In scadenza nei prossimi N giorni».
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>«Che cos'è»: le spese di sollecito, le spese legali prima della causa o della causa, un'altra spesa. La causale proposta segue la scelta, e la puoi cambiare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>«Su che cosa si fonda»: la legge, il regolamento, una delibera, un provvedimento del giudice, altro o nessuna, con il riferimento. Gli avvisi dicono dove una sentenza ha visto le cose in un altro modo: non bloccano niente.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>La gestione è proposta, e la cambi se serve. La voce che riceve il denaro quando la persona lo versa la scegli tu.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Una spesa che lo studio ha fatturato con la riga sull'unità non va qui: si addebita dall'estratto conto della persona, con «Nuovo addebito» e la sua riga di fattura.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CalendarClock class="w-4 h-4" /> Le date dei sei mesi</h3>
                        <p class="mb-3">
                            Per ogni rata scaduta il programma calcola due date: sei mesi dalla fine dell'esercizio che contiene la sua scadenza, e sei mesi dalla
                            fine dell'esercizio di competenza del piano (per una gestione straordinaria, quello della delibera). Nella riga si vede la più vicina che
                            nessuna dispensa copre; se una dispensa le copre tutte, la più vicina, con la dispensa accanto. Sei mesi dopo è lo stesso giorno del
                            sesto mese dopo, o l'ultimo giorno di quel mese se quel giorno manca (31/08 → 28/02, o 29/02 negli anni bisestili); una data che cade di festivo non si sposta. Sono scelte prudenti: la data più presto.
                        </p>
                        <blockquote v-if="testoNorma" class="border-l-4 border-slate-300 dark:border-slate-600 pl-3 text-[13px] italic text-slate-600 dark:text-slate-400 mb-3" data-testid="guida-norma">
                            {{ testoNorma }}
                        </blockquote>
                        <p class="flex gap-2">
                            <Scale class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                            <span>Se l'assemblea ha dispensato l'amministratore, registra la delibera da «Dispense dei sei mesi», in alto accanto a «Guida»: la data
                            resta la stessa, e accanto si legge la dispensa che la copre.</span>
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> Le lettere</h3>
                        <p class="mb-3">
                            «Prepara le lettere» scrive a tutte le persone dei filtri, o solo a quelle che hai spuntato; «Prepara una lettera», nella riga, a una
                            persona sola. Il promemoria e il sollecito partono per email, si stampano o si scaricano; la diffida e la messa in mora si stampano o si
                            scaricano in PDF. Una lettera stampata o scaricata, dopo averla spedita, si registra come spedita (raccomandata, PEC mandata fuori dal
                            programma, a mano, posta, altro).
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>La colonna «Ultima lettera» dice la lettera più recente di ogni persona e come è partita.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>«Stampate o scaricate, spedizione da registrare» trova le lettere ancora da spedire: spunta le righe, o «Seleziona tutte le N dei filtri», e con «Registra la spedizione» registri insieme quelle della pagina, con lo stesso canale e la stessa data.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Per «Mai scritto» e «Nessuna lettera negli ultimi (giorni)» contano le lettere partite per email o spedite; una lettera solo stampata o scaricata no.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Se una lettera per email non è partita, un avviso sopra l'elenco la propone di nuovo, con le cifre di oggi.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Handshake class="w-4 h-4" /> L'accordo di rientro</h3>
                        <p>
                            Dalla riga, «Registra un accordo di rientro» annota l'accordo con cui la persona riconosce il debito: la data, le rate coperte con quanto
                            è riconosciuto, il calendario delle rate dell'accordo, la regola di decadenza se l'accordo la prevede, e se l'assemblea l'ha autorizzato.
                            Le rate coperte restano fra le scadute, con il segno dell'accordo, e le lettere proposte lasciano fuori la parte riconosciuta fino
                            all'ultima rata del calendario. L'accordo non è la dispensa dei sei mesi.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 mt-3">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Gli interessi e le spese entrano nell'accordo dopo che li hai registrati: sono rate loro, e l'accordo le copre come le altre, anche quelle registrate il giorno dell'accordo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Il calendario proposto ha rate uguali, l'ultima con i centesimi che avanzano; le cambi come dice l'accordo firmato. La somma deve fare il debito riconosciuto, al centesimo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Il denaro che arriva sulle rate coperte va all'accordo, con «Versamento dell'accordo» o senza, e copre le rate del calendario in ordine, la più vecchia prima: una rata con anche un centesimo scoperto non è versata, e l'accordo dice quanto manca. Su una rata coperta solo in parte, un versamento con «Versamento dell'accordo» va prima alla parte nell'accordo, uno senza prima alla parte fuori. Un passaggio di proprietà non è un versamento.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Quando la regola di decadenza si verifica, l'accordo lo dice con il giorno e le cifre di quel giorno, e il giorno resta anche dopo un versamento; se un versamento registrato dopo con una data di prima dice che quel giorno la condizione non c'era, l'accordo lo dice. La decadenza la registri tu, con «Registra la decadenza»: chiude l'accordo, e le rate tornano nelle lettere proposte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Dopo l'ultima rata del calendario le rate coperte tornano nelle lettere proposte e nell'ordine dell'incasso, anche se l'accordo non è chiuso. Finché l'accordo non è decaduto e il calendario non è finito, la proposta degli interessi di mora lascia fuori, dalla data dell'accordo in poi, le rate che copre, anche quelle già versate; i giorni prima dell'accordo restano nella proposta. Dopo l'ultima rata del calendario i giorni dell'accordo restano fuori solo se l'accordo è versato tutto. Con la decadenza registrata tornano nella proposta anche i giorni dell'accordo. Resta una proposta: diventa un addebito solo se la registri.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Su una rata coperta solo in parte, sulla parte fuori dall'accordo gli interessi continuano; se nell'accordo scegli «restano sospesi finché l'accordo regge», dalla data dell'accordo resta fuori tutta la rata. La scheda dell'accordo dice la scelta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Nella successione con l'arretrato agli eredi l'accordo si chiude per la successione: il debito che resta passa agli eredi con l'arretrato, per quota, e con ognuno puoi registrare un accordo nuovo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Nell'incasso, «Versamento dell'accordo» manda il versamento alle rate coperte, prima gli interessi e le spese; senza, l'ordine proposto lo manda alle rate fuori dall'accordo.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Receipt class="w-4 h-4" /> Quando la persona versa</h3>
                        <p class="mb-3">
                            Nell'incasso, gli interessi e le spese addebitati alla persona, già scaduti o che scadono quel giorno, vengono prima delle rate, i più
                            vecchi prima (art. 1194 c.c.); poi le rate, dalla più vecchia. «Paga scadute» li prende insieme alle rate scadute.
                        </p>
                        <p>
                            Un altro ordine si può: l'incasso chiede chi l'ha scelto, «Indicata da chi ha versato» o «Scelta da te». Con un accordo di rientro in
                            corso vale quello che dice la sezione dell'accordo, qui sopra.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Printer class="w-4 h-4" /> La stampa</h3>
                        <p>
                            «Stampa l'elenco» fa il PDF delle persone dei filtri, o di quelle spuntate. Contiene dati personali: si mostra all'assemblea e a chi ha
                            diritto di conoscerlo, come il condòmino o il creditore non ancora pagato che lo chiedono. Non va esposto in bacheca né in altri spazi che
                            vedono anche gli estranei.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListFilter class="w-4 h-4" /> I filtri</h3>
                        <p>
                            Accanto al nome di ogni filtro l'icona «i» dice a che cosa serve. «Seleziona tutte le N dei filtri» spunta tutte le righe, anche quelle
                            delle altre pagine; senza nessuna riga spuntata, le lettere e la stampa valgono già per tutte le persone dei filtri.
                        </p>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
