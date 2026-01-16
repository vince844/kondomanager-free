<script setup lang="ts">

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {DropdownMenu,DropdownMenuContent,DropdownMenuItem,DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { ArrowDown, ChevronsUpDown, ArrowUp } from 'lucide-vue-next'
import type { Column } from '@tanstack/vue-table'
import type { Fornitore } from '@/types/fornitori';

interface DataTableColumnHeaderProps {
  column: Column<Fornitore, any> 
  title: string
}

defineProps<DataTableColumnHeaderProps>()
</script>

<script lang="ts">
  
export default {
  inheritAttrs: false,
}
</script>

<template>
  <div v-if="column.getCanSort()" :class="cn('flex items-center space-x-2', $attrs.class ?? '')">
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button
          variant="ghost"
          size="lg"
          class="-ml-3 h-8 data-[state=open]:bg-accent font-bold p-3"
        >
          <span>{{ title }}</span>
          <ArrowDown v-if="column.getIsSorted() === 'desc'" class="ml-2 h-4 w-4" />
          <ArrowUp v-else-if=" column.getIsSorted() === 'asc'" class="ml-2 h-4 w-4" />
          <ChevronsUpDown v-else class="ml-2 h-4 w-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        <DropdownMenuItem @click="column.toggleSorting(false)">
          <ArrowUp class="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
          Asc
        </DropdownMenuItem>
        <DropdownMenuItem @click="column.toggleSorting(true)">
          <ArrowDown class="mr-2 h-3.5 w-3.5 text-muted-foreground/70" />
          Desc
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>

  <div v-else :class="$attrs.class">
    {{ title }}
  </div>
</template>