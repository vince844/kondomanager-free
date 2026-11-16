<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della scheda «Impostazioni» del condominio, nella finestra che si apre di lato: a che cosa servono le impostazioni (valgono solo per questo
 * condominio), che cosa fa ognuno dei due interruttori del recupero dei crediti, acceso e spento, dove si vede l'effetto, la tolleranza, e che cosa le
 * impostazioni non fanno. Accanto a ogni frase, nel sorgente, la riga di codice che la prova (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Hourglass, Lightbulb, ListChecks, Settings2, ShieldCheck, TrendingUp } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-impostazioni-condominio">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-blue-100 text-blue-700 rounded-lg dark:bg-blue-900 dark:text-blue-300">
                            <Settings2 class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: impostazioni del condominio</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Le scelte che valgono solo per questo condominio: che cosa fa ognuna, dove si vede, che cosa cambia scegliendo l'altra.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-4 h-4" /> A che cosa servono</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Models/ImpostazioniCondominio.php:45 «return self::where('condominio_id', $condominioId)->first()» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le impostazioni <strong>valgono solo per questo condominio</strong>: ogni condominio ha le sue, e cambiarle qui non tocca gli altri condomini né le impostazioni generali dello studio.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Models/ImpostazioniCondominio.php:30 «public const DI_SERIE = ['recupero_salita' => self::SALITA_AVANTI, 'recupero_suggerisce_azione' => true];» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Di serie i due interruttori sono accesi. Sotto ogni interruttore la riga «Adesso» dice con un esempio che cosa succede com'è, e cambia appena lo muovi, prima di salvare. Aprire la pagina non cambia niente.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Http/Controllers/Gestionale/Struttura/ImpostazioniCondominioController.php:45 «Gate::authorize('update', $condominio);» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Le cambia chi può modificare il condominio, con «Salva»; gli altri vedono gli interruttori, fermi.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><TrendingUp class="w-4 h-4" /> Dopo una lettera, quando scadono rate nuove</h3>
                        <p class="mb-3">
                            Con le rate mensili, dopo un sollecito o una diffida scade quasi sempre una rata nuova, che la lettera non chiedeva. Questa scelta dice che
                            cosa il programma ti suggerisce allora, nel riquadro «Sei mesi per agire».
                        </p>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Debiti/PassoProposto.php:253 «$dopoIlSollecito['oltre'] === [] || $salita === self::SALITA_AVANTI» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'interruttore «Una rata nuova non fa tornare indietro». <strong>Acceso</strong>: dopo il sollecito con il termine passato il programma suggerisce «Manda la diffida», anche se dopo è scaduta una rata nuova.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Debiti/PassoProposto.php:224 «$note[] = $fraseDopo . self::euro(self::somma($dopo['oltre']))» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Dopo la diffida con il termine passato suggerisce «Prepara il fascicolo per il legale». In «A che punto è» una nota dice quanto la lettera non comprende: «La diffida del 03/10/2026 non comprende € 220,00 di rate scadute dopo.»</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Debiti/PassoProposto.php:221 «return $caso('R10', self::DIFFIDA, $fraseDopo» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Spento</strong>: per le rate scadute dopo la lettera il programma suggerisce di nuovo la stessa lettera: dopo un sollecito «Manda un sollecito», dopo una diffida «Manda la diffida».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Debiti/PassoProposto.php:371 «'chiede' => count($tutte) < count($rate)» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Una lettera che non chiede più nessuna delle rate aperte, perché le sue sono state versate, non conta in nessuno dei due modi: le rate aperte sono tutte nuove.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Lightbulb class="w-4 h-4" /> La prossima azione</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/PassoMenu.vue:31 «data-testid="passo-proposto"» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>L'interruttore «Il programma suggerisce la prossima azione». <strong>Acceso</strong>: nella colonna «Prossima azione» di ogni persona c'è un pulsante pieno con l'azione suggerita, per esempio «Manda la diffida», e la freccia accanto con le altre.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/PassoMenu.vue:52 «Scegli l'azione <ChevronDown» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span><strong>Spento</strong>: al posto del pulsante pieno c'è «Scegli l'azione», con tutte le azioni possibili e nessuna evidenziata. «A che punto è» dice gli stessi fatti.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue:339 «Scrivi alle {{ riga.quante }} persone della riga» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In fondo alla riga di un esercizio, acceso, ci sono i pulsanti delle azioni in blocco, per tutte le persone che hanno la stessa azione suggerita; spento, c'è un pulsante per scrivere a tutte le persone della riga.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Hourglass class="w-4 h-4" /> La tolleranza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/struttura/RegoleRecupero.vue:72 «etichetta: 'Tolleranza prima del sollecito'» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>I giorni di tolleranza si scrivono nella scheda «Recupero dei crediti», nella casella «Tolleranza prima del sollecito».</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Debiti/PassoProposto.php:397 «': è nella tolleranza.'» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>In tutti e due i modi una rata nuova ancora nella tolleranza non cambia la prossima azione della lettera di prima, e una nota lo dice: «La rata del 10/11/2026, € 100,00, non è nel sollecito: è nella tolleranza.»</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ListChecks class="w-4 h-4" /> Che cosa non fanno</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue:87 «lettera.value = { aperta: true, persona: { id: p.anagrafica_id» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Il programma <strong>non manda niente da solo</strong>: «Manda la diffida» e «Manda un sollecito» aprono la finestra delle lettere, dove guardi e decidi.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/components/gestionale/seiMesi/SeiMesiCompito.vue:59 «'Apri il rendiconto' : 'Apri le persone'» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nell'Inbox della dashboard il compito dei sei mesi non mostra la prossima azione: «Apri le persone» porta alla riga del riquadro.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/Accessori/SuggerimentiDelRecupero.php:36 «$tolleranza = RegoleDelRecupero::tolleranza($regole, $oggi);» --><ChevronRight class="w-4 h-4 text-blue-500 shrink-0 mt-0.5" /><span>Nell'elenco «Rate scadute» il suggerimento di chi è oltre la tolleranza senza una lettera non cambia con queste scelte: guarda solo la tolleranza e le lettere.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
