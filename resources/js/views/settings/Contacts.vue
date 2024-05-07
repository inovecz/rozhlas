<script setup>
import ContactList from "./ContactList.vue";
import ContactGroupList from "./ContactGroupList.vue";
import {onMounted} from "vue";
import ContactService from "../../services/ContactService.js";
import {contactGroupStore} from "../../store/contactGroupStore.js";

const contactGroupStoreInfo = contactGroupStore();

onMounted(() => {
  fetchContactGroupsSelect();
});

emitter.on('refetchContactGroupsSelect', () => {
  fetchContactGroupsSelect();
});

function fetchContactGroupsSelect() {
  ContactService.getAllContactGroups().then(response => {
    contactGroupStoreInfo.contactGroups = response.data.map(group => {
      return {id: group.id, name: group.name};
    });
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Kontakty</h1>
    <div class="content flex flex-col space-y-4 mb-4">
      <ContactList/>
      <ContactGroupList/>
    </div>
  </div>
</template>

<style scoped>

</style>