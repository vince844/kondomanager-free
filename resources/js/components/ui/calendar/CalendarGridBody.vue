<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { CalendarGridBodyProps } from "reka-ui"
import { CalendarGridBody } from "reka-ui"

const props = defineProps<CalendarGridBodyProps>()
</script>

<template>
  <CalendarGridBody v-bind="props">
    <slot />
  </CalendarGridBody>
</template>
