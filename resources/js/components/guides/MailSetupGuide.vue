<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La «Guida» della pagina «Configurazione email», nel cassetto (decisione 572, proposta 13): le indicazioni sulla posta dello studio, compresi i due
 * riquadri che stavano dentro la card (una casella di un grande fornitore, gli altri fornitori) e la posta del server, e la parte sulla casella PEC, con chi
 * tratta i dati per conto dello studio. Dalla decisione 577: la ricevuta PEC che il programma legge e archivia, «Togli la casella PEC», e la posta mai
 * provata che ha un consiglio e non si ferma. Dalla 1.12.0-beta.13 anche «I promemoria per email»: quello delle convocazioni da spedire.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { BellRing, CheckCircle2, Globe, Key, Mail, MailCheck, Server, ShieldCheck, Terminal } from 'lucide-vue-next';

defineProps<{
    open: boolean;
}>();

defineEmits(['update:open']);
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-caselle">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-indigo-100 text-indigo-700 rounded-lg dark:bg-indigo-900 dark:text-indigo-300">
              <Mail class="w-6 h-6" />
            </div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: le caselle di posta dello studio</SheetTitle>
          </div>
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Come si imposta e si prova la posta dello studio, da cui partono le email ai condòmini, e la casella PEC, da cui partono le convocazioni e le lettere a chi è
            in ritardo che mandi per PEC.
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">

          <!-- La posta: come si manda -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Server class="w-5 h-5 text-blue-500" /> La posta dello studio: come si manda
            </h3>
            <div class="grid gap-3 mb-4">
              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <CheckCircle2 class="w-5 h-5 text-emerald-600 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">Un server di posta (SMTP), il consigliato</h4>
                  <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                    Si collega direttamente al server del tuo gestore posta (Aruba, Gmail, Outlook, SMTP2GO, ecc.), con cifratura SSL/TLS. Il programma registra ogni messaggio che il server accetta, e senza un server lo segna come non partito; «accettato» non vuol dire «consegnato». Riduce il rischio che notifiche e ricevute finiscano nella posta indesiderata.
                  </p>
                </div>
              </div>

              <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                <Terminal class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-900 dark:text-white mb-1">La posta del server (Sendmail), solo su un server dedicato</h4>
                  <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
                    Usa il programma di posta del server dove gira KondoManager. Va scelta solo se il server è un VPS o un server dedicato con Postfix o Exim installato
                    e i record SPF e DKIM impostati nel DNS del dominio del mittente. Su un hosting condiviso come Altervista i server di posta esterni sono bloccati: scarica i PDF e mandali dal tuo programma di posta.
                  </p>
                </div>
              </div>
            </div>
          </section>

          <!-- I dati dei gestori più diffusi, compresi i due riquadri che stavano nella card -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <Key class="w-5 h-5 text-emerald-500" /> I dati dei gestori più diffusi
            </h3>
            <p class="mb-3">
              I dati della posta in uscita dei gestori più usati dagli studi di amministrazione condominiale:
            </p>

            <div class="space-y-3">
              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 text-sm mb-1">Aruba, posta e PEC</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>smtps.aruba.it</code> | Porta: <code>465</code> (SSL) o <code>587</code> (TLS)</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Utente: il tuo indirizzo email completo (es. <code>info@studiocondominio.it</code>).</p>
              </div>

              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                <h4 class="font-bold text-slate-800 dark:text-slate-200 text-sm mb-1">Gmail e Google Workspace, va bene per tutti</h4>
                <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>smtp.gmail.com</code> | Porta: <code>587</code> (TLS)</p>
                <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5 font-medium">
                  Attiva la verifica in due passaggi e genera una <strong>password per le app</strong> nella sicurezza dell'account Google: va scritta al posto della
                  tua password solita. Funziona su qualsiasi hosting, senza impostare il DNS.
                </p>
              </div>

              <div class="p-3 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 flex items-start gap-2">
                <Globe class="w-4 h-4 text-blue-500 mt-0.5 shrink-0" />
                <div>
                  <h4 class="font-bold text-slate-800 dark:text-slate-200 text-sm mb-1">Gli altri fornitori (SMTP2GO, Brevo, Mailgun)</h4>
                  <p class="text-xs text-slate-600 dark:text-slate-400">Host: <code>mail.smtp2go.com</code> | Porta: <code>2525</code> o <code>587</code> (TLS)</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Chiedono di verificare il dominio del mittente con i record DNS (SPF e DKIM): scegli
                    questa strada solo se hai un dominio tuo e l'accesso al suo pannello DNS. Sono adatti a tanti invii, con il resoconto di quelli consegnati.</p>
                </div>
              </div>
            </div>
          </section>

          <!-- Per non finire nella posta indesiderata -->
          <section>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <ShieldCheck class="w-5 h-5 text-indigo-500" /> Per non finire nella posta indesiderata
            </h3>
            <ul class="space-y-2 list-disc pl-5 text-xs text-slate-600 dark:text-slate-400">
              <li>
                <strong>Indirizzo del mittente:</strong> dipende dal gestore. Con una casella di posta (Aruba, Gmail) è l'indirizzo della casella con cui ti autentichi (con Gmail, anche un alias che hai aggiunto nell'account). Con un servizio di invio (SMTP2GO, Brevo, Mailgun) il nome utente è quello che ti dà il servizio, anche quando ha la forma di un indirizzo email: il mittente dev'essere un indirizzo, o un dominio, che hai verificato nel pannello del servizio, altrimenti il servizio rifiuta l'invio anche se server e password sono giusti.
              </li>
              <li>
                <strong>Pulsante di prova:</strong> Prima di salvare la configurazione, digita il tuo indirizzo personale ed esegui il test per verificare la connessione immediata.
              </li>
              <li>
                <strong>Se la prova fallisce:</strong> il motivo è scritto sotto la prova e, se la prova arriva all'invio, anche in «Registri», scheda «Email».
              </li>
              <li>
                <!-- prova: resources/js/components/impostazioni/caselle/CasellaDellaPosta.vue:99 «const conModifica» -->
                <strong>Cambiare una casella:</strong> «Modifica», sulla sua riga, apre i campi. Quando la riga propone un'altra cosa («Prova l'invio», «Riscrivi la password»), «Modifica» è accanto. Con un gestore nuovo scrivi i suoi dati e salva: prendono il posto dei vecchi, e la prova fatta con i dati di prima non vale per i nuovi.
              </li>
              <li>
                <strong>Mai provata:</strong> dopo un aggiornamento la posta che va da tempo risulta «Da provare», con il consiglio «Fai una prova quando puoi: gli invii continuano.». Non è un allarme: le email partono lo stesso.
              </li>
            </ul>
          </section>

          <!-- La casella PEC (scena 2 della bozza) -->
          <section data-testid="guida-pec">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <MailCheck class="w-5 h-5 text-sky-500" /> La casella PEC
            </h3>
            <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-slate-50 dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-800/50">
              <div class="px-4 py-3">
                <!-- prova: app/Services/Posta/CaselleDelloStudio.php:71 «le convocazioni e le lettere per PEC partono da qui» -->
                <b class="block text-slate-900 dark:text-white mb-0.5">A che cosa serve</b>
                Le convocazioni delle assemblee e le lettere a chi è in ritardo che scegli di mandare per PEC: sollecito, diffida, messa in mora.
              </div>
              <div class="px-4 py-3">
                <b class="block text-slate-900 dark:text-white mb-0.5">Dove trovi i dati</b>
                Nelle istruzioni del tuo gestore PEC, alla voce «posta in uscita» o «SMTP»: il nome del server, la porta e la cifratura. Utente e password sono quelli con
                cui entri nella casella.
              </div>
              <div class="px-4 py-3">
                <b class="block text-slate-900 dark:text-white mb-0.5">La prova</b>
                Mandala alla stessa casella PEC. Se nella casella arrivano la ricevuta di accettazione e quella di consegna, la casella è pronta per le convocazioni e
                per le lettere.
              </div>
              <div class="px-4 py-3">
                <!-- prova: resources/js/components/gestionale/lettere/RegistroLettere.vue:303 «Trascina qui la ricevuta di consegna (.eml)» -->
                <b class="block text-slate-900 dark:text-white mb-0.5">Le ricevute</b>
                Restano nella casella PEC: il programma non la legge. Per ogni convocazione e per ogni lettera salvi la ricevuta di consegna e la trascini sulla sua riga
                (della convocazione, o della lettera nella corrispondenza della persona); il programma ne legge il giorno e l'ora, e la archivia sulla riga, da dove si
                scarica.
              </div>
              <div class="px-4 py-3">
                <b class="block text-slate-900 dark:text-white mb-0.5">Togli la casella PEC</b>
                Per un cambio di gestore, o per fermare le PEC: «Togli la casella PEC», in fondo alla casella aperta, chiede conferma. La casella torna «Da impostare» e
                «Manda le PEC» lo dice; le PEC già partite restano.
              </div>
              <div class="px-4 py-3">
                <b class="block text-slate-900 dark:text-white mb-0.5">Chi tratta i dati</b>
                Il gestore della PEC, come quello della posta, tratta i dati dei condòmini per conto dello studio: va nominato responsabile del trattamento.
              </div>
            </div>
          </section>

          <!-- I promemoria per email, sotto le caselle -->
          <section data-testid="guida-promemoria">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2">
              <BellRing class="w-5 h-5 text-amber-500" /> I promemoria per email
            </h3>
            <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-slate-50 dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-800/50">
              <div class="px-4 py-3">
                <!-- prova: app/Services/Assemblee/Convocazione/PromemoriaDellaConvocazione.php:88 «public function giorni(CarbonImmutable $limite): array» -->
                <b class="block text-slate-900 dark:text-white mb-0.5">Promemoria per email delle convocazioni da spedire</b>
                A chi ha preparato l'assemblea, due giorni lavorativi prima del giorno limite del compito «Spedire la convocazione entro il …» e la mattina di quel giorno, se restano avvisi da spedire.
                L'email dice quanti avvisi restano e per quale mezzo, e ha il pulsante che porta alla convocazione.
              </div>
              <div class="px-4 py-3">
                <!-- prova: app/Settings/MailSettings.php:27 «public bool $promemoria_convocazioni = true;» -->
                <!-- prova: app/Http/Controllers/Impostazioni/MailSettingsController.php:77 «public function promemoria(Request $request, MailSettings $settings)» -->
                <b class="block text-slate-900 dark:text-white mb-0.5">L'interruttore</b>
                Acceso di serie; si spegne qui, con il suo interruttore, anche dove la posta la prepara chi gestisce l'installazione.
              </div>
              <div class="px-4 py-3">
                <!-- prova: app/Services/Assemblee/Convocazione/PromemoriaDellaConvocazione.php:136 «if (EmissioneRicevute::senzaServerPosta()) {» -->
                <b class="block text-slate-900 dark:text-white mb-0.5">Senza la posta</b>
                Senza un server di posta non parte, e non dà errori: il compito resta nell'Action inbox.
              </div>
            </div>
          </section>

        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
