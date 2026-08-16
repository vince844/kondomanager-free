<script setup lang="ts">

import { ref, computed } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { router, usePage, Link } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Plus } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { Table } from '@tanstack/vue-table';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Building } from '@/types/buildings';

// Props
const props = defineProps<{ table: Table<Immobile> }>();

// Page props
const page = usePage<{ condominio: Building }>();

// Permissions / routes
const { generateRoute } = usePermission();

// Filters
const nameFilter = ref('')

/**
 * Il filtro sulle pertinenze.
 *
 * ⚠️ **La voce che giustifica tutto il selettore è «da collegare».** Su un condominio con
 * sessantasette unità le pertinenze senza legame sono una manciata sparsa in cinque pagine, e
 * cercarle scorrendo l'elenco a caccia dei corsivi non è un lavoro che qualcuno farà. Con il
 * filtro diventa un elenco corto e finito: si apre, si collega, si chiude.
 *
 * È anche il motivo per cui il programma **non insegue** l'amministratore con avvisi su ogni riga
 * incompleta: la bonifica si fa quando si ha voglia, e lo strumento sta lì ad aspettare.
 *
 * Parte dal valore che il server ha già applicato — se si arriva con l'indirizzo filtrato, il
 * selettore lo mostra invece di dire «tutte» mentre l'elenco è filtrato.
 */
const page2 = usePage<{ filters?: { pertinenze?: string } }>()
const pertinenzeFilter = ref(page2.props.filters?.pertinenze ?? 'tutte')

// Computed params for router
const filterParams = computed(() => {
  const params: Record<string, any> = { page: 1 }
  if (nameFilter.value) params.nome = nameFilter.value
  if (pertinenzeFilter.value !== 'tutte') params.pertinenze = pertinenzeFilter.value
  return params
})

// Watch filters with debounce
watchDebounced(
  [nameFilter, pertinenzeFilter],
  () => {
    router.get(
      route(generateRoute('gestionale.immobili.index'), { condominio: page.props.condominio.id }),
      filterParams.value,
      {
        preserveState: true,
        replace: true,
        preserveScroll: true,
        onSuccess: () => {
          if (!nameFilter.value) props.table.reset()
        }
      }
    )
  },
  { debounce: 300 }
)
</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          placeholder="Filtra per nome..."
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />

        <!--
          Stessa altezza della casella accanto (`h-8`): sono due filtri della stessa barra e vanno
          letti come una coppia. Il segnaposto «Pertinenze» dice cosa filtra quando è inattivo.
        -->
        <Select v-model="pertinenzeFilter">
          <SelectTrigger class="h-8 w-[190px] text-xs">
            <SelectValue placeholder="Pertinenze" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="tutte" class="text-xs">Tutte le unità</SelectItem>
            <SelectItem value="principali" class="text-xs">Solo unità principali</SelectItem>
            <SelectItem value="collegate" class="text-xs">Solo pertinenze collegate</SelectItem>
            <SelectItem value="da_collegare" class="text-xs">Pertinenze da collegare</SelectItem>
          </SelectContent>
        </Select>

      </div>
    </div>

    <Link
      :href="route(generateRoute('gestionale.immobili.create'), { condominio: page.props.condominio.id })"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
      prefetch
    >
      <Plus class="w-3.5 h-3.5" />
      <span>Nuovo immobile</span>
    </Link>
    

  </div>
</template>
