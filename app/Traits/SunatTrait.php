<?php

namespace App\Traits;

use Luecano\NumeroALetras\NumeroALetras;

trait SunatTrait
{
    public function setTotales(&$data)
    {
        $details = collect($data['details']);
        $data['mtoOperGravadas'] = $details->where('tipAfeIgv', 10)->sum('mtoValorVenta');
        $data['mtoOperExoneradas'] = $details->where('tipAfeIgv', 20)->sum('mtoValorVenta');
        $data['mtoOperInafectas'] = $details->where('tipAfeIgv', 30)->sum('mtoValorVenta');
        $data['mtoOperExportacion'] = $details->where('tipAfeIgv', 40)->sum('mtoValorVenta');
        $data['mtoOperGratuitas'] = $details->whereNotIn('tipAfeIgv', [10, 20, 30, 40])->sum('mtoValorVenta');

        $data['mtoIGV'] = $details->whereIn('tipAfeIgv', [10, 20, 30, 40])->sum('igv');
        $data['mtoIgvGratuitas'] = $details->whereNotIn('tipAfeIgv', [10, 20, 30, 40])->sum('igv');
        $data['icbper'] = $details->sum('icbper');
        $data['totalImpuestos'] =  $data['mtoIGV'] + $data['icbper'];

        $data['valorVenta'] = $data['mtoOperGravadas'] + $data['mtoOperExoneradas'] + $data['mtoOperInafectas'] + $data['mtoOperExportacion'];
        $data['subTotal'] =  $data['valorVenta'] + $data['mtoIGV'];


        $data['mtoImpVenta'] = floor($data['subTotal'] * 10) / 10;
        $data['Redondeo'] = $data['mtoImpVenta'] - $data['subTotal'];
    }
    public function setLegends(&$data)
    {
        $formatter = new NumeroALetras();
        $data['legends'] = [
            [
                'code' => 1000,
                'value' => $formatter->toInvoice($data['mtoImpVenta'], 2, 'SOLES')
            ]
        ];
    }
}
