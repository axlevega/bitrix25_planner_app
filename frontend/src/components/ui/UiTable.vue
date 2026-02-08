<script setup>
defineProps({
  tag: { type: String, default: 'table' },
})
</script>

<template>
  <div class="ui-table-wrap">
    <component :is="tag" class="ui-table">
      <slot />
    </component>
  </div>
</template>

<style lang="scss" scoped>
.ui-table-wrap {
  overflow-x: auto;
  margin-bottom: 1rem;
}
.ui-table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  :deep(th),
  :deep(td) {
    padding: 0.6rem 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
  }
  :deep(th) {
    background: #f8fafc;
    font-weight: 600;
    font-size: 0.85rem;
  }
}
</style>
