<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices
 *
 * Vytvoří draft přijaté faktury + insertne items + přepočte sumy.
 * Vendor musí existovat a patřit aktuálnímu tenantovi.
 */
final class CreatePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor musí existovat a patřit tenantovi (anti-cross-tenant injection)
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }

        // Auto-set is_vendor=1 pokud dosud nebyl označen jako dodavatel (může být dosud jen customer).
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $id = $this->repo->createDraft($body, $userId, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.created', $userId, 'purchase_invoice', $id, [
            'vendor_id'    => $body['vendor_id'],
            'document_kind' => $body['document_kind'] ?? 'invoice',
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        return Json::ok($response, $invoice, 201);
    }
}
