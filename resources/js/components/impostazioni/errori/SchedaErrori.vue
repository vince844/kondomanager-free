<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<!--
  La scheda «Errori» della pagina «Registri» (1.12.0-beta.14, decisione 698 (2); bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scene
  7 e 9). Un errore per riga (gli uguali si contano insieme): lo stato in un badge con la scritta, il messaggio con il codice, dove è successo, quante volte,
  l'ultima volta, «Apri». Al telefono una scheda per errore. I filtri «Codice o testo», «Stato» (vSelect), «Dal», «Al»: chiedono solo l'elenco, senza
  ricaricare la pagina; con il codice intero chiedono anche il dettaglio, che si apre da solo. Un codice che non c'è dice perché e dove cercarlo.
-->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { debounce } from 'lodash';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Bug, Calendar as CalendarIcon, Search, SearchX, Send } from 'lucide-vue-next';
import { parseDate } from '@internationalized/date';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Calendar } from '@/components/ui/calendar';
import {
    RIGHE_PER_PAGINA, STATI, badgeDelloStato, codiceDaTesto, dataBreve, doveESuccesso, testoVolte, type FiltriErrori, type RigaErrore,
} from '@/lib/impostazioni/errori';

const props = defineProps<{
    errori: { data: RigaErrore[]; total: number; current_page: number; last_page: number; links: Array<{ url: string | null; label: string; active: boolean }> };
    filtri: FiltriErrori;
    codiceNonTrovato: string | null;
}>();
const emit = defineEmits<{ (e: 'apri', id: number): void; (e: 'mostra'): void }>();

const cerca = ref(props.filtri.cerca ?? '');
const stato = ref<FiltriErrori['stato']>(props.filtri.stato ?? 'tutti');
const dal = ref(props.filtri.dal ?? '');
const al = ref(props.filtri.al ?? '');
const perPage = ref(props.filtri.per_page ?? 10);
const apertoDal = ref(false);
const apertoAl = ref(false);

/** I filtri della scheda nell'indirizzo, senza i valori di serie. */
function parametri(): Record<string, string | number | undefined> {
    return {
        scheda: 'errori',
        cerca: cerca.value.trim() || undefined,
        stato_errori: stato.value !== 'tutti' ? stato.value : undefined,
        errori_dal: dal.value || undefined,
        errori_al: al.value || undefined,
        errori_per_page: perPage.value !== 10 ? perPage.value : undefined,
    };
}

/** Chiede solo l'elenco (e, con il codice intero, il dettaglio, che si apre): la pagina non si ricarica. */
function filtra() {
    const conIlCodice = codiceDaTesto(cerca.value) !== null;
    router.get(route('logs.index'), parametri(), {
        only: ['errori', 'erroriFiltri', 'codiceNonTrovato', ...(conIlCodice ? ['dettaglio', 'erroriNuovi'] : [])],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: () => {
            if (conIlCodice) emit('mostra');
        },
    });
}
const filtraDopo = debounce(filtra, 300);
watch(cerca, () => filtraDopo());

const haFiltri = computed(() => cerca.value.trim() !== '' || stato.value !== 'tutti' || dal.value !== '' || al.value !== '' || perPage.value !== 10);
function azzera() {
    cerca.value = '';
    stato.value = 'tutti';
    dal.value = '';
    al.value = '';
    perPage.value = 10;
    filtraDopo.cancel();
    filtra();
}

function giorno(iso: string) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return undefined;
    try {
        return parseDate(iso);
    } catch {
        return undefined;
    }
}
const breve = (iso: string) => (iso.split('-').length === 3 ? iso.split('-').reverse().join('/') : iso);
function scegliDal(v: unknown) {
    dal.value = v ? String(v) : '';
    apertoDal.value = false;
    filtra();
}
function scegliAl(v: unknown) {
    al.value = v ? String(v) : '';
    apertoAl.value = false;
    filtra();
}
</script>

<template>
    <div class="space-y-4" data-scheda-errori>
        <!-- I filtri -->
        <div class="grid grid-cols-1 items-end gap-2.5 border-t border-slate-100 pt-3 dark:border-slate-800 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))]">
            <div class="space-y-1">
                <label for="errori-cerca" class="text-xs font-medium text-muted-foreground">Codice o testo</label>
                <div class="relative w-full">
                    <Search class="absolute left-2.5 top-2 h-4 w-4 text-muted-foreground" />
                    <Input id="errori-cerca" v-model="cerca" data-testid="errori-cerca" placeholder="Per esempio K7M2QX" class="h-8 bg-white pl-8 text-xs dark:bg-gray-950" />
                </div>
            </div>
            <div class="menu-campo space-y-1 text-xs">
                <label for="errori-stato" class="text-xs font-medium text-muted-foreground">Stato</label>
                <v-select input-id="errori-stato" :options="STATI" label="etichetta" :reduce="(o: { valore: string }) => o.valore" :searchable="false"
                    :clearable="false" :model-value="stato" class="w-full bg-white dark:bg-slate-950" data-testid="errori-stato"
                    @update:model-value="(v: FiltriErrori['stato']) => { stato = v; filtra(); }" />
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-muted-foreground">Dal</label>
                <Popover v-model:open="apertoDal">
                    <PopoverTrigger as-child>
                        <button type="button" class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-start text-xs tabular-nums"
                            :class="!dal && 'text-muted-foreground'">
                            <span class="truncate">{{ dal ? breve(dal) : 'gg/mm/aaaa' }}</span>
                            <CalendarIcon class="ml-1.5 h-3.5 w-3.5 shrink-0 opacity-50" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="start">
                        <Calendar :model-value="giorno(dal)" locale="it-IT" initial-focus @update:model-value="scegliDal" />
                    </PopoverContent>
                </Popover>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-muted-foreground">Al</label>
                <Popover v-model:open="apertoAl">
                    <PopoverTrigger as-child>
                        <button type="button" class="flex h-8 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1 text-start text-xs tabular-nums"
                            :class="!al && 'text-muted-foreground'">
                            <span class="truncate">{{ al ? breve(al) : 'gg/mm/aaaa' }}</span>
                            <CalendarIcon class="ml-1.5 h-3.5 w-3.5 shrink-0 opacity-50" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="start">
                        <Calendar :model-value="giorno(al)" locale="it-IT" initial-focus @update:model-value="scegliAl" />
                    </PopoverContent>
                </Popover>
            </div>
        </div>
        <div v-if="haFiltri" class="flex justify-end">
            <button type="button" class="text-xs font-medium text-slate-500 underline-offset-2 hover:underline" data-testid="errori-azzera" @click="azzera">Azzera i filtri</button>
        </div>

        <!-- Il codice che non c'è: perché, e dove cercarlo -->
        <div v-if="codiceNonTrovato" data-testid="codice-non-trovato"
            class="flex flex-col items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-8 text-center dark:border-slate-800 dark:bg-slate-950">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-900">
                <SearchX class="h-5 w-5" />
            </span>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Nessun errore con il codice {{ codiceNonTrovato }}</p>
            <p class="max-w-lg text-sm text-slate-500 dark:text-slate-400">
                Un codice manca da qui se l'errore è di più di 90 giorni fa, o se è successo con il database fermo. Allora è solo nel registro del server, il
                file storage/logs/laravel.log: cerca {{ codiceNonTrovato }}.
            </p>
            <button type="button" class="mt-1 inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium shadow-sm dark:border-slate-700 dark:bg-slate-900" @click="azzera">
                Azzera la ricerca
            </button>
        </div>

        <!-- L'elenco: la tabella dal computer, una scheda per errore al telefono -->
        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
            <div class="hidden h-10 grid-cols-[7rem_minmax(0,1fr)_minmax(0,20rem)_4rem_7.5rem_5rem] items-center gap-3.5 border-b border-slate-200 bg-slate-50 px-4 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400 md:grid">
                <span>Stato</span><span>Errore</span><span>Pagina o lavoro</span><span class="text-right">Volte</span><span class="text-right">Ultima volta</span><span></span>
            </div>
            <div v-for="r in errori.data" :key="r.id" data-testid="riga-errore"
                class="grid grid-cols-1 gap-1.5 border-b border-slate-100 px-4 py-3 last:border-b-0 dark:border-slate-800 md:grid-cols-[7rem_minmax(0,1fr)_minmax(0,20rem)_4rem_7.5rem_5rem] md:items-center md:gap-3.5"
                :class="r.stato === 'avvisato' ? 'bg-amber-50/60 dark:bg-amber-950/20' : ''">
                <div class="flex items-center justify-between gap-2 md:block">
                    <span data-testid="stato-errore" class="inline-flex items-center gap-1 rounded-md border px-1.5 py-px text-xs font-medium" :class="badgeDelloStato(r.stato).classi">
                        <Send v-if="r.stato === 'avvisato'" class="h-3 w-3" />{{ badgeDelloStato(r.stato).testo }}
                    </span>
                    <span class="text-xs tabular-nums text-slate-500 md:hidden">{{ dataBreve(r.ultima_volta_il) }}</span>
                </div>
                <div class="min-w-0">
                    <p class="line-clamp-2 break-words text-sm font-medium text-slate-900 dark:text-white md:truncate md:line-clamp-none" :title="r.messaggio">{{ r.messaggio }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                        <span class="font-semibold tracking-wider text-slate-800 dark:text-slate-200">{{ r.codice }}</span> · {{ r.classe }}<span class="md:hidden"> · {{ testoVolte(r.volte) }}</span><template v-if="r.avvisato_da"> · ti ha avvisato {{ r.avvisato_da }}</template>
                    </p>
                </div>
                <p class="hidden min-w-0 text-xs text-slate-600 dark:text-slate-300 md:block md:line-clamp-2 [overflow-wrap:anywhere]" :title="doveESuccesso(r).testo">
                    <span v-if="doveESuccesso(r).metodo" class="mr-1 inline-flex h-[18px] items-center rounded border border-slate-200 px-1 text-xs font-semibold text-slate-500 dark:border-slate-700">{{ doveESuccesso(r).metodo }}</span>{{ doveESuccesso(r).testo }}
                </p>
                <span class="hidden text-right text-sm tabular-nums md:block">{{ r.volte }}</span>
                <span class="hidden text-right text-sm tabular-nums md:block">{{ dataBreve(r.ultima_volta_il) }}</span>
                <div class="md:text-right">
                    <button type="button" data-testid="apri-errore" class="inline-flex h-8 w-full items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 md:w-16"
                        @click="emit('apri', r.id)">Apri</button>
                </div>
            </div>
            <div v-if="errori.data.length === 0" class="flex flex-col items-center gap-2 px-4 py-10 text-center text-sm text-muted-foreground" data-testid="nessun-errore">
                <Bug class="h-8 w-8 opacity-20" />
                <span>{{ haFiltri ? 'Nessun errore con questi filtri.' : 'Nessun errore registrato: il programma non ne ha incontrati negli ultimi 90 giorni.' }}</span>
            </div>
        </div>

        <!-- Quanti, le righe per pagina, la paginazione -->
        <div v-if="!codiceNonTrovato" class="flex flex-col items-center justify-between gap-3 text-xs text-muted-foreground sm:flex-row">
            <div class="flex items-center gap-3">
                <span class="tabular-nums">{{ errori.total === 1 ? '1 errore' : `${errori.total} errori` }}</span>
                <div class="menu-campo menu-campo-piccolo flex items-center gap-1.5">
                    <span>Righe:</span>
                    <v-select :options="RIGHE_PER_PAGINA" label="etichetta" :reduce="(o: { valore: number }) => o.valore" :searchable="false" :clearable="false"
                        :model-value="perPage" class="w-20 bg-white dark:bg-slate-950" @update:model-value="(v: number) => { perPage = v; filtra(); }" />
                </div>
            </div>
            <div v-if="errori.links && errori.links.length > 3" class="flex gap-1">
                <component :is="link.url ? Link : 'button'" v-for="(link, i) in errori.links" :key="i" :href="link.url ?? undefined"
                    :only="['errori', 'erroriFiltri']" preserve-state preserve-scroll :disabled="!link.url"
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-xs tabular-nums"
                    :class="link.active ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'">
                    {{ i === 0 ? '‹' : i === errori.links.length - 1 ? '›' : link.label }}
                </component>
            </div>
        </div>
    </div>
</template>
