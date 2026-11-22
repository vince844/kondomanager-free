<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Rimanda la copia nuova» (dopo «Sostituisci la copia»), nello standard delle finestre: una finestra piccola con chi la riceve e come («Galli Franco · per
 * PEC», «Bianchi Anna · lettera da ristampare»), e «Manda». Il clic sul pulsante della trasmissione apre questa finestra: non manda più niente da solo.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import type { DestinatarioDelVerbale } from '@/types/verbale';

const props = defineProps<{ open: boolean; persone: DestinatarioDelVerbale[]; numero: number | string | null }>();
const emit = defineEmits<{ 'update:open': [v: boolean]; manda: [] }>();

const perPec = (p: DestinatarioDelVerbale) => p.mezzo.mezzo === 'pec';
const pec = computed(() => props.persone.filter(perPec).length);
const lettere = computed(() => props.persone.length - pec.value);
const riepilogo = computed(() => [pec.value ? (pec.value === 1 ? 'Parte 1 PEC' : `Partono ${pec.value} PEC`) : null,
    lettere.value ? (lettere.value === 1 ? 'si ristampa 1 lettera' : `si ristampano ${lettere.value} lettere`) : null].filter(Boolean).join('; ') + '.');
function manda() {
    emit('manda');
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-rimanda">
            <DialogHeader :class="TESTA">
                <DialogTitle>Rimanda la copia nuova{{ numero ? ` del verbale n. ${numero}` : '' }}</DialogTitle>
                <DialogDescription>La ricevono di nuovo, con lo stesso mezzo, quelli che hanno la copia di prima.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Chi la riceve, e come</p>
                    <ul class="space-y-1 text-sm text-slate-800 dark:text-slate-200">
                        <li v-for="p in persone" :key="p.anagrafica_id" class="break-words" data-testid="chi-la-riceve">{{ p.nome }} · {{ perPec(p) ? 'per PEC' : 'lettera da ristampare' }}</li>
                    </ul>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><span>{{ riepilogo }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="persone.length === 0" data-testid="manda-la-copia" @click="manda">Manda</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
