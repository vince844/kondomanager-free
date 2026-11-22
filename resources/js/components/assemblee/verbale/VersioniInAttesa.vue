<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le versioni che aspettano una scelta (scene 4 dell'editor e 5 della bozza delle scene; decisione 519 (4)):
 *
 * - **arrivata dopo la registrazione**: niente si perde e niente si applica da solo; l'avviso dice chi, l'ora in cui è stata scritta e l'ora di arrivo, con
 *   «Confronta con il registrato» (i due testi affiancati, in sola lettura) e «Ho visto»;
 * - **due versioni** (un altro dispositivo ha scritto la stessa parte): niente si sovrascrive; «Tieni questa», «Tieni quella», «Uniscile» (l'editor sulla
 *   versione di adesso con l'altra accanto, da cui copiare). Finché la scelta è aperta, «Stampa la bozza» aspetta. Nella pagina del verbale le dice la rete
 *   (`DueVersioniDelVerbale`, dal punto di vista di questo dispositivo): qui (`senzaDueVersioni`) resta solo «Uniscile», che la rete apre con `unisci()`;
 * - **arrivata dopo la generazione** (il tablet rimasto senza rete manda la discussione di un punto dopo «Genera»): il verbale intero non la legge più;
 *   «Mettila nel punto 4 del verbale» la mette al suo posto, «Lasciala fra le versioni» no. Finché non si sceglie, la bozza e la registrazione aspettano.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ora } from '@/lib/assemblee/verbale/pagina';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import EditorDelVerbale from './EditorDelVerbale.vue';
import TestoDelVerbale from './TestoDelVerbale.vue';
import { CONTENUTO_LARGO, CORPO, PIEDE, PULSANTE, PULSANTI, RIEPILOGO, TESTA, percorso } from './finestra';
import type { VerbaleDellaPagina } from '@/types/verbale';

type InAttesa = VerbaleDellaPagina['in_attesa'][number];
const props = withDefaults(defineProps<{ verbale: VerbaleDellaPagina; pagina: string; puoGestire: boolean; senzaDueVersioni?: boolean }>(),
    { senzaDueVersioni: false });

const aperta = ref<{ voce: InAttesa; modo: 'confronta' | 'unisci' } | null>(null);
const unito = ref<NodoDelVerbale | null>(null);
const diAdesso = (v: InAttesa) => props.verbale.parti[v.parte]?.documento ?? null;
const chi = (v: InAttesa) => (v.dispositivo ? `Da ${v.dispositivo}` : 'Da un altro dispositivo') + (v.chi ? ` (${v.chi})` : '');
function scegli(v: InAttesa, scelta: 'questa' | 'quella' | 'uniscile' | 'vista' | 'metti', documento?: NodoDelVerbale | null) {
    router.post(percorso.scegli(props.pagina, props.verbale.id, v.id), { scelta, ...(documento ? { documento } : {}) },
        { preserveScroll: true, onSuccess: () => { aperta.value = null; } });
}
function unisci(v: InAttesa) {
    unito.value = diAdesso(v);
    aperta.value = { voce: v, modo: 'unisci' };
}
const riquadro = 'space-y-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100';
const voci = computed(() => props.verbale.in_attesa.filter((v) => !props.senzaDueVersioni || v.attesa !== 'due_versioni'));
/** Il numero del punto di una discussione («la discussione del punto 4» → 4). */
const numeroDelPunto = (v: InAttesa) => /(\d+)$/.exec(v.nome_della_parte ?? '')?.[1] ?? '';
/** «Uniscile» aperto da fuori (le due versioni della rete), sulla versione data. */
function unisciDaFuori(id: number) {
    const voce = props.verbale.in_attesa.find((x) => x.id === id);
    if (voce) unisci(voce);
}
defineExpose({ unisci: unisciDaFuori });
</script>

<template>
    <div v-if="voci.length" class="space-y-2" data-innesto="due-versioni" data-testid="versioni-in-attesa">
        <div v-for="v in voci" :key="v.id" :class="riquadro" :data-attesa="v.attesa">
            <template v-if="v.attesa === 'dopo_la_registrazione'">
                <p class="font-medium">Arrivata dopo la registrazione: {{ v.nome_della_parte }}.</p>
                <p class="text-xs">{{ chi(v) }}: scritta alle {{ ora(v.segnata_il) }} senza rete, arrivata alle {{ ora(v.arrivata_il) }}. Il verbale n. {{ verbale.numero }} è la
                    copia firmata e non cambia: questa versione resta da parte.</p>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="button" :class="PULSANTE" data-testid="confronta" @click="aperta = { voce: v, modo: 'confronta' }">Confronta con il registrato</button>
                    <button v-if="puoGestire" type="button" :class="PULSANTE" data-testid="ho-visto" @click="scegli(v, 'vista')">Ho visto</button>
                </div>
            </template>
            <template v-else-if="v.attesa === 'dopo_la_generazione'">
                <p class="font-medium">Arrivata dopo la generazione: {{ v.nome_della_parte }}.</p>
                <p class="text-xs">{{ chi(v) }}: scritta alle {{ ora(v.segnata_il) }}, arrivata alle {{ ora(v.arrivata_il) }}, quando il verbale era già generato. Non
                    è nel testo: mettila nel punto, o lasciala fra le versioni. Finché non scegli, la bozza da firmare aspetta.</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button type="button" :class="PULSANTE" data-testid="confronta" @click="aperta = { voce: v, modo: 'confronta' }">Vedi il testo arrivato</button>
                    <template v-if="puoGestire">
                        <button type="button" :class="PULSANTE" data-testid="metti-nel-punto" @click="scegli(v, 'metti')">Mettila nel punto {{ numeroDelPunto(v) }} del verbale</button>
                        <button type="button" :class="PULSANTE" data-testid="lasciala" @click="scegli(v, 'quella')">Lasciala fra le versioni</button>
                    </template>
                </div>
            </template>
            <template v-else>
                <p class="font-medium">{{ v.nome_della_parte }}: due versioni.</p>
                <p class="text-xs">{{ chi(v) }}, scritta alle {{ ora(v.segnata_il) }}. Non sovrascrivo niente: le due versioni restano, e scegli tu quale tenere, o le unisci.
                    Finché non scegli, la bozza da firmare aspetta.</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button type="button" :class="PULSANTE" data-testid="confronta" @click="aperta = { voce: v, modo: 'confronta' }">Confronta le due versioni</button>
                    <template v-if="puoGestire">
                        <button type="button" :class="PULSANTE" data-testid="tieni-questa" @click="scegli(v, 'questa')">Tieni questa</button>
                        <button type="button" :class="PULSANTE" data-testid="tieni-quella" @click="scegli(v, 'quella')">Tieni quella di adesso</button>
                        <button type="button" :class="PULSANTE" data-testid="uniscile" @click="unisci(v)">Uniscile</button>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <Dialog :open="aperta !== null" @update:open="(a: boolean) => { if (!a) aperta = null; }">
        <DialogContent :class="CONTENUTO_LARGO" data-testid="finestra-confronto">
            <DialogHeader :class="TESTA">
                <DialogTitle>{{ aperta?.modo === 'unisci' ? 'Uniscile' : aperta?.voce.attesa === 'dopo_la_registrazione' ? `${aperta?.voce.nome_della_parte}, a confronto` : 'Le due versioni' }}</DialogTitle>
                <DialogDescription>{{ aperta?.modo === 'unisci' ? 'Correggi il testo di adesso, copiando dall\'altra versione accanto.' : 'In sola lettura, una accanto all\'altra.' }}</DialogDescription>
            </DialogHeader>
            <div v-if="aperta" :class="[CORPO, 'grid gap-3 py-4 md:grid-cols-2']">
                <div class="min-w-0 space-y-1">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ aperta.voce.attesa === 'dopo_la_registrazione' ? `Nel verbale n. ${verbale.numero}, firmato` : 'Adesso' }}</p>
                    <EditorDelVerbale v-if="aperta.modo === 'unisci'" v-model="unito" etichetta="Il testo unito" />
                    <TestoDelVerbale v-else :documento="diAdesso(aperta.voce)" class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800" />
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ chi(aperta.voce) }}, {{ ora(aperta.voce.segnata_il) }}</p>
                    <TestoDelVerbale :documento="aperta.voce.documento" class="rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2 dark:border-emerald-900 dark:bg-emerald-950/20" />
                </div>
            </div>
            <div v-if="aperta?.modo === 'unisci'" :class="PIEDE">
                <p :class="RIEPILOGO" role="status">Il testo unito diventa la versione di adesso; le due di prima restano fra le versioni.</p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="aperta = null">Chiudi</Button>
                    <Button data-testid="conferma-uniscile" @click="aperta && scegli(aperta.voce, 'uniscile', unito)">Tieni il testo unito</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
