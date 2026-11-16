<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { DrawerTriggerProps } from "vaul-vue"
import { DrawerTrigger } from "vaul-vue"

const props = defineProps<DrawerTriggerProps>()
</script>

<template>
  <DrawerTrigger
    data-slot="drawer-trigger"
    v-bind="props"
  >
    <slot />
  </DrawerTrigger>
</template>
