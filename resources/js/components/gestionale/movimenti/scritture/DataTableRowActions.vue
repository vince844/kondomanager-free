<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Eye } from 'lucide-vue-next'
import { usePermission } from '@/composables/permissions'
import type { ScritturaRow } from './columns'

const props = defineProps<{
  scrittura: ScritturaRow
  condominioId: number
  esercizioId: number
}>()

const { generateRoute } = usePermission()
</script>

<template>
  <Button variant="ghost" class="h-8 w-8 p-0" as-child>
    <Link
      :href="route(generateRoute('gestionale.scritture.show'), { condominio: props.condominioId, scrittura: props.scrittura.id })"
      title="Vedi dettaglio"
    >
      <span class="sr-only">Vedi dettaglio</span>
      <Eye class="h-4 w-4 text-slate-500" />
    </Link>
  </Button>
</template>
