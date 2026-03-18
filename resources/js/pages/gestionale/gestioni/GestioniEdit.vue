<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, FolderTree, Layers, CalendarRange } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { useDateConverter } from '@/composables/useDateConverter';
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { DropdownType } from '@/types/dropdown';
import type { Esercizio } from '@/types/gestionale/esercizi';
import type { Gestione } from '@/types/gestionale/gestioni';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  gestione: Gestione;
}>()

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'Gestioni', href: generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: props.gestione.nome, href: '#' },
  { title: 'Modifica', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Modifica gestione',
    description: `Stai modificando la gestione "${props.gestione.nome}" dell'esercizio "${props.esercizio.nome}". Attenzione: modificare le date di competenza potrebbe influire sui controlli dei movimenti associati.`,
    icon: FolderTree,
    colorVariant: 'blue' as const
  },
  {
    title: 'Tipologia',
    description: 'La gestione Ordinaria copre le spese ricorrenti annuali (pulizie, luce scale, amministrazione). La Straordinaria è usata per interventi una tantum come ristrutturazioni o lavori straordinari.',
    icon: Layers,
    colorVariant: 'amber' as const
  },
  {
    title: 'Date di competenza',
    description: 'Le date definiscono il periodo contabile della gestione. Di norma coincidono con quelle dell\'esercizio, ma per gestioni straordinarie puoi impostare un intervallo diverso legato alla durata dei lavori.',
    icon: CalendarRange,
    colorVariant: 'emerald' as const
  },
]);

const tipologie = [
  { label: 'Ordinaria',     id: 'ordinaria' },
  { label: 'Straordinaria', id: 'straordinaria' },
];

const form = useForm({
  nome: props.gestione.nome,
  descrizione: props.gestione.descrizione,
  note: props.gestione.note,
  data_inizio: props.gestione.data_inizio,
  data_fine: props.gestione.data_fine,
  tipo: props.gestione.tipo,
});

const submit = () => {
    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);

    form.put(route(...generateRoute('gestionale.esercizi.gestioni.update', { condominio: props.condominio.id, esercizio: props.esercizio.id, gestione: props.gestione.id })), {
        preserveScroll: true,
        // In fase di modifica è preferibile non resettare il form onSuccess, 
        // così l'utente vede i dati aggiornati ricaricarsi o rimanere a schermo.
    });
};
</script>

<template>
    <Head title="Modifica gestione" />

    <GestionaleLayout>
        <template #breadcrumb-condominio>
            <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
        </template>

        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                :page-title="`Modifica gestione — ${props.gestione.nome}`"
                page-subtitle="Aggiorna i dettagli della gestione contabile esistente. Modifica il periodo o la tipologia per adattarla all'esercizio in corso."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :back-url="generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
                back-text="Torna all'elenco"
            />

            <form @submit.prevent="submit" class="space-y-6">

                <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                    <CardHeader class="pb-3 border-b border-dashed mb-4">
                        <CardTitle class="text-base font-semibold">Dati principali</CardTitle>
                        <CardDescription>Nome, tipologia e periodo di competenza della gestione.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">

                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="nome">Nome gestione</Label>
                                <Input
                                    id="nome"
                                    class="mt-1 block w-full bg-white dark:bg-slate-950"
                                    v-model="form.nome"
                                    @focus="form.clearErrors('nome')"
                                    placeholder="Es. Gestione ordinaria 2026"
                                />
                                <InputError :message="form.errors.nome" />
                            </div>

                            <div class="sm:col-span-3">
                                <Label for="tipo">Tipologia</Label>
                                <v-select
                                    :options="tipologie"
                                    label="label"
                                    class="mt-1 block w-full bg-white dark:bg-slate-950"
                                    v-model="form.tipo"
                                    placeholder="Seleziona tipologia"
                                    @update:modelValue="form.clearErrors('tipo')"
                                    :reduce="(d: DropdownType) => d.id"
                                    :clearable="false"
                                />
                                <InputError :message="form.errors.tipo" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="descrizione">Descrizione</Label>
                                <Input
                                    id="descrizione"
                                    class="mt-1 block w-full bg-white dark:bg-slate-950"
                                    v-model="form.descrizione"
                                    @focus="form.clearErrors('descrizione')"
                                    placeholder="Descrizione opzionale"
                                />
                                <InputError :message="form.errors.descrizione" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="data_inizio">Data inizio</Label>
                                <VueDatePicker
                                    v-model="form.data_inizio"
                                    class="w-full mt-1"
                                    format="dd/MM/yyyy"
                                    locale="it"
                                    :enable-time-picker="false"
                                    auto-apply
                                    @update:modelValue="form.clearErrors('data_inizio')"
                                    placeholder="Seleziona data"
                                />
                                <InputError :message="form.errors.data_inizio" />
                            </div>

                            <div class="sm:col-span-3">
                                <Label for="data_fine">Data fine</Label>
                                <VueDatePicker
                                    v-model="form.data_fine"
                                    class="w-full mt-1"
                                    format="dd/MM/yyyy"
                                    locale="it"
                                    :enable-time-picker="false"
                                    auto-apply
                                    @update:modelValue="form.clearErrors('data_fine')"
                                    placeholder="Seleziona data"
                                />
                                <InputError :message="form.errors.data_fine" />
                            </div>
                        </div>

                    </CardContent>
                </Card>

                <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                    <CardHeader class="pb-3 border-b border-dashed mb-4">
                        <CardTitle class="text-base font-semibold">Note interne</CardTitle>
                        <CardDescription>Annotazioni visibili solo agli amministratori.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="note">Note</Label>
                                <Textarea
                                    id="note"
                                    placeholder="Inserisci una nota qui..."
                                    v-model="form.note"
                                    @focus="form.clearErrors('note')"
                                    class="mt-1 bg-white dark:bg-slate-950 resize-none"
                                    rows="3"
                                />
                                <InputError :message="form.errors.note" />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex items-center justify-end gap-3">
                    <Link
                        :href="generatePath('gestionale/:condominio/esercizi/:esercizio/gestioni', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
                        class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                    >
                        Annulla
                    </Link>

                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
                    >
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        <Save v-else class="h-3.5 w-3.5" />
                        Salva modifiche
                    </Button>
                </div>

            </form>
        </div>
    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>