<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Registra la spedizione» delle convocazioni stampate: le persone spuntate, come sono partite (raccomandata, a mano, fax), il giorno (non dopo oggi), il
 * numero di ogni raccomandata e una nota. Una richiesta sola per tutte; la scheda, la testata e il diario si ricaricano. Il programma non sa quando una
 * raccomandata arriva: lo dice l'esito, con «Registra la consegna».
 *
 * Con molti condòmini (da `DA_QUANTI_SI_CERCA` in su) sopra l'elenco c'è «Cerca per nome o per unità», come nelle presenze della sala; l'elenco scorre dentro
 * la finestra, e «Tutte» e «Nessuna» valgono su ciò che si vede: con la ricerca spuntano o tolgono solo le righe trovate, le altre restano come sono.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Send } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
// Il giorno di Roma, dove il programma conta i giorni della convocazione: con il computer su un altro fuso (Londra alle 23:30) il giorno del computer
// è ancora ieri, e il server rifiuta la spedizione «prima della lettera» (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import CercaNellElenco from '@/components/assemblee/CercaNellElenco.vue';
import { DA_QUANTI_SI_CERCA, paroleDellaRicerca, rispondeAllaRicerca } from '@/lib/assemblee/sala/cerca';
import { SOLO_CONVOCAZIONE } from './aiuti';
import type { RigaDelConvocato } from './tipi';

type Canale = 'raccomandata' | 'a_mano' | 'fax';

const props = defineProps<{ open: boolean; righe: RigaDelConvocato[]; scelte: number[] | null; rotta: string;
    /** La frase della spedizione di oggi dopo l'ultimo giorno utile, per canale (art. 66 disp. att.); null se in tempo. Non blocca. */
    tardive?: Record<string, string | null> | null;
    /** Che cosa si spedisce: le convocazioni (di serie) o le lettere del verbale; cambia solo le parole (rilievo C61). */
    cosa?: 'convocazioni' | 'verbale' }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'sposta-le-date'): void }>();

const CANALI: { valore: Canale; etichetta: string }[] = [
    { valore: 'raccomandata', etichetta: 'Raccomandata' },
    { valore: 'a_mano', etichetta: 'A mano' },
    { valore: 'fax', etichetta: 'Fax' },
];

const spuntate = ref<number[]>([]);
const canale = ref<Canale>('raccomandata');
const giorno = ref(giornoDiRoma());
const numeri = ref<Record<number, string>>({});
const nota = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
const oggi = giornoDiRoma();
const cerca = ref('');

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    const ids = props.righe.map((r) => r.invio_id).filter((id): id is number => id !== null);
    spuntate.value = props.scelte?.length ? ids.filter((id) => props.scelte!.includes(id)) : ids;
    const mezzi = new Set(props.righe.filter((r) => r.invio_id !== null && spuntate.value.includes(r.invio_id)).map((r) => r.mezzo));
    canale.value = mezzi.size === 1 && (mezzi.has('a_mano') || mezzi.has('fax')) ? ([...mezzi][0] as Canale) : 'raccomandata';
    giorno.value = oggi;
    numeri.value = {};
    nota.value = '';
    errori.value = {};
    cerca.value = '';
}, { immediate: true });

/** La frase vale per la spedizione di oggi: con un altro giorno scelto la finestra non la dice. */
const tardiva = computed(() => (giorno.value === oggi ? (props.tardive?.[canale.value] ?? null) : null));
const nessuna = computed(() => spuntate.value.length === 0);

/** Le stampate non ancora spedite: quante sono, in testa all'elenco. */
const tutteLeSpedibili = computed(() => props.righe.map((r) => r.invio_id).filter((id): id is number => id !== null));

// La ricerca, da otto persone in su: filtra l'elenco sul posto, per il nome e l'unità.
const conLaRicerca = computed(() => props.righe.length >= DA_QUANTI_SI_CERCA);
const cercando = computed(() => conLaRicerca.value && paroleDellaRicerca(cerca.value).length > 0);
const viste = computed(() => (cercando.value ? props.righe.filter((r) => rispondeAllaRicerca(cerca.value, [r.nome, r.unita])) : props.righe));

/** «Tutte» e «Nessuna» in testa all'elenco: valgono sulle righe che si vedono; con la ricerca le altre restano come sono. */
const idViste = () => viste.value.map((r) => r.invio_id).filter((id): id is number => id !== null);
const tutte = () => { spuntate.value = [...new Set([...spuntate.value, ...idViste()])]; };
const nessunaScelta = () => { const via = new Set(idViste()); spuntate.value = spuntate.value.filter((id) => !via.has(id)); };

function alterna(id: number, si: boolean) {
    spuntate.value = si ? [...new Set([...spuntate.value, id])] : spuntate.value.filter((x) => x !== id);
}

function registra() {
    if (nessuna.value || !giorno.value) return;
    const daMandare: Record<number, string> = {};
    if (canale.value === 'raccomandata') {
        for (const id of spuntate.value) if (numeri.value[id]?.trim()) daMandare[id] = numeri.value[id].trim();
    }
    inCorso.value = true;
    router.post(props.rotta, {
        invii: spuntate.value, canale: canale.value, data: giorno.value, numeri: daMandare, nota: nota.value.trim() || undefined,
    }, {
        preserveScroll: true,
        only: SOLO_CONVOCAZIONE,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Send class="h-5 w-5 text-sky-600" /> Registra la spedizione</DialogTitle>
                <DialogDescription>{{ cosa === 'verbale' ? 'Le lettere del verbale stampate che hai spedito o consegnato: i trenta giorni di chi le riceve contano dalla consegna.'
                    : 'Le convocazioni stampate che hai spedito o consegnato: il programma conta i giorni da qui.' }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <CercaNellElenco v-if="conLaRicerca" v-model="cerca" etichetta="Cerca per nome o per unità" testid="cerca-spedizione" testid-svuota="svuota-ricerca-spedizione" />
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-slate-500 dark:text-slate-400">Da spedire: {{ tutteLeSpedibili.length }}<template v-if="cercando"> · ne vedi {{ viste.length }}</template></span>
                    <button type="button" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        data-testid="spedizione-tutte" @click="tutte">Tutte</button>
                    <button type="button" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                        data-testid="spedizione-nessuna" @click="nessunaScelta">Nessuna</button>
                </div>
                <p v-if="cercando && viste.length === 0" class="text-xs text-slate-600 dark:text-slate-300" role="status" data-testid="ricerca-vuota-spedizione">
                    Nessuno per «{{ cerca.trim() }}»: prova con il cognome o con il numero dell'unità.
                </p>
                <!-- L'elenco scorre dentro la finestra: con molti condòmini la finestra non si allunga oltre lo schermo. -->
                <ul v-if="viste.length" class="max-h-[40vh] overflow-y-auto rounded-md border border-slate-100 px-2 dark:border-slate-800" data-testid="elenco-spedizione">
                    <li v-for="r in viste" :key="r.chiave" class="flex flex-col gap-1.5 border-b border-slate-100 py-2 last:border-b-0 dark:border-slate-800 sm:flex-row sm:items-center"
                        :data-testid="`spedizione-${r.invio_id}`">
                        <label class="flex min-w-0 flex-1 items-center gap-2">
                            <Checkbox :model-value="r.invio_id !== null && spuntate.includes(r.invio_id)"
                                @update:model-value="(v: boolean | 'indeterminate') => r.invio_id !== null && alterna(r.invio_id, v === true)" />
                            <span class="min-w-0">
                                <span class="block truncate text-slate-800 dark:text-slate-100">{{ r.nome }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ r.unita }} · {{ r.mezzo_parola }}</span>
                            </span>
                        </label>
                        <Input v-if="canale === 'raccomandata' && r.invio_id !== null && spuntate.includes(r.invio_id)" v-model="numeri[r.invio_id]"
                            class="h-8 w-full text-xs sm:w-44" placeholder="N. della raccomandata" :aria-label="`Numero della raccomandata di ${r.nome}`" />
                    </li>
                </ul>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label class="text-xs">Come è partita</Label>
                        <v-select v-model="canale" :options="CANALI" label="etichetta" :reduce="(c: { valore: Canale }) => c.valore" :searchable="false" :clearable="false" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="spedizione-giorno" class="text-xs">Il giorno</Label>
                        <CampoGiorno id="spedizione-giorno" v-model="giorno" :max="oggi" class="h-9 text-sm" />
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label for="spedizione-nota" class="text-xs">Una nota (facoltativa)</Label>
                    <Textarea id="spedizione-nota" v-model="nota" rows="2" class="min-h-[60px] resize-none text-sm" placeholder="Per esempio: consegnate in posta allo sportello di Via Roma." />
                </div>
                <div v-if="tardiva" class="space-y-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="spedizione-tardiva">
                    <p>{{ tardiva }}</p>
                    <p>Non blocca: puoi registrarla lo stesso.</p>
                    <Button variant="outline" size="sm" class="h-8 text-xs" data-testid="sposta-le-date" @click="emit('sposta-le-date')">Sposta le date</Button>
                </div>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori-spedizione">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="items-center gap-2">
                <span v-if="nessuna" class="text-xs text-slate-500">Spunta almeno una persona.</span>
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="nessuna || !giorno || inCorso" data-testid="registra-spedizione" @click="registra">
                    Registra la spedizione ({{ spuntate.length }})
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
