<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La linguetta «Assemblee» della scheda di una persona (decisione 651; prima, «Le sue assemblee» stavano in «Dettagli», decisione 622 (5)).
 *
 * In testa tre card (quante assemblee e in quanti condomini, «Può impugnare», come si convoca), poi i filtri per condominio e per anno e l'elenco a pagine.
 * **I filtri e le pagine lavorano sul server** e non ricaricano la pagina: una visita parziale di `righe`, `meta` e `filtri`, con `preserveState` e
 * `preserveScroll`; un filtro cambiato torna alla prima pagina e tiene le righe per pagina. Le card contano tutte le assemblee, non solo la pagina.
 * **Solo lo studio** (con «Vede le assemblee»); i menu sono `v-select`.
 */
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import AppLayout from '@/layouts/AppLayout.vue';
import AnagraficaLayout from '@/layouts/anagrafiche/AnagraficaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ElencoAssembleeDellaPersona from '@/components/assemblee/ElencoAssembleeDellaPersona.vue';
import PagineDelRegistro from '@/components/registroModifiche/PagineDelRegistro.vue';
import { usePermission } from '@/composables/permissions';
import { parolaDelMezzo, type PersonaInAssemblea } from '@/lib/assemblee/persona';
import { CalendarClock, Gavel, Mail } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';

interface Riepilogo {
    totale: number;
    condomini: number;
    ultima: string | null;
    puo_impugnare: { quante: number; fino_al: string | null };
}

const props = defineProps<{
    anagrafica: { id: number; nome: string; mezzo_convocazione?: string | null; [chiave: string]: unknown };
    riepilogo: Riepilogo;
    righe: PersonaInAssemblea[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    condomini: { id: number; nome: string }[];
    anni: number[];
    filtri: { condominio: number | null; anno: number | null };
}>();

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Anagrafiche', href: route(generateRoute('anagrafiche.index')) },
    { title: props.anagrafica.nome, href: generatePath('anagrafiche/:anagrafica', { anagrafica: props.anagrafica.id }) },
    { title: 'Assemblee', href: '#' },
]);

const pageGuides = [
    { title: 'Le sue assemblee', description: 'Tutte le assemblee in cui è convocata, di ogni condominio, con ciò che la riguarda.', icon: Gavel, colorVariant: 'blue' as const },
    { title: 'Può impugnare', description: 'Finché corre il termine di trenta giorni, l\'assemblea è segnata in ambra.', icon: CalendarClock, colorVariant: 'amber' as const },
    { title: 'Solo lo studio', description: 'La persona non la vede nel suo portale.', icon: Mail, colorVariant: 'emerald' as const },
];

const frase = (n: number, uno: string, molti: string) => `${n} ${n === 1 ? uno : molti}`;
const assemblee = computed(() => `${frase(props.riepilogo.totale, 'assemblea', 'assemblee')} in ${frase(props.riepilogo.condomini, 'condominio', 'condomini')}`);
const impugnare = computed(() => props.riepilogo.puo_impugnare.quante === 0 ? 'Nessuna'
    : `${props.riepilogo.puo_impugnare.quante}${props.riepilogo.puo_impugnare.fino_al ? `, fino al ${props.riepilogo.puo_impugnare.fino_al}` : ', trenta giorni dal verbale'}`);
const comeSiConvoca = computed(() => parolaDelMezzo(props.anagrafica.mezzo_convocazione) ?? 'Lo propone il programma dai dati');

const indirizzo = () => generatePath('anagrafiche/:anagrafica/assemblee', { anagrafica: props.anagrafica.id });

/** Una visita parziale: solo ciò che cambia, senza ricaricare la pagina. */
function visita(extra: Record<string, number | null>) {
    const dati: Record<string, number> = {};
    const tutto = { condominio: props.filtri.condominio, anno: props.filtri.anno, per_page: props.meta.per_page, ...extra };
    for (const [k, v] of Object.entries(tutto)) if (v !== null && v !== undefined) dati[k] = v;
    router.get(indirizzo(), dati, { preserveState: true, preserveScroll: true, replace: true, only: ['righe', 'meta', 'filtri'] });
}
const cambiaCondominio = (id: number | null) => visita({ condominio: id, page: null });
const cambiaAnno = (anno: number | null) => visita({ anno, page: null });
const vaiAllaPagina = (page: number) => visita({ page });
const cambiaMisura = (per_page: number) => visita({ per_page, page: null });

const idDelCondominio = (c: { id: number }) => c.id;
const menu = 'menu-campo w-full text-xs text-slate-700 dark:text-slate-300 sm:w-56';
const card = 'rounded-xl border border-slate-200/80 bg-slate-50 px-5 py-3.5 dark:border-slate-700/50 dark:bg-slate-800/50';
</script>

<template>
    <AppLayout>
        <Head :title="`Assemblee — ${anagrafica.nome}`" />

        <div class="space-y-4 px-6 py-8">
            <PageHeaderGuide :page-title="anagrafica.nome" page-subtitle="Le assemblee in cui è convocata" :guides="pageGuides" :breadcrumbs="breadcrumbs" :video-url="null"
                :back-url="generatePath('anagrafiche')" back-text="Indietro" />

            <div class="w-full">
                <AnagraficaLayout>
                    <div class="grid grid-cols-1 gap-4 pb-4 sm:grid-cols-3" data-testid="card-in-testa">
                        <div :class="card" data-testid="card-assemblee">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Assemblee</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ assemblee }}</span>
                        </div>
                        <div :class="card" data-testid="card-impugnare">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Può impugnare</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ impugnare }}</span>
                        </div>
                        <div :class="card" data-testid="card-convocazione">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Come si convoca</span>
                            <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ comeSiConvoca }}</span>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div class="flex flex-col gap-2 border-b border-slate-100 p-4 dark:border-slate-800 sm:flex-row sm:items-center" data-testid="filtri">
                            <div :class="menu" data-testid="filtro-condominio">
                                <v-select :model-value="filtri.condominio" :options="condomini" label="nome" :reduce="idDelCondominio" placeholder="Tutti i condomini"
                                    :clearable="true" class="w-full" @update:model-value="cambiaCondominio" />
                            </div>
                            <div :class="menu" data-testid="filtro-anno">
                                <v-select :model-value="filtri.anno" :options="anni" placeholder="Tutti gli anni" :clearable="true" class="w-full" @update:model-value="cambiaAnno" />
                            </div>
                        </div>
                        <ElencoAssembleeDellaPersona :assemblee="righe" />
                        <PagineDelRegistro :pagina="meta.current_page" :pagine="meta.last_page" :per-pagina="meta.per_page" :totale="meta.total"
                            @pagina="vaiAllaPagina" @per-pagina="cambiaMisura" />
                    </div>
                </AnagraficaLayout>
            </div>
        </div>
    </AppLayout>
</template>
