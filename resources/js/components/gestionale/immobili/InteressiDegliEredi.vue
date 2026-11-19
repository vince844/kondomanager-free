<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Decisione 494 (2): nella successione, sotto la card degli interessi del defunto, «Gli interessi degli eredi»: il riassunto in due righe scritto dal server
 * con l'anteprima, «Come le regole del condominio, in Recupero dei crediti» con il collegamento, e «Cambia per questa successione», che apre gli stessi otto
 * interruttori. La card c'è con l'arretrato agli eredi: solo lì gli eredi ereditano un debito che prende interessi. Il valore è `null` finché
 * l'amministratore non cambia una scelta: valgono le regole del condominio. Cambiata, la successione tiene **solo le scelte cambiate** (seconda prova della
 * beta.12): le altre seguono le regole del condominio, anche se cambiano dopo; la card dice quali sono, e «Torna alle regole del condominio» le toglie.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import ScelteDegliEredi from '@/components/gestionale/addebiti/InteressiDegliEredi.vue';
import { usePermission } from '@/composables/permissions';
import { scelteCambiate, scelteDalleVoci, testoScelteCambiate, type SceltaDegliEredi, type VoceInteressiEredi } from '@/lib/gestionale/addebiti/interessiDegliEredi';

const props = defineProps<{
    condominioId: number; frase: string | null | undefined; voci: VoceInteressiEredi[]; modelValue: Partial<Record<SceltaDegliEredi, boolean>> | null;
    /** Le regole del condominio, come le manda l'anteprima: le scelte cambiate sono quelle diverse da queste. */
    delCondominio?: Record<SceltaDegliEredi, boolean> | null;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Partial<Record<SceltaDegliEredi, boolean>> | null): void }>();

const { generateRoute } = usePermission();
const aperta = ref(props.modelValue !== null);
/** Le regole del condominio; senza, i valori delle voci prima di ogni cambio. */
const base = computed(() => props.delCondominio ?? scelteDalleVoci(props.voci));
const valori = computed(() => ({ ...base.value, ...(props.modelValue ?? {}) }) as Record<SceltaDegliEredi, boolean>);
const cambia = (tutte: Record<SceltaDegliEredi, boolean>) => emit('update:modelValue', scelteCambiate(tutte, base.value));
const indirizzoRegole = computed(() => route(generateRoute('gestionale.recupero-crediti.index'), { condominio: props.condominioId }));
const esempio = 'Per esempio: Ettore muore il 17/11/2026 e non ha pagato la rata di aprile, € 300,00. Il suo debito passa a Elena e Marco al 50 %, e '
    + 'arriva in una loro rata del 2027 che scade il 31/03. Di serie Elena e Marco pagano gli interessi su € 150,00 a testa dal 17/11 al 31/03, € 1,11 '
    + 'al 2 % l\'anno; poi la loro rata, con i suoi interessi. Resta una proposta: diventa un addebito solo se la registri.';
</script>

<template>
    <div class="space-y-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 dark:border-slate-700 dark:bg-slate-950" data-testid="interessi-degli-eredi-successione">
        <p class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-slate-100">
            Gli interessi degli eredi
            <TooltipProvider :delay-duration="200">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span tabindex="0" role="img" :aria-label="esempio" data-testid="info-interessi-degli-eredi"
                            class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                            <Info class="w-3.5 h-3.5" />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ esempio }}</p></TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </p>
        <p v-if="frase" class="text-xs leading-snug text-slate-700 dark:text-slate-300" data-testid="riassunto-eredi">{{ frase }}</p>
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            <span v-if="modelValue === null" data-testid="come-il-condominio">Come le regole del condominio, in
                <Link :href="indirizzoRegole" class="underline" data-testid="vai-alle-regole">Recupero dei crediti</Link></span>
            <span v-else data-testid="cambiate-per-questa">{{ testoScelteCambiate(modelValue, voci) }} Le altre seguono le regole del condominio, in
                <Link :href="indirizzoRegole" class="underline">Recupero dei crediti</Link>.</span>
            <button v-if="!aperta" type="button" class="underline" data-testid="cambia-per-questa" @click="aperta = true">Cambia per questa successione</button>
            <button v-else-if="modelValue !== null" type="button" class="underline" data-testid="torna-al-condominio" @click="emit('update:modelValue', null)">Torna alle regole del condominio</button>
        </p>
        <ScelteDegliEredi v-if="aperta" :voci="voci" :model-value="valori" modificabile prefisso="successione-eredi" @update:model-value="cambia" />
    </div>
</template>
