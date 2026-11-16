<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { InputGroupButtonProps } from "."
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { inputGroupButtonVariants } from "."

const props = withDefaults(defineProps<InputGroupButtonProps>(), {
  size: "xs",
  variant: "ghost",
})
</script>

<template>
  <Button
    :data-size="props.size"
    :variant="props.variant"
    :class="cn(inputGroupButtonVariants({ size: props.size }), props.class)"
  >
    <slot />
  </Button>
</template>
