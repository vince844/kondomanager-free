<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import {
  Coins, ChevronRight, Info, Lock, LockKeyholeOpen, TriangleAlert,
  Building2, User, TrendingUp, TrendingDown,
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
              <Coins class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: saldi iniziali</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Come si registra il pregresso di ogni unità, a chi si intesta, e — soprattutto — cosa succede quando un piano rate se lo prende e compare il lucchetto.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — Cosa sono -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Che cosa registri qui</h3>
            <p class="mb-3">
              I saldi iniziali sono il <strong>punto di partenza</strong> di ogni unità immobiliare: i debiti e i crediti che si porta dietro da prima, perché arriva da un altro gestionale o dall'esercizio precedente. Non sono spese di quest'anno: sono la fotografia del "dovuto" e del "già versato" al momento in cui la contabilità comincia.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Ogni saldo appartiene a <strong>una gestione</strong> (ordinaria, straordinaria, riscaldamento…). È la separazione dei fondi richiesta dall'<strong>art. 1130-bis c.c.</strong>: un credito maturato sull'ordinaria non copre da solo un debito sulla straordinaria.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Un'unità può avere <strong>più righe nella stessa gestione</strong>: per esempio un debito del venditore e un credito dell'acquirente. Non c'è bisogno di comprimerli in un unico numero.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Il saldo <em>finale</em> non si scrive mai qui: è sempre calcolato al volo come <em>iniziale + rate emesse − incassi</em>.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 2 — Il segno -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il segno lo scegli col pulsante, non col meno</h3>
            <p class="mb-3">
              Gli importi si inseriscono sempre <strong>positivi</strong>. A dire se è un dare o un avere è il pulsante da cui parti, non un segno digitato nel campo.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-rose-600 dark:text-rose-400">
                  <TrendingDown class="w-4 h-4" />
                  <h4 class="font-bold">Aggiungi debito</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Il condòmino <strong>deve</strong> al condominio. Verrà richiesto insieme alle rate.
                </p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-emerald-600 dark:text-emerald-400">
                  <TrendingUp class="w-4 h-4" />
                  <h4 class="font-bold">Aggiungi credito</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Il condominio <strong>deve</strong> al condòmino. Verrà scalato da quanto gli si chiede.
                </p>
              </div>
            </div>
            <div class="mt-4 p-4 rounded-lg bg-slate-100 dark:bg-slate-800 flex gap-3 text-[13px]">
              <Info class="w-5 h-5 text-slate-500 shrink-0" />
              <div>
                <strong>Il campo non accetta il segno meno, ed è voluto.</strong> Prima lo accettava e poi lo ignorava: scrivere <code>-100</code> mentre si modificava un debito non lo trasformava in credito, restava un debito di 100. Un valore rifiutato subito è meglio di uno accettato e cambiato in silenzio. Per invertire il senso di una riga si elimina e si reinserisce dal pulsante giusto.
              </div>
            </div>
          </section>

          <!-- 3 — A chi è intestato -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">A chi è intestato</h3>
            <p class="mb-3">
              Alla creazione scegli se il saldo appartiene all'unità nel suo insieme o a una persona precisa. La scelta cambia chi se lo vedrà addebitato quando le rate vengono emesse.
            </p>
            <div class="space-y-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-indigo-700 dark:text-indigo-400">
                  <Building2 class="w-4 h-4" />
                  <h4 class="font-bold">Intero immobile (solidale)</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Il saldo resta dell'unità. All'emissione viene ripartito <strong>in automatico fra i proprietari</strong>, ciascuno in proporzione alla propria quota di proprietà. È la scelta giusta quando il pregresso è dell'appartamento e non di una persona in particolare — ed è il modo in cui il sistema regge la solidarietà del subentro (<strong>art. 63 disp. att. c.c.</strong>).
                </p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-slate-700 dark:text-slate-300">
                  <User class="w-4 h-4" />
                  <h4 class="font-bold">Soggetto specifico (personale)</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Il saldo è intestato a una persona sola, e solo a lei verrà chiesto. È la scelta per le compravendite e i cambi di inquilino, quando sai <em>chi</em> ha lasciato scoperto quel numero.
                </p>
              </div>
            </div>
          </section>

          <!-- 4 — Come entrano nel piano rate -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Come finiscono nel piano rate</h3>
            <p class="mb-3">
              I saldi non si riscuotono da soli: entrano nel prossimo piano rate che li assorbe. Al momento di crearlo scegli <strong>come</strong> distribuirli:
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Rata 0, in una rata separata</strong> — la scelta consigliata: il pregresso resta leggibile e distinto dalle quote dell'anno.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Sommato alla prima rata ordinaria</strong> — meno righe, ma il pregresso si confonde con la competenza dell'anno.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Spalmato su tutte le rate</strong> — più leggero per il condòmino, più difficile da spiegare in assemblea.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 5 — Il lucchetto -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Il lucchetto: chi lo chiude e quando</h3>
            <p class="mb-3">
              Quando una riga di saldo mostra il lucchetto <Lock class="w-3.5 h-3.5 inline-block -mt-0.5 text-amber-600" /> significa che <strong>un piano rate se l'è presa e l'ha già portata fuori</strong>. Non è un blocco generico: ha un proprietario preciso, e la modale te ne dice il nome.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Non scatta alla creazione del piano.</strong> Finché il piano è solo generato, i saldi restano modificabili ed eliminabili: a quel punto il sistema è ancora disposto a buttare via tutte le quote e a riscriverle, ed è quello che fa «Ricalcola».</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Scatta all'emissione o al primo incasso.</strong> Da lì il numero è uscito dallo studio — è a giornale, o qualcuno l'ha pagato — e non si riscrive più: si rettifica.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Vale per la singola riga</strong>, non per l'intera gestione. Se un saldo è finito in un piano emesso, gli altri della stessa gestione restano liberi: puoi ancora aggiungere, correggere ed eliminare.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Si riapre da solo.</strong> Eliminando il piano rate che lo teneva, il lucchetto cade e il saldo torna disponibile per il piano successivo.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 6 — Correggere dopo -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ho sbagliato un saldo già bloccato</h3>
            <p class="mb-3">
              Dipende da una cosa sola: se qualcuno ha già pagato.
            </p>
            <div class="space-y-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Nessun incasso registrato</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Apri il piano rate e <strong>annulla le emissioni</strong>: il saldo torna modificabile senza bisogno di eliminare il piano. Se invece elimini il piano, il lucchetto si riapre lo stesso.
                </p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 mb-1">Incassi già registrati</h4>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">
                  Il passato non si riscrive: registra un <strong>Movimento di Storno</strong> che compensa l'errore, cioè un nuovo debito o un nuovo credito che porta il netto al valore giusto. Il Libro Giornale resta integro e la correzione resta visibile a chi la rileggerà.
                </p>
              </div>
            </div>
            <div class="mt-4 p-4 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex gap-3 text-[13px]">
              <TriangleAlert class="w-5 h-5 text-amber-600 shrink-0" />
              <div>
                <strong>Non serve eliminare il piano per correggere un saldo.</strong> È la strada più drastica e quasi mai la più breve: annullare le emissioni basta, e ti lascia il piano con tutte le sue impostazioni.
              </div>
            </div>
          </section>

          <!-- 7 — Lucchetto orfano -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Un lucchetto che nessuno rivendica</h3>
            <p class="mb-3">
              Su contabilità create con versioni precedenti può capitare di trovare una riga bloccata senza che nessun piano rate la reclami: il piano che l'aveva presa è stato cancellato o ricalcolato, e il lucchetto è rimasto chiuso da solo.
            </p>
            <div class="p-4 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900 flex gap-3 text-[13px]">
              <LockKeyholeOpen class="w-5 h-5 text-emerald-600 shrink-0" />
              <div>
                In quel caso la modale mostra un pulsante di <strong>sblocco</strong>: il saldo torna libero e la gestione si riallinea da sé. È sicuro, perché nessun piano lo sta usando.
              </div>
            </div>
            <p class="mt-3 mb-2">Lo sblocco manuale viene invece rifiutato in due casi, ed entrambi sono corretti:</p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Se un piano lo rivendica davvero</strong>, la strada resta agire su quel piano — annullare le emissioni o eliminarlo — non forzare la riga.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span><strong>Se è un debito verso un fornitore</strong> e non di un condòmino: vive in questa tabella ma sta volutamente fuori dai piani rate, quindi non è un lucchetto da aprire.</span>
                </li>
              </ul>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
