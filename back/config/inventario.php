<?php

return [
    /*
     * Usuario (username) cuya contraseña autoriza anular o eliminar una revisión de
     * inventario. Se pide aunque quien anula tenga el permiso "Anular Almacenes".
     */
    'autoriza_anulacion' => env('INVENTARIO_AUTORIZA_ANULACION', '7308976'),
];
