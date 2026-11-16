<script setup lang="ts">
/**
 * La guida della pagina «Incasso da terzi», nella finestra che si apre di lato dal pulsante «Guida completa» dell'intestazione: quando si usa e quando
 * no, ogni campo e perché è fatto così, che cosa scrive nei conti, dove si vede, come si annulla, e che cosa succede dopo la registrazione. Ogni frase
 * dice ciò che la pagina e il server fanno oggi (`IncassoDaTerziController`, `RegistraIncassoDaTerziAction`, `StornaIncassoDaTerziAction`).
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ArrowDownLeft, ChevronRight, CornerDownLeft, Landmark, ListChecks, RotateCcw, Users } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-incasso-da-terzi">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-emerald-100 text-emerald-700 rounded-lg dark:bg-emerald-900 dark:text-emerald-300">
                            <ArrowDownLeft class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: incasso da terzi</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Il denaro che entra da chi non è un condòmino: quando si usa, che cosa vuol dire ogni campo, che cosa scrive nei conti, dove si vede e
                        come si annulla.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Users class="w-4 h-4" /> Quando si usa</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Sì</strong>, per il denaro che entra da chi non è un condòmino: l'affitto dell'antenna, gli interessi del conto, un rimborso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>No</strong>, per le rate dei condòmini: si registrano nella pagina degli incassi, con «Registra incasso», che le imputa alle loro rate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Ci arrivi dalla pagina degli incassi, con «Altro incasso»; dalla lista delle fatture, quando una regolazione è stata scritta su una voce di entrata; dal riepilogo finanziario del rendiconto, con «Registra l'incasso» accanto a una voce di entrata che non ha ancora un incasso: la voce arriva già scelta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Chi ha versato non diventa un condòmino: l'estratto conto di una persona legge solo la sua posizione di condòmino, e l'incasso da terzi non lo vede.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> I campi</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Protocollo previsto»</strong>: il numero che l'incasso avrà, con il prefisso ENT. Diventa definitivo quando registri.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Esercizio»</strong>: quello aperto del condominio, e non si sceglie: ogni movimento va nell'esercizio aperto. Senza un esercizio aperto la pagina lo dice, e l'incasso non si registra.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Data dell'incasso»</strong>: il giorno in cui il denaro è entrato, al più oggi. Il giorno in cui lo registri lo segna il programma da sé, così il registro di contabilità segnala un incasso annotato più di trenta giorni dopo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Importo»</strong>: in euro, più di zero.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Voce di entrata»</strong>: solo le voci di entrata del condominio, delle gestioni dell'esercizio aperto, senza sottovoci e con il loro conto contabile. Una voce di spesa no: l'incasso la farebbe scendere come se qualcuno avesse restituito la spesa. Accanto alla voce scelta c'è il suo preventivo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Senza una voce di entrata la pagina dice di crearla nel piano dei conti con preventivo zero (per esempio «Affitto antenna» o «Interessi attivi»), con «Apri il piano dei conti»; poi torni qui.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Banca o cassa»</strong>: solo le banche e le casse contanti attive. Un fondo no, né una cassa virtuale: il registro di contabilità legge solo il denaro di una banca o dei contanti, e un'entrata scritta su un fondo non ci comparirebbe. Per accantonare, registra l'incasso sulla banca e poi fai un giroconto verso il fondo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Chi ha versato»</strong>, facoltativo: una persona o una società della rubrica, anche se non è un condòmino (il gestore dell'antenna, la banca). Il registro di contabilità lo mostra come controparte. Se non lo sai, lascia vuoto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Causale»</strong>: almeno tre lettere, al più 255; per esempio «Affitto antenna, primo semestre».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Registra incasso» e «Registra e nuovo» restano spenti finché mancano la voce, la banca o la cassa, la data, l'importo o la causale.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Landmark class="w-4 h-4" /> Che cosa scrive nei conti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una scrittura a protocollo ENT, con due righe: in dare la banca o la cassa, in avere il conto della voce di entrata, con la voce. Tutte e due portano chi ha versato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La gestione della scrittura è quella della voce, e l'esercizio quello aperto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non c'è un conto di ricavo: la voce di entrata riduce i costi dell'esercizio, come il piano rate riduce già le quote.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Anteprima scrittura», a destra, mostra le due righe con l'importo, la banca o la cassa e la voce, mentre scrivi.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ArrowDownLeft class="w-4 h-4" /> Dove si vede</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Nel registro di contabilità</strong>, fra le «Entrate da terzi», con chi ha versato come controparte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Nel rendiconto</strong>, nel riepilogo finanziario: la tabella «Entrate da terzi» dice per ogni voce di entrata il preventivo e l'«Incassato».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il piano dei conti e i capitoli del piano rate dicono per la voce l'incassato, non uno speso.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><RotateCcw class="w-4 h-4" /> Come si annulla</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Un incasso registrato non si modifica: si storna, dalla pagina della scrittura, con «Storna».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Scegli perché: una registrazione sbagliata (il denaro non è mai entrato, o va registrato in un altro modo) vale dal giorno dell'incasso, o da oggi se il suo esercizio è chiuso; un incasso tornato indietro (un bonifico richiamato, un accredito respinto) vale da oggi. Poi il motivo, almeno dieci caratteri.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Lo storno è la scrittura inversa, con la voce e chi ha versato ricopiati. L'incasso resta, e il registro lo segna stornato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Se l'esercizio dell'incasso è chiuso, lo storno va nell'esercizio aperto, e la sua causale dice da dove viene; se non c'è un esercizio aperto, lo storno si rifiuta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-rose-500 shrink-0 mt-0.5" /><span>Un incasso si storna una volta sola.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CornerDownLeft class="w-4 h-4" /> Dopo la registrazione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Registra incasso», in fondo al modulo, registra e ti riporta alla pagina da cui sei partito: gli incassi o le fatture. Se ci sei arrivato in un altro modo, si apre la scrittura appena registrata.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Registra e nuovo» registra e riapre il modulo vuoto, per l'incasso dopo, con la stessa pagina di ritorno.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In tutti e due i casi il messaggio in alto dice il protocollo dell'incasso registrato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Annulla» e «Indietro» escono senza registrare, verso le fatture se sei partito da lì, altrimenti verso gli incassi. Se hai già scritto qualcosa, «Annulla» chiede prima se uscire.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
