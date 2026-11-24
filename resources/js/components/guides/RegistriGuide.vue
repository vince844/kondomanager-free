<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina «Registri» (Impostazioni), nella finestra che si apre di lato: le email inviate, e dalla 1.12.0-beta.14 gli errori (decisione 698;
 * bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scena 9): il codice, gli avvisi, il rapporto, quanto restano, il codice che non c'è.
 * Accanto a ogni frase, nel sorgente, la riga di codice che la prova (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Bug, ChevronRight, Clock, Mail, ScrollText, Search, Send, ShieldCheck } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-registri">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-slate-100 text-slate-700 rounded-lg dark:bg-slate-800 dark:text-slate-200">
                            <ScrollText class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: i registri</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Le email che il programma ha mandato, e gli errori che ha incontrato: che cosa sono e che cosa ne fai.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Mail class="w-4 h-4" /> La scheda «Email»</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/pages/impostazioni/impostazioniLogs.vue:505 «data-motivo-invio» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Ogni email che il programma ha mandato. Se un invio è fallito, il motivo è scritto sotto il destinatario.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/impostazioni/impostazioniLogs.vue:492 «data-non-partita» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>«Non partita»: senza un server di posta il messaggio è solo registrato, non è partito.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Search class="w-4 h-4" /> Il codice di un errore</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Support/CodiceDellErrore.php:22 «public const ALFABETO» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Ogni errore ha un codice di sei caratteri, lo stesso che vede chi l'ha incontrato sulla pagina d'errore. Non ha 0, O, 1, I né L: si detta al telefono.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/impostazioni/errori/SchedaErrori.vue:63 «if (conIlCodice) emit('mostra');» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Scrivi il codice in «Codice o testo»: l'errore si apre da solo. Per esempio Elena ti dice «K7M2QX», tu lo scrivi e leggi che cosa è successo.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Http/Controllers/Impostazioni/LogsController.php:217 «$errore->forceFill(['stato' => Errore::VISTO])» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Un errore resta «Nuovo» finché non lo apri; poi è «Visto». Gli errori uguali sono una riga sola, con quante volte sono successi.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Send class="w-4 h-4" /> Gli avvisi</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Http/Controllers/System/AvvisoDellErroreController.php:80 «Errore::AVVISATO» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Chi non vede il dettaglio (un condòmino, un collaboratore) ti avvisa dalla pagina d'errore con «Avvisa lo studio», e può scrivere che cosa stava facendo. L'errore diventa «Avvisato».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/TipiDeiCompiti.php:87 «'errore_avvisato' =>» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Ti arriva anche un compito nella Action inbox, con «Apri l'errore». Uno per errore: gli avvisi dopo si aggiungono al primo e il compito li conta.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Inbox/CompitoDellErrore.php:44 «public static function chiLoVede» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Il compito e questa scheda li vede solo chi ha «Gestisci impostazioni generali».</span></li>
                                <li class="flex gap-2"><!-- prova: config/notifications.php:163 «NotificationType::ERROR_REPORTED->value => [» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Vuoi anche un'email? Accendi «Errore avvisato» nelle tue preferenze di notifica: è spenta di serie, arriva al primo avviso di un errore (gli avvisi dopo si contano nel compito, senza un'altra email) e non porta né il dettaglio né la nota.</span></li>
                                <li class="flex gap-2"><!-- prova: routes/settings.php:38 «throttle:5,60,avvisi-errori» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Un avviso per codice, e al massimo cinque all'ora per persona. Un visitatore non collegato non manda avvisi: ti comunica il codice lui.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Support/Errori/PaginaDiErrore.php:110 «public static function avvisoAperto» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Nelle impostazioni generali, «I condòmini possono avvisare lo studio dalla pagina d'errore» è acceso di serie. Spento, condòmini e fornitori vedono solo il codice da comunicarti; i collaboratori dello studio tengono «Avvisa l'amministratore». Gli errori restano sempre in questa scheda.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-4 h-4" /> Il rapporto</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/components/impostazioni/errori/FinestraDellErrore.vue:155 «<Download class="h-3.5 w-3.5" />Scarica il rapporto» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Apri l'errore e usa «Copia il rapporto» o «Scarica il rapporto»: lo mandi a chi ti assiste con il programma.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Support/Errori/RapportoDellErrore.php:23 «public const TOLTI» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Il rapporto dice il codice, l'ora, la versione, la pagina e che cosa è successo. Senza password, senza i dati dei moduli e i valori delle query, senza la nota di chi ha avvisato; i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Clock class="w-4 h-4" /> Quanto restano</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Support/Errori/RegistroDegliErrori.php:36 «public const GIORNI = 90;» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>90 giorni: ogni notte il programma toglie le volte più vecchie.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Support/Errori/RegistroDegliErrori.php:38 «public const MASSIMO_AL_GIORNO = 200;» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Lo stesso errore si scrive al massimo 200 volte al giorno; oltre, cresce solo il conteggio.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Bug class="w-4 h-4" /> Un codice che non c'è</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/components/impostazioni/errori/SchedaErrori.vue:160 «Nessun errore con il codice» --><ChevronRight class="w-4 h-4 text-sky-500 shrink-0 mt-0.5" /><span>Se l'errore è di più di 90 giorni fa, o è successo con il database fermo, il codice è solo nel registro del server: il file storage/logs/laravel.log, dove lo cerca chi gestisce l'installazione.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
