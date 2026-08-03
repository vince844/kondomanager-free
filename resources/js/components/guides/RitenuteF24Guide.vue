<script setup lang="ts">
/**
 * La guida in-app delle ritenute e dell'F24.
 *
 * Non ripete cosa fanno i pulsanti: spiega **le due soglie e le tre scadenze**, che sono la
 * parte che l'amministratore oggi ricostruisce a mano e che nessun gestionale gli racconta.
 * È anche il posto dove finisce il testo che prima stava accanto al pulsante di ricalcolo —
 * una spiegazione permanente non va in una riga che si legge una volta sola.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, CalendarClock, Info, Landmark, Percent } from 'lucide-vue-next';

defineProps<{
    open: boolean;
    /** Le soglie vere, dal server: qui non si riscrivono a mano. */
    soglie: { appalti_cents: number; professionisti_cents: number };
}>();

defineEmits(['update:open']);

const soglia = (cents: number) =>
    `€ ${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 }).format(cents / 100)}`;
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="mb-2 flex items-center gap-3">
                        <div class="rounded-lg bg-amber-100 p-2 text-amber-700 dark:bg-amber-900 dark:text-amber-300">
                            <Percent class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: ritenute e F24</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Quando si versa una ritenuta d'acconto, con quale codice tributo, e perché a volte
                        si aspetta e a volte no.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="quando" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-4">
                        <TabsTrigger value="quando">Quando</TabsTrigger>
                        <TabsTrigger value="soglie">Le soglie</TabsTrigger>
                        <TabsTrigger value="delega">La delega</TabsTrigger>
                        <TabsTrigger value="contabilita">Contabilità</TabsTrigger>
                    </TabsList>

                    <!-- TAB: Quando nasce la ritenuta -->
                    <TabsContent value="quando" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">La ritenuta nasce al pagamento</h3>
                            <p>
                                Non alla registrazione della fattura: <strong>all'atto del pagamento</strong>
                                (art. 25-ter DPR 600/1973). È la data in cui paghi il fornitore a decidere il
                                mese di riferimento in F24 e la scadenza del versamento — non la data del
                                documento, non quella di scadenza della fattura.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Dipende dal fornitore, non dalla spesa</h3>
                            <p>
                                L'aliquota discende dalla natura del percipiente: <strong>4%</strong> per gli
                                appalti di opere e servizi, <strong>20%</strong> per i professionisti. Non è il
                                tipo di voce di spesa a stabilirla, ed è inutile cercarla nella fattura.
                            </p>
                            <div class="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                <AlertTriangle class="h-4 w-4 shrink-0" />
                                <p>
                                    Se un fornitore non è configurato come soggetto a ritenuta, nessuna spunta
                                    sulla fattura la applica: va corretta la sua anagrafica. Il flag nel modulo
                                    può solo <em>disattivare</em>, mai attivare.
                                </p>
                            </div>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Chi non la subisce</h3>
                            <p>
                                I fornitori in <strong>regime forfetario</strong> non subiscono ritenuta. E se
                                paghi con <strong>bonifico parlante</strong> per lavori che danno diritto a
                                detrazione, la ritenuta del 4% non si applica: la opera la banca, con
                                un'aliquota sua.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- TAB: Le soglie -->
                    <TabsContent value="soglie" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Perché a volte si aspetta</h3>
                            <p>
                                Il condominio non versa a ogni pagamento: <strong>accumula</strong>, e versa
                                quando il totale raggiunge una soglia. Le soglie sono due, e non si sommano
                                fra loro — sono due adempimenti distinti.
                            </p>
                        </section>

                        <section class="space-y-3">
                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <p class="font-bold text-slate-900 dark:text-slate-100">
                                    Appalti 4% — soglia {{ soglia(soglie.appalti_cents) }}
                                </p>
                                <p class="mt-1 text-xs">
                                    Si versa entro il 16 del mese successivo a quello in cui il cumulo raggiunge
                                    la soglia. A prescindere, si versa comunque entro il
                                    <strong>16 giugno</strong> e il <strong>16 dicembre</strong>.
                                </p>
                            </div>

                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <p class="font-bold text-slate-900 dark:text-slate-100">
                                    Professionisti 20% — soglia {{ soglia(soglie.professionisti_cents) }}
                                </p>
                                <p class="mt-1 text-xs">
                                    Stessa meccanica, ma <strong>una sola</strong> data-limite annuale: il
                                    16 dicembre. Non il 16 giugno.
                                </p>
                            </div>

                            <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <p class="font-bold text-slate-900 dark:text-slate-100">Dicembre fa storia a sé</p>
                                <p class="mt-1 text-xs">
                                    Le ritenute operate a dicembre si versano entro il <strong>16 gennaio</strong>
                                    dell'anno dopo, in entrambi i regimi.
                                </p>
                            </div>
                        </section>

                        <div class="flex gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                            <Info class="h-4 w-4 shrink-0" />
                            <p>
                                Se una scadenza cade di sabato o in giorno festivo slitta al primo giorno
                                lavorativo successivo — festività nazionali comprese, lunedì dell'Angelo incluso.
                            </p>
                        </div>
                    </TabsContent>

                    <!-- TAB: La delega -->
                    <TabsContent value="delega" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">«Aggiorna scadenze»</h3>
                            <p>
                                Ricalcola le deleghe in bozza a partire dai pagamenti registrati. Le bozze non
                                sono documenti: si rifanno da capo ogni volta, quindi premerlo non fa mai danni.
                                Il pulsante <strong>diventa ambra con un numero</strong> quando ci sono ritenute
                                operate che non stanno ancora in nessuna delega — cioè quando premerlo cambia
                                davvero qualcosa.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Il prospetto</h3>
                            <p>
                                La scheda della delega mostra i campi <strong>nell'ordine del modello F24</strong>:
                                codice tributo, rateazione, mese e anno di riferimento, importo. Si trascrivono
                                così come sono nell'home banking o su F24 online. Il pulsante «Copia» li porta
                                negli appunti, «Stampa» produce il foglio da tenere agli atti o da consegnare al
                                commercialista.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Non è il modello ministeriale: quello serve per pagare allo sportello e non è
                                ancora disponibile.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Sei righe per modello</h3>
                            <p>
                                La sezione Erario ne ammette sei. Se le combinazioni di codice tributo, mese e
                                anno sono di più, la delega si spezza automaticamente in più modelli per la
                                stessa scadenza — è previsto dall'Agenzia.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- TAB: Contabilità -->
                    <TabsContent value="contabilita" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Cosa succede quando registri il versamento</h3>
                            <p>Due righe in partita doppia:</p>
                            <div class="mt-2 rounded-lg bg-slate-50 p-3 font-mono text-xs dark:bg-slate-800">
                                <p>DARE&nbsp;&nbsp;2202 Debiti v/Erario per Ritenute</p>
                                <p>AVERE&nbsp;Banca</p>
                            </div>
                            <p class="mt-2">
                                Il debito verso l'Erario si estingue e il denaro esce dal conto. È il movimento
                                che <strong>chiude il conto 2202</strong>: prima di questa versione quel conto
                                accumulava soltanto, perché la riga che lo chiude non esisteva.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Correggere: si storna, non si modifica</h3>
                            <p>
                                Una delega versata non si rettifica. Un versamento all'Erario è un fatto
                                avvenuto: si corregge con uno <strong>storno</strong>, che scrive il movimento
                                uguale e contrario invece di riscrivere il passato. Dopo lo storno le ritenute
                                tornano da versare e rientrano nel prossimo calcolo.
                            </p>
                            <div class="mt-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                <AlertTriangle class="h-4 w-4 shrink-0" />
                                <p>
                                    Lo storno riporta indietro la <em>scrittura</em>. Se l'Erario ha davvero
                                    incassato, il recupero di quel denaro è una pratica a parte.
                                </p>
                            </div>
                        </section>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Landmark class="h-4 w-4" /> Il codice fiscale del condominio
                            </h3>
                            <p>
                                È obbligatorio sull'F24. Se manca, la scheda della delega lo segnala: va inserito
                                nell'anagrafica del condominio, poi basta premere «Aggiorna scadenze».
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <CalendarClock class="h-4 w-4" /> Il promemoria
                            </h3>
                            <p>
                                Registrando un pagamento con ritenuta compare un promemoria nella tua Inbox, con
                                la scadenza già calcolata. Se storni il pagamento, il promemoria si chiude da sé.
                            </p>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
