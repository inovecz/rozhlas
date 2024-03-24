import {createRouter, createWebHistory} from "vue-router";
import AuthLayout from "./components/layouts/AuthLayout.vue";
import DefaultLayout from "./components/layouts/DefaultLayout.vue";
import Page404 from "./views/PageNotFound.vue";
import About from "./views/about/About.vue";
import Login from "./views/auth/Login.vue";
import LiveBroadcast from "./views/live-broadcast/LiveBroadcast.vue";
import Recordings from "./views/records/Recordings.vue";
import Scheduler from "./views/schedule/Scheduler.vue";

const routes = [
    {
        path: "/",
        redirect: "/live-broadcast",
        component: DefaultLayout,
        children: [
            {path: "/live-broadcast", name: "LiveBroadcast", component: LiveBroadcast, meta: {title: "Živé vysílání"}},
            {path: "/recordings", name: "Recordings", component: Recordings, meta: {title: "Záznamy"}},
            {path: "/schedule", name: "Scheduler", component: Scheduler, meta: {title: "Plán vysílání"}},
            {path: '/about', name: "About", component: About},
        ],
    },
    {
        path: "/auth",
        redirect: "/login",
        name: "Auth",
        component: AuthLayout,
        children: [
            {path: "/login", name: "Login", component: Login},
        ],
    },
    {
        path: "/:pathMatch(.*)*",
        name: "NotFound",
        component: Page404,
    },
];


const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Update the document title on each route change
router.beforeEach((to, from, next) => {
    if (to.name !== 'Login' && to.name !== 'Register' && localStorage.getItem('token') === null) {
        next({name: 'Login'});
    }
    document.title = to.meta.title || 'Sarrah IV';
    next();
});

export default router;