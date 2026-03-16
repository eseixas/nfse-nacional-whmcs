<?php
/**
 * NfseController
 * Interface administrativa do addon NFSE Nacional
 * Inclui: Dashboard, Upload de Certificado, Emissao Manual, Cancelamento, Log, Exportacao XML
 */

require_once __DIR__ . '/NfseService.php';
require_once __DIR__ . '/NfseApiClient.php';
require_once __DIR__ . '/CertManager.php';
require_once __DIR__ . '/NfseDiagnostico.php';

use WHMCS\Database\Capsule;

class NfseController
{
    private $vars;
    private $config;
    private $modulelink;
    private NfseService $service;
    private CertManager $certMgr;

    public function __construct(array $vars)
    {
        $this->vars       = $vars;
        $this->config     = $vars;
        $this->modulelink = $vars['modulelink'];
        $this->service    = new NfseService($vars);
        $this->certMgr    = new CertManager();
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboard(): void
    {
        $stats = [
            'emitidas'  => Capsule::table('mod_nfse_nacional')->where('status', 'emitida')->count(),
            'erros'     => Capsule::table('mod_nfse_nacional')->where('status', 'erro')->count(),
            'pendentes' => Capsule::table('mod_nfse_nacional')->where('status', 'pendente')->count(),
        ];

        $ultimas = Capsule::table('mod_nfse_nacional')
            ->orderBy('created_at', 'desc')
            ->limit(25)
            ->get();

        $semNfse = Capsule::table('tblinvoices')
            ->leftJoin('mod_nfse_nacional', 'tblinvoices.id', '=', 'mod_nfse_nacional.invoice_id')
            ->whereNull('mod_nfse_nacional.id')
            ->where('tblinvoices.status', 'Paid')
            ->where('tblinvoices.datepaid', '>=', date('Y-m-d', strtotime('-60 days')))
            ->select('tblinvoices.id', 'tblinvoices.userid', 'tblinvoices.total', 'tblinvoices.datepaid')
            ->orderBy('tblinvoices.datepaid', 'desc')
            ->limit(15)
            ->get();

        $certMeta = $this->certMgr->getMeta();
        $certOk   = $this->certMgr->exists();

        $this->renderNav();

        // Alerta CNPJ/IM nao configurado
        if (empty($this->config['cnpj']) || empty($this->config['im'])): ?>
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle"></i>
            <strong>Atencaoo:</strong> Configure o CNPJ e a Inscricaoo Municipal antes de emitir NFS-e.
            <a href="<?= $this->getConfigUrl() ?>" class="btn btn-sm btn-default" style="margin-left:10px;">
                <i class="fa fa-cog"></i> Configuracoes do Addon
            </a>
        </div>
        <?php endif;

        // Alerta certificado
        if (!$certOk): ?>
        <div class="alert alert-danger">
            <i class="fa fa-exclamation-circle"></i>
            <strong>Certificado digital nao configurado!</strong>
            <a href="<?= $this->modulelink ?>&action=upload_cert" class="btn btn-sm btn-warning" style="margin-left:10px;">
                <i class="fa fa-upload"></i> Fazer Upload Agora
            </a>
        </div>
        <?php elseif (!empty($certMeta['valid_to'])):
            $dias = ceil((strtotime($certMeta['valid_to']) - time()) / 86400);
            if ($dias <= 30): ?>
        <div class="alert alert-warning">
            <i class="fa fa-clock-o"></i>
            Certificado vence em <strong><?= $dias ?> dias</strong> (<?= date('d/m/Y', strtotime($certMeta['valid_to'])) ?>).
            <a href="<?= $this->modulelink ?>&action=upload_cert">Renovar</a>
        </div>
        <?php endif; endif;

        // Alerta homologacao
        $ambRaw = $this->config['ambiente'] ?? '';
        $ambKey = (strpos($ambRaw, '=') !== false) ? trim(explode('=', $ambRaw)[0]) : trim($ambRaw);
        if ($ambKey !== 'producao'): ?>
        <div class="alert alert-info">
            <i class="fa fa-flask"></i>
            <strong>Modo Producao Restrita</strong> - Notas sao apenas para testes. Mude para Producao em
            <a href="<?= $this->getConfigUrl() ?>">Configuracoes do Addon</a>.
        </div>
        <?php endif;

        // Info prestador
        if (!empty($this->config['cnpj'])): ?>
        <p class="text-muted" style="margin-bottom:15px;">
            <strong>Prestador:</strong> <?= htmlspecialchars($this->config['razao_social'] ?? '') ?>
            &nbsp;|&nbsp; CNPJ: <?= $this->formatCnpj($this->config['cnpj']) ?>
            &nbsp;|&nbsp; IM: <?= htmlspecialchars($this->config['im'] ?? '') ?>
            &nbsp;|&nbsp; ISS: <?= $this->config['aliquota_iss'] ?? '2.00' ?>%
            &nbsp;|&nbsp; <a href="<?= $this->getConfigUrl() ?>"><i class="fa fa-cog"></i> Configuracoes</a>
        </p>
        <?php endif; ?>

        <!-- Cards estatisticas -->
        <div class="row" style="margin-bottom:20px;">
            <?php foreach ([
                ['emitidas',  'success', 'fa-check-circle', 'Emitidas'],
                ['pendentes', 'warning', 'fa-clock-o',      'Pendentes'],
                ['erros',     'danger',  'fa-times-circle', 'Com Erro'],
            ] as [$k, $style, $icon, $label]): ?>
            <div class="col-sm-3">
                <div class="panel panel-<?= $style ?>">
                    <div class="panel-body text-center">
                        <i class="fa <?= $icon ?> fa-2x text-<?= $style === 'default' ? 'muted' : $style ?>" style="margin-bottom:5px;"></i>
                        <h3 style="margin:5px 0;"><?= $stats[$k] ?></h3>
                        <small><?= $label ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Emissao manual -->
        <div class="panel panel-primary">
            <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-paper-plane"></i> Emitir NFS-e Manualmente</h3></div>
            <div class="panel-body">
                <form method="post" action="<?= $this->modulelink ?>&action=emitir">
                    <input type="hidden" name="nfse_csrf_token" value="<?= $this->getCsrfToken() ?>">
                    <div class="input-group" style="max-width:500px;">
                        <span class="input-group-addon"># Fatura</span>
                        <input type="number" name="invoice_id" class="form-control" placeholder="Ex: 1042" required min="1">
                        <span class="input-group-btn">
                            <button type="submit" class="btn btn-primary" <?= $certOk ? '' : 'disabled title="Configure o certificado primeiro"' ?>>
                                <i class="fa fa-paper-plane"></i> Emitir NFS-e
                            </button>
                        </span>
                    </div>
                    <p class="help-block">A fatura precisa estar com status <strong>Paga</strong>.</p>
                </form>
            </div>
        </div>

        <!-- Faturas sem NFS-e -->
        <?php if (count((array)$semNfse) > 0): ?>
        <div class="panel panel-warning">
            <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-exclamation-triangle"></i> Faturas Pagas Sem NFS-e (ultimos 60 dias)</h3></div>
            <table class="table table-condensed table-hover" style="margin:0;">
                <thead><tr><th>Fatura</th><th>Cliente</th><th>Valor</th><th>Pago em</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($semNfse as $inv): ?>
                <tr>
                    <td><a href="invoices.php?action=edit&id=<?= $inv->id ?>" target="_blank">#<?= $inv->id ?></a></td>
                    <td><?= $inv->userid ?></td>
                    <td>R$ <?= number_format($inv->total, 2, ',', '.') ?></td>
                    <td><?= $inv->datepaid ?></td>
                    <td>
                        <form method="post" action="<?= $this->modulelink ?>&action=emitir" style="display:inline">
                            <input type="hidden" name="nfse_csrf_token" value="<?= $this->getCsrfToken() ?>">
                            <input type="hidden" name="invoice_id" value="<?= $inv->id ?>">
                            <button type="submit" class="btn btn-xs btn-success" <?= $certOk ? '' : 'disabled' ?>>
                                <i class="fa fa-paper-plane"></i> Emitir
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Ultimas emissoes -->
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-list"></i> Ultimas Emissoes</h3></div>
            <table class="table table-striped table-hover" style="margin:0;">
                <thead><tr><th>Fatura</th><th>Cliente</th><th>Valor</th><th>NFS-e No</th><th>Cod. Verif.</th><th>Status</th><th>Emitida em</th><th>Acoes</th></tr></thead>
                <tbody>
                <?php foreach ($ultimas as $n): ?>
                <tr>
                    <td><a href="invoices.php?action=edit&id=<?= $n->invoice_id ?>" target="_blank">#<?= $n->invoice_id ?></a></td>
                    <td><?= $n->client_id ?></td>
                    <td>R$ <?= number_format($n->valor, 2, ',', '.') ?></td>
                    <td><strong><?= $n->numero_nfse ?: '-' ?></strong></td>
                    <td><?= $n->codigo_verificacao ?: '-' ?></td>
                    <td><?= $this->statusBadge($n->status) ?></td>
                    <td><?= $n->emitida_em ?: '-' ?></td>
                    <td>
                        <?php if ($n->status === 'emitida'): ?>
                        <a href="<?= $this->modulelink ?>&action=ver_nfse&invoice_id=<?= $n->invoice_id ?>"
                           class="btn btn-xs btn-info" title="Visualizar XML da NFS-e">
                            <i class="fa fa-eye"></i> Visualizar
                        </a>
                        &nbsp;
                        <a href="<?= $this->modulelink ?>&action=download_xml&invoice_id=<?= $n->invoice_id ?>"
                           class="btn btn-xs btn-default" title="Baixar XML">
                            <i class="fa fa-download"></i> XML
                        </a>
                        <?php elseif ($n->status === 'erro' || $n->status === 'pendente'): ?>
                        <form method="post" action="<?= $this->modulelink ?>&action=emitir" style="display:inline">
                            <input type="hidden" name="nfse_csrf_token" value="<?= $this->getCsrfToken() ?>">
                            <input type="hidden" name="invoice_id" value="<?= $n->invoice_id ?>">
                            <button type="submit" class="btn btn-xs btn-warning" <?= $certOk ? '' : 'disabled' ?>>
                                <i class="fa fa-refresh"></i> Retentar
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (!empty($n->mensagem_erro)): ?>
                        &nbsp;<span class="label label-danger" data-toggle="tooltip"
                            title="<?= htmlspecialchars($n->mensagem_erro) ?>">
                            <i class="fa fa-info-circle"></i> Erro
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!count((array)$ultimas)): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:20px;">Nenhuma emissao registrada ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>$(function(){ $('[data-toggle="tooltip"]').tooltip(); });</script>
        <?php
    }

    // =========================================================================
    // Upload de Certificado
    // =========================================================================

    public function uploadCert(): void
    {
        $flash = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_cert'])) {
            // $this->verifyCsrf() falha com enctype="multipart/form-data": o PHP
            // processa $_FILES antes do WHMCS ler o corpo raw. Usamos CSRF proprio.
            try {
                $this->verifyCsrf();
            } catch (\Exception $e) {
                $flash = '<div class="alert alert-danger"><i class="fa fa-times"></i> ' . $e->getMessage() . '</div>';
                goto render_form;
            }

            $result = $this->certMgr->upload(
                $_FILES['cert_file'] ?? [],
                $_POST['cert_password'] ?? ''
            );

            $flash = $result['success']
                ? '<div class="alert alert-success"><i class="fa fa-check"></i> ' . htmlspecialchars($result['message']) . '</div>'
                : '<div class="alert alert-danger"><i class="fa fa-times"></i> ' . htmlspecialchars($result['message']) . '</div>';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cert'])) {
            $this->verifyCsrf();
            $this->certMgr->delete();
            $flash = '<div class="alert alert-info">Certificado removido.</div>';
        }

        render_form:
        $meta   = $this->certMgr->getMeta();
        $certOk = $this->certMgr->exists();
        $csrf   = $this->getCsrfToken();

        $this->renderNav();
        echo $flash;
        ?>
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-certificate"></i> Certificado Digital A1</h3></div>
            <div class="panel-body">

                <?php if ($certOk && $meta): ?>
                <div class="alert alert-success">
                    <i class="fa fa-check-circle fa-lg"></i>
                    <strong>Certificado configurado e valido</strong><br>
                    <table class="table table-condensed" style="margin:10px 0 0 0;max-width:500px;">
                        <tr><th>Arquivo original:</th><td><?= htmlspecialchars($meta['filename'] ?? '-') ?></td></tr>
                        <tr><th>Titular:</th><td><?= htmlspecialchars($meta['subject'] ?? '-') ?></td></tr>
                        <tr><th>Valido ate:</th><td><?= $meta['valid_to'] ? date('d/m/Y', strtotime($meta['valid_to'])) : '-' ?></td></tr>
                        <tr><th>Enviado em:</th><td><?= $meta['uploaded_at'] ?? '-' ?></td></tr>
                    </table>
                    <form method="post" action="<?= $this->modulelink ?>&action=upload_cert" style="margin-top:10px"
                          onsubmit="return confirm('Confirma a remocaoo do certificado?')">
                        <input type="hidden" name="nfse_csrf_token" value="<?= $this->getCsrfToken() ?>">
                        <input type="hidden" name="delete_cert" value="1">
                        <button type="submit" class="btn btn-xs btn-danger"><i class="fa fa-trash"></i> Remover Certificado</button>
                    </form>
                </div>
                <hr>
                <p class="text-muted">Para substituir, faca o upload de um novo certificado abaixo.</p>
                <?php endif; ?>

                <form method="post" action="<?= $this->modulelink ?>&action=upload_cert" enctype="multipart/form-data">
                    <input type="hidden" name="nfse_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="upload_cert" value="1">

                    <div class="form-group">
                        <label>Arquivo do Certificado (.pfx ou .p12)</label>
                        <input type="file" name="cert_file" class="form-control" accept=".pfx,.p12" required>
                    </div>
                    <div class="form-group">
                        <label>Senha do Certificado</label>
                        <input type="password" name="cert_password" class="form-control" style="max-width:400px;" required
                               placeholder="Senha de protecaoo do arquivo .pfx">
                        <p class="help-block">Armazenada de forma criptografada com AES-256.</p>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-upload"></i> Enviar Certificado
                    </button>
                </form>

                <div class="alert alert-info" style="margin-top:20px;">
                    <i class="fa fa-info-circle"></i>
                    O certificado fica em diretorio protegido por <code>.htaccess</code> - sem acesso HTTP direto.
                    Nunca compartilhe o <code>.pfx</code> ou sua senha.
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Emitir
    // =========================================================================

    public function emitir(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderNav();
            $this->flash('Metodo invalido.', 'danger');
            $this->dashboard();
            return;
        }

        try { $this->verifyCsrf(); } catch (\Exception $e) {
            $this->renderNav(); $this->flash($e->getMessage(), 'danger', true); $this->dashboard(); return;
        }

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            $this->renderNav();
            $this->flash('Numero de fatura invalido.', 'danger');
            $this->dashboard();
            return;
        }

        $result = $this->service->emitirParaFatura($invoiceId);
        $this->renderNav();
        $this->flash($result['message'], $result['success'] ? 'success' : 'danger');
        $this->dashboard();
    }

    // =========================================================================
    // Exportar XML por periodo -> ZIP
    // =========================================================================
    public function exportar(): void
    {
        // POST = gera e envia o ZIP diretamente
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exportar'])) {
            try { $this->verifyCsrf(); } catch (\Exception $e) {
                $this->renderNav(); $this->flash($e->getMessage(), 'danger', true); $this->exportar(); return;
            }
            $this->gerarZip();
            return;
        }

        // GET = exibe formulario + previa do mes atual
        $previewMes = Capsule::table('mod_nfse_nacional')
            ->where('status', 'emitida')
            ->whereNotNull('xml_enviado')
            ->where('emitida_em', '>=', date('Y-m-01 00:00:00'))
            ->where('emitida_em', '<=', date('Y-m-d 23:59:59'))
            ->orderBy('emitida_em')
            ->get();

        $totalMes   = array_sum(array_column((array)$previewMes, 'valor'));
        $qtdMes     = count((array)$previewMes);

        $this->renderNav();
        ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-download"></i> Exportar NFS-e em XML</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted">
                    Selecione o periodo e baixe um <strong>.zip</strong> com os XMLs de todas as
                    NFS-e emitidas no intervalo, alem de um indice em CSV.
                </p>

                <form method="post" action="<?= $this->modulelink ?>&action=exportar">
                    <input type="hidden" name="nfse_csrf_token" value="<?= $this->getCsrfToken() ?>">
                    <input type="hidden" name="exportar" value="1">

                    <div class="row">
                        <div class="col-sm-3">
                            <div class="form-group">
                                <label><i class="fa fa-calendar"></i> Data Inicial</label>
                                <input type="date" name="data_inicio" class="form-control"
                                       value="<?= date('Y-m-01') ?>" required>
                            </div>
                        </div>
                        <div class="col-sm-3">
                            <div class="form-group">
                                <label><i class="fa fa-calendar"></i> Data Final</label>
                                <input type="date" name="data_fim" class="form-control"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-sm-3">
                            <div class="form-group">
                                <label><i class="fa fa-filter"></i> Status</label>
                                <select name="status_filtro" class="form-control">
                                    <option value="emitida">Somente Emitidas</option>
                                    <option value="todos">Todos os status</option>
                                    <option value="cancelada">Somente Canceladas</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-3">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fa fa-file-zip-o"></i> Baixar ZIP com XMLs
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <hr>

                <div class="row" style="margin-bottom:15px;">
                    <div class="col-sm-4">
                        <div class="panel panel-success" style="margin:0;">
                            <div class="panel-body text-center">
                                <strong><?= $qtdMes ?></strong> nota(s) emitida(s)<br>
                                <small class="text-muted">no mes atual</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="panel panel-info" style="margin:0;">
                            <div class="panel-body text-center">
                                <strong>R$ <?= number_format($totalMes, 2, ',', '.') ?></strong><br>
                                <small class="text-muted">faturado no mes</small>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($qtdMes > 0): ?>
                <h4>NFS-e do mes atual - <?= date('m/Y') ?></h4>
                <table class="table table-condensed table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Fatura</th>
                            <th>NFS-e No</th>
                            <th>Cod. Verificacaoo</th>
                            <th>Valor</th>
                            <th>ISS</th>
                            <th>Emitida em</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewMes as $r): ?>
                    <tr>
                        <td><a href="invoices.php?action=edit&id=<?= $r->invoice_id ?>" target="_blank">#<?= $r->invoice_id ?></a></td>
                        <td><strong><?= htmlspecialchars($r->numero_nfse) ?></strong></td>
                        <td><?= htmlspecialchars($r->codigo_verificacao ?: '-') ?></td>
                        <td>R$ <?= number_format($r->valor, 2, ',', '.') ?></td>
                        <td>R$ <?= number_format($r->valor_iss ?? 0, 2, ',', '.') ?></td>
                        <td><?= $r->emitida_em ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="active">
                            <td colspan="3"><strong>Total</strong></td>
                            <td><strong>R$ <?= number_format($totalMes, 2, ',', '.') ?></strong></td>
                            <td><strong>R$ <?= number_format(array_sum(array_column((array)$previewMes, 'valor_iss')), 2, ',', '.') ?></strong></td>
                            <td><?= $qtdMes ?> nota(s)</td>
                        </tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <p class="text-muted"><i class="fa fa-info-circle"></i> Nenhuma NFS-e emitida no mes atual.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Monta e envia o ZIP para download
     */
    private function gerarZip(): void
    {
        $dataInicio   = $_POST['data_inicio']   ?? date('Y-m-01');
        $dataFim      = $_POST['data_fim']      ?? date('Y-m-d');
        $statusFiltro = $_POST['status_filtro'] ?? 'emitida';

        // Validacaoo basica de datas
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            $this->renderNav();
            $this->flash('Datas invalidas.', 'danger');
            $this->exportar();
            return;
        }

        // Busca registros
        $query = Capsule::table('mod_nfse_nacional')
            ->whereNotNull('xml_enviado')
            ->where('emitida_em', '>=', $dataInicio . ' 00:00:00')
            ->where('emitida_em', '<=', $dataFim . ' 23:59:59');

        if ($statusFiltro !== 'todos') {
            $query->where('status', $statusFiltro);
        }

        $registros = $query->orderBy('emitida_em')->get();

        if (!count((array)$registros)) {
            $this->renderNav();
            $this->flash(
                'Nenhuma NFS-e encontrada entre ' . date('d/m/Y', strtotime($dataInicio)) .
                ' e ' . date('d/m/Y', strtotime($dataFim)) . '.',
                'warning'
            );
            $this->exportar();
            return;
        }

        // Cria ZIP temporario
        $zipTemp = tempnam(sys_get_temp_dir(), 'nfse_nacional_');
        $zip     = new \ZipArchive();

        if ($zip->open($zipTemp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->renderNav();
            $this->flash('Erro ao criar o arquivo ZIP. Verifique permissoeses do servidor.', 'danger');
            $this->exportar();
            return;
        }

        $cnpj = preg_replace('/\D/', '', $this->config['cnpj'] ?? '');

        foreach ($registros as $reg) {
            if (empty($reg->xml_enviado)) continue;

            $numeroNfse  = $reg->numero_nfse ?: ('fat' . $reg->invoice_id);
            $dataEmissao = $reg->emitida_em ? date('Ymd', strtotime($reg->emitida_em)) : date('Ymd');
            $nomeArq     = sprintf('NFSe_%s_%s_%s.xml', $cnpj, $numeroNfse, $dataEmissao);

            $zip->addFromString($nomeArq, $reg->xml_enviado);
        }

        // CSV de indice
        $csv = "\xEF\xBB\xBF"; // BOM UTF-8 para abrir corretamente no Excel
        $csv .= "Fatura;NFS-e No;Cod. Verificacao;Valor (R$);ISS (R$);Status;Emitida em\n";
        foreach ($registros as $reg) {
            $csv .= implode(';', [
                '#' . $reg->invoice_id,
                $reg->numero_nfse ?: '-',
                $reg->codigo_verificacao ?: '-',
                number_format($reg->valor, 2, ',', '.'),
                number_format($reg->valor_iss ?? 0, 2, ',', '.'),
                $reg->status,
                $reg->emitida_em ?: '-',
            ]) . "\n";
        }
        $zip->addFromString('_indice.csv', $csv);
        $zip->close();

        // Nome e download
        $periodo = date('Ymd', strtotime($dataInicio)) . '_' . date('Ymd', strtotime($dataFim));
        $zipNome = 'NFSe_BH_' . $cnpj . '_' . $periodo . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipNome . '"');
        header('Content-Length: ' . filesize($zipTemp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($zipTemp);
        unlink($zipTemp);
        exit;
    }

    // =========================================================================
    // Log
    // =========================================================================

    public function log(): void
    {
        $logs = Capsule::table('mod_nfse_nacional_log')
            ->orderBy('created_at', 'desc')
            ->limit(150)
            ->get();

        $this->renderNav();
        ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-list"></i> Log de Emissoes (ultimas 150)</h3>
            </div>
            <table class="table table-condensed table-striped" style="font-size:13px;margin:0;">
                <thead><tr><th>Data/Hora</th><th>Tipo</th><th>Acaoo</th><th>Fatura</th><th>Mensagem</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= $l->created_at ?></td>
                    <td><?= $this->logBadge($l->tipo) ?></td>
                    <td><?= htmlspecialchars($l->acao) ?></td>
                    <td><?= $l->invoice_id ? '#' . $l->invoice_id : '-' ?></td>
                    <td><?= htmlspecialchars(mb_substr($l->mensagem, 0, 120, 'UTF-8')) ?></td>
                    <td>
                        <?php if (!empty($l->dados)): ?>
                        <button class="btn btn-xs btn-default" data-toggle="collapse" data-target="#log<?= $l->id ?>">
                            <i class="fa fa-code"></i>
                        </button>
                        <div id="log<?= $l->id ?>" class="collapse">
                            <pre style="font-size:11px;max-height:200px;overflow:auto;"><?= htmlspecialchars($l->dados) ?></pre>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!count((array)$logs)): ?>
                <tr><td colspan="6" class="text-center text-muted" style="padding:20px;">Nenhum log registrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    // =========================================================================
    // Diagnostico de Conectividade
    // =========================================================================

    public function diagnostico(): void
    {
        $flash = '';

        render_diag:
        $diag   = new NfseDiagnostico();
        $result = $diag->executar();
        $csrf   = $this->getCsrfToken();

        $ok = function(bool $v) {
            return $v
                ? '<span style="color:#3c763d"><i class="fa fa-check-circle"></i> OK</span>'
                : '<span style="color:#a94442"><i class="fa fa-times-circle"></i> FALHA</span>';
        };

        $this->renderNav();
        echo $flash;
        ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-stethoscope"></i> Diagnostico de Conectividade - NFSe Nacional</h3>
            </div>
            <div class="panel-body">


                <div class="row">
                    <div class="col-sm-4">
                        <h4>Servidor</h4>
                        <table class="table table-condensed table-bordered" style="font-size:12px;">
                        <?php foreach ($result['servidor'] as $k => $v): ?>
                            <tr><th><?= htmlspecialchars($k) ?></th><td><?= htmlspecialchars($v) ?></td></tr>
                        <?php endforeach; ?>
                        </table>
                    </div>
                    <div class="col-sm-4">
                        <h4>DNS</h4>
                        <table class="table table-condensed table-bordered" style="font-size:12px;">
                        <?php foreach (['dns_google','dns_producao','dns_homologacao'] as $k): ?>
                            <?php $r = $result[$k]; ?>
                            <tr>
                                <td><?= $ok($r['ok']) ?></td>
                                <td><code style="font-size:10px"><?= $r['host'] ?></code><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['msg']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </table>
                    </div>
                    <div class="col-sm-4">
                        <h4>TCP 443 / HTTPS</h4>
                        <table class="table table-condensed table-bordered" style="font-size:12px;">
                        <?php foreach (['tcp_producao','tcp_homologacao','https_producao','https_homologacao','ssl_producao','ssl_homologacao'] as $k): ?>
                            <?php if (!isset($result[$k])) continue; $r = $result[$k]; ?>
                            <tr>
                                <td><?= $ok($r['ok']) ?></td>
                                <td><code style="font-size:10px"><?= $k ?></code><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['msg']) ?></small>
                                    <?php if (!empty($r['ssl_error'])): ?>
                                    <br><span class="label label-warning">Problema CA</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </table>
                    </div>
                </div>

                <?php
                $httpsOk = ($result['https_producao']['ok'] ?? false) || ($result['https_homologacao']['ok'] ?? false);
                $dnsOk   = ($result['dns_producao']['ok']   ?? false) || ($result['dns_homologacao']['ok']   ?? false);
                $dnsG    = $result['dns_google']['ok'] ?? false;
                ?>

                <?php if (!$dnsG): ?>
                <div class="alert alert-danger"><strong>DNS completamente bloqueado.</strong> Contate o suporte da hospedagem.</div>
                <?php elseif (!$dnsOk): ?>
                <div class="alert alert-danger"><strong>DNS da API NFSe Nacional nao resolve.</strong> Solicite ao suporte: "Liberacao de HTTPS porta 443 para sefin.nfse.gov.br e sefin.producaorestrita.nfse.gov.br."</div>
                <?php elseif (!$httpsOk): ?>
                <div class="alert alert-danger"><strong>Porta 443 bloqueada.</strong> Solicite ao suporte liberacao de saida na porta 443.</div>
                <?php elseif ($httpsOk): ?>
                <div class="alert alert-success"><strong>Tudo OK!</strong> Conectividade funcionando normalmente.</div>
                <?php else: ?>
                <div class="alert alert-success"><strong>Tudo OK!</strong> Conectividade e certificado SSL funcionando.</div>
                <?php endif; ?>

                <a href="<?= $this->modulelink ?>&action=diagnostico" class="btn btn-default"><i class="fa fa-refresh"></i> Atualizar</a>
                <a href="<?= $this->modulelink ?>" class="btn btn-default" style="margin-left:8px;"><i class="fa fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    // =========================================================================
    // Visualizar XML da NFS-e
    // =========================================================================

    public function verNfse(): void
    {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $record = Capsule::table('mod_nfse_nacional')
            ->where('invoice_id', $invoiceId)
            ->first();

        $this->renderNav();

        if (!$record) {
            $this->flash('NFS-e nao encontrada para a fatura #' . $invoiceId, 'warning');
            return;
        }

        $xml = $record->xml_retorno ?: $record->xml_enviado ?: '';

        // Formata XML para exibicao
        $xmlFormatado = '';
        if ($xml) {
            try {
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                if ($dom->loadXML($xml)) {
                    $xmlFormatado = htmlspecialchars($dom->saveXML(), ENT_QUOTES, 'UTF-8');
                } else {
                    $xmlFormatado = htmlspecialchars($xml, ENT_QUOTES, 'UTF-8');
                }
            } catch (Exception $e) {
                $xmlFormatado = htmlspecialchars($xml, ENT_QUOTES, 'UTF-8');
            }
        }

        $chave     = $record->codigo_verificacao ?? '-';
        $numero    = $record->numero_nfse ?? '-';
        $status    = $this->statusBadge($record->status ?? '');
        $emitidaEm = $record->emitida_em ?? '-';
        $valor     = 'R$ ' . number_format((float)($record->valor ?? 0), 2, ',', '.');
        ?>
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-file-text"></i>
                    NFS-e da Fatura #<?= $invoiceId ?>
                    &nbsp;<?= $status ?>
                </h3>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-sm-3"><strong>Numero NFS-e:</strong><br><?= htmlspecialchars($numero) ?></div>
                    <div class="col-sm-3"><strong>Valor:</strong><br><?= $valor ?></div>
                    <div class="col-sm-3"><strong>Emitida em:</strong><br><?= htmlspecialchars($emitidaEm) ?></div>
                    <div class="col-sm-3">
                        <strong>Chave de Acesso:</strong><br>
                        <small style="word-break:break-all;"><?= htmlspecialchars($chave) ?></small>
                    </div>
                </div>

                <?php if ($xmlFormatado): ?>
                <div style="margin-top:15px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                        <strong><i class="fa fa-code"></i> XML da NFS-e</strong>
                        <a href="<?= $this->modulelink ?>&action=download_xml&invoice_id=<?= $invoiceId ?>"
                           class="btn btn-xs btn-default">
                            <i class="fa fa-download"></i> Baixar XML
                        </a>
                    </div>
                    <pre style="max-height:500px;overflow:auto;font-size:11px;background:#f8f8f8;border:1px solid #ddd;padding:10px;border-radius:4px;"><?= $xmlFormatado ?></pre>
                </div>
                <?php else: ?>
                <p class="text-muted" style="margin-top:15px;">XML nao disponivel.</p>
                <?php endif; ?>

                <div style="margin-top:10px;">
                    <a href="<?= $this->modulelink ?>" class="btn btn-default">
                        <i class="fa fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                    <a href="invoices.php?action=edit&id=<?= $invoiceId ?>" class="btn btn-default" target="_blank">
                        <i class="fa fa-external-link"></i> Ver Fatura
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Download XML da NFS-e
    // =========================================================================

    public function downloadXml(): void
    {
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $record = Capsule::table('mod_nfse_nacional')
            ->where('invoice_id', $invoiceId)
            ->first();

        if (!$record) {
            die('NFS-e nao encontrada.');
        }

        $xml  = $record->xml_retorno ?: $record->xml_enviado ?: '';
        $nome = 'nfse_fatura_' . $invoiceId . '_nfse_' . ($record->numero_nfse ?? '0') . '.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Content-Length: ' . strlen($xml));
        header('Cache-Control: no-cache');
        echo $xml;
        exit;
    }

        // =========================================================================
    // Configuracao de Servicos por Produto WHMCS
    // =========================================================================

    public function produtos(): void
    {
        $this->renderNav();

        // Salva configuracao
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_produto'])) {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId > 0) {
                $data = array(
                    'product_id'                  => $productId,
                    'item_lista_servico'           => trim($_POST['item_lista_servico'] ?? ''),
                    'codigo_tributacao_municipio'  => trim($_POST['codigo_tributacao_municipio'] ?? ''),
                    'codigo_tributacao_nacional'   => trim($_POST['codigo_tributacao_nacional'] ?? ''),
                    'codigo_nbs'                   => trim($_POST['codigo_nbs'] ?? ''),
                    'updated_at'                   => now(),
                );
                $exists = Capsule::table('mod_nfse_nacional_produtos')->where('product_id', $productId)->first();
                if ($exists) {
                    Capsule::table('mod_nfse_nacional_produtos')->where('product_id', $productId)->update($data);
                } else {
                    $data['created_at'] = now();
                    Capsule::table('mod_nfse_nacional_produtos')->insert($data);
                }
                $this->flash('Configuracao salva para o produto #' . $productId, 'success');
            }
        }

        // Lista todos os produtos WHMCS
        $produtos  = Capsule::table('tblproducts')->orderBy('name')->get();
        $configs   = Capsule::table('mod_nfse_nacional_produtos')->get();
        $configMap = array();
        foreach ($configs as $cfg) {
            $configMap[$cfg->product_id] = $cfg;
        }

        // Defaults do addon
        $defItem   = $this->config['item_lista_servico'] ?? '01.08';
        $defMun    = $this->config['codigo_tributacao_municipio'] ?? '';
        $defNac    = $this->config['codigo_tributacao_nacional'] ?? '010801';
        $defNbs    = $this->config['codigo_nbs'] ?? '115023000';
        ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">
                    <i class="fa fa-cubes"></i> Configuracao de Codigos de Servico por Produto
                </h3>
            </div>
            <div class="panel-body">
                <p class="text-muted">
                    Personalize os codigos de tributacao para cada produto/servico.
                    Campos em branco usam os valores definidos nas configuracoes gerais do addon.
                </p>
                <div class="alert alert-info" style="font-size:12px;">
                    <strong>Defaults globais:</strong>
                    Item LC116: <code><?= htmlspecialchars($defItem) ?></code> |
                    Cod. Tributacao Municipio: <code><?= htmlspecialchars($defMun) ?></code> |
                    Cod. Tributacao Nacional: <code><?= htmlspecialchars($defNac) ?></code> |
                    NBS: <code><?= htmlspecialchars($defNbs) ?></code>
                </div>

                <?php foreach ($produtos as $p): ?>
                <?php $cfg = $configMap[$p->id] ?? null; ?>
                <form method="post" action="<?= $this->modulelink ?>&action=produtos"
                      style="border:1px solid #ddd;border-radius:4px;padding:12px;margin-bottom:10px;">
                    <input type="hidden" name="salvar_produto" value="1">
                    <input type="hidden" name="product_id" value="<?= $p->id ?>">
                    <div class="row">
                        <div class="col-sm-3">
                            <strong>#<?= $p->id ?> <?= htmlspecialchars($p->name) ?></strong>
                            <?php if ($cfg): ?>
                            <br><span class="label label-success" style="font-size:10px;">Personalizado</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-2">
                            <label style="font-size:11px;">Item LC116</label>
                            <input type="text" name="item_lista_servico" class="form-control input-sm"
                                   value="<?= htmlspecialchars($cfg->item_lista_servico ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($defItem) ?>">
                        </div>
                        <div class="col-sm-2">
                            <label style="font-size:11px;">Cod. Trib. Municipio</label>
                            <input type="text" name="codigo_tributacao_municipio" class="form-control input-sm"
                                   value="<?= htmlspecialchars($cfg->codigo_tributacao_municipio ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($defMun) ?>">
                        </div>
                        <div class="col-sm-2">
                            <label style="font-size:11px;">Cod. Trib. Nacional</label>
                            <input type="text" name="codigo_tributacao_nacional" class="form-control input-sm"
                                   value="<?= htmlspecialchars($cfg->codigo_tributacao_nacional ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($defNac) ?>">
                        </div>
                        <div class="col-sm-2">
                            <label style="font-size:11px;">Cod. NBS</label>
                            <input type="text" name="codigo_nbs" class="form-control input-sm"
                                   value="<?= htmlspecialchars($cfg->codigo_nbs ?? '') ?>"
                                   placeholder="<?= htmlspecialchars($defNbs) ?>">
                        </div>
                        <div class="col-sm-1" style="padding-top:18px;">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fa fa-save"></i>
                            </button>
                        </div>
                    </div>
                </form>
                <?php endforeach; ?>
                <?php if (!count((array)$produtos)): ?>
                <p class="text-muted">Nenhum produto cadastrado no WHMCS.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

        private function renderNav(): void
    {
        $l       = $this->modulelink;
        $current = $_GET['action'] ?? '';

        echo '<h2><i class="fa fa-file-text-o"></i> NFSE Nacional <small>NFSe Nacional SPED v1.00</small></h2>';
        echo '<ul class="nav nav-pills" style="margin-bottom:15px;">';

        $items = [
            ''                      => ['fa-dashboard',   'Dashboard'],
            '&action=exportar'      => ['fa-download',    'Exportar XML'],
            '&action=upload_cert'   => ['fa-certificate', 'Certificado Digital'],
            '&action=log'           => ['fa-list',        'Log'],
            '&action=diagnostico'   => ['fa-stethoscope', 'Diagnostico'],
            '&action=produtos'       => ['fa-cubes',        'Servicos/Produtos'],
        ];

        foreach ($items as $q => $info) {
            $key    = str_replace('&action=', '', $q);
            $active = ($current === $key) ? 'class="active"' : '';
            echo '<li ' . $active . '><a href="' . $l . $q . '"><i class="fa ' . $info[0] . '"></i> ' . $info[1] . '</a></li>';
        }

        echo '</ul>';
    }

    private function flash(string $msg, string $type = 'success', bool $raw = false): void
    {
        $body = $raw ? $msg : htmlspecialchars($msg);
        echo '<div class="alert alert-' . $type . ' alert-dismissible">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . $body . '</div>';
    }

    private function statusBadge(string $s): string
    {
        $map = array(
            'emitida'   => '<span class="label label-success">Emitida</span>',
            'cancelada' => '<span class="label label-default">Cancelada</span>',
            'erro'      => '<span class="label label-danger">Erro</span>',
            'pendente'  => '<span class="label label-warning">Pendente</span>',
        );
        return isset($map[$s]) ? $map[$s] : '<span class="label label-default">' . htmlspecialchars($s) . '</span>';
    }

    private function logBadge(string $t): string
    {
        $map = array(
            'success' => '<span class="label label-success">OK</span>',
            'error'   => '<span class="label label-danger">Erro</span>',
            'warning' => '<span class="label label-warning">Aviso</span>',
        );
        return isset($map[$t]) ? $map[$t] : '<span class="label label-info">Info</span>';
    }

    private function formatCnpj(string $cnpj): string
    {
        $c = preg_replace('/\D/', '', $cnpj);
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $c);
    }

    // --- URL de configuracaoo do addon ----------------------------------------

    private function getConfigUrl(): string
    {
        // modulelink e algo como "addonmodules.php?module=nfse_nacional"
        // ou "https://dominio.com/gerenciamento/addonmodules.php?module=nfse_nacional"
        // Substitu??mos o arquivo .php e tudo depois pelo destino pretendido.
        // configaddonmods.php e a pagina de configuracaoo de addons no WHMCS 7/8.
        return preg_replace('/[^\/]*\.php.*$/', 'configaddonmods.php', $this->modulelink);
    }

    // --- CSRF para multipart/form-data ---------------------------------------

    private function getCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['nfse_nacional_csrf'])) {
            $_SESSION['nfse_nacional_csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['nfse_nacional_csrf'];
    }

    private function verifyCsrf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $tokenPost    = $_POST['nfse_csrf_token'] ?? '';
        $tokenSession = $_SESSION['nfse_nacional_csrf'] ?? '';

        // Regenera sempre antes de lancar excecaoo
        $_SESSION['nfse_nacional_csrf'] = bin2hex(random_bytes(24));

        if (empty($tokenPost) || empty($tokenSession) || !hash_equals($tokenSession, $tokenPost)) {
            throw new \Exception('Sessao expirada ou token invalido. <a href="javascript:history.back()">Volte</a> e tente novamente.');
        }

        // Rotaciona o token apos uso bem-sucedido
        $_SESSION['nfse_nacional_csrf'] = bin2hex(random_bytes(24));
    }
}
