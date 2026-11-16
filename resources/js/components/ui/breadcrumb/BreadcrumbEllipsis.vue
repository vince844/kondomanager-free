<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { MoreHorizontal } from "lucide-vue-next"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <span
    role="presentation"
    aria-hidden="true"
    :class="cn('flex h-9 w-9 items-center justify-center', props.class)"
  >
    <slot>
      <MoreHorizontal class="h-4 w-4" />
    </slot>
    <span class="sr-only">More</span>
  </span>
</template>
