const routes = [
  {
    path: '/login',
    component: () => import('pages/LoginPage.vue')
  },
  {
    path: '/',
    component: () => import('layouts/MainLayout.vue'),
    children: [
      { path: '', component: () => import('pages/IndexPage.vue') },
      { path: 'usuarios', component: () => import('pages/usuarios/IndexPage.vue') },
      { path: 'productos', component: () => import('pages/productos/IndexPage.vue') },
      { path: 'ventas', component: () => import('pages/ventas/IndexPage.vue') },
      { path: 'ventas/nueva', component: () => import('pages/ventas/NuevaPage.vue') }
    ]
  },

  // Always leave this as last one,
  // but you can also remove it
  {
    path: '/:catchAll(.*)*',
    component: () => import('pages/ErrorNotFound.vue')
  }
]

export default routes
