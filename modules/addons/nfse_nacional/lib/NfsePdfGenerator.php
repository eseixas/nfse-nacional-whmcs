<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * Gera o DANFSe em PDF a partir do XML autorizado da NFSe Nacional.
 */
class NfsePdfGenerator
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function render($record, string $xml): string
    {
        $xml = $this->normalizeXml($xml);
        $data = $this->extractData($record, $xml);
        $this->loadTcpdf();

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('NFSE Nacional WHMCS');
        $pdf->SetAuthor($this->config['razao_social'] ?? 'NFSE Nacional');
        $pdf->SetTitle('DANFSe ' . $data['numero']);
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(false, 5);
        $pdf->SetHeaderMargin(0);
        $pdf->SetFooterMargin(0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setFooterFont(['helvetica', '', 0]);
        $pdf->AddPage();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->SetDrawColor(45, 45, 45);
        $pdf->SetLineWidth(0.16);
        $pdf->SetTextColor(20, 20, 20);

        $this->renderExampleLayout($pdf, $data);

        return $pdf->Output('', 'S');
    }

    public function normalizeXml(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new \Exception('XML da NFS-e nao disponivel.');
        }

        if (strlen($xml) > 2 && ord($xml[0]) === 0x1f && ord($xml[1]) === 0x8b) {
            $decoded = @gzdecode($xml);
            if ($decoded !== false) {
                $xml = trim($decoded);
            }
        }

        $json = json_decode($xml, true);
        if (is_array($json) && !empty($json['nfseXmlGZipB64'])) {
            $payload = base64_decode($json['nfseXmlGZipB64'], true);
            if ($payload === false) {
                throw new \Exception('XML da NFS-e em base64 invalido.');
            }
            $decoded = @gzdecode($payload);
            $xml = trim($decoded !== false ? $decoded : $payload);
        }

        return $xml;
    }

    private function renderExampleLayout(\TCPDF $pdf, array $data): void
    {
        $this->header($pdf, $data);

        $this->titleBar($pdf, 'EMITENTE DA NFS-e');
        $this->grid($pdf, [
            ['Prestador do Serviço', ''],
            ['CNPJ / CPF / NIF', $data['prestador_doc']],
            ['Inscrição Municipal', $data['prestador_im']],
            ['Telefone', $data['prestador_fone']],
            ['Nome / Nome Empresarial', $data['prestador_nome'], 2],
            ['E-mail', $data['prestador_email'], 2],
            ['Endereço', $data['prestador_endereco'], 2],
            ['Município', $data['prestador_municipio']],
            ['CEP', $data['prestador_cep']],
            ['Simples Nacional na Data de Competência', $data['simples_nacional'], 2],
            ['Regime de Apuração Tributária pelo SN', $data['regime_sn'], 2],
        ], 4, 6.6);

        $this->titleBar($pdf, 'TOMADOR DO SERVIÇO');
        $this->grid($pdf, [
            ['CNPJ / CPF / NIF', $data['tomador_doc']],
            ['Inscrição Municipal', $data['tomador_im']],
            ['Telefone', $data['tomador_fone']],
            ['Nome / Nome Empresarial', $data['tomador_nome']],
            ['E-mail', $data['tomador_email'], 2],
            ['Endereço', $data['tomador_endereco'], 2],
            ['Município', $data['tomador_municipio'], 2],
            ['CEP', $data['tomador_cep'], 2],
        ], 4, 6.2);

        $this->notice($pdf, 'INTERMEDIÁRIO DO SERVIÇO NÃO IDENTIFICADO NA NFS-e');

        $this->titleBar($pdf, 'SERVIÇO PRESTADO');
        $this->grid($pdf, [
            ['Código de Tributação Nacional', $data['c_trib_nac'], 2],
            ['Código de Tributação Municipal', $data['c_trib_mun'], 2],
            ['Local da Prestação', $data['local_prestacao']],
            ['País da Prestação', $data['pais_prestacao']],
            ['NBS', $data['nbs'], 2],
            ['Descrição do Serviço', $data['descricao'], 4, 26.5],
        ], 4, 6.2);

        $this->titleBar($pdf, 'TRIBUTAÇÃO MUNICIPAL');
        $this->grid($pdf, [
            ['Tributação do ISSQN', $data['trib_issqn'], 2],
            ['País Resultado da Prestação do Serviço', $data['pais_prestacao'], 2],
            ['Município de Incidência do ISSQN', $data['municipio_incidencia']],
            ['Regime Especial de Tributação', $data['regime_especial']],
            ['Tipo de Imunidade', '-'],
            ['Suspensão da Exigibilidade do ISSQN', $data['suspensao_issqn']],
            ['Número Processo Suspensão', '-'],
            ['Benefício Municipal', '-'],
            ['Valor do Serviço', $data['valor_servico']],
            ['Desconto Incondicionado', $data['desconto_incondicionado']],
            ['Total Deduções/Reduções', '-'],
            ['Cálculo do BM', '-'],
            ['BC ISSQN', $data['bc_issqn']],
            ['Alíquota Aplicada', $data['aliquota']],
            ['Retenção do ISSQN', $data['retencao_issqn']],
            ['ISSQN Apurado', $data['issqn_apurado']],
        ], 4, 8.1);

        $this->titleBar($pdf, 'TRIBUTAÇÃO FEDERAL');
        $this->grid($pdf, [
            ['IRRF', '-'],
            ['Contribuição Previdenciária - Retida', '-'],
            ['Contribuições Sociais - Retidas', '-'],
            ['Descrição Contrib. Sociais - Retidas', '-'],
            ['PIS - Débito Apuração Própria', '-'],
            ['COFINS - Débito Apuração Própria', '-'],
        ], 4, 8.1);

        $this->titleBar($pdf, 'VALOR TOTAL DA NFS-E');
        $this->grid($pdf, [
            ['Valor do Serviço', $data['valor_servico']],
            ['Desconto Condicionado', $data['desconto_condicionado']],
            ['Desconto Incondicionado', $data['desconto_incondicionado']],
            ['ISSQN Retido', $data['issqn_retido']],
            ['Total das Retenções Federais', $data['retencoes_federais']],
            ['PIS/COFINS - Débito Apur. Própria', '-'],
            ['Valor Líquido da NFS-e', $data['valor_liquido'], 2],
        ], 4, 7.5);

        $this->titleBar($pdf, 'TOTAIS APROXIMADOS DOS TRIBUTOS');
        $this->grid($pdf, [
            ['Federais', $data['tributos_federais']],
            ['Estaduais', $data['tributos_estaduais']],
            ['Municipais', $data['tributos_municipais']],
        ], 3, 7.5);

        $this->titleBar($pdf, 'INFORMAÇÕES COMPLEMENTARES');
        $this->fullField($pdf, 'NBS: ' . ($data['nbs'] ?: '-'), 7.5);
    }

    private function header(\TCPDF $pdf, array $data): void
    {
        $logo = dirname(__DIR__) . '/assets/danfse_nfse_logo.png';
        if (is_file($logo)) {
            $pdf->Image($logo, 5, 5, 39, 7.8, 'PNG');
        }

        $crest = dirname(__DIR__) . '/assets/danfse_bh_crest.png';
        if (is_file($crest)) {
            $pdf->Image($crest, 136.5, 3.9, 8.8, 0, 'PNG');
        }

        $pdf->SetXY(43, 5);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(90, 4.5, 'DANFSe v1.0', 0, 2, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(90, 4.5, 'Documento Auxiliar da NFS-e', 0, 0, 'C');

        $pdf->SetXY(149, 3.2);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->MultiCell(56, 3.8, $this->municipioName($data['prestador_municipio'], 'Prefeitura Municipal'), 0, 'L');
        $pdf->SetFont('helvetica', '', 6);
        $pdf->SetXY(149, 8.4);
        $pdf->MultiCell(56, 3.1, 'Secretaria Municipal de Fazenda - SMFA', 0, 'L');

        $pdf->Line(5, 14.5, 205, 14.5);

        if (!method_exists($pdf, 'write2DBarcode')) {
            throw new \Exception('TCPDF instalado nao possui suporte a QR-Code.');
        }
        $style = [
            'border' => 0,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ];
        $pdf->write2DBarcode($data['qr_url'], 'QRCODE,M', 169, 17, 22, 22, $style, 'N');

        $pdf->SetXY(5, 16.4);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell(130, 3.5, 'Chave de Acesso da NFS-e', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(130, 4, $data['chave'], 0, 1, 'L');

        $pdf->SetXY(154, 40);
        $pdf->SetFont('helvetica', '', 5.4);
        $pdf->MultiCell(51, 2.5, 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e', 0, 'L');

        $pdf->SetY(24);
        $this->grid($pdf, [
            ['Número da NFS-e', $data['numero']],
            ['Competência da NFS-e', $data['competencia']],
            ['Data e Hora da emissão da NFS-e', $data['emissao_nfse'], 2],
            ['Número da DPS', $data['numero_dps']],
            ['Série da DPS', $data['serie_dps']],
            ['Data e Hora da emissão da DPS', $data['emissao_dps'], 2],
        ], 4, 7.5);
        $pdf->SetY(49);
    }

    private function titleBar(\TCPDF $pdf, string $title): void
    {
        $this->ensurePageSpace($pdf, 6);
        $y = $pdf->GetY() + 0.9;
        $pdf->Line(5, $y, 205, $y);
        $pdf->SetXY(5, $y + 0.5);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(200, 4, $title, 0, 1, 'L');
    }

    private function grid(\TCPDF $pdf, array $cells, int $cols, float $defaultHeight): void
    {
        $colW = 200 / $cols;
        $x0 = 5;
        $usedCols = 0;
        $rowHeight = $defaultHeight;
        $row = [];

        foreach ($cells as $cell) {
            $span = (int)($cell[2] ?? 1);
            $height = (float)($cell[3] ?? $defaultHeight);
            if ($usedCols + $span > $cols) {
                $this->drawGridRow($pdf, $row, $colW, $rowHeight, $x0);
                $row = [];
                $usedCols = 0;
                $rowHeight = $defaultHeight;
            }
            $row[] = [$cell[0], $cell[1], $span];
            $usedCols += $span;
            $rowHeight = max($rowHeight, $height);
        }
        if ($row) {
            $this->drawGridRow($pdf, $row, $colW, $rowHeight, $x0);
        }
    }

    private function drawGridRow(\TCPDF $pdf, array $row, float $colW, float $height, float $x0): void
    {
        $this->ensurePageSpace($pdf, $height);
        $y = $pdf->GetY();
        $x = $x0;
        foreach ($row as $cell) {
            [$label, $value, $span] = $cell;
            $w = $colW * $span;
            $pdf->SetXY($x, $y + 0.5);
            $pdf->SetFont('helvetica', 'B', 6.9);
            $pdf->SetTextColor(20, 20, 20);
            $labelWraps = $pdf->GetStringWidth($label) > ($w - 2);
            $pdf->MultiCell($w - 2, 2.6, $label, 0, 'L', false, 1);
            $valueOffset = $labelWraps ? 5.1 : 3.35;
            $pdf->SetXY($x, $y + $valueOffset);
            $pdf->SetFont('helvetica', '', $height > 12 ? 7.2 : 7.8);
            $pdf->SetTextColor(20, 20, 20);
            $displayValue = $this->clean((string)$value);
            if ($displayValue === '' && $label !== 'Prestador do Serviço') {
                $displayValue = '-';
            }
            $pdf->MultiCell($w - 2, max(1, $height - $valueOffset - 0.4), $displayValue, 0, 'L', false, 1);
            $x += $w;
        }
        $pdf->SetY($y + $height);
    }

    private function notice(\TCPDF $pdf, string $text): void
    {
        $this->ensurePageSpace($pdf, 6);
        $y = $pdf->GetY() + 0.9;
        $pdf->Line(5, $y, 205, $y);
        $pdf->SetXY(5, $y + 0.5);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(200, 4, $text, 0, 1, 'C');
        $pdf->Line(5, $y + 5, 205, $y + 5);
        $pdf->SetY($y + 5);
    }

    private function fullField(\TCPDF $pdf, string $text, float $height): void
    {
        $this->ensurePageSpace($pdf, $height);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(200, $height, $text, 0, 'L', false, 1);
    }

    private function ensurePageSpace(\TCPDF $pdf, float $height): void
    {
        if ($pdf->GetY() + $height > 292) {
            $pdf->AddPage();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetY(5);
        }
    }

    private function extractData($record, string $xml): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \Exception('XML da NFS-e invalido para gerar PDF: ' . implode('; ', $errors));
        }
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);
        $infNfse = $this->first($xp, '//*[local-name()="infNFSe"]');
        if (!$infNfse) {
            throw new \Exception('PDF indisponivel: o XML armazenado nao contem uma NFS-e autorizada.');
        }
        $infDps = $this->first($xp, '//*[local-name()="infDPS"]');
        $prest = $this->first($xp, '//*[local-name()="prest"]');
        $toma = $this->first($xp, '//*[local-name()="toma"]');
        $serv = $this->first($xp, '//*[local-name()="serv"]');
        $valores = $this->first($xp, '//*[local-name()="valores"]');

        $idAttr = ($infNfse->attributes instanceof \DOMNamedNodeMap) ? $infNfse->attributes->getNamedItem('Id') : null;
        $chaveRaw = $idAttr ? (string)$idAttr->nodeValue : (string)($record->codigo_verificacao ?? '');
        $chave = $this->normalizeChave($chaveRaw);
        if (strlen($chave) !== 50) {
            throw new \Exception('Chave de acesso ausente ou invalida para gerar o QR-Code do DANFSe.');
        }

        return [
            'chave' => $chave,
            'qr_url' => 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $chave,
            'numero' => $this->firstText($xp, $infNfse, 'nNFSe') ?: (string)($record->numero_nfse ?? '-'),
            'competencia' => $this->formatDate($this->firstText($xp, $infDps, 'dCompet')),
            'emissao_nfse' => $this->formatDateTime($this->firstText($xp, $infNfse, 'dhProc') ?: $this->firstText($xp, $infDps, 'dhEmi') ?: (string)($record->emitida_em ?? '')),
            'numero_dps' => $this->firstText($xp, $infDps, 'nDPS') ?: (string)($record->n_dps ?? '-'),
            'serie_dps' => $this->firstText($xp, $infDps, 'serie') ?: '-',
            'emissao_dps' => $this->formatDateTime($this->firstText($xp, $infDps, 'dhEmi')),
            'prestador_nome' => $this->firstText($xp, $prest, 'xNome') ?: ($this->config['razao_social'] ?? '-'),
            'prestador_doc' => $this->formatDoc($this->firstText($xp, $prest, 'CNPJ') ?: $this->firstText($xp, $prest, 'CPF') ?: ($this->config['cnpj'] ?? '')),
            'prestador_im' => $this->firstText($xp, $prest, 'IM') ?: ($this->config['im'] ?? '-'),
            'prestador_fone' => $this->firstText($xp, $prest, 'fone') ?: '-',
            'prestador_email' => $this->firstText($xp, $prest, 'email') ?: '-',
            'prestador_endereco' => $this->address($xp, $prest),
            'prestador_municipio' => $this->municipio($xp, $prest),
            'prestador_cep' => $this->formatCep($this->firstText($xp, $prest, 'CEP')),
            'simples_nacional' => $this->mapSimples($this->firstText($xp, $prest, 'opSimpNac')),
            'regime_sn' => $this->mapRegimeSn($this->firstText($xp, $prest, 'regApTribSN')),
            'tomador_nome' => $this->firstText($xp, $toma, 'xNome') ?: '-',
            'tomador_doc' => $this->formatDoc($this->firstText($xp, $toma, 'CNPJ') ?: $this->firstText($xp, $toma, 'CPF') ?: $this->firstText($xp, $toma, 'NIF')),
            'tomador_im' => $this->firstText($xp, $toma, 'IM') ?: '-',
            'tomador_fone' => $this->firstText($xp, $toma, 'fone') ?: '-',
            'tomador_email' => $this->firstText($xp, $toma, 'email') ?: '-',
            'tomador_endereco' => $this->address($xp, $toma),
            'tomador_municipio' => $this->municipio($xp, $toma),
            'tomador_cep' => $this->formatCep($this->firstText($xp, $toma, 'CEP')),
            'c_trib_nac' => $this->firstText($xp, $serv, 'cTribNac') ?: '-',
            'c_trib_mun' => $this->firstText($xp, $serv, 'cTribMun') ?: '-',
            'local_prestacao' => $this->formatMunicipioCode($this->firstText($xp, $serv, 'cLocPrestacao')),
            'pais_prestacao' => $this->firstText($xp, $serv, 'cPaisPrestacao') ?: '-',
            'descricao' => $this->firstText($xp, $serv, 'xDescServ') ?: '-',
            'trib_issqn' => $this->mapTribIssqn($this->firstText($xp, $valores, 'tribISSQN')),
            'municipio_incidencia' => $this->formatMunicipioCode($this->firstText($xp, $valores, 'cMunIncid')),
            'regime_especial' => $this->firstText($xp, $prest, 'regEspTrib') ?: '0',
            'suspensao_issqn' => $this->firstText($xp, $valores, 'tpSusp') ?: 'Não',
            'valor_servico' => $this->money($this->firstText($xp, $valores, 'vServ') ?: (string)($record->valor ?? '')),
            'bc_issqn' => $this->money($this->firstText($xp, $valores, 'vBC')),
            'aliquota' => $this->percent($this->firstText($xp, $valores, 'pAliq')),
            'retencao_issqn' => $this->mapRetencao($this->firstText($xp, $valores, 'tpRetISSQN')),
            'issqn_apurado' => $this->money($this->firstText($xp, $valores, 'vISSQN') ?: (string)($record->valor_iss ?? '')),
            'desconto_condicionado' => $this->money($this->firstText($xp, $valores, 'vDescCond')),
            'desconto_incondicionado' => $this->money($this->firstText($xp, $valores, 'vDescIncond')),
            'issqn_retido' => $this->money($this->firstText($xp, $valores, 'vISSQNRet')),
            'retencoes_federais' => $this->money($this->firstText($xp, $valores, 'vRetFed')),
            'valor_liquido' => $this->money($this->firstText($xp, $valores, 'vLiq') ?: $this->firstText($xp, $valores, 'vServ') ?: (string)($record->valor ?? '')),
            'tributos_federais' => $this->money($this->firstText($xp, $valores, 'vTotTribFed')),
            'tributos_estaduais' => $this->money($this->firstText($xp, $valores, 'vTotTribEst')),
            'tributos_municipais' => $this->money($this->firstText($xp, $valores, 'vTotTribMun')),
            'nbs' => $this->firstText($xp, $serv, 'cNBS') ?: '-',
        ];
    }

    private function loadTcpdf(): void
    {
        if (class_exists('TCPDF')) {
            return;
        }
        $paths = [];
        if (defined('ROOTDIR')) {
            $paths[] = ROOTDIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            $paths[] = ROOTDIR . '/vendor/tcpdf/tcpdf.php';
            $paths[] = ROOTDIR . '/includes/tcpdf/tcpdf.php';
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                require_once $path;
                if (class_exists('TCPDF')) {
                    return;
                }
            }
        }
        throw new \Exception('TCPDF nao encontrado no WHMCS. Nao foi possivel gerar o DANFSe em PDF.');
    }

    private function first(\DOMXPath $xp, string $query): ?\DOMNode
    {
        $nodes = $xp->query($query);
        return ($nodes && $nodes->length) ? $nodes->item(0) : null;
    }

    private function firstText(\DOMXPath $xp, ?\DOMNode $context, string $name): string
    {
        if (!$context) {
            return '';
        }
        $nodes = $xp->query('.//*[local-name()="' . $name . '"]', $context);
        return ($nodes && $nodes->length) ? trim((string)$nodes->item(0)->textContent) : '';
    }

    private function address(\DOMXPath $xp, ?\DOMNode $node): string
    {
        $parts = array_filter([
            $this->firstText($xp, $node, 'xLgr'),
            $this->firstText($xp, $node, 'nro'),
            $this->firstText($xp, $node, 'xCpl'),
            $this->firstText($xp, $node, 'xBairro'),
        ], fn($v) => trim((string)$v) !== '');
        return $parts ? implode(', ', $parts) : '-';
    }

    private function municipio(\DOMXPath $xp, ?\DOMNode $node): string
    {
        $nome = $this->firstText($xp, $node, 'xMun');
        $uf = $this->firstText($xp, $node, 'UF');
        $codigo = $this->firstText($xp, $node, 'cMun');
        if ($nome && $uf) {
            return $nome . ' - ' . $uf;
        }
        return $nome ?: ($codigo ?: '-');
    }

    private function municipioName(string $municipio, string $prefix): string
    {
        $municipio = trim($municipio);
        if ($municipio === '' || $municipio === '-') {
            $municipio = $this->formatMunicipioCode((string)($this->config['codigo_municipio_prestacao'] ?? ''));
        }
        if ($municipio === '' || $municipio === '-') {
            return $prefix;
        }
        $nome = preg_replace('/\s+-\s+[A-Z]{2}$/', '', $municipio);
        return $prefix . ' de ' . $nome;
    }

    private function formatMunicipioCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '-';
        }

        $known = [
            '3106200' => 'Belo Horizonte - MG',
        ];

        return $known[$value] ?? $value;
    }

    private function normalizeChave(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^NFS(.{50})$/i', $value, $m)) {
            return $m[1];
        }
        $digits = preg_replace('/\D/', '', $value);
        return strlen($digits) >= 50 ? substr($digits, -50) : $digits;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function formatDoc(string $doc): string
    {
        $d = preg_replace('/\D/', '', $doc);
        if (strlen($d) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
        }
        if (strlen($d) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
        }
        return $doc ?: '-';
    }

    private function formatCep(string $cep): string
    {
        $d = preg_replace('/\D/', '', $cep);
        return strlen($d) === 8 ? preg_replace('/(\d{5})(\d{3})/', '$1-$2', $d) : ($cep ?: '-');
    }

    private function formatDate(string $date): string
    {
        if (!$date) {
            return '-';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $date;
    }

    private function formatDateTime(string $date): string
    {
        if (!$date) {
            return '-';
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y H:i:s', $ts) : $date;
    }

    private function money(string $value): string
    {
        if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
            return '-';
        }
        return 'R$ ' . number_format((float)str_replace(',', '.', $value), 2, ',', '.');
    }

    private function percent(string $value): string
    {
        if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
            return '-';
        }
        return number_format((float)str_replace(',', '.', $value), 2, ',', '.') . '%';
    }

    private function mapSimples(string $value): string
    {
        return ['1' => 'Não Optante', '2' => 'Optante - MEI', '3' => 'Optante - ME/EPP'][$value] ?? ($value ?: '-');
    }

    private function mapRegimeSn(string $value): string
    {
        return ['1' => 'Regime de apuração pelo Simples Nacional', '2' => 'Simples Nacional - excesso de sublimite', '3' => 'Regime normal'][$value] ?? ($value ?: '-');
    }

    private function mapTribIssqn(string $value): string
    {
        return ['1' => 'Operação tributável', '2' => 'Exportação de serviço', '3' => 'Não incidência', '4' => 'Imunidade'][$value] ?? ($value ?: '-');
    }

    private function mapRetencao(string $value): string
    {
        return ['1' => 'Não Retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário'][$value] ?? ($value ?: '-');
    }
}
