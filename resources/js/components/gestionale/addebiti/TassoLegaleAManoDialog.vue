<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il tasso legale di un anno che il programma non ha ancora, scritto a mano dallo studio dalla pagina «Recupero dei crediti»: l'anno (fra quelli che si
 * possono scrivere), il tasso e la fonte (il decreto e la Gazzetta Ufficiale). Vale per tutti i condomini. Un anno già scritto si corregge: la finestra
 * parte dal tasso e dalla fonte di prima e lo dice. Gli errori del server arrivano nel gruppo «tassoLegale», separati da quelli della regola nuova
 * della stessa pagina. L'anno si sceglie con il menu del programma, che si apre dentro la finestra, sopra i campi sotto.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { cheCosaMancaAlTassoLegale, testoScrittoAMano, type TassoLegaleAMano } from '@/lib/gestionale/addebiti/accessori';

const props = defineProps<{
    open: boolean; condominioId: number;
    /** L'anno da cui parte la finestra: il primo che manca, o quello da correggere. */
    anno: number | null;
    /** Gli anni che si possono scrivere oggi: dal primo dopo quelli del programma all'anno prossimo. */
    anni: number[];
    /** I tassi già scritti a mano. */
    aMano: TassoLegaleAMano[];
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const { generateRoute } = usePermission();
const page = usePage<{ errors?: Record<string, unknown> }>();
const errori = computed(() => Object.values((page.props.errors?.tassoLegale ?? {}) as Record<string, string>));

const modulo = ref<{ anno: number | null; tasso: string; fonte: string }>({ anno: null, tasso: '', fonte: '' });
const inCorso = ref(false);
const giaScritto = computed(() => props.aMano.find((t) => t.anno === modulo.value.anno) ?? null);
const manca = computed(() => cheCosaMancaAlTassoLegale(modulo.value));

/** Parte da un anno: con il tasso e la fonte già scritti, se ci sono. */
function parti(anno: number | null) {
    const scritto = props.aMano.find((t) => t.anno === anno);
    modulo.value = { anno, tasso: scritto?.tasso_annuo ?? '', fonte: scritto?.fonte ?? '' };
}
watch(() => props.open, (aperta) => {
    if (aperta) {
        parti(props.anno ?? props.anni[0] ?? null);
    }
}, { immediate: true });

/**
 * Aperta, la finestra mette il cursore nel tasso, il campo da scrivere: il primo campo è il menu dell'anno, che prendendo il cursore si aprirebbe da sé.
 */
function cursoreNelTasso(evento: Event) {
    evento.preventDefault();
    nextTick(() => document.getElementById('tasso-legale')?.focus());
}

/** Le voci del menu dell'anno. */
const vociAnno = computed(() => props.anni.map((a) => ({ valore: a, etichetta: String(a) })));
const valoreDi = (v: { valore: number }) => v.valore;

function registra() {
    if (manca.value !== null || inCorso.value) {
        return;
    }
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.recupero-crediti.tasso-legale.store'), { condominio: props.condominioId }),
        { anno: modulo.value.anno, tasso: modulo.value.tasso, fonte: modulo.value.fonte },
        { errorBag: 'tassoLegale', preserveScroll: true, onSuccess: () => emit('update:open', false), onFinish: () => { inCorso.value = false; } });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md" @open-auto-focus="cursoreNelTasso">
            <DialogHeader>
                <DialogTitle data-testid="titolo-tasso">Il tasso legale di un anno</DialogTitle>
                <DialogDescription data-testid="spiegazione-tasso">
                    Vale per tutti i condomini, finché il programma non ha il tasso di quell'anno. Se poi il programma ne ha uno diverso, vale quello del programma.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-4">
                    <div class="menu-tasso-legale space-y-1.5" data-testid="anno-tasso">
                        <Label for="anno-tasso" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Anno</Label>
                        <v-select
                            :model-value="modulo.anno"
                            :options="vociAnno"
                            label="etichetta"
                            :reduce="valoreDi"
                            :searchable="false"
                            :clearable="false"
                            input-id="anno-tasso"
                            class="w-full"
                            @update:model-value="(v: number) => parti(v)"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="tasso-legale" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Tasso annuo (%)</Label>
                        <Input id="tasso-legale" v-model="modulo.tasso" inputmode="decimal" class="h-9 w-full text-sm" data-testid="tasso-legale" />
                    </div>
                </div>
                <p v-if="giaScritto" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200"
                    data-testid="gia-scritto">
                    Il {{ giaScritto.anno }} ha già {{ giaScritto.tasso }} {{ testoScrittoAMano(giaScritto) }}: registrando lo correggi.
                </p>
                <div class="space-y-1.5">
                    <Label for="fonte-legale" class="text-xs font-semibold text-slate-700 dark:text-slate-300">Fonte</Label>
                    <Input id="fonte-legale" v-model="modulo.fonte" class="h-9 w-full text-sm" data-testid="fonte-legale" />
                    <p class="text-[11px] leading-snug text-slate-500 dark:text-slate-400" data-testid="spiegazione-fonte">
                        Il decreto del Ministero dell'economia e la Gazzetta Ufficiale che lo pubblica, con le loro date.
                    </p>
                </div>
                <ul v-if="errori.length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori-tasso">
                    <li v-for="m in errori" :key="m">{{ m }}</li>
                </ul>
                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <span v-if="manca" class="text-xs text-slate-500 dark:text-slate-400" data-testid="cosa-manca-tasso">{{ manca }}</span>
                    <Button size="sm" :disabled="manca !== null || inCorso" data-testid="registra-tasso" @click="registra">Registra il tasso</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style>
/* Il menu dell'anno: alto come il campo del tasso accanto, con il testo della finestra, leggibile nel tema scuro; aperto sta sopra i campi sotto,
   dentro la finestra. Come i menu delle ricevute e dei filtri di «Rate scadute», all'altezza dei campi di questa finestra. */
.menu-tasso-legale .v-select {
    --vs-font-size: 0.875rem;
    --vs-line-height: 1.25;
    --vs-border-radius: 0.375rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
    --vs-disabled-bg: transparent;
    background: transparent;
}
.menu-tasso-legale .vs__dropdown-toggle {
    min-height: 2.25rem;
    padding: 0;
    align-items: center;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.menu-tasso-legale .vs__selected-options {
    align-items: center;
    padding-left: 8px;
}
.menu-tasso-legale .vs__selected,
.menu-tasso-legale .vs__search {
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}
.menu-tasso-legale .vs__search,
.menu-tasso-legale .vs__search::placeholder {
    font-size: 1em !important;
}
.menu-tasso-legale .vs__actions {
    padding: 0 8px 0 4px;
}
.menu-tasso-legale .vs__open-indicator {
    transform: scale(0.75);
}
.menu-tasso-legale .vs--open .vs__open-indicator {
    transform: rotate(180deg) scale(0.75);
}
.menu-tasso-legale .vs__dropdown-menu {
    font-size: 1em;
    max-height: 14rem;
}
.dark .menu-tasso-legale .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-color: rgb(226 232 240);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-controls-color: rgb(148 163 184);
    --vs-border-color: var(--input);
}
.dark .menu-tasso-legale .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
.dark .menu-tasso-legale .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
