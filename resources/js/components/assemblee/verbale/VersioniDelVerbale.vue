<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Le versioni» del verbale (scena 2 della bozza dell'editor, scena 5 della bozza delle scene): una ogni volta che si smette di scrivere per un po', e a ogni
 * gesto importante; «Vedi» mostra il testo in sola lettura, «Ripristina» torna a una versione (e il ripristino è una versione nuova). Registrato il verbale,
 * il testo è quello firmato: «Ripristina» non c'è più, e una versione arrivata dopo ha il suo badge. Le righe sono compatte (decisione 656), divise da una linea, la più
 * recente in alto (il server le manda già così, `VersioniDelVerbale::elenco()`): l'ora in grassetto e il gesto, sotto chi, a destra «Vedi» piccolo; niente
 * riquadri dentro il riquadro. Al telefono le versioni stanno in un cassetto.
 */
import { ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { ChevronRight, History } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { giorno, ora } from '@/lib/assemblee/verbale/pagina';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import TestoDelVerbale from './TestoDelVerbale.vue';
import { CARD, CONTENUTO_LARGO, CORPO, PICCOLO, PULSANTE, TESTA, percorso } from './finestra';
import type { VoceDellaVersione } from '@/types/verbale';

const props = defineProps<{ versioni: VoceDellaVersione[]; verbaleId: number; pagina: string; puoRipristinare: boolean }>();
const cassetto = ref(false);
const vista = ref<{ voce: VoceDellaVersione; documento: NodoDelVerbale | null } | null>(null);
const errore = ref('');

async function vedi(v: VoceDellaVersione) {
    errore.value = '';
    vista.value = { voce: v, documento: null };
    try {
        const r = await axios.get(percorso.versione(props.pagina, props.verbaleId, v.id));
        vista.value = { voce: v, documento: (r.data?.documento ?? null) as NodoDelVerbale | null };
    } catch {
        errore.value = 'La versione non si è aperta. Riprova.';
    }
}
function ripristina(v: VoceDellaVersione) {
    router.post(percorso.ripristina(props.pagina, props.verbaleId, v.id), {}, { preserveScroll: true });
}
const titolo = (v: VoceDellaVersione) => (v.perche ? v.perche.charAt(0).toUpperCase() + v.perche.slice(1) : `Versione ${v.numero}`);
</script>

<template>
    <!-- Dal tablet largo in su: la card accanto al testo -->
    <section :class="[CARD, 'hidden px-4 py-4 lg:block']" data-testid="le-versioni">
        <h2 class="text-[15px] font-semibold text-slate-900 dark:text-white">Le versioni</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Una ogni volta che smetti di scrivere per un po', e a ogni gesto importante.</p>
        <ol class="mt-2" data-testid="elenco-delle-versioni">
            <li v-for="v in versioni" :key="v.id" class="flex items-start justify-between gap-2 border-t border-slate-100 py-2 first:border-t-0 dark:border-slate-800" :data-versione="v.numero">
                <div class="min-w-0 text-xs text-slate-700 dark:text-slate-300">
                    <p class="break-words"><b class="font-semibold text-slate-900 dark:text-white">{{ ora(v.segnata_il ?? v.arrivata_il) }}</b> · {{ titolo(v) }}</p>
                    <p v-if="v.chi" class="text-slate-500 dark:text-slate-400" data-testid="chi-della-versione">{{ v.chi }}</p>
                    <p v-if="v.segnata_il && v.arrivata_il && ora(v.segnata_il) !== ora(v.arrivata_il)" class="text-slate-500 dark:text-slate-400">
                        scritta alle {{ ora(v.segnata_il) }}, arrivata alle {{ ora(v.arrivata_il) }}</p>
                    <span v-if="v.attesa === 'dopo_la_registrazione'" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'mt-1']">Arrivata dopo la registrazione</span>
                </div>
                <div class="flex shrink-0 gap-1.5">
                    <button type="button" :class="PICCOLO" data-testid="vedi-versione" @click="vedi(v)">Vedi</button>
                    <button v-if="puoRipristinare && !v.in_attesa && v.ripristina !== false" type="button" :class="PICCOLO" data-testid="ripristina" @click="ripristina(v)">Ripristina</button>
                </div>
            </li>
        </ol>
        <p v-if="!puoRipristinare" class="mt-2 text-xs text-slate-500 dark:text-slate-400">Registrato il verbale, il testo è quello firmato: «Ripristina» non c'è più.</p>
    </section>

    <!-- Al telefono e sul tablet: il cassetto -->
    <button type="button" :class="[PULSANTE, 'w-full justify-between lg:hidden']" data-testid="apri-le-versioni" @click="cassetto = true">
        <span class="inline-flex items-center gap-1.5"><History class="h-4 w-4" aria-hidden="true" />Le versioni · {{ versioni.length }}</span>
        <ChevronRight class="h-4 w-4" aria-hidden="true" />
    </button>
    <Sheet v-model:open="cassetto">
        <SheetContent class="w-full overflow-y-auto sm:w-[420px]">
            <SheetHeader><SheetTitle>Le versioni</SheetTitle><SheetDescription>Una ogni volta che smetti di scrivere per un po', e a ogni gesto importante.</SheetDescription></SheetHeader>
            <ol class="px-4 pb-6">
                <li v-for="v in versioni" :key="v.id" class="flex items-start justify-between gap-2 border-t border-slate-100 py-2 first:border-t-0 dark:border-slate-800">
                    <div class="min-w-0 text-xs text-slate-700 dark:text-slate-300">
                        <p class="break-words"><b class="font-semibold text-slate-900 dark:text-white">{{ ora(v.segnata_il ?? v.arrivata_il) }}</b> · {{ titolo(v) }}</p>
                        <p v-if="v.chi" class="text-slate-500 dark:text-slate-400">{{ v.chi }}</p>
                        <span v-if="v.attesa === 'dopo_la_registrazione'" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'mt-1']">Arrivata dopo la registrazione</span>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        <button type="button" :class="PICCOLO" @click="vedi(v)">Vedi</button>
                        <button v-if="puoRipristinare && !v.in_attesa && v.ripristina !== false" type="button" :class="PICCOLO" @click="ripristina(v)">Ripristina</button>
                    </div>
                </li>
            </ol>
        </SheetContent>
    </Sheet>

    <Dialog :open="vista !== null" @update:open="(a: boolean) => { if (!a) vista = null; }">
        <DialogContent :class="CONTENUTO_LARGO" data-testid="finestra-versione">
            <DialogHeader :class="TESTA">
                <DialogTitle>Versione {{ vista?.voce.numero }}</DialogTitle>
                <DialogDescription>{{ vista ? `${titolo(vista.voce)} · ${giorno(vista.voce.segnata_il)} alle ${ora(vista.voce.segnata_il)}` : '' }}. In sola lettura.</DialogDescription>
            </DialogHeader>
            <div :class="[CORPO, 'py-4']">
                <p v-if="errore" class="text-sm text-red-600 dark:text-red-500">{{ errore }}</p>
                <p v-else-if="vista && !vista.documento" class="text-sm text-slate-500 dark:text-slate-400">Un momento…</p>
                <TestoDelVerbale v-else-if="vista" :documento="vista.documento" />
            </div>
        </DialogContent>
    </Dialog>
</template>
