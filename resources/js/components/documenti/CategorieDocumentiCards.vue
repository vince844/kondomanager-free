<script setup lang="ts">

import { Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { Categoria } from '@/types/categorie';
import { usePermission } from "@/composables/permissions";

const { generateRoute } = usePermission();

defineProps<{
  categoria: Categoria;
}>();

</script>

<template>
  <Card>
    <CardHeader class="flex flex-row items-center justify-between p-2">
      <CardTitle class="text-xl font-bold capitalize">

        <Link
          :href="route(generateRoute('categorie-documenti.show'), {id: categoria.id})"
          class="text-xl font-bold text-primary"
        >
          {{ categoria.name }}
        </Link>

      </CardTitle>
      <Badge
        variant="outline"
        class="w-6 h-6 rounded-full flex items-center justify-center text-xs p-0"
      >
        {{ categoria.documenti_count }}
      </Badge>
    </CardHeader>
    <CardContent class="p-2">
      <div class="text-sm text-muted-foreground">
        {{ categoria.description || 'Nessuna descrizione' }}
      </div>
    </CardContent>
  </Card>
</template>
