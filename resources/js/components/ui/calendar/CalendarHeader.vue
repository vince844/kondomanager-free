<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { CalendarHeaderProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { CalendarHeader, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<CalendarHeaderProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <CalendarHeader :class="cn('relative flex w-full items-center justify-between pt-1', props.class)" v-bind="forwardedProps">
    <slot />
  </CalendarHeader>
</template>
