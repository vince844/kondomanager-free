<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import {
  FileText, ChevronRight, Info, Ban, RotateCcw, TriangleAlert,
  ShieldCheck, Coins,
} from 'lucide-vue-next';

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
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <FileText class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: fatture passive</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Cosa entra in questo elenco e cosa no, come si legge una riga, la domanda che arriva prima o poi a tutti (ho sbagliato una fattura, la elimino o la
            storno?) e i tre pulsanti della ratifica sulla pagina di una fattura.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — Cosa c'è qui -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Cosa entra in questo elenco</h3>
            <p class="mb-3">
              Le <strong>fatture dei fornitori</strong> e le <strong>note di credito</strong>. Ogni riga registrata qui produce una scrittura in partita doppia e alimenta lo scadenzario dei pagamenti.
            </p>
            <div class="p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Una Regolazione immediata non compare qui, ed è corretto.</strong> Non genera una fattura: è un movimento diretto, e si trova nel <strong>Libro Giornale</strong>. Il pulsante per crearla sta però in questa barra, quindi è facile registrarne una e poi non ritrovarla — per questo l'avviso in cima alla pagina le conta e ci porta direttamente.
              </div>
            </div>
          </section>

          <!-- 2 — Come si legge una riga -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Come si legge una riga</h3>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Pagamento</strong> — <em>Da pagare</em>, <em>Parziale</em>, <em>Pagata</em> o <em>Stornata</em>. Non lo imposti a mano: lo calcola il sistema dai pagamenti realmente registrati.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Approvazione</strong> — <em>Da approvare</em>, <em>Approvata</em>, <em>Contestata</em> o <em>Sforo motivato</em>.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Il badge <span class="inline-flex items-center bg-cyan-50 text-cyan-600 border border-cyan-200 text-xs font-black uppercase tracking-wider px-1.5 py-0.5 rounded">Ritenuta</span> segnala una fattura <strong>soggetta a ritenuta d'acconto</strong>: dalla cassa esce il netto, la ritenuta resta a debito verso l'Erario finché non la versi con l'F24.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>L'icona del documento indica che c'è <strong>almeno un allegato</strong>: per scaricarlo o vederli tutti si apre il menu della riga o il Dettaglio della fattura.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 3 — Sforo e ratifica -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ShieldCheck class="w-4 h-4 inline-block -mt-0.5 text-orange-500" /> Sforo di budget e ratifica
            </h3>
            <p class="mb-3">
              Se una fattura supera il preventivo del suo capitolo, il sistema non la rifiuta: chiede una <strong>motivazione scritta</strong> e la registra come <em>Sforo motivato</em>. La spesa è contabilizzata, ma resta segnata come da ratificare.
            </p>
            <div class="p-4 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex gap-3 text-[13px]">
              <TriangleAlert class="w-5 h-5 text-amber-600 shrink-0" />
              <div>
                <strong>Registrare non è deliberare.</strong> Uno sforo va portato in assemblea (art. 1135 c.c.). Dal menu della riga, «Paga per l'urgenza» sblocca il pagamento con il motivo dell'urgenza, e la spesa si riferisce alla prima assemblea; «Ratifica» la lega alla delibera dell'assemblea che l'ha ratificata, con il giorno della riunione. Finché non fai l'una o l'altra la fattura resta nel contatore <em>Da ratificare</em> in cima alla pagina, e nella colonna dell'approvazione resta «Da ratificare» fino alla delibera. Il programma propone la spesa fra i punti della prossima assemblea. Vale la pena scriverla per chi la leggerà, non per chiudere il dialogo.
              </div>
            </div>
          </section>

          <!-- 4 — Eliminare o stornare -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ho sbagliato: elimino o storno?</h3>
            <p class="mb-3">
              La regola è una sola, e non è burocratica: <strong>si elimina ciò che non è ancora uscito dallo studio, si storna ciò che è già arrivato a qualcun altro.</strong>
            </p>
            <div class="space-y-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-red-600">
                  <FileText class="w-4 h-4" />
                  <h4 class="font-bold">Elimina</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Cancella la fattura e la sua scrittura, come se non fosse mai esistita. Va bene per l'errore di battitura scoperto subito, prima che qualcosa poggi su quel numero.
                </p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-amber-600">
                  <RotateCcw class="w-4 h-4" />
                  <h4 class="font-bold">Storna</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Genera una <strong>nota di credito</strong> che annulla la fattura lasciandone traccia. È la strada obbligata quando la fattura è già stata pagata, è in un piano rate che ha già incassato, o appartiene a un esercizio chiuso.
                </p>
              </div>
            </div>
            <div class="mt-4 p-4 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900 flex gap-3 text-[13px]">
              <RotateCcw class="w-5 h-5 text-emerald-600 shrink-0" />
              <div>
                <strong>Lo storno è reversibile, e quasi nessuno lo sa.</strong> La nota di credito che genera è a sua volta una fattura aperta, quindi eliminabile: eliminandola, la fattura originale torna allo stato calcolato dai pagamenti reali. Se hai stornato per sbaglio, non sei in un vicolo cieco.
              </div>
            </div>
            <div class="mt-4 p-4 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-[13px] text-slate-600 dark:text-slate-400">
              <strong class="text-slate-800 dark:text-slate-200">La nota di credito del fornitore.</strong> Quando è il fornitore a correggere una fattura, registri la sua nota e la colleghi alla fattura che rettifica: nel modulo, o dopo, con <strong>«Collega a una fattura»</strong> dal menu della nota (così si collegano anche le note registrate con una versione più vecchia). <strong>«Scollega»</strong> toglie il collegamento. Collegata, la fattura vale al netto della nota nei piani rate e nel cruscotto, e non si storna né si elimina finché la nota è collegata: prima si scollega o si elimina la nota.
            </div>
          </section>

          <!-- 5 — Il divieto spiegato -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Ban class="w-4 h-4 inline-block -mt-0.5 text-slate-500" /> «Elimina — non consentito»
            </h3>
            <p class="mb-3">
              Quando la voce dice «non consentito», <strong>cliccala</strong>: si apre il <strong>motivo</strong> con il <strong>rimedio</strong>. Sono otto situazioni diverse, e per ciascuna c'è una strada:
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Copertura dal fondo confermata</strong> → storna prima il giroconto di conferma, dalla pagina Giroconti.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>È in un piano rate che ha già incassato</strong> → usa lo storno: le rate del piano restano, e quanto i condòmini hanno versato per quella spesa si regola con una delibera. Se però la fattura ha righe su unità specifiche (una spesa ad personam) che una rata emessa ha già chiesto, anche lo storno si rifiuta: il motivo dice la strada, che passa dal piano.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>È in un piano rate con rate emesse, ma senza incassi</strong> → annulla le emissioni di quel piano e riportalo in bozza.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>È in un piano rate approvato</strong> → riporta il piano in bozza.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Pagata o parzialmente pagata</strong> → storna prima il pagamento, da Pagamenti.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Collegata a più scritture</strong>, <strong>esercizio chiuso</strong>, o <strong>già stornata</strong> → si usa lo Storno.</span>
                </li>
              </ul>
            </div>
            <p class="mt-3 text-[13px] text-slate-500 dark:text-slate-400">
              Il motivo che leggi è esattamente quello che applicherebbe il sistema se provassi: non è una previsione, è la stessa regola.
            </p>
            <p class="mt-3">
              <strong>«Storna — non consentito»</strong> segue la stessa scala. Una fattura dentro un piano rate che non ha ancora incassato
              non si storna: le rate del piano la chiederebbero ancora. Prima si toglie dal piano — lo si riporta in bozza, annullando le
              emissioni se ce ne sono, e lo si elimina — poi si storna la fattura, e le altre fatture del piano tornano disponibili per un
              piano nuovo.
            </p>
            <p class="mt-3">
              Con gli incassi lo storno resta possibile, tranne un caso: la fattura con righe su unità specifiche (una spesa ad personam) che
              una rata emessa ha già chiesto non si storna nemmeno così, perché lo storno toglierebbe la spesa dall'unità una seconda volta. Si
              risale dal piano come dice il motivo: di norma si stornano gli incassi delle sue rate, si annullano le emissioni, si riporta il
              piano in bozza e lo si elimina; poi si storna la fattura.
            </p>
          </section>

          <!-- 6 — Coperture -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <Coins class="w-4 h-4 inline-block -mt-0.5 text-emerald-500" /> Coperture da fondo
            </h3>
            <p>
              Una fattura può essere coperta, in tutto o in parte, da un fondo già accantonato. Finché la copertura è <strong>in attesa</strong> è solo un'indicazione. Quando la <strong>confermi</strong>, nasce un giroconto vero nel Libro Giornale: da quel momento il fondo è stato consumato, e la fattura non si elimina più senza prima stornare quel giroconto — altrimenti resterebbe un movimento senza più il motivo che lo giustificava.
            </p>
          </section>

          <!-- 6b — Coda 689: «Metti a rate questa spesa», a schema -->
          <section data-testid="guida-metti-a-rate">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Mettere a rate una spesa</h3>
            <ul class="list-disc space-y-1 pl-5">
              <li>Sulla pagina della fattura, nel riquadro «Le rate», e nel menu della riga dell'elenco: «Metti a rate questa spesa».</li>
              <li>C'è solo se resta qualcosa da mettere a rate; altrimenti il riquadro dice «Nei piani della voce» e porta al piano.</li>
              <li>Apre il nuovo piano sulla scheda giusta, con tutto già scelto: una spesa sulla voce va su «Dalle voci del preventivo».</li>
              <li>Una fattura con una riga «Fuori preventivo» va su «Spese fuori preventivo», con la fattura nel carrello.</li>
              <li>Con la delibera della gestione già scelta, se la spesa ne ha una. Il piano nasce quando lo salvi.</li>
            </ul>
          </section>

          <!-- 6c — Coda 688: la competenza dei lavori urgenti, a schema -->
          <section data-testid="guida-lavori-urgenti-fattura">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">La competenza dei lavori urgenti</h3>
            <ul class="list-disc space-y-1 pl-5">
              <li>Se una voce della fattura sta in un piano d'urgenza, la competenza ha un modo in più: «Lavori ordinati il …».</li>
              <li>Una fattura nuova lo propone con il giorno del piano; lo cambi se la fattura dice altro.</li>
              <li>Senza periodo, il conguaglio dell'opera usa il giorno del piano, come le rate.</li>
              <li>Messa a rate con «Urgenza» da «Spese fuori preventivo», la fattura senza periodo prende il giorno del piano; quella con il suo periodo tiene il suo.</li>
            </ul>
          </section>

          <!-- 7 — I tre pulsanti della ratifica, sulla pagina della fattura -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              <ShieldCheck class="w-4 h-4 inline-block -mt-0.5 text-indigo-500" /> La pagina di una fattura: i tre pulsanti della ratifica
            </h3>
            <p class="mb-3">
              Una spesa che supera il preventivo aspetta la ratifica dell'assemblea, e finché non c'è non si paga. Il caso: la fattura n. 45 di Idraulica Bianchi,
              € 1.800,00 per la colonna dell'acqua, va oltre il preventivo della voce, e registrandola hai scritto la motivazione dello sforo. Il programma la propone
              fra i punti della prossima assemblea, con la sua motivazione sotto. Sulla pagina della fattura trovi tre pulsanti.
            </p>
            <ul class="space-y-2">
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span><strong>«Paga per l'urgenza»</strong>: i lavori non potevano aspettare,
                e la paghi subito. «Perché era urgente» parte con la motivazione che hai scritto registrando la fattura: cambiala solo se serve, e premi «Sblocca il
                pagamento». Il motivo resta sulla fattura, va sotto il punto che il programma propone per la prossima assemblea e lo leggono anche i condòmini
                nell'avviso di convocazione. È la regola dei lavori urgenti (art. 1135 c. 2 c.c.): li riferisci alla prima assemblea, e la ratifica arriva dopo, con la
                sua delibera. Sbloccata, la fattura dice Sbloccata per l'urgenza finché non la paghi, Pagata in parte per l'urgenza fino al saldo, Pagata per l'urgenza
                dopo: lo stesso dice il punto proposto per la prossima assemblea.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span><strong>«Da portare in assemblea»</strong>: un promemoria per portare la
                fattura in assemblea prima di pagarla o di decidere che cosa fare: niente si paga, e il punto ti aspetta quando prepari l'assemblea.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span><strong>«Ratifica»</strong>: l'assemblea ne ha parlato e l'ha ratificata.
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Se un'assemblea ha respinto, rinviato, revocato o annullato una delibera che riguarda la fattura (per esempio la ratifica respinta), la scheda lo dice in testa con la frase della delibera e il collegamento all'assemblea. Sono frasi: nessun conto cambia da solo.</span></li>
                Scegli la delibera di quella riunione: la fattura diventa ratificata, con il giorno della riunione e non quello del clic, e i compiti della ratifica si
                chiudono.</span></li>
              <li class="flex gap-2"><ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" /><span>Una fattura <strong>ratificata prima</strong> che le assemblee fossero nel
                programma resta ratificata, con la sua nota e il giorno in cui l'hai registrata. Se vuoi, «Collega la delibera» la lega alla riunione registrata a mano.</span></li>
            </ul>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
