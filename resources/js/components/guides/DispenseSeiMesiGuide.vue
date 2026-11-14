<script setup lang="ts">
/**
 * La guida della pagina «Dispense dei sei mesi», nella finestra che si apre di lato: che cosa dice la legge, quando si registra una dispensa, come si
 * compila, che cosa cambia nelle rate scadute, la revoca e che cosa la dispensa non è. Il testo della norma arriva dalla pagina, che lo riceve dal server.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CalendarClock, ChevronRight, FileText, Scale, Undo2, Users } from 'lucide-vue-next';

defineProps<{ open: boolean; testoNorma?: string }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-dispense">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Scale class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: dispense dei sei mesi</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Che cos'è la dispensa dell'assemblea, quando registrarla, come si compila e che cosa cambia nell'elenco delle rate scadute.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa dice la legge</h3>
                        <p class="mb-3">
                            L'amministratore deve agire per riscuotere le quote non pagate entro <strong>sei mesi dalla chiusura dell'esercizio</strong> in cui
                            quelle quote sono comprese. Ne è esonerato solo se l'assemblea lo ha <strong>dispensato espressamente</strong>: è questa la
                            dispensa che si registra qui.
                        </p>
                        <blockquote v-if="testoNorma" class="border-l-4 border-slate-300 dark:border-slate-600 pl-3 text-[13px] italic text-slate-600 dark:text-slate-400" data-testid="guida-norma">
                            {{ testoNorma }}
                        </blockquote>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Quando si registra</h3>
                        <p class="mb-3">
                            Quando l'assemblea ha votato di non agire, per tutti o per alcune persone: per esempio perché preferisce aspettare, o perché la cifra
                            è piccola. Senza una delibera la dispensa non c'è, e la pagina non serve.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una delibera si registra una volta sola, anche se copre più esercizi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se l'assemblea delibera di nuovo, per altri esercizi o altre persone, si registra un'altra dispensa.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Come si compila</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                                <div class="flex items-center gap-2 mb-1 text-blue-700 dark:text-blue-300"><FileText class="w-4 h-4" /><h4 class="font-bold">Il verbale</h4></div>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                                    La data della delibera e il numero scritto sul verbale dell'assemblea, per esempio 3/2027. Il PDF o la foto del verbale si
                                    può allegare: si riapre dalla dispensa registrata.
                                </p>
                            </div>
                            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                                <div class="flex items-center gap-2 mb-1 text-amber-700 dark:text-amber-300"><CalendarClock class="w-4 h-4" /><h4 class="font-bold">Gli esercizi coperti</h4></div>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                                    Gli esercizi di cui la delibera parla. Una rata ha due date dei sei mesi, dalla chiusura dell'esercizio che contiene la sua
                                    scadenza e da quella dell'esercizio di competenza del piano: la dispensa copre le date che si contano da questi esercizi. Un esercizio creato dopo non è coperto finché non registri una dispensa che lo comprende.
                                </p>
                            </div>
                            <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm sm:col-span-2">
                                <div class="flex items-center gap-2 mb-1 text-emerald-700 dark:text-emerald-300"><Users class="w-4 h-4" /><h4 class="font-bold">Per chi vale</h4></div>
                                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                                    Lo dice la delibera: per tutte le persone del condominio, o solo per alcune, che scegli dal menu scrivendo una parte del nome. La
                                    scelta non è già fatta: va presa ogni volta.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa cambia nelle rate scadute</h3>
                        <p class="mb-3">
                            <strong>Le date dei sei mesi non cambiano.</strong> Nell'elenco delle rate scadute e nella sua stampa, accanto alla data coperta, si
                            legge «dispensa dell'assemblea del …», con il numero del verbale. Per ogni rata la data più vicina è, se c'è, una che nessuna
                            dispensa copre: così la data che si legge accanto a ogni persona, e che ordina l'elenco per data dei sei mesi, è quella che va ancora
                            fatta; se una dispensa le copre tutte, si legge la più vicina, con la dispensa accanto.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Undo2 class="w-4 h-4" /> La revoca</h3>
                        <p>
                            Se l'assemblea cambia idea, accanto alla dispensa scegli «Registra la revoca», con la data della nuova delibera. Dal giorno della revoca
                            la dispensa non copre più le date, e l'elenco scrive «dispensa revocata il …». La dispensa resta nell'elenco di questa pagina, con
                            la sua revoca.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa la dispensa non è</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non toglie niente da quello che le persone devono versare: le rate restano scadute.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non è l'accordo di rientro con una persona, che si registra dalla sua riga nelle rate scadute.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non ferma le lettere: i solleciti si possono mandare lo stesso.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
