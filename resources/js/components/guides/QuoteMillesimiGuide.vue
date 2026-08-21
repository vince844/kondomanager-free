<script setup lang="ts">
/**
 * Guida della pagina «Associazione millesimi».
 *
 * Scritta nella beta.61, che a quella pagina ha aggiunto parecchio: l'associazione in blocco con
 * anteprima, la ricerca e l'ordinamento, il «+» sull'ultima riga, e soprattutto il millesimo che
 * si può lasciare vuoto.
 *
 * ⚠️ **Metà di questa guida spiega tre distinzioni che non si indovinano**, e che il programma
 * tratta in modo diverso pur mostrandole quasi uguali: riga assente, valore zero e valore vuoto.
 * Prima della beta.61 le prime due erano indistinguibili e la terza non esisteva. È il genere di
 * cosa che, non detta, si scopre al primo riparto sbagliato.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { ChevronRight, Hash, Layers, Plus, Search, AlertTriangle, Check } from 'lucide-vue-next';

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
              <Hash class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: assegnare i millesimi</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Come associare le unità a una tabella, cosa succede se un millesimo manca, e perché
            una riga vuota e uno zero non sono la stessa cosa.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Associare le unità</h3>
            <p class="mb-3">
              Questa pagina non crea le unità immobiliari: le <strong>associa</strong>. Il tetto è
              quindi il numero di unità presenti in anagrafica, e il contatore in alto dice sempre
              quante ne restano da associare.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                <h4 class="font-bold text-slate-900 dark:text-white mb-2 flex items-center gap-2">
                  <Layers class="w-4 h-4" /> Associa in blocco
                </h4>
                <p class="leading-relaxed text-[13px]">
                  Aggiunge molte unità insieme. Puoi prenderle <strong>tutte</strong> o
                  raggrupparle per palazzina, scala o tipologia — compaiono solo i criteri che in
                  questo condominio hanno davvero dei dati. Prima di confermare vedi
                  <strong>l'elenco delle unità</strong>, con le caselle già spuntate: togli quelle
                  che non vuoi e il pulsante ti dice quante ne entreranno.
                </p>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                <h4 class="font-bold text-slate-900 dark:text-white mb-2 flex items-center gap-2">
                  <Plus class="w-4 h-4" /> Aggiungi immobile
                </h4>
                <p class="leading-relaxed text-[13px]">
                  Aggiunge <strong>una</strong> riga per volta, da scegliere nella tendina. Lo
                  stesso pulsante lo trovi anche accanto al cestino dell'<strong>ultima riga</strong>:
                  serve a non dover risalire in cima ogni volta che ne aggiungi un'altra.
                </p>
              </div>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">
              Vuoto, zero, riga assente: tre cose diverse
            </h3>
            <p class="mb-4">
              Sono la distinzione più importante di questa pagina, e il programma le tratta in modo
              diverso anche se a schermo si somigliano.
            </p>
            <div class="space-y-3">
              <div class="bg-amber-50 dark:bg-amber-900/20 p-4 rounded-xl border border-amber-100 dark:border-amber-900/50">
                <h4 class="font-bold text-amber-900 dark:text-amber-300 mb-1">Casella vuota — «non l'ho ancora compilato»</h4>
                <p class="text-amber-800 dark:text-amber-200/80 leading-relaxed text-[13px]">
                  Si salva senza problemi: puoi associare le unità oggi e scrivere i millesimi
                  quando arrivano dal tecnico, anche in più sedute. Il contatore in alto ti ricorda
                  quante righe restano da compilare, e <strong>alla generazione del piano rate il
                  programma si ferma</strong> per avvisarti.
                </p>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                <h4 class="font-bold text-slate-900 dark:text-white mb-1">Zero — «questa unità non partecipa»</h4>
                <p class="leading-relaxed text-[13px]">
                  È una scelta dichiarata, ed è legittima: l'ascensore che i piani terra non pagano,
                  le scale che non riguardano il negozio con ingresso su strada. L'unità resta fuori
                  dal riparto e <strong>non compare nessun avviso</strong>, perché non c'è niente da
                  segnalare.
                </p>
              </div>
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                <h4 class="font-bold text-slate-900 dark:text-white mb-1">Riga assente — «non partecipa», detto togliendo la riga</h4>
                <p class="leading-relaxed text-[13px]">
                  Ha lo stesso identico effetto dello zero. È il modo in cui le tabelle parziali
                  arrivano dall'importazione, ed è quello che consigliamo: una tabella con meno
                  righe si legge meglio di una piena di zeri.
                </p>
              </div>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <AlertTriangle class="w-5 h-5 text-amber-500" />
              Cosa succede se un millesimo manca
            </h3>
            <p class="mb-3">
              Il motore ripartisce sempre <strong>il 100% della spesa</strong>, dividendola sulla
              somma effettiva della tabella — non su 1000. Quindi un'unità senza millesimo non paga
              zero: <strong>sparisce dal piano</strong>, e la sua quota la pagano le altre.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <p class="text-[13px] leading-relaxed">
                Con dieci unità, nove compilate e una dimenticata, ciascuno dei nove si vede
                addebitare <strong>€ 1.111,11 invece di € 1.000,00</strong> — e il totale del piano
                resta identico al preventivo, quindi nessun controllo contabile ha nulla da dire.
              </p>
            </div>
            <p class="mt-3">
              Per questo, se generi un piano rate con dei millesimi ancora vuoti, il programma si
              ferma, ti dice <strong>quale unità e in quale tabella</strong>, e ti porta qui a
              sistemare. Puoi procedere lo stesso — la decisione resta tua — ma scrivendo il
              perché, che resta agli atti del piano.
            </p>
            <div class="bg-amber-50 dark:bg-amber-900/20 p-4 rounded-xl border border-amber-100 dark:border-amber-900/50 mt-3">
              <p class="text-amber-800 dark:text-amber-200/80 leading-relaxed text-[13px] mb-0">
                <strong>Ma la finestra per rimediare si chiude.</strong> Finché non emetti le rate e non
                arriva il primo incasso puoi compilare i millesimi e rigenerare il piano. Dopo, il
                ricalcolo si blocca: per correggerlo dovresti prima annullare gli incassi o le emissioni.
                Se i millesimi ti mancano ancora, spesso conviene aspettare a emettere.
              </p>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Search class="w-5 h-5 text-slate-500" />
              Cercare e ordinare
            </h3>
            <p class="mb-3">
              <strong>Da nove righe in su</strong> compare una casella di ricerca — cerca per nome,
              interno, piano o palazzina. Sotto quella soglia l'elenco si legge a colpo d'occhio e
              la casella non serve. Le intestazioni «Immobile» e «Millesimi» ordinano invece
              l'elenco sempre: un clic crescente, due decrescente, tre torna all'ordine originale.
            </p>
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-xl border border-blue-100 dark:border-blue-900/50">
              <p class="text-blue-800 dark:text-blue-200/80 leading-relaxed text-[13px]">
                <strong>La ricerca nasconde, non toglie.</strong> Le righe filtrate restano nel
                salvataggio — la pagina te lo scrive accanto alla casella — e se una riga nascosta
                ha un errore che blocca il salvataggio, un avviso accanto al pulsante «Salva quote»
                ti dice di mostrarle tutte.
              </p>
            </div>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I decimali</h3>
            <p class="mb-3">
              Il numero di decimali dichiarato dalla tabella governa <strong>come il valore si
              mostra</strong>, non cosa viene conservato. Un valore più preciso — per esempio
              importato da un altro gestionale con quattro decimali — resta intatto e si vede per
              intero, anche su una tabella che ne dichiara due.
            </p>
            <p>
              Da tastiera invece il limite è quello che hai dichiarato tu: se la tabella è a tre
              decimali, il quarto non entra. Se ti serve scriverne di più fini, alza i decimali
              nelle impostazioni della tabella.
            </p>
          </section>

          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Check class="w-5 h-5 text-emerald-600" />
              Il totale non giudica
            </h3>
            <p class="mb-3">
              In fondo all'elenco trovi la somma dei valori, che si muove mentre digiti. Non viene
              confrontata con 1000 e non ti dice se è giusta, <strong>di proposito</strong>.
            </p>
            <ul class="space-y-2">
              <li class="flex gap-2">
                <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                <span>Sulle tabelle vere il 1000 spesso non è il numero atteso: le parziali, quelle a parti uguali, quelle arrotondate dal tecnico e approvate così in assemblea.</span>
              </li>
              <li class="flex gap-2">
                <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                <span>Il numero che deve venire lo sai tu, perché è scritto sul documento approvato. Il gestionale te lo mostra e tace.</span>
              </li>
            </ul>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
