<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventarioPaginadoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        $user = User::create([
            'name' => 'CONTADOR', 'username' => 'contador'.uniqid(),
            'email' => uniqid().'@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes', 'Aplicar Almacenes']);

        return $user;
    }

    private function producto(string $nombre, float $stock): Producto
    {
        return Producto::create([
            'codigo' => 'T'.random_int(10000, 99999), 'nombre' => $nombre,
            'unidad' => 'KG', 'precio_compra' => 10, 'precio_venta' => 20, 'stock_inicial' => $stock,
        ]);
    }

    public function test_la_cabecera_no_trae_el_detalle_y_el_detalle_se_pagina(): void
    {
        $user = $this->usuario();
        $id = $this->actingAs($user)->postJson('/api/almacenes', [])->assertCreated()->json('id');

        $pollo = $this->producto('POLLO ENTERO', 10);
        $chorizo = $this->producto('CHORIZO', 5);
        $queso = $this->producto('QUESO', 2);
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pollo->id, 'cantidad' => 12.5])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $chorizo->id, 'cantidad' => 5])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $queso->id, 'cantidad' => 1, 'conteos' => [['lote' => 'L1', 'cantidad' => 1]]])->assertCreated();

        $this->getJson("/api/almacenes/{$id}")->assertOk()
            ->assertJson(['detalles_count' => 3])->assertJsonMissingPath('detalles');

        $page = $this->getJson("/api/almacenes/{$id}/detalles?per_page=2")->assertOk();
        $page->assertJson(['estado' => 'BORRADOR', 'total' => 3, 'last_page' => 2]);
        $this->assertCount(2, $page->json('data'));

        $diffs = $this->getJson("/api/almacenes/{$id}/detalles?solo_diferencias=1&orden=nombre")->assertOk();
        $this->assertSame(['POLLO ENTERO', 'QUESO'], array_column($diffs->json('data'), 'nombre'));
        $this->assertEqualsWithDelta(2.5, (float) $diffs->json('data.0.diferencia_actual'), 0.0001);
        $this->assertCount(1, $diffs->json('data.1.conteos'));

        $byProduct = $this->getJson("/api/almacenes/{$id}/detalles?".http_build_query(['producto_ids' => [$chorizo->id]]))->assertOk();
        $this->assertSame([$chorizo->id], array_column($byProduct->json('data'), 'producto_id'));

        $this->getJson("/api/almacenes/{$id}/avance")->assertOk()->assertJson([
            'revisados' => 3, 'con_diferencia' => 2, 'sin_diferencia' => 1,
            'sobrantes' => ['count' => 1], 'faltantes' => ['count' => 1],
            'diferencia_valor' => 15.0,
        ])->assertJsonMissingPath('detalles');

        // Ya aplicada, la comparación usa el stock guardado al aplicar.
        $this->postJson("/api/almacenes/{$id}/aplicar")->assertOk();
        $this->getJson("/api/almacenes/{$id}/avance")->assertOk()->assertJson(['con_diferencia' => 2, 'diferencia_valor' => 15.0]);
    }
}
