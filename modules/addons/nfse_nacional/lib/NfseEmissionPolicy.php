<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * Decisao de retry/idempotencia da emissao (sem I/O).
 *
 * Garante que timeout, clique duplo ou retentativa nao mintam um n_dps novo
 * quando a SEFIN pode ja ter autorizado a DPS anterior.
 */
class NfseEmissionPolicy
{
    public const ABORT_EMITTED = 'abort_emitted';
    public const REUSE_XML = 'reuse_xml';
    public const REBUILD_SAME_NDPS = 'rebuild_same_ndps';
    public const ALLOCATE_NEW = 'allocate_new';

    /**
     * @param object|null $existing Linha de mod_nfse_nacional
     * @return array{action:string,n_dps:?int,xml:?string}
     */
    public static function plan($existing, int $offset = 1): array
    {
        $offset = max(1, $offset);

        if ($existing === null) {
            return self::result(self::ALLOCATE_NEW, null, null);
        }

        $status = (string)($existing->status ?? '');
        $nDps   = isset($existing->n_dps) && $existing->n_dps !== null && $existing->n_dps !== ''
            ? (int)$existing->n_dps
            : 0;
        $xml    = trim((string)($existing->xml_enviado ?? ''));

        if ($status === 'emitida') {
            return self::result(self::ABORT_EMITTED, $nDps > 0 ? $nDps : null, $xml !== '' ? $xml : null);
        }

        if ($status === 'pendente' && $nDps > 0 && $xml !== '') {
            return self::result(self::REUSE_XML, $nDps, $xml);
        }

        if ($status === 'erro' && $nDps > 0) {
            return self::result(self::REBUILD_SAME_NDPS, $nDps, $xml !== '' ? $xml : null);
        }

        if ($status === 'pendente' && $nDps > 0 && $xml === '') {
            return self::result(self::REBUILD_SAME_NDPS, $nDps, null);
        }

        return self::result(self::ALLOCATE_NEW, null, null);
    }

    /**
     * @return array{action:string,n_dps:?int,xml:?string}
     */
    private static function result(string $action, ?int $nDps, ?string $xml): array
    {
        return [
            'action' => $action,
            'n_dps'  => $nDps,
            'xml'    => $xml,
        ];
    }
}
