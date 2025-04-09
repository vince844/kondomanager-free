<script setup lang="ts">
import type { Column } from '@tanstack/vue-table'
import type { Component } from 'vue'
import type { Segnalazione } from '@/types/segnalazioni';
import { cn } from '@/lib/utils'
import Badge from '@/components/ui/badge/Badge.vue';
import Button from '@/components/ui/button/Button.vue';
import { 
  Command, 
  CommandEmpty, 
  CommandGroup, 
  CommandInput, 
  CommandItem, 
  CommandList, 
  CommandSeparator 
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Separator } from '@/components/ui/separator'
import { computed } from 'vue'
import { Check, PlusCircle } from 'lucide-vue-next'

interface DataTableFacetedFilterProps {
  column?: Column<Segnalazione, unknown>
  title?: string
  options: {
    label: string
    value: string
    icon?: Component
  }[]
}

const props = defineProps<DataTableFacetedFilterProps>()

const facets = computed(() => {
  if (!props.column) return new Map()
  return props.column.getFacetedUniqueValues()
})

const selectedValues = computed(() => {
  const filterValue = props.column?.getFilterValue()
  return new Set(Array.isArray(filterValue) ? filterValue : [])
})
</script>

<template>
  <Popover>
    <PopoverTrigger as-child>
      <Button variant="outline" size="sm" class="h-8 border-dashed">
        <PlusCircle class="mr-2 h-4 w-4" />
        {{ title }}
        <template v-if="selectedValues.size > 0">
          <Separator orientation="vertical" class="mx-2 h-4" />
          <Badge variant="secondary" class="rounded-sm px-1 font-normal lg:hidden">
            {{ selectedValues.size }}
          </Badge>
          <div class="hidden space-x-1 lg:flex">
            <Badge
              v-if="selectedValues.size > 2"
              variant="secondary"
              class="rounded-sm px-1 font-normal"
            >
              {{ selectedValues.size }} selezionati
            </Badge>
            <template v-else>
              <Badge
                v-for="option in options.filter((option) => selectedValues.has(option.value))"
                :key="option.value"
                variant="secondary"
                class="rounded-sm px-1 font-normal"
              >
                {{ option.label }}
              </Badge>
            </template>
          </div>
        </template>
      </Button>
    </PopoverTrigger>
    <PopoverContent class="w-[250px] p-0" align="start">
      <Command>
        <CommandInput :placeholder="title" />
        <CommandList>
          <CommandEmpty>Nessun risultato trovato</CommandEmpty>
          <CommandGroup>
            <CommandItem
              v-for="option in options"
              :key="option.value"
              :value="option"
              @select="() => {
                const newSelectedValues = new Set(selectedValues)
                if (newSelectedValues.has(option.value)) {
                  newSelectedValues.delete(option.value)
                } else {
                  newSelectedValues.add(option.value)
                }
                props.column?.setFilterValue(
                  newSelectedValues.size ? Array.from(newSelectedValues) : undefined
                )
              }"
            >
              <div
                :class="cn(
                  'mr-2 flex h-4 w-4 items-center justify-center rounded-sm border border-primary',
                  selectedValues.has(option.value)
                    ? 'bg-primary text-primary-foreground'
                    : 'opacity-50 [&_svg]:invisible',
                )"
              >
                <Check class="h-4 w-4" />
              </div>
              <component
                :is="option.icon"
                v-if="option.icon"
                class="mr-2 h-4 w-4 text-muted-foreground"
              />
              <span>{{ option.label }}</span>
              <span
                v-if="facets?.get(option.value)"
                class="ml-auto flex h-4 w-4 items-center justify-center font-mono text-xs"
              >
                {{ facets.get(option.value) }}
              </span>
            </CommandItem>
          </CommandGroup>
          <template v-if="selectedValues.size > 0">
            <CommandSeparator />
            <CommandGroup>
              <CommandItem
                :value="{ label: 'Resetta filtri' }"
                class="justify-center text-center"
                @select="props.column?.setFilterValue(undefined)"
              >
                Resetta filtri
              </CommandItem>
            </CommandGroup>
          </template>
        </CommandList>
      </Command>
    </PopoverContent>
  </Popover>
</template>