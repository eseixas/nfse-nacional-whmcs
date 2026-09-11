<?php
/**
 * Testes sem WHMCS/PHPUnit: php tests/run.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        return strlen($string);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nfse_whmcs_root';
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
if (!defined('ROOTDIR')) {
    define('ROOTDIR', $root);
}

require_once dirname(__DIR__) . '/modules/addons/nfse_nacional/lib/NfseEmissionPolicy.php';
require_once dirname(__DIR__) . '/modules/addons/nfse_nacional/lib/NfseStorage.php';
require_once dirname(__DIR__) . '/modules/addons/nfse_nacional/lib/NfseXmlBuilder.php';

$failed = 0;
$passed = 0;

function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK   {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL {$msg}\n";
}

function row(array $data): object
{
    return (object)$data;
}

// --- 1. Idempotencia -------------------------------------------------------

$plan = NfseEmissionPolicy::plan(null, 1);
check($plan['action'] === NfseEmissionPolicy::ALLOCATE_NEW, 'primeira emissao aloca n_dps novo');

$plan = NfseEmissionPolicy::plan(row([
    'status' => 'emitida',
    'n_dps' => 10,
    'xml_enviado' => '<DPS/>',
    'numero_nfse' => '99',
]), 1);
check($plan['action'] === NfseEmissionPolicy::ABORT_EMITTED, 'ja emitida aborta');

$plan = NfseEmissionPolicy::plan(row([
    'status' => 'pendente',
    'n_dps' => 7,
    'xml_enviado' => '<DPS Id="DPS7"/>',
]), 1);
check($plan['action'] === NfseEmissionPolicy::REUSE_XML, 'pendente reusa o mesmo XML');
check($plan['n_dps'] === 7, 'pendente conserva n_dps 7');
check($plan['xml'] === '<DPS Id="DPS7"/>', 'pendente conserva xml_enviado');

$plan = NfseEmissionPolicy::plan(row([
    'status' => 'erro',
    'n_dps' => 7,
    'xml_enviado' => '<DPS Id="DPS7"/>',
]), 1);
check($plan['action'] === NfseEmissionPolicy::REBUILD_SAME_NDPS, 'erro reconstrói com o mesmo n_dps');
check($plan['n_dps'] === 7, 'erro nao minta n_dps novo');

$plan = NfseEmissionPolicy::plan(row([
    'status' => 'cancelada',
    'n_dps' => 7,
    'xml_enviado' => '<DPS/>',
]), 1);
check($plan['action'] === NfseEmissionPolicy::ALLOCATE_NEW, 'cancelada aloca n_dps novo');

$plan = NfseEmissionPolicy::plan(row([
    'status' => 'pendente',
    'n_dps' => 3,
    'xml_enviado' => '',
]), 1);
check($plan['action'] === NfseEmissionPolicy::REBUILD_SAME_NDPS, 'pendente sem XML reconstrói o mesmo n_dps');

// --- 2. Storage fora do webroot --------------------------------------------

$inside = NfseStorage::resolveBase(['storage_path' => '{ROOTDIR}/modules/addons/nfse_nacional/certs']);
check($inside['ok'] === false, 'storage_path dentro do ROOTDIR e recusado');

$empty = NfseStorage::resolveBase(['storage_path' => '']);
check($empty['ok'] === true, 'storage vazio usa default fora do webroot');
check(NfseStorage::isOutsideWebRoot($empty['base']), 'default fica fora do ROOTDIR');
check(!str_contains(str_replace('\\', '/', $empty['base']), '/nfse_whmcs_root/'), 'default nao e filho do ROOTDIR');

$okPath = NfseStorage::resolveBase(['storage_path' => '{ROOTDIR}/../nfse_nacional_data']);
check($okPath['ok'] === true, '{ROOTDIR}/../nfse_nacional_data e aceito');

$canon = NfseStorage::canonicalize(ROOTDIR . '/../nfse_nacional_data');
check(NfseStorage::isOutsideWebRoot($canon), 'canonicalize de ../ sai do webroot');

// --- 3. Product id via packageid -------------------------------------------

$lookup = function (int $hostingId): int {
    $map = [55 => 12, 99 => 8];
    return $map[$hostingId] ?? 0;
};

$pid = NfseXmlBuilder::resolveProductIdFromInvoiceItems([
    ['type' => 'Domain', 'relid' => 12],
    ['type' => 'Hosting', 'relid' => 55],
], $lookup);
check($pid === 12, 'Hosting relid 55 resolve packageid 12');

$pid = NfseXmlBuilder::resolveProductIdFromInvoiceItems([
    ['type' => 'Hosting', 'relid' => 55],
], $lookup);
check($pid === 12, 'somente Hosting usa o lookup');

$pid = NfseXmlBuilder::resolveProductIdFromInvoiceItems([
    ['type' => '', 'relid' => 12],
    ['type' => 'Item', 'relid' => 12],
], $lookup);
check($pid === 0, 'relid de item avulso nao e tratado como product_id');

$pid = NfseXmlBuilder::resolveProductIdFromInvoiceItems([
    ['type' => 'Hosting', 'relid' => 55],
], null);
check($pid === 0, 'sem lookup nao inventa product_id');

// --- 4. totTrib / endereco / xDescServ -------------------------------------

$cfg = [
    'cnpj' => '12345678000199',
    'im' => '1234',
    'codigo_municipio_prestacao' => '3106200',
    'ambiente' => 'Producao Restrita (Testes)',
    'serie' => '2',
    'optante_simples' => 'Optante (ME/EPP)',
    'regime_tributario' => 'Simples Nacional',
    'perc_trib_sn' => '6.00',
    'aliquota_iss' => '2.00',
    'codigo_tributacao_nacional' => '010801',
    'codigo_tributacao_municipio' => '001',
    'codigo_nbs' => '115023000',
    'discriminacao_padrao' => 'Servico padrao',
    'tp_ret_issqn' => 'Nao retido',
    'storage_path' => '{ROOTDIR}/../nfse_nacional_data',
];
$builder = new NfseXmlBuilder($cfg);

$sn = $builder->buildTotTribXml('3', '6.00');
check(str_contains($sn, '<pTotTribSN>6.00</pTotTribSN>'), 'SN emite pTotTribSN');
check(!str_contains($sn, '<pTotTrib>'), 'SN nao emite pTotTrib de nao optante');
check(!str_contains($sn, 'indTotTrib'), 'SN nao emite indTotTrib');

$nao = $builder->buildTotTribXml('1', '6.00');
check(!str_contains($nao, 'pTotTribSN'), 'nao optante nao emite pTotTribSN');
check(str_contains($nao, '<pTotTribMun>2.00</pTotTribMun>'), 'nao optante usa pTotTrib municipal');

$desc = $builder->buildDiscriminacao([
    'notes' => 'NOTA INTERNA NAO DEVE IR PARA A NFSE',
    'items' => [
        ['description' => 'Hospedagem VPS'],
        ['description' => 'Backup'],
    ],
]);
check($desc === 'Hospedagem VPS | Backup', 'xDescServ usa itens da fatura');
check(!str_contains($desc, 'NOTA INTERNA'), 'xDescServ ignora notes internas');

$descPadrao = $builder->buildDiscriminacao(['notes' => 'secreto', 'items' => []]);
check($descPadrao === 'Servico padrao', 'sem itens usa discriminacao_padrao, nao notes');

$invoice = [
    'total' => 100.00,
    'notes' => 'interno',
    'items' => [
        ['type' => 'Hosting', 'relid' => 55, 'description' => 'Plano Cloud'],
    ],
];
$br = [
    'id' => 1,
    'companyname' => 'Cliente LTDA',
    'firstname' => 'Ana',
    'lastname' => 'Silva',
    'tax_id' => '12345678901',
    'country' => 'BR',
    'postcode' => '',
    'city' => 'Belo Horizonte',
    'state' => 'MG',
    'address1' => 'Rua A, 100',
    'address2' => '',
    'email' => 'a@b.com',
];
$xmlBr = $builder->buildDps($invoice, $br, 1);
check(str_contains($xmlBr, '<endNac>'), 'tomador BR usa endNac');
check(str_contains($xmlBr, '<cMun>3106200</cMun>'), 'tomador BR em BH usa IBGE do mapa');
check(str_contains($xmlBr, 'Plano Cloud'), 'DPS leva descricao do item');
check(!str_contains($xmlBr, 'interno'), 'DPS nao leva notes da fatura');
check(str_contains($xmlBr, '<pTotTribSN>'), 'DPS SN contem pTotTribSN');

$us = $br;
$us['country'] = 'US';
$us['city'] = 'New York';
$us['state'] = 'NY';
$us['postcode'] = '10001';
$us['tax_id'] = '12-3456789';
$xmlUs = $builder->buildDps($invoice, $us, 1);
check(str_contains($xmlUs, '<endExt>'), 'tomador estrangeiro usa endExt');
check(!str_contains($xmlUs, '<endNac>'), 'tomador estrangeiro nao usa endNac');
check(str_contains($xmlUs, '<cPais>US</cPais>'), 'endExt informa pais ISO');
check(str_contains($xmlUs, '<xCidade>New York</xCidade>'), 'endExt informa cidade estrangeira');
check(!preg_match('/<endExt>[\s\S]*3106200[\s\S]*<\/endExt>/', $xmlUs), 'endExt nao usa IBGE do prestador');

$invalido = NfseXmlBuilder::validarDocumentoTomador([
    'tax_id' => '1234567890',
    'country' => 'BR',
    'companyname' => 'X',
    'firstname' => '',
    'lastname' => '',
    'id' => 9,
]);
check($invalido !== null, 'CPF com 10 digitos nao e aceito com pad');

echo "\n{$passed} ok, {$failed} falha(s)\n";
exit($failed === 0 ? 0 : 1);
