<script setup lang="ts" generic="TData">
/**
 * I filtri dello scadenzario F24.
 *
 * Stessa meccanica della toolbar dei pagamenti — ricerca con debounce, select, reset — e
 * stessi parametri in query string, così un filtro si può mettere nei preferiti o mandare a
 * qualcuno per link.
 *
 * Le tre dimensioni sono quelle su cui si cerca davvero: **stato** («cosa mi resta da
 * versare»), **tipo di plafond** (appalti contro professionisti, che sono due adempimenti
 * distinti con soglie diverse), e **codice tributo**, che è il campo con cui l'amministratore
 * ragiona quando ha l'F24 davanti.
 */
import { computed, ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Search, X } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

defineProps<{ table: Table<TData> }>();

const page = usePage<{ condominio: Building; filters: any }>();
const { generateRoute } = usePermission();
const condominioId = computed(() => page.props.condominio.id);

const ricerca = ref(page.props.filters?.search || '');
const stato = ref(page.props.filters?.stato || 'all');
const plafond = ref(page.props.filters?.plafond || 'all');

const statiOpzioni = [
    // In cima le due domande vere, prima degli stati tecnici: «cosa è in ritardo» e
    // «cosa mi resta». Il ritardo per primo, perché è l'unico che costa sanzioni.
    { value: 'scadute', label: 'Solo scadute' },
    { value: 'da_versare', label: 'Da versare' },
    { value: 'bozza', label: 'Bozza' },
    { value: 'confermata', label: 'Confermata' },
    { value: 'versata', label: 'Versata' },
    { value: 'stornata', label: 'Stornata' },
    { value: 'annullata', label: 'Annullata' },
];

const plafondOpzioni = [
    { value: 'soglia_500_tre_finestre', label: 'Appalti 4%' },
    { value: 'soglia_100_annuale', label: 'Professionisti' },
    { value: 'mensile_sempre', label: 'Mensile' },
];

const filtriAttivi = computed(() =>
    !!ricerca.value || stato.value !== 'all' || plafond.value !== 'all'
);

const parametri = computed(() => {
    const p: Record<string, any> = { page: 1 };

    if (ricerca.value) p.search = ricerca.value;
    if (stato.value !== 'all') p.stato = stato.value;
    if (plafond.value !== 'all') p.plafond = plafond.value;

    return p;
});

watchDebounced(
    [ricerca, stato, plafond],
    () => {
        router.get(
            route(generateRoute('gestionale.f24.index'), { condominio: condominioId.value }),
            parametri.value,
            { preserveState: true, replace: true, preserveScroll: true },
        );
    },
    { debounce: 350 },
);

const azzera = () => {
    ricerca.value = '';
    stato.value = 'all';
    plafond.value = 'all';
};
</script>

<template>
    <div class="flex flex-1 flex-wrap items-center gap-2">
        <div class="relative">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <Search class="h-4 w-4" />
            </div>
            <Input
                v-model="ricerca"
                placeholder="Cerca codice tributo o note..."
                class="h-8 w-[200px] pl-9 lg:w-[250px]"
            />
        </div>

        <Select v-model="stato">
            <SelectTrigger class="h-8 w-40 bg-white lg:w-48">
                <SelectValue placeholder="Stato" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="all">Tutti gli stati</SelectItem>
                    <SelectItem v-for="s in statiOpzioni" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>

        <Select v-model="plafond">
            <SelectTrigger class="h-8 w-40 bg-white lg:w-48">
                <SelectValue placeholder="Tipo" />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectItem value="all">Tutti i tipi</SelectItem>
                    <SelectItem v-for="p in plafondOpzioni" :key="p.value" :value="p.value">
                        {{ p.label }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>

        <Button v-if="filtriAttivi" variant="ghost" class="h-8 px-2 text-slate-500 hover:text-slate-700 lg:px-3" @click="azzera">
            Azzera <X class="ml-2 h-4 w-4" />
        </Button>

        <!-- Le azioni della pagina stanno sulla stessa riga dei filtri: sopra c'era un
             titolo di sezione che ripeteva quello della pagina, e il pulsante da solo su
             una riga sua. -->
        <div class="ml-auto flex items-center gap-2">
            <slot name="azioni" />
        </div>
    </div>
</template>
