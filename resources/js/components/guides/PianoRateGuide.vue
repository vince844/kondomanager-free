<script setup lang="ts">
/**
 * La guida in-app della creazione di un piano rate.
 *
 * Questa pagina è quella con più combinazioni di tutto il gestionale: due tipi di piano, tre
 * metodi di distribuzione dei saldi, due modi di scegliere il budget, e un calendario con
 * quattro leve. Le tre card in cima dicono *dove* sono le cose; qui si spiega **quale
 * scegliere e perché**, che è la domanda vera.
 *
 * La regola che governa il testo: non ripetere le etichette dei campi — chi legge le ha già
 * davanti — ma dire il criterio di scelta e le conseguenze che si vedono dopo, quando
 * cambiarle costa.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, CalendarDays, Info, Layers, Wallet } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-emerald-100 p-2 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">
                            <CalendarDays class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: creare un piano rate</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Quale tipo scegliere, cosa fare dei debiti dell'anno scorso, e quando cadono
                        davvero le rate.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="tipo" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-4">
                        <TabsTrigger value="tipo">Tipo</TabsTrigger>
                        <TabsTrigger value="saldi">Saldi</TabsTrigger>
                        <TabsTrigger value="budget">Budget</TabsTrigger>
                        <TabsTrigger value="scadenze">Scadenze</TabsTrigger>
                    </TabsList>

                    <!-- ── TIPO ────────────────────────────────────────────────── -->
                    <TabsContent value="tipo" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Il criterio non è l'importanza della spesa</h3>
                            <p>
                                È l'errore più comune: si sceglie «straordinario» perché la spesa è grossa.
                                Il criterio vero è un altro — <strong>come è registrata la fattura</strong>.
                            </p>
                        </section>

                        <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Piano rate ordinario</h3>
                            <p>
                                Finanzia le <strong>voci di preventivo</strong>: spese già approvate a bilancio,
                                compresi i loro sforamenti. Il rifacimento della facciata deliberato in assemblea e
                                messo a preventivo è ordinario, per quanto costi.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Se per questa gestione hai già emesso il preventivo iniziale, la pagina rietichetta
                                il tipo come <strong>«Piano Rata Integrativa»</strong>: stai aggiungendo, non
                                rifacendo.
                            </p>
                        </section>

                        <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Piano rate straordinario (art. 1135 c.c.)</h3>
                            <p>
                                Finanzia <strong>fatture che a bilancio non ci sono</strong>: l'imprevisto, il guasto,
                                il lavoro ad personam. Non scegli capitoli — scegli le <strong>fatture</strong>, una
                                per una, e dichiari con quale <strong>autorizzazione</strong> le stai finanziando,
                                citando gli estremi del verbale.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                Una spesa imprevista <em>registrata su un capitolo esistente</em> non è
                                straordinaria: è uno sforamento, e si finanzia con un piano ordinario integrativo. Il
                                tipo segue la registrazione contabile, non la sorpresa.
                            </p>
                        </div>
                    </TabsContent>

                    <!-- ── SALDI ───────────────────────────────────────────────── -->
                    <TabsContent value="saldi" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Cosa sono i saldi pregressi</h3>
                            <p>
                                Debiti e crediti che i condòmini si portano dall'anno precedente. Compaiono qui solo
                                se sono <strong>liberi</strong>: quelli già assorbiti da un altro piano non si possono
                                chiedere due volte.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Wallet class="h-4 w-4 text-emerald-600" /> I tre modi di distribuirli
                            </h3>
                            <div class="space-y-3">
                                <p>
                                    <strong class="text-emerald-700 dark:text-emerald-400">Rata separata (Rata 0)</strong> —
                                    consigliata. I pregressi restano una riga a sé, distinta dalle rate dell'anno.
                                    È l'unica che regge un <strong>subentro</strong>: se un appartamento cambia
                                    proprietario a metà anno si vede subito cosa spetta a chi.
                                </p>
                                <p>
                                    <strong>Somma alla prima rata</strong> — metodo tradizionale. La prima rata
                                    diventa più pesante e il condòmino non distingue più il vecchio dal nuovo.
                                </p>
                                <p>
                                    <strong>Spalma su tutte le rate</strong> — sconsigliata: maschera l'importo reale
                                    della gestione corrente, e a fine anno nessuno sa più quanto costava davvero.
                                </p>
                            </div>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Nominali e solidali</h3>
                            <p>
                                I saldi <strong>nominali</strong> seguono la persona. Quelli <strong>solidali</strong>
                                (art. 63 disp. att. c.c.) seguono l'<strong>unità immobiliare</strong>: restano
                                attaccati all'appartamento anche se cambia il proprietario, ed è il motivo per cui il
                                gestionale li tiene separati invece di sommarli.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── BUDGET ──────────────────────────────────────────────── -->
                    <TabsContent value="budget" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Layers class="h-4 w-4 text-blue-600" /> Tutto, o solo una parte
                            </h3>
                            <p>
                                <strong>Lasciando vuoto</strong> il campo delle voci, il piano finanzia
                                <strong>tutto il preventivo</strong> della gestione. È il caso normale.
                            </p>
                            <p class="mt-2">
                                <strong>Scegliendo delle voci</strong>, ne finanzi solo alcune — per esempio il solo
                                riscaldamento — e per ciascuna puoi chiedere un <strong>importo parziale</strong>
                                invece dell'intero.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                            <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                Una voce già finanziata da un altro piano attivo non è riselezionabile, e vale per
                                tutto il suo ramo: prendendo un capitolo prendi anche i suoi sottoconti. Serve a non
                                chiedere due volte gli stessi soldi.
                            </p>
                        </div>
                    </TabsContent>

                    <!-- ── SCADENZE ────────────────────────────────────────────── -->
                    <TabsContent value="scadenze" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Da dove parte il calendario</h3>
                            <p>
                                Lasciando vuota <strong>Prima scadenza</strong>, il piano parte dall'inizio della
                                gestione — ed è una scelta, non un ripiego: quel piano <strong>continua a seguire la
                                gestione</strong> anche se un domani la sua data cambia.
                            </p>
                            <p class="mt-2">
                                Indicando una data, la prima rata cade <strong>esattamente lì, con il suo giorno</strong>:
                                il 30 settembre resta il 30. Dalla seconda in poi comanda il <strong>giorno del
                                mese</strong>.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Genera subito, o dopo</h3>
                            <p>
                                Con <strong>«Genera calcolo scadenze subito»</strong> le rate nascono insieme al
                                piano. Togliendo la spunta il piano resta senza rate, e le generi quando il preventivo
                                è definitivo.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Ricorrenza avanzata</h3>
                            <p>
                                Serve quando «una rata al mese al giorno X» non basta: bimestrale, trimestrale, o
                                agganciata a un giorno della settimana. Se non ti serve, non aprirla — il calendario
                                mensile semplice copre la quasi totalità dei casi.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <p class="text-xs text-amber-900 dark:text-amber-200">
                                Cambiare le date dopo aver generato le rate significa <strong>ricalcolare il
                                piano</strong>, che le rifà da capo. Finché il piano non è emesso in contabilità non
                                costa nulla; dopo l'emissione le regole cambiano, e conviene decidere il calendario
                                adesso.
                            </p>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
