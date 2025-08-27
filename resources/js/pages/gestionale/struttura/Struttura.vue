<script setup lang="ts">

import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { usePermission } from "@/composables/permissions";
import TreeCondominio from '@/components/TreeCondominio.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent } from '@/components/ui/card';
import type { Palazzina } from '@/types/gestionale/palazzine';
import Alert from '@/components/Alert.vue';

// Types
interface Condominio {
  id: number;
  nome: string;
  palazzine?: Palazzina[];
}

// Get Inertia page props
const page = usePage<{
  condominio: Condominio;
  palazzine: Palazzina[];
  meta: any;
  flash?: { message?: string; type?: string };
}>();

const { generatePath } = usePermission();

// Condominio data
const condominio = computed<Condominio>(() => page.props.condominio);

// Flash messages
const flashMessage = computed(() => page.props.flash?.message);

// Breadcrumbs
const breadcrumbs = computed(() => [
  { title: 'Gestionale', href:generatePath('gestionale/:condominio', { condominio: condominio.value.id }) },
  { title: condominio.value?.nome, href: '#' },
  { title: 'struttura', href: '#' },
]);
</script>


<template>
  <Head title="Dashboard gestionale" />

  <GestionaleLayout :breadcrumbs="breadcrumbs">

    <StrutturaLayout>
        pagina struttura

    </StrutturaLayout>
   
  </GestionaleLayout>
</template>
