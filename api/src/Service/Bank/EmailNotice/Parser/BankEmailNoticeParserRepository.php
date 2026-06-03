<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class BankEmailNoticeParserRepository
{
    /**
     * @var array<string,BankEmailNoticeParserInterface>
     */
    private array $parsers = [];

    /**
     * @var array<string,list<BankEmailNoticeProvider>>
     */
    private array $providersCache = [];

    /**
     * @var list<BankEmailNoticeProvider>|null
     */
    private ?array $defaultProvidersCache = null;

    /**
     * @param array<string,BankEmailNoticeParserInterface> $parsers
     */
    public function __construct(
        private readonly Connection $db,
        array $parsers,
    ) {
        foreach ($parsers as $parser) {
            if (!$parser instanceof BankEmailNoticeParserInterface) {
                throw new \RuntimeException('Parser registry obsahuje službu bez BankEmailNoticeParserInterface.');
            }
            $code = trim($parser->key());
            if ($code === '') {
                throw new \RuntimeException('Parser registry obsahuje parser s prázdným key().');
            }
            if (isset($this->parsers[$code])) {
                throw new \RuntimeException("Duplicitní bank email parser key: {$code}.");
            }
            $this->parsers[$code] = $parser;
        }
        if ($this->parsers === []) {
            throw new \RuntimeException('Parser registry je prázdný.');
        }
    }

    /**
     * @return array{provider:BankEmailNoticeProvider, parsed:ParsedBankEmailNotice}
     */
    public function parse(BankEmailNoticeMessage $message, ?string $preferredProviderRef = null, ?int $supplierId = null): array
    {
        foreach ($this->providers($preferredProviderRef, $supplierId) as $provider) {
            $parser = $this->parsers[$provider->parserType] ?? null;
            if ($parser === null) {
                continue;
            }
            if (!$parser->supports($message, $provider)) {
                continue;
            }
            return ['provider' => $provider, 'parsed' => $parser->parse($message, $provider)];
        }

        throw new \RuntimeException('Pro e-mail nebyl nalezen žádný aktivní parser provider.');
    }

    /**
     * @return list<BankEmailNoticeProvider>
     */
    public function providers(?string $preferredProviderRef = null, ?int $supplierId = null, bool $enabledOnly = true): array
    {
        $providers = $this->providersForScope($supplierId, $enabledOnly);

        if ($preferredProviderRef !== null && trim($preferredProviderRef) !== '') {
            $ref = trim($preferredProviderRef);
            return array_values(array_filter(
                $providers,
                static fn (BankEmailNoticeProvider $provider): bool => $provider->providerRef === $ref,
            ));
        }

        return $providers;
    }

    public function clearCache(): void
    {
        $this->providersCache = [];
        $this->defaultProvidersCache = null;
    }

    /**
     * @return list<BankEmailNoticeProvider>
     */
    private function providersForScope(?int $supplierId, bool $enabledOnly): array
    {
        $cacheKey = ($enabledOnly ? 'enabled' : 'all') . ':' . (($supplierId !== null && $supplierId > 0) ? (string) $supplierId : 'global');
        if (isset($this->providersCache[$cacheKey])) {
            return $this->providersCache[$cacheKey];
        }

        $sql = 'SELECT * FROM bank_email_notice_providers WHERE 1 = 1';
        $params = [];
        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }
        if ($supplierId !== null && $supplierId > 0) {
            $sql .= ' AND (supplier_id IS NULL OR supplier_id = ?)';
            $params[] = $supplierId;
        }
        $sql .= ' ORDER BY supplier_id IS NOT NULL DESC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $providers = [];
        foreach ($rows as $row) {
            $row['field_patterns'] = $this->json((string) $row['field_patterns']);
            $row['normalizer_config'] = $this->json((string) ($row['normalizer_config'] ?? '{}'));
            $providers[] = $this->providerFromDatabaseRow($row);
        }

        foreach ($this->defaultProviders() as $provider) {
            $providers[] = $provider;
        }

        return $this->providersCache[$cacheKey] = $providers;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(string $json): array
    {
        $decoded = json_decode($json !== '' ? $json : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function providerFromDatabaseRow(array $row): BankEmailNoticeProvider
    {
        $id = (int) $row['id'];
        return new BankEmailNoticeProvider(
            id: $id,
            supplierId: $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null,
            providerRef: 'db:' . $id,
            code: (string) $row['code'],
            name: (string) $row['name'],
            parserType: (string) $row['parser_type'],
            enabled: (bool) $row['enabled'],
            senderWhitelist: $this->stringOrNull($row['sender_whitelist'] ?? null),
            subjectPattern: $this->stringOrNull($row['subject_pattern'] ?? null),
            bodyPattern: $this->stringOrNull($row['body_pattern'] ?? null),
            fieldPatterns: is_array($row['field_patterns'] ?? null) ? $row['field_patterns'] : [],
            normalizerConfig: is_array($row['normalizer_config'] ?? null) ? $row['normalizer_config'] : [],
            system: false,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    /**
     * @return list<BankEmailNoticeProvider>
     */
    private function defaultProviders(): array
    {
        if ($this->defaultProvidersCache !== null) {
            return $this->defaultProvidersCache;
        }

        $providers = [];
        foreach ($this->parsers as $parser) {
            $provider = $parser->defaultProvider();
            if ($provider !== null) {
                $providers[] = $provider;
            }
        }
        return $this->defaultProvidersCache = $providers;
    }
}
