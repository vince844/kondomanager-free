<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FileText, Info, AlertTriangle, Zap, Home, History, Copy } from 'lucide-vue-next';

defineProps<{
    open: boolean;
}>();

defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-emerald-100 text-emerald-700 rounded-lg dark:bg-emerald-900 dark:text-emerald-300">
              <FileText class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Registrazione fattura</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Questo modulo fa molto più della fattura ordinaria: debiti degli esercizi passati, spese sul
            singolo immobile, imprevisti fuori preventivo e sforamenti coperti dal fondo. Ecco tutte le strade.
          </SheetDescription>
        </SheetHeader>

        <Tabs default-value="base" class="w-full">
          <TabsList class="grid w-full grid-cols-4 mb-6">
            <TabsTrigger value="base">Percorso base</TabsTrigger>
            <TabsTrigger value="speciali">Casi speciali</TabsTrigger>
            <TabsTrigger value="sforo">Sforo budget</TabsTrigger>
            <TabsTrigger value="consigli">Consigli</TabsTrigger>
          </TabsList>

          <!-- TAB: Percorso base -->
          <TabsContent value="base" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <!-- Le tre spiegazioni dell'importazione XML vivono qui dalla beta.14
                 (03/09/2026): stavano in testa alla pagina come tre schede, ma occupavano
                 la prima schermata anche a chi stava solo registrando una fattura a mano.
                 Deciso con Vincenzo: «lo scriviamo nella guida dell'header». -->
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Partire dal file XML invece che dai campi</h3>
              <p class="mb-3">
                Se hai il file che il fornitore ha mandato allo SdI, non c'è niente da ricopiare: il pulsante
                <strong>«Importa XML»</strong> in testa al modulo apre il lettore, e i campi si riempiono da soli.
                Puoi caricarne <strong>molti insieme</strong> — li leggo tutti e ti presento l'elenco, poi scegli
                quale registrare per primo. Dopo ogni registrazione ti ricordo quali restano.
              </p>
              <ul class="space-y-2 list-disc pl-5">
                <li><strong>Lettura automatica</strong>: numero, data, importi, scadenza, IBAN, righe di dettaglio
                    e ritenuta d'acconto arrivano dal file così come il fornitore li ha dichiarati.</li>
                <li><strong>Fornitore riconosciuto</strong>: agganciato per partita IVA o codice fiscale. Se non
                    è ancora in anagrafica lo crei da lì, senza lasciare la pagina e senza ricaricare il file.</li>
                <li><strong>Capitolo proposto</strong>: suggerito dall'ultima fattura registrata per quello stesso
                    fornitore, invece di sceglierlo ogni volta da zero. Resta sempre modificabile.</li>
                <li>Il file letto diventa anche l'<strong>allegato</strong> della fattura: non serve caricarlo
                    una seconda volta. Il PDF, se lo vuoi accanto all'XML, si allega dal Dettaglio fattura.</li>
              </ul>
              <p class="mt-3">
                Una fattura intestata a un <strong>altro condominio</strong> viene rifiutata dicendotelo, invece di
                entrare per sbaglio nei conti di questo: il confronto è sul codice fiscale del destinatario
                dichiarato nel file. Se il file è già stato letto e nel modulo hai scritto qualcosa a mano, ti
                chiedo conferma prima di sostituirlo.
              </p>
            </section>

            <!-- ⚠️ Aggiunta chiudendo la beta.14: le tre cose che riguardano il DENARO.
                 Stanno nella guida e non a schermo perché a schermo compaiono solo quando
                 servono — un avviso che c'è sempre smette di essere letto in una settimana. -->
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ritenuta e cassa previdenziale: cosa faccio e cosa chiedo a te</h3>
              <p class="mb-3">
                Sono le tre cose del file che spostano davvero dei soldi, e su tutte e tre l'ultima parola resta a te.
              </p>
              <ul class="space-y-2 list-disc pl-5">
                <li><strong>Ogni riga ha una casella «Concorre alla base ritenuta»</strong>, spuntata di default:
                    decide se quell'importo entra nel calcolo. Lascia spuntata la riga del compenso vero e proprio;
                    togli la spunta a ciò che non è reddito del professionista — un contributo di cassa
                    previdenziale, un rimborso spese documentato (art. 15 TUIR). Vale anche se stai scrivendo la
                    fattura a mano, non solo quando arriva dal file.</li>
                <li><strong>Il contributo di cassa previdenziale</strong> — il 4 o 5% che un geometra, un ingegnere
                    o un avvocato addebita in parcella — entra come riga di spesa con la sua IVA, perché è parte del
                    corrispettivo. E se quel contributo concorre alla base della ritenuta lo dice il documento
                    stesso: leggo quello, invece di deciderlo io.</li>
                <li><strong>Se il file dichiara una ritenuta e il fornitore in anagrafica non è segnato come
                    soggetto</strong>, te lo dico e ti mostro i due importi uno sotto l'altro. Non blocco e non
                    correggo niente: l'anagrafica descrive il fornitore <em>oggi</em>, il file descrive
                    <em>quel</em> documento, e la differenza può essere una casella dimenticata come una fattura di
                    soli materiali. Decidi tu.</li>
                <li><strong>Vale anche al contrario</strong>: se io tratterrei una ritenuta e il file non ne
                    dichiara nessuna, te lo dico ugualmente. Che il fornitore non la scriva non vuol dire che non sia
                    dovuta — l'obbligo di versarla è del condominio, non suo.</li>
                <li><strong>Un contributo previdenziale non è una ritenuta d'acconto.</strong> Se il file ne dichiara
                    uno (INPS, ENASARCO, ENPAM) te lo nomino a parte: quello lo versa il fornitore al proprio ente,
                    non tu con l'F24.</li>
              </ul>
              <p class="mt-3">
                Creando un fornitore dal file, se l'aliquota dichiarata è chiara e i conti tornano ti propongo già
                spuntata la casella della ritenuta e il regime giusto — <strong>proposti</strong>: li togli o li
                cambi prima di salvare. Quando l'aliquota non basta a stabilire il regime lascio la scelta a te e ti
                dico perché.
              </p>
            </section>

            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Panel + Ledger: dati a sinistra, voci a destra</h3>
              <p class="mb-3">
                A sinistra i dati del documento (fornitore, numero, date, conto di addebito, allegato
                PDF/XML/P7M); a destra il <strong>registro voci di spesa</strong>: una o più righe, ognuna
                agganciata a un capitolo del preventivo, con il proprio imponibile e la propria aliquota IVA.
                Alla registrazione nasce la scrittura in partita doppia con protocollo <strong>FTP</strong>
                e il debito verso il fornitore entra nello scadenziario.
              </p>
              <ul class="space-y-2 list-disc pl-5">
                <li><strong>Fattura / Nota credito</strong>: il selettore in alto a sinistra. La nota di credito
                    inverte i segni contabili — la usi per stornare importi a favore del condominio.</li>
                <li><strong>Ritenuta d'acconto</strong>: se il fornitore è soggetto a ritenuta (dalla sua
                    anagrafica), il modulo separa da solo il netto da pagare dalla quota per l'Erario.</li>
                <li><strong>IVA a zero</strong>: perfettamente ammessa — professionisti in regime forfetario,
                    commissioni bancarie, bolli. Scrivi 0 nella colonna %.</li>
                <li>Ogni capitolo mostra il <strong>residuo di budget</strong> nel menu di scelta: il confronto
                    è sempre sul lordo (imponibile + IVA), la stessa base del preventivo.</li>
              </ul>
            </section>
            <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
              <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                Il pannello scuro <strong>"Simulazione impatto finanziario"</strong> si aggiorna a ogni tasto:
                a sinistra l'effetto sul budget di ogni capitolo toccato, a destra la previsione di cassa sul
                conto scelto. Se qualcosa non torna, lo vedi <em>prima</em> di registrare, non dopo.
                I totali sono calcolati al centesimo con le stesse regole della registrazione, ritenuta
                compresa: il <strong>netto da pagare</strong> che leggi qui è quello che troverai nell'elenco
                fatture dopo il salvataggio.
              </div>
            </div>
            <div class="p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
              <Copy class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                Mentre compili, il sistema controlla da solo se una fattura simile esiste già — stesso
                fornitore e stesso numero nello stesso esercizio, oppure stesso importo al centesimo entro
                una settimana. Se trova qualcosa te lo dice con un avviso sotto le date, ma <strong>non ti
                blocca mai</strong>: decidi tu se è davvero un doppione o una coincidenza (una manutenzione
                ricorrente allo stesso importo, per esempio). Lo stesso avviso compare anche riaprendo una
                fattura in Modifica.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Casi speciali -->
          <TabsContent value="speciali" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <div class="flex items-center gap-2 mb-2">
                <History class="w-4 h-4 text-slate-500" />
                <h3 class="font-bold text-slate-900 dark:text-white">Debito di un esercizio precedente (pregresso)</h3>
              </div>
              <p class="mb-2">
                La spunta <strong>"Debito esercizio precedente"</strong> (si attiva anche da sola se la data
                documento è anteriore all'esercizio) cambia natura alla registrazione: la fattura
                <strong>non tocca il budget dell'anno in corso</strong> e va coperta con le risorse di allora —
                il debito patrimoniale dei saldi iniziali, la capienza della rata zero, o altri fondi.
                Il radar anti-duplicati ti avvisa se una fattura simile risulta già registrata.
              </p>
            </section>
            <section>
              <div class="flex items-center gap-2 mb-2">
                <Home class="w-4 h-4 text-slate-500" />
                <h3 class="font-bold text-slate-900 dark:text-white">Spesa sul singolo immobile (Art. 63)</h3>
              </div>
              <p class="mb-2">
                La colonna <strong>"Unità"</strong> della riga, di solito su "Tutti (Spesa Comune)", permette di
                addebitare la voce a <strong>un solo immobile</strong>: è l'anticipazione ad personam — la spesa
                non pesa sui capitoli comuni né sul loro budget, ma diventa un credito del condominio verso quel
                condòmino, che la ritroverà nel suo estratto conto.
              </p>
            </section>
            <section>
              <div class="flex items-center gap-2 mb-2">
                <Zap class="w-4 h-4 text-amber-500" />
                <h3 class="font-bold text-slate-900 dark:text-white">Fuori preventivo (spesa imprevista)</h3>
              </div>
              <p>
                Il pulsante <strong>"Fuori Preventivo"</strong> sulla riga serve quando la spesa
                <strong>non ha alcun capitolo</strong> a preventivo — la tubazione che esplode di notte.
                Il sistema crea al volo la voce fra le sopravvenienze e ti chiede la motivazione d'urgenza
                (Art. 1135 c.c.): quella motivazione è lo <strong>scudo legale</strong> che resta agli atti
                per l'assemblea. Non confonderlo con lo sforo: se il capitolo esiste ma è incapiente, lascia
                la riga sul capitolo e gestisci lo sforamento (scheda successiva).
              </p>
            </section>
          </TabsContent>

          <!-- TAB: Sforo budget -->
          <TabsContent value="sforo" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Quando il capitolo non basta</h3>
              <p class="mb-3">
                Se il lordo delle righe supera il residuo del capitolo, il pulsante diventa
                <strong>"Autorizza e Registra"</strong> e si apre la finestra di sforamento: l'eccesso va
                giustificato con una motivazione (minimo 10 caratteri, resta agli atti) e una
                <strong>strategia di rientro</strong>:
              </p>
              <ol class="space-y-2 list-decimal pl-5 mb-3">
                <li><strong>Attesa conguaglio</strong> — registri lo sforo e i soldi mancanti verranno chiesti
                    ai condòmini a chiusura esercizio.</li>
                <li><strong>Rata Integrativa</strong> — resta un avviso in Dashboard: emetterai in seguito un piano
                    rate ordinario integrativo per coprire l'eccesso.</li>
                <li><strong>Fondo di riserva</strong> — pianifichi la copertura dal fondo. Attenzione alla
                    parola: <em>pianifichi</em>. Il fondo non viene toccato alla registrazione.</li>
              </ol>
              <p>
                Con la strategia fondo, la fattura mostra poi un banner viola <strong>"Copertura in attesa di
                conferma"</strong>: la conferma è un <strong>giroconto</strong> dal fondo alla banca, proposto
                già precompilato. Solo quel giroconto decurta il fondo; a conferma avvenuta il banner diventa
                verde con il protocollo GIR e il collegamento al pagamento. I dettagli sono nella guida della
                pagina Giroconti.
              </p>
            </section>
            <div class="p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
              <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
              <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                Il saldo del fondo mostrato nella finestra è già <strong>al netto delle coperture promesse</strong>
                ad altre fatture e non ancora confermate: non puoi impegnare due volte gli stessi soldi.
                E se le righe contengono spese ad personam, il fondo comune non è selezionabile per costruzione.
              </div>
            </div>
          </TabsContent>

          <!-- TAB: Consigli -->
          <TabsContent value="consigli" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Abitudini che ti salvano in assemblea</h3>
              <ul class="space-y-3 list-disc pl-5">
                <li><strong>Causali leggibili</strong>: scrivi la causale come la leggeresti ad alta voce in
                    assemblea — è ciò che rende il libro giornale difendibile.</li>
                <li><strong>Più righe, capitoli giusti</strong>: se il documento copre lavori diversi, spezzalo
                    in righe sui rispettivi capitoli invece di caricare tutto sul più capiente. Il budget ringrazia
                    e il consuntivo pure.</li>
                <li><strong>Niente eliminazioni</strong>: una fattura registrata si corregge con lo
                    <strong>storno</strong> (nota di credito automatica) e una nuova registrazione. Se la fattura
                    ha pagamenti o una copertura confermata, il sistema ti indica cosa stornare prima.</li>
                <li><strong>La data documento conta</strong>: oltre 30 giorni fa scatta il promemoria dell'Art.
                    1130 (annotazione a registro entro 30 giorni); anteriore all'esercizio, la fattura diventa
                    pregressa da sola.</li>
                <li><strong>Fornitore prima di tutto</strong>: scegliendolo, scadenza, IBAN e modalità di
                    pagamento si compilano dalla sua anagrafica — correggili lì se sbagliati, non a ogni fattura.</li>
              </ul>
            </section>
          </TabsContent>
        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
