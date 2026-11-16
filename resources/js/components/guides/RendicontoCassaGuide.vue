<script setup lang="ts">
/**
 * La guida della scheda «Cassa» del rendiconto, nella finestra che si apre di lato: le entrate e le uscite per cassa con il riporto dell'esercizio prima,
 * il conto in banca con il gruppo «Come si divide il conto» (i fondi con il loro tipo, la parte libera, «si può usare»), il registro filtrato su una
 * cassa, il «Totale delle casse», le casse sotto zero, il raccordo fra cassa e competenza, la stampa e i controlli che leggono la scheda; il saldo che dice la banca si confronta nella nota sintetica e
 * fra i controlli. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, ListChecks, Lock, Scale, TriangleAlert, Wallet } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-cassa">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Wallet class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: le entrate e le uscite per cassa</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Il denaro entrato e uscito da ogni cassa nell'esercizio, e perché la liquidità è cambiata in modo diverso dal risultato di gestione.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Wallet class="w-4 h-4" /> Per cassa</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il denaro entrato e uscito dalle casse con le scritture dell'esercizio. In testa, il primo e l'ultimo giorno dell'esercizio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una riga per cassa: «Disponibilità iniziale», «Entrate», «Uscite», «Disponibilità finale». Ogni riga quadra da sé: iniziale più entrate meno uscite uguale finale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fondo è una parte dello stesso conto corrente: il conto in banca chiude con il saldo della banca, con il fondo dentro. Sotto il conto, il gruppo «Come si divide il conto» dice quanto è in ogni fondo e quanto è libero, con il fondo grigio e un filetto a sinistra: le sue righe sono già nella riga del conto e non si sommano al totale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il denaro passato dalla parte libera a un fondo è fra le entrate del fondo, con sotto «messi nel fondo», e fra le uscite della parte libera, che sotto la cifra dice quanto ne è andato nei fondi («messi nei fondi»); il denaro tornato da un fondo alla parte libera dice «tornati dai fondi». Nella riga del conto questi spostamenti non ci sono: il denaro resta sullo stesso conto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con più conti in banca, ogni conto ha i fondi accantonati da lui. Un fondo aperto con un saldo suo, o passato fra fondi di banche diverse, ha la sua riga: il programma non sa in quale banca stia.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La disponibilità iniziale è il riporto dell'esercizio prima, con la regola del registro di contabilità: ogni movimento si conta una volta, nell'esercizio in cui è scritto. L'apertura di una cassa conta qui, non fra le entrate.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un clic sul nome di un conto apre il registro di contabilità filtrato su quella cassa: le card sono di quella cassa, con lo stesso saldo di qui, e «Come è composta» accanto al saldo lo dice voce per voce; una riga dice come si legge accanto a questa scheda, e il pulsante «Torna alla scheda Cassa» in alto riporta qui. Un clic su un fondo apre il suo mastrino.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Lock class="w-4 h-4" /> Il tipo dei fondi e quanto si può usare</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto il nome di ogni fondo un'etichetta dice il suo tipo, quello scelto nella modifica della cassa del fondo alla voce «Destinazione d'uso»: «generico, si può usare»; «vincolato ai lavori», con la descrizione del vincolo; «TFR»; «morosità».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fondo vincolato che l'assemblea ha sbloccato («Consenti sblocco d'emergenza», con la motivazione) dice «vincolato, sbloccato dall'assemblea» e la motivazione: il suo denaro si può usare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fondo senza tipo dice «tipo non indicato» e conta come vincolato: non si presume libero.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto «libero», la riga «si può usare» dice nella colonna «Disponibilità finale» quanto del conto si può usare alla fine dell'esercizio: la parte libera più i fondi generici e quelli sbloccati dall'assemblea. I fondi vincolati ai lavori, il TFR, la morosità e i fondi senza tipo restano fuori, e anche una cassa virtuale, che non è un fondo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>È la stessa regola che decide se da un fondo si può fare un giroconto verso un'altra cassa: da un fondo vincolato, non sbloccato, no.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><TriangleAlert class="w-4 h-4" /> Il totale e le righe in rosso</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Totale delle casse»: la somma delle righe delle casse, in grassetto; conta solo il denaro entrato nel condominio e uscito dal condominio, e le righe del gruppo non si sommano. Un passaggio da una cassa all'altra compare nelle due righe ma non nel totale: la riga sotto il totale dice quanto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un movimento stornato nell'esercizio non conta né fra le entrate né fra le uscite: l'originale e lo storno si annullano, e restano leggibili nel registro di contabilità.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una banca o una cassa contanti andata sotto zero nell'esercizio, anche per un giorno, è in rosso, con il giorno e la cifra più bassa: un conto reale non può contenere meno di niente. La banca si guarda con i suoi fondi dentro, che sono parte dello stesso conto. La sua parte libera sotto zero, con il conto sopra zero, è segnata a parte sulla riga «libero»: un pagamento ha usato il denaro di un fondo, e di solito manca il giroconto dal fondo al conto.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Scale class="w-4 h-4" /> Il raccordo fra cassa e competenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Raccordo fra cassa e competenza» dice perché nell'esercizio la liquidità è cambiata di una cifra, mentre il risultato di gestione è un'altra: la variazione della liquidità è il risultato di gestione, meno l'aumento dei crediti, più l'aumento dei debiti. Ogni riga è una posta con il suo nome.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se la somma non torna, la riga sotto dice di quanto: una scrittura dell'esercizio non quadra, e la diagnosi dello sbilancio nel Libro Giornale la trova.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> La stampa e i controlli</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Stampa»</strong>, in alto: le entrate e le uscite per cassa e il raccordo. Nel fascicolo questa scheda è la parte «Entrate e uscite per cassa». La stampa ha lo stesso gruppo «Come si divide il conto», con il tipo di ogni fondo fra parentesi sotto il suo nome, e il «Totale delle casse».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Tre controlli del rendiconto leggono questa scheda: «Raccordo fra cassa e competenza», «Casse reali sotto zero» e «Liquidità e cassa», che confronta la liquidità della situazione patrimoniale con la disponibilità finale di qui. Sono nella scheda «Controlli».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La cassa è quella della contabilità: il saldo registrato nel programma. Il saldo che dice la banca, scritto sull'estratto conto della banca, lo scrivi nella «Nota sintetica»; il controllo «Il saldo della banca e la contabilità» confronta le due cifre.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
