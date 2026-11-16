<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Ricevute degli incassi», nella finestra che si apre di lato: da dove parte la pagina, i filtri, la scelta delle righe,
 * la finestra prima di emettere, la stampa, il PDF per persona, l'email, chi non ha un indirizzo, gli avvisi in testa alla pagina e la ricevuta
 * di un incasso solo. Ogni frase dice ciò che la pagina e il server fanno oggi.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { AlertTriangle, Archive, CalendarClock, ChevronRight, FileText, ListChecks, ListFilter, Mail, MailX, Printer, Receipt } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-ricevute">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-emerald-100 text-emerald-700 rounded-lg dark:bg-emerald-900 dark:text-emerald-300">
                            <Receipt class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: ricevute degli incassi</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Le ricevute dei versamenti, una o tante: da dove parte la pagina, i filtri, la scelta, la stampa, il PDF per persona, l'email e gli avvisi.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><CalendarClock class="w-4 h-4" /> Da dove parte la pagina</h3>
                        <p class="mb-3">
                            La pagina si apre sulle ricevute <strong>mai emesse</strong> dei versamenti dal primo del mese scorso a oggi. «Mai emessa» vuol dire
                            non stampata, non inviata e non in coda. Una ricevuta che non è partita torna fra le mai emesse.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Il numero accanto al pulsante «Ricevute», nell'elenco degli incassi, le conta allo stesso modo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Un incasso registrato da poco può avere un versamento più vecchio del periodo. Una riga lo dice, e con «Includile» il periodo parte dal versamento più vecchio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Nell'elenco non ci sono gli incassi annullati, quelli in cui non è entrato denaro (le rate coperte solo dal credito) e quelli con le rate di più persone che non dicono chi ha versato.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListFilter class="w-4 h-4" /> I filtri</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Ricevuta</strong>: mai emessa, già stampata, già inviata o in coda, tutte.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Versamento dal</strong> e <strong>al</strong>: la data del versamento.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Gestione</strong> e <strong>Cassa</strong>: la gestione dell'incasso e la cassa in cui è entrato il denaro.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Email</strong>: se la persona ha l'indirizzo primario o il secondario. La PEC qui non conta.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Importo</strong>: sopra € 77,47 o fino a € 77,47. Conta il denaro versato.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Persone</strong>: le ricevute di chi ne è la posizione o ha versato. Vuoto vale tutte. Nella riga, «solo questa persona» fa lo stesso con un clic.</span></li>
                            </ul>
                        </div>
                        <p class="mt-3">Quando cambi un filtro, la scelta delle righe si toglie.</p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> La scelta</h3>
                        <p class="mb-3">
                            Spunta le righe una per una, o la casella in testa alla tabella per quelle della pagina. «Seleziona tutte le N dei filtri» prende
                            anche quelle delle altre pagine: finché vale, le caselle restano spuntate e ferme. «Togli la scelta» ricomincia da capo.
                        </p>
                        <p>
                            «Stampa», «PDF per persona» e «Invia per email» si accendono quando ne hai scelta almeno una. Ognuno apre una finestra con
                            l'anteprima: lì decidi, e solo dopo confermi.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><FileText class="w-4 h-4" /> Nella finestra, prima di emettere</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>In alto: quante ricevute sono, e quante restano fuori (incassi annullati, senza denaro, con le rate di più persone).</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Un avviso dice se ci sono versamenti di più di 60 giorni fa: controlla il periodo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>La prima emissione è l'originale. Una ricevuta già emessa esce come «Copia», con le cifre dell'originale.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Il bollo si sceglie una volta per le ricevute sopra € 77,47 e una per le altre. Se e come la ricevuta lo sconta lo decidi tu.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>La nota, facoltativa, va su tutte. La causale dell'incasso si stampa solo se spunti «Stampa la causale».</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Nella stampa e nel PDF per persona, «Anteprima» apre il PDF senza emettere: nel registro non scrive niente.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Printer class="w-4 h-4" /> Stampa</h3>
                        <p class="mb-3">
                            Il PDF si apre in una scheda nuova; se il browser non la apre, si scarica. Stampate, le ricevute non sono più fra le mai emesse.
                        </p>
                        <p class="mb-3">
                            L'ordine è per persona, per unità o per data del versamento. Le ricevute della stessa persona restano insieme, con l'indirizzo sulla
                            prima: una busta per persona. Senza indirizzo postale il riquadro della busta resta vuoto, e la finestra dice di chi.
                        </p>
                        <p>
                            In un PDF stanno al massimo 200 ricevute. Oltre, si stampano in parti, una per volta, senza dividere una persona (salvo chi da sola ne
                            ha più di 200).
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Archive class="w-4 h-4" /> PDF per persona</h3>
                        <p>
                            Per chi manda le ricevute dal suo programma di posta. Si scarica un archivio .zip: un PDF per persona, e il file «Indirizzi email.txt»
                            con gli indirizzi di ognuna. Ogni ricevuta risulta stampata. In un archivio stanno al massimo 200 ricevute: oltre, restringi i filtri,
                            per esempio il periodo.
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> Invia per email</h3>
                        <p class="mb-3">
                            Scegli a quali indirizzi: primaria, secondaria, PEC. La PEC parte come email semplice, dal server di posta configurato. La finestra
                            ripropone gli indirizzi del tuo ultimo invio in blocco in questo condominio.
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>«Partiranno» dice quante persone, quante ricevute e quanti indirizzi.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Se fra le scelte ce ne sono già inviate o in coda, la casella «Escludi le N già inviate o in coda» di serie è spuntata: restano fuori.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>«Manda anche a chi ha versato per conto di altri» manda la ricevuta anche a chi ha pagato per un'altra persona.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>«Mostra chi riceve che cosa» elenca persone, indirizzi e ricevute. Da lì togli una persona dall'invio.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>«Mandane una a me» manda al tuo indirizzo il messaggio della prima persona, con la scritta PROVA. Non conta come ricevuta inviata.</span></li>
                            </ul>
                        </div>
                        <p class="mt-3 mb-3">
                            Le email partono in coda. Un messaggio porta al massimo 50 ricevute: oltre, la stessa persona ne riceve più d'uno.
                        </p>
                        <p class="mb-3">
                            Se l'installazione manda le email senza la coda, partono mentre la pagina aspetta, al massimo 50 messaggi per volta. Per gli altri,
                            dopo l'invio, c'è «Invia le altre».
                        </p>
                        <p>Senza un server di posta configurato le ricevute non partono: la finestra lo dice, e puoi stamparle.</p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><MailX class="w-4 h-4" /> Chi non ha un indirizzo</h3>
                        <p class="mb-3">
                            Chi non ha un indirizzo fra quelli scelti non riceve l'email. La finestra lo nomina, prima e dopo l'invio, e «Stampa le N senza
                            indirizzo» stampa le sue ricevute per la posta.
                        </p>
                        <p>
                            Se ha un indirizzo di un altro tipo, la finestra lo dice: spuntalo per raggiungerlo. Nell'elenco, nella colonna «Email», chi non ce l'ha porta
                            «No: stampala per la posta».
                        </p>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><AlertTriangle class="w-4 h-4" /> Gli avvisi in testa alla pagina</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>La coda ferma.</strong> Se una ricevuta resta in coda più di un'ora, la pagina lo dice. Di solito sul server non gira il servizio che manda le email in coda: chi gestisce l'installazione controlla che sia attivo.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Le non partite.</strong> Accanto a ogni ricevuta c'è l'errore del server. «Riprova le non partite» le rimanda, dopo una conferma.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Le annullate da comunicare.</strong> L'incasso è stato annullato dopo l'uscita della ricevuta. La ricevuta si ristampa o, se era partita per email, si rimanda: esce con «INCASSO ANNULLATO». Se e come dirlo alla persona lo decidi tu: se non serve, «Non serve comunicarla» toglie l'avviso.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Gli annullati dopo l'avviso di compensazione.</strong> Il programma non rifà l'avviso. Anche qui decidi tu; «Non serve comunicarla» toglie la riga.</span></li>
                                <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span><strong>Le compensazioni senza avviso.</strong> Nel periodo il credito ha coperto le rate di una persona che non riceve la ricevuta di quell'incasso. La sezione propone l'avviso di compensazione: si stampa, o si manda per email a chi ha un indirizzo.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Receipt class="w-4 h-4" /> La ricevuta di un incasso solo</h3>
                        <p>
                            «Apri», nella riga, apre la finestra della ricevuta di quell'incasso: la stampi o la mandi da lì. «invia di nuovo» rimanda l'ultima
                            email della ricevuta, dopo una conferma che dice a quale indirizzo va.
                        </p>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
