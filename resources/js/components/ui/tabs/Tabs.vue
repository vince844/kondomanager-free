<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { TabsRootEmits, TabsRootProps } from "reka-ui"
import { TabsRoot, useForwardPropsEmits } from "reka-ui"

const props = defineProps<TabsRootProps>()
const emits = defineEmits<TabsRootEmits>()

const forwarded = useForwardPropsEmits(props, emits)
</script>

<template>
  <TabsRoot v-bind="forwarded">
    <slot />
  </TabsRoot>
</template>
