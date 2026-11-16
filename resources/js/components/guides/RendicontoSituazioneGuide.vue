<script setup lang="ts">
/**
 * La guida della scheda «Situazione patrimoniale» del rendiconto, nella finestra che si apre di lato: la fotografia alla data, i quattro numeri, le
 * attività e le passività, le fatture aperte e gli altri debiti certi scritti nella nota sintetica, la divisione per gestione, la liquidità e i fondi,
 * che cosa la scheda non fa, la stampa e i controlli che la leggono. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Camera, ChevronRight, CircleOff, Landmark, ListChecks, Receipt, Scale, TrendingUp } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-rendiconto-situazione">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Camera class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: la situazione patrimoniale</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        I conti del condominio a una data: la liquidità, i crediti, i debiti, le fatture aperte, i fondi e il risultato di gestione.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Camera class="w-4 h-4" /> Una fotografia alla data</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Ogni saldo è quanto c'era sul conto a quella data, con dentro tutto ciò che è successo prima, anche negli esercizi precedenti. Per l'esercizio aperto la data è oggi; per uno finito, la sua data di fine.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Per un esercizio non ancora cominciato la situazione è quella di oggi, e la pagina lo dice.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un clic su un conto apre il suo mastrino: tutti i movimenti di quel conto, nello stesso esercizio.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><TrendingUp class="w-4 h-4" /> I quattro numeri</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Liquidità al»</strong> giorno: banca, contanti e fondi, con sotto la parte nei fondi. È la liquidità registrata nella contabilità, non il saldo che dice la banca.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Crediti verso i condòmini»</strong>: le quote emesse e non incassate, e le spese anticipate per singole unità. «Chi deve che cosa: le rate scadute» apre l'elenco delle rate scadute alla stessa data; «Le posizioni di ognuno» apre la scheda «Condòmini».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Debiti»</strong>: verso i fornitori e verso l'Erario. Il numero delle fatture aperte porta al loro elenco, più sotto.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>L'avanzo o il disavanzo di gestione</strong>: le quote registrate in questo esercizio meno i costi di questo esercizio. Che cosa farne lo decide l'assemblea.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Scale class="w-4 h-4" /> Attività e passività</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Due colonne con i saldi dei conti. I costi non ancora conguagliati stanno fra le attività: sono spese sostenute per conto dei condòmini. Le quote emesse ai condòmini stanno fra le passività, come debito della gestione.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Quadratura»</strong>: le attività più i costi sono uguali alle passività, più i ricavi se ce ne sono. È la regola dare uguale avere, applicata ai saldi dei conti; il totale dei movimenti è nel controllo «Partita doppia».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Saldi di apertura non ancora registrati a giornale»: una riga loro, in giallo, fra le attività.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Receipt class="w-4 h-4" /> Le fatture aperte e gli altri debiti</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Fatture aperte al»</strong> giorno: le fatture di ogni esercizio con la data entro quel giorno e non ancora pagate, con il residuo verso il fornitore, al netto della ritenuta, in ordine di scadenza. Una fattura scaduta è in rosso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Paga» apre il pagamento della fattura; per un esercizio finito non c'è. Una nota di credito non si paga: si usa in un pagamento. «Tutte le fatture» apre l'elenco delle fatture.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sotto la tabella, una riga lega il totale delle fatture aperte ai debiti verso i fornitori del giornale. I debiti verso l'Erario hanno accanto «F24».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Un debito che non è una fattura</strong> (un debito da una sentenza, il TFR del portiere, un debito verso l'amministratore di prima) lo scrivi nella «Nota sintetica», nell'elenco «Altri debiti certi»: compare qui sotto la riga, fuori dal giornale e dai totali. «Scrivili nella nota» porta lì.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Landmark class="w-4 h-4" /> Per gestione, liquidità e fondi</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Per gestione»</strong>: i costi seguono la gestione della voce, le quote quella del piano, i debiti quella della fattura. Costi e quote sono dell'esercizio; crediti e debiti sono alla data.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Liquidità: libera e accantonata»</strong>: la banca e i contanti, ogni fondo, il totale. Accanto, i vincoli dichiarati nei contributi versati e le casse fondo che li tengono, con che cosa fare quando un fondo ha meno di quanto gli è stato assegnato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Movimento dei fondi»</strong>: per ogni fondo, nell'esercizio, l'iniziale, l'accantonato, l'utilizzato, il finale, il vincolo e lo scoperto. Un clic sul fondo apre il suo mastrino.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un fondo è una parte dello stesso conto corrente: il totale conta banca, contanti e fondi una volta sola.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CircleOff class="w-4 h-4" /> Che cosa la scheda non fa</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non elenca chi deve soldi, solo il totale: le posizioni di ognuno sono nella scheda «Condòmini», le rate scadute di ognuno nella pagina «Rate scadute».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non confronta con l'estratto conto della banca movimento per movimento. Il saldo alla fine dell'esercizio si confronta se scrivi il saldo dell'estratto conto della banca nella «Nota sintetica»: il controllo è nella scheda «Controlli».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Non chiude l'esercizio: la fotografia di un esercizio finito si rifà a ogni lettura, con le registrazioni che ci sono.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> La stampa e i controlli</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Stampa»</strong>, in alto: «Situazione patrimoniale», questa scheda con l'esito dei controlli in testa; «Situazione, cassa e controlli», in un foglio solo; «Libro mastro», il mastrino di ogni conto movimentato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Sei dei quindici controlli del rendiconto leggono questa scheda: «Partita doppia», «Quadratura patrimoniale», «Raccordo fra cassa e competenza», «Vincoli e casse fondo», «Liquidità e cassa», «Casse reali sotto zero». Sono nella scheda «Controlli», con l'esito e che cosa fare.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
