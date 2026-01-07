<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import CondominioDropdown from "@/components/CondominioDropdown.vue";
import DataTable from '@/components/gestionale/movimenti/incassi/DataTable.vue'; 
import { createColumns } from '@/components/gestionale/movimenti/incassi/columns';
import { usePermission } from "@/composables/permissions";

const props = defineProps<{
  condominio: any;
  condomini: any[]; // Per il dropdown
  movimenti: { data: any[], meta: any }; // Dati paginati
  filters: any;
}>();

const { generatePath } = usePermission();

const breadcrumbs = [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: props.condominio.nome, component: "condominio-dropdown" } as any,
    { title: 'Movimenti', href: '#' },
    { title: 'Incassi Rate', href: '#' }
];
</script>

<template>
    <Head title="Elenco Incassi" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">
        <template #breadcrumb-condominio>
            <CondominioDropdown :condominio="condominio" :condomini="condomini" />
        </template>

        <MovimentiLayout>
            <div class="p-0"> 
                <DataTable 
                    :columns="createColumns(condominio.id)"
                    :data="movimenti.data"
                    :meta="movimenti.meta || movimenti" 
                    :condominio="condominio"
                />
            </div>
        </MovimentiLayout>
    </GestionaleLayout>
</template>