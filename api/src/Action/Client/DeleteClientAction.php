<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/clients/{id}
 * Tvrdé smazání. Selže (409), pokud na klientovi existují faktury nebo zakázky.
 * Pro „soft" odstavení slouží archive endpoint.
 */
final class DeleteClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?');
        $stmt->execute([$id]);
        $invoices = (int) $stmt->fetchColumn();
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM projects WHERE client_id = ?');
        $stmt->execute([$id]);
        $projects = (int) $stmt->fetchColumn();
        if ($invoices > 0 || $projects > 0) {
            return Json::error(
                $response,
                'has_dependencies',
                "Klienta nelze smazat — má {$invoices} faktur a {$projects} zakázek. Místo toho ho archivuj.",
                409,
            );
        }

        $this->db->pdo()->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('client.deleted', $user['id'] ?? null, 'client', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
