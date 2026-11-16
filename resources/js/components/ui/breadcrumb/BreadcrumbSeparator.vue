<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { ChevronRight } from "lucide-vue-next"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <li
    role="presentation"
    aria-hidden="true"
    :class="cn('[&>svg]:w-3.5 [&>svg]:h-3.5', props.class)"
  >
    <slot>
      <ChevronRight />
    </slot>
  </li>
</template>
