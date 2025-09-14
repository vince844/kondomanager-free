<script setup lang="ts">

import { ref, computed } from "vue";
import { Head, useForm, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import Heading from '@/components/Heading.vue';
import { Separator } from "@/components/ui/separator";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { List, Plus, LoaderCircle} from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { BreadcrumbItem } from "@/types";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { Millesimo } from "@/types/gestionale/millesimi";

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  millesimi: Millesimo[];
}>()

const form = useForm({
  quote: props.millesimi.map((q) => ({
    id: q.id,
    immobile: q.immobile ?? {}, 
    valore: q.valore ?? "",
  })),
});

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'tabelle', href: '#' },
]);

const submit = () => {
  form.put(
    route("gestionale.tabelle.quote.update", {
      condominio: props.condominio.id,
      tabella: props.tabella.id,
    }),
    { preserveScroll: true }
  );
};

</script>

<template>
  
  <Head title="Quote tabella" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    
    <div class="px-4 py-6">
        <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
            <section class="w-full">

                <Heading 
                    :title="`Associa immobli alla tabella - ${props.tabella.nome}`" 
                    description="Di seguito puoi specificare i millesimi per ogni immobile associato alla tabella"
                />

                <div class="flex flex-wrap flex-col lg:flex-row lg:justify-end gap-2 items-start lg:items-center mb-4">
                    
                    <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                        <Plus class="w-4 h-4" v-if="!form.processing" />
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Salva
                    </Button>

                    <Link 
                        :href="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })" 
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
                    >
                        <List class="w-4 h-4" />
                        <span>Tabelle</span>
                    </Link>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Table -->
                    <div class="overflow-x-auto">

                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <Table>
                                <TableHeader>
                                <TableRow>
                                    <TableHead class="px-4 py-2 text-left text-sm font-medium text-gray-600">
                                    Immobile
                                    </TableHead>
                                    <TableHead class="px-4 py-2 text-left text-sm font-medium text-gray-600">
                                    Valore (millesimi)
                                    </TableHead>
                                </TableRow>
                                </TableHeader>

                                <TableBody>
                                <TableRow
                                    v-for="(q, idx) in form.quote"
                                    :key="q.id ?? idx"
                                    class="border-t"
                                >
                                    <TableCell class="px-4 py-2 text-sm text-gray-800">
                                        <div class="font-medium">
                                            {{ q.immobile.nome }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Interno: {{ q.immobile.interno ?? "—" }} | 
                                            Piano: {{ q.immobile?.piano ?? "—" }} | 
                                            Sup: {{ q.immobile?.superficie ?? "—" }} m²
                                        </div>
                                    </TableCell>
                                    <TableCell class="px-4 py-2">
                                    <Input
                                        v-model="q.valore"
                                        class="w-32"
                                    />
                                    </TableCell>
                                </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                    </div>

                </form>

            </section>
        </div>
    </div>

  </GestionaleLayout>
</template>
