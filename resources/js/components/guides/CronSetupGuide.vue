<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AlertTriangle, Terminal, Globe, Server, Info } from 'lucide-vue-next';

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
              <Server class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Configurazione cron job</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Scegli il tuo ambiente di hosting per visualizzare le istruzioni dettagliate su come configurare il processo cron.
          </SheetDescription>
        </SheetHeader>

        <Tabs defaultValue="cronjob" class="w-full">
          <TabsList class="grid w-full grid-cols-3 mb-6">
            <TabsTrigger value="cronjob">cron-job.org</TabsTrigger>
            <TabsTrigger value="cpanel">cPanel</TabsTrigger>
            <TabsTrigger value="plesk">Plesk / VPS</TabsTrigger>
          </TabsList>

          <!-- TAB: cron-job.org -->
          <TabsContent value="cronjob" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">La soluzione per hosting condivisi (senza terminale)</h3>
              <p class="mb-3">
                Se il tuo hosting non permette di creare veri cron job (o ti dà problemi di permessi come su Altervista), puoi usare <strong>cron-job.org</strong>. Questo servizio gratuito "chiamerà" il tuo gestionale ogni minuto tramite un webhook, come se visitasse una pagina web segreta.
              </p>
            </section>
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">Passo 1: Abilita il webhook</h3>
              <p class="mb-3">Assicurati che l'interruttore "Abilita scheduler esterno" in questa pagina sia acceso e copia il <strong>webhook URL</strong> generato dal sistema.</p>
            </section>
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">Passo 2: Configura cron-job.org</h3>
              <ul class="space-y-2 list-disc pl-5">
                <li>Crea un account gratuito su <a href="https://cron-job.org" target="_blank" class="text-indigo-600 underline">cron-job.org</a></li>
                <li>Clicca su <strong>Create Cronjob</strong>.</li>
                <li>Nel campo URL, incolla il Webhook copiato al Passo 1.</li>
                <li>Nella sezione <strong>Execution Schedule</strong>, imposta l'esecuzione su <strong>Ogni minuto</strong> (Every 1 minute).</li>
                <li>Salva.</li>
              </ul>
              <div class="mt-4 p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
                <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                  Dopo un paio di minuti, vedrai il pallino "Processo Cron" in questa pagina diventare <strong class="text-green-600">Attivo</strong> con dicitura "Webhook".
                </div>
              </div>
            </section>
          </TabsContent>

          <!-- TAB: cPanel -->
          <TabsContent value="cpanel" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Configurazione nativa per cPanel</h3>
              <p class="mb-3">
                cPanel è il pannello più diffuso sugli hosting professionali. La configurazione nativa (via CLI) è più stabile ed efficiente rispetto all'uso del Webhook.
              </p>
            </section>
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">Istruzioni operative</h3>
              <ul class="space-y-3 list-decimal pl-5 mb-4">
                <li>Disattiva il <strong>webhook esterno</strong> tramite l'interruttore in questa pagina (non serve più).</li>
                <li>Accedi al tuo cPanel e cerca la voce <strong>"Cron jobs"</strong> o <strong>"Processi cron"</strong>.</li>
                <li>Nella sezione "Aggiungi nuovo cron job", imposta la frequenza su <strong>Ogni minuto (* * * * *)</strong>.</li>
                <li>Nel campo comando, incolla la riga seguente (adattandola al tuo percorso):</li>
              </ul>
              <div class="bg-slate-900 text-emerald-400 p-4 rounded-xl font-mono text-[11px] overflow-x-auto shadow-inner mb-2">
                /usr/local/bin/php /home/tuosito/public_html/artisan schedule:run >> /dev/null 2>&1
              </div>
              <p class="text-[12px] text-slate-500 mb-2">Attenzione: se usi un percorso diverso per <code>public_html</code> o una diversa versione PHP, modificalo di conseguenza.</p>
              
              <div class="mt-4 p-4 rounded-lg border border-blue-200 bg-blue-50 dark:bg-blue-900/20 dark:border-blue-800/50 flex gap-3">
                <Info class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                <div class="text-[13px] text-blue-800 dark:text-blue-200/90 leading-relaxed">
                  <strong>Nota architetturale:</strong> Su cPanel solitamente non è necessario modificare il file <code>.env</code> come su Plesk. Il timeout di default per i processi PHP eseguiti da Cron su cPanel è permissivo.
                </div>
              </div>
            </section>
          </TabsContent>

          <!-- TAB: Plesk -->
          <TabsContent value="plesk" class="space-y-6 text-sm text-slate-700 dark:text-slate-300">
            <section>
              <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3">Configurazione avanzata (fix errore 500)</h3>
              <p class="mb-3">
                Sui server Plesk o VPS privati, i limiti di tempo del server sono più severi. Mischiare l'avvio delle operazioni con l'elaborazione delle code in un'unica chiamata web genera errori 500. La soluzione è eseguire <strong>due processi separati</strong> tramite terminale.
              </p>
            </section>

            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">1. Modifica del file .env</h3>
              <p class="mb-2">Apri il file <code>.env</code> nella root del progetto e aggiungi o modifica questa impostazione:</p>
              <div class="bg-slate-900 text-emerald-400 p-3 rounded-lg font-mono text-xs overflow-x-auto mb-1 shadow-inner">
                SCHEDULE_QUEUE_WORKER=false
              </div>
              <p class="text-[12px] text-slate-500 mb-2">Con "false", lo scheduler di manutenzione non tenterà più di elaborare anche le code (e-mail, PDF).</p>
              <div class="p-3 rounded-md border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/50 flex gap-2 mb-4">
                <AlertTriangle class="w-4 h-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <div class="text-[12px] text-amber-800 dark:text-amber-200/90 leading-relaxed">
                  <strong>Importante:</strong> Non modificare mai il file <code>config/app.php</code>. Il valore va cambiato solo ed esclusivamente nel file <code>.env</code>.
                </div>
              </div>
            </section>

            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">2. Svuota la cache (Obbligatorio)</h3>
              <p class="mb-2 text-[13px]">Dopo aver modificato il <code>.env</code>, devi ricaricare la configurazione (e pulire eventuali mutex bloccati). Scegli uno dei due percorsi:</p>
              
              <div class="space-y-3 mb-4">
                <div class="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                  <h4 class="font-bold text-slate-800 dark:text-slate-200 text-[13px] mb-1">Percorso A: tramite aggiornamento automatico</h4>
                  <p class="text-[12px] text-slate-600 dark:text-slate-400">Se è disponibile un nuovo aggiornamento dal pannello KondoManager, installalo. L'aggiornamento eseguirà automaticamente lo svuotamento della cache.</p>
                </div>
                
                <div class="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                  <h4 class="font-bold text-slate-800 dark:text-slate-200 text-[13px] mb-1">Percorso B: comando manuale</h4>
                  <p class="text-[12px] text-slate-600 dark:text-slate-400 mb-2">Se non devi aggiornare, esegui questo comando da terminale SSH:</p>
                  <div class="bg-slate-900 text-emerald-400 p-2.5 rounded-md font-mono text-[10px] overflow-x-auto shadow-inner">
                    cd /var/www/vhosts/tuodominio.it/httpdocs && \<br>
                    /opt/plesk/php/8.4/bin/php artisan optimize:clear
                  </div>
                </div>
              </div>
            </section>

            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2">3. Configura i due cron in Plesk</h3>
              <p class="mb-3">Disattiva il <strong>webhook esterno</strong> qui su KondoManager, poi nel pannello Plesk crea <strong>due attività pianificate</strong> ("Ogni minuto" <code>* * * * *</code>).</p>
              <div class="grid gap-3 mb-3">
                <div class="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1 flex items-center gap-2 text-[13px]">
                    <Terminal class="w-3.5 h-3.5 text-emerald-600" /> Cron 1 — Scheduler (manutenzione)
                  </h4>
                  <div class="bg-slate-900 text-emerald-400 p-2.5 rounded-md font-mono text-[10px] overflow-x-auto shadow-inner">
                    cd /var/www/vhosts/tuodominio.it/httpdocs && /opt/plesk/php/8.4/bin/php artisan schedule:run >> /dev/null 2>&1
                  </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800/50 p-3 rounded-lg border border-slate-200 dark:border-slate-800">
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1 flex items-center gap-2 text-[13px]">
                    <Terminal class="w-3.5 h-3.5 text-indigo-600" /> Cron 2 — Queue worker (e-mail, rate)
                  </h4>
                  <div class="bg-slate-900 text-emerald-400 p-2.5 rounded-md font-mono text-[10px] overflow-x-auto shadow-inner">
                    cd /var/www/vhosts/tuodominio.it/httpdocs && /opt/plesk/php/8.4/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /var/www/vhosts/tuodominio.it/httpdocs/storage/logs/worker.log 2>&1
                  </div>
                </div>
              </div>
              <div class="p-3 rounded-md border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800/50 flex gap-2">
                <AlertTriangle class="w-4 h-4 text-red-600 dark:text-red-400 shrink-0 mt-0.5" />
                <div class="text-[12px] text-red-800 dark:text-red-200/90 leading-relaxed">
                  Adatta <code>tuodominio.it</code> e la versione PHP (es. <code>8.4</code>). Assicurati inoltre che nel <code>.env</code> sia presente <code>QUEUE_CONNECTION=database</code>.
                </div>
              </div>
            </section>
            
            <section>
              <h3 class="font-bold text-slate-900 dark:text-white mb-2 mt-4">4. Verifica finale</h3>
              <p class="text-[13px] mb-2">Dopo 2-3 minuti, osserva il widget "Processo Cron" in alto in questa pagina:</p>
              <ul class="space-y-1.5 list-disc pl-5 text-[13px]">
                <li><strong class="text-green-600">Attivo + "system"</strong>: perfetto, i cron nativi girano!</li>
                <li><strong class="text-green-600">Attivo + "webhook"</strong>: sta ancora girando cron-job.org, disattivalo.</li>
                <li><strong class="text-red-600">Rosso (Fermo/Errore)</strong>: i cron Plesk non stanno partendo, controlla l'output degli errori nel pannello Plesk.</li>
              </ul>
            </section>
          </TabsContent>
        </Tabs>

      </div>
    </SheetContent>
  </Sheet>
</template>
