<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { DropdownMenuGroupProps } from "reka-ui"
import { DropdownMenuGroup } from "reka-ui"

const props = defineProps<DropdownMenuGroupProps>()
</script>

<template>
  <DropdownMenuGroup v-bind="props">
    <slot />
  </DropdownMenuGroup>
</template>
