<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra del fascicolo per il legale di una persona, dal riquadro «Sei mesi per agire» e dalla scheda «Corrispondenza» del suo estratto conto. In testa
 * «Che cosa metti nel fascicolo», tutto scelto di serie: gli esercizi dei sei mesi con le loro rate (che si aprono con la freccia) e le parti. Sotto «Che cosa
 * c'è» (un file per riga, con il segno verde e lo stato dell'impronta, e i fatti) e «Che cosa manca» (su fondo ambra, con il collegamento dove si aggiunge),
 * le cifre delle rate scelte e la frase in fondo all'indice. «Scarica il fascicolo (.zip)» è spento senza rate.
 *
 * L'anteprima si legge una volta, all'apertura: le caselle nascondono i pezzi, i fatti e le mancanze tolti con i segni che il server ha messo, senza un'altra
 * richiesta. Togliere un esercizio toglie le sue rate; togliere tutte le rate di un esercizio toglie l'esercizio. Se una prova si allega dopo, la finestra
 * riaperta lo dice di nuovo: l'anteprima si rilegge.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { AlertTriangle, ArrowRight, CheckCircle2, ChevronDown, ChevronRight, FolderArchive, Info } from 'lucide-vue-next';
import { fraseDaErroreConFile, frasePerErrore, nomeDalContentDisposition, scaricaFile, verificaIlFile } from '@/lib/gestionale/incassi/ricevute';
import {
    FRASE_DATI_PERSONALI, FRASE_IN_FONDO, FRASE_NESSUNA_MANCANZA, FRASE_NON_FATTO, FRASE_NON_LETTO, FRASE_PREPARATO, FRASE_SCEGLI_UNA_RATA, FRASE_TUTTO_SCELTO,
    NOMI_DELLE_PARTI, TITOLO, cifreScelte, dentro, testoCifra, testoDiCui, testoEsercizio, testoPezzo, testoProspetto, testoRata, testoUltimo,
    type AnteprimaDelFascicolo, type EsercizioDelFascicolo, type Parte,
} from '@/lib/gestionale/debiti/fascicoloLegale';

const props = defineProps<{ open: boolean; condominioId: number; persona: { id: number; nome: string } | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const rotta = (nome: string) => route(generateRoute(`gestionale.anagrafiche.${nome}`), { condominio: props.condominioId, anagrafica: props.persona?.id });

const anteprima = ref<AnteprimaDelFascicolo | null>(null);
const scelte = ref<Set<string>>(new Set());
const parti = ref<Set<Parte>>(new Set());
const aperti = ref<Set<string>>(new Set());
const problema = ref<string | null>(null);
const preparato = ref(false);
const inCorso = ref(false);
let lettura = 0;

async function leggi() {
    if (!props.persona) return;
    const numero = ++lettura;
    anteprima.value = null;
    problema.value = null;
    preparato.value = false;
    try {
        const a = (await axios.get(rotta('fascicolo-legale'))).data as AnteprimaDelFascicolo;
        // Arrivata dopo un'altra apertura: non è la persona a video.
        if (numero !== lettura) return;
        anteprima.value = a;
        scelte.value = new Set(a.esercizi.flatMap((e) => e.rate.map((r) => r.chiave)));
        parti.value = new Set(a.parti);
        aperti.value = new Set();
    } catch (e) {
        if (numero === lettura) problema.value = frasePerErrore(e, FRASE_NON_LETTO);
    }
}

watch(() => [props.open, props.persona?.id], ([aperta]) => {
    if (aperta) leggi();
}, { immediate: true });

const conLeScelte = <T extends { parte: Parte | null; rate: string[] | null }>(x: T) => dentro(x, scelte.value, parti.value);
const pezzi = computed(() => (anteprima.value?.pezzi ?? []).filter((p) => p.file !== null && conLeScelte(p)));
const fatti = computed(() => (anteprima.value?.fatti ?? []).filter(conLeScelte));
const mancanze = computed(() => (anteprima.value?.mancanze ?? []).filter(conLeScelte));
const cifre = computed(() => cifreScelte(anteprima.value?.esercizi ?? [], scelte.value));
const nessunaRata = computed(() => scelte.value.size === 0);
const senzaRate = computed(() => anteprima.value !== null && anteprima.value.esercizi.length === 0);

/** Lo stato della casella di un esercizio: tutte le sue rate, nessuna, o alcune. */
function statoEsercizio(e: EsercizioDelFascicolo): boolean | 'indeterminate' {
    const quante = e.rate.filter((r) => scelte.value.has(r.chiave)).length;

    return quante === 0 ? false : (quante === e.rate.length ? true : 'indeterminate');
}
function alternaEsercizio(e: EsercizioDelFascicolo) {
    const nuove = new Set(scelte.value);
    const tutte = statoEsercizio(e) === true;
    e.rate.forEach((r) => (tutte ? nuove.delete(r.chiave) : nuove.add(r.chiave)));
    scelte.value = nuove;
}
function alternaRata(chiave: string, si: boolean) {
    const nuove = new Set(scelte.value);
    if (si) nuove.add(chiave); else nuove.delete(chiave);
    scelte.value = nuove;
}
function alternaParte(p: Parte, si: boolean) {
    const nuove = new Set(parti.value);
    if (si) nuove.add(p); else nuove.delete(p);
    parti.value = nuove;
}
function alternaAperto(chiave: string, si: boolean) {
    const nuovi = new Set(aperti.value);
    if (si) nuovi.add(chiave); else nuovi.delete(chiave);
    aperti.value = nuovi;
}
/** Il numero della chiave di un esercizio, per i segni delle prove («esercizio-25» → 25). */
const idDi = (e: EsercizioDelFascicolo) => e.chiave.replace(/^esercizio-/, '');

async function scarica() {
    if (!anteprima.value || nessunaRata.value) return;
    problema.value = null;
    inCorso.value = true;
    try {
        // Nell'ordine dell'anteprima, non in quello delle spunte; le parti nell'ordine della finestra.
        const rate = anteprima.value.esercizi.flatMap((e) => e.rate.map((r) => r.chiave)).filter((k) => scelte.value.has(k));
        const scelteLeParti = anteprima.value.parti.filter((p) => parti.value.has(p));
        const r = await axios.post(rotta('fascicolo-legale.prepara'), { rate, parti: scelteLeParti }, { responseType: 'blob' });
        await verificaIlFile(r, 'zip');
        scaricaFile(r.data, nomeDalContentDisposition(r.headers?.['content-disposition'], 'fascicolo-per-il-legale.zip'));
        preparato.value = true;
        emit('fatto');
    } catch (e) {
        problema.value = await fraseDaErroreConFile(e, FRASE_NON_FATTO);
    } finally {
        inCorso.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg max-h-[90vh] overflow-y-auto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <FolderArchive class="h-5 w-5 text-slate-500" /> {{ TITOLO }}
                </DialogTitle>
                <DialogDescription>{{ persona?.nome }}<template v-if="anteprima"> · {{ anteprima.condominio }}</template></DialogDescription>
            </DialogHeader>

            <p v-if="!anteprima && !problema" class="text-xs text-slate-500">Leggo che cosa c'è…</p>

            <div v-if="anteprima" class="space-y-4 text-sm" data-testid="fascicolo">
                <p v-if="anteprima.ultimo" class="text-xs text-slate-600 dark:text-slate-300" data-testid="ultimo">{{ testoUltimo(anteprima.ultimo) }}</p>
                <p v-if="senzaRate" class="rounded-lg border border-slate-200 bg-slate-50/50 p-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-300">
                    {{ anteprima.persona.nome }} non ha rate scadute e non versate: il fascicolo non si prepara.
                </p>

                <!-- Che cosa metti nel fascicolo -->
                <section v-else class="space-y-2" data-testid="scelta">
                    <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500">Che cosa metti nel fascicolo</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_TUTTO_SCELTO }}</p>
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                        <Collapsible v-for="e in anteprima.esercizi" :key="e.chiave" :open="aperti.has(e.chiave)" @update:open="(v: boolean) => alternaAperto(e.chiave, v)">
                            <div class="flex items-center gap-2 px-2.5 py-2" :data-testid="`esercizio-${e.chiave}`">
                                <Checkbox :model-value="statoEsercizio(e)" :aria-label="e.nome" @update:model-value="() => alternaEsercizio(e)" />
                                <span class="min-w-0 flex-1 text-xs text-slate-800 dark:text-slate-100">{{ testoEsercizio(e, scelte) }}</span>
                                <CollapsibleTrigger as-child>
                                    <button type="button" :aria-label="aperti.has(e.chiave) ? 'Chiudi le rate' : 'Apri le rate'" :data-testid="`apri-esercizio-${idDi(e)}`"
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800">
                                        <ChevronDown v-if="aperti.has(e.chiave)" class="h-4 w-4" />
                                        <ChevronRight v-else class="h-4 w-4" />
                                    </button>
                                </CollapsibleTrigger>
                            </div>
                            <CollapsibleContent>
                                <ul class="space-y-1.5 pb-2 pl-6 pr-2.5">
                                    <li v-for="r in e.rate" :key="r.chiave" class="flex items-start gap-2" :data-testid="`rata-${r.chiave}`">
                                        <Checkbox class="mt-0.5" :model-value="scelte.has(r.chiave)" :aria-label="r.nome"
                                            @update:model-value="(v: boolean | 'indeterminate') => alternaRata(r.chiave, v === true)" />
                                        <span class="min-w-0 flex-1 text-xs text-slate-700 dark:text-slate-200">{{ testoRata(r) }}</span>
                                    </li>
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-2 pt-1" data-testid="parti">
                        <label v-for="p in anteprima.parti" :key="p" class="flex items-center gap-1.5 text-xs text-slate-700 dark:text-slate-200" :data-testid="`parte-${p}`">
                            <Checkbox :model-value="parti.has(p)" @update:model-value="(v: boolean | 'indeterminate') => alternaParte(p, v === true)" /> {{ NOMI_DELLE_PARTI[p] }}
                        </label>
                    </div>
                </section>

                <template v-if="!senzaRate">
                    <!-- Le cifre delle rate scelte -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/30" data-testid="cifre">
                        <p class="text-xs font-semibold text-slate-800 dark:text-slate-100">{{ testoCifra(cifre.totale, anteprima.oggi) }}</p>
                        <p v-for="e in cifre.esercizi" :key="e.nome" class="text-xs text-slate-600 dark:text-slate-300">{{ testoDiCui(e) }}</p>
                    </div>

                    <!-- Che cosa c'è -->
                    <section class="space-y-2" data-testid="che-cosa-c-e">
                        <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500">Che cosa c'è</h4>
                        <ul class="space-y-2">
                            <li v-for="p in pezzi" :key="p.file!" class="flex items-start gap-2">
                                <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <div class="min-w-0">
                                    <p class="break-words text-xs font-semibold text-slate-800 dark:text-slate-100">{{ p.file }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ p.tipo === 'prospetto' ? testoProspetto(cifre.rate, cifre.totale, anteprima.oggi) : testoPezzo(p) }}
                                    </p>
                                </div>
                            </li>
                            <li v-for="f in fatti" :key="f.frase" class="flex items-start gap-2">
                                <Info class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                <p class="min-w-0 text-xs text-slate-600 dark:text-slate-300">{{ f.frase }}</p>
                            </li>
                        </ul>
                    </section>

                    <!-- Che cosa manca -->
                    <section class="space-y-2" data-testid="mancanze">
                        <h4 class="text-xs font-bold uppercase tracking-widest text-amber-700 dark:text-amber-400">Che cosa manca</h4>
                        <ul v-if="mancanze.length" class="space-y-1.5">
                            <li v-for="m in mancanze" :key="m.frase"
                                class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50/60 px-2.5 py-2 dark:border-amber-900/50 dark:bg-amber-950/20">
                                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <div class="min-w-0 space-y-0.5">
                                    <p class="text-xs text-slate-800 dark:text-slate-100">{{ m.frase }}</p>
                                    <a v-if="m.azione" :href="m.azione.url"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-amber-800 hover:underline dark:text-amber-300">
                                        {{ m.azione.etichetta }} <ArrowRight class="h-3.5 w-3.5" />
                                    </a>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_NESSUNA_MANCANZA }}</p>
                    </section>

                    <div class="space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <p>{{ FRASE_IN_FONDO }}</p>
                        <p>{{ FRASE_DATI_PERSONALI }}</p>
                    </div>
                </template>

                <p v-if="preparato" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="preparato">{{ FRASE_PREPARATO }}</p>
            </div>
            <p v-if="problema" class="text-xs text-red-700 dark:text-red-400" data-testid="problema">{{ problema }}</p>

            <DialogFooter class="items-center gap-2">
                <span v-if="anteprima && !senzaRate && nessunaRata" class="text-xs text-slate-500">{{ FRASE_SCEGLI_UNA_RATA }}</span>
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Chiudi</Button>
                <Button size="sm" :disabled="!anteprima || senzaRate || nessunaRata || inCorso" data-testid="scarica-fascicolo" @click="scarica">
                    Scarica il fascicolo (.zip)
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
