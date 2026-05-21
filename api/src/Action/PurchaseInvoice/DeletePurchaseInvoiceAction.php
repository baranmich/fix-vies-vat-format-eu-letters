<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}
 *
 * Smaže přijatou fakturu vč. items (ON DELETE CASCADE). Pouze draft lze smazat.
 * Vystavené / zaúčtované doklady jsou součástí auditní stopy — používá se cancel.
 */
final class DeletePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        if ($existing['status'] !== 'draft') {
            return Json::error(
                $response,
                'not_deletable',
                'Lze smazat pouze koncepty. Pro storno použijte přechod do stavu „zrušeno".',
                409,
            );
        }

        $this->repo->delete($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.deleted', $user['id'] ?? null, 'purchase_invoice', $id,
            ['varsymbol' => $existing['varsymbol'] ?? null], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true]);
    }
}
