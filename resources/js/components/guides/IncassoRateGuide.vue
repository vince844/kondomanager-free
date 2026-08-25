<script setup lang="ts">
/**
 * La guida in-app della registrazione di un incasso rate.
 *
 * Perché esiste (beta.49). Questa schermata ha trenta comandi e una dozzina di comportamenti che
 * dal nome non si deducono: «Paga tutto» paga solo le righe visibili, «Mostra scadute» nasconde
 * invece di evidenziare, «Resetta» lascia la pagina in manuale, «Usa credito» premuto a mano
 * rifà l'intera distribuzione. Le tre schede in cima alla pagina non ci stavano — erano già le
 * più lunghe del gestionale — e occupavano 264 px della colonna che serve a vedere le rate.
 * Sono state assorbite qui dentro, dove il testo non costa spazio.
 *
 * La regola del testo, la stessa di PianoRateGuide: non ripetere le etichette dei campi — chi
 * legge le ha davanti — ma dire cosa succede davvero quando li si tocca, e in quale ordine.
 *
 * ⚠️ Ogni affermazione di questo file è stata verificata sul codice il 12/08/2026. Alcune
 * descrivono comportamenti che sono difetti aperti, non scelte: sono segnati «per ora» e hanno
 * una voce in roadmap. Se li correggi, questo file va corretto con loro.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, Info, Receipt, Search, Wallet } from 'lucide-vue-next';

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
                            <Receipt class="h-6 w-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registrare un incasso</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Chi paga, come si distribuisce l'importo, quando entra in gioco il credito e
                        cosa viene scritto davvero alla conferma.
                    </SheetDescription>
                </SheetHeader>

                <Tabs default-value="ricerca" class="w-full">
                    <TabsList class="mb-6 grid w-full grid-cols-4">
                        <TabsTrigger value="ricerca">Chi paga</TabsTrigger>
                        <TabsTrigger value="distribuire">Distribuire</TabsTrigger>
                        <TabsTrigger value="credito">Credito</TabsTrigger>
                        <TabsTrigger value="conferma">Conferma</TabsTrigger>
                    </TabsList>

                    <!-- ── CHI PAGA ────────────────────────────────────────────── -->
                    <TabsContent value="ricerca" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Search class="h-4 w-4 text-emerald-600" /> Le due ricerche non sono equivalenti
                            </h3>
                            <p>
                                Cercando <strong>per persona</strong>, chi scegli è insieme il soggetto della ricerca
                                e il pagante: ogni riga contiene solo le sue quote, e il residuo che leggi è davvero
                                il suo.
                            </p>
                            <p class="mt-2">
                                Cercando <strong>per immobile</strong>, la riga somma le quote di
                                <strong>tutti gli intestatari dell'unità</strong> — proprietario, inquilino,
                                usufruttuario — e il residuo è la loro somma algebrica. Il pagante non è dedotto:
                                lo scegli a parte, in «Intestatario della ricevuta».
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p>
                                    In ricerca per immobile, finché non scegli l'intestatario il pulsante
                                    <strong>«Usa credito» non compare su nessuna riga</strong> e al suo posto trovi
                                    «N/D». Non manca il credito: manca la persona a cui attribuirlo.
                                </p>
                                <p>
                                    Allocando su una riga che porta anche il debito di un altro, quello che eccede
                                    la quota del pagante diventa un <strong>suo anticipo</strong>, e il debito
                                    altrui resta aperto. Se su quella rata il pagante non ha proprio nessuna quota,
                                    l'incasso viene <strong>rifiutato</strong> e il motivo compare sopra il pulsante
                                    di conferma.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Vedere com'è fatta una riga</h3>
                            <p>
                                L'icona <strong>«i»</strong> accanto al nome della gestione apre l'unico punto in cui
                                si legge la composizione reale: unità, nominativo, ruolo, quota e netto, una voce per
                                ciascun intestatario. In ricerca per immobile è lì che si scopre che la riga contiene
                                persone diverse.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">L'intestatario è il pagante</h3>
                            <p>
                                Non è solo il nome sulla ricevuta: decide quali quote vengono saldate, quale credito è
                                utilizzabile e a chi va accreditato l'eventuale anticipo. L'elenco propone
                                <strong>tutte</strong> le anagrafiche con un'unità nel condominio, non i soli
                                intestatari dell'unità scelta.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Cambiando persona a compilazione avviata la distribuzione viene buttata via: era
                                costruita per un altro. Cambiando scheda «Persona»/«Immobile» si azzerano pagante,
                                unità, elenco e importo — ma non cassa, data, causale, filtro gestione e «Mostra
                                scadute», che restano attivi sulla ricerca successiva.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── DISTRIBUIRE ─────────────────────────────────────────── -->
                    <TabsContent value="distribuire" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Automatico e manuale</h3>
                            <p>
                                In <strong>automatico</strong> i campi delle righe sono spenti e la cifra si spalma
                                partendo dalla rata <strong>più vecchia</strong> — la più vecchia, non la più
                                scaduta — riempiendone il residuo prima di passare alla successiva.
                            </p>
                            <p class="mt-2">
                                In <strong>manuale</strong> scrivi riga per riga, e ogni valore viene tagliato al
                                residuo di quella riga. Appena tocchi una riga, «Importo versato» in alto viene
                                <strong>riscritto</strong> con la somma di tutte le righe.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Conseguenza pratica: non puoi scrivere prima l'importo ricevuto e poi distribuirne
                                solo una parte. Un anticipo voluto si ottiene alzando l'importo in alto
                                <strong>dopo</strong> aver finito con le righe.
                            </p>
                        </section>

                        <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">«Paga scadute» e «Paga tutto»</h3>
                            <p>
                                Riempiono le righe e <strong>sovrascrivono «Importo versato»</strong> con la somma
                                ottenuta: non chiedono quanto hai ricevuto, dichiarano di aver ricevuto quella cifra.
                                Passano anche la pagina in manuale.
                            </p>
                            <p class="mt-2">
                                Se hai incassato meno, <strong>correggi le righe una per una</strong>: l'importo in
                                alto le segue da sé. Abbassarlo dall'alto non ridistribuisce niente, e l'incasso
                                viene rifiutato perché i conti non tornano.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <div class="space-y-2 text-xs text-amber-900 dark:text-amber-200">
                                <p>
                                    <strong>«Paga tutto» non paga tutto il dovuto:</strong> opera solo sulle righe
                                    visibili in quel momento, quindi risente del filtro gestione e di «Mostra
                                    scadute». Vale anche per «Debito totale» in cima all'elenco.
                                </p>
                                <p>
                                    Entrambi i pulsanti <strong>azzerano i crediti già applicati</strong>: se hai
                                    appena premuto «Usa credito», quel credito viene rilasciato.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">«Resetta» non riporta all'inizio</h3>
                            <p>
                                Svuota l'importo e azzera tutte le righe, ma lascia la pagina in
                                <strong>manuale</strong>. Da lì digitare un importo non alloca più niente: tutta la
                                cifra diventa anticipo e la conferma viene rifiutata. Per tornare a distribuire
                                riclicca <strong>«Automatico»</strong>.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">I due filtri dell'elenco</h3>
                            <p>
                                <strong>«Mostra scadute»</strong>, nonostante il nome, non evidenzia: <strong>nasconde
                                tutto il resto</strong>. Restano le righe già scadute più quelle che nella descrizione
                                contengono «saldo», «rata 0» o «pregresso».
                            </p>
                            <p class="mt-2">
                                Il menu <strong>«Gestione»</strong> filtra l'elenco, ma non è solo un filtro: il
                                valore viene salvato e diventa la gestione della scrittura contabile. Lasciandolo su
                                «Tutte» la gestione viene dedotta dalla prima rata pagata.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Toccare uno dei due ricostruisce l'elenco da capo: le allocazioni fatte a mano si
                                perdono. In automatico la distribuzione si rifà da sola, in manuale no.
                            </p>
                        </section>
                    </TabsContent>

                    <!-- ── CREDITO ─────────────────────────────────────────────── -->
                    <TabsContent value="credito" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-3 flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <Wallet class="h-4 w-4 text-blue-600" /> Il credito non si usa da solo
                            </h3>
                            <p>
                                Nessun credito viene impiegato finché non premi <strong>«Usa credito»</strong> sulla
                                riga che lo porta. Il pulsante non applica l'importo da sé: marca la riga e lascia
                                fare alla distribuzione automatica, impegnando il credito
                                <strong>per la parte che il contante lascia scoperta</strong>. Un secondo clic lo
                                rilascia.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Premendolo mentre sei in manuale, la pagina <strong>torna in automatico</strong> e
                                rifà tutta la distribuzione: gli importi scritti a mano vengono ricalcolati.
                            </p>
                        </section>

                        <section class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Le righe «saldo misto»</h3>
                            <p>
                                Sono rate in cui un debito e un credito <strong>della stessa rata</strong> si
                                annullano: il netto è zero, ma dentro ci sono due quote distinte. Su queste righe non
                                esiste il campo importo — l'unica azione possibile è prelevarne il credito.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                Spendendo altrove quel credito, il debito che stava annullando
                                <strong>riemerge</strong>: la rata che oggi risulta a saldo tornerà a debito. È
                                corretto, ma conviene saperlo prima.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Compensare fra gestioni diverse</h3>
                            <p>
                                Se il credito che stai prelevando appartiene a una gestione di cui non stai pagando
                                debiti, compare una fascia ambra con una spunta di conferma: finché non la metti,
                                <strong>«Conferma incasso» resta spento</strong>. Serve a rendere deliberato uno
                                spostamento fra ordinaria e straordinaria.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                            <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                            <p class="text-xs text-slate-600 dark:text-slate-400">
                                Il badge <strong>«Credito attivo»</strong> descrive i crediti come «automaticamente
                                attivi e pronti per la compensazione». Per ora la scritta promette più di quanto
                                accada: segnala che su quella riga c'è un credito, non che verrà usato. Va comunque
                                premuto «Usa credito».
                            </p>
                        </div>
                    </TabsContent>

                    <!-- ── CONFERMA ────────────────────────────────────────────── -->
                    <TabsContent value="conferma" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">«Totale allocato» non è il debito che stai chiudendo</h3>
                            <p>
                                È una somma <strong>algebrica</strong>: le righe a credito viaggiano negative e la
                                abbassano. Con €&nbsp;300,00 di debiti, €&nbsp;100,00 di contante e €&nbsp;200,00 di credito il
                                pannello scrive <strong>€&nbsp;100,00</strong>, pur estinguendo €&nbsp;300,00. In pratica
                                misura il contante allocato.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Cosa mostra l'anteprima</h3>
                            <p>
                                L'effetto <strong>sulle rate</strong>, riga per riga: saldata, parziale con quanto
                                resta, credito usato con quanto ne rimane. Non è un'anteprima in partita doppia — non
                                mostra i conti, il dare e l'avere, né la riga di cassa.
                            </p>
                            <p class="mt-2 text-xs text-slate-500">
                                «Resta da pagare» è calcolato sul residuo dell'<strong>intera riga</strong>: in
                                ricerca per immobile può indicare una cifra che al pagante non compete.
                            </p>
                        </section>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">L'anticipo</h3>
                            <p>
                                Il contante che avanza dopo aver coperto le righe viene registrato come
                                strapagamento sull'ultima quota toccata, così <strong>ricompare come credito</strong>
                                del condòmino, riutilizzabile al prossimo incasso.
                            </p>
                        </section>

                        <div class="flex items-start gap-3 rounded-lg bg-slate-50 p-4 dark:bg-slate-800/50">
                            <Info class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />
                            <div class="space-y-2 text-xs text-slate-600 dark:text-slate-400">
                                <p>
                                    Un incasso <strong>interamente compensato a credito</strong> è ammesso: l'importo
                                    versato resta €&nbsp;0,00 e in cassa non entra nulla. Nella lista degli incassi
                                    comparirà con importo €&nbsp;0,00, perché non ha una riga di cassa.
                                </p>
                                <p>
                                    Il badge <strong>«No emissione»</strong> avvisa che la rata non ha ancora la sua
                                    scrittura di emissione, ma non impedisce di incassarla.
                                </p>
                                <p>
                                    Se il server rifiuta, il motivo compare nella fascia rossa sopra il pulsante e
                                    <strong>la compilazione resta in piedi</strong>: si corregge e si riprova, senza
                                    rifare la distribuzione.
                                </p>
                            </div>
                        </div>

                        <section>
                            <h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Dopo la conferma</h3>
                            <p>
                                Le scadenze del condòmino vengono aggiornate a saldata, parziale o da pagare, e le
                                attività di verifica collegate si chiudono da sole nella posta interna — compresa
                                quella generale della rata, quando risulta saldata da tutto il condominio.
                            </p>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </SheetContent>
    </Sheet>
</template>
