<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il foglio delle consegne a mano: prima «Stampa il foglio», l'elenco di chi riceve la convocazione a mano con la colonna della firma; poi «Carica il
 * foglio firmato», una scansione sola che vale per tutte le persone spuntate, ognuna con il giorno in cui ha firmato. Ogni consegna diventa l'esito della
 * sua riga, con il foglio come prova («Foglio» nella colonna «Prova»), e una riga nel diario.
 *
 * Con molti condòmini (da `DA_QUANTI_SI_CERCA` in su) sopra l'elenco c'è «Cerca per nome o per unità», come nelle presenze della sala; l'elenco scorre dentro
 * la finestra, e «Tutte» e «Nessuna» valgono su ciò che si vede: con la ricerca spuntano o tolgono solo le righe trovate, le altre restano come sono.
 */
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { FileSignature, Printer } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
// Il giorno di Roma, dove il programma conta i giorni della convocazione, non quello del computer (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import CercaNellElenco from '@/components/assemblee/CercaNellElenco.vue';
import { DA_QUANTI_SI_CERCA, paroleDellaRicerca, rispondeAllaRicerca } from '@/lib/assemblee/sala/cerca';
import { apriPdfInUnaScheda, fraseDaErroreConFile } from '@/lib/gestionale/incassi/ricevute';
import { SOLO_CONVOCAZIONE, testoDelFile } from './aiuti';
import type { RigaDelConvocato } from './tipi';

const props = defineProps<{ open: boolean; righe: RigaDelConvocato[]; rotte: { foglio: string; foglio_firmato: string } }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const oggi = giornoDiRoma();
const spuntate = ref<number[]>([]);
const giorni = ref<Record<number, string>>({});
const foglio = ref<File | null>(null);
const esito = ref<string | null>(null);
const problema = ref<string | null>(null);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
const cerca = ref('');

const conInvio = computed(() => props.righe.filter((r) => r.invio_id !== null));

// La ricerca, da otto persone in su: filtra l'elenco sul posto, per il nome e l'unità.
const conLaRicerca = computed(() => conInvio.value.length >= DA_QUANTI_SI_CERCA);
const cercando = computed(() => conLaRicerca.value && paroleDellaRicerca(cerca.value).length > 0);
const viste = computed(() => (cercando.value ? conInvio.value.filter((r) => rispondeAllaRicerca(cerca.value, [r.nome, r.unita])) : conInvio.value));

/** «Tutte» e «Nessuna» in testa all'elenco: valgono sulle righe che si vedono; con la ricerca le altre restano come sono. */
const idViste = () => viste.value.map((r) => r.invio_id as number);
const tutte = () => { spuntate.value = [...new Set([...spuntate.value, ...idViste()])]; };
const nessuna = () => { const via = new Set(idViste()); spuntate.value = spuntate.value.filter((id) => !via.has(id)); };

watch(() => props.open, (aperta) => {
    if (!aperta) return;
    spuntate.value = conInvio.value.map((r) => r.invio_id as number);
    giorni.value = Object.fromEntries(conInvio.value.map((r) => [r.invio_id as number, oggi]));
    foglio.value = null;
    esito.value = null;
    problema.value = null;
    errori.value = {};
    cerca.value = '';
}, { immediate: true });

function alterna(id: number, si: boolean) {
    spuntate.value = si ? [...new Set([...spuntate.value, id])] : spuntate.value.filter((x) => x !== id);
}

function stampa() {
    esito.value = null;
    problema.value = null;
    const anagrafiche = props.righe.map((r) => r.anagrafica_id).filter((id): id is number => id !== null);
    // La scheda si apre nel clic, prima della richiesta.
    apriPdfInUnaScheda(() => axios.post(props.rotte.foglio, { anagrafiche }, { responseType: 'blob' }), 'foglio-delle-consegne-a-mano.pdf')
        .then((arrivato) => { esito.value = testoDelFile(arrivato, 'Falla firmare a chi riceve, poi caricala qui sotto.'); })
        .catch(async (e) => { problema.value = await fraseDaErroreConFile(e, 'Il foglio non si è stampato. Riprova.'); });
}


const manca = computed(() => !foglio.value || spuntate.value.length === 0 || spuntate.value.some((id) => !giorni.value[id]));

function carica() {
    if (manca.value || !foglio.value) return;
    inCorso.value = true;
    const consegne = conInvio.value.filter((r) => spuntate.value.includes(r.invio_id as number))
        .map((r) => ({ invio_id: r.invio_id as number, giorno: giorni.value[r.invio_id as number] }));
    router.post(props.rotte.foglio_firmato, { foglio: foglio.value, consegne } as never, {
        forceFormData: true,
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
                <DialogTitle class="flex items-center gap-2"><FileSignature class="h-5 w-5 text-slate-500" /> Il foglio delle consegne a mano</DialogTitle>
                <DialogDescription>Chi riceve la convocazione a mano firma il foglio; la scansione caricata una volta vale per tutte le sue righe.</DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm">
                <section class="space-y-2">
                    <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400">1. Stampa il foglio</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ righe.length === 1 ? '1 persona' : `${righe.length} persone` }}, con la colonna della firma e del giorno.</p>
                    <Button variant="outline" size="sm" class="h-8" data-testid="stampa-foglio" @click="stampa"><Printer class="h-3.5 w-3.5" /> Stampa il foglio</Button>
                    <p v-if="esito" class="text-xs text-slate-600 dark:text-slate-300">{{ esito }}</p>
                    <p v-if="problema" class="text-xs text-rose-700 dark:text-rose-300">{{ problema }}</p>
                </section>

                <section class="space-y-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400">2. Carica il foglio firmato</h4>
                    <CampoFile v-model="foglio" accept=".pdf,.jpg,.jpeg,.png" testid="file-foglio" />
                    <CercaNellElenco v-if="conLaRicerca" v-model="cerca" etichetta="Cerca per nome o per unità" testid="cerca-foglio" testid-svuota="svuota-ricerca-foglio" />
                    <div v-if="conInvio.length > 1" class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-slate-500 dark:text-slate-400">Hanno firmato: {{ spuntate.length }} di {{ conInvio.length }}<template v-if="cercando"> · ne vedi {{ viste.length }}</template></span>
                        <button type="button" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            data-testid="foglio-tutte" @click="tutte">Tutte</button>
                        <button type="button" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            data-testid="foglio-nessuna" @click="nessuna">Nessuna</button>
                    </div>
                    <p v-if="cercando && viste.length === 0" class="text-xs text-slate-600 dark:text-slate-300" role="status" data-testid="ricerca-vuota-foglio">
                        Nessuno per «{{ cerca.trim() }}»: prova con il cognome o con il numero dell'unità.
                    </p>
                    <!-- L'elenco scorre dentro la finestra: con molti condòmini la finestra non si allunga oltre lo schermo. -->
                    <ul v-if="viste.length || !conInvio.length" class="max-h-[40vh] overflow-y-auto rounded-md border border-slate-100 px-2 dark:border-slate-800" data-testid="elenco-foglio">
                        <li v-for="r in viste" :key="r.chiave" class="flex flex-col gap-1.5 border-b border-slate-100 py-2 last:border-b-0 dark:border-slate-800 sm:flex-row sm:items-center"
                            :data-foglio="r.invio_id">
                            <label class="flex min-w-0 flex-1 items-center gap-2">
                                <Checkbox :model-value="spuntate.includes(r.invio_id as number)" @update:model-value="(v: boolean | 'indeterminate') => alterna(r.invio_id as number, v === true)" />
                                <span class="min-w-0">
                                    <span class="block truncate text-slate-800 dark:text-slate-100">{{ r.nome }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ r.unita }}</span>
                                </span>
                            </label>
                            <div v-if="spuntate.includes(r.invio_id as number)" class="flex items-center gap-2">
                                <Label class="text-xs text-slate-500" :for="`foglio-giorno-${r.invio_id}`">Ha firmato il</Label>
                                <CampoGiorno :id="`foglio-giorno-${r.invio_id}`" v-model="giorni[r.invio_id as number]" :max="oggi" class="w-40" />
                            </div>
                        </li>
                        <li v-if="!conInvio.length" class="py-3 text-xs text-slate-500">Nessuna consegna a mano da registrare: le lettere si stampano prima.</li>
                    </ul>
                    <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300">
                        <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                    </ul>
                </section>
            </div>

            <DialogFooter class="items-center gap-2">
                <span v-if="!foglio" class="text-xs text-slate-500">Scegli la scansione del foglio firmato.</span>
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="manca || inCorso" data-testid="carica-foglio" @click="carica">Registra le consegne ({{ spuntate.length }})</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
