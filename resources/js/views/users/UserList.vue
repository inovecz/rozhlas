<script setup>
import {ref} from "vue";
import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import {formatDate, getLoggedUserId} from "../../helper.js";
import UserService from "../../services/UserService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import CreateEditUser from "../../components/modals/CreateEditUser.vue";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";

const users = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchUsers);

function fetchUsers(paginatorUrl) {
  UserService.fetchRecords(paginatorUrl, search, pageLength, orderColumn.value, orderAsc.value).then(response => {
    users.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function editUser(id = null) {
  const foundUser = users.value.data.find(user => user.id === id);
  const {reveal, onConfirm} = createConfirmDialog(CreateEditUser, {
    user: foundUser ? {...foundUser} : {id: null, username: '', password: null}
  });
  reveal();
  onConfirm(user => {
    UserService.saveUser(user).then(() => {
      toast.success('Uživatel byl úspěšně uložen');
      fetchRecords();
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se uložit uživatele');
    });
  });
}

function deleteUser(id) {
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu si přejete smazat uživatele?',
    message: 'Tato akce je nevratná, dojde k trvalému smazání uživatele.'
  });
  reveal();
  onConfirm(() => {
    UserService.deleteUser(id).then(() => {
      toast.success('Uživatel byl úspěšně smazán');
      fetchRecords();
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat uživatele');
    });
  });
}
</script>

<template>
  <Box label="Seznam uživatelů">
    <template #header>
      <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
      <button @click="editUser" class="btn btn-primary btn-square btn-sm text-xl">
        <span class="mdi mdi-plus-box"></span>
      </button>
    </template>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('id')">
              <div class="flex items-center cursor-pointer underline">
                #
                <span v-if="orderColumn.value === 'id'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('username')">
              <div class="flex items-center cursor-pointer underline">
                Uživatelské jméno
                <span v-if="orderColumn.value === 'username'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer underline">
                Vytvořen dne
                <span v-if="orderColumn.value === 'created_at'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in users.data" :key="user.id" class="hover">
            <td>
              {{ user.id }}
            </td>
            <td>
              {{ user.username }}
            </td>
            <td>
              {{ formatDate(new Date(user.created_at), 'd.m.Y') }}
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="editUser(user.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button v-if="user.id !== getLoggedUserId()" @click="deleteUser(user.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="users?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="users?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in users?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </Box>
</template>

<style scoped>
</style>