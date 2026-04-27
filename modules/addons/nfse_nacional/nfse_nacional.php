<?php
/**
 * NFS-e Belo Horizonte - Addon WHMCS
 * Emissao de Nota Fiscal de Servico Eletronica
 * Padrao: NFSe Nacional SPED v1.00 | API REST SefinNacional
 *
 * @version 1.3.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// --- Metadados ----------------------------------------------------------------

function nfse_nacional_config()
{
    return [
        'name'        => 'NFSE Nacional',
        'description' => 'Emissao de NFS-e via API REST NFSe Nacional (SefinNacional SPED v1.00)',
        'version'     => '1.3',
        'author'      => '',
        'language'    => 'portuguese-br',
        'fields'      => [
            // -- Prestador --------------------------------------------------
            'cnpj' => [
                'FriendlyName' => 'CNPJ do Prestador',
                'Type'         => 'text',
                'Size'         => '20',
                'Description'  => 'Somente numeros. Ex: 00000000000000',
            ],
            'im' => [
                'FriendlyName' => 'Inscricao Municipal',
                'Type'         => 'text',
                'Size'         => '20',
                'Description'  => 'Inscricao Municipal cadastrada na Prefeitura',
            ],
            'razao_social' => [
                'FriendlyName' => 'Razao Social',
                'Type'         => 'text',
                'Size'         => '60',
            ],
            // -- Tributacaoo -------------------------------------------------
            'regime_tributario' => [
                'FriendlyName' => 'Regime Tributario',
                'Type'         => 'dropdown',
                'Options'      => '1=Simples Nacional,2=Simples Nacional - Excesso,3=Normal',
                'Default'      => '1',
            ],
            'aliquota_iss' => [
                'FriendlyName' => 'Aliquota ISS (%)',
                'Type'         => 'text',
                'Size'         => '8',
                'Default'      => '2.00',
                'Description'  => 'Aliquota do ISS para o seu servico (ex: 2.00)',
            ],
            'optante_simples' => [
                'FriendlyName' => 'Optante pelo Simples Nacional',
                'Type'         => 'dropdown',
                'Options'      => '1=Sim,2=Nao',
                'Default'      => '1',
            ],
            'incentivador_cultural' => [
                'FriendlyName' => 'Incentivador Cultural',
                'Type'         => 'dropdown',
                'Options'      => '1=Sim,2=Nao',
                'Default'      => '2',
            ],
            // -- Servico ----------------------------------------------------
            'item_lista_servico' => [
                'FriendlyName' => 'Item da Lista de Servico (LC 116)',
                'Type'         => 'text',
                'Size'         => '10',
                'Default'      => '01.08',
                'Description'  => 'Ex: 01.08 = Suporte tecnico em informatica (LC 116)',
            ],
            'codigo_tributacao_municipio' => [
                'FriendlyName' => 'Codigo Tributacao Municipio',
                'Type'         => 'text',
                'Size'         => '20',
                'Default'      => '010800001',
                'Description'  => 'Codigo de tributacao do municipio conforme padrao NFSe Nacional',
            ],
            'discriminacao_padrao' => [
                'FriendlyName' => 'Discriminacao Padrao do Servico',
                'Type'         => 'text',
                'Size'         => '120',
                'Default'      => 'Servicos de tecnologia e hospedagem',
                'Description'  => 'Complementado automaticamente com detalhes da fatura',
            ],
            'codigo_municipio_prestacao' => [
                'FriendlyName' => 'Codigo Municipio de Prestacao (IBGE)',
                'Type'         => 'text',
                'Size'         => '10',
                'Default'      => '3106200',
                'Description'  => 'BH = 3106200',
            ],
            // -- Ambiente ---------------------------------------------------
            'ambiente' => [
                'FriendlyName' => 'Ambiente',
                'Type'         => 'dropdown',
                'Options'      => 'producao_restrita=Producao Restrita (Testes),producao=Producao',
                'Default'      => 'producao_restrita',
                'Description'  => 'Use Homologacao ate validar o funcionamento',
            ],
            // -- Emissao ----------------------------------------------------
            'emissao_automatica' => [
                'FriendlyName' => 'Modo de Emissao',
                'Type'         => 'dropdown',
                'Options'      => 'manual=Manual,invoice=Ao emitir a fatura,paid=Ao pagar a fatura',
                'Default'      => 'paid',
                'Description'  => 'Manual: emita pelo dashboard. Ao criar: emite ao gerar a fatura. Ao pagar: emite quando a fatura for paga.',
            ],

            // -- Debug -----------------------------------------------------
            'debug_ativo' => [
                'FriendlyName' => 'Debug',
                'Type'         => 'yesno',
                'Description'  => 'Salva arquivos debug_*.xml e debug_*.txt na pasta do addon. Desative em producao apos homologar.',
            ],
            // -- Numeracao DPS ----------------------------------------------
            'ndps_offset' => [
                'FriendlyName' => 'Numero DPS Inicial',
                'Type'         => 'text',
                'Size'         => '10',
                'Default'      => '1',
                'Description'  => 'Use para evitar conflito com outro sistema emissor. Ex: se o outro sistema ja emitiu ate DPS 500, coloque 501 aqui. O proximo DPS deste addon sera o maior entre este valor e o ultimo ja emitido.',
            ],
            'serie' => [
                'FriendlyName' => 'Serie da NFS-e',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '2',
                'Description'  => 'Numero da serie da DPS. Padrao: 2. Verifique com sua prefeitura a serie correta.',
            ],
        ],
    ];
}

// --- Ativacaoo -----------------------------------------------------------------

function nfse_nacional_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_nfse_nacional')) {
            Capsule::schema()->create('mod_nfse_nacional', function ($t) {
                $t->increments('id');
                $t->integer('invoice_id')->unsigned()->unique();
                $t->integer('client_id')->unsigned();
                $t->string('numero_nfse', 30)->nullable();
                $t->string('codigo_verificacao', 60)->nullable();
                $t->decimal('valor', 10, 2)->default(0);
                $t->decimal('valor_iss', 10, 2)->default(0);
                $t->enum('status', ['pendente', 'emitida', 'cancelada', 'erro'])->default('pendente');
                $t->text('xml_enviado')->nullable();
                $t->text('xml_retorno')->nullable();
                $t->text('mensagem_erro')->nullable();
                $t->timestamp('emitida_em')->nullable();
                $t->timestamps();
            });
        } else {
            // Migracaoo: adiciona colunas que podem nao existir em instalacoeses anteriores
            $cols = Capsule::schema()->getColumnListing('mod_nfse_nacional');
            if (!in_array('valor_iss', $cols)) {
                Capsule::schema()->table('mod_nfse_nacional', function ($t) {
                    $t->decimal('valor_iss', 10, 2)->default(0)->after('valor');
                });
            }
            if (!in_array('codigo_verificacao', $cols)) {
                Capsule::schema()->table('mod_nfse_nacional', function ($t) {
                    $t->string('codigo_verificacao', 60)->nullable()->after('numero_nfse');
                });
            } else {
                // Aumenta tamanho para 60 se ainda for 30 (chave de acesso tem ate 53 chars)
                try {
                    Capsule::statement('ALTER TABLE mod_nfse_nacional MODIFY COLUMN codigo_verificacao VARCHAR(60)');
                } catch (Exception $e) {
                    // Ignora se ja tiver o tamanho correto
                }
            }
        }

        // Tabela de configuracao de servicos por produto WHMCS
        if (!Capsule::schema()->hasTable('mod_nfse_nacional_produtos')) {
            Capsule::schema()->create('mod_nfse_nacional_produtos', function ($t) {
                $t->increments('id');
                $t->integer('product_id')->unsigned()->unique();
                $t->string('item_lista_servico', 10)->nullable();
                $t->string('codigo_tributacao_municipio', 20)->nullable();
                $t->string('codigo_tributacao_nacional', 20)->nullable();
                $t->string('codigo_nbs', 20)->nullable();
                $t->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('mod_nfse_nacional_log')) {
            Capsule::schema()->create('mod_nfse_nacional_log', function ($t) {
                $t->increments('id');
                $t->integer('invoice_id')->unsigned()->nullable();
                $t->enum('tipo', ['info', 'success', 'error', 'warning'])->default('info');
                $t->string('acao', 100);
                $t->text('mensagem');
                $t->text('dados')->nullable();
                $t->timestamps();
            });
        }

        // Cria diretorio seguro para certificados
        $certDir = __DIR__ . '/certs';
        if (!is_dir($certDir)) {
            mkdir($certDir, 0700, true);
            file_put_contents($certDir . '/.htaccess', "Deny from all\n");
            file_put_contents($certDir . '/index.php', "<?php // silence");
        }

        return ['status' => 'success', 'description' => 'Addon NFSE Nacional v1.3 instalado com sucesso!'];
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Erro: ' . $e->getMessage()];
    }
}

function nfse_nacional_deactivate()
{
    return ['status' => 'success', 'description' => 'Desativado. Historico e certificados preservados.'];
}

// --- Interface ----------------------------------------------------------------

function nfse_nacional_output($vars)
{
    // Migration automatica: roda em toda requisicao para garantir schema correto

    // Cria tabela principal se nao existir (independe do activate)
    try {
        if (!Capsule::schema()->hasTable('mod_nfse_nacional')) {
            Capsule::schema()->create('mod_nfse_nacional', function ($t) {
                $t->increments('id');
                $t->integer('invoice_id')->unsigned()->unique();
                $t->integer('client_id')->unsigned();
                $t->string('numero_nfse', 30)->nullable();
                $t->string('n_dfse', 20)->nullable();
                $t->unsignedInteger('n_dps')->nullable();
                $t->string('codigo_verificacao', 60)->nullable();
                $t->decimal('valor', 10, 2)->default(0);
                $t->decimal('valor_iss', 10, 2)->default(0);
                $t->enum('status', ['pendente', 'emitida', 'cancelada', 'erro'])->default('pendente');
                $t->text('xml_enviado')->nullable();
                $t->text('xml_retorno')->nullable();
                $t->text('mensagem_erro')->nullable();
                $t->timestamp('emitida_em')->nullable();
                $t->timestamps();
            });
        }
    } catch (Exception $ignored) {}

    try {
        $cols = Capsule::schema()->getColumnListing('mod_nfse_nacional');
        if (!in_array('codigo_verificacao', $cols)) {
            // Coluna nao existe: adiciona com tamanho correto (53+ chars)
            Capsule::schema()->table('mod_nfse_nacional', function($t) {
                $t->string('codigo_verificacao', 60)->nullable()->after('numero_nfse');
            });
        } else {
            // Coluna existe: garante tamanho 60 (era 30 nas versoes antigas)
            Capsule::statement('ALTER TABLE mod_nfse_nacional MODIFY COLUMN codigo_verificacao VARCHAR(60)');
        }
        if (!in_array('mensagem_erro', $cols)) {
            Capsule::schema()->table('mod_nfse_nacional', function($t) {
                $t->text('mensagem_erro')->nullable()->after('xml_retorno');
            });
        }
        if (!in_array('n_dfse', $cols)) {
            Capsule::schema()->table('mod_nfse_nacional', function($t) {
                $t->string('n_dfse', 20)->nullable()->after('numero_nfse');
            });
        }
        if (!in_array('n_dps', $cols)) {
            Capsule::schema()->table('mod_nfse_nacional', function($t) {
                $t->unsignedInteger('n_dps')->nullable()->after('n_dfse');
            });
        }
    } catch (Exception $ignored) {}

    // Popula n_dps retroativamente para registros existentes (uma vez)
    try {
        $sem_ndps = Capsule::table('mod_nfse_nacional')
            ->whereNull('n_dps')
            ->whereNotNull('xml_enviado')
            ->get(['id', 'xml_enviado']);
        foreach ($sem_ndps as $row) {
            if (preg_match('/<nDPS>(\d+)<\/nDPS>/', $row->xml_enviado, $mx)) {
                Capsule::table('mod_nfse_nacional')->where('id', $row->id)
                    ->update(['n_dps' => (int)$mx[1]]);
            }
        }
    } catch (Exception $ignored) {}

    // Migration: tabela de configuracao por produto (criada aqui para nao depender do activate)
    try {
        if (!Capsule::schema()->hasTable('mod_nfse_nacional_produtos')) {
            Capsule::schema()->create('mod_nfse_nacional_produtos', function ($t) {
                $t->increments('id');
                $t->integer('product_id')->unsigned()->unique();
                $t->string('item_lista_servico', 10)->nullable();
                $t->string('codigo_tributacao_municipio', 20)->nullable();
                $t->string('codigo_tributacao_nacional', 20)->nullable();
                $t->string('codigo_nbs', 20)->nullable();
                $t->timestamps();
            });
        }
    } catch (Exception $ignored) {}

    // Migration: tabela de log
    try {
        if (!Capsule::schema()->hasTable('mod_nfse_nacional_log')) {
            Capsule::schema()->create('mod_nfse_nacional_log', function ($t) {
                $t->increments('id');
                $t->integer('invoice_id')->unsigned()->nullable();
                $t->enum('tipo', ['info', 'success', 'error', 'warning'])->default('info');
                $t->string('acao', 100);
                $t->text('mensagem');
                $t->text('dados')->nullable();
                $t->timestamps();
            });
        }
    } catch (Exception $ignored) {}

    require_once __DIR__ . '/lib/NfseController.php';
    $action = isset($_GET['action']) ? preg_replace('/[^a-z_]/', '', $_GET['action']) : 'dashboard';
    $ctrl   = new NfseController($vars);

    switch ($action) {
        case 'emitir':       $ctrl->emitir();       break;
        case 'upload_cert':  $ctrl->uploadCert();   break;
        case 'exportar':     $ctrl->exportar();     break;
        case 'diagnostico':  $ctrl->diagnostico();  break;
        case 'log':          $ctrl->log();          break;
        case 'ver_nfse':     $ctrl->verNfse();      break;
        case 'download_xml': $ctrl->downloadXml();  break;
        case 'produtos':     $ctrl->produtos();     break;
        default:             $ctrl->dashboard();    break;
    }
}

function nfse_nacional_sidebar($vars)
{
    $l = $vars['modulelink'];
    return '<div class="widget"><div class="widget-header"><h3><i class="fa fa-file-text"></i> NFSE Nacional</h3></div>
        <div class="widget-content"><ul class="list-group">
            <li class="list-group-item"><a href="' . $l . '"><i class="fa fa-dashboard"></i> Dashboard</a></li>
            <li class="list-group-item"><a href="' . $l . '&action=exportar"><i class="fa fa-download"></i> Exportar</a></li>
            <li class="list-group-item"><a href="' . $l . '&action=upload_cert"><i class="fa fa-certificate"></i> Certificado Digital</a></li>
            <li class="list-group-item"><a href="' . $l . '&action=diagnostico"><i class="fa fa-stethoscope"></i> Diagnostico</a></li>
            <li class="list-group-item"><a href="' . $l . '&action=log"><i class="fa fa-list"></i> Log de Emissoes</a></li>
        </ul></div></div>';
}
