<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { TooltipRootEmits, TooltipRootProps } from "reka-ui"
import { TooltipRoot, useForwardPropsEmits } from "reka-ui"

const props = defineProps<TooltipRootProps>()
const emits = defineEmits<TooltipRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <TooltipRoot
    v-slot="slotProps"
    data-slot="tooltip"
    v-bind="forwarded"
  >
    <slot v-bind="slotProps" />
  </TooltipRoot>
</template>
