<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { DialogTriggerProps } from "reka-ui"
import { DialogTrigger } from "reka-ui"

const props = defineProps<DialogTriggerProps>()
</script>

<template>
  <DialogTrigger
    data-slot="dialog-trigger"
    v-bind="props"
  >
    <slot />
  </DialogTrigger>
</template>
