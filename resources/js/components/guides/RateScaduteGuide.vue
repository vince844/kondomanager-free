<script setup lang="ts">
/**
 * La guida della pagina «Rate scadute», nella finestra che si apre di lato: che cosa mostra una riga, le due viste, l'elenco a una data passata, che
 * cosa non è nell'elenco, le due date dei sei mesi e le dispense, le lettere e la loro spedizione, l'accordo di rientro, la stampa. Il testo della norma
 * arriva dalla pagina, che lo riceve dal server. Ogni frase dice ciò che il programma fa oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CalendarClock, ChevronRight, Handshake, ListFilter, Mail, Printer, Scale, Wallet } from 'lucide-vue-next';

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
                        Chi deve ancora pagare, persona per persona: come si legge l'elenco, le date dei sei mesi, le lettere, l'accordo di rientro e la stampa.
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
                            Le rate con la scadenza passata ma non ancora emesse, e i saldi degli esercizi precedenti non ancora in un piano approvato. Sono dovuti,
                            ma non sono stati chiesti: una riga sopra l'elenco dice quanto sono. Le rate si emettono dal piano rate; i saldi si chiedono mettendoli
                            in un piano.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CalendarClock class="w-4 h-4" /> Le date dei sei mesi</h3>
                        <p class="mb-3">
                            Per ogni rata scaduta il programma calcola due date: sei mesi dalla fine dell'esercizio che contiene la sua scadenza, e sei mesi dalla
                            fine dell'esercizio di competenza del piano (per una gestione straordinaria, quello della delibera). Nella riga si vede la più vicina che
                            nessuna dispensa copre; se una dispensa le copre tutte, la più vicina, con la dispensa accanto. «Più sei mesi» è lo stesso giorno del
                            sesto mese dopo, o l'ultimo giorno di quel mese se quel giorno manca (31/08 → 28/02, o 29/02 negli anni bisestili); una data che cade di festivo non si sposta. Sono scelte prudenti: la data più presto.
                        </p>
                        <blockquote v-if="testoNorma" class="border-l-4 border-slate-300 dark:border-slate-600 pl-3 text-[13px] italic text-slate-600 dark:text-slate-400 mb-3" data-testid="guida-norma">
                            {{ testoNorma }}
                        </blockquote>
                        <p class="flex gap-2">
                            <Scale class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" />
                            <span>Se l'assemblea ha dispensato l'amministratore, registra la delibera da «Dispense dei sei mesi»: la data resta la stessa, e accanto si
                            legge la dispensa che la copre.</span>
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
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Per «Mai scritto» e «Nessuna lettera negli ultimi N giorni» contano le lettere partite per email o spedite; una lettera solo stampata o scaricata no.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Se una lettera per email non è partita, un avviso sopra l'elenco la propone di nuovo, con le cifre di oggi.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Handshake class="w-4 h-4" /> L'accordo di rientro</h3>
                        <p>
                            Dalla riga, «Registra un accordo di rientro» annota l'accordo con cui la persona riconosce il debito: la data, fino a quando vale, quanto
                            è riconosciuto e se l'assemblea l'ha autorizzato. È un registro: le rate
                            restano fra le scadute, con il segno dell'accordo, e le lettere proposte lasciano fuori la parte riconosciuta fino al giorno scritto.
                            Il calendario delle rate dell'accordo, gli interessi e le spese il programma non li registra. L'accordo non è la dispensa dei sei
                            mesi.
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
