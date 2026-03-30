<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * NfseXmlBuilder
 * Gera o XML da DPS no padrao NFSe Nacional SPED v1.00
 *
 * Baseado no XML real do projeto (NFS31062002...xml) que foi aceito pela API.
 * Gera XML compacto (sem espacos/indentacao) para evitar problemas de parsing.
 *
 * Id da DPS: DPS + cMunEmissor(7) + tpInsc(1) + CNPJ(14) + serie(5) + nDPS(15) = 45 chars
 * tpInsc: 1=CPF, 2=CNPJ
 */
class NfseXmlBuilder
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Gera a DPS sem assinatura.
     * O elemento assinavel eh <infDPS Id="...">.
     * A assinatura eh inserida dentro de <DPS> apos </infDPS> pelo NfseSigner.
     *
     * Formato exato enviado para a API (apenas o <DPS>, sem o envelope <NFSe>):
     *   <?xml version="1.0" encoding="UTF-8"?>
     *   <DPS xmlns="..." versao="1.00"><infDPS Id="...">...</infDPS></DPS>
     */
    public function buildDps($invoice, $client, $nDps)
    {
        $cnpj      = preg_replace('/\D/', '', $this->config['cnpj']);
        $im        = preg_replace('/\D/', '', $this->config['im']);
        $cLocEmi   = preg_replace('/\D/', '', $this->config['codigo_municipio_prestacao'] ?? '3106200');

        // Subtrai 60s para evitar E0008 (clock skew entre servidor WHMCS e Receita Federal)
        $dhEmi     = date('Y-m-d\TH:i:sP', time() - 60);
        $dCompet   = date('Y-m-d', time() - 60);
        $serie     = trim($this->config['serie'] ?? '2') ?: '2';
        $seriePad  = str_pad($serie, 5, '0', STR_PAD_LEFT);
        $nDpsPad   = str_pad($nDps, 15, '0', STR_PAD_LEFT);
        $cLocEmiPad= str_pad($cLocEmi, 7, '0', STR_PAD_LEFT);

        // tpInsc: 1=CPF, 2=CNPJ
        $tpInsc = '2';
        $idDps  = 'DPS' . $cLocEmiPad . $tpInsc . str_pad($cnpj, 14, '0', STR_PAD_LEFT) . $seriePad . $nDpsPad;

        // Ambiente: producao=1, qualquer outro=2 (producao restrita / homologacao)
        $ambNorm = (strpos($this->config['ambiente'] ?? '', '=') !== false)
            ? trim(explode('=', $this->config['ambiente'])[0])
            : trim($this->config['ambiente'] ?? '');
        $tpAmb = ($ambNorm === 'producao') ? '1' : '2';

        // Tributos
        $vServ      = number_format((float)$invoice['total'], 2, '.', '');
        $pTotTribSN = number_format((float)($this->config['perc_trib_sn'] ?? 6), 2, '.', '');
        $opSimpNac  = $this->dropdownVal($this->config['op_simples']       ?? '3');
        $regApTrib  = $this->dropdownVal($this->config['reg_ap_trib_sn']   ?? '1');
        // Codigos de servico: busca config por produto, fallback para config global
        $productId = 0;
        if (!empty($invoice['items'])) {
            foreach ($invoice['items'] as $item) {
                if (!empty($item['relid'])) { $productId = (int)$item['relid']; break; }
            }
        }
        $prodCfg = null;
        if ($productId > 0) {
            try {
                $prodCfg = \WHMCS\Database\Capsule::table('mod_nfse_nacional_produtos')
                    ->where('product_id', $productId)->first();
            } catch (Exception $ignored) {}
        }
        $cTribNac = !empty($prodCfg->codigo_tributacao_nacional)
            ? $prodCfg->codigo_tributacao_nacional
            : ($this->config['c_trib_nac'] ?? '010801');
        $cTribMun = !empty($prodCfg->codigo_tributacao_municipio)
            ? $prodCfg->codigo_tributacao_municipio
            : ($this->config['c_trib_mun'] ?? '003');
        $cNbs = !empty($prodCfg->codigo_nbs)
            ? $prodCfg->codigo_nbs
            : ($this->config['c_nbs'] ?? '115023000');
        $xDescServ  = $this->buildDiscriminacao($invoice);
        $tpRetISSQN = $this->dropdownVal($this->config['tp_ret_issqn'] ?? '1');

        // Tomador
        list($tomadorXml, $cMunToma) = $this->buildTomador($client);

        // Monta XML compacto (sem newlines desnecessarios - igual ao XML real do projeto)
        $x = '<?xml version="1.0" encoding="UTF-8"?>';
        $x .= '<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">';
        $x .= '<infDPS Id="' . $idDps . '">';
        $x .= '<tpAmb>' . $tpAmb . '</tpAmb>';
        $x .= '<dhEmi>' . $dhEmi . '</dhEmi>';
        $x .= '<verAplic>1.00</verAplic>';
        $x .= '<serie>' . $serie . '</serie>';
        $x .= '<nDPS>' . $nDps . '</nDPS>';
        $x .= '<dCompet>' . $dCompet . '</dCompet>';
        $x .= '<tpEmit>1</tpEmit>';
        $x .= '<cLocEmi>' . $cLocEmi . '</cLocEmi>';

        // Prestador
        $x .= '<prest>';
        $x .= '<CNPJ>' . $cnpj . '</CNPJ>';
        $x .= '<IM>' . $im . '</IM>';
        $x .= '<regTrib>';
        $x .= '<opSimpNac>' . $opSimpNac . '</opSimpNac>';
        $x .= '<regApTribSN>' . $regApTrib . '</regApTribSN>';
        $x .= '<regEspTrib>0</regEspTrib>';
        $x .= '</regTrib>';
        $x .= '</prest>';

        // Tomador
        $x .= $tomadorXml;

        // Servico
        $x .= '<serv>';
        $x .= '<locPrest>';
        $x .= '<cLocPrestacao>' . $cLocEmi . '</cLocPrestacao>';
        $x .= '</locPrest>';
        $x .= '<cServ>';
        $x .= '<cTribNac>' . htmlspecialchars($cTribNac, ENT_QUOTES, 'UTF-8') . '</cTribNac>';
        $x .= '<cTribMun>' . htmlspecialchars($cTribMun, ENT_QUOTES, 'UTF-8') . '</cTribMun>';
        $x .= '<xDescServ>' . htmlspecialchars($xDescServ, ENT_QUOTES, 'UTF-8') . '</xDescServ>';
        $x .= '<cNBS>' . htmlspecialchars($cNbs, ENT_QUOTES, 'UTF-8') . '</cNBS>';
        $x .= '</cServ>';
        $x .= '</serv>';

        // Valores
        $x .= '<valores>';
        $x .= '<vServPrest>';
        $x .= '<vServ>' . $vServ . '</vServ>';
        $x .= '</vServPrest>';
        $x .= '<trib>';
        $x .= '<tribMun>';
        $x .= '<tribISSQN>1</tribISSQN>';
        $x .= '<tpRetISSQN>' . $tpRetISSQN . '</tpRetISSQN>';
        $x .= '</tribMun>';
        $x .= '<totTrib>';
        $x .= '<pTotTribSN>' . $pTotTribSN . '</pTotTribSN>';
        $x .= '</totTrib>';
        $x .= '</trib>';
        $x .= '</valores>';

        $x .= '</infDPS>';
        $x .= '</DPS>';

        return $x;
    }

    /**
     * Gera XML de evento de cancelamento
     * POST /nfse/{chaveAcesso}/cancelamento
     */
    /**
     * @param string $nNFSe    Numero sequencial da NFS-e (ex: "1")
     * @param string $chaveAcesso  Id completo do infNFSe (ex: "NFS31062...")
     * @param string $nDFSe    Numero do protocolo de autorizacao (ex: "1095576")
     */
    /**
     * @param string $nNFSe       Numero sequencial da NFS-e (ex: "9")
     * @param string $chaveAcesso Chave completa incluindo prefixo NFS (ex: "NFS31062...")
     * @param string $nDFSe       Protocolo de autorizacao (ex: "1095584")
     */
    public function buildCancelamento($nNFSe, $chaveAcesso = '', $nDFSe = '')
    {
        $cnpj    = preg_replace('/\D/', '', $this->config['cnpj']);
        $ambNorm2 = (strpos($this->config['ambiente'] ?? '', '=') !== false)
            ? trim(explode('=', $this->config['ambiente'])[0])
            : trim($this->config['ambiente'] ?? '');
        $tpAmb   = ($ambNorm2 === 'producao') ? '1' : '2';
        $dhEvento = date('Y-m-d\TH:i:sP', time() - 30);

        // chave SEM prefixo NFS - 50 digitos (formato correto para o XML e para a URL)
        $chaveRaw = $chaveAcesso ?: $nNFSe;
        // Remove prefixo NFS se presente
        $chaveSemNFS = preg_match('/^NFS(.{50})$/i', $chaveRaw, $mx) ? $mx[1] : $chaveRaw;
        // Garante 50 chars
        $chaveSemNFS = str_pad(substr($chaveSemNFS, -50), 50, '0', STR_PAD_LEFT);

        // Id formato: "ID" + tpEvento(6) + chNFSe(50) + nSeqEvento(2) = 60 chars
        $idCancel = 'ID110111' . $chaveSemNFS . '01';

        // nProt = protocolo de autorizacao (nDFSe)
        $protocolo = !empty($nDFSe) ? $nDFSe : $nNFSe;

        $x = '<?xml version="1.0" encoding="UTF-8"?>';
        $x .= '<evCancNFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">';
        $x .= '<infEvento Id="' . $idCancel . '">';
        $x .= '<cOrgao>99</cOrgao>';
        $x .= '<tpAmb>' . $tpAmb . '</tpAmb>';
        $x .= '<CNPJ>' . $cnpj . '</CNPJ>';
        $x .= '<chNFSe>' . htmlspecialchars($chaveSemNFS, ENT_QUOTES, 'UTF-8') . '</chNFSe>';
        $x .= '<dhEvento>' . $dhEvento . '</dhEvento>';
        $x .= '<nSeqEvento>1</nSeqEvento>';
        $x .= '<tpEvento>110111</tpEvento>';
        $x .= '<verEvento>1.00</verEvento>';
        $x .= '<detEvento versao="1.00">';
        $x .= '<descEvento>Cancelamento</descEvento>';
        $x .= '<nProt>' . htmlspecialchars($protocolo, ENT_QUOTES, 'UTF-8') . '</nProt>';
        $x .= '<xJust>Cancelamento solicitado pelo prestador do servico</xJust>';
        $x .= '</detEvento>';
        $x .= '</infEvento>';
        $x .= '</evCancNFSe>';

        return $x;
    }

    // --- Helpers -------------------------------------------------------------

    private function buildDiscriminacao($invoice)
    {
        // Usa notes da fatura (campo Description no WHMCS)
        $desc = trim($invoice['notes'] ?? '');

        if (empty($desc)) {
            $items = array();
            foreach (($invoice['items'] ?? array()) as $item) {
                if (!empty($item['description'])) {
                    $items[] = trim($item['description']);
                }
            }
            $desc = $items
                ? implode(' | ', $items)
                : ($this->config['discriminacao_padrao'] ?? 'Servicos de tecnologia');
        }

        // Limita a 2000 chars (campo xDescServ max 2000 conforme manual)
        return mb_substr($desc, 0, 2000, 'UTF-8');
    }

    /**
     * Retorna [xmlTomador, cMunTomador]
     */
    private function buildTomador($client)
    {
        $doc  = preg_replace('/\D/', '', $client['tax_id'] ?? '');
        $nome = mb_substr(
            trim($client['companyname'] ?: ($client['firstname'] . ' ' . $client['lastname'])),
            0, 150, 'UTF-8'
        );
        $cep    = str_pad(preg_replace('/\D/', '', $client['postcode'] ?? ''), 8, '0', STR_PAD_LEFT);
        // Busca IBGE pelo CEP (mais preciso) e so usa mapa de cidade como fallback
        $ibgeByCep = $this->getCodMunIBGEByCep($cep);
        if ($ibgeByCep) {
            $cMun = $ibgeByCep;
        } else {
            $cMun = $this->getCodMunIBGE($client['city'] ?? '', $client['state'] ?? '');
        }
        $email  = trim($client['email'] ?? '');
        $lgr    = mb_substr(trim($client['address1'] ?? ''), 0, 125, 'UTF-8');
        $nro    = 'S/N';
        $cpl    = mb_substr(trim($client['address2'] ?? ''), 0, 60, 'UTF-8');
        $bairro = mb_substr(trim($client['city'] ?? ''), 0, 60, 'UTF-8');

        // Tenta extrair numero do logradouro
        if (preg_match('/^(.*?)[,\s]+(\d+\S*)\s*$/', $lgr, $m)) {
            $lgr = trim($m[1]);
            $nro = trim($m[2]);
        }

        $x = '<toma>';

        if (strlen($doc) === 14) {
            $x .= '<CNPJ>' . $doc . '</CNPJ>';
        } elseif (strlen($doc) === 11) {
            $x .= '<CPF>' . $doc . '</CPF>';
        } else {
            // Estrangeiro sem documento - usa NIF
            $nif = preg_replace('/[^a-zA-Z0-9]/', '', $doc);
            if (empty($nif)) {
                $nif = 'NAO_INFORMADO';
            }
            $x .= '<NIF>' . htmlspecialchars(substr($nif, 0, 40), ENT_QUOTES, 'UTF-8') . '</NIF>';
            $x .= '<cNaoNIF>3</cNaoNIF>';
        }

        $x .= '<xNome>' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</xNome>';
        $x .= '<end>';
        $x .= '<endNac>';
        $x .= '<cMun>' . $cMun . '</cMun>';
        $x .= '<CEP>' . $cep . '</CEP>';
        $x .= '</endNac>';
        if (!empty($lgr)) {
            $x .= '<xLgr>' . htmlspecialchars($lgr, ENT_QUOTES, 'UTF-8') . '</xLgr>';
            $x .= '<nro>' . htmlspecialchars($nro, ENT_QUOTES, 'UTF-8') . '</nro>';
        }
        if (!empty($cpl)) {
            $x .= '<xCpl>' . htmlspecialchars($cpl, ENT_QUOTES, 'UTF-8') . '</xCpl>';
        }
        if (!empty($bairro)) {
            $x .= '<xBairro>' . htmlspecialchars($bairro, ENT_QUOTES, 'UTF-8') . '</xBairro>';
        }
        $x .= '</end>';

        if (!empty($email)) {
            $x .= '<email>' . htmlspecialchars(substr($email, 0, 80), ENT_QUOTES, 'UTF-8') . '</email>';
        }

        $x .= '</toma>';

        return array($x, $cMun);
    }

    private function dropdownVal($v)
    {
        if (strpos($v, '=') !== false) {
            return trim(explode('=', $v)[0]);
        }
        return trim($v);
    }

    /**
     * Retorna codigo IBGE do municipio consultando o CEP via ViaCEP.
     * Se a consulta falhar, usa mapa estatico como fallback.
     */
    private function getCodMunIBGEByCep($cep)
    {
        // CEP deve ter 8 digitos
        $cepClean = preg_replace('/\D/', '', $cep);
        if (strlen($cepClean) !== 8) {
            return null;
        }

        // Tenta consultar ViaCEP (timeout curto para nao travar emissao)
        $url = 'https://viacep.com.br/ws/' . $cepClean . '/json/';
        $ctx = stream_context_create(array('http' => array('timeout' => 5)));
        $json = @file_get_contents($url, false, $ctx);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (!empty($data['ibge'])) {
                return $data['ibge'];
            }
        }
        return null;
    }

    private function getCodMunIBGE($city, $state)
    {
        // Mapa estatico com principais cidades (ASCII puro, sem acentos)
        $map = array(
            'MG' => array('belo horizonte'=>'3106200','contagem'=>'3118601','betim'=>'3106705',
                'uberlandia'=>'3170206','juiz de fora'=>'3136702','montes claros'=>'3143302',
                'governador valadares'=>'3127701','ipatinga'=>'3131307','sete lagoas'=>'3166600',
                'divinopolis'=>'3121605'),
            'SP' => array('sao paulo'=>'3550308','campinas'=>'3509502','guarulhos'=>'3518800',
                'santo andre'=>'3547809','osasco'=>'3534401','sao bernardo do campo'=>'3548708',
                'santos'=>'3548500','sorocaba'=>'3552205','ribeirao preto'=>'3543402',
                'sao jose dos campos'=>'3549904','mogi das cruzes'=>'3530706',
                'bauru'=>'3506003','jundiai'=>'3525904'),
            'RJ' => array('rio de janeiro'=>'3304557','niteroi'=>'3303302',
                'nova iguacu'=>'3303500','duque de caxias'=>'3301702','sao goncalo'=>'3304904',
                'campos dos goytacazes'=>'3301009','petropolis'=>'3303906'),
            'RS' => array('porto alegre'=>'4314902','caxias do sul'=>'4305108',
                'pelotas'=>'4314407','canoas'=>'4304606','santa maria'=>'4316907',
                'novo hamburgo'=>'4313409','sao leopoldo'=>'4318705'),
            'PR' => array('curitiba'=>'4106902','maringa'=>'4115200','londrina'=>'4113700',
                'foz do iguacu'=>'4108304','cascavel'=>'4104808','sao jose dos pinhais'=>'4125506',
                'colombo'=>'4105805','ponta grossa'=>'4119905','guarapuava'=>'4109401',
                'paranagua'=>'4118204','apucarana'=>'4101408','campo mourao'=>'4103604'),
            'SC' => array('florianopolis'=>'4205407','joinville'=>'4209102',
                'blumenau'=>'4202404','sao jose'=>'4216602','criciuma'=>'4204608',
                'chapeco'=>'4204202','itajai'=>'4207304','jaragua do sul'=>'4208906'),
            'BA' => array('salvador'=>'2927408','feira de santana'=>'2910800',
                'vitoria da conquista'=>'2933307','camacan'=>'2905701','ilheus'=>'2913606'),
            'PE' => array('recife'=>'2611606','caruaru'=>'2604106','olinda'=>'2609600',
                'paulista'=>'2610707','jaboatao dos guararapes'=>'2607901'),
            'CE' => array('fortaleza'=>'2304400','caucaia'=>'2303709','juazeiro do norte'=>'2307304',
                'maracanau'=>'2307650','sobral'=>'2312908'),
            'AM' => array('manaus'=>'1302603','parintins'=>'1303403'),
            'GO' => array('goiania'=>'5208707','aparecida de goiania'=>'5201405',
                'anapolis'=>'5201108','rio verde'=>'5218805'),
            'DF' => array('brasilia'=>'5300108'),
            'ES' => array('vitoria'=>'3205309','vila velha'=>'3205150','cariacica'=>'3201308',
                'serra'=>'3205010','cachoeiro de itapemirim'=>'3200904'),
            'MT' => array('cuiaba'=>'5103403','varzea grande'=>'5108402','sinop'=>'5107206'),
            'MS' => array('campo grande'=>'5002704','dourados'=>'5003702','tres lagoas'=>'5008305'),
            'PA' => array('belem'=>'1501402','ananindeua'=>'1500800','santarem'=>'1506807'),
            'MA' => array('sao luis'=>'2111300','imperatriz'=>'2105302'),
            'PB' => array('joao pessoa'=>'2507507','campina grande'=>'2504009'),
            'RN' => array('natal'=>'2408102','mossoro'=>'2408003'),
            'AL' => array('maceio'=>'2704302','arapiraca'=>'2700300'),
            'PI' => array('teresina'=>'2211001','parnaiba'=>'2207702'),
            'SE' => array('aracaju'=>'2800308','nossa senhora do socorro'=>'2804706'),
            'RO' => array('porto velho'=>'1100205','ji-parana'=>'1100122'),
            'AC' => array('rio branco'=>'1200401'),
            'AP' => array('macapa'=>'1600303'),
            'TO' => array('palmas'=>'1721000','araguaina'=>'1702109'),
            'RR' => array('boa vista'=>'1400100'),
        );

        // Normaliza nome: lowercase + remove acentos via transliteracao simples
        $n = strtolower(trim($city));
        $from = array('a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c','n');
        // Substitui caracteres acentuados codificados em UTF-8
        // Mapeia caracteres acentuados UTF-8 para ASCII via strtr
        // Cada chave e uma sequencia UTF-8 de 2 bytes representada como hex literal
        $pairs = array(
            "\xc3\xa0"=>'a', "\xc3\xa1"=>'a', "\xc3\xa2"=>'a', "\xc3\xa3"=>'a', "\xc3\xa4"=>'a',
            "\xc3\xa8"=>'e', "\xc3\xa9"=>'e', "\xc3\xaa"=>'e', "\xc3\xab"=>'e',
            "\xc3\xac"=>'i', "\xc3\xad"=>'i', "\xc3\xae"=>'i', "\xc3\xaf"=>'i',
            "\xc3\xb2"=>'o', "\xc3\xb3"=>'o', "\xc3\xb4"=>'o', "\xc3\xb5"=>'o',
            "\xc3\xb9"=>'u', "\xc3\xba"=>'u', "\xc3\xbb"=>'u', "\xc3\xbc"=>'u',
            "\xc3\xa7"=>'c', "\xc3\x83"=>'a', "\xc3\x87"=>'c',
            "\xc3\x80"=>'a', "\xc3\x81"=>'a', "\xc3\x82"=>'a',
            "\xc3\x88"=>'e', "\xc3\x89"=>'e', "\xc3\x8a"=>'e',
            "\xc3\x8c"=>'i', "\xc3\x8d"=>'i',
            "\xc3\x92"=>'o', "\xc3\x93"=>'o', "\xc3\x94"=>'o', "\xc3\x95"=>'o',
            "\xc3\x99"=>'u', "\xc3\x9a"=>'u',
        );
        $n = strtr($n, $pairs);
        // Remove qualquer caracter nao ASCII restante
        $n = preg_replace('/[^a-z0-9 \-]/', '', $n);
        $s = strtoupper(trim($state));

        if (isset($map[$s][$n])) {
            return $map[$s][$n];
        }

        // Fallback: codigo IBGE do municipio configurado (prestacao)
        return preg_replace('/\D/', '', $this->config['codigo_municipio_prestacao'] ?? '3106200');
    }
}
