<script setup lang="ts">

import { ChevronDown } from "lucide-vue-next";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { useCondominioDropdown } from "@/composables/useCondominioDropdown";
import type { Building } from "@/types/buildings";

const props = defineProps<{
  condominio: Building;
  condomini: (Building & { esercizio_aperto?: { id: number } | null })[];
}>();

const { selectCondominio } = useCondominioDropdown();
</script>

<template>
  <DropdownMenu>
    <DropdownMenuTrigger class="flex items-center gap-1 font-medium">
      {{ props.condominio.nome }}
      <ChevronDown class="h-4 w-4 mt-1" />
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start">
      <DropdownMenuItem
        v-for="c in props.condomini"
        :key="c.id"
        @click="selectCondominio(c.id)"
        class="cursor-pointer"
      >
        {{ c.nome }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
