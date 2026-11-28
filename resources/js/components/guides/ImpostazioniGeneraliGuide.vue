<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina delle impostazioni generali, nella finestra che si apre di lato: per ogni impostazione della pagina che cosa fa, a chi si applica e
 * da dove parte, con la cancellazione delle aperture e che cosa si salva quando. Accanto a ogni frase, nel sorgente, la riga di codice che la prova
 * (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { ChevronRight, Globe, MessagesSquare, Settings2, ShieldCheck, UserPlus } from 'lucide-vue-next'

defineProps<{ open: boolean }>()
defineEmits(['update:open'])
</script>

<template>
  <Sheet :open="open" @update:open="$emit('update:open', $event)">
    <SheetContent class="w-full overflow-y-auto p-0 sm:w-[600px] sm:max-w-2xl" data-testid="guida-impostazioni-generali">
      <div class="px-6 py-8">
        <SheetHeader class="mb-8">
          <div class="mb-2 flex items-center gap-3">
            <div class="rounded-lg bg-blue-100 p-2 text-blue-700 dark:bg-blue-900 dark:text-blue-300"><Settings2 class="h-6 w-6" /></div>
            <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: impostazioni generali</SheetTitle>
          </div>
          <!-- prova: app/Http/Controllers/Impostazioni/ImpostazioniGeneraliController.php:139 «$userPreferences->open_condominio_on_login = $validated» -->
          <!-- prova: resources/js/components/GestionaleHeader.vue:91 «title: 'Condominio',» -->
          <!-- prova: resources/js/layouts/gestionale/StrutturaLayout.vue:72 «title: 'Impostazioni',» -->
          <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
            Valgono per tutto lo studio, tranne «Apri condominio al login», che vale solo per te. Le scelte di un solo condominio stanno nel suo menu «Condominio», alla scheda «Impostazioni».
          </SheetDescription>
        </SheetHeader>

        <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><Globe class="h-4 w-4" /> Il programma</h3>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
              <ul class="space-y-2">
                <li class="flex gap-2"><!-- prova: app/Http/Middleware/SetLocaleMiddleware.php:32 «App::setLocale($locale);» --><!-- prova: app/Notifications/LocalizedNotification.php:42 «app(GeneralSettings::class)->language» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Lingua</strong>: una sola per tutti, non si sceglie utente per utente. Cambia le pagine tradotte e le email.</span></li>
                <li class="flex gap-2"><!-- prova: resources/js/app.ts:28 «${title} - ${appName}» --><!-- prova: app/Notifications/InviteUserNotification.php:58 «'appName' => config('app.name')» --><!-- prova: app/Notifications/NewUserEmailNotification.php:52 «'appName' => config('app.name')» --><!-- prova: app/Notifications/Users/AvvisoDiSicurezzaNotification.php:82 «'appName' => config('app.name')» --><!-- prova: app/Support/Email/NomeNelleEmail.php:32 «if ($studio !== '') {» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Nome</strong>: nella scheda del browser e nelle email di invito, benvenuto e sicurezza. In testa e in firma alle email vale solo se nelle stampe non è scritto il nome dello studio.</span></li>
                <li class="flex gap-2"><!-- prova: app/Helpers/RedirectHelper.php:66 «$user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])» --><!-- prova: app/Helpers/RedirectHelper.php:71 «return route('admin.gestionale.index'» --><!-- prova: app/Http/Requests/Settings/CreateImpostazioniGeneraliRequest.php:63 «'required_if:open_condominio_on_login,true'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Apri condominio al login</strong>: solo per te. Acceso, se sei amministratore o collaboratore dopo l'accesso entri nel «Condominio predefinito» invece che nella dashboard; senza sceglierlo non si salva.</span></li>
                <li class="flex gap-2"><!-- prova: app/Traits/PaginaElenco.php:84 «app(GeneralSettings::class)->default_per_page» --><!-- prova: app/Http/Controllers/Impostazioni/ImpostazioniGeneraliController.php:60 «il portale condòmino non ha un selettore» --><!-- prova: app/Http/Controllers/Gestionale/Movimenti/ScritturaContabileController.php:82 «predefinito: 20» --><!-- prova: app/Http/Controllers/Gestionale/Movimenti/RegistroContabilitaController.php:57 «predefinito: 20» --><!-- prova: app/Http/Controllers/Gestionale/Movimenti/MastrinoController.php:47 «predefinito: 20» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Righe per pagina</strong>: il numero vale dove non hai ancora scelto; nel gestionale ognuno lo cambia elenco per elenco (il portale non ha il selettore). Libro giornale, registro di contabilità e mastrini partono da 20.</span></li>
              </ul>
            </div>
          </section>

          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><UserPlus class="h-4 w-4" /> Chi si registra</h3>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
              <ul class="space-y-2">
                <li class="flex gap-2"><!-- prova: routes/auth.php:18 «'CheckUserRegistration'» --><!-- prova: resources/js/pages/Welcome.vue:43 «v-if="$page.props.can_register"» --><!-- prova: resources/js/pages/auth/Login.vue:108 «v-if="$page.props.can_register"» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Registrazione utenti</strong>: accesa, ci si registra dalla pagina iniziale e da quella di accesso.</span></li>
                <li class="flex gap-2"><!-- prova: app/Services/UserRegistrationService.php:60 «if ($daUnInvito && ! in_array($nome, Role::concedibiliDaTutti(), true))» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Ruolo dei nuovi utenti</strong>: lo riceve chi si registra dal sito; chi accetta un invito lo riceve solo se è «utente» o «fornitore», altrimenti riceve «utente».</span></li>
                <li class="flex gap-2"><!-- prova: app/Http/Requests/Settings/CreateImpostazioniGeneraliRequest.php:81 «hasRole(Role::AMMINISTRATORE->value)» --><!-- prova: app/Http/Controllers/Impostazioni/ImpostazioniGeneraliController.php:93 «ConfermaPasswordRecente::scaduta($request)» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Il ruolo lo cambia solo un amministratore</strong>. Cambiarlo, o accendere la registrazione con un ruolo diverso da «utente» e «fornitore», può chiedere di ridigitare la password.</span></li>
              </ul>
            </div>
          </section>

          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><MessagesSquare class="h-4 w-4" /> Segnalazioni, comunicazioni e allegati</h3>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
              <ul class="space-y-2">
                <li class="flex gap-2"><!-- prova: app/Support/Commenti/RegoleCommenti.php:448 «if (self::haPannello($user) && (» --><!-- prova: app/Support/Commenti/RegoleCommenti.php:462 «return 'in_attesa';» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Commenti in moderazione</strong>: acceso, i commenti aspettano l'approvazione, tranne quelli di chi dal pannello li può approvare o pubblicare.</span></li>
                <li class="flex gap-2"><!-- prova: app/Settings/GeneralSettings.php:19 «$commenti_segnalazioni_moderati_di_serie = false» --><!-- prova: app/Settings/GeneralSettings.php:20 «$commenti_comunicazioni_moderati_di_serie = true» --><!-- prova: app/Models/Segnalazione.php:62 «->commenti_moderati = app» --><!-- prova: app/Models/Comunicazione.php:87 «->commenti_moderati = app» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Gli interruttori sono due: <strong>segnalazioni</strong> (di serie spento) e <strong>comunicazioni</strong> (di serie acceso). Valgono per gli elementi nuovi; ognuno si cambia dalla sua scheda.</span></li>
                <li class="flex gap-2"><!-- prova: app/Support/Allegati/RegoleAllegati.php:189 «self::permesso($elemento, 'approva')» --><!-- prova: app/Settings/GeneralSettings.php:21 «$allegati_moderati_di_serie = true» --><!-- prova: app/Models/Segnalazione.php:68 «->allegati_moderati = app» --><!-- prova: app/Settings/GeneralSettings.php:22 «$allegati_massimo_per_elemento = 5» --><!-- prova: app/Http/Requests/Settings/CreateImpostazioniGeneraliRequest.php:35 «min:1|max:50» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Allegati</strong>: in moderazione di serie per gli elementi nuovi, tranne quelli di chi li può approvare. Quanti file al massimo per elemento: da 1 a 50 (di serie 5).</span></li>
                <li class="flex gap-2"><!-- prova: app/Http/Resources/Portale/SegnalazionePortaleResource.php:37 «segnalazioni_assegnazione_visibile_nel_portale» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Chi segue la segnalazione</strong>: acceso, il condòmino ne legge il nome nel portale.</span></li>
                <li class="flex gap-2"><!-- prova: app/Support/Segnalazioni/AssegnazioneSegnalazioni.php:147 «$campi['stato'] = 'in lavorazione';» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Assegnata, «in lavorazione»</strong>: acceso, una segnalazione aperta cambia stato da sola quando la assegni.</span></li>
                <li class="flex gap-2"><!-- prova: app/Support/Comunicazioni/LettureComunicazione.php:41 «comunicazioni_registra_aperture» --><!-- prova: lang/it/impostazioni.php:362 «il condòmino ne è avvisato sotto la comunicazione» --><!-- prova: lang/it/impostazioni.php:363 «conviene sentire chi segue la privacy» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Aperture delle comunicazioni</strong>: acceso, il programma segna quando un condòmino apre nel portale, e il condòmino lo legge sotto la comunicazione. È un dato personale: senti chi segue la privacy.</span></li>
                <li class="flex gap-2"><!-- prova: app/Http/Controllers/Impostazioni/ImpostazioniGeneraliController.php:171 «LettureComunicazione::cancellaAperture();» --><!-- prova: app/Support/Comunicazioni/LettureComunicazione.php:317 «update(['aperta_il' => null]);» --><!-- prova: lang/it/impostazioni.php:370 «Le prese visione restano.» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>«Cancella le aperture registrate»</strong>: agisce subito, dopo la conferma, su tutte le comunicazioni: non si recuperano, e le prese visione restano.</span></li>
              </ul>
            </div>
          </section>

          <section>
            <h3 class="mb-3 flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-white"><ShieldCheck class="h-4 w-4" /> Sicurezza ed errori</h3>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
              <ul class="space-y-2">
                <li class="flex gap-2"><!-- prova: app/Settings/GeneralSettings.php:31 «$due_fattori_obbligatoria_pannello = false» --><!-- prova: app/Http/Middleware/DueFattoriPerIlPannello.php:82 «return redirect()->route('two-factor.show');» --><!-- prova: app/Http/Middleware/DueFattoriPerIlPannello.php:111 «return self::obbligatoria() && self::entraNelPannello($user);» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Verifica in due passaggi</strong>: di serie spenta. Accesa, chi entra nel pannello la attiva prima di lavorare, anche tu, e non può toglierla. Condòmini e fornitori non cambiano.</span></li>
                <li class="flex gap-2"><!-- prova: app/Settings/GeneralSettings.php:28 «$errori_avviso_dalla_pagina = true» --><!-- prova: app/Http/Controllers/System/AvvisoDellErroreController.php:81 «$compito->avvisato($volta, $utente);» --><!-- prova: app/Http/Controllers/System/AvvisoDellErroreController.php:104 «->where('enabled', true)» --><!-- prova: lang/it/errors.php:45 «'Avvisa lo studio'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span><strong>Avvisa lo studio</strong>: di serie acceso. Condòmini e fornitori hanno «Avvisa lo studio» sulla pagina d'errore: arriva un compito nella Action inbox, e un'email a chi l'ha accesa nelle sue notifiche.</span></li>
                <li class="flex gap-2"><!-- prova: app/Support/Errori/PaginaDiErrore.php:112 «hasRole('collaboratore')» --><!-- prova: lang/it/errors.php:46 «'Avvisa l'amministratore'» --><ChevronRight class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" /><span>Spento, condòmini e fornitori vedono il codice dell'errore da comunicarti. I collaboratori dello studio tengono «Avvisa l'amministratore» anche da spento.</span></li>
              </ul>
            </div>
          </section>

          <!-- prova: app/Http/Controllers/Impostazioni/ImpostazioniGeneraliController.php:135 «$settings->save();» -->
          <!-- prova: app/Services/RegistroModifiche/ImpostazioniNelRegistro.php:21 «'general' => ['Impostazioni generali', 'impostazioni_generali']» -->
          <!-- prova: app/Services/RegistroModifiche/CatalogoDelRegistro.php:141 «Models\UserPreference::class => 'preferenze personali dell'interfaccia'» -->
          <p class="text-xs text-slate-500 dark:text-slate-400">Gli interruttori e i campi cambiano solo con «Salva impostazioni». La «Storia» in alto dice chi ha cambiato che cosa; non registra «Apri condominio al login», che è tua, né la cancellazione delle aperture.</p>
        </div>
      </div>
    </SheetContent>
  </Sheet>
</template>
