<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { DropdownMenuLabelProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { DropdownMenuLabel, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<DropdownMenuLabelProps & { class?: HTMLAttributes["class"], inset?: boolean }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <DropdownMenuLabel
    v-bind="forwardedProps"
    :class="cn('px-2 py-1.5 text-sm font-semibold', inset && 'pl-8', props.class)"
  >
    <slot />
  </DropdownMenuLabel>
</template>
