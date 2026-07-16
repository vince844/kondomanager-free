<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, Info, DatabaseBackup, ShieldAlert, RotateCcw, CheckCircle2 } from 'lucide-vue-next';

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
              <DatabaseBackup class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Backup e ripristino</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Cosa contiene l'archivio di backup, come custodirlo e la procedura passo-passo per ripristinare o trasferire l'installazione.
          </SheetDescription>
        </SheetHeader>

        <Tabs defaultValue="contenuto" class="w-full">
          <TabsList class="grid w-full grid-cols-3 mb-6">
            <TabsTrigger value="contenuto">Contenuto</TabsTrigger>
            <TabsTrigger value="ripristino">Ripristino</TabsTrigger>
            <TabsTrigger value="sicurezza">Sicurezza</TabsTrigger>
          </TabsList>

          <!-- TAB: Contenuto -->
          <TabsContent value="contenuto" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Cosa c'è dentro lo zip</h3>
              <p class="mb-3">
                Ogni backup <strong>completo</strong> è un archivio autosufficiente: contiene tutto il necessario per trasferire o ripristinare KondoManager su qualsiasi server. Scegliendo invece <strong>"Solo database"</strong> alla creazione, l'archivio contiene soltanto il dump del database e il manifest — più veloce e leggero, utile come salvataggio rapido prima di un'operazione delicata, ma non sufficiente da solo per un trasferimento completo.
              </p>
              <ul class="space-y-3 list-disc pl-5">
                <li>
                  <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">db/database.sql</code> —
                  il dump completo del database, già pronto per essere importato con phpMyAdmin o dal client MySQL. Non richiede utenti o privilegi speciali.
                </li>
                <li>
                  <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/.env</code> —
                  il file di configurazione dell'applicazione, con la <strong>APP_KEY</strong> (la chiave di cifratura, non ricostruibile) e le credenziali.
                </li>
                <li>
                  <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/storage/app/...</code> —
                  tutti i documenti caricati: allegati dei condomini, firme, immagini.
                </li>
                <li>
                  <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">manifest.json</code> —
                  la "carta d'identità" del backup: versione dell'applicazione, elenco delle migrazioni, conteggi e checksum di integrità.
                </li>
              </ul>
              <div class="mt-4 p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
                <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                  Il file <strong>manifest.json</strong> è generato automaticamente: non va modificato né rimosso dall'archivio. È la base del <strong>ripristino guidato dall'interfaccia</strong> (scheda Ripristino), che lo usa per verificare compatibilità di versione e integrità prima di toccare qualsiasi dato.
                </div>
              </div>
            </section>
          </TabsContent>

          <!-- TAB: Ripristino -->
          <TabsContent value="ripristino" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ripristinare dal pannello (con un clic)</h3>
              <p class="mb-3">
                Accanto a ogni backup completato trovi il pulsante <strong>Ripristina</strong> (l'icona a freccia circolare <RotateCcw class="inline w-3.5 h-3.5 align-text-bottom" />): riporta l'installazione allo stato di quel backup senza phpMyAdmin né terminale.
              </p>
              <ul class="space-y-3 list-decimal pl-5">
                <li>Premi <strong>Ripristina</strong> sul backup desiderato: si apre una finestra di conferma con data, tipo e dimensione.</li>
                <li>Se il backup è <strong>protetto da password</strong>, inseriscila (è quella con cui l'hai cifrato).</li>
                <li>Lascia attivo il <strong>backup di sicurezza</strong> (consigliato): fotografa lo stato attuale del database prima di sovrascriverlo, così puoi tornare indietro.</li>
                <li>Conferma con la <strong>password del tuo account</strong> e avvia.</li>
                <li>L'applicazione entra in <strong>modalità ripristino</strong> e mostra una schermata di avanzamento. Al termine, tutti gli utenti (te compreso) dovranno effettuare di nuovo l'accesso.</li>
              </ul>
              <div class="mt-4 p-4 rounded-lg border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800/50 flex gap-3">
                <CheckCircle2 class="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-emerald-800 dark:text-emerald-200/90 leading-relaxed">
                  <strong>Versioni diverse?</strong> Nessun problema: se ripristini un backup di una versione più vecchia, KondoManager applica automaticamente gli aggiornamenti del database necessari e riallinea la versione. Non devi installare prima la vecchia versione.
                </div>
              </div>
            </section>

            <section>
              <h3 class="text-base font-bold text-slate-900 dark:text-white mb-3">Trasferire su un altro server</h3>
              <p class="mb-3">
                Per spostare l'installazione su un nuovo dominio o hosting, la strada naturale è ripartire da un'installazione pulita e ripristinare lì il backup. In alternativa, la procedura manuale funziona ovunque (servono phpMyAdmin e accesso ai file):
              </p>
              <ul class="space-y-2 list-decimal pl-5 text-[13px]">
                <li>Estrai lo zip e importa <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">db/database.sql</code> nel database del nuovo server (svuotalo prima se non è vuoto).</li>
                <li>Copia il contenuto di <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/storage/app</code> dentro <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">storage/app</code>, sovrascrivendo.</li>
                <li>Copia <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/.env</code> come <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">.env</code>, aggiornando <strong>solo</strong> le credenziali database (<code class="text-[12px]">DB_*</code>) e l'indirizzo del sito (<code class="text-[12px]">APP_URL</code>).</li>
              </ul>
              <div class="mt-4 p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                  Nel trasferimento manuale <strong>non rigenerare mai la APP_KEY</strong> del file .env del backup: è la chiave con cui sono cifrati alcuni dati (autenticazione a due fattori, password email). Con una chiave diversa quei dati diventano illeggibili e vanno riconfigurati.
                </div>
              </div>
            </section>
          </TabsContent>

          <!-- TAB: Sicurezza -->
          <TabsContent value="sicurezza" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Buone pratiche di custodia</h3>
              <ul class="space-y-3 list-disc pl-5 mb-4">
                <li>
                  <strong>Scarica e porta fuori dal server.</strong> Un backup che vive solo sul server protegge dagli errori, non dai guasti del server stesso. La regola d'oro è averne una copia in un posto diverso (computer locale, disco esterno, cloud personale).
                </li>
                <li>
                  <strong>Trattalo come un documento riservato.</strong> L'archivio contiene l'intero database, i documenti dei condomini e le chiavi dell'applicazione: chiunque lo possieda può leggere tutto. Conservalo in una posizione protetta.
                </li>
                <li>
                  <strong>Verifica i trasferimenti.</strong> Accanto a ogni backup trovi l'impronta <strong>SHA-256</strong>: dopo aver copiato il file altrove, puoi ricalcolarla e confrontarla per essere certo che la copia sia integra al byte.
                </li>
                <li>
                  <strong>Scegli il momento giusto.</strong> Il backup fotografa i dati "a caldo": eseguilo preferibilmente quando nessuno sta inserendo pagamenti o caricando documenti.
                </li>
                <li>
                  <strong>Password opzionale (AES-256).</strong> Imposta una volta sola la password di protezione nelle impostazioni in fondo alla pagina (accanto al numero di backup da conservare): l'interruttore "Proteggi con la password salvata (AES-256)" si attiva da solo e ogni backup protetto viene cifrato per intero. La password è custodita cifrata sul server e non finisce mai dentro gli archivi. Attenzione: password dimenticata = backup irrecuperabile, e su Windows serve 7-Zip per aprire gli zip cifrati (Esplora Risorse non li supporta; su Mac va bene Keka).
                </li>
                <li>
                  <strong>L'elenco dei file resta visibile: è normale.</strong> Aprendo un archivio cifrato, il tuo programma di compressione mostra subito i nomi dei file senza chiedere nulla: nel formato zip la cifratura protegge il <em>contenuto</em> dei file, non l'elenco. La password viene chiesta al primo file che apri o estrai — prova con <code class="text-[12px]">db/database.sql</code>: è la verifica che la protezione sta funzionando.
                </li>
              </ul>
              <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
                <ShieldAlert class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                  I backup restano in <code class="text-[12px]">storage/app/backups</code>, fuori dalla cartella pubblica del sito: non sono raggiungibili via web e si scaricano solo da questa pagina, con un account amministratore.
                </div>
              </div>
            </section>
          </TabsContent>
        </Tabs>
      </div>
    </SheetContent>
  </Sheet>
</template>
