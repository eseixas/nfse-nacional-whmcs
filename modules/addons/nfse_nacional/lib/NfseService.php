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
require_once __DIR__ . '/NfseEmissionPolicy.php';
require_once __DIR__ . '/NfseStorage.php';

use WHMCS\Database\Capsule;

class NfseAlreadyEmittedException extends \Exception
{
}

class NfseService
{
    private $config;
    private $certMgr;
    private $builder;
    private $api;

    public function __construct($config)
    {
        $this->config  = $config;
        $this->certMgr = new CertManager((array)$config);
        $this->builder = new NfseXmlBuilder($config);
    }

    private function debugEnabled(): bool
    {
        return !empty($this->config['debug_ativo']);
    }

    private function debugDir(): string
    {
        $dir = NfseStorage::debugDir((array)$this->config);
        NfseStorage::protectDir($dir);
        return $dir;
    }

    private function ts(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Serializa emissoes da mesma fatura (GET_LOCK). Evita dois POSTs com DPS distintas.
     */
    private function withInvoiceLock(int $invoiceId, callable $fn)
    {
        $name = 'nfse_nacional_inv_' . $invoiceId;
        $rows = Capsule::select('SELECT GET_LOCK(?, 60) AS acquired', [$name]);
        $first = $rows[0] ?? null;
        if (is_object($first)) {
            $acquired = (int)($first->acquired ?? 0);
        } elseif (is_array($first)) {
            $acquired = (int)($first['acquired'] ?? 0);
        } else {
            $acquired = 0;
        }
        if ($acquired !== 1) {
            return [
                'success' => false,
                'message' => 'Emissao ja em andamento para a fatura #' . $invoiceId . '. Aguarde e tente novamente.',
            ];
        }
        try {
            return $fn();
        } finally {
            Capsule::select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function debugWrite(string $filename, string $content): void
    {
        if ($this->debugEnabled()) {
            file_put_contents($this->debugDir() . '/' . $filename, $content);
        }
    }

    private function debugAppend(string $filename, string $content): void
    {
        if ($this->debugEnabled()) {
            $path = $this->debugDir() . '/' . $filename;
            file_put_contents($path, @file_get_contents($path) . $content);
        }
    }

    private function getApi()
    {
        if (!isset($this->api)) {
            $this->api = new NfseApiClient(
                $this->config['ambiente'] ?? 'Producao Restrita (Testes)',
                $this->certMgr->getCertPath(),
                $this->certMgr->getPassword()
            );
        }
        return $this->api;
    }

    // --- Emissao -------------------------------------------------------------

    public function emitirParaFatura($invoiceId, array $options = array())
    {
        $invoiceId = (int)$invoiceId;
        try {
            return $this->withInvoiceLock($invoiceId, function () use ($invoiceId, $options) {
                return $this->emitirParaFaturaLocked($invoiceId, $options);
            });
        } catch (NfseAlreadyEmittedException $e) {
            return array('success' => false, 'message' => $e->getMessage());
        } catch (\Throwable $e) {
            $this->log('error', 'emissao', 'Excecao: ' . $e->getMessage(),
                array('class' => get_class($e)), $invoiceId);
            return array('success' => false, 'message' => 'Erro interno: ' . $e->getMessage());
        }
    }

    private function emitirParaFaturaLocked(int $invoiceId, array $options): array
    {
        $allowUnpaid = !empty($options['allow_unpaid']);

        $certStatus = $this->certMgr->getStatus();
        if ($certStatus['state'] === 'missing') {
            return array('success' => false,
                'message' => 'Certificado digital nao configurado. Acesse Addons > NFS-e > Certificado Digital.');
        }
        if (!$this->certMgr->isReady()) {
            return array('success' => false,
                'message' => $certStatus['error'] ?: 'Certificado digital indisponivel para emissao.');
        }

        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) {
            return array('success' => false, 'message' => 'Fatura #' . $invoiceId . ' nao encontrada.');
        }

        if (!$allowUnpaid && $invoice['status'] !== 'Paid') {
            return array('success' => false,
                'blocked_by_status' => true,
                'message' => 'A fatura #' . $invoiceId . ' nao esta paga (status: ' . $invoice['status'] . ').');
        }

        $client = $this->getClient($invoice['userid']);
        if (!$client) {
            return array('success' => false, 'message' => 'Cliente nao encontrado para a fatura #' . $invoiceId . '.');
        }

        $docError = NfseXmlBuilder::validarDocumentoTomador($client);
        if ($docError) {
            return array('success' => false, 'message' => $docError);
        }

        $certs  = $this->certMgr->read();
        $signer = new NfseSigner($certs);

        $valorIss    = round($invoice['total'] * ((float)($this->config['aliquota_iss'] ?? 2) / 100), 2);
        $hasValorIss = in_array('valor_iss', Capsule::schema()->getColumnListing('mod_nfse_nacional'), true);
        $offset      = max(1, (int)($this->config['ndps_offset'] ?? 1));

        [$recordId, $nDps, $xmlAssinado] = Capsule::transaction(
            function () use ($invoice, $client, $invoiceId, $signer, $valorIss, $hasValorIss, $offset) {
                $existing = Capsule::table('mod_nfse_nacional')
                    ->where('invoice_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();

                $plan = NfseEmissionPolicy::plan($existing, $offset);

                if ($plan['action'] === NfseEmissionPolicy::ABORT_EMITTED) {
                    throw new NfseAlreadyEmittedException(
                        'Ja existe NFS-e emitida para a fatura #' . $invoiceId
                        . ' (Numero: ' . ($existing->numero_nfse ?? '') . ')'
                    );
                }

                if ($plan['action'] === NfseEmissionPolicy::REUSE_XML && $plan['xml'] !== null) {
                    return array($existing->id, $plan['n_dps'], $plan['xml']);
                }

                if ($plan['action'] === NfseEmissionPolicy::REBUILD_SAME_NDPS) {
                    $nDps = (int)$plan['n_dps'];
                } else {
                    $nDps = max(
                        $offset,
                        (int) Capsule::table('mod_nfse_nacional')->lockForUpdate()->max('n_dps') + 1
                    );
                }

                $xmlAssinado = $this->buildAndSignDps($invoice, $client, $nDps, $signer);
                $recordId = $this->persistPendente(
                    $existing,
                    $invoiceId,
                    $invoice,
                    $nDps,
                    $xmlAssinado,
                    $valorIss,
                    $hasValorIss
                );

                return array($recordId, $nDps, $xmlAssinado);
            }
        );

        $this->debugWrite('debug_dps_' . $invoiceId . '.xml', $xmlAssinado);

        $cnpj     = preg_replace('/\D/', '', $this->config['cnpj']);
        $response = $this->getApi()->emitir($xmlAssinado, $cnpj);

        if ($response['success']) {
            $numeroNfse   = $response['numero_nfse']  ?? null;
            $chaveAcesso  = $response['chave_acesso'] ?? null;
            $xmlRetorno   = !empty($response['nfse_xml']) ? $response['nfse_xml'] : $response['raw'];
            $nDfse        = $response['n_dfse'] ?? null;

            $updateData = array(
                'numero_nfse'       => $numeroNfse,
                'codigo_verificacao'=> $chaveAcesso,
                'status'            => 'emitida',
                'xml_retorno'       => $xmlRetorno,
                'mensagem_erro'     => null,
                'emitida_em'        => $this->ts(),
                'updated_at'        => $this->ts(),
            );
            if ($nDfse !== null) {
                $updateData['n_dfse'] = $nDfse;
            }

            Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update($updateData);

            $this->addNoteToInvoice($invoiceId, $numeroNfse ?? 'N/D');
            $this->log('success', 'emissao',
                'NFS-e #' . $numeroNfse . ' emitida para fatura #' . $invoiceId,
                $this->logSafeResponse($response), $invoiceId);

            return array(
                'success' => true,
                'message' => 'NFS-e emitida com sucesso! Numero: ' . ($numeroNfse ?? 'Aguardando'),
                'data'    => $response,
            );
        }

        $rawJson = json_decode($response['raw'] ?? '{}', true);
        $erroE0014 = false;
        $idDpsRetornado = is_array($rawJson) ? ($rawJson['idDPS'] ?? null) : null;
        if (is_array($rawJson)) {
            foreach (($rawJson['erros'] ?? array()) as $err) {
                if (($err['Codigo'] ?? '') === 'E0014') {
                    $erroE0014 = true;
                    break;
                }
            }
        }
        if (!$idDpsRetornado && preg_match('/infDPS Id="([^"]+)"/', $xmlAssinado, $mxId)) {
            $idDpsRetornado = $mxId[1];
        }

        if ($erroE0014 && !empty($idDpsRetornado)) {
            $consultaResp = $this->getApi()->consultarPorIdDps($idDpsRetornado);
            if ($consultaResp['success'] && !empty($consultaResp['numero_nfse'])) {
                $xmlRetorno = !empty($consultaResp['nfse_xml']) ? $consultaResp['nfse_xml'] : $consultaResp['raw'];
                Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
                    'numero_nfse'       => $consultaResp['numero_nfse'],
                    'codigo_verificacao'=> $consultaResp['chave_acesso'] ?? null,
                    'status'            => 'emitida',
                    'xml_retorno'       => $xmlRetorno,
                    'mensagem_erro'     => null,
                    'emitida_em'        => $this->ts(),
                    'updated_at'        => $this->ts(),
                ));
                $this->addNoteToInvoice($invoiceId, $consultaResp['numero_nfse']);
                $this->log('success', 'emissao',
                    'NFS-e #' . $consultaResp['numero_nfse'] . ' recuperada (E0014) para fatura #' . $invoiceId,
                    $this->logSafeResponse($consultaResp), $invoiceId);
                return array(
                    'success' => true,
                    'message' => 'NFS-e ja existente recuperada! Numero: ' . $consultaResp['numero_nfse'],
                    'data'    => $consultaResp,
                );
            }

            Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
                'status'        => 'pendente',
                'mensagem_erro' => 'E0014: DPS ja emitida na SEFIN. A consulta do numero falhou; retente para recuperar.',
                'updated_at'    => $this->ts(),
            ));
            return array(
                'success' => false,
                'message' => 'DPS ja existe na SEFIN (E0014), mas a consulta do numero falhou. Tente novamente para recuperar a nota.',
            );
        }

        Capsule::table('mod_nfse_nacional')->where('id', $recordId)->update(array(
            'status'        => 'erro',
            'xml_retorno'   => $response['raw'],
            'mensagem_erro' => $response['error'],
            'updated_at'    => $this->ts(),
        ));

        $this->log('error', 'emissao',
            'Erro ao emitir NFS-e para fatura #' . $invoiceId . ': ' . $response['error'],
            $this->logSafeResponse($response), $invoiceId);

        return array('success' => false, 'message' => 'Erro ao emitir NFS-e: ' . $response['error']);
    }

    private function buildAndSignDps(array $invoice, array $client, int $nDps, NfseSigner $signer): string
    {
        $xmlDps = $this->builder->buildDps($invoice, $client, $nDps);
        if (!preg_match('/Id="([^"]+)"/', $xmlDps, $m)) {
            throw new \Exception('Id nao encontrado no XML da DPS.');
        }
        $xmlAssinado = $signer->sign($xmlDps, '#' . $m[1]);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xmlAssinado, LIBXML_NONET)) {
            $errs = array_map(function ($e) { return $e->message; }, libxml_get_errors());
            libxml_clear_errors();
            throw new \Exception('XML da DPS invalido: ' . implode('; ', $errs));
        }
        libxml_clear_errors();

        return $xmlAssinado;
    }

    private function persistPendente($existing, int $invoiceId, array $invoice, int $nDps, string $xmlAssinado, $valorIss, bool $hasValorIss): int
    {
        $data = array(
            'client_id'     => $invoice['userid'],
            'valor'         => $invoice['total'],
            'n_dps'         => $nDps,
            'status'        => 'pendente',
            'xml_enviado'   => $xmlAssinado,
            'xml_retorno'   => null,
            'mensagem_erro' => null,
            'updated_at'    => $this->ts(),
        );
        if ($hasValorIss) {
            $data['valor_iss'] = $valorIss;
        }

        if ($existing) {
            Capsule::table('mod_nfse_nacional')->where('id', $existing->id)->update($data);
            return (int)$existing->id;
        }

        $data['invoice_id'] = $invoiceId;
        $data['created_at'] = $this->ts();
        return (int) Capsule::table('mod_nfse_nacional')->insertGetId($data);
    }

    private function logSafeResponse(array $response): array
    {
        return array(
            'success'      => $response['success'] ?? null,
            'http_code'    => $response['http_code'] ?? null,
            'error'        => $response['error'] ?? null,
            'numero_nfse'  => $response['numero_nfse'] ?? null,
            'chave_acesso' => $response['chave_acesso'] ?? null,
            'n_dfse'       => $response['n_dfse'] ?? null,
        );
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

            $chaveAcesso = $record->codigo_verificacao ?? null;
            $chaveValida = !empty($chaveAcesso) && strlen($chaveAcesso) >= 50;

            if (!$chaveValida && !empty($record->xml_retorno)) {
                if (preg_match('/infNFSe\s[^>]*Id="([^"]{50,})"/', $record->xml_retorno, $mx)) {
                    $chaveAcesso = $mx[1]; $chaveValida = true;
                }
            }

            if (!$chaveValida && !empty($record->xml_enviado)) {
                if (preg_match('/infDPS Id="([^"]+)"/', $record->xml_enviado, $mx)) {
                    $consultaApi = $this->getApi()->consultarPorIdDps($mx[1]);
                    if (!empty($consultaApi['chave_acesso']) && strlen($consultaApi['chave_acesso']) >= 50) {
                        $chaveAcesso = $consultaApi['chave_acesso']; $chaveValida = true;
                        $upd = array('codigo_verificacao' => $chaveAcesso);
                        if (!empty($consultaApi['nfse_xml'])) $upd['xml_retorno'] = $consultaApi['nfse_xml'];
                        if (!empty($consultaApi['n_dfse']))   $upd['n_dfse'] = $consultaApi['n_dfse'];
                        try { Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update($upd); } catch (\Exception $ig) {}
                    }
                }
            }

            if (!$chaveValida) {
                return array('success' => false, 'message' =>
                    'Nao foi possivel determinar a chave de acesso. A nota pode ter sido emitida antes da correcao do banco.');
            }

            try { Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update(array('codigo_verificacao' => $chaveAcesso)); } catch (\Exception $ig) {}

            $chaveUrl = preg_match('/^NFS(.{50})$/i', $chaveAcesso, $mxg) ? $mxg[1] : $chaveAcesso;
            $chaveUrl = substr(trim($chaveUrl), 0, 50);
            $getResp  = $this->getApi()->consultarPorChave($chaveUrl);

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

            $debugGet = 'debug_get_nfse_' . $invoiceId . '.txt';
            $this->debugWrite($debugGet, "GET /nfse/{$chaveUrl}\n" .
                "success: " . ($getResp['success'] ? 'true' : 'false') . "\n" .
                "error: " . ($getResp['error'] ?? 'nenhum') . "\n" .
                "http_code: " . ($getResp['http_code'] ?? '?') . "\n" .
                "--- XML (primeiros 2000 chars) ---\n" .
                substr($getXml, 0, 2000) . "\n"
            );

            if (!$getResp['success'] && ($getResp['http_code'] ?? 0) == 404) {
                return array('success' => false, 'message' =>
                    'Nota nao encontrada no servidor (GET 404). A nota pode ter sido emitida em ambiente diferente ou o prazo de cancelamento expirou.');
            }

            if (!empty($getXml) && strpos($getXml, '<cStat>101</cStat>') !== false) {
                try {
                    Capsule::table('mod_nfse_nacional')->where('id', $record->id)->update(array(
                        'status'     => 'cancelada',
                        'updated_at' => $this->ts(),
                    ));
                } catch (\Exception $ig) {}
                $this->log('success', 'cancelamento',
                    'NFS-e #' . $record->numero_nfse . ' ja estava cancelada no servidor. Status atualizado.', array(), $invoiceId);
                return array('success' => true, 'message' =>
                    'NFS-e #' . $record->numero_nfse . ' ja estava cancelada no servidor (cStat=101). Status atualizado no banco.');
            }

            $nDfse = $record->n_dfse ?? '';
            if (empty($nDfse) && !empty($record->xml_retorno)) {
                if (preg_match('/<nDFSe[^>]*>([^<]+)<\/nDFSe>/', $record->xml_retorno, $mn)) {
                    $nDfse = trim($mn[1]);
                }
            }
            if (!empty($getXml)) {
                if (preg_match('/<nDFSe[^>]*>([^<]+)<\/nDFSe>/', $getXml, $mn2)) {
                    $nDfse = trim($mn2[1]);
                    $this->debugAppend($debugGet,  "nDFSe extraido do GET: {$nDfse}\n");
                }
            }
            $xmlCancel   = $this->builder->buildCancelamento($record->numero_nfse, $chaveAcesso, $nDfse);
            $xmlAssinado = $signer->signCancelamento($xmlCancel);

            $this->debugWrite('debug_cancel_' . $invoiceId . '.xml', $xmlAssinado);

            $chaveUrl = preg_match('/^NFS(.{50})$/i', $chaveAcesso, $mxd) ? $mxd[1] : $chaveAcesso;
            $ambienteRaw = $this->config['ambiente'] ?? 'Producao Restrita (Testes)';
            $ambiente    = (strpos($ambienteRaw, '=') !== false)
                ? trim(explode('=', $ambienteRaw)[0]) : trim($ambienteRaw);
            $baseUrl   = ($ambiente === 'producao' || $ambiente === 'Producao')
                ? 'https://sefin.nfse.gov.br/SefinNacional/'
                : 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/';
            $debugUrl  = 'debug_cancel_url_' . $invoiceId . '.txt';
            $this->debugWrite($debugUrl, "Ambiente: {$ambiente}\n" .
                "URL: {$baseUrl}nfse/{$chaveUrl}/eventos\n" .
                "chaveAcesso original: {$chaveAcesso}\n" .
                "chaveSemNFS (50): {$chaveUrl}\n" .
                "nDfse (nProt): {$nDfse}\n" .
                "nNFSe: {$record->numero_nfse}\n"
            );

            $response = $this->getApi()->cancelar($xmlAssinado, $chaveAcesso);

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
                    'updated_at' => $this->ts(),
                ));
                $this->log('success', 'cancelamento',
                    'NFS-e #' . $record->numero_nfse . ' cancelada.', array(), $invoiceId);
                return array('success' => true, 'message' => 'NFS-e #' . $record->numero_nfse . ' cancelada com sucesso.');
            }

            return array('success' => false, 'message' => 'Erro ao cancelar: ' . $response['error']);

        } catch (\Exception $e) {
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
        $inv['items'] = array_map(function ($i) { return (array)$i; }, $items->toArray());

        return $inv;
    }

    private function getClient($id)
    {
        $client = Capsule::table('tblclients')->where('id', $id)->first();
        if (!$client) return null;
        $client = (array)$client;

        $fieldNames = array('CPF/CNPJ', 'CNPJ/CPF', 'CPF ou CNPJ', 'CNPJ', 'CPF');
        $cfRows = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfieldsvalues.relid', $id)
            ->where('tblcustomfields.type', 'client')
            ->whereNotNull('tblcustomfieldsvalues.value')
            ->where('tblcustomfieldsvalues.value', '!=', '')
            ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
            ->get();

        $taxId = '';
        $priority = array_flip(array_map('strtolower', $fieldNames));
        $bestRank = PHP_INT_MAX;
        foreach ($cfRows as $row) {
            $nameKey = strtolower(trim($row->fieldname));
            if (!isset($priority[$nameKey])) {
                continue;
            }
            $rank = $priority[$nameKey];
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $taxId = trim($row->value);
            }
        }

        $client['tax_id'] = $taxId;

        return $client;
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
                'created_at' => $this->ts(),
                'updated_at' => $this->ts(),
            ));
        } catch (\Exception $e) {
            // silencia
        }
    }
}
