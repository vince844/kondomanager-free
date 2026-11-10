<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import EsercizioDropdown from "@/components/EsercizioDropdown.vue";
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, FolderTree, Layers, BookOpen } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Gestione } from '@/types/gestionale/gestioni';
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  gestioni: Gestione[];
  pianoConti: PianoDeiConti;
  condomini?: Building[];
  esercizi?: Esercizio[];
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'Piani dei conti', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: props.pianoConti.nome, href: '#' },
  { title: 'Modifica', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Modifica piano',
    description: `Stai modificando il piano dei conti "${props.pianoConti.nome}" per l'esercizio "${props.esercizio.nome}". I capitoli e le voci di costo rimarranno associati.`,
    icon: FolderTree,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Gestione associata',
    description: 'Se modifichi la gestione associata, assicurati che la nuova gestione non abbia già un altro piano dei conti collegato.',
    icon: Layers,
    colorVariant: 'amber' as const,
  },
  {
    title: 'Voci e riparto',
    description: 'La modifica dei dati anagrafici del piano non altera le quote già ripartite o i movimenti contabili registrati.',
    icon: BookOpen,
    colorVariant: 'emerald' as const,
  },
]);

const form = useForm({
  nome: props.pianoConti.nome,
  descrizione: props.pianoConti.descrizione,
  note: props.pianoConti.note,
  gestione_id: props.pianoConti.gestione?.id ?? (props.pianoConti as any).gestione_id ?? '',
});

const submit = () => {
    form.put(route(...generateRoute('gestionale.esercizi.piani-conti.update', { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoConto: props.pianoConti.id })), {
        preserveScroll: true,
    });
};

</script>

<template>
    <Head title="Modifica piano dei conti" />

    <GestionaleLayout>
        <template #breadcrumb-condominio v-if="props.condomini">
            <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
        </template>

        <template #breadcrumb-esercizio v-if="props.esercizi">
            <EsercizioDropdown
                :condominio="props.condominio"
                :esercizio="props.esercizio"
                :esercizi="props.esercizi"
            />
        </template>

        <div class="px-6 py-8 space-y-6">

            <PageHeaderGuide
                :page-title="`Modifica piano dei conti — ${props.pianoConti.nome}`"
                page-subtitle="Aggiorna le informazioni del piano dei conti e la gestione a cui è associato nell'esercizio."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :back-url="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
                back-text="Torna all'elenco"
            />

            <form @submit.prevent="submit" class="space-y-6">

                <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                    <CardHeader class="pb-3 border-b border-dashed mb-4">
                        <CardTitle class="text-base font-semibold">Dati principali</CardTitle>
                        <CardDescription>Nome, gestione associata e descrizione del piano dei conti.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">

                        <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="nome">Nome piano dei conti</Label>
                                <Input
                                    id="nome"
                                    class="mt-1 block w-full bg-white dark:bg-slate-950"
                                    v-model="form.nome"
                                    @focus="form.clearErrors('nome')"
                                    placeholder="Es. Piano ordinario 2026"
                                />
                                <InputError :message="form.errors.nome" />
                            </div>

                            <div class="sm:col-span-3">
                                <Label for="gestione_id">Gestione</Label>
                                <v-select
                                    :options="props.gestioni"
                                    label="nome"
                                    class="mt-1 block w-full bg-white dark:bg-slate-950"
                                    v-model="form.gestione_id"
                                    placeholder="Seleziona una gestione"
                                    @update:modelValue="form.clearErrors('gestione_id')"
                                    :reduce="(g: Gestione) => g.id"
                                    :clearable="false"
                                />
                                <InputError :message="form.errors.gestione_id" />
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
                                    placeholder="Es. Piano dei conti preventivo per la gestione ordinaria"
                                />
                                <InputError :message="form.errors.descrizione" />
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
                        :href="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
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
