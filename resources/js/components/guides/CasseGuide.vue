<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La guida della pagina di una risorsa o di un fondo («Modifica risorsa»), nella finestra che si apre di lato: i dati principali e il saldo di apertura, la
 * destinazione d'uso di un fondo, lo sblocco d'emergenza con la sua motivazione, e perché la tesoreria porta qui con «Registra lo sblocco». Accanto a ogni
 * frase, nel sorgente, la riga di codice che la prova (`prova: file:riga «testo»`): la prova della guida controlla che ci sia.
 */
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { ChevronRight, Landmark, LockOpen, ShieldCheck, Sparkles, Wallet } from 'lucide-vue-next';

defineProps<{ open: boolean }>();
defineEmits(['update:open']);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <SheetContent class="sm:max-w-2xl overflow-y-auto w-full sm:w-[600px] p-0" data-testid="guida-casse">
            <div class="px-6 py-8">
                <SheetHeader class="mb-8">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="p-2 bg-emerald-100 text-emerald-700 rounded-lg dark:bg-emerald-900 dark:text-emerald-300">
                            <Wallet class="w-6 h-6" />
                        </div>
                        <SheetTitle class="text-2xl font-extrabold tracking-tight">Guida: la risorsa o il fondo</SheetTitle>
                    </div>
                    <SheetDescription class="text-base text-slate-600 dark:text-slate-400">
                        Che cosa si cambia in questa pagina e, per un fondo, quando si può usare per le spese urgenti.
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-8 text-sm text-slate-700 dark:text-slate-300">
                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Landmark class="w-4 h-4" /> Dati principali</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:223 «Dati principali» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>In «Dati principali» il nome, il tipo della risorsa, il saldo di apertura, la descrizione e le note.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:287 «Già registrato in contabilità» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Il saldo di apertura si corregge solo finché non è registrato in contabilità: salvando, va a giornale come saldo di apertura; dopo, il campo mostra il saldo di oggi e da qui non si cambia più.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><ShieldCheck class="w-4 h-4" /> La destinazione d'uso del fondo</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:161 «return form.sottotipo_fondo === 'generico' || form.is_override_assemblea;» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Per un fondo, in «Natura giuridica e vincoli del fondo» scegli la «Destinazione d'uso». Il fondo cassa generico si può usare per le spese impreviste; il fondo vincolato ai lavori, il TFR e il fondo morosità restano vincolati. Sotto il menu si legge «Utilizzabile per imprevisti» o «Fondo bloccato».</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:351 «data-testid="tipo-non-indicato"» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Un fondo senza un tipo conta come vincolato, come nello Stato patrimoniale e nella tesoreria: per salvare ne scegli uno.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><LockOpen class="w-4 h-4" /> Lo sblocco d'emergenza</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:383 «Consenti sblocco d'emergenza» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Per un fondo che non è generico c'è l'interruttore «Consenti sblocco d'emergenza»: acceso, dichiari che l'assemblea o il regolamento permettono di usare il fondo per le spese urgenti.</span></li>
                                <li class="flex gap-2"><!-- prova: resources/js/pages/gestionale/casse/CasseEdit.vue:390 «tipo="cassa"» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Se un'assemblea ha respinto, rinviato, revocato o annullato una delibera che riguarda il fondo, la scheda lo dice in testa con la frase della delibera e il collegamento all'assemblea. Sono frasi: nessun conto cambia da solo.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Http/Requests/Gestionale/Casse/UpdateCassaRequest.php:84 «Inserisci gli estremi della delibera o la motivazione» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Con l'interruttore acceso serve la motivazione: gli estremi della delibera o l'articolo del regolamento, per esempio la delibera del 12/04/2026, punto 3. Senza, il fondo non si salva.</span></li>
                                <li class="flex gap-2"><!-- prova: app/Services/Gestionale/LiquiditaVincolataService.php:96 «un fondo sbloccato dall'assemblea si può usare, come il generico» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Un fondo sbloccato si può usare come il generico, anche per una spesa imprevista. Spegnendo l'interruttore torna vincolato.</span></li>
                            </ul>
                        </div>
                    </section>

                    <section>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-3 flex items-center gap-2"><Sparkles class="w-4 h-4" /> Dalla tesoreria</h3>
                        <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl border border-slate-200 dark:border-slate-800">
                            <ul class="space-y-2">
                                <li class="flex gap-2"><!-- prova: resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts:550 «export const FONDI_DA_SBLOCCARE» --><ChevronRight class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" /><span>Quando un fondo vincolato ai lavori, o senza un tipo, coprirebbe il punto più basso del saldo, le azioni consigliate della dashboard lo propongono con «Registra lo sblocco», che apre questa pagina. Mai il TFR, e non il fondo morosità; la scelta resta dell'assemblea.</span></li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
