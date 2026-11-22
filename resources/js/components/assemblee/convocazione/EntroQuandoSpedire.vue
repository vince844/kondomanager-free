<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Entro quando spedire», in testa al passo «Convocazione» (decisione 565), senza spazi vuoti (la bozza approvata `design/assemblee_convocazione_layout_bozza.html`).
 * Il programma propone il giorno della spedizione delle raccomandate contando prudente; l'amministratore può sceglierne un altro, da oggi in poi, che si
 * salva quando cambia, come le date dell'assemblea. Un giorno già passato è spento nel calendario, e il server lo rifiuta con la sua frase, rossa sotto il
 * campo come negli altri moduli.
 *
 * - Sullo schermo largo «Come conto» sta sempre a destra, alto quanto la card accanto, fatto sulla data che vale (la proposta o quella scelta): la settimana,
 *   e se arriva in tempo (verde, con il margine) o tardi (arancio, con il giorno in cui arriverebbe). Con la data scelta, sotto, «Che cosa segue la data
 *   che scegli»; accanto al campo, «Torna alla proposta».
 * - Al telefono tutto in colonna, e «Come conto» resta nel cassetto della «i» del conto; con una data che arriva tardi il riquadro arancio sta anche sotto
 *   il campo.
 * - Sotto il campo le righe d'aiuto, leggere: il compito nell'Action inbox con i giorni del promemoria per email, «Giorni di posta dello studio: 3
 *   lavorativi · Cambia», che porta all'impostazione (per chi la può cambiare). Le frasi arrivano dal server.
 * - La «i» della data ha il suggerimento del programma, che compare subito e al tocco.
 *
 * Con un punto sulla destinazione d'uso (art. 1117-ter) la nota dice che cosa manca e, finché l'assemblea è in preparazione, prende le due date
 * dell'affissione.
 */
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { AlertTriangle, Clock, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import ContoDellaSettimana from './ContoDellaSettimana.vue';
import SuggerimentoDellaI from './SuggerimentoDellaI.vue';
import { SOLO_CONVOCAZIONE, classiBadge, classiNota, giornoConNome, giornoEMese, giornoPrima } from './aiuti';
import type { EntroQuandoSpedire } from './tipi';

const props = withDefaults(defineProps<{ entro: EntroQuandoSpedire; rotta: string; rottaAffissione?: string }>(), { rottaAffissione: '' });

const emit = defineEmits<{ (e: 'sposta-le-date'): void }>();

const schermoLargo = useMediaQuery('(min-width: 1024px)');
const contoAperto = ref(false);
const giorno = ref(props.entro.raccomandate?.entro ?? '');
watch(() => props.entro.raccomandate?.entro, (nuovo) => { giorno.value = nuovo ?? ''; });

const raccomandate = computed(() => props.entro.raccomandate);
const sceltaDaTe = computed(() => raccomandate.value?.scelta_da_te ?? false);
const suggerimentoDellaData = computed(() => {
    const proposta = raccomandate.value?.proposta;
    if (!proposta) return '';
    const prima = giornoPrima(proposta);

    return `La data è tua: il programma la usa per il compito e per le lettere. Esempio: se le porti in posta ${giornoConNome(prima)}, scrivi ${giornoEMese(prima)}, `
        + 'e «Come conto» si rifà su quel giorno.';
});

/** L'errore del server sul giorno, o la frase di una data salvata che è passata: rossa sotto il campo, senza icona. */
const errore = ref<string | null>(null);
watch(() => props.entro.raccomandate?.entro, () => { errore.value = null; });
const fraseSottoIlCampo = computed(() => errore.value ?? raccomandate.value?.passata ?? null);

/** La data scelta nel calendario: quella della proposta torna la proposta (il giorno vuoto), le altre si salvano come sono. */
function salva(nuovo: string | null) {
    if (!props.entro.si_cambia || !raccomandate.value) return;
    const valore = !nuovo || nuovo === raccomandate.value.proposta ? null : nuovo;
    errore.value = null;
    router.patch(props.rotta, { giorno: valore }, {
        preserveScroll: true, preserveState: true, only: SOLO_CONVOCAZIONE,
        onError: (e) => { errore.value = (e as Record<string, string>).giorno ?? Object.values(e as Record<string, string>).join(' '); },
    });
}

// Le due date dell'affissione (art. 1117-ter: almeno trenta giorni consecutivi prima della prima convocazione).
const affissioneDal = ref(props.entro.destinazione_uso?.affissione?.dal ?? '');
const affissioneAl = ref(props.entro.destinazione_uso?.affissione?.al ?? '');
watch(() => props.entro.destinazione_uso?.affissione, (a) => { affissioneDal.value = a?.dal ?? ''; affissioneAl.value = a?.al ?? ''; });
const erroriAffissione = ref<Record<string, string>>({});
const salvandoAffissione = ref(false);
function salvaLAffissione() {
    if (!props.rottaAffissione || !props.entro.destinazione_uso?.affissione?.si_cambia) return;
    salvandoAffissione.value = true;
    router.patch(props.rottaAffissione, { affissione_dal: affissioneDal.value || null, affissione_al: affissioneAl.value || null }, {
        preserveScroll: true, preserveState: true, only: SOLO_CONVOCAZIONE,
        onError: (e) => { erroriAffissione.value = e as Record<string, string>; },
        onSuccess: () => { erroriAffissione.value = {}; },
        onFinish: () => { salvandoAffissione.value = false; },
    });
}
</script>

<template>
    <section class="grid items-stretch gap-4 lg:grid-cols-2" data-testid="entro-quando-spedire">
        <div class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <div class="border-b border-slate-100 px-4 py-3.5 dark:border-slate-800 sm:px-5">
                <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100">
                    <Clock class="h-4 w-4 shrink-0" /> Entro quando spedire
                </h3>
                <p class="mt-0.5 hidden text-sm text-slate-500 dark:text-slate-400 sm:block">{{ entro.frase }}</p>
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400 sm:hidden">{{ entro.frase_breve }}</p>
            </div>

            <div v-if="raccomandate" class="grid grid-cols-1 items-center gap-x-3 gap-y-1 border-b border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] sm:px-5">
                <span class="hidden text-slate-500 dark:text-slate-400 sm:block">Raccomandate ({{ raccomandate.quante }})</span>
                <span class="flex flex-wrap items-center gap-1.5">
                    <b class="hidden font-semibold text-slate-900 dark:text-slate-100 sm:inline">entro {{ raccomandate.entro_parole }}</b>
                    <b class="font-semibold text-slate-900 dark:text-slate-100 sm:hidden">{{ raccomandate.frase }}</b>
                    <!-- Al telefono «Come conto» sta nel cassetto di questa «i». -->
                    <button
                        v-if="entro.conto && !schermoLargo"
                        type="button"
                        class="inline-flex shrink-0 items-center justify-center rounded-md"
                        :aria-expanded="contoAperto"
                        :aria-label="entro.conto.titolo"
                        data-testid="apri-conto"
                        @click="contoAperto = !contoAperto"
                    ><Info class="h-3.5 w-3.5 transition-colors" :class="contoAperto ? 'text-indigo-500 dark:text-indigo-400' : 'text-slate-400 hover:text-indigo-500 dark:text-slate-500 dark:hover:text-indigo-400'" aria-hidden="true" /></button>
                </span>
            </div>
            <div v-if="entro.altri" class="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] items-center gap-3 border-b border-slate-100 px-5 py-2.5 text-sm dark:border-slate-800 sm:grid">
                <span class="text-slate-500 dark:text-slate-400">{{ entro.altri.etichetta }}</span>
                <span class="text-slate-700 dark:text-slate-300">entro {{ entro.altri.entro_parole }}</span>
            </div>

            <div class="flex flex-1 flex-col gap-3 px-4 py-3.5 sm:px-5">
                <div v-if="raccomandate">
                    <div class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">
                        <label for="raccomandate-entro">Spedisco le raccomandate entro</label>
                        <SuggerimentoDellaI v-if="suggerimentoDellaData" :testo="suggerimentoDellaData" testid="esempio-data" />
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2.5">
                        <CampoGiorno
                            id="raccomandate-entro"
                            v-model="giorno"
                            class="h-9 text-sm sm:w-[190px]"
                            :min="entro.oggi"
                            :disabled="!entro.si_cambia"
                            @update:model-value="(v: string) => salva(v || null)"
                        />
                        <span :class="classiBadge(sceltaDaTe ? 'am' : 'sl')" class="self-start sm:self-auto" data-testid="badge-data">
                            {{ sceltaDaTe ? 'scelta da te' : 'proposta dal programma' }}
                        </span>
                        <button
                            v-if="raccomandate.torna && entro.si_cambia"
                            type="button"
                            class="self-start text-xs font-medium text-sky-700 underline-offset-2 hover:underline dark:text-sky-300 sm:self-auto"
                            data-testid="torna-alla-proposta"
                            @click="salva(null)"
                        >{{ raccomandate.torna }}</button>
                    </div>
                    <p v-if="fraseSottoIlCampo" class="mt-1.5 text-sm text-red-600 dark:text-red-500" data-testid="errore-data">{{ fraseSottoIlCampo }}</p>
                </div>

                <!-- Al telefono «Come conto» è nel cassetto: una data che arriva tardi lo dice anche qui. -->
                <div v-if="entro.vicina" :class="classiNota('or')" data-testid="cinque-giorni-vicina">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div class="min-w-0">
                        <p class="text-xs">{{ entro.vicina }}</p>
                        <Button v-if="entro.si_cambia" variant="outline" size="sm" class="mt-2 h-8 bg-white text-xs text-slate-700 dark:bg-slate-950 dark:text-slate-200"
                            data-testid="sposta-le-date-vicina" @click="emit('sposta-le-date')">Sposta le date</Button>
                    </div>
                </div>

                <div v-if="!schermoLargo && entro.conto && !entro.conto.esito.in_tempo" :class="classiNota('or')" data-testid="esito-tardi">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <p class="text-xs"><b class="font-semibold">{{ entro.conto.esito.titolo }}</b>{{ entro.conto.esito.frase }}</p>
                </div>

                <div class="space-y-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400" data-testid="righe-aiuto">
                    <p v-for="r in entro.righe" :key="r">{{ r }}</p>
                    <p v-if="entro.altri" class="sm:hidden">{{ entro.altri.etichetta }}: entro {{ entro.altri.entro_parole }}.</p>
                    <p data-testid="giorni-di-posta">
                        Giorni di posta dello studio: <b class="font-semibold text-slate-700 dark:text-slate-300">{{ entro.giorni_di_posta.adesso }}</b>
                        <template v-if="entro.giorni_di_posta.dove"> · <Link :href="entro.giorni_di_posta.dove" class="font-medium text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">Cambia</Link></template>
                    </p>
                </div>

                <div v-if="entro.destinazione_uso" :class="classiNota('am')" data-testid="destinazione-uso">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div class="min-w-0">
                        <p>{{ entro.destinazione_uso.frase }}</p>
                        <ul v-if="entro.destinazione_uso.mancano.length" class="mt-1 list-disc pl-4 text-xs">
                            <li v-for="m in entro.destinazione_uso.mancano" :key="m">{{ m }}</li>
                        </ul>
                        <div v-if="entro.destinazione_uso.affissione?.si_cambia && rottaAffissione" class="mt-2.5 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end"
                            data-testid="affissione">
                            <label class="flex flex-col gap-1 text-xs font-medium">L'affissione dal
                                <CampoGiorno v-model="affissioneDal" class="h-9 text-sm sm:w-[170px]" svuotabile data-testid="affissione-dal" />
                            </label>
                            <label class="flex flex-col gap-1 text-xs font-medium">al
                                <CampoGiorno v-model="affissioneAl" class="h-9 text-sm sm:w-[170px]" svuotabile data-testid="affissione-al" />
                            </label>
                            <Button variant="outline" size="sm" class="h-9 bg-white text-slate-700 dark:bg-slate-950 dark:text-slate-200" :disabled="salvandoAffissione"
                                data-testid="salva-affissione" @click="salvaLAffissione">{{ salvandoAffissione ? 'Un momento…' : 'Salva l\'affissione' }}</Button>
                        </div>
                        <p v-for="(m, k) in erroriAffissione" :key="k" class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ m }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sullo schermo largo «Come conto» sta sempre accanto, alto quanto la card di sinistra. -->
        <div v-if="schermoLargo && entro.conto" class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="come-conto">
            <div class="px-5 pb-2.5 pt-3.5">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ entro.conto.titolo }}</h3>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ entro.conto.frase }}</p>
            </div>
            <div class="flex flex-1 flex-col gap-3 px-5 pb-4">
                <ContoDellaSettimana :conto="entro.conto" />
                <div v-if="sceltaDaTe && entro.segue.length" data-testid="che-cosa-segue">
                    <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa segue la data che scegli</p>
                    <div class="space-y-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                        <p v-for="r in entro.segue" :key="r">{{ r }}</p>
                    </div>
                </div>
                <p v-else class="text-xs text-slate-500 dark:text-slate-400">{{ entro.conto.piede }}</p>
            </div>
        </div>

        <!-- Al telefono la «i» apre «Come conto» in un cassetto dal basso. -->
        <Sheet v-if="!schermoLargo && entro.conto" :open="contoAperto" @update:open="(v: boolean) => (contoAperto = v)">
            <SheetContent side="bottom" class="max-h-[85vh] gap-0 overflow-y-auto rounded-t-2xl p-0 [&>button:last-child]:hidden">
                <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-slate-200 dark:bg-slate-700" />
                <div class="flex items-center justify-between gap-2.5 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <SheetTitle class="text-sm font-bold">{{ entro.conto.titolo }}</SheetTitle>
                    <Button variant="ghost" size="sm" class="h-7 text-xs" @click="contoAperto = false">Chiudi</Button>
                </div>
                <SheetDescription class="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">{{ entro.conto.frase }}</SheetDescription>
                <div class="px-4 py-3"><ContoDellaSettimana :conto="entro.conto" telefono /></div>
                <div v-if="sceltaDaTe && entro.segue.length" class="px-4 pb-4">
                    <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa segue la data che scegli</p>
                    <div class="space-y-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                        <p v-for="r in entro.segue" :key="r">{{ r }}</p>
                    </div>
                </div>
                <p v-else class="px-4 pb-4 text-xs text-slate-500 dark:text-slate-400">{{ entro.conto.piede }}</p>
            </SheetContent>
        </Sheet>
    </section>
</template>
