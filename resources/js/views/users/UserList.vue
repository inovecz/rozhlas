<script setup>
import {ref} from "vue";
import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import {formatDate, getLoggedUserId} from "../../helper.js";
import UserService from "../../services/UserService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import CreateEditUser from "../../components/modals/CreateEditUser.vue";
import ModalDialog from "../../components/modals/ModalDialog.vue";

const users = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchUsers);

function fetchUsers(paginatorUrl) {
  UserService.fetchRecords(paginatorUrl, search, pageLength, orderColumn, orderAsc).then(response => {
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
  <div class="component-box">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        Seznam uživatelů
      </div>
      <div class="flex gap-4">
        <label class="input input-bordered input-sm flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
            <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
          </svg>
          <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
          <svg @click="() => {search.value = null}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 opacity-70 cursor-pointer">
            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"></path>
          </svg>
        </label>
        <button @click="editUser" class="btn btn-primary btn-square btn-sm text-xl"><span class="mdi mdi-plus-box"></span></button>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('id')">
              <div class="flex items-center cursor-pointer underline">
                #
                <span v-if="orderColumn === 'id'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('username')">
              <div class="flex items-center cursor-pointer underline">
                Uživatelské jméno
                <span v-if="orderColumn === 'username'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer underline">
                Vytvořen dne
                <span v-if="orderColumn === 'created_at'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
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

  </div>
</template>

<style scoped>

</style>