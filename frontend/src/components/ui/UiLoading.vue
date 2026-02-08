<script setup>
defineProps({
  text: { type: String, default: 'Загрузка…' },
})
</script>

<template>
  <div class="ui-loading">{{ text }}</div>
</template>

<style lang="scss" scoped>
.ui-loading {
  color: #64748b;
  font-size: 0.9rem;
}
</style>
