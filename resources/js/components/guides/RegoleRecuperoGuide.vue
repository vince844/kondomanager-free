<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Recupero dei crediti», nella finestra che si apre di lato: come si usano le regole (nessuna scelta: in un giorno ne vale una
 * sola per tutto il condominio), dove entrano (la proposta degli interessi, i suggerimenti dell'elenco «Rate scadute», le lettere), che cosa vuol dire
 * ogni campo della regola nuova, e il tasso legale con l'anno che manca e il tasso scritto a mano dallo studio. Ogni frase dice ciò che la pagina e il
 * server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { CalendarClock, ChevronRight, History, Lightbulb, ListChecks, Percent, Scale, Users } from 'lucide-vue-next';
import { GUIDA_INTERESSI_EREDI } from '@/lib/gestionale/addebiti/interessiDegliEredi';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-recupero-crediti">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Percent class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: recupero dei crediti</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Le regole del condominio per gli interessi di mora e per i solleciti: come si usano, dove entrano, che cosa vuol dire ogni campo, e il
                        tasso legale.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><History class="w-4 h-4" /> Come si usano le regole</h3>
                        <p class="mb-3">
                            Non c'è una regola da scegliere. In un giorno vale <strong>una regola sola</strong>, per tutto il condominio: la stessa per tutte le
                            persone. Ogni regola vale dal suo giorno al giorno prima della regola dopo. «Oggi», in testa alla pagina, dice il tasso che vale oggi;
                            nella storia la regola di oggi porta «vale oggi».
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Senza nessuna regola vale la legge</strong>: il tasso legale dell'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza (o dopo l'emissione, se la rata è emessa più tardi). Lo stesso vale per i giorni prima della prima regola.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una regola non si riscrive. Per cambiarla ne scrivi una nuova, con il suo giorno di inizio: quella di prima resta per i suoi giorni. Due regole non cominciano lo stesso giorno.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Gli interessi già registrati non cambiano.</strong> Se la regola nuova comincia in giorni già registrati, la pagina lo dice. La proposta dopo riparte dal giorno dopo l'ultimo registrato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Elimina» c'è solo per una regola che nessun interesse registrato ha usato. Le altre restano nella storia, con il giorno degli ultimi interessi registrati che le hanno usate.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Lightbulb class="w-4 h-4" /> Dove entrano</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>La proposta degli interessi.</strong> In «Rate scadute», nel riquadro dei suggerimenti, «Proponi gli interessi» apre la proposta degli interessi di mora. Il tasso e i giorni vengono dalle regole, giorno per giorno: quando comincia una regola nuova, o un anno nuovo con il tasso legale, la riga si divide in tratti, ognuno con il suo tasso e la sua fonte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il primo giorno degli interessi di una rata è il giorno dopo la scadenza, più i giorni di «Interessi dopo la scadenza» della regola che vale quel giorno; se la rata è emessa più tardi, il giorno dopo l'emissione: prima di essere chiesta, la rata non è in ritardo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>La proposta non registra niente da sola: gli interessi li registri tu. Con un tasso scritto, la causale degli interessi registrati porta il tasso e il riferimento. Si leggono così nell'estratto conto, nella ricevuta e nelle lettere.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Con un accordo di rientro.</strong> Di una rata che un accordo di rientro copre, finché l'accordo è in corso o è rispettato fino all'ultima rata del calendario, la proposta lascia fuori i giorni dalla data dell'accordo in poi: i giorni prima dell'accordo restano nella proposta, e la riga dice fin dove arriva. Su una rata coperta solo in parte, sulla parte fuori dall'accordo gli interessi continuano; se nell'accordo hai scelto che restino sospesi finché l'accordo regge, dalla data dell'accordo resta fuori tutta la rata. Se registri la decadenza, o il calendario finisce con rate non versate, tornano anche i giorni dell'accordo. I giorni già registrati non tornano.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>I suggerimenti.</strong> Con i giorni di tolleranza, in «Rate scadute» un suggerimento conta le persone con rate scadute da più giorni della tolleranza e nessuna lettera, partita per email o spedita, dopo la scadenza della più vecchia. «Scegli queste persone» le spunta nell'elenco. Una rata che un accordo di rientro tiene tutta fuori dalle lettere non conta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Un altro suggerimento dice per quante persone ci sono interessi da proporre. Se per un anno manca il tasso legale, dice per quante persone si fermano, e perché. I suggerimenti ci sono nella vista «Rate scadute» con l'elenco a oggi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Le lettere.</strong> Il segnaposto [tasso] scrive il tasso della regola di oggi con il suo riferimento, o il tasso legale. Il termine della regola è quello che la finestra delle lettere propone per il sollecito, quando non ricorda il termine dell'ultimo sollecito.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Dopo la domanda giudiziale il tasso è un altro (art. 1284 c. 4 c.c.). La proposta lo ricorda: gli interessi di una causa li quantifica il legale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>In assemblea.</strong> «Da portare in assemblea», nella card «Oggi», scrive per la prossima assemblea il punto dei criteri per gli interessi e le spese, dalla regola di oggi. Senza regole scrive lo stesso punto. Lo vede chi gestisce le assemblee.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> I campi della regola</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Da quando vale»</strong>: il giorno da cui la regola vale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Il tasso legale dell'anno»</strong>: il tasso legale di ogni anno, quello della tabella più sotto. Cambia ogni 1° gennaio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Un tasso scritto, con la sua fonte»</strong>: un tasso diverso dal legale. In «Tasso annuo (%)» i punti percentuali l'anno: più di zero, al più 100, con al più quattro decimali.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Da dove viene»</strong>: «regolamento», «delibera dell'assemblea» o «altro».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Il riferimento»</strong>: con un tasso scritto è obbligatorio, al più 150 caratteri. Scrivi da dove viene il tasso, così si può controllare: per esempio «art. 12 del regolamento contrattuale» o «assemblea del 10/03/2025, verbale n. 3, all'unanimità». Si stampa per intero accanto al tasso, nelle lettere e negli interessi registrati. L'icona «i» accanto al campo lo ricorda.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Se un'assemblea ha respinto, rinviato, revocato o annullato una delibera che riguarda la regola, perché è la sua fonte, la scheda lo dice in testa con la frase della delibera e il collegamento all'assemblea. Sono frasi: nessun conto cambia da solo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una regola scritta prima senza riferimento resta valida. Nella storia porta «senza riferimento», e dove si stampa il tasso c'è il nome della fonte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Interessi dopo la scadenza»</strong>: con 0 gli interessi contano dal giorno dopo la scadenza (o dopo l'emissione, se la rata è emessa più tardi); con un numero, da quei giorni più tardi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Tolleranza prima del sollecito»</strong>: i giorni dopo la scadenza in cui il suggerimento non conta una rata. Con 0 conta ogni rata scaduta. Vuoto, il suggerimento non c'è.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Termine scritto nelle lettere»</strong>: i giorni del termine che la finestra delle lettere propone per il sollecito. Vuoto, il termine lo scegli nella finestra.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>«Registra la regola»</strong> resta spento finché manca qualcosa, e accanto una frase dice che cosa.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Scale class="w-4 h-4" /> Le fonti di un tasso scritto</h3>
                        <p class="mb-3">
                            Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto all'unanimità. Per questo, con «regolamento» o
                            «delibera dell'assemblea», la pagina mostra un avviso accanto alla scelta, nella storia e nella proposta degli interessi.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con «regolamento» l'avviso chiede di controllare che il regolamento sia contrattuale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con «delibera dell'assemblea» l'avviso dice che una delibera a maggioranza può non bastare.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Con «altro» non c'è un avviso.</span></li>
                            </ul>
                        </div>
                        <p class="mt-3">L'avviso non blocca niente: la regola si registra, e la scelta resta tua.</p>
                    </section>

                    <!-- Decisioni 493 e 494 (1): gli interessi degli eredi, scelta per scelta, con il caso di Ettore. -->
                    <section data-testid="guida-interessi-eredi">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Users class="w-4 h-4" /> Gli interessi degli eredi</h3>
                        <p class="mb-3">
                            Quando un condòmino muore e il suo debito passa agli eredi con la successione, la proposta degli interessi degli eredi conta gli interessi su quel
                            debito dal decesso, ciascuno per la sua parte, finché il debito non entra in una loro rata. Resta una proposta: diventa un addebito solo se la
                            registri, e prima puoi abbassare le cifre, con il perché. Le otto scelte valgono per ogni successione del condominio, anche per quelle registrate
                            prima; nella pagina della successione le puoi cambiare per quella sola. Il caso: Ettore muore il 17/11/2026 senza aver
                            pagato la rata di aprile, € 300,00, e il debito passa a Elena e Marco al 50 %, al 2 % l'anno.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li v-for="g in GUIDA_INTERESSI_EREDI" :key="g.chiave" class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>{{ g.titolo }}.</strong> {{ g.esempio }}</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CalendarClock class="w-4 h-4" /> Il tasso legale</h3>
                        <p class="mb-3">
                            Il tasso legale cambia ogni 1° gennaio (art. 1284 c.c.) ed è lo stesso per tutti i condomini. La tabella in fondo alla pagina mostra
                            gli anni che il programma ha.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>L'anno che manca.</strong> Un anno che il programma non ha ancora sta in testa alla tabella, con la frase che dice perché manca. Una rata che ha giorni in quell'anno resta fuori dalla proposta degli interessi, tutta e non a metà, con la frase che dice quale anno manca.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Il tasso scritto a mano.</strong> Chi gestisce le impostazioni generali lo può scrivere da sé, con «Scrivi il tasso di un anno che manca»: l'anno, il tasso e la fonte, cioè il decreto del Ministero dell'economia e la Gazzetta Ufficiale. Vale per tutti i condomini. Si scrive per gli anni dal primo che il programma non ha fino all'anno prossimo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il tasso scritto a mano si vede con chi l'ha scritto, il giorno e la fonte, nella tabella e nella proposta degli interessi. Nelle lettere è il tasso legale, e basta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>«Correggi» e «Togli» li ha solo chi gestisce le impostazioni generali. Gli interessi già registrati restano come sono.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Quando il programma ha il tasso di quell'anno, vale quello del programma. Se è diverso da quello scritto a mano, la tabella lo dice.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
