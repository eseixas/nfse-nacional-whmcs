<?php
/**
 * Hook WHMCS - NFSE Nacional
 * Modos de emissao:
 *   manual  - somente pelo Dashboard
 *   invoice - ao criar a fatura (hook InvoiceCreation)
 *   paid    - ao pagar a fatura (hook InvoicePaid)
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

// --- Funcao auxiliar de emissao (usada por ambos os hooks) -------------------

function nfse_nacional_emitir_automatico($invoiceId)
{
    $invoiceId = (int)$invoiceId;
    $config    = nfse_nacional_get_config();

    if (empty($config['cnpj']) || empty($config['im'])) {
        logActivity('[NFSE Nacional] Emissao ignorada - CNPJ ou IM nao configurados. Fatura #' . $invoiceId);
        return;
    }

    if (!file_exists(ROOTDIR . '/modules/addons/nfse_nacional/certs/cert.pfx')) {
        logActivity('[NFSE Nacional] Emissao ignorada - certificado nao enviado. Fatura #' . $invoiceId);
        return;
    }

    // Evita dupla emissao
    $existe = Capsule::table('mod_nfse_nacional')
        ->where('invoice_id', $invoiceId)
        ->whereIn('status', ['emitida', 'pendente'])
        ->exists();

    if ($existe) {
        return;
    }

    try {
        require_once ROOTDIR . '/modules/addons/nfse_nacional/lib/NfseService.php';

        $service = new NfseService($config);
        $result  = $service->emitirParaFatura($invoiceId);

        if ($result['success']) {
            logActivity('[NFSE Nacional] NFS-e emitida para fatura #' . $invoiceId . '. ' . $result['message']);
        } else {
            logActivity('[NFSE Nacional] FALHA ao emitir NFS-e para fatura #' . $invoiceId . ': ' . $result['message']);
        }
    } catch (\Throwable $e) {
        logActivity('[NFSE Nacional] Excecao (fatura #' . $invoiceId . '): ' . $e->getMessage());
    }
}

// --- Hook: Ao criar fatura (modo invoice) ------------------------------------

add_hook('InvoiceCreation', 1, function ($vars) {
    $config = nfse_nacional_get_config();
    $modo   = nfse_nacional_normalizar_modo($config['emissao_automatica'] ?? '');
    if ($modo !== 'invoice') {
        return;
    }
    nfse_nacional_emitir_automatico($vars['invoiceid'] ?? 0);
});

// --- Hook: Ao pagar fatura (modo paid) ---------------------------------------

add_hook('InvoicePaid', 1, function ($vars) {
    $config = nfse_nacional_get_config();
    $modo   = nfse_nacional_normalizar_modo($config['emissao_automatica'] ?? '');
    if ($modo !== 'paid') {
        return;
    }
    nfse_nacional_emitir_automatico($vars['invoiceid'] ?? 0);
});

// --- Hook: Widget na Pagina de Faturas ---------------------------------------

add_hook('AdminAreaPage', 1, function ($vars) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'invoices.php') === false) return;
    if (($_GET['action'] ?? '') !== 'edit') return;
    if (empty($_GET['id'])) return;

    $invoiceId = (int)$_GET['id'];
    $config    = nfse_nacional_get_config();

    if (empty($config['cnpj'])) return;

    $nfse    = Capsule::table('mod_nfse_nacional')->where('invoice_id', $invoiceId)->first();
    $certOk  = file_exists(ROOTDIR . '/modules/addons/nfse_nacional/certs/cert.pfx');
    $modLink = 'addonmodules.php?module=nfse_nacional';

    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION['nfse_nacional_csrf'])) {
        $_SESSION['nfse_nacional_csrf'] = bin2hex(random_bytes(24));
    }
    $token = $_SESSION['nfse_nacional_csrf'];

    $ambRaw = $config['ambiente'] ?? '';
    $ambKey = (strpos($ambRaw, '=') !== false) ? trim(explode('=', $ambRaw)[0]) : trim($ambRaw);
    $env    = ($ambKey === 'producao') ? 'Producao' : 'Prod. Restrita';

    $modoRaw = $config['emissao_automatica'] ?? '';
    $modo    = nfse_nacional_normalizar_modo($modoRaw);
    $modoLabel = ['manual' => 'Manual', 'invoice' => 'Ao emitir', 'paid' => 'Ao pagar'];

    ob_start();
    ?>
    <div id="nfse-nacional-box" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;margin-bottom:15px;">
        <strong><i class="fa fa-file-text-o"></i> NFSE Nacional</strong>
        <span style="float:right;font-size:11px;color:#888;"><?= $env ?> | <?= $modoLabel[$modo] ?? $modo ?></span>
        <hr style="margin:8px 0;">
        <?php if ($nfse && $nfse->status === 'emitida'): ?>
            <span class="label label-success">Emitida</span>
            NFS-e <strong>No <?= htmlspecialchars($nfse->n_dps ?? $nfse->numero_nfse) ?></strong>
            <?php if ($nfse->codigo_verificacao): ?> | Cod. Verif.: <code><?= htmlspecialchars($nfse->codigo_verificacao) ?></code><?php endif; ?>
            <br><small class="text-muted">em <?= $nfse->emitida_em ?></small>
            <br><br>
            <a href="addonmodules.php?module=nfse_nacional&action=ver_nfse&invoice_id=<?= $invoiceId ?>"
               class="btn btn-xs btn-info" target="_blank">
                <i class="fa fa-eye"></i> Ver NFS-e
            </a>
            &nbsp;
            <a href="addonmodules.php?module=nfse_nacional&action=download_xml&invoice_id=<?= $invoiceId ?>"
               class="btn btn-xs btn-default">
                <i class="fa fa-download"></i> Baixar XML
            </a>
        <?php elseif ($nfse && $nfse->status === 'cancelada'): ?>
            <span class="label label-default">Cancelada</span>
            NFS-e No <?= htmlspecialchars($nfse->n_dps ?? $nfse->numero_nfse ?? '-') ?> foi cancelada.
        <?php elseif ($nfse && $nfse->status === 'pendente'): ?>
            <span class="label label-warning">Pendente</span>
            Aguardando processamento da prefeitura.
            <form method="post" action="<?= $modLink ?>&action=emitir" style="display:inline">
                <input type="hidden" name="nfse_csrf_token" value="<?= $token ?>">
                <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                <button type="submit" class="btn btn-xs btn-warning"><i class="fa fa-refresh"></i> Verificar / Retentar</button>
            </form>
        <?php elseif ($nfse && $nfse->status === 'erro'): ?>
            <span class="label label-danger">Erro</span>
            <?= htmlspecialchars(mb_substr($nfse->mensagem_erro ?? '', 0, 100, 'UTF-8')) ?>
            <form method="post" action="<?= $modLink ?>&action=emitir" style="display:inline">
                <input type="hidden" name="nfse_csrf_token" value="<?= $token ?>">
                <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                <button type="submit" class="btn btn-xs btn-warning"><i class="fa fa-refresh"></i> Retentar</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= $modLink ?>&action=emitir">
                <input type="hidden" name="nfse_csrf_token" value="<?= $token ?>">
                <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                <button type="submit" class="btn btn-sm btn-primary" <?= $certOk ? '' : 'disabled title="Configure o certificado digital"' ?>>
                    <i class="fa fa-paper-plane"></i> Emitir NFS-e
                </button>
                <?php if (!$certOk): ?>
                <a href="addonmodules.php?module=nfse_nacional&action=upload_cert" class="btn btn-sm btn-warning">
                    <i class="fa fa-certificate"></i> Configurar Certificado
                </a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();

    return ['jquerycode' => '
        $(document).ready(function() {
            var box = ' . json_encode($html) . ';
            var inserted = false;
            // Tenta inserir apos o cabecalho da pagina (WHMCS 7/8)
            var $header = $("h2.pagetitle, .content-header h1").first().closest(".row,.page-header");
            if ($header.length) {
                $header.after(box);
                inserted = true;
            }
            // Tenta inserir antes da tabela principal da fatura (WHMCS 9+)
            if (!inserted) {
                var $invoiceTable = $("#tabInvoiceDetails, .invoice-details, form[action*=invoices] .tab-content").first();
                if ($invoiceTable.length) {
                    $invoiceTable.before(box);
                    inserted = true;
                }
            }
            // Fallback para qualquer area de conteudo
            if (!inserted) {
                $(".content-area, #main-body, .main-content, #contentarea").first().prepend(box);
            }
        });
    '];
});

// --- Helpers -----------------------------------------------------------------

function nfse_nacional_normalizar_modo(string $valor): string
{
    // WHMCS dropdown retorna "key=label" ou apenas "key"
    if (strpos($valor, '=') !== false) {
        $valor = trim(explode('=', $valor)[0]);
    }
    $valor = trim($valor);

    // Compatibilidade com valor antigo 'on' (yesno -> equivale a paid)
    if ($valor === 'on' || $valor === '') {
        return 'manual';
    }

    return in_array($valor, ['manual', 'invoice', 'paid']) ? $valor : 'manual';
}

function nfse_nacional_get_config(): array
{
    return Capsule::table('tbladdonmodules')
        ->where('module', 'nfse_nacional')
        ->pluck('value', 'setting')
        ->toArray();
}
