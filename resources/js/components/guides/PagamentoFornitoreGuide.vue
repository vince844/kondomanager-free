<script setup lang="ts">
/**
 * La guida in-app della registrazione di un pagamento a fornitore.
 *
 * ## Perché esiste (beta.67)
 *
 * Questa schermata fa **cinque** cose che dal nome non si deducono: usa le note di credito per
 * coprire una fattura, si ferma se l'IBAN non è quello in anagrafica, si ferma se il conto non ha
 * capienza, si ferma se sembra un pagamento già fatto, e chiede una ratifica se la spesa aveva
 * sforato il budget. Nessuna di queste era spiegata da nessuna parte.
 *
 * Le tre schede in cima si chiamavano «Ledger Esecutivo», «Smart Netting» e «Sentinella
 * Anti-Frode»: nomi che non dicono a un amministratore né cosa fa la pagina né cosa gli succede se
 * preme un pulsante. Sono state riscritte in italiano, ma tre righe non bastano per cinque
 * comportamenti — da qui questa guida, dove il testo non costa spazio.
 *
 * ## La regola del testo
 *
 * La stessa di `IncassoRateGuide` e `PianoRateGuide`: **non ripetere le etichette dei campi** — chi
 * legge le ha davanti — ma dire cosa succede davvero quando li si tocca, e in quale ordine.
 *
 * ⚠️ **Ogni affermazione di questo file è stata verificata sul codice il 22/08/2026**, in
 * `PagamentoFornitoreService` e in `PagamentoNew.vue`. Le cifre citate (il limite dei contanti, le
 * ventiquattro ore del controllo duplicati) sono lette nel codice, non ricordate. Se cambi uno di
 * quei comportamenti, questo file va corretto insieme.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, Banknote, Info, ReceiptText, ShieldCheck, Sparkles } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-indigo-100 p-2 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">
                            <Banknote class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: pagare un fornitore</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Come si compone un pagamento, cosa fanno le note di credito, e i quattro
                        controlli che possono fermarti prima della conferma.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="comporre" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-4">
                        <TabsTrigger value="comporre">Comporre</TabsTrigger>
                        <TabsTrigger value="credito">Note di credito</TabsTrigger>
                        <TabsTrigger value="controlli">Controlli</TabsTrigger>
                        <TabsTrigger value="conferma">Cosa registra</TabsTrigger>
                    </TabsList>

                    <!-- ── COMPORRE ────────────────────────────────────────────── -->
                    <TabsContent value="comporre" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <ReceiptText class="h-4 w-4 text-indigo-600" /> Un pagamento, più documenti
                            </h3>
                            <p>
                                Scegli il fornitore a sinistra: a destra compaiono <strong>tutti</strong> i suoi
                                documenti aperti in questo condominio — le fatture da pagare e le note di credito da
                                usare. Un solo pagamento può chiuderne più d'uno: spunti quelli che ti servono e
                                scrivi, su ciascuno, quanto ci stai mettendo sopra.
                            </p>
                            <p class="mt-2">
                                L'importo che scrivi su una fattura può essere <strong>meno del suo residuo</strong>:
                                la fattura resta aperta per la differenza e passa allo stato «parziale». Non serve
                                saldarla tutta in una volta.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">I tre numeri in basso non dicono la stessa cosa</h3>
                            <p>
                                <strong>Totale documenti</strong> è quanto stai chiudendo di debito.
                                <strong>Note di credito</strong> è la parte di quel debito coperta dal credito che il
                                fornitore ti deve. <strong>Bonifico</strong> è quello che esce davvero dal conto
                                corrente — la differenza fra i due.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Quando le note coprono tutto, il bonifico è € 0,00 e il pagamento si registra lo
                                stesso: è una compensazione, e in banca non si muove niente.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Le commissioni non chiudono debito</h3>
                            <p>
                                Le spese bancarie che scrivi si sommano all'uscita di cassa ma non vanno sul debito
                                verso il fornitore: finiscono su un conto di spesa a parte. È il motivo per cui il
                                totale che esce dal conto può essere più alto della somma delle fatture.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── NOTE DI CREDITO ─────────────────────────────────────── -->
                    <TabsContent value="credito" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Sparkles class="h-4 w-4 text-amber-600" /> Cos'è, in una riga
                            </h3>
                            <p>
                                Una nota di credito è un documento con cui il fornitore <strong>ti restituisce</strong>
                                qualcosa: uno storno, un lavoro rifatto, un errore in fattura. Non è denaro che entra
                                in cassa — è un credito che puoi usare per pagare meno la volta dopo.
                            </p>
                            <p class="mt-2">
                                Usarla qui vuol dire questo: la fattura si chiude per intero — il fornitore non è più
                                a credito verso il condominio per quella cifra — ma dal conto corrente esce solo la
                                differenza. Il debito si estingue in due pezzi: uno pagato, uno compensato.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Cosa fa il pulsante «Usa le note di credito»</h3>
                            <p>Tre cose, in quest'ordine:</p>
                            <ol class="mt-2 ml-5 list-decimal space-y-1.5">
                                <li>seleziona tutte le fatture <strong>approvate</strong> del fornitore, per il loro residuo;</li>
                                <li>distribuisce il credito delle note su quelle fatture, partendo dalle <strong>più scadute</strong>;</li>
                                <li>ricalcola il bonifico, che diventa la parte non coperta.</li>
                            </ol>
                            <p class="mt-2">
                                Non registra niente: è una proposta. Ogni importo resta modificabile a mano, e finché
                                non premi «Registra pagamento» nel condominio non cambia nulla.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Le fatture in attesa di ratifica per sforo di budget non vengono selezionate: prima
                                vanno approvate, con il pulsante sulla loro riga.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20">
                            <Info class="mt-0.5 h-5 w-5 shrink-0 text-blue-600" />
                            <div class="space-y-2 text-xs text-blue-900 dark:text-blue-200">
                                <p>
                                    <strong>Se la nota vale più delle fatture che hai scelto</strong>, l'eccedenza non
                                    si può usare in questo pagamento: il programma te lo scrive sotto i totali e la
                                    lascia sulla nota, che resta «parziale» e sarà usabile la prossima volta.
                                </p>
                                <p>
                                    Non è una limitazione tecnica: un credito si può usare solo contro un debito che
                                    esiste. Il resto non si perde.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Dove si controlla cosa verrà scritto</h3>
                            <p>
                                Nel pannello scuro in basso a destra, «Dettaglio allocazioni»: è l'elenco esatto delle
                                righe che stanno per essere registrate. Una fattura coperta in parte compare
                                <strong>due volte</strong> — una riga «Pagamento» per la parte che esce dalla banca e
                                una «Compensazione» per la parte coperta dalla nota. Non è un doppione: è il modo in
                                cui la partita doppia tiene distinte le due cose.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── CONTROLLI ───────────────────────────────────────────── -->
                    <TabsContent value="controlli" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <p>
                            Quattro controlli possono fermare la registrazione. Tre si superano confermando, uno no.
                        </p>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <ShieldCheck class="h-4 w-4 text-emerald-600" /> L'IBAN non è quello in anagrafica
                            </h3>
                            <p>
                                Se il fornitore ha un IBAN registrato e quello che stai per usare è diverso, il
                                programma si ferma e mostra tutti e due. È il controllo che intercetta la truffa più
                                comune del settore: una mail che comunica un «nuovo IBAN» del fornitore. Se il cambio
                                è vero, confermi e prosegui — ma dopo aver telefonato al fornitore, non dopo aver
                                riletto la mail.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Sembra un pagamento già fatto</h3>
                            <p>
                                Stessa fattura, stesso importo, stesso tipo, entro <strong>ventiquattro ore</strong>:
                                il programma te lo segnala prima di scrivere. Capita di premere due volte, o di
                                registrare qualcosa che aveva già registrato un collega.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Il conto non ha capienza</h3>
                            <p>
                                Se l'uscita supera il saldo del conto scelto, il pagamento è bloccato e il messaggio
                                dice di quanto. Si può procedere lo stesso — un conto può andare in rosso — ma è una
                                decisione che va presa, non subita.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p>
                                    <strong>Contanti da € 5.000,00 in su: vietati, e non si superano.</strong> Non è
                                    una scelta del programma, è il limite del D.Lgs. 231/2007 sull'uso del contante.
                                    L'unico modo di procedere è cambiare metodo di pagamento.
                                </p>
                                <p>
                                    <strong>Allocare più del residuo</strong> di una fattura è possibile, ma richiede
                                    di scrivere il perché, e quel testo resta agli atti del pagamento.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">La spesa aveva sforato il budget</h3>
                            <p>
                                Una fattura registrata sopra il preventivo del suo capitolo resta in attesa: sulla sua
                                riga compare «Ratifica richiesta» e non è pagabile finché non usi «Approva sforo»,
                                dove si registra la delibera assembleare o la motivazione d'urgenza dell'art. 1135
                                c.c. Non è un cavillo del programma: è la traccia che serve al rendiconto per
                                spiegare perché si è speso più di quanto approvato.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── COSA REGISTRA ───────────────────────────────────────── -->
                    <TabsContent value="conferma" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Cosa succede alla conferma</h3>
                            <p>
                                Viene creata <strong>una scrittura in partita doppia</strong>: si chiude il debito
                                verso il fornitore per il totale dei documenti, esce dalla banca il solo bonifico, e
                                il credito delle note viene consumato. Le fatture toccate passano a «pagata» o
                                «parziale» a seconda di quanto resta.
                            </p>
                            <p class="mt-2">
                                Tutto avviene in un colpo solo: se una qualsiasi parte non quadra, non viene scritto
                                niente. Non esistono pagamenti registrati a metà.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Se c'è una ritenuta d'acconto</h3>
                            <p>
                                Sui fornitori soggetti a ritenuta il bonifico è il <strong>netto</strong>: la
                                trattenuta non esce dal conto adesso, resta come debito verso l'erario e si versa con
                                il modello F24. Il fornitore risulta comunque pagato per intero.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Bonifico parlante</h3>
                            <p>
                                Da spuntare quando la spesa dà diritto a una detrazione fiscale (art. 16-bis TUIR).
                                Serve a generare la causale nella forma che la banca deve trasmettere all'Agenzia
                                delle Entrate, con il tipo di detrazione e i condòmini beneficiari. Senza quella
                                causale la detrazione si perde, e non è recuperabile dopo.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Se hai sbagliato</h3>
                            <p>
                                Un pagamento registrato non si cancella: si <strong>storna</strong>, cioè si registra
                                una scrittura uguale e contraria che lo annulla lasciando entrambe visibili. È la
                                regola di tutta la contabilità del programma, e serve a chi legge il rendiconto un
                                anno dopo.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Un pagamento su una sola fattura si può anche modificare; uno cumulativo su più
                                fatture no — lì si storna e si rifà.
                            </p>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
