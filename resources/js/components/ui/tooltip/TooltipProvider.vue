<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { TooltipProviderProps } from "reka-ui"
import { TooltipProvider } from "reka-ui"

const props = withDefaults(defineProps<TooltipProviderProps>(), {
  delayDuration: 0,
})
</script>

<template>
  <TooltipProvider v-bind="props">
    <slot />
  </TooltipProvider>
</template>
