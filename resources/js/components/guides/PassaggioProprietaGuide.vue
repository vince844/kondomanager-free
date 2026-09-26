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
import { ArrowRightLeft, BookOpen, CalendarDays, Scale, ShieldCheck } from 'lucide-vue-next';

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
              <li><strong>Nella locazione e nell'usufrutto le bozze restano a chi esce</strong> e il conguaglio le comprende. Nella vendita della nuda proprietà l'ordinaria dei piani generati prima dell'usufrutto è dell'usufruttuario (art. 1004 c.c.): chi compra la nuda proprietà non ne risponde. Se il piano non ha emesso nulla, si ricalcola e le rate passano da sé.</li>
              <li><strong>Millesimi, tabelle e pertinenze non cambiano.</strong> Il collegamento di una pertinenza è descrittivo e non sposta importi.</li>
              <li><strong>Il prospetto degli oneri accessori.</strong> Dalla pagina dei titolari, «Prospetto oneri accessori» stampa per ogni conduttore le voci che il riparto pone a carico dell'inquilino, divise per giorni di conduzione: quelle già nelle sue rate e quelle che ha pagato il proprietario e vanno rimborsate. È sul preventivo; il conguaglio definitivo si fa sul rendiconto.</li>
            </ul>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              Se il passaggio tocca rate già emesse o cambia il destinatario di un piano già generato, la conferma chiede una spunta e una nota di almeno dieci caratteri, che restano registrate con l'operazione. Se non tocca nulla, il pulsante è attivo subito.
            </p>
          </section>

          <section>
            <h3 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ShieldCheck class="w-5 h-5 text-indigo-500" /> Chi resta obbligato
            </h3>
            <p class="mb-3">
              Chi acquista risponde in solido con chi vende per i contributi dell'esercizio in corso e di quello precedente (art. 63 co. 4 disp. att. c.c.). Il programma <strong>non intesta nulla</strong> all'acquirente per questo: la nota resta nel pannello e nella situazione debitoria dell'unità. Chi paga in forza della solidarietà ha regresso verso il venditore per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021).
            </p>
            <p class="mb-3">
              Finché il condominio non riceve <strong>copia autentica del titolo</strong>, chi vende resta obbligato per i contributi maturati dopo (art. 63 co. 5). La casella «Ho ricevuto copia autentica del titolo», con la sua data, è l'unico campo del modulo con un effetto giuridico diretto. Registra pure il passaggio anche senza: l'anagrafe si aggiorna sulla comunicazione scritta del condòmino (art. 1130 n. 6 c.c.).
            </p>
            <p class="text-[13px] text-slate-500 dark:text-slate-400">
              L'attestazione dello stato dei pagamenti e delle liti in corso (art. 1130 n. 9 c.c.) la chiede il condòmino che vende; il notaio la pretende nella prassi, ma non ha valore liberatorio verso il condominio (Cass. 7260/2024).
            </p>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
