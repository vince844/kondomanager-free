<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { DialogOverlayProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { DrawerOverlay } from "vaul-vue"
import { cn } from "@/lib/utils"

const props = defineProps<DialogOverlayProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <DrawerOverlay v-bind="delegatedProps" :class="cn('fixed inset-0 z-50 bg-black/80', props.class)" />
</template>
