<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Mail, Server, Terminal, ShieldCheck, CheckCircle2, Key } from 'lucide-vue-next';

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
              <Mail class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: Configurazione Server Email</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Scopri come configurare i parametri SMTP, garantire la massima consegnabilità delle email ed evitare la cartella Spam.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- Scelta del Driver -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Server class="w-5 h-5 text-blue-500" /> 1. Scelta del Driver (SMTP vs Sendmail)
            </h3>
            <div class="grid gap-3 mb-4">
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle2 class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">SMTP (Raccomandato per produzione)</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Si collega direttamente al server del tuo gestore posta (Aruba, Gmail, Outlook, SMTP2GO, ecc.). Offre il 100% di tracciabilità, cifratura SSL/TLS sicura e previene il blocco delle notifiche di sollecito e ricevuta.
                  </p>
                </div>
              </div>

              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <Terminal class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Sendmail (Solo per server dedicati)</h4>
                  <p class="text-[13px] text-slate-600 dark:text-slate-400 leading-relaxed">
                    Utilizza l'eseguibile di sistema dell'hosting web. È veloce ma non richiede autenticazione forte; le mail rischiano di finire in Spam se il server di hosting non ha IP dedicato e record SPF/DKIM configurati.
                  </p>
                </div>
              </div>
            </div>
          </section>

          <!-- Configurazione parametri SMTP -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Key class="w-5 h-5 text-emerald-500" /> 2. Parametri SMTP dei gestori più diffusi
            </h3>
            <p class="mb-3">
              Ecco i dati predefiniti per i provider email più utilizzati dagli studi di amministrazione condominiale:
            </p>

            <div class="space-y-3">
              <!-- Aruba -->
              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 text-xs uppercase tracking-wider mb-1">Aruba Email / Pec</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>smtps.aruba.it</code> | Porta: <code>465</code> (SSL) o <code>587</code> (TLS)</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Username: il tuo indirizzo email completo (es. <code>info@studiocondominio.it</code>).</p>
              </div>

              <!-- Gmail -->
              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 text-xs uppercase tracking-wider mb-1">Google Gmail / Google Workspace</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>smtp.gmail.com</code> | Porta: <code>587</code> (TLS)</p>
                <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-0.5 font-medium">
                  ⚠️ Richiede l'uso di una <strong>"Password per le app"</strong> generata dalla sicurezza dell'account Google (non la tua password solita).
                </p>
              </div>

              <!-- SMTP2GO / Brevo -->
              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 text-xs uppercase tracking-wider mb-1">Servizi Transazionali (SMTP2GO, Brevo, Mailgun)</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>mail.smtp2go.com</code> | Porta: <code>2525</code> o <code>587</code> (TLS)</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Scelta ideale per volumi elevati e reportistica in tempo reale sulle email consegnate o aperte.</p>
              </div>
            </div>
          </section>

          <!-- Consigli Deliverability -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <ShieldCheck class="w-5 h-5 text-indigo-500" /> 3. Consigli per evitare la cartella SPAM
            </h3>
            <ul class="space-y-2 list-disc pl-5 text-xs text-slate-600 dark:text-slate-400">
              <li>
                <strong>Coerenza dell'indirizzo "From":</strong> Assicurati che l'indirizzo <em>"Mittente (Address)"</em> sia identico all'account con cui ti autentichi nel server SMTP.
              </li>
              <li>
                <strong>Pulsante di prova:</strong> Prima di salvare la configurazione, digita il tuo indirizzo personale ed esegui il test per verificare la connessione immediata.
              </li>
            </ul>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
