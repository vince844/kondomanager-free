<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Stato del verbale» (scena 1 della bozza del verbale, scene 4 e 10 della bozza delle scene; la forma compatta è la decisione 656): in testa il titolo e la riga
 * dello stato a sinistra, il badge e il «⋯» a destra, sulla stessa riga. La bozza con la versione, «Stampa la bozza da firmare», «Rigenera dal modello»,
 * «Registra il verbale sottoscritto», con i pulsanti piccoli. Registrato, sotto la testa e divisi da una linea, con l'etichetta sopra: «La copia firmata»
 * (il numero, il giorno, il peso, «Apri») e «La pagina per il libro dei verbali di carta» (da stampare e incollare, o il giorno dell'incollatura) con la «i», «Stampa la pagina» e
 * «Segna incollata nel libro» piccoli in riga. Nel menu ⋯ «Apri la copia firmata», «Sostituisci la copia» e «Annulla la registrazione», in rosso. «Firmato» il programma non lo vede: dopo la bozza stampata dice «da
 * firmare» finché la copia non è caricata, e «Cambiato dopo la stampa» se il testo o i dati della sala sono cambiati dopo (si ristampa: si registra il testo
 * firmato).
 */
import { computed } from 'vue';
import { MoreHorizontal } from 'lucide-vue-next';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { giorno, ora } from '@/lib/assemblee/verbale/pagina';
import { CARD, PICCOLO, PRINCIPALE_PICCOLO } from './finestra';
import type { PaginaDelVerbale, VerbaleDellaPagina } from '@/types/verbale';

const props = defineProps<{ verbale: VerbaleDellaPagina; puo: PaginaDelVerbale['puo']; versione: number | null; inStampa: boolean }>();
const emit = defineEmits<{ stampa: []; rigenera: []; registra: []; sostituisci: []; annulla: []; libro: []; incollata: [] }>();

const registrato = computed(() => props.verbale.stato === 'registrato');
const riga = computed(() => {
    const v = props.verbale;
    if (v.stato === 'registrato') return `Registrato alle ${ora(v.registrato_il)}${v.registrato_da ? ` da ${v.registrato_da}` : ''} · sottoscritto il ${giorno(v.sottoscritto_il)}`;
    if (v.stato === 'annullato') return `Annullato il ${giorno(v.annullato?.il)}${v.annullato?.da ? ` da ${v.annullato.da}` : ''}: «${v.annullato?.motivo ?? ''}»`;
    if (v.stampata?.cambiata) return v.stampata.cambiata;
    if (v.stampata) return `Bozza stampata alle ${ora(v.stampata.il)} · versione ${v.stampata.versione}: falla firmare a presidente e segretario`;
    if (v.generato_il) return `Bozza generata alle ${ora(v.generato_il)}${props.versione ? ` · versione ${props.versione}` : ''}`;
    return 'La discussione dei punti, scritta durante l\'assemblea';
});
const badge = computed(() => {
    const v = props.verbale;
    if (v.stato === 'registrato') return { testo: `Registrato · n. ${v.numero}`, colore: CLASSI_DEL_BADGE.verde };
    if (v.stato === 'annullato') return { testo: 'Annullato', colore: CLASSI_DEL_BADGE.rosso };
    if (v.stampata?.cambiata) return { testo: 'Cambiato dopo la stampa', colore: CLASSI_DEL_BADGE.rosso };
    if (v.stampata) return { testo: 'Da firmare', colore: CLASSI_DEL_BADGE.ambra };
    return { testo: v.stato_parola, colore: CLASSI_DEL_BADGE.neutro };
});
/** «Apri» la copia firmata la apre nel browser, in una scheda nuova (`?come=pdf` risponde inline); la rotta senza scarica il file com'è. */
const apriLaCopia = computed(() => (props.verbale.copia ? `${props.verbale.copia.url}${props.verbale.copia.url.includes('?') ? '&' : '?'}come=pdf` : ''));
const peso = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
</script>

<template>
    <section :class="[CARD, 'px-4 py-4']" data-testid="stato-del-verbale">
        <div class="flex items-start justify-between gap-2" data-testid="testata-dello-stato">
            <div class="min-w-0">
                <h2 class="text-[15px] font-semibold text-slate-900 dark:text-white">Stato del verbale</h2>
                <p class="mt-0.5 break-words text-xs text-slate-600 dark:text-slate-400">{{ riga }}</p>
                <p v-if="verbale.sostituisce" class="text-xs text-slate-600 dark:text-slate-400">Sostituisce il n. {{ verbale.sostituisce }}, annullato.</p>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                <span :class="[BADGE, badge.colore]" data-testid="badge-dello-stato">{{ badge.testo }}</span>
                <DropdownMenu v-if="registrato && puo.registrare">
                    <DropdownMenuTrigger as-child>
                        <button type="button" :class="[PICCOLO, 'w-7 px-0']" aria-label="Altre azioni del verbale" data-testid="menu-del-verbale"><MoreHorizontal class="h-4 w-4" /></button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuItem v-if="verbale.copia" as-child><a :href="apriLaCopia" target="_blank" rel="noopener">Apri la copia firmata</a></DropdownMenuItem>
                        <DropdownMenuItem data-testid="sostituisci-la-copia" @select="emit('sostituisci')">Sostituisci la copia</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="text-rose-700 dark:text-rose-400" data-testid="voce-annulla" @select="emit('annulla')">Annulla la registrazione</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <template v-if="registrato">
            <div v-if="verbale.copia" class="mt-3 space-y-1 border-t border-slate-100 pt-3 dark:border-slate-800" data-testid="la-copia">
                <p class="text-xs text-slate-500 dark:text-slate-400">La copia firmata</p>
                <p class="flex flex-wrap items-center gap-x-2 text-xs text-slate-700 dark:text-slate-300">
                    <span class="min-w-0 break-words">Verbale n. {{ verbale.numero }} del {{ giorno(verbale.sottoscritto_il) }} · {{ peso(verbale.copia.peso) }}</span>
                    <a :href="apriLaCopia" target="_blank" rel="noopener" class="font-medium underline underline-offset-2" data-testid="apri-la-copia">Apri</a>
                </p>
            </div>
            <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800" data-testid="nel-libro">
                <p class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">La pagina per il libro dei verbali di carta
                    <SuggerimentoDellaI testid="i-libro-di-carta" testo="Il registro dei verbali si tiene anche su un libro di carta: «Stampa la pagina» prepara il foglio da incollare, «Segna incollata nel libro» registra che l'hai incollato." /></p>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <p class="text-xs text-slate-700 dark:text-slate-300">{{ verbale.nel_libro_il ? `Incollata nel libro il ${giorno(verbale.nel_libro_il)}` : 'Da stampare e incollare' }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" :class="PICCOLO" data-testid="pagina-per-il-libro" @click="emit('libro')">Stampa la pagina</button>
                        <button v-if="puo.registrare && !verbale.nel_libro_il" type="button" :class="PICCOLO" data-testid="segna-incollata" @click="emit('incollata')">Segna incollata nel libro</button>
                    </div>
                </div>
            </div>
        </template>
        <template v-else-if="verbale.stato === 'bozza'">
            <p class="mt-3 text-xs text-slate-600 dark:text-slate-400">Stampa la bozza, falla firmare a presidente e segretario, poi carica la copia firmata (una scansione, o il PDF
                firmato digitalmente .p7m).</p>
            <div v-if="puo.gestire || puo.registrare" class="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <button v-if="puo.gestire" type="button" :class="PICCOLO" :disabled="inStampa" data-testid="stampa-la-bozza" @click="emit('stampa')">Stampa la bozza da firmare</button>
                <button v-if="puo.gestire" type="button" :class="PICCOLO" data-testid="rigenera" @click="emit('rigenera')">Rigenera dal modello</button>
                <button v-if="puo.registrare" type="button" :class="[PRINCIPALE_PICCOLO, 'w-full sm:w-auto']" data-testid="registra-il-verbale" @click="emit('registra')">Registra il verbale sottoscritto</button>
            </div>
        </template>
    </section>
</template>
