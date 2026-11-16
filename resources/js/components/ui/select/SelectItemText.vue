<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { SelectItemTextProps } from "reka-ui"
import { SelectItemText } from "reka-ui"

const props = defineProps<SelectItemTextProps>()
</script>

<template>
  <SelectItemText v-bind="props">
    <slot />
  </SelectItemText>
</template>
