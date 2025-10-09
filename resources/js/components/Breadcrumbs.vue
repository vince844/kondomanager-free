<script setup lang="ts">
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Link } from '@inertiajs/vue3';

interface BreadcrumbItem {
  title: string;
  href?: string;
  component?: string; 
}

defineProps<{
  breadcrumbs: BreadcrumbItem[];
}>();
</script>

<template>
  <Breadcrumb>
    <BreadcrumbList>
      <template v-for="(item, index) in breadcrumbs" :key="index">
        <BreadcrumbItem>
          <!-- Caso speciale: componente custom -->
          <template v-if="item.component === 'condominio-dropdown'">
            <slot name="breadcrumb-condominio" />
          </template>

          <template v-else-if="item.component === 'esercizio-dropdown'">
            <slot name="breadcrumb-esercizio" />
          </template>

          <!-- Ultimo elemento = pagina corrente -->
          <template v-else-if="index === breadcrumbs.length - 1">
            <BreadcrumbPage>{{ item.title }}</BreadcrumbPage>
          </template>

          <!-- Link normale -->
          <template v-else>
            <BreadcrumbLink>
              <Link :href="item.href ?? '#'">{{ item.title }}</Link>
            </BreadcrumbLink>
          </template>
        </BreadcrumbItem>

        <!-- Separatore -->
        <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
      </template>
    </BreadcrumbList>
  </Breadcrumb>
</template>
