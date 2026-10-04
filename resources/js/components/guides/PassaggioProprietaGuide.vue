<script setup lang="ts">
/**
 * Guida di «Registra passaggio»: che cosa fa il programma quando cambia il titolare di un'unità, e
 * che cosa **non** decide al posto dell'amministratore.
 *
 * Testi dal §2 e dal §6 di `docs/pertinenze_vendita_locazione.md` e dalle decisioni 11–20 del
 * progetto sul subentro. Ogni sentenza citata è verificata su fonte (17–18/09/2026): la 9148/2008
 * **non** è il criterio della delibera, la 24654/2010 sì; l'attestazione dell'art. 1130 n. 9 la
 * chiede il condòmino e non ha valore liberatorio (Cass. 7260/2024).
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { ArrowRightLeft, BookOpen, CalendarDays, Scale, ShieldCheck, Undo2 } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <BookOpen class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: registrare un passaggio</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Vendita, donazione, locazione, usufrutto: come il programma conserva la storia dell'unità e cosa mostra prima di scrivere.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ArrowRightLeft class="w-5 h-5 text-indigo-500" /> Chi esce, chi entra
            </h3>
            <p class="mb-3">
              «Registra passaggio» <strong>chiude</strong> il periodo di chi esce al giorno prima della data dell'atto e <strong>apre</strong> quello di chi entra da quella data. La riga di chi esce non sparisce: resta nello storico dell'unità, con le sue date, perché il riparto e l'estratto conto l'hanno già raccontata.
            </p>
            <p class="mb-3">
              È diverso da «Modifica associazione», che corregge un dato sbagliato sulla stessa persona, e da «Dissocia», che cancella una riga scritta per errore. Una riga con una storia — un periodo chiuso, un passaggio registrato — non si cancella e non si sovrascrive con un'altra persona: il programma lo rifiuta e rimanda qui.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              La locazione <strong>si aggiunge</strong>, non sostituisce: il proprietario resta, l'inquilino entra con il suo ruolo. Verso il condominio continua a rispondere il proprietario; all'inquilino vengono addebitate solo le voci il cui coefficiente lo indica.
            </p>
          </section>

          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <CalendarDays class="w-5 h-5 text-indigo-500" /> Da quando
            </h3>
            <p class="mb-3">
              Si scrive <strong>una data sola</strong>, quella dell'atto. Il giorno prima lo calcola il programma: chiedere due date è l'errore che produce buchi e sovrapposizioni. Sotto il campo compare la frase che dice esattamente chi risulterà titolare fino a quando e chi da quando.
            </p>
            <p class="mb-3">
              La data non ha un valore predefinito. «Oggi» è il giorno in cui si compila, quasi mai il giorno del rogito: il programma non tira a indovinare una data che decide chi paga.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Per lo straordinario conta la data della delibera che ha approvato lavori e prezzo, non quella del passaggio (Cass. 24654/2010; art. 63 disp. att. c.c.): l'obbligazione è sorta con la delibera, e resta di chi era titolare allora. Per l'ordinario la spesa matura nel tempo e si divide in proporzione ai giorni.
            </p>
          </section>

          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Scale class="w-5 h-5 text-indigo-500" /> Cosa cambia nei conti
            </h3>
            <p class="mb-3">
              Il pannello «Cosa cambierà» è calcolato dal server su quello che hai scritto e si aggiorna mentre compili. Quattro blocchi: l'anagrafica, le rate già emesse e il conguaglio, chi resta obbligato, cosa non cambia.
            </p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
              <li><strong>Le rate già emesse non si toccano.</strong> Sono crediti già iscritti verso persone nominate. Il conguaglio si propone come due righe di saldo che sommano a zero: credito a chi esce, debito a chi entra, o viceversa.</li>
              <li><strong>La morosità resta di chi l'ha maturata.</strong> Il conguaglio si calcola sulla competenza, non sui pagamenti.</li>
              <li><strong>Nella vendita le rate in bozza passano a chi entra.</strong> Se il piano ha già una quota a giornale, o un incasso, un credito usato o un rimborso su una sua quota, o se un passaggio non annullato, anche su un'altra unità, l'ha già preso nel suo conguaglio, non si ricalcola più, e il passaggio lo prende nel suo conguaglio: le rate in bozza che scadono dal giorno del passaggio passano a chi entra — cambia l'intestatario, non l'importo — e il conguaglio è la sua parte dell'intero piano meno quelle rate (se le rate coprono più dei suoi giorni, si rovescia). Restano a chi esce, e si conguagliano, le bozze già scadute o pagate e quelle di una straordinaria che è sua; il saldo pregresso dentro una bozza resta suo in una quota a parte. Se chi vende tiene un'altra parte dell'unità — per esempio è proprietario pieno di metà e usufruttuario dell'altra metà, e vende la metà piena — passa solo la spesa della quota che esce: le bozze, che comprendono anche la parte che resta, restano a chi vende, e la parte che passa si conguaglia. Il pannello dice quali rate passano e quali no, e perché.</li>
              <li><strong>Quando il conguaglio si ferma.</strong> Se non può separare con certezza la parte che passa, il conguaglio non propone cifre e lo dice nel pannello. Succede con quote passate per più strade sulla stessa unità (una persona che ne ha ceduta una e ne ha avuta un'altra, o che ne tiene una arrivata da un predecessore mentre ne cede un'altra), con le quote della 1.7.x quando il piano ha assorbito un saldo pregresso, e con un piano senza dettaglio del riparto su un'unità mista la cui titolarità è cambiata dopo la generazione. Se serve, si scrive con un saldo manuale dal Wallet sulla stessa gestione.</li>
              <li><strong>Nella locazione e nell'usufrutto le bozze restano a chi esce</strong> e il conguaglio le comprende. Alla costituzione dell'usufrutto il modulo chiede chi paga l'ordinaria dal giorno dell'atto, come nella riserva (qui sotto). Nella vendita della nuda proprietà l'ordinaria dei piani generati prima dell'usufrutto è dell'usufruttuario (art. 1004 c.c.) e non passa a chi compra la nuda proprietà, salvo le voci che sono del nudo proprietario perché alla costituzione o alla riserva hai scelto «come dice ogni voce». Se il piano si ricalcola ancora (niente a giornale, nessun movimento e nessun passaggio che l'abbia preso nel suo conguaglio), il passaggio lo lascia al ricalcolo, e ricalcolato le rate passano da sé, salvo le voci che il passaggio non ha potuto spostare (qui sotto). Dopo una vendita o un usufrutto il programma chiede di ricalcolarlo prima di emettere o di registrare un incasso; dopo un cambio d'inquilino no, e un piano emesso senza ricalcolo lascia le quote all'inquilino di prima.</li>
              <li><strong>Il passaggio porta tutta la quota di chi esce.</strong> Se l'atto trasferisce solo una parte della quota, il passaggio non si registra da qui: si registra a mano. Chiudi la riga di chi vende al giorno prima dell'atto da «Modifica associazione», poi da «Associa soggetto» riapri chi vende alla quota che tiene e apri chi compra alla sua, tutte e due dal giorno dell'atto. Non c'è conguaglio automatico. Con le date attaccate il riparto conta le righe nuove dal giorno dell'atto; con un buco fra le date valgono da sempre, e un piano generato o ricalcolato dopo le addebita anche per i mesi prima dell'atto.</li>
              <li><strong>Il piano preso dal conguaglio resta com'è.</strong> Non si ricalcola, non si elimina e non perde una voce. Annullare il conguaglio dallo storico vuol dire che le parti hanno regolato fra loro, e il piano resta fermo (un'eccezione per i passaggi registrati prima della 1.11.0-beta.42: se il passaggio aveva preso il piano solo per rate segnate «emesse» senza scritture, annullato il conguaglio il piano non è più fermo, e va ricalcolato prima di emettere o di registrare un incasso); per ricalcolarlo si tolgono prima le sue quote a giornale e i movimenti, se ne ha, poi si annulla il passaggio dallo storico della sua unità, lo si registra di nuovo e si ricalcola (con un passaggio registrato prima della versione 1.11.0-beta.42 l'ordine lo dicono i messaggi). Se le parti avevano già regolato fra loro, il messaggio lo dice, con la cifra quando la rinuncia o l'annullamento del conguaglio sono fatti dalla versione 1.11.0-beta.42 in poi: ricalcolando, il condominio addebita a chi entra i suoi giorni, e quell'accordo va rifatto. Un movimento tolto si registra di nuovo dopo il ricalcolo.</li>
              <li><strong>Nella vendita o donazione con riserva d'usufrutto chi vende resta usufruttuario</strong>, sulla stessa quota, e il modulo chiede chi paga l'ordinaria dal giorno dell'atto. La proposta è la legge: l'usufruttuario (art. 1004 c.c.). Allora nelle rate già emesse e nelle bozze dei piani che hanno già emesso l'ordinaria resta a chi vende e non si conguaglia, e le voci ordinarie sul «Proprietario» — anche quelle senza coefficienti — passano sull'«Usufruttuario», così un piano generato o ricalcolato dopo l'atto fa lo stesso, salvo la spesa addebitata direttamente all'unità, che non ha una voce e va al nudo proprietario. Le voci sono elencate tutte spuntate: togli la spunta a quelle che devono andare al nudo proprietario. Non si spostano le voci di una gestione che ha un piano approvato: quelle che il piano elenca hanno la ripartizione bloccata, e un piano senza capitoli le comprende tutte. Se quel piano non si ricalcola più (ha una quota a giornale, un incasso, un credito usato o un rimborso su una sua quota, o è già stato preso nel conguaglio di un passaggio), per quel piano non serve spostarle: l'ordinaria è già dell'usufruttuario anche sulle quote ancora in bozza (nella costituzione gliela dà il conguaglio, nella riserva resta a chi vende e non si conguaglia). Se si ricalcola ancora, la scelta non lo raggiunge: non c'è conguaglio, e il piano generato o ricalcolato dà l'ordinaria di quelle voci al nudo proprietario. Per applicarla, riporta il piano in bozza dalla sua pagina prima di registrare il passaggio, poi riapprovalo e ricalcolalo. Il pannello dice quale caso è, con il nome del piano; una voce bloccata da più piani di casi diversi ha una frase sua, senza rimedio. Le voci create dopo partono dal «Proprietario». La scelta resta scritta nello storico del passaggio. Una voce vale per tutta la tabella, e il modulo elenca le altre unità in usufrutto che lo spostamento tocca; dove non c'è usufrutto paga il proprietario, come prima. Con «come dice ogni voce» le voci non si toccano e il conguaglio le segue una per una: le voci sul «Proprietario», e l'addebito diretto all'unità, passano a chi compra la nuda proprietà per i giorni dopo l'atto. Se chi vende ha solo una quota e sull'unità resta un altro proprietario pieno, il programma divide l'unità secondo le quote registrate (guida «Ruoli e usufrutto»); i saldi pregressi intestati all'unità, per ora, no. Le straordinarie seguono la competenza — la data della delibera, o quella dichiarata sulla fattura — come in ogni vendita, e dal giorno dell'atto sono del nudo proprietario (art. 1005 c.c.). Si dichiara spuntando «Chi vende o dona resta usufruttuario»: il programma non la deduce dal ruolo scelto.</li>
              <li><strong>Millesimi, tabelle e pertinenze non cambiano.</strong> Il collegamento di una pertinenza è descrittivo e non sposta importi.</li>
              <li><strong>Il prospetto degli oneri accessori.</strong> Dalla pagina dei titolari, il pulsante con la stampante («Prospetto oneri accessori» sugli schermi larghi) stampa per ogni conduttore le voci che il riparto pone a carico dell'inquilino, divise per giorni di conduzione: quelle già nelle sue rate e quelle che ha pagato il proprietario e vanno rimborsate. È sul preventivo; il conguaglio definitivo si fa sul rendiconto.</li>
            </ul>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Se il passaggio tocca rate già emesse o cambia il destinatario di un piano già generato, la conferma chiede una spunta e una nota di almeno dieci caratteri, che restano registrate con l'operazione. Se non tocca nulla, il pulsante è attivo subito. Le quote che restano a chi le ha — per legge o per la scelta fatta nel modulo, come l'ordinaria che resta all'usufruttuario e la straordinaria che resta al nudo proprietario, o perché a chi entra non ne va nessuna parte, come quelle di soli saldi pregressi o di una spesa tutta di chi vende — il modulo le elenca sopra il pulsante, senza chiedere la spunta: il passaggio non le tocca.
            </p>
          </section>

          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ShieldCheck class="w-5 h-5 text-indigo-500" /> Chi resta obbligato
            </h3>
            <p class="mb-3">
              Chi acquista risponde in solido con chi vende per i contributi dell'esercizio in corso e di quello precedente (art. 63 co. 4 disp. att. c.c.). Il programma <strong>non intesta nulla</strong> all'acquirente per questo, salvo i saldi intestati all'unità, che si addebitano quando si genera il piano: la nota resta nel pannello e nella situazione debitoria dell'unità. Chi paga in forza della solidarietà ha regresso verso il venditore per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021).
            </p>
            <p class="mb-3">
              Finché il condominio non riceve <strong>copia autentica del titolo</strong>, chi vende resta obbligato per i contributi maturati dopo (art. 63 co. 5). La casella «Ho ricevuto copia autentica del titolo», con la sua data, è l'unico campo del modulo con un effetto giuridico diretto. Registra pure il passaggio anche senza: l'anagrafe si aggiorna sulla comunicazione scritta del condòmino (art. 1130 n. 6 c.c.).
            </p>
            <p class="mb-3">
              Nella <strong>vendita o donazione con riserva d'usufrutto</strong>, dal giorno dell'atto nudo proprietario e usufruttuario rispondono in solido verso il condominio (art. 67 ult. co. disp. att. c.c.), e la copia autentica non libera chi vende, che resta usufruttuario. Se chi compra la nuda proprietà risponda anche dell'arretrato di chi vende (art. 63 co. 4) la giurisprudenza non l'ha chiarito: decide l'amministratore.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              L'attestazione dello stato dei pagamenti e delle liti in corso (art. 1130 n. 9 c.c.) la chiede il condòmino che vende; il notaio la pretende nella prassi, ma non ha valore liberatorio verso il condominio (Cass. 7260/2024).
            </p>
          </section>

          <!-- 1.11.0-beta.37, decisione 27: l'annullamento dell'ultimo passaggio, a rate intatte. -->
          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Undo2 class="w-5 h-5 text-indigo-500" /> Ho registrato un passaggio sbagliato
            </h3>
            <p class="mb-3">
              Si annulla dallo storico dell'unità: nella pagina dei titolari il pulsante «Storico» apre «Chi ha avuto questa unità», e sotto il passaggio c'è «Annulla il passaggio…». Le pertinenze passate insieme all'unità tornano con lei, nella stessa operazione: nello storico della pertinenza quel passaggio non compare. Prima di confermare il programma dice che cosa torna come prima — le righe di titolarità scritte dal passaggio, le quote delle rate passate a chi era entrato — e, se c'è, che il conguaglio si toglie dai saldi della gestione; e dice che cosa resta da fare, per esempio ricalcolare di nuovo un piano generato o ricalcolato dopo il passaggio. Serve una nota di almeno dieci caratteri, che resta nello storico.
            </p>
            <p class="mb-3">
              Si annulla <strong>solo l'ultimo passaggio</strong> dell'unità, e solo <strong>a rate intatte</strong>. Altrimenti, al posto del comando compare «Annulla il passaggio — non consentito: perché?», e con un clic si legge che cosa sistemare prima. Succede quando:
            </p>
            <ul class="list-disc pl-5 space-y-2 mb-3">
              <li>sull'unità, o su una pertinenza passata con lei, c'è un passaggio con una data successiva, o con la stessa data e registrato dopo: si annulla prima quello;</li>
              <li>una rata passata a chi era entrato è stata emessa; oppure sulla quota dell'unità in quella rata c'è un pagamento, anche solo sulla parte del saldo pregresso rimasta a chi era uscito; oppure chi era entrato o chi era uscito ha segnalato dal portale un pagamento di quella rata, non ancora verificato;</li>
              <li>in un piano generato o ricalcolato dopo il passaggio, una quota che l'annullamento cambierebbe — di chi era entrato, di chi era uscito o di chi ha preso i giorni rimasti scoperti — è stata emessa o pagata, o ne è stato segnalato il pagamento dal portale e non è ancora verificato;</li>
              <li>il conguaglio è già stato assorbito da un piano;</li>
              <li>una riga di titolarità aperta, chiusa o cambiata dal passaggio è stata corretta a mano dopo;</li>
              <li>una riga associata o modificata a mano dopo il passaggio si scontra con quella che tornerebbe: la stessa persona, nello stesso ruolo, due volte nello stesso periodo, oppure quote dello stesso ruolo oltre 100 nello stesso giorno. Il «perché?» dice come sistemarla.</li>
            </ul>
            <p class="mb-3">
              Il passaggio annullato resta nello storico, con la data, chi l'ha annullato e la nota; nessun conto lo legge più.
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Un passaggio registrato prima della 1.11.0-beta.37 non si annulla: il programma non teneva il registro di ciò che scriveva. Si corregge a mano, chiudendo la riga con una data di fine e registrando da «Associa soggetto» la titolarità giusta.
            </p>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
