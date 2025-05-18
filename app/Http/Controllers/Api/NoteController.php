<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\SunatService;
use App\Traits\SunatTrait;
use Greenter\Report\XmlUtils;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    //
    use SunatTrait;
    public function send(Request $request)
    {
        $data = $request->all();
        $request->validate([
            'company' => 'required|array',
            'company.address' => 'required|array',
            'client' => 'required|array',
            'details' => 'required|array',
            'details.*' => 'required|array',
        ]);
        $empresa = Empresa::where('ruc', '20608731653')->first();
        $this->setTotales($data);
        $this->setLegends($data);

        $sunat = new SunatService();

        $see = $sunat->getSee($empresa);
        $note = $sunat->getNote($data);

        $result = $see->send($note);
        $response['xml'] = $see->getFactory()->getLastXml();
        $response['hash'] = (new XmlUtils())->getHashSign($response['xml']);
        $response['sunatResponse'] = $sunat->sunatResponse($result);

        return response()->json($response);
    }
    public function xml(Request $request)
    {
        $empresa = Empresa::where('ruc', '20608731653')->first();
        $data = $request->all();
        $request->validate([
            'company' => 'required|array',
            'company.address' => 'required|array',
            'client' => 'required|array',
            'details' => 'required|array',
            'details.*' => 'required|array',
        ]);
        $this->setTotales($data);
        $this->setLegends($data);
        $sunat = new SunatService();
        $see = $sunat->getSee($empresa);
        $note = $sunat->getNote($data);

        $response['xml'] = $see->getXmlSigned($note);
        $response['hash'] = (new XmlUtils())->getHashSign($response['xml']);
        return response()->json($response, 200);
    }
    public function pdf(Request $request)
    {
        $empresa = Empresa::where('ruc', '20608731653')->first();
        $data = $request->all();
        $request->validate([
            'company' => 'required|array',
            'company.address' => 'required|array',
            'client' => 'required|array',
            'details' => 'required|array',
            'details.*' => 'required|array',
        ]);
        $this->setTotales($data);
        $this->setLegends($data);
        $sunat = new SunatService();

        $note = $sunat->getNote($data);
       // $sunat->generatePdfReport($invoice);
        return $sunat->getHtmlReport($note);
    }
}
