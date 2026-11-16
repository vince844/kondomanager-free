<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts" setup>
import type { DrawerDescriptionProps } from "vaul-vue"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { DrawerDescription } from "vaul-vue"
import { cn } from "@/lib/utils"

const props = defineProps<DrawerDescriptionProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <DrawerDescription v-bind="delegatedProps" :class="cn('text-sm text-muted-foreground', props.class)">
    <slot />
  </DrawerDescription>
</template>
