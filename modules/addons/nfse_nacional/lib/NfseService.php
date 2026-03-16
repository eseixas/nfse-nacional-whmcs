<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * NfseService
 * Orquestra emissao e cancelamento de NFS-e
 * via API REST NFSe Nacional (SefinNacional)
 *
 * Ambientes:
 *   Producao Restrita: https://sefin.producaorestrita.nfse.gov.br/SefinNacional/
 *   Producao:          https://sefin.nfse.gov.br/SefinNacional/
 *
 * Referencia: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao
 */

require_once __DIR__ . '/CertManager.php';
require_once __DIR__ . '/NfseXmlBuilder.php';
require_once __DIR__ . '/NfseSigner.php';
require_once __DIR__ . '/NfseApiClient.php';

use WHMCS\Database\Capsule;

class NfseService
{
    private $config;
    private $certMgr;
    private $builder;
    private $api;

    public function __construct($config)
    {
        $this->config  = $config;
        $this->certMgr = new CertManager();
        $this->builder = new NfseXmlBuilder($config);
    }

    private function debugEnabled(): bool
    {
        return !empty($this->config['debug_ativo']);
    }

    private function debugWrite($path, $content): void
    {
        if ($this->debugEnabled()) {
            file_put_contents($path, $content);
        }
    }

    private function debugAppend($path, $content): void
    {
        if ($this->debugEnabled()) {
            file_put_contents($path, file_get_contents($path) . $content);
        }
    }

    private function getApi()
    {
        if (!isset($this->api)) {
            $this->api = new NfseApiClient(
                $this->config['ambiente'] ?? 'producao_restrita',
                $this->certMgr->getCertPath(),
                $this->certMgr->getPassword()
            );
        }
        return $this->api;
    }

    // --- Emissao -------------------------------------------------------------

    public function emitirParaFatura($invoiceId)
    {
        try {
            // Verifica se ja existe NFS-e emitida
            $existing = Capsule::table('mod_nfse_nacional')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'emitida')
                ->first();

            if ($existing) {
                return array('success' => false,
                    'message' => 'Ja existe NFS-e emitida para a fatura #' . $invoiceId . ' (Numero: ' . $existing->numero_nfse . ')');
            }

            if (!$this->certMgr->exists()) {
                return array('success' => false,
                    'message' => 'Certificado digital nao configurado. Acesse Addons > NFS-e > Certificado Digital.');
            }

            $invoice = $this->getInvoice($invoiceId);
            if (!$invoice) {
                return array('success' => false, 'message' => 'Fatura #' . $invoiceId . ' nao encontrada.');
            }

            if ($invoice['status'] !== 'Paid') {
                return array('success' => false,
                    'message' => 'A fatura #' . $invoiceId . ' nao esta paga (status: ' . $invoice['status'] . ').');
            }

            $client = $this->getClient($invoice['userid']);
            if (!$client) {
                return array('success' => false, 'message' => 'Cliente nao encontrado para a fatura #' . $invoiceId . '.');
            }

            // Carrega certificado e cria o assinador
            $certs  = $this->certMgr->read();
            $signer = new NfseSigner($certs);

            // Numero sequencial da DPS
            $nDps = $this->nextDpsNumber();

            // Constroi XML da DPS (padrao NFSe Nacional SPED v1.00)
            $xmlDps = $this->builder->buildDps($invoice, $client, $nDps);

            // Extrai o Id do infDPS para a assinatura
            if (!preg_match('/Id="([^"]+)"/', $xmlDps, $m)) {
                throw new Exception('Id nao encontrado no XML da DPS.');
            }
            $refUri = '#' . $m[1];

            // Assina com RSA-SHA256 via DOM (garante XML valido apos assinatura)
            $xmlAssinado = $signer->sign($xmlDps, $refUri);

            $valorIss    = round($invoice['total'] * ((float)($this->config['aliquota_iss'] ?? 2) / 100), 2);
            $hasValorIss = in_array('valor_iss', Capsule::schema()->getColumnListing('mod_nfse_nacional'));

            // Upsert: se ja existe registro (qualquer status exceto emitida), atualiza; senao insere
            $existing2 = Capsule::table('mod_nfse_nacional')->where('invoice_id', $invoiceId)->first();

            if ($existing2) {
                // Reenvio: atualiza o registro existente
                $recordId = $existing2->id;
                $updateData = array(
                    'client_id'    => $invoice['userid'],
                    'valor'        => $invoice['total'],
                    'status'       => 'pendente',
                    'xml_enviado'  => $xmlAssinado,
                    'xml_retorno'  => null,
                    'mensagem_erro'=> null,
                    'updated_at'   => now(),
                );
                if ($hasValorIss) {
                    $updateData['valor_iss'] = $valorIss;
                }
                Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update($updateData);
            } else {
                $insertData = array(
                    'invoice_id'  => $invoiceId,
                    'client_id'   => $invoice['userid'],
                    'valor'       => $invoice['total'],
                    'status'      => 'pendente',
                    'xml_enviado' => $xmlAssinado,
                    'n_dps'       => $nDps,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                );
                if ($hasValorIss) {
                    $insertData['valor_iss'] = $valorIss;
                }
                $recordId = Capsule::table('mod_nfse_nacional')->insertGetId($insertData);
            }

            // Valida o XML antes de enviar
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            if (!$dom->loadXML($xmlAssinado)) {
                $errs = array_map(function($e) { return $e->message; }, libxml_get_errors());
                libxml_clear_errors();
                throw new Exception('XML da DPS invalido: ' . implode('; ', $errs));
            }
            libxml_clear_errors();

            // Grava o XML para diagnostico (remova em producao)
            $debugPath = __DIR__ . '/../debug_dps_' . $invoiceId . '.xml';
            $this->debugWrite($debugPath, $xmlAssinado);

            // Envia para a API NFSe Nacional
            $cnpj     = preg_replace('/\D/', '', $this->config['cnpj']);
            $response = $this->getApi()->emitir($xmlAssinado, $cnpj);

            if ($response['success']) {
                $numeroNfse   = $response['numero_nfse']  ?? null;
                $chaveAcesso  = $response['chave_acesso'] ?? null;
                // Prefere o XML da NFSe descomprimido; fallback para raw
                $xmlRetorno   = !empty($response['nfse_xml']) ? $response['nfse_xml'] : $response['raw'];
                $nDfse        = $response['n_dfse'] ?? null;

                $updateData = array(
                    'numero_nfse'       => $numeroNfse,
                    'codigo_verificacao'=> $chaveAcesso,
                    'status'            => 'emitida',
                    'xml_retorno'       => $xmlRetorno,
                    'emitida_em'        => now(),
                    'updated_at'        => now(),
                );
                if ($nDfse !== null) {
                    $updateData['n_dfse'] = $nDfse;
                }

                Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update($updateData);

                $this->addNoteToInvoice($invoiceId, $numeroNfse ?? 'N/D');
                $this->log('success', 'emissao',
                    'NFS-e #' . $numeroNfse . ' emitida para fatura #' . $invoiceId,
                    $response, $invoiceId);

                return array(
                    'success' => true,
                    'message' => 'NFS-e emitida com sucesso! Numero: ' . ($numeroNfse ?? 'Aguardando'),
                    'data'    => $response,
                );
            }

            // E0014: DPS ja emitida anteriormente - consulta nota existente pelo idDPS
            $rawJson = json_decode($response['raw'] ?? '{}', true);
            $erroE0014 = false;
            $idDpsRetornado = $rawJson['idDPS'] ?? null;
            foreach (($rawJson['erros'] ?? array()) as $err) {
                if (($err['Codigo'] ?? '') === 'E0014') {
                    $erroE0014 = true;
                    break;
                }
            }

            if ($erroE0014 && !empty($idDpsRetornado)) {
                // Nota ja existe na Receita - consulta via API para obter numero e chave
                $consultaResp = $this->getApi()->consultarPorIdDps($idDpsRetornado);
                if ($consultaResp['success'] && !empty($consultaResp['numero_nfse'])) {
                    Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
                        'numero_nfse'       => $consultaResp['numero_nfse'],
                        'codigo_verificacao'=> $consultaResp['chave_acesso'] ?? null,
                        'status'            => 'emitida',
                        'xml_retorno'       => $consultaResp['raw'],
                        'mensagem_erro'     => null,
                        'emitida_em'        => now(),
                        'updated_at'        => now(),
                    ));
                    $this->addNoteToInvoice($invoiceId, $consultaResp['numero_nfse']);
                    $this->log('success', 'emissao',
                        'NFS-e #' . $consultaResp['numero_nfse'] . ' recuperada (E0014) para fatura #' . $invoiceId,
                        $consultaResp, $invoiceId);
                    return array(
                        'success' => true,
                        'message' => 'NFS-e ja existente recuperada! Numero: ' . $consultaResp['numero_nfse'],
                        'data'    => $consultaResp,
                    );
                }
                // Consulta falhou: salva como emitida sem numero (melhor que erro)
                Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
                    'status'        => 'emitida',
                    'mensagem_erro' => 'E0014: DPS ja emitida. Consulte manualmente o numero da nota.',
                    'updated_at'    => now(),
                ));
                return array('success' => true,
                    'message' => 'NFS-e ja havia sido emitida (E0014). Verifique o numero no portal.');
            }

            // Erro na transmissao
            Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
                'status'        => 'erro',
                'xml_retorno'   => $response['raw'],
                'mensagem_erro' => $response['error'],
                'updated_at'    => now(),
            ));

            $this->log('error', 'emissao',
                'Erro ao emitir NFS-e para fatura #' . $invoiceId . ': ' . $response['error'],
                $response, $invoiceId);

            return array('success' => false, 'message' => 'Erro ao emitir NFS-e: ' . $response['error']);

        } catch (Exception $e) {
            $this->log('error', 'emissao', 'Excecao: ' . $e->getMessage(),
                array('trace' => $e->getTraceAsString()), $invoiceId);
            return array('success' => false, 'message' => 'Erro interno: ' . $e->getMessage());
        }
    }

    // --- Cancelamento --------------------------------------------------------

    public function cancelar($invoiceId)
    {
        try {
            $record = Capsule::table('mod_nfse_nacional')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'emitida')
                ->first();

            if (!$record) {
                return array('success' => false, 'message' => 'NFS-e nao encontrada ou nao esta emitida.');
            }

            $certs  = $this->certMgr->read();
            $signer = new NfseSigner($certs);

            // Resolve chave de acesso completa (53 chars: NFS + 50 digitos)
            $chaveAcesso = $record->codigo_verificacao ?? null;
            $chaveValida = !empty($chaveAcesso) && strlen($chaveAcesso) >= 50;

            // Tenta extrair do xml_retorno se chave invalida/truncada
            if (!$chaveValida && !empty($record->xml_retorno)) {
                if (preg_match('/infNFSe\s[^>]*Id="([^"]{50,})"/', $record->xml_retorno, $mx)) {
                    $chaveAcesso = $mx[1]; $chaveValida = true;
                }
            }

            // Recupera via API usando idDPS do xml_enviado
            if (!$chaveValida && !empty($record->xml_enviado)) {
                if (preg_match('/infDPS Id="([^"]+)"/', $record->xml_enviado, $mx)) {
                    $consultaApi = $this->getApi()->consultarPorIdDps($mx[1]);
                    if (!empty($consultaApi['chave_acesso']) && strlen($consultaApi['chave_acesso']) >= 50) {
                        $chaveAcesso = $consultaApi['chave_acesso']; $chaveValida = true;
                        $upd = array('codigo_verificacao' => $chaveAcesso);
                        if (!empty($consultaApi['nfse_xml'])) $upd['xml_retorno'] = $consultaApi['nfse_xml'];
                        if (!empty($consultaApi['n_dfse']))   $upd['n_dfse'] = $consultaApi['n_dfse'];
                        try { Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update($upd); } catch (Exception $ig) {}
                    }
                }
            }

            if (!$chaveValida) {
                return array('success' => false, 'message' =>
                    'Nao foi possivel determinar a chave de acesso. A nota pode ter sido emitida antes da correcao do banco.');
            }

            try { Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update(array('codigo_verificacao' => $chaveAcesso)); } catch (Exception $ig) {}

            // Consulta GET /nfse/{chave} para verificar estado atual da nota no servidor
            $chaveUrl = preg_match('/^NFS(.{50})$/i', $chaveAcesso, $mxg) ? $mxg[1] : $chaveAcesso;
            $chaveUrl = substr(trim($chaveUrl), 0, 50);
            $getResp  = $this->getApi()->consultarPorChave($chaveUrl);

            // Decodifica o XML retornado pelo GET (pode ser gzip ou JSON com campo nfseXmlGZipB64)
            $getXml = '';
            if (!empty($getResp['raw'])) {
                $raw = $getResp['raw'];
                if (strlen($raw) > 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b) {
                    $getXml = @gzdecode($raw) ?: $raw;
                } elseif (!empty($getResp['nfse_xml'])) {
                    $getXml = $getResp['nfse_xml'];
                } else {
                    $getXml = $raw;
                }
            }

            $debugGet = __DIR__ . '/../debug_get_nfse_' . $invoiceId . '.txt';
            $this->debugWrite($debugGet, "GET /nfse/{$chaveUrl}\n" .
                "success: " . ($getResp['success'] ? 'true' : 'false') . "\n" .
                "error: " . ($getResp['error'] ?? 'nenhum') . "\n" .
                "http_code: " . ($getResp['http_code'] ?? '?') . "\n" .
                "--- XML (primeiros 2000 chars) ---\n" .
                substr($getXml, 0, 2000) . "\n"
            );

            // Se GET retornou 404, a nota nao existe neste ambiente
            if (!$getResp['success'] && ($getResp['http_code'] ?? 0) == 404) {
                return array('success' => false, 'message' =>
                    'Nota nao encontrada no servidor (GET 404). A nota pode ter sido emitida em ambiente diferente ou o prazo de cancelamento expirou.');
            }

            // Se GET mostra nota ja cancelada (cStat=101), atualiza banco e retorna sucesso
            if (!empty($getXml) && strpos($getXml, '<cStat>101</cStat>') !== false) {
                try {
                    Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update(array(
                        'status'     => 'cancelada',
                        'updated_at' => now(),
                    ));
                } catch (Exception $ig) {}
                $this->log('success', 'cancelamento',
                    'NFS-e #' . $record->numero_nfse . ' ja estava cancelada no servidor. Status atualizado.', array(), $invoiceId);
                return array('success' => true, 'message' =>
                    'NFS-e #' . $record->numero_nfse . ' ja estava cancelada no servidor (cStat=101). Status atualizado no banco.');
            }

            $nDfse = $record->n_dfse ?? '';
            // Se n_dfse nao esta no banco, tenta extrair do xml_retorno
            if (empty($nDfse) && !empty($record->xml_retorno)) {
                if (preg_match('/<nDFSe[^>]*>([^<]+)<\/nDFSe>/', $record->xml_retorno, $mn)) {
                    $nDfse = trim($mn[1]);
                }
            }
            // Tenta extrair nDFSe e cOrgao do XML do GET (fonte mais confiavel)
            if (!empty($getXml)) {
                if (preg_match('/<nDFSe[^>]*>([^<]+)<\/nDFSe>/', $getXml, $mn2)) {
                    $nDfse = trim($mn2[1]);
                    $this->debugAppend($debugGet,  "nDFSe extraido do GET: {$nDfse}\n");
                }
            }
            $xmlCancel   = $this->builder->buildCancelamento($record->numero_nfse, $chaveAcesso, $nDfse);
            $xmlAssinado = $signer->signCancelamento($xmlCancel);

            // Debug: salva XML de cancelamento para inspecao
            $debugCancel = __DIR__ . '/../debug_cancel_' . $invoiceId . '.xml';
            $this->debugWrite($debugCancel, $xmlAssinado);

            // Debug: salva info do request (URL e ambiente)
            $chaveUrl = preg_match('/^NFS(.{50})$/i', $chaveAcesso, $mxd) ? $mxd[1] : $chaveAcesso;
            $ambienteRaw = $this->config['ambiente'] ?? 'producao_restrita';
            $ambiente    = (strpos($ambienteRaw, '=') !== false)
                ? trim(explode('=', $ambienteRaw)[0]) : trim($ambienteRaw);
            $baseUrl   = ($ambiente === 'producao')
                ? 'https://sefin.nfse.gov.br/SefinNacional/'
                : 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/';
            $debugUrl  = __DIR__ . '/../debug_cancel_url_' . $invoiceId . '.txt';
            $this->debugWrite($debugUrl, "Ambiente: {$ambiente}\n" .
                "URL: {$baseUrl}nfse/{$chaveUrl}/eventos\n" .
                "chaveAcesso original: {$chaveAcesso}\n" .
                "chaveSemNFS (50): {$chaveUrl}\n" .
                "nDfse (nProt): {$nDfse}\n" .
                "nNFSe: {$record->numero_nfse}\n"
            );

            $response = $this->getApi()->cancelar($xmlAssinado, $chaveAcesso);

            // Acrescenta resultado no debug
            $this->debugAppend($debugUrl, 
                "---\n" .
                "success: " . ($response['success'] ? 'true' : 'false') . "\n" .
                "http_code: " . ($response['http_code'] ?? '?') . "\n" .
                "error: " . ($response['error'] ?? '') . "\n" .
                "raw: " . substr((string)($response['raw'] ?? ''), 0, 500) . "\n"
            );

            if ($response['success']) {
                Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update(array(
                    'status'     => 'cancelada',
                    'updated_at' => now(),
                ));
                $this->log('success', 'cancelamento',
                    'NFS-e #' . $record->numero_nfse . ' cancelada.', array(), $invoiceId);
                return array('success' => true, 'message' => 'NFS-e #' . $record->numero_nfse . ' cancelada com sucesso.');
            }

            return array('success' => false, 'message' => 'Erro ao cancelar: ' . $response['error']);

        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Erro: ' . $e->getMessage());
        }
    }

    // --- Helpers -------------------------------------------------------------

    private function getInvoice($id)
    {
        $inv = Capsule::table('tblinvoices')->where('id', $id)->first();
        if (!$inv) return null;
        $inv = (array)$inv;

        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->get();
        $inv['items'] = array_map(function($i) { return (array)$i; }, $items->toArray());

        return $inv;
    }

    private function getClient($id)
    {
        $client = Capsule::table('tblclients')->where('id', $id)->first();
        if (!$client) return null;
        $client = (array)$client;

        // Busca CPF/CNPJ do campo customizado "CPF/CNPJ"
        $cf = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfieldsvalues.relid', $id)
            ->where('tblcustomfields.fieldname', 'CPF/CNPJ')
            ->first();

        $client['tax_id'] = $cf ? trim($cf->value) : '';

        return $client;
    }

    private function nextDpsNumber()
    {
        // Offset configuravel: numero minimo da serie DPS deste addon
        $offset = (int)($this->config['ndps_offset'] ?? 1);
        if ($offset < 1) {
            $offset = 1;
        }

        // Usa coluna n_dps dedicada (adicionada pela migration) para consulta rapida e confiavel
        $maxNdps = 0;
        try {
            $max = Capsule::table('mod_nfse_nacional')->max('n_dps');
            $maxNdps = (int)$max;
        } catch (Exception $e) {
            // Fallback: extrai do XML (para instancias sem a coluna ainda)
            $rows = Capsule::table('mod_nfse_nacional')
                ->whereNotNull('xml_enviado')
                ->pluck('xml_enviado');
            foreach ($rows as $xml) {
                if (preg_match('/<nDPS>(\d+)<\/nDPS>/', $xml, $m)) {
                    $n = (int)$m[1];
                    if ($n > $maxNdps) { $maxNdps = $n; }
                }
            }
        }

        // Proximo numero = max entre: (offset configurado), (ultimo emitido + 1)
        return max($offset, $maxNdps + 1);
    }

    private function addNoteToInvoice($invoiceId, $numeroNfse)
    {
        $notes = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('notes');
        $note  = 'NFS-e No ' . $numeroNfse . ' emitida em ' . date('d/m/Y H:i') . ' (NFSe Nacional)';
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array(
            'notes' => ($notes ? $notes . "\n" : '') . $note,
        ));
    }

    public function log($tipo, $acao, $msg, $dados = null, $invoiceId = null)
    {
        try {
            Capsule::table('mod_nfse_nacional_log')->insert(array(
                'invoice_id' => $invoiceId,
                'tipo'       => $tipo,
                'acao'       => $acao,
                'mensagem'   => $msg,
                'dados'      => $dados ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ));
        } catch (Exception $e) {
            // silencia
        }
    }
}

function now() { return date('Y-m-d H:i:s'); }
