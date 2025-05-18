<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Detalle;
use App\Models\Empresa;
use App\Models\Medida;
use App\Models\Pos;
use App\Models\Producto;
use App\Models\Proveedor;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDF;
use Illuminate\Support\Facades\View;
use Luecano\NumeroALetras\NumeroALetras;

class posController extends Controller
{
    //
    public function show()
    {
        $productos = Producto::where('estado', 1)->orderBy('nombre', 'asc')->get();
        $clientes = Cliente::all();
        $cart = session()->get('cart', []);
        $cajaAbierta = Caja::where('estado', 'abierto')
            ->where('usuario_id', Auth::user()->id)
            ->first();


        return view('pos.vender', compact('productos', 'cart', 'clientes', 'cajaAbierta'));
    }

    public function addToCart(Request $request, Producto $product)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$product->id])) {
            $cart[$product->id]['quantity']++;
        } else {
            $cart[$product->id] = [
                "name" => $product->nombre,
                "quantity" => 1,
                "price" => $product->precio_venta,
                "uni_medida" => $product->unimedida->simbolo_sunat,
                "id" => $product->id,
            ];
        }

        session()->put('cart', $cart);
        return view('pos.partial', compact('cart'));
    }

    public function updateCart(Request $request)
    {
        if ($request->id && $request->quantity && $request->price) {
            $cart = session()->get('cart');
            $cart[$request->id]["quantity"] = $request->quantity;
            $cart[$request->id]["price"] = $request->price;
            session()->put('cart', $cart);

            // Recalcular el total
            $total = 0;
            foreach ($cart as $item) {
                $total += $item['price'] * $item['quantity'];
            }

            return view('pos.partial', compact('cart'));
        }
    }
    public function removeFromCart(Request $request, $productId)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$productId])) {
            unset($cart[$productId]);
            session()->put('cart', $cart);
        }

        return view('pos.partial', compact('cart'));
    }
    public function processPayment(Request $request)
    {

        $cart = session()->get('cart', []);
        $total = 0;
        $gananciaTotal = 0;


        $caja = Caja::where('estado', 'abierto')
            ->where('usuario_id', Auth::user()->id)
            ->first();

        // Iniciamos una transacción de base de datos
        DB::beginTransaction();

        try {
            $venta = Pos::create([
                'tipo_comprobante' => $request->tipo_comprobante,
                'serie_comprobante' => '001',
                'num_comprobante' => '1000',
                'fecha' => now(),
                'igv' => 0,
                'total' => 0, // Lo actualizaremos después
                'estado' => 1,
                'usuario_id' => Auth::user()->id,
                'cliente_id' => $request->client_id,
                'forma_pago' => $request->forma_pago,
                'tipo_pago' => $request->tipo_pago,
                'caja_id' => $caja->id
            ]);

            foreach ($cart as $item) {
                $producto = Producto::findOrFail($item['id']);
                $cantidadVendida = $item['quantity'];
                $precioVenta = $item['price'];
                $subtotal = $precioVenta * $cantidadVendida;
                $costoVenta = 0;

                // Calcular el costo de venta usando FIFO
                $detallesCompra = $producto->detalleCompras()
                    ->where('cantidad_restante', '>', 0)
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($detallesCompra as $detalleCompra) {
                    if ($cantidadVendida <= 0) break;

                    $cantidadUsada = min($cantidadVendida, $detalleCompra->cantidad_restante);
                    $costoVenta += $cantidadUsada * $detalleCompra->precio_compra;
                    $cantidadVendida -= $cantidadUsada;

                    $detalleCompra->decrement('cantidad_restante', $cantidadUsada);
                }

                $ganancia = $subtotal - $costoVenta;
                $gananciaTotal += $ganancia;

                Detalle::create([
                    'venta_id' => $venta->id,
                    'producto_id' => $item['id'],
                    'cantidad' => $item['quantity'],
                    'precio' => $precioVenta,
                    'descuento' => 0,
                    'costo_venta' => $costoVenta,
                    'ganancia' => $ganancia,
                ]);

                $producto->decrement('stok', $item['quantity']);

                $total += $subtotal;
            }

            // Actualizamos el total y la ganancia de la venta
            $venta->update([
                'total' => $total,
                'ganancia_total' => $gananciaTotal,
            ]);

            // Confirmamos la transacción
            DB::commit();

            // Limpiamos el carrito después de procesar el pago
            session()->forget('cart');

            return response()->json([
                'success' => true,
                'message' => 'Pago procesado correctamente',
                'total' => $total,
                'ganancia' => $gananciaTotal,
                'pdfUrl' => route('generate.pdf', $venta->id),
                'success_id' => $venta->id,
            ]);
        } catch (\Exception $e) {
            // Si algo sale mal, revertimos la transacción
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function generatePDF($successId)
    {


        $success = Pos::where('id', '=', $successId)->get();
        foreach ($success as $pay) {
            $total = $pay->total;
        }
        $empresa = Empresa::first();
        $formatter = new NumeroALetras();
        $totalInWords = $formatter->toMoney($total, 2, 'SOLES', 'CENTAVOS');

        $pdf = FacadePdf::loadView('pos.invoice', compact('success', 'successId', 'totalInWords', 'empresa'));

        $pdf->setPaper([0, 0, 226.77, 841.89], 'portrait');

        // $pdf->setPaper([-20, 0, 500, 1000], 'portrait');
        // Cambia `download` por `stream`
        return $pdf->stream($successId . '.pdf');
    }
    public function search(Request $request)
    {
        $query = $request->input('query');
        $productos = Producto::where('nombre', 'LIKE', "%{$query}%")->where('estado', 1)->orderBy('nombre', 'asc')->get();
        return view('pos.partial-prod', compact('productos'))->render();
    }
}
