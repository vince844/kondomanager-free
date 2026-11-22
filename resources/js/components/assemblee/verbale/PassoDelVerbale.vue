<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il passo 5 «Verbale e trasmissione» della pagina dell'assemblea (scena 10 della bozza delle scene): le riunioni con il loro verbale, una riga ciascuna
 * (una sospesa e la sua prosecuzione sono due riunioni, due verbali: decisione 595 (1)), con «Apri»; la riga sotto il passo, ciò che manca e il pulsante li
 * scrive il server (`PassiDellAssemblea`), e la strada li mostra come per gli altri passi. Si apre con la prop `verbale`, chiesta quando il passo si apre.
 */
import { Link } from '@inertiajs/vue3';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { giorno, ora } from '@/lib/assemblee/verbale/pagina';
import { CARD, PULSANTE } from './finestra';
import type { VerbaleDelPasso } from '@/types/verbale';

defineProps<{ verbale: VerbaleDelPasso }>();

const riga = (r: VerbaleDelPasso['riunioni'][number]) => {
    if (!r.verbale) return r.chiusa_il ? `${r.esito === 'sospesa' ? 'sospesa' : 'chiusa'} alle ${ora(r.chiusa_il)} · da scrivere` : r.aperta_il ? 'in corso: la discussione si scrive sotto il voto' : 'dopo la sala';
    if (r.verbale.stato === 'registrato') return `n. ${r.verbale.numero} · registrato`;
    if (r.verbale.stato === 'annullato') return 'annullato';
    if (r.verbale.stato === 'in_sala') return r.chiusa_il ? 'da generare' : 'in corso: la discussione';
    return 'bozza';
};
const badge = (r: VerbaleDelPasso['riunioni'][number]) => (r.verbale?.stato === 'registrato' ? { t: 'Registrato', c: CLASSI_DEL_BADGE.verde }
    : r.verbale?.stato === 'bozza' ? { t: 'Bozza', c: CLASSI_DEL_BADGE.ambra } : r.chiusa_il ? { t: 'Da scrivere', c: CLASSI_DEL_BADGE.ambra } : { t: 'Dopo la sala', c: CLASSI_DEL_BADGE.neutro });
</script>

<template>
    <div class="space-y-3" data-testid="passo-del-verbale">
        <ul :class="[CARD, 'divide-y divide-slate-100 dark:divide-slate-800']">
            <li v-for="r in verbale.riunioni" :key="r.seduta_id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" data-testid="riunione-del-passo">
                <span class="min-w-0">
                    <b class="block text-sm font-semibold text-slate-900 dark:text-white">{{ giorno(r.giorno) }} · {{ r.convocazione === 'prima' ? 'prima' : 'seconda' }} convocazione</b>
                    <span class="text-xs text-slate-600 dark:text-slate-400">{{ riga(r) }}</span>
                </span>
                <span class="flex items-center gap-2">
                    <span :class="[BADGE, badge(r).c]">{{ badge(r).t }}</span>
                    <Link :href="r.url" :class="PULSANTE" data-testid="apri-il-verbale">Apri</Link>
                </span>
            </li>
            <li v-if="verbale.riunioni.length === 0" class="px-4 py-4 text-sm text-slate-600 dark:text-slate-400">Il verbale si apre dopo la sala.</li>
        </ul>
        <p class="text-xs text-slate-600 dark:text-slate-400">Una riunione, un verbale: se la presidente sospende e si prosegue un altro giorno, ogni giorno ha il suo. Il registro
            dei verbali è nella sua pagina: <Link :href="verbale.rotte.registro" class="font-medium underline underline-offset-2">Il registro dei verbali</Link>.</p>
    </div>
</template>
