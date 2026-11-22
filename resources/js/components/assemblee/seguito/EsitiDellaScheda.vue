<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Gli esiti delle delibere su una scheda di arrivo (P4; scena 8 della bozza della tappa): «Respinto dall'assemblea del 15/12/2026, punto 2 (verbale n.
 * 42).», «Rinviato …», «Revocata il …», «Annullata dal giudice il …», «Impugnata il … · non ferma niente», ognuna con il suo badge e «Apri l'assemblea».
 * Un componente solo, che ogni scheda monta con una riga: il rendiconto, il piano, la fattura, l'accordo, la dispensa, le regole, il fondo, la gestione.
 * Chiede le frasi al server quando si monta, solo a chi vede le assemblee; senza esiti non mostra niente. Sono frasi: nessun conto cambia.
 */
import { onMounted, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { BADGE, CLASSI_DEL_BADGE, type ColoreDelBadge } from '@/lib/assemblee/assemblee';

const props = defineProps<{ condominioId: number; tipo: string; id: number }>();

interface Esito { tipo: 'respinta' | 'rinviata' | 'impugnata' | 'annullata' | 'revocata'; frase: string; delibera_id: number; assemblea_id: number }

const { generateRoute, hasPermission } = usePermission();
const esiti = ref<Esito[]>([]);
const PAROLA: Record<Esito['tipo'], [string, ColoreDelBadge]> = {
    respinta: ['Respinta', 'rosso'], rinviata: ['Rinviata', 'neutro'], impugnata: ['Impugnata', 'ambra'], annullata: ['Annullata', 'rosso'], revocata: ['Revocata', 'rosso'],
};
const apri = (e: Esito) => route(generateRoute('gestionale.assemblee.show'), { condominio: props.condominioId, assemblea: e.assemblea_id, scheda: 'delibere',
    delibera: e.delibera_id });

onMounted(async () => {
    // Solo chi vede le assemblee: per gli altri la scheda resta com'era, senza chiedere niente.
    if (typeof hasPermission === 'function' && !hasPermission([Permission.VIEW_ASSEMBLEE, Permission.MANAGE_ASSEMBLEE])) return;
    try {
        const r = await axios.get(route(generateRoute('gestionale.esiti-nelle-schede'), { condominio: props.condominioId, tipo: props.tipo, id: props.id }));
        esiti.value = (r.data?.esiti ?? []) as Esito[];
    } catch {
        esiti.value = [];
    }
});
</script>

<template>
    <ul v-if="esiti.length" class="space-y-1.5" data-testid="esiti-della-scheda">
        <li v-for="e in esiti" :key="`${e.tipo}-${e.delibera_id}`" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-800 dark:bg-slate-950"
            data-testid="esito-della-delibera">
            <span :class="[BADGE, CLASSI_DEL_BADGE[PAROLA[e.tipo]?.[1] ?? 'neutro']]">{{ PAROLA[e.tipo]?.[0] ?? e.tipo }}</span>
            <span class="min-w-0 flex-1 break-words text-slate-800 dark:text-slate-200">{{ e.frase }}</span>
            <Link :href="apri(e)" class="text-xs font-medium underline underline-offset-2">Apri l'assemblea</Link>
        </li>
    </ul>
</template>
