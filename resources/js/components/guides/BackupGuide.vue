<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, Info, DatabaseBackup, ShieldAlert } from 'lucide-vue-next';

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
                Ogni backup è un archivio <strong>completo e autosufficiente</strong>: contiene tutto il necessario per trasferire o ripristinare KondoManager su qualsiasi server.
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
                  Il file <strong>manifest.json</strong> è generato automaticamente: non va modificato né rimosso dall'archivio. Le future versioni di KondoManager lo useranno per il <strong>ripristino guidato dall'interfaccia</strong>, verificando compatibilità di versione e integrità prima di toccare qualsiasi dato.
                </div>
              </div>
            </section>
          </TabsContent>

          <!-- TAB: Ripristino -->
          <TabsContent value="ripristino" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Ripristinare o trasferire l'installazione</h3>
              <p class="mb-3">
                La procedura è manuale ma semplice, e funziona su qualsiasi hosting. Servono solo phpMyAdmin (o un client MySQL) e l'accesso ai file del server (FTP o file manager).
              </p>
              <ul class="space-y-3 list-decimal pl-5">
                <li>
                  Prepara sul nuovo server una <strong>installazione pulita di KondoManager della stessa versione</strong> indicata nel manifest del backup (la versione è visibile anche nel nome del file di dump). Non eseguire il wizard di configurazione.
                </li>
                <li>Estrai lo zip di backup sul tuo computer.</li>
                <li>
                  Importa <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">db/database.sql</code> nel database del nuovo server (phpMyAdmin → scheda <strong>Importa</strong>). Se il database non è vuoto, svuotalo prima.
                </li>
                <li>
                  Copia il contenuto di <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/storage/app</code> dentro la cartella <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">storage/app</code> del server, sovrascrivendo.
                </li>
                <li>
                  Copia <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">files/.env</code> come <code class="text-[12px] bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">.env</code> nella cartella principale, poi aggiorna <strong>solo</strong> i valori che cambiano col server: credenziali database (<code class="text-[12px]">DB_*</code>) e indirizzo del sito (<code class="text-[12px]">APP_URL</code>).
                </li>
                <li>Visita il sito ed effettua il login: i dati e i documenti saranno tutti al loro posto.</li>
              </ul>
              <div class="mt-4 p-4 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                  <strong>Non rigenerare mai la APP_KEY</strong> presente nel file .env del backup: è la chiave con cui sono cifrati alcuni dati. Con una chiave diversa, quei dati diventerebbero illeggibili per sempre. Per lo stesso motivo, se le versioni non coincidono installa prima la versione del backup e aggiorna dopo il ripristino.
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
