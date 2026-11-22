<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La raccolta delle date già scritte (§3 P7, R9, D10): le date di delibera scritte a mano nei piani rate, nelle dispense, negli accordi e nelle spese
 * impreviste, raggruppate per giorno e numero del verbale in riunioni a mano proposte, con una casella per scheda. **L'anteprima non scrive niente**; una data
 * sospetta (il giorno in cui il piano è stato approvato nel programma, o un giorno dopo oggi) ha il suo segno e non è spuntata di serie. Ogni riunione ha
 * «Per chi» (di serie tutto il condominio, o una palazzina, o una scala). «Raccogli N date» crea le riunioni, le delibere e i collegamenti delle sole righe
 * spuntate. Al telefono una card per riunione.
 */
import { computed, ref } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle } from 'lucide-vue-next';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import AssembleaGuide from '@/components/guides/AssembleaGuide.vue';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, giornoItaliano } from '@/lib/assemblee/assemblee';
import type { Building } from '@/types/buildings';

interface SchedaDellaRaccolta {
    oggetto_tipo: string; oggetto_id: number; ruolo: string; nome: string; giorno: string; numero_verbale: string | null; sospetta: string | null; spuntata: boolean;
}
interface RiunioneProposta { chiave: string; giorno: string; numero_verbale: string | null; riunione: { id: number; nome: string } | null; schede: SchedaDellaRaccolta[] }

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: any; anteprima: RiunioneProposta[];
    palazzine?: { id: number; nome: string }[]; scale?: { id: number; nome: string }[];
}>();

const { generatePath, generateRoute } = usePermission();
const mostraGuida = ref(false);
const chiave = (s: SchedaDellaRaccolta) => `${s.oggetto_tipo}:${s.oggetto_id}`;
const spuntate = ref<Record<string, boolean>>(Object.fromEntries(props.anteprima.flatMap((g) => g.schede.map((s) => [chiave(s), s.spuntata]))));
const quante = computed(() => Object.values(spuntate.value).filter(Boolean).length);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

/** «Per chi» di ogni riunione proposta: `condominio`, `palazzina:3`, `scala:7`. */
const perChi = ref<Record<string, string>>(Object.fromEntries(props.anteprima.map((g) => [g.chiave, 'condominio'])));
const opzioniPerChi = computed(() => [
    { valore: 'condominio', nome: 'Tutto il condominio' },
    ...(props.palazzine ?? []).map((p) => ({ valore: `palazzina:${p.id}`, nome: `Solo la palazzina ${p.nome}` })),
    ...(props.scale ?? []).map((sc) => ({ valore: `scala:${sc.id}`, nome: `Solo la scala ${sc.nome}` })),
]);
function perimetroDi(valore: string) {
    const [ambito, id] = valore.split(':');
    return { ambito, palazzina_id: ambito === 'palazzina' ? Number(id) : null, scala_id: ambito === 'scala' ? Number(id) : null };
}

const parolaDelTipo: Record<string, string> = {
    piano_rate: 'Piano rate', dispensa_sei_mesi: 'Dispensa dei sei mesi', accordo_rientro: 'Accordo di rientro', fattura_passiva: 'Spesa imprevista',
};

function raccogli() {
    inCorso.value = true;
    const scelte = props.anteprima.flatMap((g) => g.schede).filter((s) => spuntate.value[chiave(s)]).map((s) => ({ oggetto_tipo: s.oggetto_tipo, oggetto_id: s.oggetto_id }));
    const perimetri = Object.entries(perChi.value).filter(([, v]) => v !== 'condominio').map(([k, v]) => ({ chiave: k, ...perimetroDi(v) }));
    router.post(route(generateRoute('gestionale.delibere.raccolta.store'), { condominio: props.condominio.id }), { spuntate: scelte, perimetri }, {
        onError: (e: Record<string, string>) => { errori.value = e; }, onFinish: () => { inCorso.value = false; },
    });
}

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }) },
    { title: 'Raccogli le date scritte a mano' },
]);
</script>

<template>
    <Head title="Raccogli le date scritte a mano" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide
                page-title="Raccogli le date scritte a mano"
                :page-subtitle="`Le date di delibera scritte a mano in ${condominio.nome}, raccolte in riunioni registrate a mano. Niente cambia finché non confermi.`"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="condominio"
                :condomini="condomini"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            />

            <p v-if="anteprima.length === 0" class="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400"
                data-testid="raccolta-vuota">In {{ condominio.nome }} non ci sono date scritte a mano da raccogliere.</p>

            <template v-else>
                <section v-for="g in anteprima" :key="g.chiave" class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="riunione-proposta">
                    <header class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            Riunione del {{ giornoItaliano(g.giorno) }}{{ g.numero_verbale ? ` · verbale n. ${g.numero_verbale}` : ' · senza numero del verbale' }}
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <template v-if="perChi[g.chiave] !== 'condominio'">Entra nella riunione a mano dello stesso giorno, verbale e perimetro se c'è; altrimenti ne nasce una, con «Verbale da caricare».</template>
                            <template v-else>{{ g.riunione ? `Entra nella ${g.riunione.nome.toLowerCase()}, già registrata a mano.` : 'Nasce una riunione registrata a mano, con «Verbale da caricare».' }}</template>
                        </p>
                        <label v-if="opzioniPerChi.length > 1" class="mt-2 block max-w-xs">
                            <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Per chi era la riunione</span>
                            <div class="menu-campo" :data-testid="`per-chi-${g.chiave}`">
                                <v-select v-model="perChi[g.chiave]" :options="opzioniPerChi" label="nome" :reduce="(o: { valore: string }) => o.valore" :clearable="false" :searchable="false" />
                            </div>
                        </label>
                    </header>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        <li v-for="s in g.schede" :key="chiave(s)" class="flex items-start gap-3 px-4 py-2.5" data-testid="scheda-raccolta">
                            <Checkbox v-model="spuntate[chiave(s)]" class="mt-1 rounded" :aria-label="s.nome" />
                            <div class="min-w-0 flex-1">
                                <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ s.nome }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    <span :class="[BADGE, CLASSI_DEL_BADGE.neutro]">{{ parolaDelTipo[s.oggetto_tipo] ?? s.oggetto_tipo }}</span>
                                    <span>Scritta a mano: {{ giornoItaliano(s.giorno) }}{{ s.numero_verbale ? `, verbale n. ${s.numero_verbale}` : '' }}</span>
                                </p>
                                <p v-if="s.sospetta" class="mt-1 flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300" data-testid="data-sospetta">
                                    <AlertTriangle class="mt-px h-3.5 w-3.5 shrink-0" /> {{ s.sospetta }}.
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>
                <p v-for="(m, k) in errori" :key="k" class="text-sm text-rose-700 dark:text-rose-400">{{ m }}</p>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                    <p class="text-xs text-slate-500 dark:text-slate-400 sm:mr-auto">Ogni scheda collegata ha la sua riga nel registro delle modifiche; le date delle schede non cambiano.</p>
                    <button type="button" class="inline-flex h-10 items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-900"
                        :disabled="quante === 0 || inCorso" data-testid="raccogli" @click="raccogli">{{ inCorso ? 'Un momento…' : (quante === 1 ? 'Raccogli 1 data' : `Raccogli ${quante} date`) }}</button>
                </div>
            </template>
        </div>
        <AssembleaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
