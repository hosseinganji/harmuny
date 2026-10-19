import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
    { path: '/register', name: 'register', component: () => import('@/views/RegisterView.vue'), meta: { guest: true } },
    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue') },
    { path: '/search', name: 'search', component: () => import('@/views/SearchView.vue') },
    {
      path: '/library',
      component: () => import('@/views/LibraryView.vue'),
      children: [
        { path: '', name: 'library', component: () => import('@/views/library/PlaylistsTab.vue') },
        { path: 'songs', name: 'songs', component: () => import('@/views/library/SongsTab.vue') },
        { path: 'artists', name: 'artists', component: () => import('@/views/library/ArtistsTab.vue') },
        { path: 'liked', name: 'liked', component: () => import('@/views/library/LikedTab.vue') },
        { path: 'downloads', name: 'downloads', component: () => import('@/views/library/DownloadsTab.vue') },
        { path: 'device', name: 'device', component: () => import('@/views/library/DeviceTab.vue') },
        { path: 'history', name: 'history', component: () => import('@/views/library/HistoryTab.vue') },
      ],
    },
    { path: '/playlist/:id', name: 'playlist', component: () => import('@/views/PlaylistView.vue'), props: (r) => ({ id: Number(r.params.id) }) },
    { path: '/artist', name: 'artist', component: () => import('@/views/ArtistView.vue'), props: (r) => ({ name: String(r.query.name ?? '') }) },
    { path: '/profile', name: 'profile', component: () => import('@/views/ProfileView.vue') },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
    { path: '/settings/equalizer', name: 'equalizer', component: () => import('@/views/EqualizerView.vue') },
    {
      path: '/settings/:section(appearance|language|playback|notifications|storage|about)',
      name: 'settings-section',
      component: () => import('@/views/SettingsSectionView.vue'),
      props: true,
    },
    {
      path: '/admin',
      component: () => import('@/views/admin/AdminLayout.vue'),
      meta: { admin: true },
      children: [
        { path: '', name: 'admin', component: () => import('@/views/admin/AdminDashboard.vue') },
        { path: 'users', name: 'admin-users', component: () => import('@/views/admin/AdminUsers.vue') },
        { path: 'telegram', name: 'admin-telegram', component: () => import('@/views/admin/AdminTelegram.vue') },
        { path: 'reports', name: 'admin-reports', component: () => import('@/views/admin/AdminReports.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

// Library reopens on the tab the user was last on (remembered per user on this device).
const libraryTabKey = () => `smp.libraryTab.${useAuth().user?.id ?? 0}`

router.beforeEach((to, from) => {
  const auth = useAuth()
  if (to.meta.guest) return auth.isLoggedIn ? '/' : true
  if (!auth.isLoggedIn) return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : undefined }
  if (to.matched.some((r) => r.meta.admin) && !auth.isAdmin) return '/'
  if (to.name === 'library' && !from.path.startsWith('/library')) {
    const last = localStorage.getItem(libraryTabKey())
    if (last && last !== to.path && last.startsWith('/library/')) return last
  }
  return true
})

router.afterEach((to) => {
  if (to.path.startsWith('/library')) localStorage.setItem(libraryTabKey(), to.path)
})

export default router
