<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Chiudi l'assemblea» (scena 6 della bozza delle scene della tappa 3): si chiude solo con un esito per ogni punto. La finestra elenca i punti senza esito,
 * ognuno con la sua azione: un voto da confermare porta al punto («Conferma»), il punto senza voto ha «Trattato», un punto non trattato diventa «Rinviato»
 * alla chiusura (torna fra i punti per la prossima assemblea, con il suo memo). «Chiudi alle …» si accende quando nessun voto aspetta la conferma.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { useSala } from './contesto';
import { parolaDellEsito } from './sala';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'vai', puntoId: number): void }>();
const { sala, segna, oraDelGesto } = useSala();

const confermati = computed(() => sala.value.punti.filter((p) => p.esito !== null));
const senza = computed(() => sala.value.punti.filter((p) => p.esito === null).map((p) => {
    const daConfermare = p.votazioni.find((v) => v.confermata_il === null) ?? null;
    const azione = daConfermare ? 'conferma' : p.maggioranza === 'senza_voto' ? 'trattato' : 'rinviato';

    return { punto: p, daConfermare, azione };
}));
const bloccata = computed(() => senza.value.some((x) => x.azione === 'conferma'));
/** La frase in testa: i punti da decidere hanno un gesto (conferma, trattato); i non trattati si rinviano da sé con la chiusura, e la frase lo dice (rilievo C56). */
const fraseInTesta = computed(() => {
    const daDecidere = senza.value.filter((x) => x.azione !== 'rinviato').length;
    const rinviandi = senza.value.filter((x) => x.azione === 'rinviato').map((x) => x.punto.numero);
    const prima = daDecidere ? `Ogni punto ha bisogno del suo esito: ne mancano ${daDecidere}.` : 'Ogni punto ha il suo esito.';
    if (!rinviandi.length) return prima;
    const elenco = rinviandi.length === 1 ? String(rinviandi[0]) : `${rinviandi.slice(0, -1).join(', ')} e ${rinviandi[rinviandi.length - 1]}`;
    return `${prima} ${rinviandi.length === 1 ? `Il punto ${elenco}, non trattato, si rinvia` : `I punti ${elenco}, non trattati, si rinviano`} con la chiusura.`;
});
/** Il rendiconto votato e il riparto no: la chiusura non lo ferma, ma la finestra lo dice. */
const senzaIlRiparto = computed(() => sala.value.punti.filter((p) => p.materia === 'rendiconto' && p.votazioni.some((v) => v.oggetto === 'rendiconto' && v.confermata_il !== null)
    && !p.votazioni.some((v) => v.numero === 2)));

async function trattato(puntoId: number) {
    if (sala.value.seduta_id !== null) await segna('trattato', { seduta_id: sala.value.seduta_id, punto_id: puntoId });
}
async function chiudi() {
    if (sala.value.seduta_id === null || bloccata.value) return;
    await segna('chiudi', { seduta_id: sala.value.seduta_id, ...(senza.value.length ? { non_trattati: 'rinviata' as const } : {}) });
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-chiudi" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>Chiudi l'assemblea</DialogTitle>
                <DialogDescription>
                    {{ fraseInTesta }}
                </DialogDescription>
            </DialogHeader>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-800">
                <li v-if="confermati.length" class="flex items-center justify-between gap-2 px-3 py-2">
                    <span class="text-slate-700 dark:text-slate-300">Punti {{ confermati.map((p) => p.numero).join(', ') }} con l'esito</span>
                    <span :class="[BADGE, CLASSI_DEL_BADGE.verde]">{{ confermati.length === 1 ? '1 esito' : `${confermati.length} esiti` }}</span>
                </li>
                <li v-for="x in senza" :key="x.punto.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" :data-senza-esito="x.punto.numero">
                    <span class="min-w-0">
                        <span class="block break-words text-slate-900 dark:text-white">{{ x.punto.numero }}. {{ x.punto.testo }}</span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            {{ x.azione === 'conferma' ? `votato · proposta: ${parolaDellEsito(x.daConfermare?.esito_proposto).toLowerCase() || 'decide il presidente'}`
                                : x.azione === 'trattato' ? 'senza voto' : 'non trattato' }}
                        </span>
                    </span>
                    <Button v-if="x.azione === 'conferma'" size="sm" variant="outline" @click="emit('update:open', false); emit('vai', x.punto.id)">Conferma</Button>
                    <Button v-else-if="x.azione === 'trattato'" size="sm" variant="outline" @click="trattato(x.punto.id)">Trattato</Button>
                    <span v-else :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Rinviato</span>
                </li>
            </ul>
            <p v-for="p in senzaIlRiparto" :key="p.id" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800
                dark:bg-amber-950/40 dark:text-amber-200" data-testid="senza-riparto">
                Il riparto del punto {{ p.numero }} non è votato: il rendiconto sì. Se serve, votalo prima di chiudere.
            </p>
            <ul class="list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400">
                <li>Un punto rinviato torna fra i punti per la prossima assemblea, con il suo memo.</li>
                <li>«Chiudi» si accende quando nessun voto aspetta la conferma.</li>
                <li>Il lavoro tenuto in questo browser si cancella quando la chiusura arriva al server.</li>
            </ul>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Torna in sala</Button>
                <Button size="sm" :disabled="bloccata" data-testid="conferma-chiudi" @click="chiudi">Chiudi alle {{ oraDelGesto() }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
