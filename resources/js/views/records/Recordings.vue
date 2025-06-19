<script setup>
import Recorder from "./Recorder.vue";
import RecordList from "./RecordList.vue";
import PageContent from "../../components/custom/PageContent.vue";
</script>

<template>
  <PageContent label="Záznamy">
    <Recorder/>
    <RecordList/>
  </PageContent>
</template>