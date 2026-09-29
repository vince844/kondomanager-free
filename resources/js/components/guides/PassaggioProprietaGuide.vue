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
              <li><strong>Nella vendita le rate in bozza passano a chi entra.</strong> Se il piano ha già emesso a giornale anche una sola rata non si ricalcola più: le rate in bozza che scadono dal giorno del passaggio passano a chi entra — cambia l'intestatario, non l'importo — e il conguaglio è la sua parte dell'intero piano meno quelle rate (se le rate coprono più dei suoi giorni, si rovescia). Restano a chi esce, e si conguagliano, le bozze già scadute o pagate e quelle di una straordinaria che è sua; il saldo pregresso dentro una bozza resta suo in una quota a parte. Il pannello dice quali rate passano e quali no, e perché.</li>
              <li><strong>Nella locazione e nell'usufrutto le bozze restano a chi esce</strong> e il conguaglio le comprende. Nella vendita della nuda proprietà l'ordinaria dei piani generati prima dell'usufrutto è dell'usufruttuario (art. 1004 c.c.): non passa a chi compra la nuda proprietà. Se il piano non ha emesso nulla, si ricalcola e le rate passano da sé.</li>
              <li><strong>Nella vendita o donazione con riserva d'usufrutto il passaggio non sposta l'ordinaria.</strong> Chi vende resta, sulla stessa quota, come usufruttuario, e fra le parti le spese ordinarie sono dell'usufruttuario (art. 1004 c.c.): nelle rate già emesse e nelle bozze dei piani che hanno già emesso l'ordinaria resta a chi vende e non si conguaglia. Un piano generato o ricalcolato dopo l'atto addebita invece secondo i coefficienti: dal giorno dell'atto le voci sul «Proprietario», e quelle senza coefficienti, vanno a chi compra la nuda proprietà; perché l'ordinaria resti a chi vende, la voce va su «Usufruttuario», che dove non c'è usufrutto la dà al proprietario e mai all'inquilino; non su «Inquilino», che su un'unità affittata la fa pagare all'inquilino (guida «Ruoli e usufrutto»). Il pannello «Cosa cambierà» lo segnala quando lo legge nel riparto di un piano non ancora emesso. Se chi vende ha solo una quota e sull'unità resta un altro proprietario pieno, l'unità è in parte in piena proprietà e in parte in nuda proprietà e usufrutto: i piani generati o ricalcolati non la sanno dividere, e il pannello lo dice prima della registrazione. Le straordinarie seguono la competenza — la data della delibera, o quella dichiarata sulla fattura — come in ogni vendita, e dal giorno dell'atto sono del nudo proprietario (art. 1005 c.c.). Si dichiara spuntando «Chi vende o dona resta usufruttuario»: il programma non la deduce dal ruolo scelto.</li>
              <li><strong>Millesimi, tabelle e pertinenze non cambiano.</strong> Il collegamento di una pertinenza è descrittivo e non sposta importi.</li>
              <li><strong>Il prospetto degli oneri accessori.</strong> Dalla pagina dei titolari, il pulsante con la stampante («Prospetto oneri accessori» sugli schermi larghi) stampa per ogni conduttore le voci che il riparto pone a carico dell'inquilino, divise per giorni di conduzione: quelle già nelle sue rate e quelle che ha pagato il proprietario e vanno rimborsate. È sul preventivo; il conguaglio definitivo si fa sul rendiconto.</li>
            </ul>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Se il passaggio tocca rate già emesse o cambia il destinatario di un piano già generato, la conferma chiede una spunta e una nota di almeno dieci caratteri, che restano registrate con l'operazione. Se non tocca nulla, il pulsante è attivo subito. Le quote che restano a chi le ha — per legge, come l'ordinaria che fra le parti è dell'usufruttuario e la straordinaria che è del nudo proprietario, o perché a chi entra non ne va nessuna parte, come quelle di soli saldi pregressi o di una spesa tutta di chi vende — il modulo le elenca sopra il pulsante, senza chiedere la spunta: il passaggio non le tocca.
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
