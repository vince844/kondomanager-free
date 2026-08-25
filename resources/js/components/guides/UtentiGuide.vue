<script setup lang="ts">
/**
 * Guida della sezione «Utenti e accessi».
 *
 * Nasce con la beta.55, che ha reso efficace la sospensione e ha introdotto le regole su chi può
 * concedere quale ruolo: erano comportamenti nuovi e nessuna superficie li spiegava. La guida
 * in-app è quella che legge **chi sta usando il programma in quel momento**, quindi è il primo
 * posto dove un cambio di regola va raccontato, non l'ultimo.
 */
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import {
  UsersRound, ChevronRight, ShieldCheck, MonitorX, KeyRound, Send, Filter, TriangleAlert,
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
              <UsersRound class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: utenti e accessi</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Chi entra nel programma, con quali poteri, e cosa succede davvero quando sospendi qualcuno.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- 1 — Utente non è anagrafica -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Un utente non è un'anagrafica</h3>
            <p class="mb-3">
              L'<strong>anagrafica</strong> è la persona: quella che possiede un'unità, riceve il riparto e paga le rate. L'<strong>utente</strong> è un accesso al programma. Le due cose si collegano — un condòmino che vuole entrare nel portale ha bisogno di entrambe — ma restano separate, e la maggior parte delle anagrafiche non ha nessun utente collegato.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Eliminare un utente <strong>non</strong> cancella la sua anagrafica: i dati contabili restano intatti.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Un'anagrafica può essere collegata a <strong>un solo utente</strong>: se provi a riusarla, il programma te lo dice e ti nomina chi la sta già usando.</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- 2 — I ruoli -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">I quattro ruoli di sistema</h3>
            <p class="mb-3">
              Il ruolo è il pacchetto di permessi che una persona si porta dietro. Quelli di sistema non si possono modificare né cancellare — è voluto: sono il pavimento su cui poggia tutto il resto. Puoi però creare ruoli tuoi e comporli permesso per permesso.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-indigo-600 dark:text-indigo-400">
                  <ShieldCheck class="w-4 h-4" />
                  <h4 class="font-bold">Amministratore</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">Tutti i permessi. È l'unico che governa gli accessi degli altri.</p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-emerald-600 dark:text-emerald-400">
                  <ShieldCheck class="w-4 h-4" />
                  <h4 class="font-bold">Collaboratore</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">Lavora nello studio: crea e corregge utenti, anagrafiche, condomìni, comunicazioni.</p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-slate-600 dark:text-slate-400">
                  <UsersRound class="w-4 h-4" />
                  <h4 class="font-bold">Utente</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">Il condòmino: vede il proprio, apre segnalazioni, commenta.</p>
              </div>
              <div class="p-4 bg-white dark:bg-slate-900 rounded-lg border shadow-sm">
                <div class="flex items-center gap-2 mb-1 text-amber-600 dark:text-amber-400">
                  <UsersRound class="w-4 h-4" />
                  <h4 class="font-bold">Fornitore</h4>
                </div>
                <p class="text-[13px] text-slate-600 dark:text-slate-400">Vede e commenta le segnalazioni che lo riguardano, niente di più.</p>
              </div>
            </div>
          </section>

          <!-- 3 — Chi concede cosa -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Chi può concedere quale ruolo</h3>
            <p class="mb-3">
              I ruoli <strong>amministratore</strong> e <strong>collaboratore</strong> li assegna solo un amministratore: sono ruoli che governano l'installazione, non semplici etichette. Un collaboratore continua a creare e correggere le utenze dei condòmini — che è il suo mestiere — ma nella sua tendina quei due non compaiono.
            </p>
            <div class="flex gap-3 p-4 bg-amber-50 dark:bg-amber-900/20 rounded-xl border border-amber-200 dark:border-amber-800">
              <KeyRound class="w-5 h-5 text-amber-600 shrink-0" />
              <div>
                Vale anche per i <strong>permessi singoli</strong>: nessuno può concedere un permesso che non ha lui stesso. Se stai modificando qualcuno che ha permessi che tu non possiedi, quelli restano dove sono — salvare la sua scheda non glieli toglie.
              </div>
            </div>
          </section>

          <!-- 4 — La sospensione -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Sospendere: cosa succede davvero</h3>
            <p class="mb-3">
              Sospendere <strong>chiude la porta subito</strong>. Non è un promemoria e non aspetta il prossimo accesso: chi è collegato in quel momento si ritrova alla schermata di ingresso alla prima pagina che apre, e da lì non rientra finché non lo riattivi.
            </p>
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
              <ul class="space-y-2">
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Vale su <strong>tutte le porte</strong>: accesso normale, doppia autenticazione, e la sessione già aperta.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Non cancella niente: anagrafica, documenti, segnalazioni e storico restano al loro posto. È una porta chiusa, non una gomma.</span>
                </li>
                <li class="flex gap-2">
                  <ChevronRight class="w-4 h-4 text-indigo-500 shrink-0 mt-0.5" />
                  <span>Riattivare è un clic sulla stessa riga, e la persona rientra con tutto quello che aveva prima.</span>
                </li>
              </ul>
            </div>
            <div class="flex gap-3 mt-3 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl border">
              <MonitorX class="w-5 h-5 text-rose-600 shrink-0" />
              <div>
                Sospendere è un potere a sé: richiede il permesso <strong>«Sospendi utenti»</strong>, che di partenza ha solo l'amministratore. Chi non ce l'ha non vede nemmeno la voce nel menù.
              </div>
            </div>
          </section>

          <!-- 5 — L'ultimo amministratore -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">L'ultimo amministratore non si tocca</h3>
            <div class="flex gap-3 p-4 bg-rose-50 dark:bg-rose-900/20 rounded-xl border border-rose-200 dark:border-rose-800">
              <TriangleAlert class="w-5 h-5 text-rose-600 shrink-0" />
              <div>
                Se resta un solo amministratore attivo, il programma non ti lascia sospenderlo, eliminarlo né cambiargli ruolo — e nessuno può fare nessuna di queste tre cose <strong>a sé stesso</strong>. Da un'installazione senza amministratori non si esce dall'interfaccia: servirebbe intervenire sul database.
              </div>
            </div>
            <p class="mt-3">
              Se devi passare la mano, l'ordine è: crei o promuovi il nuovo amministratore <em>prima</em>, e solo dopo togli i poteri a quello vecchio.
            </p>
          </section>

          <!-- 6 — Reinvito -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Reinvitare azzera la password</h3>
            <div class="flex gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl border">
              <Send class="w-5 h-5 text-indigo-600 shrink-0" />
              <div>
                «Reinvita» rimanda l'email di attivazione e <strong>svuota la password attuale</strong>: è utile per chi non è mai riuscito a entrare, ma su un utente attivo lo lascia fuori finché non ne imposta una nuova dal link ricevuto. Non è un semplice sollecito.
              </div>
            </div>
          </section>

          <!-- 7 — Trovare qualcuno -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Trovare qualcuno nell'elenco</h3>
            <div class="flex gap-3 p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl border">
              <Filter class="w-5 h-5 text-indigo-600 shrink-0" />
              <div>
                Oltre alla ricerca per nome ci sono due filtri, <strong>ruolo</strong> e <strong>stato</strong>, che si combinano fra loro. Lavorano sull'archivio intero e non sulle righe della pagina che stai guardando: «tutti i sospesi» è tutti, anche quelli che stanno a pagina otto.
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
