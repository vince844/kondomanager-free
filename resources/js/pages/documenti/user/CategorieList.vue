<script setup lang="ts">

import {computed } from 'vue';
import { Head, usePage } from "@inertiajs/vue3";
import AppLayout from "@/layouts/AppLayout.vue";
import Heading from "@/components/Heading.vue";
import Alert from "@/components/Alert.vue";
import CategorieDocumentiCards from '@/components/documenti/CategorieDocumentiCards.vue';
import { Card, CardContent, CardHeader, CardDescription, CardTitle } from '@/components/ui/card';
import DocumentiList from '@/components/documenti/DocumentiList.vue';
import { trans } from 'laravel-vue-i18n';
import type { Categoria } from '@/types/categorie';
import type { Documento } from '@/types/documenti';
import type { Flash } from '@/types/flash';
import type { Auth } from '@/types';

defineProps<{ 
  categorie: Categoria[], 
  documenti: Documento[]
}>()

const page = usePage<{ flash: { message?: Flash }; auth: Auth }>();
const flashMessage = computed(() => page.props.flash.message);

</script>

<template>
  <Head :title="trans('documenti.header.list_categories_head')" />

  <AppLayout>
    <div class="px-4 py-6">
      <!-- Page Heading -->
      <Heading
        :title="trans('documenti.header.list_categories_title')"
        :description="trans('documenti.header.list_categories_description')"
      />

      <div v-if="flashMessage" class="py-4">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <!-- Container -->
      <div class="container mx-auto mt-6">
        <div class="flex flex-col lg:flex-row gap-4 w-full">
          <!-- Left Widget -->
          <Card class="w-full lg:w-2/3 border border-muted rounded-lg shadow-sm">
            <CardContent class="p-3">
              <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <CategorieDocumentiCards
                  v-for="categoria in categorie"
                  :key="categoria.id"
                  :categoria="categoria"
                />
              </div>
            </CardContent>
          </Card>

          <!-- Right Widget -->
          <Card class="w-full lg:w-1/3 border border-muted rounded-lg shadow-sm">
            <CardHeader class="p-3 ml-3">
              <CardTitle class="text-base font-semibold">{{ trans('documenti.user.latest_documents_title') }}</CardTitle>
              <CardDescription>
                {{ trans('documenti.user.latest_documents_description') }}
              </CardDescription>
            </CardHeader>
            <CardContent>

              <DocumentiList
                :documenti="documenti" 
              />
              
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
