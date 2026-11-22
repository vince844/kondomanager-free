<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Aggiungi un documento» a un punto (la scena 2 della bozza approvata dei documenti dei punti), nella forma delle finestre: ancorata in alto, il corpo che
 * scorre dentro, i gruppi con il titolo piccolo e grigio separati da una riga, il riepilogo fisso in fondo accanto ai pulsanti, «Aggiungi» nero.
 *
 * Tre gruppi: «Proposti dal programma» (i PDF delle schede di cui parla il punto, mai spuntati da soli; con «Scegli» la finestra si apre con quelli già
 * spuntati), «Dai documenti di Via Roma 12» (i documenti del condominio con la ricerca per nome; un file che non è un PDF non si sceglie e lo dice accanto al
 * nome), «Un PDF nuovo» (va nei documenti del condominio, nella categoria «Assemblee», e si collega al punto). La finestra si chiede al server quando si apre
 * e a ogni ricerca; intanto il segno piccolo nel riepilogo, e «Aggiungi» spento.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { FileText, Loader2, Plus, Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import CampoFile from '@/components/CampoFile.vue';
import { SOLO_FASCICOLO } from '../convocazione/aiuti';
import type { FinestraDeiDocumenti } from '../convocazione/tipi';

const props = defineProps<{
    open: boolean;
    punto: { id: number; numero: string; testo?: string; sotto?: string | null } | null;
    /** I documenti già spuntati all'apertura: quelli della proposta, con «Scegli». */
    scelti: number[];
    rotte: { documenti: string; aggiungi: string };
    condominio: string;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const dati = ref<FinestraDeiDocumenti | null>(null);
const spuntati = ref<number[]>([]);
/** Nome e pagine dei documenti visti, anche se una ricerca li toglie dall'elenco: il riepilogo li conta lo stesso. */
const visti = ref<Record<number, { nome: string; pagine: number | null }>>({});
const cerca = ref('');
const file = ref<File | null>(null);
const caricamento = ref(false);
const inCorso = ref(false);
const errore = ref<string | null>(null);
let lettura = 0;
let attesa: ReturnType<typeof setTimeout> | null = null;

const conPunto = (url: string) => url.replace('__PUNTO__', String(props.punto?.id ?? ''));

async function leggi() {
    if (!props.punto) return;
    const numero = ++lettura;
    caricamento.value = true;
    try {
        const parametri = cerca.value.trim() !== '' ? { cerca: cerca.value.trim() } : {};
        const r = (await axios.get(conPunto(props.rotte.documenti), { params: parametri })).data as FinestraDeiDocumenti;
        if (numero !== lettura) return;
        dati.value = r;
        for (const d of [...r.proposti, ...r.condominio.documenti]) {
            visti.value[d.id] = { nome: d.nome, pagine: d.pagine };
        }
    } catch {
        if (numero === lettura) errore.value = 'I documenti non sono arrivati. Riprova.';
    } finally {
        if (numero === lettura) caricamento.value = false;
    }
}

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    dati.value = null;
    spuntati.value = [...props.scelti];
    visti.value = {};
    cerca.value = '';
    file.value = null;
    errore.value = null;
    leggi();
}, { immediate: true });

/** La ricerca parte quando ti fermi di scrivere, o subito con Invio. */
function cercaDopo() {
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(() => { attesa = null; leggi(); }, 300);
}
function cercaSubito() {
    if (attesa) clearTimeout(attesa);
    attesa = null;
    leggi();
}

function spunta(id: number, si: boolean) {
    spuntati.value = si ? [...new Set([...spuntati.value, id])] : spuntati.value.filter((x) => x !== id);
}

const filePdf = computed(() => file.value === null || file.value.type === 'application/pdf' || /\.pdf$/i.test(file.value.name));
const quanti = computed(() => spuntati.value.length + (file.value && filePdf.value ? 1 : 0));
const pagine = computed(() => spuntati.value.reduce((n, id) => n + (visti.value[id]?.pagine ?? 0), 0));
const pronta = computed(() => quanti.value > 0 && filePdf.value && !caricamento.value && !inCorso.value && props.punto !== null);
const parolaPagine = (n: number) => (n === 1 ? '1 pagina' : `${n} pagine`);

function aggiungi() {
    if (!pronta.value || !props.punto) return;
    inCorso.value = true;
    errore.value = null;
    const corpo: Record<string, unknown> = { documenti: [...spuntati.value] };
    if (file.value) corpo.file = file.value;
    router.post(conPunto(props.rotte.aggiungi), corpo, {
        forceFormData: true,
        preserveScroll: true,
        only: SOLO_FASCICOLO,
        onSuccess: () => emit('update:open', false),
        onError: (e: Record<string, string>) => { errore.value = Object.values(e).join(' '); },
        onFinish: () => { inCorso.value = false; },
    });
}

const gruppo = 'space-y-2 border-b border-slate-100 py-4 last:border-b-0 dark:border-slate-800';
const titoloGruppo = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
const sottoRiga = 'block text-xs text-slate-500 dark:text-slate-400';
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <!-- La forma delle finestre: ancorata in alto (non si ricentra quando cambia altezza), il contenuto scorre dentro, il riepilogo e i pulsanti fissi
             nel piede; l'aggiornamento è un segno piccolo nel riepilogo, mai una riga che compare e scompare. -->
        <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[640px]" data-testid="finestra-documenti"
            @open-auto-focus.prevent>
            <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
                <DialogTitle data-testid="titolo-finestra">Aggiungi un documento al punto {{ punto?.numero }}</DialogTitle>
                <DialogDescription v-if="dati">{{ dati.punto.testo }}<template v-if="dati.punto.sotto"> ({{ dati.punto.sotto }})</template></DialogDescription>
            </DialogHeader>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 text-sm" data-testid="corpo-finestra">
                <template v-if="dati">
                    <section v-if="dati.proposti.length" :class="gruppo">
                        <h3 :class="titoloGruppo" data-testid="titolo-gruppo">Proposti dal programma</h3>
                        <!-- I proposti come pulsanti affiancati con la loro casella, e accanto le pagine e da dove vengono. -->
                        <div class="flex flex-wrap gap-2">
                            <label v-for="d in dati.proposti" :key="d.id" class="flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-1.5 text-[13px] transition-colors"
                                :class="spuntati.includes(d.id) ? 'border-slate-900 dark:border-slate-300' : 'border-slate-200 hover:border-slate-300 dark:border-slate-700'"
                                :data-testid="`scelta-${d.id}`">
                                <Checkbox :model-value="spuntati.includes(d.id)" class="rounded" @update:model-value="(v: any) => spunta(d.id, !!v)" />
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ d.nome }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ d.pagine !== null ? `${parolaPagine(d.pagine)} · ` : '' }}{{ d.da }}</span>
                            </label>
                        </div>
                    </section>

                    <section :class="gruppo">
                        <h3 :class="titoloGruppo" data-testid="titolo-gruppo">Dai documenti di {{ dati.condominio.nome }}</h3>
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-2 left-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
                            <input v-model="cerca" type="search" placeholder="Cerca per nome" aria-label="Cerca per nome" data-testid="cerca-documenti"
                                class="h-8 w-full rounded-lg border border-slate-200 bg-white pr-2.5 pl-8 text-[13px] text-slate-900 placeholder:text-slate-400 focus:border-slate-400 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                @input="cercaDopo" @keydown.enter.prevent="cercaSubito" />
                        </div>
                        <!-- L'elenco scorre dentro il suo gruppo. -->
                        <ul class="max-h-56 overflow-y-auto">
                            <li v-for="d in dati.condominio.documenti" :key="d.id" class="border-t border-slate-100 first:border-t-0 dark:border-slate-800">
                                <label class="flex items-start gap-2.5 py-2" :class="d.pdf && d.pagine !== null && !d.gia ? 'cursor-pointer' : 'cursor-default'" :data-testid="`scelta-${d.id}`">
                                    <Checkbox :model-value="d.gia || spuntati.includes(d.id)" :disabled="!d.pdf || d.gia || d.pagine === null" class="mt-0.5 rounded"
                                        @update:model-value="(v: any) => spunta(d.id, !!v)" />
                                    <span class="min-w-0 text-[13px]" :class="d.pdf ? 'text-slate-900 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400'">
                                        <span class="break-words">{{ d.nome }}</span>
                                        <span v-if="!d.pdf" :class="sottoRiga">Non è un PDF: non va nel fascicolo.</span>
                                        <span v-else-if="d.gia" :class="sottoRiga">Già nel punto</span>
                                        <span v-else-if="d.pagine === null" class="block text-xs text-amber-800 dark:text-amber-300" data-testid="non-si-legge">Non si legge come PDF: non va nel fascicolo.</span>
                                        <span v-else :class="sottoRiga">{{ [d.pagine !== null ? parolaPagine(d.pagine) : null, d.categorie || null, d.caricato_il ? `caricato il ${d.caricato_il}` : null].filter(Boolean).join(' · ') }}</span>
                                        <span v-if="d.pdf && !d.gia && d.di_una_persona" class="block text-xs text-amber-800 dark:text-amber-300" data-testid="di-una-persona">È di una persona: arriva a tutti i convocati.</span>
                                    </span>
                                </label>
                            </li>
                        </ul>
                        <p v-if="dati.condominio.documenti.length === 0" class="text-xs text-slate-500 dark:text-slate-400">
                            {{ cerca.trim() !== '' ? 'Nessun documento con questo nome.' : `Nessun documento nei documenti di ${dati.condominio.nome}.` }}
                        </p>
                        <p v-if="dati.condominio.altri > 0" class="text-xs text-slate-500 dark:text-slate-400">e altri {{ dati.condominio.altri }}: cercali per nome.</p>
                    </section>

                    <section :class="gruppo">
                        <h3 :class="titoloGruppo" data-testid="titolo-gruppo">Un PDF nuovo</h3>
                        <CampoFile v-model="file" accept="application/pdf,.pdf" testid="file-documento" :disabled="inCorso" />
                        <p v-if="file && !filePdf" class="text-xs text-rose-700 dark:text-rose-300">«{{ file.name }}» non è un PDF: scegli un file PDF.</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Va nei documenti di {{ dati.condominio.nome }}, nella categoria «Assemblee», e si collega al punto. Solo PDF: il fascicolo è un PDF solo.
                            Al più {{ dati.tetto }}.
                        </p>
                    </section>
                </template>
                <p v-else-if="caricamento" class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Un momento…</p>

                <p v-if="errore" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
                    data-testid="errore-finestra">{{ errore }}</p>
            </div>

            <!-- Il piede fisso: il riepilogo di ciò che succede a sinistra, i pulsanti a destra. -->
            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/60"
                data-testid="piede-finestra">
                <p class="flex min-w-0 flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300" role="status" data-testid="riepilogo">
                    <span v-if="caricamento || inCorso" class="inline-flex shrink-0" data-testid="aggiorno"><Loader2 class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /><span class="sr-only">Un momento…</span></span>
                    <FileText v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0">
                        <template v-if="quanti === 0">Scegli un documento da aggiungere al punto {{ punto?.numero }}.</template>
                        <template v-else-if="spuntati.length === 0">Si aggiunge <b class="font-semibold text-slate-900 dark:text-white">1 documento</b> al punto {{ punto?.numero }}: il PDF nuovo.</template>
                        <template v-else>{{ quanti === 1 ? 'Si aggiunge' : 'Si aggiungono' }} <b class="font-semibold text-slate-900 dark:text-white">{{ quanti === 1 ? '1 documento' : `${quanti} documenti` }}</b>
                            al punto {{ punto?.numero }}: <b class="font-semibold text-slate-900 dark:text-white">{{ parolaPagine(pagine) }}</b> nel fascicolo<template v-if="file && filePdf">, più quelle del PDF nuovo</template>.</template>
                    </span>
                </p>
                <!-- I pulsanti restano su una riga: se il riepilogo è lungo, va a capo lui. -->
                <div class="flex shrink-0 justify-end gap-2">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!pronta" data-testid="aggiungi" @click="aggiungi"><Plus class="mr-1.5 h-4 w-4" /> Aggiungi</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
