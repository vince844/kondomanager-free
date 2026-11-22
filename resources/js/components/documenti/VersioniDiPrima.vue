<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Versioni di prima» nella pagina di modifica di un documento (1.12.0-beta.13, §9 del registro delle modifiche, decisioni 518 (4) e 559: l'archivio e le
 * schede della persona, del fornitore e dell'unità): i file
 * sostituiti, dal più recente, in sola lettura, con il giorno, chi l'ha sostituito, la grandezza, l'impronta e «Scarica». Le versioni arrivano con la pagina
 * (`versioni`, senza il percorso); qui non si scrive niente.
 */
import { Download, FileClock } from 'lucide-vue-next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermission } from '@/composables/permissions';
import {
    DESCRIZIONE_VERSIONI, TITOLO_VERSIONI, VUOTO_VERSIONI, improntaBreve, rigaDellaVersione, type VersioneDiPrima,
} from '@/lib/documenti/versioniDiPrima';

const props = withDefaults(defineProps<{
    versioni: VersioneDiPrima[];
    documentoId: number;
    /** La rotta dello scaricamento, senza il prefisso del ruolo: di serie quella dell'archivio; nelle schede della persona, del fornitore e dell'unità la loro. */
    rotta?: string;
    /** I parametri della rotta oltre al documento e alla versione: `{ anagrafica }`, `{ fornitore }`, `{ condominio, immobile }`. */
    parametri?: Record<string, number>;
}>(), { rotta: 'documenti.versioni.download', parametri: () => ({}) });

const { generateRoute } = usePermission();

const indirizzo = (v: VersioneDiPrima) => route(generateRoute(props.rotta), { ...props.parametri, documento: props.documentoId, versione: v.id });
</script>

<template>
    <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20" data-testid="versioni-di-prima">
        <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">{{ TITOLO_VERSIONI }}</CardTitle>
            <CardDescription>{{ DESCRIZIONE_VERSIONI }}</CardDescription>
        </CardHeader>
        <CardContent>
            <p v-if="!versioni.length" class="text-sm text-muted-foreground" data-testid="versioni-vuoto">{{ VUOTO_VERSIONI }}</p>
            <ul v-else class="divide-y divide-border rounded-lg border bg-white dark:bg-slate-950">
                <li v-for="v in versioni" :key="v.id" class="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap" data-testid="versione">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/20">
                        <FileClock class="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium" :title="v.nome">{{ v.nome }}</div>
                        <div class="text-xs text-muted-foreground">{{ rigaDellaVersione(v) }}</div>
                        <div class="text-xs text-muted-foreground break-all" :title="v.impronta">Impronta {{ improntaBreve(v.impronta) }}</div>
                    </div>
                    <a
                        :href="indirizzo(v)"
                        class="inline-flex h-8 shrink-0 items-center justify-center gap-2 rounded-md border border-input bg-background px-3 text-xs font-semibold shadow-sm transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        <Download class="h-4 w-4" />
                        Scarica
                    </a>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
